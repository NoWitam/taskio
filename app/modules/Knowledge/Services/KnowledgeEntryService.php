<?php

namespace App\Modules\Knowledge\Services;

use App\Modules\Knowledge\DTOs\KnowledgeEntryDTO;
use App\Modules\Knowledge\Enums\KnowledgeEntryStatus;
use App\Modules\Knowledge\Enums\KnowledgeIndexStatus;
use App\Modules\Knowledge\Exceptions\KnowledgeSlugConflictException;
use App\Modules\Knowledge\Exceptions\StaleKnowledgeWriteException;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Models\KnowledgeEntryRevision;
use App\Modules\Knowledge\Support\KnowledgeDigest;
use App\Modules\Knowledge\Support\SubjectPhrases;
use App\Modules\Knowledge\Support\WikilinkParser;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Business logic + persistence for KNOWLEDGE ENTRIES.
 *
 * Every write here is multi-step by nature — the row, its revision, its links, its index bookkeeping
 * — so every write runs in a transaction. A half-applied save is the one outcome this module must
 * never produce: an entry whose text and whose edges disagree misleads both the reader and (later)
 * the retrieval layer, and an entry saved without its revision has silently lost the audit trail
 * that makes collaborative editing safe.
 *
 * Three invariants are maintained here rather than in the database, each for a stated reason:
 *   SLUG UNIQUENESS is per base among LIVE entries (see {@see mintSlug()}). A partial unique index
 *     could express that, but it would have to be duplicated in the tenant tree and would make the
 *     trash unable to hold a same-slug row — which is what a restorable trash is for.
 *   REVISIONS are appended only when the AUTHORED state changed (title/content/metadata). A status
 *     flip or a review-date change is not a new version of the document, and recording it as one
 *     would bury the real edits in noise.
 *   INDEX STATE is stamped from the digest: whenever the digest moves, the entry goes back to
 *     `pending` so B2a's embedder re-chunks it. Nothing here calls a provider — that is B2a's job
 *     and it sits behind its own kill switch.
 */
class KnowledgeEntryService
{
    /** How many suffixed attempts a slug mint makes before falling back to a random discriminator. */
    private const SLUG_ATTEMPTS = 50;

    public function __construct(
        private KnowledgeLinkService $links,
    ) {}

    // ---- reads ----------------------------------------------------------------

    /**
     * The base's entries, ordered by their manual position. The order ENDS in the primary key: a
     * cursor seek on `position` alone would skip or duplicate rows at a page edge, because positions
     * are not unique.
     */
    public function index(KnowledgeBase $base, Request $request): CursorPaginator
    {
        return $this->filtered($base, $request)
            ->with('creator')
            // The progress numerator, as one correlated sub-select for the whole page — see
            // KnowledgeEntry::scopeWithIndexedChunks(). Never a query per row.
            ->withIndexedChunks()
            ->orderBy('position')
            ->orderBy('id')
            ->cursorPaginate(25)
            ->withQueryString();
    }

    /** The filter surface shared by the list (kept separate so a future export reuses it). */
    private function filtered(KnowledgeBase $base, Request $request): Builder
    {
        $query = KnowledgeEntry::query()->where('knowledge_base_id', $base->getKey());

        // `trashed=1` lists the base's TRASH instead of its live entries — an entry-level trash, which
        // the base-level one (KnowledgeBaseService::index) cannot express: a base whose entries were
        // deleted one at a time is not itself deleted, so without this those entries are reachable only
        // by an id nobody wrote down. Same shape, same order, same resource; `deleted_at` is already on
        // the resource. Nothing brings them back one at a time any more — restoring the BASE restores
        // what it holds, and that is the only route left.
        if ($request->boolean('trashed')) {
            $query->onlyTrashed();
        }

        $statuses = array_values(array_filter(
            (array) $request->input('status', []),
            fn ($status) => in_array($status, KnowledgeEntryStatus::ids(), true),
        ));

        if ($statuses !== []) {
            $query->whereIn('status', $statuses);
        }

        if ($request->boolean('stale')) {
            $query->stale();
        }

        // The shared Searchable scope — term passed as an argument, never read from the request there.
        $query->search(['title', 'content'], $request->input('search'));

        return $query;
    }

    /** An entry's revisions, newest first. */
    public function revisions(KnowledgeEntry $entry): Collection
    {
        return $entry->revisions()
            ->with('creator')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    // ---- writes ---------------------------------------------------------------

    /**
     * $draftSessionId marks the new entry as an AI DRAFT, and it is a constructor argument rather than
     * something the caller stamps afterwards because the link passes below run inside this method: an
     * entry that is only marked as a draft after `create()` returns spends that window looking like a
     * real entry, and {@see KnowledgeLinkService::attachGhosts()} would resolve another entry's red
     * `[[link]]` to something invisible. Passing it in closes the window at the source.
     */
    public function create(KnowledgeBase $base, KnowledgeEntryDTO $dto, ?string $draftSessionId = null): KnowledgeEntry
    {
        return DB::transaction(function () use ($base, $dto, $draftSessionId) {
            $entry = new KnowledgeEntry([
                'knowledge_base_id' => $base->getKey(),
                'title' => $dto->title,
                'slug' => $this->mintSlug($base, $dto->slug ?? $dto->title),
                'content' => $dto->content,
                'metadata' => $dto->metadata,
                'aliases' => $dto->aliases,
                'entry_type' => $dto->entryType,
                'status' => $dto->status,
                'stale_at' => $dto->staleAt,
                'position' => $this->nextPosition($base),
                'draft_session_id' => $draftSessionId,
                // `[]` when the caller says nothing — a new entry claims no facts until it says so.
                'covers' => $dto->covers ?? [],
            ]);

            $this->stampIndexState($entry);
            $entry->save();

            $this->appendRevision($entry, $dto->changeNote);

            $this->links->syncWikilinks($entry);
            // A brand-new slug may be exactly what other entries have been pointing at all along.
            $this->links->attachGhosts($entry);

            return $entry;
        });
    }

    public function update(KnowledgeEntry $entry, KnowledgeEntryDTO $dto): KnowledgeEntry
    {
        $this->assertNotStale($entry, $dto->expectedRevisionId);

        return DB::transaction(function () use ($entry, $dto) {
            $previousSlug = $entry->slug;
            $previousContent = (string) $entry->content;
            // The digest of the entry AS IT STANDS, recomputed under the CURRENT config rather than
            // read off the column. The stored one was computed under whatever chunker version and
            // embedding model were configured when it was last saved, so comparing against it would
            // report "the author changed something" every time an OPERATOR changed the indexing
            // pipeline — appending an empty revision to the next unrelated edit of every entry in the
            // base. Computing both sides under the same parameters makes them cancel, leaving only a
            // genuine authored change.
            $previousDigest = KnowledgeDigest::for(
                (string) $entry->title,
                (string) $entry->content,
                is_array($entry->metadata) ? $entry->metadata : [],
            );

            $entry->fill([
                'title' => $dto->title,
                'content' => $dto->content,
                'metadata' => $dto->metadata,
                // NOT part of the index digest: aliases change how the entry is FOUND IN PROSE, not
                // what it says, so editing them must not restale its embeddings. They do change the
                // mention graph — which `links.version` covers, and which a sweep recomputes for free.
                'aliases' => $dto->aliases,
                // Also outside the digest, and for a sharper version of the same reason: an entry's
                // KIND is a fact about the graph, not about the document. Re-embedding a 40k-character
                // entry because somebody ticked "person" would be paying for an answer that cannot
                // have moved.
                'entry_type' => $dto->entryType,
                'status' => $dto->status,
                'stale_at' => $dto->staleAt,
            ]);

            // COVERS IS WRITTEN ONLY WHEN THE CALLER SAYS SOMETHING ABOUT IT.
            //
            // Null means "leave the column alone" and is what every non-composer caller passes, so an
            // ordinary save cannot erase a claim. Compare `entry_type` two lines up, which IS written
            // unconditionally — and which duly went null on every accepted amendment until the DTO
            // there was taught to carry it. One such field per module is enough.
            if ($dto->covers !== null) {
                $entry->covers = $dto->covers;
            }

            // A rename is deliberate and explicit; the title never touches the slug.
            //
            // AND IT IS CHECKED, because nothing else checks it. `create()` de-collides through
            // `mintSlug()`, and the column carries a plain index rather than a unique one (see the
            // migration for why the trash has to be able to hold a same-slug row) — so the only thing
            // keeping two live entries off one address on THIS path was that its single caller happened
            // to de-collide beforehand. A guarantee made of good manners, and withdrawing
            // hand-authorship removed every other writer who might have tripped over it first.
            //
            // A duplicate slug is not cosmetic: the slug is what `[[wikilinks]]` resolve against, so two
            // entries answering to one address make every link to it a coin toss.
            if ($dto->slug !== null && $dto->slug !== $previousSlug) {
                $base = $entry->base()->firstOrFail();

                if ($this->slugTaken($base, $dto->slug, $entry)) {
                    throw new KnowledgeSlugConflictException($dto->slug);
                }

                $entry->slug = $dto->slug;
            }

            $this->stampIndexState($entry);

            // What counts as a new VERSION of the document is exactly what the DIGEST covers
            // (title + content + metadata, canonicalized). Reusing it instead of comparing the three
            // fields by hand means a metadata map that merely came back with its keys in a different
            // order does not manufacture an empty revision — and the two notions of "changed" cannot
            // drift apart, because there is only one of them.
            $authoredChanged = $entry->index_digest !== $previousDigest;

            $entry->save();

            if ($authoredChanged) {
                $this->appendRevision($entry, $dto->changeNote);
            }

            if ($entry->slug !== $previousSlug) {
                // The old handle stops existing: edges that were addressed to it go back to being
                // ghosts (they still say what they meant), and anything waiting for the NEW handle is
                // adopted.
                $this->links->degradeIncomingToGhosts($entry);
                $this->links->attachGhosts($entry);
            }

            // Wikilinks are derived from the CONTENT and from nothing else, so a save that did not move
            // the body cannot have moved them. Skipping is not just an optimisation: syncWikilinks
            // deletes and re-inserts this entry's edges, so running it on every status flip would churn
            // rows (and their ids) for a set that is already correct.
            if ((string) $entry->content !== $previousContent) {
                $this->links->syncWikilinks($entry);
            }

            return $entry->refresh();
        });
    }

    /**
     * Re-derive an entry's edges from its current content, and adopt anything that was waiting for its
     * slug.
     *
     * Exists for ACCEPTANCE (B11b). A draft deliberately draws no edges and adopts no ghosts while it
     * is invisible — otherwise a real entry's red `[[cennik]]` would resolve to something the reader
     * cannot open. The moment a draft becomes a real entry, both passes have to run once, and they run
     * through here rather than being re-implemented by the composer.
     */
    public function syncLinks(KnowledgeEntry $entry): void
    {
        DB::transaction(function () use ($entry): void {
            $this->links->syncWikilinks($entry);
            $this->links->attachGhosts($entry);
        });
    }

    /** Soft delete. Links are left intact — a restore must bring the entry back whole. */
    public function delete(KnowledgeEntry $entry): void
    {
        $entry->delete();
    }

    /**
     * Restore a trashed entry. Refused (409) when its slug was taken while it was in the trash — see
     * {@see KnowledgeSlugConflictException} for why re-slugging silently would be the worse answer.
     */
    public function restore(KnowledgeEntry $entry): KnowledgeEntry
    {
        return DB::transaction(function () use ($entry) {
            $taken = KnowledgeEntry::query()
                ->where('knowledge_base_id', $entry->knowledge_base_id)
                ->where('slug', $entry->slug)
                ->whereKeyNot($entry->getKey())
                ->exists();

            if ($taken) {
                throw new KnowledgeSlugConflictException((string) $entry->slug);
            }

            $entry->restore();

            // Its slug exists again, so anything that was pointing at it stops being a ghost.
            $this->links->attachGhosts($entry);

            return $entry;
        });
    }

    /**
     * Destroy an entry and everything derived from it: its revision history, its chunks and the edges
     * it draws. Edges pointing AT it are degraded to ghosts rather than deleted — the other entries
     * still say what they said.
     *
     * The explicit deletes duplicate the database's ON DELETE cascades on purpose: the cascade is the
     * safety net, this is the intent, and only this side can express "incoming links survive as
     * ghosts" (a cascade would have to choose between deleting them and nulling them blindly).
     *
     * $eraseIncomingMatching narrows exactly ONE of those decisions for the erasure-request path
     * (`knowledge:purge-subject`): an inbound edge whose target slug names the subject is DELETED
     * rather than degraded, because that slug is the person's name and a ghost would keep rendering
     * it. It is a parameter rather than a second purge method on purpose — the cascade below is the
     * one place that knows what an entry is made of, and a copy of it for the erasure path would be a
     * copy that stops being updated.
     */
    public function purge(KnowledgeEntry $entry, ?SubjectPhrases $eraseIncomingMatching = null): void
    {
        DB::transaction(function () use ($entry, $eraseIncomingMatching) {
            // Before the degradation, so the two never contend for the same row.
            if ($eraseIncomingMatching !== null) {
                $this->links->deleteIncomingMatching($entry, $eraseIncomingMatching);
            }

            $this->links->degradeIncomingToGhosts($entry);
            $this->links->deleteOutgoing($entry);

            $entry->chunks()->delete();
            $entry->revisions()->delete();

            $entry->forceDelete();
        });
    }

    // NO `reorder()` AND NO `restoreRevision()`.
    //
    // Both were only ever reachable from a person: nothing in the composer or the applier called
    // either, unlike `create`/`update`/`delete`/`purge`, which are the AI's own write path and stay.
    // `position` is still set — at creation, by `nextPosition()` — so a base keeps a stable order; what
    // is gone is a person rearranging it.

    // ---- internals ------------------------------------------------------------

    /**
     * Append the entry's current authored state as a new revision and point `current_revision_id` at
     * it. That pointer doubles as the optimistic-lock token, so it must move on the SAME save as the
     * content it describes — hence the second, targeted update inside the caller's transaction.
     */
    private function appendRevision(KnowledgeEntry $entry, ?string $changeNote): KnowledgeEntryRevision
    {
        $revision = KnowledgeEntryRevision::create([
            'knowledge_entry_id' => $entry->getKey(),
            'title' => $entry->title,
            'content' => $entry->content,
            'metadata' => is_array($entry->metadata) ? $entry->metadata : [],
            'change_note' => $changeNote,
            'created_at' => now(),
        ]);

        $entry->current_revision_id = $revision->getKey();
        $entry->save();

        return $revision;
    }

    /**
     * Refuse the write when the caller's token is not the entry's current revision. A null token
     * means the caller did not ask for the check: the API is used by scripts and (later) by bots that
     * legitimately write blind, and forcing a token on them would only teach callers to fetch-then-
     * send one they never compared. The editor always sends it.
     */
    private function assertNotStale(KnowledgeEntry $entry, ?string $expectedRevisionId): void
    {
        if ($expectedRevisionId === null) {
            return;
        }

        if ($expectedRevisionId !== $entry->current_revision_id) {
            throw new StaleKnowledgeWriteException($entry->current_revision_id);
        }
    }

    /**
     * Recompute the entry's index digest and, when it moved, send it back to `pending` so the
     * embedder picks it up. `indexed_digest` is deliberately NOT touched: the pair is what tells B2a
     * whether the stored chunks are current, and clearing it here would throw away the knowledge that
     * they once were.
     */
    private function stampIndexState(KnowledgeEntry $entry): void
    {
        $digest = KnowledgeDigest::for(
            (string) $entry->title,
            (string) $entry->content,
            is_array($entry->metadata) ? $entry->metadata : [],
        );

        // The PIPELINE the digest was computed under, stored alongside it so a chunker/model change is
        // visible to the sweep in SQL. Written on every stamp so a normal save keeps the pair
        // consistent; when it is stale, the indexer restamps both before it does anything else.
        $parameters = KnowledgeDigest::parameters();

        if ($entry->index_digest === $digest && $entry->index_params === $parameters) {
            return;
        }

        $entry->index_digest = $digest;
        $entry->index_params = $parameters;
        $entry->index_status = KnowledgeIndexStatus::PENDING;
    }

    /**
     * A stable, unique handle for a new entry, derived from its first title.
     *
     * Collisions get a numeric suffix rather than a rejection: two entries called "Cennik" in
     * different corners of a base is normal, and refusing the second one would make the writer
     * invent a title to satisfy a URL. A title that slugs to nothing (emoji, CJK punctuation) falls
     * back to a neutral stem so the entry still gets an addressable handle.
     */
    private function mintSlug(KnowledgeBase $base, string $source): string
    {
        $stem = WikilinkParser::normalize($source);

        if ($stem === '') {
            $stem = 'entry';
        }

        $stem = mb_substr($stem, 0, 200);

        for ($attempt = 1; $attempt <= self::SLUG_ATTEMPTS; $attempt++) {
            $candidate = $attempt === 1 ? $stem : $stem . '-' . $attempt;

            if (!$this->slugTaken($base, $candidate)) {
                return $candidate;
            }
        }

        // Pathological case only (50 same-titled live entries): a random discriminator terminates.
        return $stem . '-' . bin2hex(random_bytes(4));
    }

    /**
     * Taken means taken by a LIVE entry — the trash deliberately does not reserve a slug.
     *
     * `$except` excludes the row being renamed, so re-saving an entry with the slug it already has is
     * not a collision with itself.
     */
    private function slugTaken(KnowledgeBase $base, string $slug, ?KnowledgeEntry $except = null): bool
    {
        return KnowledgeEntry::query()
            ->where('knowledge_base_id', $base->getKey())
            ->where('slug', $slug)
            ->when($except?->exists, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->exists();
    }

    /** New entries land at the end of the base's manual order. */
    private function nextPosition(KnowledgeBase $base): int
    {
        return (int) KnowledgeEntry::query()
            ->where('knowledge_base_id', $base->getKey())
            ->max('position') + 1;
    }
}
