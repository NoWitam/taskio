<?php

namespace Database\Factories;

use App\Modules\Calendar\DTOs\CalendarRecurrence;
use App\Modules\Calendar\Models\CalendarEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CalendarEvent>
 *
 * The DEFAULT state is a timed event, and the two shapes are reached through {@see allDay()} /
 * {@see timed()} rather than by setting columns — the same discipline the DTO enforces on production
 * writes. A factory that let a test set `start_date` alongside `starts_at` would let a test assert
 * against a row no production path can create, and the assertion would keep passing after the invariant
 * broke.
 */
class CalendarEventFactory extends Factory
{
    protected $model = CalendarEvent::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->sentence(3),
            'description' => null,
            'all_day' => false,
            'start_date' => null,
            'starts_at' => CarbonImmutable::parse('2026-08-10 09:00:00', 'UTC'),
            'ends_at' => CarbonImmutable::parse('2026-08-10 10:00:00', 'UTC'),
            'subject_type' => null,
            'subject_id' => null,
        ];
    }

    /** An event that occupies a DAY: `start_date` only, both instants blank. */
    public function allDay(string $date): static
    {
        return $this->state(fn (): array => [
            'all_day' => true,
            'start_date' => $date,
            'starts_at' => null,
            'ends_at' => null,
        ]);
    }

    /** An event that happens at a MOMENT: instants only, no day string. */
    public function timed(string $startsAt, ?string $endsAt = null): static
    {
        return $this->state(fn (): array => [
            'all_day' => false,
            'start_date' => null,
            'starts_at' => CarbonImmutable::parse($startsAt, 'UTC'),
            'ends_at' => $endsAt === null ? null : CarbonImmutable::parse($endsAt, 'UTC'),
        ]);
    }

    /**
     * A REPEATING event, with the rule handed over already stamped — normally by asking
     * {@see \App\Modules\Calendar\Services\CalendarRecurrenceService} for one, exactly as the write path
     * does.
     *
     * There is deliberately no "give me a weekly series" shortcut that assembles a descriptor from
     * loose arguments. A hand-built descriptor is one whose ANCHOR nobody checked, and a fixture whose
     * anchor is not its own first occurrence is a row the production write path cannot create — so a
     * test written against it would keep passing after the invariant it depends on had broken. Building
     * the rule through the real service is what keeps the fixture and the product in step.
     */
    public function repeating(CalendarRecurrence $recurrence): static
    {
        return $this->state(fn (): array => [
            'recurrence' => $recurrence->descriptor,
            'recurrence_until' => $recurrence->until,
        ]);
    }
}
