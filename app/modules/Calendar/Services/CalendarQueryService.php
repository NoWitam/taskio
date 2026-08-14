<?php

namespace App\Modules\Calendar\Services;

use App\Modules\Calendar\DTOs\CalendarOccurrence;
use App\Modules\Calendar\DTOs\CalendarResult;
use App\Modules\Calendar\DTOs\CalendarTruncation;
use App\Modules\Calendar\DTOs\CalendarWindow;
use App\Modules\Calendar\Enums\CalendarTruncationKind;
use App\Modules\Calendar\Enums\CalendarUnavailableReason;

/**
 * Asks every requested source what it has inside the window, merges the answers into one ordered list,
 * and reports honestly on anything it had to leave out.
 *
 * It contains no knowledge of any source. Its whole job is fan-out, merge, order and bound — which is
 * what lets a fourth source appear without this file changing.
 */
class CalendarQueryService
{
    public function __construct(private CalendarSourceRegistry $registry) {}

    public function occurrences(CalendarWindow $window): CalendarResult
    {
        /** @var array<int, array{0: string, 1: CalendarOccurrence}> $decorated */
        $decorated = [];
        $labels = [];
        /** @var array<string, CalendarUnavailableReason> $unavailable */
        $unavailable = [];
        /** @var array<int, CalendarTruncation> $truncations */
        $truncations = [];

        foreach ($this->registry->ids() as $id) {
            // EVERY registered source is labelled, not merely the requested ones. The filter chips are
            // built from this list, so narrowing it to the current filter would delete the chip the user
            // just switched off — leaving them no way to switch it back on. A catalogue that shrinks as
            // you use it is not a catalogue.
            $labels[$id] = $this->registry->labelFor($id) ?? $id;

            if (!$window->wants($id)) {
                continue;
            }

            $result = $this->registry->occurrencesFor($id, $window);

            // A reason, never a bare "it did not work": whether trying again is worth anything is the
            // one thing a reader wants at this moment, and the registry already knew.
            if ($result instanceof CalendarUnavailableReason) {
                $unavailable[$id] = $result;

                continue;
            }

            foreach ($result->truncations as $truncation) {
                $truncations[] = $truncation;
            }

            foreach ($result->occurrences as $occurrence) {
                // Decorate-sort-undecorate: usort calls its comparator O(n log n) times, and each
                // sortKey() is a timezone conversion plus two format() calls. Computing it once per
                // occurrence instead of once per comparison is the difference between a few thousand
                // Carbon operations and a hundred thousand at the ceiling.
                $decorated[] = [$occurrence->sortKey($window->timezone), $occurrence];
            }
        }

        // Sort BEFORE trimming, so a global overflow keeps the EARLIEST occurrences in the window rather
        // than whichever source happened to be registered first. Every source therefore also orders its
        // OWN query ascending: a source that took its slice from the other end would have that slice
        // trimmed away wholesale here, contributing nothing while the response said only "some things
        // were cut".
        usort($decorated, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        if (count($decorated) > $window->maxOccurrences) {
            $kept = array_slice($decorated, 0, $window->maxOccurrences);

            // ATTRIBUTED, NOT ANNOUNCED. The global ceiling is enforced on the merged set, but what it
            // cuts still BELONGS to particular sources, and here — unlike everywhere else — the exact
            // figure is knowable: count what each source had and subtract what survived. So the "global"
            // trim is reported per source with exact numbers, and the response never has to fall back on
            // an unattributed "some things were cut".
            foreach ($this->countBySource($decorated) as $source => $before) {
                $after = $this->countBySource($kept)[$source] ?? 0;

                if ($before > $after) {
                    $truncations[] = new CalendarTruncation(
                        source: $source,
                        kind: CalendarTruncationKind::WINDOW_TRIMMED,
                        omittedOccurrences: $before - $after,
                    );
                }
            }

            $decorated = $kept;
        }

        return new CalendarResult(
            occurrences: array_column($decorated, 1),
            timezone: $window->timezone,
            truncations: $this->mergeTruncations($truncations),
            sources: $labels,
            unavailableSources: $unavailable,
        );
    }

    /**
     * At most ONE report per (source, kind), so a client never has to add up two entries that say the
     * same thing about the same source.
     *
     * The combining rule is the honest one: counts add, but an UNKNOWN count poisons the total. If a
     * source already bounded its own query, it can only say "and there were more" — and adding an exact
     * 2 to an unknown quantity does not make 2. Reporting the known part as though it were the whole
     * would be a smaller lie than saying nothing, but still a lie, and precisely the kind this response
     * shape exists to stop telling.
     *
     * @param  array<int, CalendarTruncation>  $truncations
     * @return array<int, CalendarTruncation>
     */
    private function mergeTruncations(array $truncations): array
    {
        /** @var array<string, CalendarTruncation> $merged */
        $merged = [];

        foreach ($truncations as $truncation) {
            $key = $truncation->source . '|' . $truncation->kind->value;
            $existing = $merged[$key] ?? null;

            if ($existing === null) {
                $merged[$key] = $truncation;

                continue;
            }

            $merged[$key] = new CalendarTruncation(
                source: $truncation->source,
                kind: $truncation->kind,
                omittedOccurrences: $this->addOrUnknown($existing->omittedOccurrences, $truncation->omittedOccurrences),
                affectedItems: $this->addOrUnknown($existing->affectedItems, $truncation->affectedItems),
            );
        }

        return array_values($merged);
    }

    /** Sum two counts, where either being unknown makes the sum unknown. */
    private function addOrUnknown(?int $a, ?int $b): ?int
    {
        return $a === null || $b === null ? null : $a + $b;
    }

    /**
     * How many occurrences each source contributed to a decorated list.
     *
     * @param  array<int, array{0: string, 1: CalendarOccurrence}>  $decorated
     * @return array<string, int>
     */
    private function countBySource(array $decorated): array
    {
        $counts = [];

        foreach ($decorated as [, $occurrence]) {
            $counts[$occurrence->source] = ($counts[$occurrence->source] ?? 0) + 1;
        }

        return $counts;
    }
}
