<?php

namespace App\Modules\Publishing\Http\Requests;

use App\Modules\Calendar\Services\CalendarInstantResolver;
use App\Modules\Publishing\Enums\PublishingPlatform;
use App\Modules\Publishing\Models\Publication;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a PUBLICATION on create.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHOSE CLOCK "09:00" IS ON — REUSED, NOT RESTATED
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `scheduled_at` goes through {@see CalendarInstantResolver}, the rule the Calendar module extracted
 * for exactly this problem: a zone-less string is read on the WORKSPACE's clock, and a string that
 * names a zone is taken as given, because a workspace timezone is an interpretation of silence and
 * never a correction of speech.
 *
 * Importing it is deliberate and is the cheaper of the two options. The alternative is a second
 * definition of "whose nine o'clock", and that defect has already been paid for once in this codebase —
 * the same text through two doors produced two instants two hours apart, with nothing disagreeing out
 * loud. The dependency direction is sanctioned: Publishing may name the Calendar (it implements the
 * Calendar's source contract), and the Calendar names nobody.
 *
 * The consequence for this module is sharper than it was for a meeting: a publication read on the wrong
 * clock does not sit on the wrong square, it GOES OUT at the wrong hour, and nothing recalls it.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * SETTING A TIME IS NOT ARMING
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * A create with `scheduled_at` produces a DRAFT that carries a moment. Arming is a transition, made by
 * the Manager, through `POST /publishing/publications/{id}/schedule`. That separation is what lets
 * somebody pick a time while still writing without the act of saving becoming irreversible.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT A CALLER MAY NOT SEND, AND WHY EACH IS REFUSED RATHER THAN DROPPED
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 *   status                          the Manager's alone. A payload that could set it would be a second
 *                                   entrance to the state machine, bypassing every edge in the table.
 *   remote_id / remote_draft_id     a platform's words about a platform's artifact. A client asserting
 *                                   either could mark a row as already-published and suppress the real
 *                                   publish, or point a resume at a container that does not exist.
 *   attempts / published_at         the machine's own bookkeeping.
 *
 * All `prohibited`, never ignored: a client sending one believes it is setting something, and a silent
 * drop leaves it correct-looking and wrong. That is the same rule `StoreCalendarEventRequest` applies
 * to `color`, applied where the stakes are higher.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `media` IS A LIST OF IDS AND IS NOT CHECKED FOR EXISTENCE
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * Deliberately, and for the same reason a calendar annotation's optional subject pointer is not:
 * validating it means this module resolving a Disk file, which is a dependency it does not take. (The
 * sibling table is not named here on purpose — `CalendarEventFenceTest` scans app/ literally for it,
 * because a docblock reference is exactly how a real read begins.) Nor would the check be worth
 * much — a file present at draft time can be trashed before the scheduled minute, so the only check
 * that means anything happens at publish, in the adapter, where a missing file has a state to go to and
 * a reason to show.
 *
 * The ORDER of the array is data (it is the order the platform receives them in), so it is preserved
 * verbatim — and `distinct` is enforced, because the same file twice in one carousel is a mistake with
 * no sensible reading.
 */
class StorePublicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Publication::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            // `text` in the schema: a YouTube description is 5000 characters.
            'body' => ['nullable', 'string', 'max:5000'],

            'platform' => ['required', Rule::in(PublishingPlatform::values())],

            // The connections table arrives in B2, so there is no `exists` rule to write yet. Stated
            // rather than left blank: when B2 lands, this is where the rule goes, and it must also
            // check that the connection serves the platform named above.
            'platform_connection_id' => ['nullable', 'uuid'],

            // An INSTANT. Any parseable form; a zone-less one is read on the workspace's clock. Note
            // the absence of `after:now` — a publication may legitimately be created with a moment in
            // the past (an import, a correction, a re-plan); what refuses to publish into the past is
            // the arming step, which is where that decision belongs.
            'scheduled_at' => ['nullable', 'date'],

            'media' => ['nullable', 'array', 'max:' . Publication::MEDIA_MAX],
            'media.*' => ['uuid', 'distinct'],

            'options' => ['nullable', 'array'],

            // The machine's, not the caller's. See the class docblock.
            'status' => ['prohibited'],
            'remote_id' => ['prohibited'],
            'remote_draft_id' => ['prohibited'],
            'published_at' => ['prohibited'],
            'attempts' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'status.prohibited' => __('publishing.validation.status_not_accepted'),
            'remote_id.prohibited' => __('publishing.validation.remote_not_accepted'),
            'remote_draft_id.prohibited' => __('publishing.validation.remote_not_accepted'),
            'published_at.prohibited' => __('publishing.validation.remote_not_accepted'),
            'attempts.prohibited' => __('publishing.validation.remote_not_accepted'),
            'platform.in' => __('publishing.validation.platform_unknown'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateScheduledAtIsReadable($validator);
        });
    }

    /**
     * The `date` rule accepts strings the resolver cannot turn into an instant (it is fail-soft and
     * answers null). Without this the value would silently become "no moment at all" on a payload that
     * clearly named one — and a publication that quietly forgot when it was meant to go out is the
     * worst kind of success.
     */
    private function validateScheduledAtIsReadable(Validator $validator): void
    {
        if ($validator->errors()->has('scheduled_at') || !$this->filled('scheduled_at')) {
            return;
        }

        if ($this->resolvedScheduledAt() === null) {
            $validator->errors()->add('scheduled_at', __('publishing.validation.scheduled_at_unreadable'));
        }
    }

    public function resolvedPlatform(): PublishingPlatform
    {
        return PublishingPlatform::from($this->string('platform')->value());
    }

    /**
     * The moment this publication names, as a UTC instant — or null when it names none.
     *
     * Read through the shared resolver, never `$request->date()`: the latter parses through
     * `config('app.timezone')`, which is a hard 'UTC', and a Warsaw team asking for 09:00 would be
     * published at 11:00 local. See the class docblock.
     */
    public function resolvedScheduledAt(): ?CarbonImmutable
    {
        if (!$this->filled('scheduled_at')) {
            return null;
        }

        return app(CalendarInstantResolver::class)->toUtc($this->string('scheduled_at')->value());
    }

    /**
     * The media list, ORDER PRESERVED and re-indexed.
     *
     * `array_values` matters: a client sending `media[0]` and `media[2]` would otherwise store an object
     * with numeric keys rather than an array, and every consumer that iterates positionally would get a
     * different sequence than the one that was sent.
     *
     * @return array<int, string>
     */
    public function resolvedMedia(): array
    {
        return array_values(array_filter(
            $this->array('media'),
            static fn ($value): bool => is_string($value) && $value !== '',
        ));
    }

    /** @return array<string, mixed> */
    public function resolvedOptions(): array
    {
        $options = $this->input('options');

        return is_array($options) ? $options : [];
    }
}
