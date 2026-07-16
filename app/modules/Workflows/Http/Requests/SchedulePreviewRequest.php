<?php

namespace App\Modules\Workflows\Http\Requests;

use App\Modules\Workflows\Services\WorkflowScheduleRulesValidator;
use App\Modules\Workflows\Services\WorkflowScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a live SCHEDULE-PREVIEW request (POST /workflows/meta/schedule-preview): a bare
 * `schedule` cadence block, an optional `count` of occurrences to project, and an optional `anchor`
 * instant to project AROUND (rather than from now).
 *
 * Authorization mirrors the schedule-assist endpoint — any authenticated workspace member may
 * preview (the endpoint exposes no tenant data; it only projects fire times from a public cadence
 * descriptor). Membership is enforced upstream by ResolveWorkspace, so there is no per-object policy;
 * a guest is stopped by auth:sanctum with a 401.
 *
 * The `schedule` block is validated by the SAME shared WorkflowScheduleRulesValidator the write and
 * AI-assist paths use, under a bare `schedule` prefix — with ONE difference: the empty-schedule guard
 * is OFF (secondPass checkEmpty: false). An over-constrained config is NOT a 422 here; it comes back
 * as data (`empty: true`) so the FE can render the live "these rules remove every occurrence" warning
 * BEFORE the user saves. Every STRUCTURAL error (unknown mode, out-of-bounds field, malformed
 * window/exclusions) still returns 422.
 *
 * ANCHOR: an ISO-8601 datetime the preview centres on. WITHOUT an offset it is a WALL-CLOCK time read
 * in the schedule's own tz; WITH an offset it is an absolute instant (the offset wins). It seeds
 * WorkflowScheduleService::occurrencesFrom, which returns the occurrence at-or-before the anchor first.
 */
class SchedulePreviewRequest extends FormRequest
{
    /** The largest number of occurrences a single preview may project. */
    private const MAX_COUNT = 12;

    /** The default occurrence count when the request omits `count`. */
    public const DEFAULT_COUNT = 6;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Base rules: the shared per-key schedule rules under the bare `schedule` prefix, plus the
     * occurrence count. Per-family bounds, ordering and the times/exclusions cross-checks run in
     * withValidator() (checkEmpty off) where the whole block is visible.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return array_merge(
            app(WorkflowScheduleRulesValidator::class)->baseRules('schedule'),
            [
                'count' => ['nullable', 'integer', 'min:1', 'max:' . self::MAX_COUNT],
                // A parseable ISO-8601 datetime; the offset-vs-wall-clock interpretation is applied
                // against the schedule tz in anchor(). `date` accepts both offset and offset-less forms.
                'anchor' => ['nullable', 'string', 'date'],
            ],
        );
    }

    /**
     * Second-pass schedule checks via the shared validator, with the empty-schedule guard DISABLED
     * (checkEmpty: false) — the one behavioural difference from the write path. Structural errors
     * still surface; emptiness is deferred to the response as `empty: true`.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            app(WorkflowScheduleRulesValidator::class)->secondPass(
                $validator,
                is_array($this->input('schedule')) ? $this->input('schedule') : [],
                'schedule',
                checkEmpty: false,
            );
        });
    }

    /** The validated schedule block. */
    public function scheduleBlock(): array
    {
        $schedule = $this->validated('schedule');

        return is_array($schedule) ? $schedule : [];
    }

    /** The requested occurrence count, clamped to a sane default when absent. */
    public function occurrenceCount(): int
    {
        return (int) ($this->validated('count') ?? self::DEFAULT_COUNT);
    }

    /**
     * The optional anchor as a CarbonImmutable, folded into the schedule's tz: an offset-less string
     * is read as wall-clock time in that tz; a string with an offset keeps its absolute instant (the
     * offset wins). Null when no anchor was supplied — the controller then projects from now().
     */
    public function anchor(): ?CarbonImmutable
    {
        $raw = $this->validated('anchor');

        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $tz = app(WorkflowScheduleService::class)->timezone($this->scheduleBlock());

        return CarbonImmutable::parse($raw, $tz);
    }
}
