<?php

namespace Tests\Unit\Workflows;

use App\Modules\Workflows\Agents\ScheduleAssistAgent;
use App\Modules\Workflows\Enums\ScheduleLimits;
use PHPUnit\Framework\TestCase;

/**
 * The load-bearing CLAUSES of the schedule-assist instruction. These are prompt contents, and they
 * are pinned for the same reason the shot-list story clause and the ai-text length clause are: the
 * behaviour the owner reported as broken lived ENTIRELY in the prompt, so a silent edit that drops
 * one of these sentences reintroduces the bug with every test still green.
 *
 * The reported failures: "trzy razy dziennie — rano, popołudniu i wieczorem" and "codziennie
 * z wyjątkiem dni wolnych od pracy" both came back INFEASIBLE, although the descriptor expresses
 * both easily (three of six allowed `time.at` entries; weekdays + dated exclusions). The agent was
 * refusing on IMPRECISION and on the absence of a "holiday" feature — neither of which is a limit
 * of the vocabulary. The rule these tests guard: only the VOCABULARY may make a request infeasible.
 */
class ScheduleAssistAgentContractTest extends TestCase
{
    private function instructions(?string $today = '2026-07-30', ?string $tz = 'Europe/Warsaw'): string
    {
        return (string) (new ScheduleAssistAgent('pl', $today, $tz))->instructions();
    }

    /** Vagueness is a thing to DECIDE and disclose, never a reason to refuse. */
    public function test_under_specified_requests_must_be_decided_not_refused(): void
    {
        $instructions = $this->instructions();

        $this->assertStringContainsString('UNDER-SPECIFIED', $instructions);
        $this->assertStringContainsString('THE VOCABULARY IS THE ONLY LIMIT', $instructions);
        $this->assertStringContainsString('This list is EXHAUSTIVE', $instructions);

        // The conventional anchors that turn "rano/popołudniu/wieczorem" into real times.
        foreach (['morning 08:00', 'afternoon 15:00', 'evening 20:00'] as $anchor) {
            $this->assertStringContainsString($anchor, $instructions);
        }

        // …and the disclosure that keeps "choose a detail" from becoming "substitute a schedule".
        $this->assertStringContainsString('MUST name those values explicitly', $instructions);
    }

    /** Public holidays have no axis, but they ARE expressible — so they are never "unsupported". */
    public function test_holidays_are_expressed_as_dated_exclusions_not_reported_as_unsupported(): void
    {
        $instructions = $this->instructions();

        $this->assertStringContainsString('CALENDAR KNOWLEDGE', $instructions);
        $this->assertStringContainsString('dni wolne od pracy', $instructions);
        $this->assertStringContainsString('exclusions.dates', $instructions);
        $this->assertStringContainsString('weekdays [1,2,3,4,5]', $instructions);
        $this->assertStringContainsString('NOT unsupported, so never reported as such', $instructions);
    }

    /** The anchor the dated exclusions depend on — date + zone, both in the prompt. */
    public function test_the_calendar_anchor_carries_the_date_and_the_zone(): void
    {
        $instructions = $this->instructions('2026-07-30', 'Europe/Warsaw');

        $this->assertStringContainsString('Today is 2026-07-30', $instructions);
        $this->assertStringContainsString('The caller\'s timezone is "Europe/Warsaw"', $instructions);
    }

    /**
     * Honest degradation: with no anchor the model must NOT emit dated exclusions at all, rather
     * than guess a year. A wrong-year holiday list validates and compiles — it would fail silently.
     */
    public function test_without_an_anchor_dated_exclusions_are_forbidden_outright(): void
    {
        $instructions = $this->instructions(null, null);

        $this->assertStringContainsString('you do NOT know today\'s date', $instructions);
        $this->assertStringNotContainsString('Enumerate them FORWARD from today', $instructions);
    }

    /**
     * The dates cap shown to the model is READ FROM ScheduleLimits, so the prompt can never promise
     * a bigger holiday list than the validator accepts (the whole point of building the vocabulary
     * block programmatically).
     */
    public function test_the_dates_cap_tracks_the_validator(): void
    {
        $this->assertStringContainsString(
            'at most ' . ScheduleLimits::EXCLUSIONS_DATES_MAX,
            $this->instructions(),
        );
    }
}
