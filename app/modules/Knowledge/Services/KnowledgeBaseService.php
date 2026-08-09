<?php

namespace App\Modules\Knowledge\Services;

use App\Modules\Knowledge\DTOs\KnowledgeBaseDTO;
use App\Modules\Knowledge\Enums\KnowledgeIndexStatus;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Models\KnowledgeEntryChunk;
use App\Modules\Knowledge\Models\KnowledgeEntryRevision;
use App\Modules\Knowledge\Models\KnowledgeLink;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Business logic + persistence for KNOWLEDGE BASES.
 *
 * The interesting part is the CASCADE, and specifically that it is done here rather than by the
 * database. Trashing a base soft-deletes its entries by stamping them with the base's own
 * `deleted_at`; restoring the base restores exactly the entries that fell WITH it (deleted_at at or
 * after that instant), leaving entries somebody had trashed individually beforehand where they were.
 *
 * A database cascade cannot express that. `ON DELETE CASCADE` does not fire for a soft delete at all,
 * and a trigger-based one would have no way to tell "fell with the base" from "was already in the
 * trash" on the way back — so restoring a base would silently resurrect entries that had been
 * deliberately removed weeks earlier. The FK cascades that DO exist stay as the safety net under
 * {@see purge()}, where deletion really is permanent.
 */
class KnowledgeBaseService
{
    public function index(Request $request): CursorPaginator
    {
        // No withCount('entries') here: `entries_count` is filled by attachAggregates() from the SAME
        // grouped query that produces the index summary, so counting it a second time would be a second
        // sub-select per page whose result is immediately overwritten.
        return KnowledgeBase::query()
            ->when($request->boolean('trashed'), fn ($query) => $query->onlyTrashed())
            ->with('creator')
            ->search(['name', 'description'], $request->input('search'))
            ->orderBy('name')
            // The order ENDS in the primary key: a cursor seek on a non-unique column alone
            // skips/duplicates rows at a page edge.
            ->orderBy('id')
            ->cursorPaginate(25)
            ->withQueryString();
    }

    /**
     * Attach the two aggregates a base CARD needs: how many red links it has, and where its indexing
     * stands. Two grouped queries for the WHOLE page, never one per base.
     *
     * This exists because the alternative is an N+1 the front end cannot avoid. A card shows an index
     * badge and a red-link chip; without these fields a list of 25 bases is 25 extra requests, and the
     * first thing anyone would build instead is a per-base "stats" endpoint called in a loop — the
     * same N+1 with more moving parts. Both numbers are cheap grouped counts, so they ride along with
     * the list they belong to.
     *
     * The results are attached as MODEL ATTRIBUTES rather than passed beside the collection, which is
     * the same mechanism `withCount` uses and what lets {@see \App\Modules\Knowledge\Http\Resources\KnowledgeBaseResource}
     * read them with no special casing. They are display-only: nothing saves a base after this runs,
     * and nothing should.
     *
     * @param  iterable<int, KnowledgeBase>  $bases
     */
    public function attachAggregates(iterable $bases): void
    {
        $models = collect($bases)->filter(fn ($base): bool => $base instanceof KnowledgeBase);
        $ids = $models->map(fn (KnowledgeBase $base): string => (string) $base->getKey())->all();

        if ($ids === []) {
            return;
        }

        $index = $this->indexSummaries($ids);
        $ghosts = $this->ghostCounts($ids);

        foreach ($models as $base) {
            $id = (string) $base->getKey();
            $summary = $index[$id] ?? self::emptyIndexSummary();

            $base->setAttribute('index_summary', $summary);
            $base->setAttribute('ghost_links_count', $ghosts[$id] ?? 0);
            // The same number `withCount('entries')` produces, filled here too so a base that was just
            // created or updated carries the whole card contract rather than most of it.
            $base->setAttribute('entries_count', $summary['total']);
        }
    }

    /**
     * Live entries per base, broken down by indexing state. Trashed entries are excluded by the
     * model's soft-delete scope, so `total` is exactly the base's entry count.
     *
     * @param  array<int, string>  $ids
     * @return array<string, array<string, int>>
     */
    private function indexSummaries(array $ids): array
    {
        $rows = KnowledgeEntry::query()
            ->select(['knowledge_base_id', 'index_status'])
            ->selectRaw('count(*) as entries_total')
            ->whereIn('knowledge_base_id', $ids)
            ->groupBy('knowledge_base_id', 'index_status')
            ->get();

        $summaries = [];

        foreach ($rows as $row) {
            $base = (string) $row->knowledge_base_id;
            $status = $row->index_status?->value;
            $count = (int) $row->getAttribute('entries_total');

            $summaries[$base] ??= self::emptyIndexSummary();
            $summaries[$base]['total'] += $count;

            if ($status !== null && array_key_exists($status, $summaries[$base])) {
                $summaries[$base][$status] += $count;
            }
        }

        return $summaries;
    }

    /**
     * Unresolved links per base — the "you referenced something that does not exist" count.
     *
     * Dismissed ghosts are excluded (a human said the reference is fine as it is), and so are ghosts
     * drawn by a TRASHED entry: a red link in a deleted document is not something anybody can act on,
     * and counting it would leave a base permanently reporting work that cannot be done.
     *
     * @param  array<int, string>  $ids
     * @return array<string, int>
     */
    private function ghostCounts(array $ids): array
    {
        return KnowledgeLink::query()
            ->select('knowledge_base_id')
            ->selectRaw('count(*) as ghost_total')
            ->whereIn('knowledge_base_id', $ids)
            ->whereNull('to_entry_id')
            ->whereNull('dismissed_at')
            ->whereHas('fromEntry')
            ->groupBy('knowledge_base_id')
            ->get()
            ->mapWithKeys(fn (KnowledgeLink $row): array => [
                (string) $row->knowledge_base_id => (int) $row->getAttribute('ghost_total'),
            ])
            ->all();
    }

    /** @return array<string, int> */
    private static function emptyIndexSummary(): array
    {
        $summary = ['total' => 0];

        foreach (KnowledgeIndexStatus::ids() as $status) {
            $summary[$status] = 0;
        }

        return $summary;
    }

    public function create(KnowledgeBaseDTO $dto): KnowledgeBase
    {
        $base = new KnowledgeBase($this->attributes($dto));
        $base->save();

        return $base;
    }

    public function update(KnowledgeBase $base, KnowledgeBaseDTO $dto): KnowledgeBase
    {
        $base->fill($this->attributes($dto));
        $base->save();

        return $base->refresh();
    }

    /** Trash the base and everything currently live inside it — reversibly. See the class docblock. */
    public function delete(KnowledgeBase $base): void
    {
        DB::transaction(function () use ($base) {
            $base->delete();

            // Read the STORED value back: the column has second precision, so the in-memory Carbon
            // (microseconds) would not compare equal to what a restore later reads.
            $base->refresh();

            // toBase(): the scopes (workspace + "not already trashed") still apply, but Eloquent's
            // automatic updated_at stamp does NOT. Trashing a base is not an edit of the entries it
            // takes with it, and bumping their modification time would misreport who last touched
            // them — the one thing a knowledge base is expected to get right.
            KnowledgeEntry::query()
                ->where('knowledge_base_id', $base->getKey())
                ->toBase()
                ->update(['deleted_at' => $base->deleted_at]);
        });
    }

    /** Restore the base and exactly the entries that fell with it. */
    public function restore(KnowledgeBase $base): KnowledgeBase
    {
        return DB::transaction(function () use ($base) {
            $cascadedAt = $base->deleted_at;

            $base->restore();

            if ($cascadedAt !== null) {
                KnowledgeEntry::onlyTrashed()
                    ->where('knowledge_base_id', $base->getKey())
                    // ">=" not "=": anything trashed at or after the cascade instant fell with the
                    // base; anything trashed before it was already in the trash on its own.
                    ->where('deleted_at', '>=', $cascadedAt)
                    ->restore();
            }

            return $base->refresh();
        });
    }

    /**
     * Destroy a base and everything under it, permanently. Entries are force-deleted through their
     * own table rather than left to the FK cascade so the derived rows (links, chunks, revisions) are
     * removed in an order this side controls; the cascades remain as the net beneath it.
     */
    public function purge(KnowledgeBase $base): void
    {
        DB::transaction(function () use ($base) {
            // A sub-query, not a plucked id list: a large base should not have to fit in memory to be
            // deleted.
            $entryIds = KnowledgeEntry::withTrashed()
                ->where('knowledge_base_id', $base->getKey())
                ->select('id');

            KnowledgeLink::query()->where('knowledge_base_id', $base->getKey())->delete();
            KnowledgeEntryChunk::query()->whereIn('knowledge_entry_id', $entryIds)->delete();
            KnowledgeEntryRevision::query()->whereIn('knowledge_entry_id', $entryIds)->delete();

            KnowledgeEntry::withTrashed()
                ->where('knowledge_base_id', $base->getKey())
                ->forceDelete();

            $base->forceDelete();
        });
    }

    /** @return array<string, mixed> */
    private function attributes(KnowledgeBaseDTO $dto): array
    {
        return [
            'name' => $dto->name,
            'description' => $dto->description,
            'charter' => $dto->charter,
            'metadata_schema' => $dto->metadataSchema,
            // NULL is written as null, on purpose: it means "the whole vocabulary, whatever it grows
            // to", which an expanded list of today's verbs could not express.
            'relation_types' => $dto->relationTypes,
            'language' => $dto->language,
        ];
    }
}
