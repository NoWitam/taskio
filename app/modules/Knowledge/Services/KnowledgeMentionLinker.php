<?php

namespace App\Modules\Knowledge\Services;

use App\Modules\Knowledge\Enums\KnowledgeLinkSource;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Models\KnowledgeLink;
use App\Modules\Knowledge\Support\MentionScanner;
use Illuminate\Support\Facades\DB;

/**
 * Draws the `mention` edges of the graph: "this entry's prose NAMES that entry".
 *
 * ------------------------------------------------------------------------------------------------
 * THE PROBLEM IT SOLVES
 *
 * A knowledge graph built only from `[[wikilinks]]` is a graph built from a discipline nobody keeps.
 * People write "wieży Eiffla", not "[[wieza-eiffla]]", and asking them to think about link syntax
 * while writing is asking them to do the machine's job. Left as it was, a real base showed a screen
 * full of unconnected dots.
 *
 * So the connection is inferred from the words, by {@see MentionScanner}. This service owns everything
 * around that: which entries are candidates, which edges may be written, and — the half that is easy
 * to miss — that the inference has to run in BOTH directions.
 *
 * ------------------------------------------------------------------------------------------------
 * TWO DIRECTIONS, AND WHY THE SECOND ONE IS NOT OPTIONAL
 *
 * FORWARD is the obvious one: scan the entry being indexed for the titles of every other live entry.
 *
 * REVERSE is the one that decides whether the feature actually works. Ten notes already say "wieża
 * Eiffla"; today someone finally writes the entry called "Wieża Eiffla". Nothing edits those ten notes,
 * so with only a forward pass the new entry arrives with no inbound edges and stays that way until each
 * of them happens to be re-saved. The moment a name starts existing is exactly the moment the base
 * should notice it — the same reasoning that makes ghost wikilinks attach on create.
 *
 * The reverse pass is kept cheap by pre-filtering in SQL: one indexed `like` on the longest stem of
 * the new title narrows the base to plausible entries, and only those are verified with the real
 * scanner in PHP. It is bounded by `links.reverse_scan` (200) rather than left to run over a whole
 * base; a larger base simply finishes the job on the sweep's next pass, which is a far better failure
 * mode than a background job that grows with the workspace.
 *
 * ------------------------------------------------------------------------------------------------
 * WHAT IT WILL NOT DO
 *
 *   NO GHOSTS. A mention names an entry that EXISTS — that is the whole claim. A ghost mention would
 *   be asserting that prose refers to something nobody has written, which is not something a word
 *   match can know.
 *   NO DUPLICATES OF AN AUTHORED EDGE. If the entry already `[[links]]` to the target (or a human drew
 *   the edge), no mention is written: the author's explicit commitment is the stronger statement and
 *   two edges saying the same thing are a duplicate on screen. Similarity may coexist — "about the
 *   same subject" and "names it" are different claims.
 *   NO RESURRECTION. A dismissed pair is skipped exactly as in the similarity pass, keyed on
 *   `target_slug`, so "no, that is not a reference" survives every future re-index.
 */
class KnowledgeMentionLinker
{
    public function __construct(
        private MentionScanner $scanner,
    ) {}

    /**
     * Recompute the mention edges around one entry — those it draws, and those drawn AT it. Returns
     * how many rows were written in total.
     *
     * Safe on an entry that has vanished between dispatch and run.
     */
    public function link(string $entryId): int
    {
        $entry = KnowledgeEntry::query()->find($entryId);

        if ($entry === null) {
            return 0;
        }

        return $this->forward($entry) + $this->reverse($entry);
    }

    // ---- this entry names others ----------------------------------------------

    /** Rewrite the mention edges this entry DRAWS, from a scan of its own content. */
    private function forward(KnowledgeEntry $entry): int
    {
        $candidates = KnowledgeEntry::query()
            ->where('knowledge_base_id', $entry->knowledge_base_id)
            ->whereKeyNot($entry->getKey())
            // Titles AND aliases: an entry is named in prose by whichever surface form fits the
            // sentence, and the alias list is precisely the set of forms the stemmer cannot infer.
            ->get(['id', 'title', 'aliases'])
            ->mapWithKeys(fn (KnowledgeEntry $candidate): array => [
                (string) $candidate->getKey() => $candidate->mentionNames(),
            ])
            ->all();

        $mentions = $this->scanner->scan((string) $entry->content, $candidates);

        // First appearance wins the cap. Deterministic, and it matches how a reader would rank them:
        // what the document names first is what it is most likely to be about.
        uasort($mentions, static fn (array $a, array $b): int => $a['char_start'] <=> $b['char_start']);

        $targets = KnowledgeEntry::query()
            ->whereKey(array_keys($mentions))
            ->pluck('slug', 'id');

        return DB::transaction(function () use ($entry, $mentions, $targets): int {
            $blocked = $this->blockedTargets($entry);

            KnowledgeLink::query()
                ->where('from_entry_id', $entry->getKey())
                ->ofSource(KnowledgeLinkSource::MENTION)
                ->whereNull('dismissed_at')
                ->delete();

            $cap = max(0, (int) config('knowledge.links.max_mentions'));
            $written = 0;

            foreach ($mentions as $id => $evidence) {
                $slug = $targets->get($id);

                if ($written >= $cap || !is_string($slug) || in_array($slug, $blocked, true)) {
                    continue;
                }

                $this->write($entry, (string) $id, $slug, $evidence);
                $written++;
            }

            return $written;
        });
    }

    // ---- others name this entry ------------------------------------------------

    /**
     * Find entries whose prose already names THIS entry's title, and draw the edge from each of them.
     *
     * Additive rather than a rewrite: these rows belong to OTHER entries' mention sets, and each of
     * those will be rebuilt properly when that entry is itself re-indexed. Deleting them here would
     * make two entries' passes fight over the same rows.
     */
    private function reverse(KnowledgeEntry $entry): int
    {
        $names = $entry->mentionNames();
        $stems = $this->scanner->longestStems($names);

        if ($stems === []) {
            return 0; // only very short words to go on — too weak a handle to scan for
        }

        $sources = KnowledgeEntry::query()
            ->where('knowledge_base_id', $entry->knowledge_base_id)
            ->whereKeyNot($entry->getKey())
            // ONE query with the stems OR-ed, not a query per name: an alias list of ten would
            // otherwise turn a single indexed pre-filter into ten of them, each re-reading the same
            // rows. Still deliberately looser than the scanner (a stem, anywhere), so the pre-filter
            // can never hide a mention the verification would have found.
            ->where(function ($query) use ($stems): void {
                foreach ($stems as $stem) {
                    $query->orWhereLike('content', '%' . $stem . '%');
                }
            })
            ->orderBy('id')
            ->limit(max(0, (int) config('knowledge.links.reverse_scan')))
            ->get(['id', 'slug', 'knowledge_base_id', 'title', 'content']);

        $written = 0;

        foreach ($sources as $source) {
            $mentions = $this->scanner->scan((string) $source->content, [
                (string) $entry->getKey() => $names,
            ]);

            $evidence = $mentions[(string) $entry->getKey()] ?? null;

            if ($evidence === null) {
                continue;
            }

            $written += $this->writeReverse($source, $entry, $evidence);
        }

        return $written;
    }

    /**
     * Draw ONE inbound edge, respecting everything the forward pass respects — the source entry's own
     * cap, its authored edges, and any dismissal.
     *
     * @param  array{char_start: int, char_length: int}  $evidence
     */
    private function writeReverse(KnowledgeEntry $source, KnowledgeEntry $target, array $evidence): int
    {
        return DB::transaction(function () use ($source, $target, $evidence): int {
            $existing = KnowledgeLink::query()
                ->where('from_entry_id', $source->getKey())
                ->where('target_slug', $target->slug)
                ->get();

            foreach ($existing as $link) {
                // Already drawn (or already refused) — in every case, leave it alone.
                if ($link->source !== KnowledgeLinkSource::SIMILARITY) {
                    return 0;
                }
            }

            $mentions = KnowledgeLink::query()
                ->where('from_entry_id', $source->getKey())
                ->ofSource(KnowledgeLinkSource::MENTION)
                ->count();

            if ($mentions >= max(0, (int) config('knowledge.links.max_mentions'))) {
                return 0;
            }

            $this->write($source, (string) $target->getKey(), (string) $target->slug, $evidence);

            return 1;
        });
    }

    // ---- shared ----------------------------------------------------------------

    /**
     * Targets this entry must not draw a mention to: the ones it already reaches by an AUTHORED edge,
     * and the ones a human has dismissed.
     *
     * @return array<int, string> target slugs
     */
    private function blockedTargets(KnowledgeEntry $entry): array
    {
        return KnowledgeLink::query()
            ->where('from_entry_id', $entry->getKey())
            ->where(fn ($query) => $query
                ->whereIn('source', [KnowledgeLinkSource::WIKILINK->value, KnowledgeLinkSource::MANUAL->value])
                ->orWhere(fn ($dismissed) => $dismissed
                    ->ofSource(KnowledgeLinkSource::MENTION)
                    ->whereNotNull('dismissed_at')))
            ->pluck('target_slug')
            ->unique()
            ->values()
            ->all();
    }

    /** @param array{char_start: int, char_length: int} $evidence */
    private function write(KnowledgeEntry $from, string $toId, string $toSlug, array $evidence): void
    {
        KnowledgeLink::create([
            'knowledge_base_id' => $from->knowledge_base_id,
            'from_entry_id' => $from->getKey(),
            'to_entry_id' => $toId,
            'target_slug' => $toSlug,
            'source' => KnowledgeLinkSource::MENTION->value,
            // No score: a mention is not a measurement. Its evidence is WHERE the name appears, in the
            // module's character-offset convention, so a reader can be shown the sentence rather than
            // asked to trust the edge.
            'score' => null,
            'evidence' => $evidence,
        ]);
    }
}
