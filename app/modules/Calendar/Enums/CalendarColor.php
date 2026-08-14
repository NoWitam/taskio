<?php

namespace App\Modules\Calendar\Enums;

/**
 * The colour vocabulary a calendar occurrence may carry.
 *
 * Deliberately NOT a new palette: these are exactly the semantic variants the `next` Badge/chip
 * primitives already understand (`resources/js/next/ui/primitives/Badge.vue`), so a source picks a
 * MEANING and the design system picks the pixels — dark mode, contrast and token changes included.
 *
 * `modified` (the project-wide "drifted from a snapshot" variant) is deliberately excluded: it is a
 * diff semantic, not a calendar one, and a source reaching for it would be saying something the grid
 * has no way to explain.
 */
enum CalendarColor: string
{
    case NEUTRAL = 'neutral';
    case PRIMARY = 'primary';
    case SUCCESS = 'success';
    case WARNING = 'warning';
    case DANGER = 'danger';
    case INFO = 'info';

    /** @return array<int, string> */
    public static function ids(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Adopt a `tone()` string from one of the existing domain enums (TaskPriority, WorkflowRunState,
     * TaskStatus, …). Those already speak this exact vocabulary, so a source reuses its own model's
     * tone instead of inventing a second mapping — and an unknown/renamed tone degrades to neutral
     * rather than throwing a whole calendar screen away for one bad chip.
     */
    public static function fromTone(?string $tone): self
    {
        return self::tryFrom((string) $tone) ?? self::NEUTRAL;
    }
}
