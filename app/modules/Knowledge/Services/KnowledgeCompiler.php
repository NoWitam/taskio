<?php

namespace App\Modules\Knowledge\Services;

use App\Modules\Knowledge\DTOs\CompiledKnowledge;
use App\Modules\Knowledge\Enums\KnowledgeBindingMode;
use App\Modules\Knowledge\Enums\KnowledgeEntryStatus;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeBinding;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Support\KnowledgeFence;
use Illuminate\Support\Collection;

/**
 * INLINE compilation: the whole approved base, in the author's order, as one fenced DATA block.
 *
 * Costs NOTHING — no embedding, no provider, no vector column. That is the reason it exists and the reason
 * it is the fallback for every way retrieval can fail. A knowledge base that can only be read by spending
 * money is a knowledge base that goes dark when a budget is reached, and "the bot forgot everything it
 * knows because the month's cap was hit" is not a degradation anybody would accept.
 *
 * ------------------------------------------------------------------------------------------------
 * THREE DECISIONS WORTH DEFENDING
 *
 * ONLY `approved`. The human search shows drafts on purpose (a writer is usually looking for something
 * they half-wrote); this is the opposite situation. Text compiled here is handed to a model AS FACT and
 * repeated to a customer with the workspace's voice behind it, so a half-written draft and a deliberately
 * retired `archived` entry are both worse than silence. The editorial vocabulary exists precisely so this
 * line can be drawn, and drawing it differently from search is the point rather than an inconsistency.
 *
 * NEVER CUT AN ENTRY. An entry that does not fit the remaining budget is dropped WHOLE. Truncating mid-way
 * is how a model ends up reading "refunds are accepted within" and confidently completing the sentence
 * itself — a half-fact reads exactly like a fact and is unfalsifiable from inside the prompt. Dropping is
 * visible; cutting is not. A later, shorter entry may still fit, so packing continues rather than stopping
 * at the first miss: the manual `position` order says what is most important, and honouring it while still
 * filling the budget is strictly better than truncating the list at the first big document.
 *
 * SAY WHAT WAS LEFT OUT. The omission marker names the missing TITLES inside the block, so the model knows
 * its picture is partial and can say "I don't have that here" instead of inventing the answer. Its cost is
 * charged to the same budget (see {@see compile()}), so the cap is a real cap and not a cap plus however
 * much the marker happens to need.
 */
class KnowledgeCompiler
{
    /** How many omitted titles the marker names before it summarises the rest. */
    private const MAX_OMITTED_TITLES = 25;

    /**
     * Backstop on the reserve/pack iteration. The loop's own exit condition is the real one; this only
     * bounds the pathological case, and it is small on purpose — each pass is a full re-pack, and a
     * budget that has not settled in a handful of passes will not settle in fifty.
     */
    private const MAX_PACK_PASSES = 8;

    /** The fixed opening of the omission marker (157 characters, and part of every `$room` sum). */
    private const OMISSION_PREFIX = 'NOT SHOWN (character budget). These entries exist in this base but '
        . 'were left out of this block; say so rather than guessing if one of them is what you need: ';

    /**
     * The whole approved base for a binding, bounded by $maxChars, or NULL when the base is gone or holds
     * nothing approved (an empty DATA block is worse than no block: it tells a model there is a knowledge
     * base and that it is empty, which is rarely what an unindexed or draft-only base means).
     *
     * $maxChars bounds the BLOCK CONTENT — everything between the fence markers. The label and the two
     * markers are fixed framing of about 200 characters; charging the caller's knowledge budget for the
     * frame would make the number mean something different for every fence.
     */
    public function compileForBinding(KnowledgeBinding $binding, int $maxChars): ?CompiledKnowledge
    {
        $base = $binding->base()->first();

        if ($base === null) {
            return null;
        }

        return $this->compile($base, $this->approvedEntries($base), $maxChars);
    }

    /** Whether the base holds anything a consumer is allowed to be told. */
    public function hasApprovedEntries(KnowledgeBase $base): bool
    {
        return KnowledgeEntry::query()
            ->where('knowledge_base_id', $base->getKey())
            ->where('status', KnowledgeEntryStatus::APPROVED->value)
            ->exists();
    }

    /**
     * The base's approved entries in the author's manual order. The order ENDS in the primary key so two
     * entries sharing a position cannot swap places between two reads — a bot whose knowledge silently
     * reorders itself is a bot whose behaviour cannot be reproduced.
     *
     * @return Collection<int, KnowledgeEntry>
     */
    private function approvedEntries(KnowledgeBase $base): Collection
    {
        return KnowledgeEntry::query()
            ->where('knowledge_base_id', $base->getKey())
            ->where('status', KnowledgeEntryStatus::APPROVED->value)
            ->orderBy('position')
            ->orderBy('id')
            ->get(['id', 'title', 'content', 'current_revision_id']);
    }

    /**
     * Pack the header and as many whole entries as fit, then name what was left out.
     *
     * The omission marker's length depends on WHICH entries were omitted, and omitting more makes it
     * longer — so budget and marker are mutually defined. Resolved by a short iteration rather than by
     * reserving a worst-case block up front (which would waste most of a small base's budget on a marker
     * that never appears).
     *
     * THE RESERVE ONLY EVER GROWS, and that is the whole correctness argument. An earlier version
     * re-packed against "the previous pass's marker", assuming a smaller budget could only omit MORE.
     * It cannot: the packer is greedy, so shrinking the budget can drop one large entry and let two
     * small ones in — a DIFFERENT omission set, with a different marker length, which lets the two
     * passes chase each other forever (a period-2 oscillation, e.g. blocks of 520/160/300 against 740).
     * The loop then fell out on its iteration cap holding a `$packed` computed under a reserve that did
     * not match its own marker, and emitted more than it was given.
     *
     * Taking the running MAXIMUM makes the budget sequence monotonically non-increasing, so the reserve
     * can only rise a bounded number of times (the marker is itself bounded, see
     * {@see omissionMarker()}) and the loop terminates on `$needed <= $reserve` — the exact invariant
     * the output needs. The pass cap remains as a backstop, and the marker is fitted to the REAL
     * remaining room at render time, so even a hypothetical non-termination cannot overflow.
     *
     * @param  Collection<int, KnowledgeEntry>  $entries
     */
    private function compile(KnowledgeBase $base, Collection $entries, int $maxChars): ?CompiledKnowledge
    {
        if ($entries->isEmpty()) {
            return null;
        }

        $header = $this->header($base);
        $blocks = [];

        foreach ($entries as $entry) {
            $blocks[(string) $entry->getKey()] = $this->entryBlock($entry);
        }

        $budget = max(0, $maxChars);
        $reserve = 0;
        $packed = $this->pack($entries, $blocks, $header, $budget);

        for ($pass = 0; $pass < self::MAX_PACK_PASSES; $pass++) {
            if ($packed['omitted'] === []) {
                break; // nothing to announce, so nothing to reserve
            }

            $needed = mb_strlen($this->omissionMarker($packed['omitted'])) + 2; // + the "\n\n" join

            if ($needed <= $reserve) {
                break; // the space already held back covers this pass's marker
            }

            $reserve = $needed;
            $packed = $this->pack($entries, $blocks, $header, $budget - $reserve);
        }

        $parts = [];
        $used = 0;

        if ($packed['header']) {
            $parts[] = $header;
            $used = mb_strlen($header);
        }

        foreach ($packed['included'] as $entry) {
            $block = $blocks[(string) $entry->getKey()];
            $used += mb_strlen($block) + ($parts === [] ? 0 : 2);
            $parts[] = $block;
        }

        if ($packed['omitted'] !== []) {
            // FITTED to what is actually left, not to what was reserved. The loop above makes the two
            // agree in every case anyone will meet; this is the belt to that pair of braces, and it is
            // what makes "the content never exceeds maxChars" true by CONSTRUCTION rather than by an
            // argument about a fixed point. A marker with no room at all is dropped entirely.
            $marker = $this->omissionMarker($packed['omitted'], $budget - $used - ($parts === [] ? 0 : 2));

            if ($marker !== '') {
                $parts[] = $marker;
            }
        }

        if ($parts === []) {
            // A budget too small to hold even the header or the notice. An EMPTY fenced block is worse
            // than none — it tells a model the workspace has a knowledge base and that it is empty —
            // so this is the same answer as a base with nothing approved in it.
            return null;
        }

        return new CompiledKnowledge(
            text: KnowledgeFence::block()->render(KnowledgeFence::LABEL, implode("\n\n", $parts)),
            baseId: (string) $base->getKey(),
            mode: KnowledgeBindingMode::INLINE,
            entryIds: array_map(fn (KnowledgeEntry $entry): string => (string) $entry->getKey(), $packed['included']),
            revisionIds: array_values(array_filter(array_map(
                fn (KnowledgeEntry $entry): ?string => $entry->current_revision_id,
                $packed['included'],
            ))),
            omittedTitles: $packed['omitted'],
        );
    }

    /**
     * One packing pass at a fixed budget.
     *
     * @param  Collection<int, KnowledgeEntry>  $entries
     * @param  array<string, string>  $blocks
     * @return array{header: bool, included: array<int, KnowledgeEntry>, omitted: array<int, string>}
     */
    private function pack(Collection $entries, array $blocks, string $header, int $budget): array
    {
        $used = 0;
        $headerFits = mb_strlen($header) <= $budget;

        if ($headerFits) {
            $used = mb_strlen($header);
        }

        $included = [];
        $omitted = [];

        foreach ($entries as $entry) {
            $block = $blocks[(string) $entry->getKey()];
            // Every part after the first is joined by a blank line, so the separator is part of the cost.
            $cost = mb_strlen($block) + ($used > 0 ? 2 : 0);

            if ($used + $cost > $budget) {
                $omitted[] = $this->title($entry);

                continue;
            }

            $used += $cost;
            $included[] = $entry;
        }

        return ['header' => $headerFits, 'included' => $included, 'omitted' => $omitted];
    }

    /**
     * The block's header: which base this is and what it is FOR. The charter is the base's own statement
     * of scope, and giving it to the model is what lets it tell "not in my knowledge base" from "not in
     * this base's remit" — two different answers a reader hears very differently.
     *
     * PUBLIC because {@see KnowledgeRetrievalService} renders the same header above its passages: a base's
     * identity must not depend on which mode happened to produce the block, and two copies of this would
     * be two things to keep in step.
     */
    public function header(KnowledgeBase $base): string
    {
        $header = 'BASE: ' . KnowledgeFence::sanitize((string) $base->name);

        if (filled($base->charter)) {
            $header .= "\nCHARTER: " . KnowledgeFence::sanitize((string) $base->charter);
        }

        return $header;
    }

    /** One entry as `## Title` + body, both sanitized — an entry may itself contain fence markers. */
    private function entryBlock(KnowledgeEntry $entry): string
    {
        return '## ' . $this->title($entry) . "\n" . KnowledgeFence::sanitize((string) $entry->content);
    }

    private function title(KnowledgeEntry $entry): string
    {
        return KnowledgeFence::sanitize((string) $entry->title);
    }

    /**
     * What was left out, named — and never longer than the room it is given.
     *
     * Two bounds, for two different failure modes. {@see MAX_OMITTED_TITLES} is an EDITORIAL bound: a
     * base of 300 entries would otherwise spend most of an 8 000-character budget listing its own
     * titles, and past a couple of dozen names the useful signal is the count. `$room` is a HARD one: a
     * title may be 255 characters, so twenty-five of them plus the prefix is ~6.6 kB — comfortably more
     * than a small consumer's entire budget. Without it the notice about overflowing the budget could
     * itself overflow the budget, which is the kind of bug that only shows up on the one workspace that
     * writes long titles.
     *
     * Titles are dropped from the END until it fits (they are in `position` order, so the ones that
     * survive are the ones the author ranked highest) and the dropped count is folded into the "+N more"
     * tail, which is why shortening the list never makes the marker LONGER. When not even the bare
     * notice fits, the empty string tells the caller to drop it: a truncated sentence mid-word would be
     * worse than silence, and the block is already honest about being partial by being short.
     *
     * @param  array<int, string>  $titles
     * @param  int|null  $room  characters available; null = no hard bound (the reserve-sizing pass)
     */
    private function omissionMarker(array $titles, ?int $room = null): string
    {
        $shown = array_slice($titles, 0, self::MAX_OMITTED_TITLES);
        $hidden = count($titles) - count($shown);

        while (true) {
            $marker = self::OMISSION_PREFIX
                . ($shown === [] ? $hidden . ' entries' : implode('; ', $shown) . ($hidden > 0 ? '; +' . $hidden . ' more' : ''));

            if ($room === null || mb_strlen($marker) <= $room) {
                return $marker;
            }

            if ($shown === []) {
                return ''; // not even the bare notice fits
            }

            array_pop($shown);
            $hidden++;
        }
    }
}
