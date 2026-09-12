<?php

namespace App\Modules\Publishing\Http\Requests;

use App\Modules\Calendar\Services\CalendarInstantResolver;
use App\Modules\Publishing\Http\Requests\Concerns\ExplainsAReviewHold;
use App\Modules\Publishing\Models\Publication;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ARM A PUBLICATION — the request behind the one act in this module that has consequences outside it.
 *
 * It is a separate endpoint and a separate ability rather than a field on the update payload, for two
 * reasons that both come down to the same thing: arming is not editing.
 *
 *   IT IS A TRANSITION. `draft | failed | blocked → scheduled` is an edge in the Manager's table, and
 *   the module has exactly one way to take an edge. An `update` that also armed would be a second one,
 *   reachable by any client that happened to include a status-shaped field.
 *
 *   IT IS THE CONSEQUENTIAL ACT. After this, a clock is running towards something appearing in public.
 *   A distinct ability (`PublicationPolicy::schedule`) is what lets a future rule — only a reviewer may
 *   arm, which is where the Approvable work lands — attach without redefining what editing means.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * B6: A LIVE REVIEW REFUSES THIS ENDPOINT, AND SAYS SO
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * The future rule the paragraph above anticipated has arrived and it is the strongest version of it:
 * while an approval process is pending, arming is refused for everybody. `PublicationPolicy::schedule()`
 * is where that is decided; {@see ExplainsAReviewHold} is why the answer is a 422 that names the hold
 * rather than a 403 that implies a missing permission.
 *
 * An approved AUTOMATED publication never needs this endpoint at all — the approval arms it. An approved
 * hand-made one does, and that is deliberate: approval lifts the hold, it does not press the button.
 *
 * AND ON A REVIEW-GATED DRAFT, THIS ENDPOINT SUBMITS INSTEAD OF ARMING — the most surprising thing it
 * does, so it is stated in the door and not only in the service. A draft with a pipeline attached and no
 * standing approval answers 200 with `status: draft` and `is_in_approval: true`: the chosen moment was
 * parked as the arming intent and the review is now open; the approval will arm for exactly that moment.
 * A screen must read `is_in_approval` to tell "armed" from "submitted" — the status alone will not say.
 * See {@see \App\Modules\Publishing\Services\PublicationService::schedule()} for the full argument.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THIS IS WHERE THE PAST IS REFUSED, AND ONLY HERE
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `StorePublicationRequest` deliberately accepts a `scheduled_at` in the past: a draft may carry any
 * moment, including one being corrected or re-planned. Arming for a moment that has already gone is
 * different — the due-sweep would claim it on its very next pass, so "schedule for last Tuesday" means
 * "publish immediately", which is not what anybody typing a past date is asking for. Refused with a
 * sentence rather than silently corrected to now(): publishing at a moment the caller did not name is
 * exactly the class of surprise this module must not produce.
 *
 * The tolerance is small and deliberate. A few seconds of clock skew and form latency between the
 * client rendering "now" and the server reading it must not turn a legitimate "publish immediately"
 * into a validation error.
 */
class SchedulePublicationRequest extends FormRequest
{
    use ExplainsAReviewHold;

    /**
     * How far into the past an arming may point before it is refused.
     *
     * Not zero, because a client composing "now" and a server validating it are seconds apart, and
     * "publish immediately" is a real and common request. Not minutes, because past that the caller
     * meant a date and got it wrong.
     */
    private const PAST_TOLERANCE_SECONDS = 60;

    public function authorize(): bool
    {
        $publication = $this->route('publication');

        if (!$publication instanceof Publication) {
            return false;
        }

        return $this->user()?->can('schedule', $publication) ?? false;
    }

    public function rules(): array
    {
        return [
            // REQUIRED. An arming with no moment would have to invent one, and the invented answer
            // ("now") is the single most consequential default a form could have.
            'scheduled_at' => ['required', 'date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('scheduled_at')) {
                return;
            }

            $instant = $this->resolvedScheduledAt();

            if ($instant === null) {
                $validator->errors()->add('scheduled_at', __('publishing.validation.scheduled_at_unreadable'));

                return;
            }

            if ($instant->lessThan(now()->subSeconds(self::PAST_TOLERANCE_SECONDS))) {
                $validator->errors()->add('scheduled_at', __('publishing.validation.scheduled_in_the_past'));
            }
        });
    }

    /**
     * The moment to arm for, as a UTC instant.
     *
     * The same resolver the create path uses, so "09:00" means one thing in this module. A zone-less
     * string is the workspace's nine o'clock; a string that names a zone is taken as given.
     */
    public function resolvedScheduledAt(): ?CarbonImmutable
    {
        return app(CalendarInstantResolver::class)->toUtc($this->string('scheduled_at')->value());
    }
}
