<?php

namespace App\Modules\Calendar\Http\Requests;

use App\Modules\Calendar\Enums\CalendarColor;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Calendar\Services\CalendarInstantResolver;
use App\Modules\Calendar\Services\CalendarTimezoneResolver;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a CALENDAR EVENT on create, and refuses — flatly — any payload that does not commit to one
 * shape in time.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE DISCRIMINATOR IS ENFORCED BOTH WAYS
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 *   all_day = true   → `start_date` is REQUIRED and `starts_at`/`ends_at` are FORBIDDEN.
 *   all_day = false  → `starts_at` is REQUIRED and `start_date` is FORBIDDEN.
 *
 * Both halves matter, and the forbidding half is the one that would be tempting to drop. Accepting a
 * stray `starts_at` on an all-day payload and quietly ignoring it produces exactly the failure this
 * module is arranged against: the caller believes it stored a time, the grid renders a day, and
 * nothing anywhere disagrees out loud. So an over-complete payload is a 422 naming the offending
 * field, not a silently narrowed save. (The DTO makes the incoherent row unrepresentable; this makes
 * the incoherent REQUEST answerable.)
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHOSE CLOCK A ZONE-LESS INSTANT IS ON
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * The read side draws the grid in the WORKSPACE's timezone (`meta.timezone`,
 * {@see CalendarTimezoneResolver}). The write side has to agree with it, and by default it did not:
 * `$request->date()` parses through `config('app.timezone')`, which is a hard `'UTC'`. A Warsaw team
 * booking 14:30 stored 14:30Z and read it back as 16:30 — silently, off by the offset. That is the
 * same family of defect the all-day discriminator exists to prevent, arriving through the other door.
 *
 * So the rule is explicit, and both halves of it are deliberate:
 *
 *   input WITHOUT a zone  → interpreted in the WORKSPACE's timezone. It is the only reading that makes
 *                           the write agree with the read: a bare "14:30" means the same wall time the
 *                           grid will show.
 *   input WITH a zone     → taken EXACTLY as given, workspace timezone ignored. A client that wrote
 *                           `+02:00` or `Z` has already said what it means; the workspace zone is an
 *                           interpretation of SILENCE, never a correction of speech.
 *
 * IT IS NOT SPELLED OUT HERE. It lives in {@see CalendarInstantResolver}, because this request is not
 * the only writer of this table: the `create_event` workflow step reaches the same DTO with no request
 * anywhere in the process, and while the rule was stated only here that step read a zone-less instant on
 * `config('app.timezone')` instead — the same text through the two doors, two hours apart, with nothing
 * disagreeing out loud. A rule this quiet cannot survive being written twice.
 *
 * `ends_at` is optional — an event with no stated end is ordinary — but when present it must not
 * precede `starts_at`, because a negative span has no rendering and no meaning. That comparison runs
 * on the RESOLVED INSTANTS, not through `after_or_equal`, and the difference is not cosmetic: the rule
 * parses both strings in the app timezone, so a MIXED payload (`10:00Z` start, zone-less `11:00` end)
 * passed validation while actually ending an hour before it began. The thing that validates has to be
 * the thing that stores.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THERE IS NO `color`, AND SENDING ONE IS A 422
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * An event has no colour of its own, and a caller cannot give it one. {@see CalendarColor} is a
 * vocabulary of MEANINGS the grid explains — a task's deadline colours by priority, a run by how it
 * ended, a schedule projection by being a projection — and every source that emits one is answering a
 * question about its subject. A human-picked colour on an event answered nothing while looking exactly
 * like the three that do: red meant "urgent", red meant "failed", and red meant nothing, in one grid.
 * So the event source now emits a CONSTANT ({@see \App\Modules\Calendar\Sources\EventCalendarSource}),
 * and this field is gone from the wire.
 *
 * REFUSED rather than ignored, deliberately. A client still sending `color` believes it is setting one;
 * dropping it quietly would leave that client correct-looking and wrong, which is the same silence the
 * all-day discriminator is refused for above. The 422 names the field and says why.
 *
 * `subject_type`/`subject_id` is the optional POINTER, accepted BOTH-OR-NEITHER and stored verbatim.
 * It is deliberately not checked for existence: validating it would mean resolving a morph alias to a
 * class, which is the one dependency the Calendar refuses to take (see the model). A pointer at
 * something that is not there is inert — nothing ever follows it — so a bad one costs a dangling
 * deep-link, not a broken calendar.
 */
class StoreCalendarEventRequest extends FormRequest
{
    /** Aliases + ids are handed over as opaque primitives; only the WIDTH is this layer's business. */
    private const SUBJECT_TYPE_MAX = 255;

    public function authorize(): bool
    {
        return $this->user()?->can('create', CalendarEvent::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],

            // THE DISCRIMINATOR. Required, and never inferred from which date fields happen to be
            // present: inferring it would make an ambiguous payload silently pick a branch.
            'all_day' => ['required', 'boolean'],

            // A calendar DAY. `date_format` rather than `date` on purpose — `date` would happily accept
            // an ISO instant and hand it on for the conversion that puts an event on the wrong square.
            'start_date' => ['nullable', 'date_format:Y-m-d'],

            // INSTANTS. Any parseable form is accepted; a zone-less one is read in the workspace's
            // timezone and the pair is normalized to UTC — see the docblock and {@see instant()}.
            //
            // NOTE the absence of `after_or_equal:starts_at`. That rule parses both sides in the APP
            // timezone, which is not necessarily how either side is actually being read, so it could
            // pass a genuinely inverted pair. The ordering is checked on the resolved instants instead
            // ({@see validateOrdering}).
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],

            // An event carries no colour of its own — see the docblock. `prohibited` is the whole
            // enforcement: present-and-non-empty is a 422, absent is fine, and the rule sits here where
            // a reader looks for the field rather than in an after-hook.
            'color' => ['prohibited'],

            'subject_type' => ['nullable', 'string', 'max:' . self::SUBJECT_TYPE_MAX],
            'subject_id' => ['nullable', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'color.prohibited' => __('calendar.validation.color_not_accepted'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateShapeInTime($validator);
            $this->validateOrdering($validator);
            $this->validateSubjectPair($validator);
        });
    }

    /**
     * An event may not end before it begins — compared on the instants that will actually be STORED,
     * each read in whichever zone it named (or the workspace's, when it named none).
     *
     * Skipped when either field already failed the `date` rule, so a caller sees the cause rather than
     * a second complaint about a value that was never parseable.
     */
    private function validateOrdering(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['starts_at', 'ends_at'])) {
            return;
        }

        $startsAt = $this->resolvedStartsAt();
        $endsAt = $this->resolvedEndsAt();

        if ($startsAt === null || $endsAt === null) {
            return;
        }

        if ($endsAt->lessThan($startsAt)) {
            $validator->errors()->add('ends_at', __('calendar.validation.end_before_start'));
        }
    }

    /**
     * The all-day/timed split: exactly one column group, required in full and empty on the other side.
     * Skipped when `all_day` itself failed, so a caller sees the cause rather than three consequences.
     */
    private function validateShapeInTime(Validator $validator): void
    {
        if ($validator->errors()->has('all_day') || !$this->has('all_day')) {
            return;
        }

        if ($this->boolean('all_day')) {
            if (!$this->filled('start_date')) {
                $validator->errors()->add('start_date', __('calendar.validation.start_date_required'));
            }

            foreach (['starts_at', 'ends_at'] as $field) {
                if ($this->filled($field)) {
                    $validator->errors()->add($field, __('calendar.validation.instant_on_all_day'));
                }
            }

            return;
        }

        if (!$this->filled('starts_at')) {
            $validator->errors()->add('starts_at', __('calendar.validation.starts_at_required'));
        }

        if ($this->filled('start_date')) {
            $validator->errors()->add('start_date', __('calendar.validation.day_on_timed'));
        }
    }

    /**
     * The pointer is BOTH-OR-NEITHER. Half a pointer addresses nothing and would sit in the row
     * looking like a reference somebody could follow.
     */
    private function validateSubjectPair(Validator $validator): void
    {
        $hasType = $this->filled('subject_type');
        $hasId = $this->filled('subject_id');

        if ($hasType !== $hasId) {
            $validator->errors()->add(
                $hasType ? 'subject_id' : 'subject_type',
                __('calendar.validation.subject_incomplete'),
            );
        }
    }

    public function resolvedDescription(): ?string
    {
        return $this->filled('description') ? $this->string('description')->value() : null;
    }

    /** The event's start as a UTC instant, read in whichever zone the caller named — or the workspace's. */
    public function resolvedStartsAt(): ?CarbonImmutable
    {
        return $this->instant('starts_at');
    }

    public function resolvedEndsAt(): ?CarbonImmutable
    {
        return $this->instant('ends_at');
    }

    /**
     * One wire instant, normalized to UTC through {@see CalendarInstantResolver} — the module's single
     * statement of the rule, shared with the `create_event` workflow step so the two doors into this
     * table cannot disagree about what a zone-less string means.
     *
     * Fail-soft on an unparseable value: the `date` rule has already reported it, and throwing here
     * would turn a reported 422 into a 500 while the validator is still collecting errors. The resolver
     * returns null for exactly that case.
     */
    private function instant(string $key): ?CarbonImmutable
    {
        if (!$this->filled($key)) {
            return null;
        }

        return app(CalendarInstantResolver::class)->toUtc($this->string($key)->value());
    }

    /**
     * The pointer, both halves or neither — already proved complete-or-absent above.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public function resolvedSubject(): array
    {
        if (!$this->filled('subject_type') || !$this->filled('subject_id')) {
            return [null, null];
        }

        return [$this->string('subject_type')->value(), $this->string('subject_id')->value()];
    }
}
