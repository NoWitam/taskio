<?php

namespace App\Modules\Calendar\Http\Controllers;

use App\Modules\Calendar\Enums\CalendarUnavailableReason;
use App\Modules\Calendar\Http\Requests\CalendarWindowRequest;
use App\Modules\Calendar\Http\Resources\CalendarOccurrenceResource;
use App\Modules\Calendar\Services\CalendarQueryService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CalendarOccurrenceController
{
    public function __construct(private CalendarQueryService $query) {}

    /**
     * GET /api/calendar/occurrences
     *
     * Deliberately NOT paginated. A calendar window is bounded by its own dates (62 days at most) and
     * by the occurrence ceiling; a cursor over a set merged from several independent sources would have
     * no stable seek key — computed occurrences have no row to page from — and "page 2 of Thursday" is
     * not a thing anybody wants. What does not fit is reported (`meta.truncated`), not paged.
     */
    public function index(CalendarWindowRequest $request): AnonymousResourceCollection
    {
        $result = $this->query->occurrences($request->toWindow());

        return CalendarOccurrenceResource::collection($result->occurrences)->additional([
            'meta' => [
                // The zone every day boundary in this response was reckoned in. Sent so the UI can say
                // whose midnight this is — a user must never have to guess whether "Friday" is theirs
                // or the team's.
                'timezone' => $result->timezone,

                // A convenience roll-up — "is anything missing at all" — for a client that only needs
                // to decide whether to show a banner. It is DERIVED from `truncations`, never stored
                // beside it, so the two cannot disagree.
                'truncated' => $result->isTruncated(),

                // THE TRUTH, PER SOURCE. Which source is incomplete, in what WAY, and by how much.
                // One boolean used to stand for three structurally different losses — the far end of
                // the window cut, one item repeating faster than it is drawn, and whole items absent
                // from the grid entirely — so anything rendered from it was wrong in two cases out of
                // three. The `kind` vocabulary is closed and owned by the Calendar (a new source
                // cannot add one), so a client words three fixed cases and still needs no per-source
                // knowledge. Counts are null when genuinely unknown, never zero.
                'truncations' => array_map(fn ($truncation): array => [
                    'source' => $truncation->source,
                    'kind' => $truncation->kind->value,
                    'omitted_occurrences' => $truncation->omittedOccurrences,
                    'affected_items' => $truncation->affectedItems,
                ], $result->truncations),

                // EVERY registered source, with its translated name — the filter catalogue, not a
                // report on this request. It is deliberately independent of the `sources` filter: a
                // list that shrank to the current selection would delete the chip the user just
                // switched off, leaving no way to switch it back on. The client needs no per-source
                // knowledge either way, which is what keeps "a new source needs no frontend change"
                // true past the first filter interaction.
                'sources' => collect($result->sources)
                    ->map(fn (string $label, string $id): array => ['id' => $id, 'label' => $label])
                    ->values()
                    ->all(),

                // Sources that were asked and could not answer, each with WHY (see
                // CalendarSourceRegistry). Empty in the ordinary case; never omitted, so its absence
                // can't be mistaken for its emptiness.
                //
                // The `reason` is a closed CalendarUnavailableReason vocabulary and it is here to be
                // BRANCHED ON, not merely displayed: `failed` is worth a retry, `not_constructed` and
                // `malformed` are not, and a list of bare ids could not tell a user which of those they
                // were looking at. Shaped like `truncations` — `source` plus a code — so the two
                // partial-answer channels read the same way.
                'unavailable_sources' => collect($result->unavailableSources)
                    ->map(fn (CalendarUnavailableReason $reason, string $id): array => [
                        'source' => $id,
                        'reason' => $reason->value,
                    ])
                    ->values()
                    ->all(),
            ],
        ]);
    }
}
