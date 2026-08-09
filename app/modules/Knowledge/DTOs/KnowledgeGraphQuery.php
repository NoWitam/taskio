<?php

namespace App\Modules\Knowledge\DTOs;

use App\Modules\Knowledge\Enums\KnowledgeLinkSource;
use Illuminate\Http\Request;

/**
 * WHAT slice of the graph a caller is asking for.
 *
 * Every field is a BOUND rather than a preference, which is why they are resolved here — once, from
 * the request — instead of being read out of `$request` deep inside the traversal. A graph query is
 * the one read in this module whose cost is set by the CALLER, so the place where those numbers stop
 * being user input has to be obvious and singular.
 *
 * `$centerId` null selects the OVERVIEW: the base's most connected entries rather than one entry's
 * neighbourhood. The two modes answer different questions ("what is this base shaped like" versus
 * "what does this entry touch") and share a cap, a filter set and a response shape.
 *
 * `$depth` is capped at 2 in the request rules and again here. Not arbitrary: at depth 3 an ego graph
 * of a well-linked base is the whole base, which is the overview with extra steps and a much more
 * expensive walk to reach it.
 */
final class KnowledgeGraphQuery
{
    /**
     * Edge kinds a graph shows unless asked otherwise — the three that are DERIVED from the content.
     *
     * `mention` is a default rather than an opt-in, and that is the decision the whole B10 layer rests
     * on: a base whose author never typed a `[[wikilink]]` has essentially no other edges, so hiding
     * mentions behind a toggle would leave the default view as the empty screen the layer exists to
     * fix. `manual` stays out for the opposite reason — it is not derived, so it belongs to whoever
     * asks for it.
     */
    public const DEFAULT_SOURCES = [
        KnowledgeLinkSource::WIKILINK->value,
        KnowledgeLinkSource::SIMILARITY->value,
        KnowledgeLinkSource::MENTION->value,
    ];

    public const MAX_DEPTH = 2;

    /**
     * @param  array<int, string>  $sources
     */
    public function __construct(
        public readonly ?string $centerId,
        public readonly int $depth,
        public readonly array $sources,
        public readonly float $minScore,
        public readonly bool $includeDismissed,
        /**
         * Whether TYPED RELATIONS are drawn alongside the derived links. Defaults to TRUE, and that is
         * a deliberate asymmetry with `manual`: a relation is the most reliable line in the picture —
         * somebody asserted and approved it — so hiding it behind an opt-in would leave the DEFAULT
         * graph showing the machine's guesses while omitting the base's actual facts.
         */
        public readonly bool $relations = true,
        /**
         * Whether ENDED and RETRACTED relations are drawn. Defaults to FALSE: the graph answers "what
         * is true", and a base with five years of history would otherwise draw every former membership
         * at the same weight as the current one, which is a picture that gets less readable the longer
         * the base is maintained.
         */
        public readonly bool $includeHistorical = false,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $requested = $request->input('sources', self::DEFAULT_SOURCES);

        // Accepts both `sources[]=wikilink&sources[]=manual` and the flatter `sources=wikilink,manual`
        // a hand-written link or a chart embed is far more likely to carry.
        $requested = is_string($requested) ? explode(',', $requested) : (array) $requested;

        $sources = array_values(array_filter(
            array_map(static fn ($source): string => is_string($source) ? trim($source) : '', $requested),
            static fn (string $source): bool => in_array($source, KnowledgeLinkSource::ids(), true),
        ));

        return new self(
            centerId: $request->filled('entry') ? (string) $request->input('entry') : null,
            depth: max(1, min(self::MAX_DEPTH, (int) $request->input('depth', 1))),
            sources: $sources === [] ? self::DEFAULT_SOURCES : $sources,
            // The floor an edge had to clear to be MADE is the natural floor for showing it: every
            // stored similarity edge already passed it, so the default hides nothing and a caller who
            // raises it gets exactly the tightening they asked for.
            //
            // Which means the LOWEST bar that can produce an edge, not the standard one. Since the
            // linker became length-aware a short-text pair is stored at `threshold_short`, and
            // defaulting to `threshold` here would filter those rows straight back out — the graph
            // would stay empty while the database was full, which is the worst possible version of
            // this bug because nothing about it is visible from either side. `min()` rather than the
            // short constant so a configuration that inverts the two still shows everything stored.
            minScore: $request->filled('min_score')
                ? (float) $request->input('min_score')
                : min(
                    (float) config('knowledge.similarity.threshold'),
                    (float) config('knowledge.similarity.threshold_short'),
                ),
            includeDismissed: $request->boolean('include_dismissed'),
            // Present-and-false is the only way to turn relations off; an absent flag means on.
            relations: !$request->has('relations') || $request->boolean('relations'),
            includeHistorical: $request->boolean('include_historical'),
        );
    }
}
