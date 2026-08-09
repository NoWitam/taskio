<?php

namespace App\Modules\Knowledge\Services;

use App\Modules\Knowledge\DTOs\KnowledgeEntryDTO;
use App\Modules\Knowledge\DTOs\ResolutionSet;
use App\Modules\Knowledge\Enums\KnowledgeDraftSessionStatus;
use App\Modules\Knowledge\Enums\KnowledgeEntryStatus;
use App\Modules\Knowledge\Exceptions\KnowledgeContextAlreadyExpanded;
use App\Modules\Knowledge\Exceptions\KnowledgeDraftBudgetExceeded;
use App\Modules\Knowledge\Exceptions\KnowledgeSeedIsAliasException;
use App\Modules\Knowledge\Exceptions\StaleKnowledgeWriteException;
use App\Modules\Knowledge\Jobs\GenerateKnowledgeDraftsJob;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeDraftSession;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Support\EntryAliases;
use App\Modules\Knowledge\Support\KnowledgePipelineEstimate;
use App\Modules\Knowledge\Support\SectionAppender;
use App\Modules\Knowledge\Support\WikilinkParser;
use App\Modules\Variables\Contracts\MeteredAiCall;
use App\Modules\Variables\Exceptions\AiBudgetExceededException;
use App\Modules\Variables\Services\AiUsageService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * The LIFECYCLE of a drafting session: start, refine, accept, reject, abandon.
 *
 * Owns the two guards that make an async, billed, user-driven run safe, and keeps them in ONE order at
 * every entry point:
 *
 *   1. GATE BEFORE CLAIM. The budget is asserted first, so a refused request leaves the session exactly
 *      as it was. Claiming first would park it in `generating` with no worker coming — a session the
 *      user can only escape by abandoning, for an event that is not their fault.
 *   2. CLAIM BY GUARDED UPDATE. `status != generating` in the WHERE, so two clicks (or two tabs) race
 *      in the database and exactly one wins. A read-then-write check would let both through, and two
 *      runs against one session means two sets of drafts reconciling over each other.
 *
 * The composer also sits behind the module's single AI kill switch (`knowledge.index.enabled`), which
 * is documented as covering EVERY AI cost this module can incur. Honouring it here is what keeps that
 * sentence true now that indexing is no longer the only spender.
 */
class KnowledgeDraftSessionService
{
    public function __construct(
        private MeteredAiCall $meter,
        private AiUsageService $usage,
        private KnowledgeEntryService $entries,
        private KnowledgeDraftRetrievalService $retrieval,
        private KnowledgeEntityResolutionService $resolution,
        private KnowledgeGraphOpsApplier $graphApplier,
        private TenantContext $tenant,
    ) {}

    // ---- starting work ---------------------------------------------------------

    /**
     * Open a session on some raw material and queue the first composition.
     *
     * @throws KnowledgeDraftBudgetExceeded
     */
    public function start(KnowledgeBase $base, string $sourceText, ?string $seedSlug, ?string $seedTitle): KnowledgeDraftSession
    {
        // Projected for the WHOLE pipeline, not merely for the first call — see assertComposable().
        $this->assertComposable($sourceText);

        // A SEED THAT IS ALREADY AN ENTRY'S ALIAS IS REFUSED BEFORE ANY SPEND.
        //
        // "Write the entry this red link points at" cannot be satisfied when the red link is an
        // inflected form of something the base already holds: resolution matches the alias, the model
        // correctly declines to write a duplicate, and the seed check then fails the run. Every layer
        // is right and the user gets `seed_missed` for a link they can never fill.
        //
        // Refused here, by name, so the client can offer the entry instead of offering to create it —
        // and so the pointless AI call is never made.
        $this->assertSeedIsNotAnAlias($base, $seedSlug);

        $session = new KnowledgeDraftSession([
            'knowledge_base_id' => $base->getKey(),
            'source_text' => $sourceText,
            'status' => KnowledgeDraftSessionStatus::GENERATING,
            'claimed_at' => now(),
            'prompt_history' => [],
            'seed_slug' => $seedSlug,
            'seed_title' => $seedTitle,
        ]);

        $session->save();

        $session->forceFill($this->freezeContext($base, $sourceText, $session))->save();

        $this->dispatch($session);

        return $session;
    }

    /**
     * Retrieve context AGAIN, on the user's explicit request. One more metered embedding, which is why
     * it is an action rather than something a refinement does implicitly: the client shows the cost.
     *
     * Widens the evidence for the NEXT run — it does not re-judge the drafts already on the table.
     *
     * @throws KnowledgeDraftBudgetExceeded
     */
    public function expandContext(KnowledgeDraftSession $session): KnowledgeDraftSession
    {
        // Refused ONCE PER ROUND, server-side. The "already expanded" state used to live only in the
        // browser that pressed the button, so a reload or a second reviewer was offered the paid action
        // again and buying it produced the identical retrieval set. A refinement clears the flag, so the
        // loop that is actually useful — widen, revise, widen again — stays open.
        //
        // CLAIMED BY A GUARDED UPDATE, not read-then-throw. Reading the column and throwing left the
        // whole embedding pass inside the window between the check and the stamp, which was written
        // AFTER the spend: two simultaneous POSTs both read null, both paid, and the second overwrote
        // the first's identical set. Same shape and same fix as `claim()` below — the check and the
        // write are one statement, so the database decides the race.
        $taken = KnowledgeDraftSession::query()
            ->whereKey($session->getKey())
            ->whereNull('context_expanded_at')
            ->update(['context_expanded_at' => now()]);

        if ($taken === 0) {
            throw new KnowledgeContextAlreadyExpanded;
        }

        $base = $session->base()->firstOrFail();

        try {
            $this->assertComposable((string) $session->source_text);

            $session->forceFill($this->freezeContext($base, (string) $session->source_text, $session))->save();
        } catch (\Throwable $failure) {
            // THE STAMP IS RELEASED when the pass did not happen. Claiming before the spend is what
            // closes the race; keeping the claim after a refusal would be a different bug — a budget
            // blip or a provider outage would lock the session out of an action it never received,
            // until a refinement cleared the flag.
            //
            // Through a QUERY, not the model: the in-memory copy still holds the null it was loaded
            // with, so `forceFill(null)->save()` would write nothing at all and quietly keep the claim.
            KnowledgeDraftSession::query()
                ->whereKey($session->getKey())
                ->update(['context_expanded_at' => null]);

            throw $failure;
        }

        return $session->refresh();
    }

    /**
     * Freeze the ONE context this session will read — and only one of the two passes ever runs.
     *
     * ENTITY RESOLUTION SUPERSEDES RETRIEVAL when it is enabled. Both freeze entry text and both spend
     * an embedding, so running them together bought the same context twice and would have quoted the
     * same entries twice into one prompt. Resolution is the richer answer — it carries the same content
     * plus the typed relations and the handles — so it is the one that survives.
     *
     * The switch is made HERE, at the freeze, rather than downstream at the read, because a session
     * must never hold two contexts that could disagree: `contextEntries()` picks a source, and if both
     * columns were populated the amendment allow-list would depend on which one won a tie.
     *
     * The number of FULL-CONTENT slots is the amendment cap, because those are the entities a run may
     * actually propose a change to — and an entity a model may rewrite is one it must have been shown
     * whole (the G1 rule, applied to the same state for the same reason).
     *
     * @return array<string, mixed>
     */
    private function freezeContext(KnowledgeBase $base, string $sourceText, KnowledgeDraftSession $session): array
    {
        $slots = max(0, (int) config('knowledge.drafting.max_shadow_per_session'));

        if (config('knowledge.graph_extraction.enabled')) {
            $resolution = $this->resolution->resolve(
                $base,
                $sourceText,
                $session->creator_type,
                $session->creator_id,
                $slots,
            );

            // ONE context, MERGED rather than chosen between. The resolution pass now runs both legs —
            // entries the material NAMES and entries it is ABOUT — in a single embedding batch, so
            // there is no second source to fall back to and no second spend. The retrieval column is
            // CLEARED rather than merely ignored: a stale set left behind would be read the moment the
            // merge came back empty, handing the composer context nobody paid for on this run, against
            // revisions that may since have moved.
            return ['resolution_set' => $resolution->toArray(), 'retrieval_set' => null];
        }

        return [
            // CONTEXT, retrieved once and frozen: what the base already says about this material, which
            // is what lets the composer propose an amendment instead of a near-duplicate. Fail-soft by
            // construction — an empty set simply means create-only, never a failed session.
            'retrieval_set' => $this->retrieval->retrieve($base, $sourceText, $session->creator_type, $session->creator_id),
            // Written rather than left null, and it costs no call: the payload then SAYS why the
            // resolution section is empty. Without it a client cannot tell "the operator switched this
            // off" from "it ran and found nothing", and those want different screens.
            'resolution_set' => ResolutionSet::empty(ResolutionSet::DEGRADED_DISABLED)->toArray(),
        ];
    }

    /**
     * Point a shadow draft at the target's CURRENT revision, without asking the model anything.
     *
     * The case: a human edited the target after the composer read it, so the proposal is written
     * against text that has moved. Rebasing does NOT touch the proposal's content — it only says "I
     * have seen the newer version, compare against that" — which is the honest division of labour. The
     * machine cannot know whether the human's edit and the proposal conflict in meaning; the reviewer
     * can, and now has a diff against what is actually there.
     */
    public function rebase(KnowledgeEntry $shadow): KnowledgeEntry
    {
        $target = $shadow->targetsEntry;

        if ($target !== null) {
            $shadow->forceFill(['target_revision_id' => $target->current_revision_id])->save();
        }

        return $shadow->refresh();
    }

    /**
     * Ask for a revision of the whole set. The instruction joins the history — refinement is cumulative
     * ("shorter", then "add examples", must mean both).
     *
     * @throws KnowledgeDraftBudgetExceeded
     */
    public function refine(KnowledgeDraftSession $session, string $instruction): KnowledgeDraftSession
    {
        // PROJECTED AGAINST THE SESSION'S OWN MATERIAL, like every other paid path here.
        //
        // This called the gate with no text, which made the projection 0.0 — and a gate projecting zero
        // asks only "is there any budget left at all". That is the weakest possible question for the
        // most expensive call in the module, on the one action a user can repeat indefinitely: each
        // refinement re-composes the whole set against a prompt history that grows every time.
        $this->assertComposable((string) $session->source_text);

        if (!$this->claim($session)) {
            return $session->refresh(); // a run is already in flight; the poll will show it
        }

        $history = $session->instructions();
        $history[] = ['at' => now()->toISOString(), 'instruction' => $instruction];

        $session->forceFill(['prompt_history' => array_values($history)])->save();

        $this->dispatch($session);

        return $session;
    }

    /**
     * Take the claim, or report that somebody else holds it. A guarded UPDATE — the check and the write
     * are one statement, so the race is decided by the database.
     */
    private function claim(KnowledgeDraftSession $session): bool
    {
        $taken = KnowledgeDraftSession::query()
            ->whereKey($session->getKey())
            ->where('status', '!=', KnowledgeDraftSessionStatus::GENERATING->value)
            ->update([
                'status' => KnowledgeDraftSessionStatus::GENERATING->value,
                'claimed_at' => now(),
                'failure_reason' => null,
                // A REVISION CONSUMES THE WIDENED CONTEXT: the composer re-derives its whole set
                // against it, so once that has happened, expanding again is genuinely new work rather
                // than a repeat purchase. Cleared inside the CLAIM so it is decided by the same
                // guarded statement that decides who owns the run — two clicks cannot half-apply it.
                'context_expanded_at' => null,
            ]);

        if ($taken === 0) {
            return false;
        }

        $session->refresh();

        return true;
    }

    private function dispatch(KnowledgeDraftSession $session): void
    {
        $sessionId = (string) $session->getKey();
        $workspaceId = $this->tenant->id();

        // After the transaction the caller may be inside, and with SCALARS only — a serialized model
        // would carry the whole source text through the queue payload and could resolve stale.
        DB::afterCommit(fn () => GenerateKnowledgeDraftsJob::dispatch($sessionId, (string) $workspaceId));
    }

    /**
     * Refuse a seed that is really an existing entry's ALIAS, and name that entry.
     *
     * THE CASE: a model wrote `[[nowego-tokio]]` where the base holds "Nowe Tokio" (slug `nowe-tokio`,
     * alias "Nowego Tokio"). The link resolved to nothing, so it rendered red; the reader clicked
     * "write the missing entry"; entity resolution then matched the alias to the existing entry —
     * correctly — the model declined to write a duplicate — correctly — and the seed check found no
     * entry under the requested slug and failed the run. Every layer behaved as designed and the
     * person got `seed_missed` for a red link they could never fill, after paying for the call.
     *
     * A SLUG WINS over an alias here, as everywhere: a seed naming a live address is not this
     * situation at all, and is left to the ordinary seed machinery.
     *
     * @throws KnowledgeSeedIsAliasException
     */
    private function assertSeedIsNotAnAlias(KnowledgeBase $base, ?string $seedSlug): void
    {
        $seed = $seedSlug === null ? '' : WikilinkParser::normalize($seedSlug);

        if ($seed === '') {
            return;
        }

        // A SLUG WINS. An entry addressed by exactly this slug means the seed is not an alias case at
        // all — and a seed pointing at a live address is a different (older) situation the seed
        // machinery already handles.
        $taken = KnowledgeEntry::query()
            ->where('knowledge_base_id', $base->getKey())
            ->where('slug', $seed)
            ->exists();

        if ($taken) {
            return;
        }

        $entries = KnowledgeEntry::query()
            ->where('knowledge_base_id', $base->getKey())
            ->whereNotNull('aliases')
            ->get(['id', 'slug', 'title', 'aliases']);

        foreach ($entries as $entry) {
            foreach (EntryAliases::normalize($entry->aliases) as $alias) {
                if (WikilinkParser::normalize($alias) === $seed) {
                    throw new KnowledgeSeedIsAliasException(
                        $seed,
                        (string) $entry->getKey(),
                        (string) $entry->slug,
                        (string) $entry->title,
                    );
                }
            }
        }
    }

    /**
     * Refuse up front when the workspace cannot pay for the WHOLE run, or when the operator has
     * stopped this module's AI.
     *
     * The projection is the point. A composer run is no longer one call: with resolution on it is
     * extraction → embeddings → composition, and a gate that only asked "is any budget left" could
     * pass, charge the workspace for reading the document, and then run out before a word was written.
     * The user would have paid for a session that produced nothing — and, before the failure reasons
     * were split, would have been told the answer was unparseable.
     *
     * $sourceText is optional so the availability probe can ask the plain question ("could this
     * workspace compose at all") without inventing a document to project against.
     *
     * @throws KnowledgeDraftBudgetExceeded
     */
    private function assertComposable(?string $sourceText = null): void
    {
        if (!config('knowledge.index.enabled')) {
            throw new KnowledgeDraftBudgetExceeded;
        }

        $projected = $sourceText === null ? 0.0 : KnowledgePipelineEstimate::forSource($sourceText);

        try {
            $this->meter->assertWithinBudget(KnowledgeDraftService::CHANNEL, $projected);
        } catch (AiBudgetExceededException) {
            throw new KnowledgeDraftBudgetExceeded;
        }
    }

    /**
     * What the client needs to decide whether to offer the composer at all — asked BEFORE the form is
     * rendered, so an over-cap workspace sees an explanation instead of a button that 429s.
     *
     * @return array<string, mixed>
     */
    public function availability(): array
    {
        $enabled = (bool) config('knowledge.index.enabled');
        $summary = $this->usage->summary();

        // The GATE decides, not the summary. This endpoint exists so the answer here and the answer a
        // POST would give are the same answer; reading the ledger's own view instead would let the two
        // drift, and the symptom would be a button that renders enabled and then 429s.
        $blocked = false;

        try {
            $this->meter->assertWithinBudget(KnowledgeDraftService::CHANNEL);
        } catch (AiBudgetExceededException) {
            $blocked = true;
        }

        return [
            'can_compose' => $enabled && !$blocked,
            'reason' => match (true) {
                !$enabled => 'disabled',
                $blocked => KnowledgeDraftBudgetExceeded::CODE,
                default => null,
            },
            'budget' => [
                'cost_used' => $summary['cost_used'] ?? null,
                'cost_cap' => $summary['cost_cap'] ?? null,
                'cost_remaining' => $summary['cost_remaining'] ?? null,
                'warn_reached' => $summary['warn_reached'] ?? false,
                'blocked' => $blocked,
                'period' => $summary['period'] ?? null,
            ],
            'limits' => [
                'source_max_chars' => (int) config('knowledge.drafting.source_max_chars'),
                'prompt_max_chars' => (int) config('knowledge.drafting.prompt_max_chars'),
                'max_entries_per_session' => (int) config('knowledge.drafting.max_entries_per_session'),
            ],
        ];
    }

    // ---- finishing work --------------------------------------------------------

    /**
     * PUBLISH the chosen drafts: clear `draft_session_id` and set the editorial status, atomically.
     *
     * One column write per entry is the whole operation — no copy, no move, no new row — which is the
     * payoff of drafts having been real entries all along. Clearing the column fires the entry
     * observer, so an accepted entry queues for indexing exactly like any other save; nothing here has
     * to know about chunks or embeddings.
     *
     * ------------------------------------------------------------------------------------------------
     * THE GRAPH HALF IS APPLIED IN THE SAME ACT
     *
     * A proposal is one thing to a reviewer — "Anna left Acme, here is the dated line" — even though it
     * lands in two tables. So the relation operations run in the SAME accept, through
     * {@see KnowledgeGraphOpsApplier}, which re-checks every rule against the base as it stands now
     * rather than trusting the verdict the composer got an hour ago.
     *
     * Declared entities (`N<n>`) are created FIRST and inside one transaction with the relations that
     * name them, because "Anna met Bob" is not two decisions: a Bob without the relation is a stub
     * nobody asked for, and a relation without Bob cannot exist at all.
     *
     * NOTHING HERE WAITS ON AN AI PROVIDER. Re-indexing an amended entry is queued by the entry
     * observer and runs after the commit, so no lock is held across an HTTP call and a provider outage
     * can never roll back text a human has approved.
     *
     * ACCEPTING AN AMENDMENT RETURNS THE LIVE TARGET, not the shadow that proposed it — the shadow is
     * purged on publication. So `accepted` reads uniformly as "the entries that now hold what you
     * approved", which is also why there is no second list of rows that moved underneath the reviewer:
     * it would be this one again.
     *
     * @param  array<int, string>  $entryIds
     * @return array{accepted: array<int, KnowledgeEntry>, conflicts: array<int, array<string, mixed>>, relations: array<int, \App\Modules\Knowledge\Models\KnowledgeRelation>, skipped: array<int, array<string, mixed>>}
     */
    public function accept(KnowledgeDraftSession $session, array $entryIds, KnowledgeEntryStatus $status, ?array $graphOpKeys = null): array
    {
        $drafts = $entryIds === []
            ? new \Illuminate\Database\Eloquent\Collection
            : $session->drafts()->with('targetsEntry')->whereKey($entryIds)->get();

        $accepted = [];
        $conflicts = [];

        foreach ($drafts as $draft) {
            // ONE TRANSACTION PER DRAFT, not one for the batch. A shadow whose target moved must fail
            // ALONE: rolling the whole batch back would punish the other proposals for a conflict that
            // has nothing to do with them, and a reviewer who accepted five things would be told that
            // none of them happened.
            try {
                $accepted[] = DB::transaction(fn (): KnowledgeEntry => $draft->isShadow()
                    ? $this->publishAmendment($draft, $status)
                    : $this->publishNewEntry($draft, $status));
            } catch (StaleKnowledgeWriteException) {
                // Read from the TARGET rather than from the exception: it is the same value, and the
                // client needs it to fetch the version the proposal now has to be rebased onto.
                $conflicts[] = [
                    'entry_id' => (string) $draft->getKey(),
                    'targets_entry_id' => $draft->targets_entry_id,
                    'current_revision_id' => $draft->targetsEntry?->fresh()?->current_revision_id,
                ];
            }
        }

        $graph = $this->applyGraphOps($session, $status, $graphOpKeys);

        return [
            'accepted' => $accepted,
            'conflicts' => array_merge($conflicts, $graph['conflicts']),
            'relations' => $graph['applied'],
            'skipped' => $graph['skipped'],
        ];
    }

    /**
     * The graph half of an acceptance — declared entities and relation operations, as one act.
     *
     * ONE TRANSACTION for the SELECTED graph operations, unlike the per-draft transactions above. The
     * asymmetry is deliberate: drafts are independent proposals a reviewer picks from, while the
     * operations they chose are applied together — half of a chosen change is a lie, and a new entity
     * without the relation that needed it is a stub nobody asked for.
     *
     * WHICH operations, though, is now the reviewer's to say. `$graphOpKeys` null means all of them
     * (the compatible reading); a list means exactly those, and the rest are reported as `not_selected`
     * rather than silently absent.
     *
     * @param  array<int, string>|null  $graphOpKeys
     * @return array{applied: array<int, \App\Modules\Knowledge\Models\KnowledgeRelation>, conflicts: array<int, array<string, mixed>>, skipped: array<int, array<string, mixed>>}
     */
    private function applyGraphOps(KnowledgeDraftSession $session, KnowledgeEntryStatus $status, ?array $graphOpKeys): array
    {
        $ops = $session->graphOps();

        if ($ops['entities'] === [] && $ops['wiki_updates'] === [] && $ops['graph_updates'] === []) {
            return ['applied' => [], 'conflicts' => [], 'skipped' => []];
        }

        $base = $session->base()->firstOrFail();

        return DB::transaction(function () use ($session, $base, $status, $graphOpKeys): array {
            // THE SELECTION REACHES BOTH HALVES. It used to reach only `apply()`, so a reviewer who
            // refused the whole graph (`graph_op_keys: []`) still had every declared entity written as a
            // real, approved entry — the one answer the review screen offers that the server did not
            // honour.
            $created = $this->graphApplier->createDeclaredEntities($session, $base, $status, $graphOpKeys);

            return $this->graphApplier->apply($session, $base, $status, $created, $graphOpKeys);
        });
    }

    /**
     * A plain draft becomes a real entry: one column write, no copy, no new row.
     *
     * Clearing `draft_session_id` fires the observer, which is what queues it for indexing — and the
     * link pass is re-run here because a draft deliberately draws no edges (see KnowledgeLinkService):
     * now that it is visible, its own `[[wikilinks]]` resolve and any ghost aimed at its slug is
     * adopted.
     */
    private function publishNewEntry(KnowledgeEntry $draft, KnowledgeEntryStatus $status): KnowledgeEntry
    {
        $draft->forceFill([
            'draft_session_id' => null,
            'targets_entry_id' => null,
            'target_revision_id' => null,
            'status' => $status,
        ])->save();

        $this->entries->syncLinks($draft->refresh());

        return $draft;
    }

    /**
     * A shadow becomes an EDIT of the entry it targets, through the ordinary entry service — so it
     * appends a revision, re-syncs links and re-queues indexing exactly like a human's save.
     *
     * The frozen `target_revision_id` is replayed as the optimistic-lock token. That is the whole point
     * of freezing it: if a person edited the target after the composer read it, this throws instead of
     * overwriting their work with text written against a version that no longer exists.
     *
     * The shadow is then destroyed. It was a proposal, it has been applied, and keeping it would leave
     * a row pointing at a revision that is no longer current — an orphan that would show up as a stale
     * proposal forever.
     *
     * @throws StaleKnowledgeWriteException
     */
    private function publishAmendment(KnowledgeEntry $shadow, KnowledgeEntryStatus $status): KnowledgeEntry
    {
        $target = $shadow->targetsEntry;

        if ($target === null) {
            // The target was deleted while the proposal sat on the table. Nothing to amend; the shadow
            // goes rather than being published as an entry with a reserved slug.
            $this->entries->purge($shadow);

            throw new StaleKnowledgeWriteException(null);
        }

        // AN APPEND IS COMPOSED HERE, from the LIVE text, and carries NO lock.
        //
        // "Add this dated line" means the same thing before and after somebody else's edit, so applying
        // it to the newer text loses nothing from either side — and demanding the revision the composer
        // read would manufacture a conflict for an operation that cannot collide. A REWRITE replaces
        // the document, so it keeps both: its own body, and the frozen revision as its lock.
        $isAppend = $shadow->isAppendShadow();

        // A REWRITE WE CANNOT LOCK IS REFUSED.
        //
        // `assertNotStale()` returns early on a null token, so a shadow whose frozen revision was never
        // recorded — or whose revision row has since been destroyed — replaced the whole document with
        // NO check at all, silently overwriting a concurrent human edit. Recoverable from history, but
        // nobody was ever offered the choice.
        //
        // This is the G5.1 rule — "where we cannot prove what the model worked from, we do not replace"
        // — applied at the one point that knows the truth. It REFUSES rather than degrading to an
        // append, because a rewrite's content is a WHOLE NEW BODY and appending it would paste the
        // document on top of itself. A conflict is also the useful shape: the client already offers a
        // rebase for exactly this, and a rebase is precisely what re-establishes the missing token.
        if (!$isAppend && $shadow->target_revision_id === null) {
            throw new StaleKnowledgeWriteException($target->current_revision_id);
        }

        $updated = $this->entries->update($target, new KnowledgeEntryDTO(
            title: (string) $shadow->title,
            content: $isAppend
                ? SectionAppender::apply((string) $target->content, $shadow->amend_section, (string) $shadow->content)
                : (string) $shadow->content,
            metadata: is_array($shadow->metadata) ? $shadow->metadata : [],
            status: $status,
            staleAt: $target->stale_at,
            expectedRevisionId: $isAppend ? null : $shadow->target_revision_id,
            // THE TARGET KEEPS ITS TYPE. `update()` writes `entry_type` from the DTO unconditionally,
            // so omitting it set the column to NULL — untyping the entry on every accepted amendment.
            // Harmless while nothing was ever typed; the moment the composer started typing entries it
            // would have quietly undone that on the first append to any of them, and the relation
            // matrix would have gone dark again one entry at a time.
            entryType: $target->entry_type,
            // AN APPEND ADDS ITS CLAIMS; A REWRITE RESTATES THEM.
            //
            // Two different statements about the same column, and the difference follows the content.
            // An append leaves the existing body in place and adds a dated line, so the facts the
            // target already covered are still covered there — replacing the list would erase a true
            // claim about text that has not moved. A rewrite replaces the whole body, so its claims
            // describe the whole of the new one, and merging would keep a claim for a sentence the
            // rewrite may have dropped.
            covers: $isAppend
                ? array_values(array_unique(array_merge(
                    is_array($target->covers) ? $target->covers : [],
                    is_array($shadow->covers) ? $shadow->covers : [],
                )))
                : (is_array($shadow->covers) ? $shadow->covers : []),
        ));

        $this->entries->purge($shadow);

        return $updated;
    }

    /** Throw one draft away. Soft-deleted, so it can come back while the session lives. */
    public function reject(KnowledgeEntry $draft): void
    {
        $this->entries->delete($draft);
    }

    /**
     * Abandon the session: every draft destroyed for good, then the session row.
     *
     * A hard purge, through the entry service's own cascade so revisions and links go with them. There
     * is nothing to keep — an unaccepted draft is machine output nobody chose, and the session holds
     * the user's raw source text, which is not something to retain for its own sake.
     */
    public function abandon(KnowledgeDraftSession $session): void
    {
        DB::transaction(function () use ($session): void {
            foreach ($session->drafts()->withTrashed()->get() as $draft) {
                $this->entries->purge($draft);
            }

            $session->delete();
        });
    }
}
