<?php

namespace App\Modules\Knowledge\Services;

use App\Modules\Knowledge\Contracts\KnowledgeEmbedder;
use App\Modules\Knowledge\DTOs\ChunkDraft;
use App\Modules\Knowledge\Enums\KnowledgeIndexStatus;
use App\Modules\Knowledge\Exceptions\KnowledgeChunkOverflowException;
use App\Modules\Knowledge\Jobs\IndexKnowledgeEntryJob;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Models\KnowledgeEntryChunk;
use App\Modules\Knowledge\Support\ChunkVector;
use App\Modules\Knowledge\Support\KnowledgeDigest;
use App\Modules\Variables\Contracts\MeteredAiCall;
use App\Modules\Variables\Exceptions\AiBudgetExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The INDEXING run: turn an entry's current text into current vectors, spending as little as possible.
 *
 * This owns the whole {@see KnowledgeIndexStatus} lifecycle, which is why it is one service and not a
 * chain of them: every exit from a run — indexed, partial, refused, failed — has to leave the entry in
 * a state that is both TRUE and ACTIONABLE by the sweep. Splitting that across layers is how a status
 * ends up disagreeing with the rows it describes.
 *
 * ------------------------------------------------------------------------------------------------
 * DIFFERENTIAL INDEXING is the point of the design, and it works on SETS OF DIGESTS.
 *
 * The chunker gives every passage a digest over its heading path + text. A stored chunk carrying that
 * digest IS that passage, so its vector is still correct and re-buying it would be pure waste. A
 * re-index therefore:
 *
 *   - REUSES a stored row whose digest still appears among the drafts. Its vector is untouched; only
 *     its address (ordinal, heading path, offsets) is refreshed, because an edit ABOVE a passage moves
 *     it without changing it.
 *   - EMBEDS a draft whose digest is new — or one whose matched row has no vector yet, which is how a
 *     budget-refused or half-failed run gets finished later without re-paying for what it did buy.
 *   - DELETES a stored row whose digest no longer appears anywhere.
 *
 * The consequence worth protecting in review: editing one paragraph of a twenty-five-chunk entry costs
 * ONE embedding call, not twenty-five. Re-saving it unchanged costs zero — the run returns before the
 * chunker is even invoked, because `index_digest == indexed_digest`.
 *
 * Digests are paired as MULTISETS rather than through a map: an entry may legitimately repeat the same
 * sentence under the same heading, and pairing by count stops that from thrashing (one row deleted and
 * an identical one inserted on every single run).
 *
 * ------------------------------------------------------------------------------------------------
 * SPEND rides the shared {@see MeteredAiCall} seam on the `ai_embedding` channel, gated BEFORE it: the
 * budget is asserted once up front (an over-cap workspace does no work at all) and again by the meter
 * around every batch. An exhausted budget is NOT a failure — work already paid for is persisted and
 * the entry is left `partial` or `pending_budget` for the sweep to finish once the cap moves. Nothing
 * here can block or slow a WRITE: the entry was committed long before this ran.
 *
 * CONCURRENCY. Every write from this service targets the INDEXING COLUMNS ONLY, through a keyed
 * builder update — never `$entry->save()`. The model was loaded before a network call that can take
 * seconds, so saving it whole would silently roll a concurrent edit back to the text this run started
 * with. `updated_at` gets the same treatment for two reasons at once: a background re-index must never
 * make a document look as though a human had just edited it, and it must never REWIND the timestamp of
 * an editor who saved during the provider call. Both are achieved by writing the column's own current
 * value back to itself — see {@see writeIndexColumns()}, which is the only place any of this happens.
 */
class KnowledgeIndexService
{
    /** The meter channel every embedding spend in this module is bucketed under. */
    public const CHANNEL = 'ai_embedding';

    private const OUTCOME_OK = 'ok';

    private const OUTCOME_BUDGET = 'budget';

    private const OUTCOME_ERROR = 'error';

    public function __construct(
        private KnowledgeChunker $chunker,
        private KnowledgeEmbedder $embedder,
        private MeteredAiCall $meter,
    ) {}

    /**
     * Index one entry. Idempotent, and free when there is nothing to do.
     *
     * Never throws for a domain reason — every outcome is recorded on the entry — so the job wrapping
     * it only ever fails on a genuine infrastructure fault.
     *
     * Returns whether this run actually REBUILT the entry's chunks. That is what the caller needs in
     * order to decide whether the entry's derived similarity edges are worth recomputing: a sweep pass
     * over a base that is already current returns false everywhere and costs nothing beyond the
     * digest comparison, and re-deriving edges from vectors that did not move would turn the cheapest
     * path in the module into the most expensive one.
     */
    public function index(string $entryId): bool
    {
        // withDrafts(): a draft must be FOUND here in order to be refused. Resolving it through the
        // default scope would return null, which reads as "deleted" and is indistinguishable from a
        // genuine race — so the refusal below would be silent and untestable.
        $entry = KnowledgeEntry::query()->withDrafts()->find($entryId);

        if ($entry === null) {
            return false; // deleted, or another tenant's, between dispatch and run
        }

        if ($entry->isDraft()) {
            // Second half of the observer's gate, here because a job can outlive the state it was
            // queued in: an entry accepted and then re-drafted (or a payload redelivered) must not
            // embed unapproved text. Costs nothing and closes the window.
            return false;
        }

        $this->restamp($entry);

        if (!$entry->needsIndexing()) {
            return false; // THE zero-cost path: the stored vectors already describe this exact text
        }

        if (!ChunkVector::supported()) {
            // No vector column on this driver (the chunks migration adds it only on pgsql). Stand
            // down without spending and without lying about the state: the entry stays stale, and a
            // pgsql environment will pick it up.
            Log::warning('Knowledge indexing skipped: the active connection has no pgvector support.', [
                'entry_id' => $entry->getKey(),
            ]);

            return false;
        }

        $digest = (string) $entry->index_digest;

        $this->claim($entry);

        try {
            $drafts = $this->chunker->chunk((string) $entry->title, (string) $entry->content);
        } catch (KnowledgeChunkOverflowException $e) {
            // Reachable only for an entry that predates the current cap, or one lowered under it: the
            // COMPOSER refuses to PROPOSE an over-cap draft (DraftRunNotes::ENTRY_TOO_MANY_CHUNKS),
            // which is where that guard went when the entry FormRequest was removed with
            // hand-authorship. Counts only; never the text.
            Log::warning('Knowledge entry exceeds the chunk cap; not indexed.', [
                'entry_id' => $entry->getKey(),
                'chunks' => $e->count,
                'max' => $e->max,
            ]);

            $this->settle($entry, KnowledgeIndexStatus::FAILED, null, null);

            return false;
        }

        $plan = $this->plan($drafts, $this->existingChunks($entry));

        [$vectors, $outcome] = $this->embed($entry, $drafts, $plan['embed']);

        $this->persist($entry, $digest, $drafts, $plan, $vectors, $outcome);

        return true;
    }

    /**
     * RETRY the indexing of one entry, on a human's request.
     *
     * WHICH states qualify is the enum's own answer ({@see KnowledgeIndexStatus::isRetryable()}) — the
     * three that describe an unfinished run:
     *
     *   failed          something went wrong; trying again is the whole remedy.
     *   pending_budget  refused before spending. The cap has probably moved since — that is WHY someone
     *                   is pressing the button — and waiting for the next scheduled sweep to find out
     *                   is a poor answer to "I just raised it".
     *   partial         some passages embedded, some did not. A retry costs only the difference,
     *                   because the digest match keeps every vector already bought.
     *
     * Everything else is refused with a 422 rather than quietly accepted, so a user never gets a
     * confirmation for work their click did not cause.
     *
     * This method is REQUEST-DRIVEN and therefore throws, which is the deliberate exception to
     * {@see index()}'s never-throw contract: that one runs on a queue where the only honest place to
     * record an outcome is the entry itself, while this one is answering a person who is waiting.
     *
     * It restamps first, so a retry after a chunker/model change re-indexes against the pipeline
     * configured NOW rather than the one that failed. All writes go through
     * {@see writeIndexColumns()}: a retry is not an edit, so `updated_at` must not move — a badge
     * click that made a document look freshly authored would corrupt the one thing a knowledge base
     * has to get right.
     *
     * Dispatch is EXPLICIT because those targeted writes bypass model events: the observer never sees
     * them, so it can neither dispatch nor double-dispatch here. The job re-checks the kill switch as
     * its first statement and the budget gate as usual, so nothing here can spend what an operator has
     * turned off — and if it is off, the entry simply stays `pending` for the sweep.
     *
     * @throws ValidationException when the entry's index state has nothing to retry
     */
    public function retry(KnowledgeEntry $entry, ?string $workspaceId): KnowledgeEntry
    {
        if (!($entry->index_status?->isRetryable() ?? false)) {
            throw ValidationException::withMessages([
                'index' => __('knowledge.index.not_retryable', [
                    'status' => $entry->index_status?->value ?? 'unknown',
                ]),
            ]);
        }

        $this->restamp($entry);

        $this->writeIndexColumns($entry, [
            'index_status' => KnowledgeIndexStatus::PENDING->value,
            // Release any stale claim: a `failed` row can still carry the clock of the run that died.
            'index_started_at' => null,
        ]);

        if ($workspaceId !== null) {
            $entryId = (string) $entry->getKey();

            DB::afterCommit(fn () => IndexKnowledgeEntryJob::dispatch($entryId, $workspaceId));
        }

        return $entry;
    }

    /**
     * Mark a run failed from the job's failed() hook. Guarded on `indexing` so a redelivered payload
     * cannot fail a run that already settled, and safe on an entry that has since vanished.
     */
    public function fail(string $entryId): void
    {
        $entry = KnowledgeEntry::query()->find($entryId);

        if ($entry === null || $entry->index_status !== KnowledgeIndexStatus::INDEXING) {
            return;
        }

        $this->settle($entry, KnowledgeIndexStatus::FAILED, null, null);
    }

    // ---- planning -------------------------------------------------------------

    /**
     * The stored chunks of an entry: address, digest, and whether a vector is already there that is
     * still USABLE. The vector itself is deliberately NOT loaded (see {@see KnowledgeEntryChunk}) —
     * only its presence matters here, and hydrating 1536 floats per row to learn one boolean is
     * exactly the mistake that model's docblock warns about.
     *
     * "Usable" includes the MODEL that produced it. Vectors from two different embedding models share
     * no coordinate system, so keeping an old model's vector because its text happened not to change
     * would silently poison the index: every similarity score against it would be meaningless, and
     * nothing downstream could detect it. A model swap therefore invalidates every vector — which is
     * also what the pipeline fingerprint on the entry promises.
     *
     * @return array<int, array{id: string, ordinal: int, digest: string, has_embedding: bool}>
     */
    private function existingChunks(KnowledgeEntry $entry): array
    {
        $model = (string) config('knowledge.embedding.model');

        return KnowledgeEntryChunk::query()
            ->where('knowledge_entry_id', $entry->getKey())
            ->orderBy('ordinal')
            // Cast to int rather than returning a bare boolean: the driver's boolean marshalling is
            // the kind of detail that differs between environments, and an int cannot be ambiguous.
            ->get(['id', 'ordinal', 'digest', 'embedding_model', DB::raw('(embedding is not null)::int as has_embedding')])
            ->map(fn (KnowledgeEntryChunk $chunk): array => [
                'id' => (string) $chunk->getKey(),
                'ordinal' => (int) $chunk->ordinal,
                'digest' => (string) $chunk->digest,
                'has_embedding' => ((int) $chunk->getAttribute('has_embedding')) === 1
                    && (string) $chunk->embedding_model === $model,
            ])
            ->all();
    }

    /**
     * Pair the drafts against the stored rows by digest: which draft keeps which row, which drafts
     * must be embedded, which rows are now orphaned.
     *
     * @param  array<int, ChunkDraft>  $drafts
     * @param  array<int, array{id: string, ordinal: int, digest: string, has_embedding: bool}>  $existing
     * @return array{rows: array<int, ?string>, embed: array<int, int>, delete: array<int, string>}
     */
    private function plan(array $drafts, array $existing): array
    {
        $available = [];

        foreach ($existing as $row) {
            $available[$row['digest']][] = $row;
        }

        $rows = [];   // draft ordinal => stored row id (null when the draft needs a new row)
        $embed = [];  // draft ordinals that must be sent to the provider
        $claimed = [];

        foreach ($drafts as $draft) {
            $row = empty($available[$draft->digest]) ? null : array_shift($available[$draft->digest]);

            if ($row !== null) {
                $claimed[$row['id']] = true;
            }

            $rows[$draft->ordinal] = $row['id'] ?? null;

            // A row whose digest matched but whose vector is missing is the residue of a run that was
            // cut short. It keeps its identity and simply gets its vector filled in.
            if ($row === null || !$row['has_embedding']) {
                $embed[] = $draft->ordinal;
            }
        }

        $delete = [];

        foreach ($existing as $row) {
            if (!isset($claimed[$row['id']])) {
                $delete[] = $row['id'];
            }
        }

        return ['rows' => $rows, 'embed' => $embed, 'delete' => $delete];
    }

    // ---- spend ----------------------------------------------------------------

    /**
     * Embed the drafts that need it, in batches of `knowledge.index.embed_batch`.
     *
     * A batch that succeeded is KEPT even when a later one is refused or errors: tokens already bought
     * are never thrown away, and the next run picks those chunks up through the digest match. That is
     * what makes an interrupted run cost the difference rather than the whole thing again.
     *
     * @param  array<int, ChunkDraft>  $drafts
     * @param  array<int, int>  $ordinals
     * @return array{0: array<int, array<int, float>>, 1: string} [vectors by draft ordinal, outcome]
     */
    private function embed(KnowledgeEntry $entry, array $drafts, array $ordinals): array
    {
        if ($ordinals === []) {
            return [[], self::OUTCOME_OK]; // a pure re-ordering: addresses move, nothing is bought
        }

        try {
            // GATE BEFORE SPEND, up front — refuse the whole run rather than doing a diff's worth of
            // writes for a workspace that cannot pay for any of it.
            $this->meter->assertWithinBudget(self::CHANNEL);
        } catch (AiBudgetExceededException) {
            return [[], self::OUTCOME_BUDGET];
        }

        $byOrdinal = [];

        foreach ($drafts as $draft) {
            $byOrdinal[$draft->ordinal] = $draft;
        }

        $vectors = [];
        $size = max(1, (int) config('knowledge.index.embed_batch'));

        foreach (array_chunk($ordinals, $size) as $batch) {
            $texts = array_map(
                fn (int $ordinal): string => $this->embeddedText($entry, $byOrdinal[$ordinal]),
                $batch,
            );

            try {
                $result = $this->meter->meter(self::CHANNEL, fn () => $this->embedder->embed($texts));
            } catch (AiBudgetExceededException) {
                return [$vectors, self::OUTCOME_BUDGET];
            } catch (Throwable $e) {
                // The provider's own error, never our input: no title, no heading path, no content.
                Log::error('Knowledge embedding call failed.', [
                    'entry_id' => $entry->getKey(),
                    'batch_size' => count($texts),
                    'exception' => $e::class,
                ]);
                report($e);

                return [$vectors, self::OUTCOME_ERROR];
            }

            foreach ($batch as $index => $ordinal) {
                if (isset($result->vectors[$index])) {
                    $vectors[$ordinal] = $result->vectors[$index];
                }
            }
        }

        return [$vectors, self::OUTCOME_OK];
    }

    /**
     * The text actually sent to the provider for one passage: TITLE, then the heading trail, then the
     * passage.
     *
     * Composed here rather than in the chunker because it is an EMBEDDING decision, not a splitting
     * one — the chunker's output has to stay a faithful description of the document. The title is
     * repeated into every chunk deliberately: a passage that reads "obowiązuje od 1 stycznia" is
     * unmatchable on its own, and the cheapest way to give every fragment its subject is to carry the
     * document's name with it.
     */
    private function embeddedText(KnowledgeEntry $entry, ChunkDraft $draft): string
    {
        return trim((string) $entry->title) . "\n" . $draft->headingPath . "\n" . $draft->content;
    }

    // ---- persistence ----------------------------------------------------------

    /**
     * Apply the plan and settle the entry's state, all in one transaction: a half-applied re-index
     * would leave the entry describing text it no longer contains, which is worse than not indexing
     * it at all (a stale passage is retrieved and believed; a missing one is merely missing).
     *
     * @param  array<int, ChunkDraft>  $drafts
     * @param  array{rows: array<int, ?string>, embed: array<int, int>, delete: array<int, string>}  $plan
     * @param  array<int, array<int, float>>  $vectors
     */
    private function persist(
        KnowledgeEntry $entry,
        string $digest,
        array $drafts,
        array $plan,
        array $vectors,
        string $outcome,
    ): void {
        $model = (string) config('knowledge.embedding.model');
        $now = now();

        DB::transaction(function () use ($entry, $digest, $drafts, $plan, $vectors, $outcome, $model, $now): void {
            if ($plan['delete'] !== []) {
                KnowledgeEntryChunk::query()->whereKey($plan['delete'])->delete();
            }

            // Park every surviving row on a negative ordinal first. `(knowledge_entry_id, ordinal)` is
            // UNIQUE, so re-numbering in place would collide the moment two chunks swap positions —
            // and an edit that inserts a paragraph shifts every ordinal after it. The negatives are a
            // scratch space no final ordinal can occupy.
            KnowledgeEntryChunk::query()
                ->where('knowledge_entry_id', $entry->getKey())
                ->where('ordinal', '>=', 0)
                ->update(['ordinal' => DB::raw('-1 - ordinal')]);

            foreach ($drafts as $draft) {
                $rowId = $plan['rows'][$draft->ordinal] ?? null;

                if ($rowId === null) {
                    // Through Eloquent, so TenantAware stamps workspace_id in shared mode and OMITS it
                    // in own-database mode, where the column does not exist. See ChunkVector for why
                    // the vector is a second statement rather than part of this insert.
                    $rowId = KnowledgeEntryChunk::create([
                        'knowledge_entry_id' => $entry->getKey(),
                        'knowledge_base_id' => $entry->knowledge_base_id,
                        'ordinal' => $draft->ordinal,
                        'heading_path' => $draft->headingPath,
                        'content' => $draft->content,
                        'char_start' => $draft->charStart,
                        'char_length' => $draft->charLength,
                        'digest' => $draft->digest,
                    ])->getKey();
                } else {
                    KnowledgeEntryChunk::query()->whereKey($rowId)->update([
                        'ordinal' => $draft->ordinal,
                        'heading_path' => $draft->headingPath,
                        'content' => $draft->content,
                        'char_start' => $draft->charStart,
                        'char_length' => $draft->charLength,
                        'digest' => $draft->digest,
                        'updated_at' => $now,
                    ]);
                }

                if (isset($vectors[$draft->ordinal])) {
                    ChunkVector::write((string) $rowId, $vectors[$draft->ordinal], $model, $now);
                }
            }

            // Counted through the SHARED definition of a usable vector
            // ({@see KnowledgeEntryChunk::scopeIndexed()}), which includes the embedding MODEL: a run
            // interrupted after a model swap would otherwise count an old model's leftover vectors as
            // done and settle the entry `indexed` while half of it was unsearchable. The same scope
            // answers the API's progress count, so the badge a user reads and the state the indexer
            // settles on can never disagree.
            $embedded = KnowledgeEntryChunk::query()
                ->where('knowledge_entry_id', $entry->getKey())
                ->indexed()
                ->count();

            $status = $this->outcomeStatus($outcome, count($drafts), $embedded);

            $this->settle(
                $entry,
                $status,
                // The digest advances ONLY on a complete run. Anything else must stay stale so
                // needsIndexing() keeps returning true and the sweep comes back for it.
                $status === KnowledgeIndexStatus::INDEXED ? $digest : null,
                count($drafts),
            );
        });
    }

    /**
     * The honest state for a finished run.
     *
     * `partial` and `pending_budget` are separated because they call for different actions: `partial`
     * means the entry IS retrievable, just incompletely, while `pending_budget` means nothing of it is
     * — and neither is a `failed` that a human should investigate, because raising the cap fixes both.
     */
    private function outcomeStatus(string $outcome, int $total, int $embedded): KnowledgeIndexStatus
    {
        if ($total === 0) {
            // An entry with no body has nothing to embed. It is up to date by definition — calling it
            // anything else would make the sweep pick it up on every pass, forever.
            return KnowledgeIndexStatus::INDEXED;
        }

        if ($embedded >= $total) {
            return KnowledgeIndexStatus::INDEXED;
        }

        if ($outcome === self::OUTCOME_BUDGET) {
            return $embedded > 0 ? KnowledgeIndexStatus::PARTIAL : KnowledgeIndexStatus::PENDING_BUDGET;
        }

        return $embedded > 0 ? KnowledgeIndexStatus::PARTIAL : KnowledgeIndexStatus::FAILED;
    }

    /**
     * Bring the entry's digest up to the pipeline that is configured RIGHT NOW.
     *
     * `index_digest` is stamped by the entry service on write, so it describes the entry as of the
     * last save — under whatever chunker version and embedding model were configured then. When those
     * change, the digest a save WOULD produce today is different, but nothing has re-saved the entry
     * to notice. Recomputing here, at the start of the run that is about to act on it, is what turns
     * a config change into a real re-index instead of a promise.
     *
     * A no-op when nothing moved, which keeps the zero-cost path free: the comparison below decides
     * whether anything is written at all.
     */
    private function restamp(KnowledgeEntry $entry): void
    {
        $digest = KnowledgeDigest::for(
            (string) $entry->title,
            (string) $entry->content,
            is_array($entry->metadata) ? $entry->metadata : [],
        );
        $parameters = KnowledgeDigest::parameters();

        if ($entry->index_digest === $digest && $entry->index_params === $parameters) {
            return;
        }

        $this->writeIndexColumns($entry, [
            'index_digest' => $digest,
            'index_params' => $parameters,
        ]);
    }

    /** Take the `indexing` claim and start the reaper's clock. */
    private function claim(KnowledgeEntry $entry): void
    {
        $this->writeIndexColumns($entry, [
            'index_status' => KnowledgeIndexStatus::INDEXING->value,
            'index_started_at' => now(),
        ]);
    }

    /** Record a terminal state, releasing the reaper's claim. */
    private function settle(
        KnowledgeEntry $entry,
        KnowledgeIndexStatus $status,
        ?string $indexedDigest,
        ?int $chunksCount,
    ): void {
        $values = [
            'index_status' => $status->value,
            'index_started_at' => null,
        ];

        if ($indexedDigest !== null) {
            $values['indexed_digest'] = $indexedDigest;
        }

        if ($chunksCount !== null) {
            $values['chunks_count'] = $chunksCount;
        }

        $this->writeIndexColumns($entry, $values);
    }

    /**
     * Write ONLY the indexing columns, and leave `updated_at` EXACTLY WHERE THE ROW HAS IT.
     *
     * Three distinct hazards, all real:
     *
     *   1. Saving the loaded model would write back the title/content it held BEFORE a multi-second
     *      provider call, silently reverting anyone who edited meanwhile. Hence a targeted update.
     *   2. Letting the builder stamp a fresh `updated_at` would advertise a background job as a human
     *      edit on every list and detail view. Passing the column explicitly wins over the builder's
     *      automatic one, which is how it stays untouched.
     *   3. And — the one that is easy to get wrong while fixing (2) — passing the timestamp the RUN
     *      LOADED would REWIND a concurrent editor's. The provider call is the window: an edit that
     *      lands during it moves `updated_at` forward, and a settle carrying the old value would put
     *      it back. Nothing is lost from the text (the writes are column-scoped), but the entry's
     *      "last edited" would travel backwards and it would sink in every freshness ordering —
     *      the graph overview's isolated-node fill, the search tie-break, the hydrate ordering.
     *
     * `DB::raw('updated_at')` answers (2) and (3) together, and does it better than a re-read would:
     * the value is read by the DATABASE inside the very statement that writes it, so there is no
     * select-then-update window for a third write to slip through. It is the same expression the
     * stale-claim reaper already uses for the same reason ({@see \App\Modules\Knowledge\Console\ReapStaleKnowledgeIndexCommand}),
     * so the two paths that touch an entry without editing it now say so identically.
     *
     * The in-memory model is deliberately NOT refreshed with the row's timestamp: nothing reads it
     * after these writes, and a SELECT per index write would buy nothing.
     *
     * @param  array<string, mixed>  $values
     */
    private function writeIndexColumns(KnowledgeEntry $entry, array $values): void
    {
        KnowledgeEntry::query()
            ->whereKey($entry->getKey())
            ->update($values + ['updated_at' => DB::raw('updated_at')]);

        // Keep the in-memory model consistent with the row for the rest of this run.
        foreach ($values as $column => $value) {
            $entry->setAttribute($column, $value);
        }

        $entry->syncOriginal();
    }
}
