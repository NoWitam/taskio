<?php

namespace App\Modules\Publishing\Http\Requests;

use App\Modules\Approvals\Models\ApprovalPipeline;
use App\Modules\Calendar\Services\CalendarInstantResolver;
use App\Modules\Publishing\Enums\PublishingPlatform;
use App\Modules\Publishing\Models\PlatformConnection;
use App\Modules\Publishing\Models\Publication;
use App\Rules\ScopedExists;
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

            // B2 filled in what B1 left as a note here. `uuid` only checks a SHAPE; the three real
            // questions are asked by validateConnectionServesThisPlatform() below, because two of them
            // cannot be expressed as an `exists` rule at all.
            'platform_connection_id' => ['nullable', 'uuid'],

            // An INSTANT. Any parseable form; a zone-less one is read on the workspace's clock. Note
            // the absence of `after:now` — a publication may legitimately be created with a moment in
            // the past (an import, a correction, a re-plan); what refuses to publish into the past is
            // the arming step, which is where that decision belongs.
            'scheduled_at' => ['nullable', 'date'],

            'media' => ['nullable', 'array', 'max:' . Publication::MEDIA_MAX],
            'media.*' => ['uuid', 'distinct'],

            'options' => ['nullable', 'array'],

            // THE REVIEW A PERSON ATTACHES TO THEIR OWN DRAFT (B6). The same rule shape
            // `StoreTasksRequest` uses for the same column, and `ScopedExists` rather than a bare
            // `exists` for the same reason it does: the stock rule bypasses Eloquent's global scopes, so
            // a payload could name another workspace's pipeline and this row would then be gated on a
            // review nobody here can see.
            //
            // OMITTING IT DETACHES, on update — this is a whole-row write and the Task contract is
            // identical. What stops that being a way around a live review is `PublicationPolicy::update()`,
            // which refuses the whole request while one is pending.
            'approval_pipeline_id' => ['nullable', 'uuid', new ScopedExists(ApprovalPipeline::class)],

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
            $this->validateConnectionServesThisPlatform($validator);
        });
    }

    /**
     * THE CONNECTION MUST EXIST, MUST SERVE THIS DESTINATION, AND MUST BE USABLE.
     *
     * B1 left a note where this now is. Three checks rather than an `exists` rule, because only the
     * first of them is expressible as one:
     *
     *   IT EXISTS, in THIS WORKSPACE. The lookup goes through the tenant-aware model, so `WorkspaceScope`
     *     (shared mode) or the tenant connection (own mode) does the scoping. An `exists:platform_connections,id`
     *     rule would query the table unscoped and confirm a connection belonging to somebody else — which
     *     would then be a foreign key this workspace could aim a publication at.
     *
     *   IT SERVES THE SAME PLATFORM. A YouTube publication pointed at a Facebook connection would pass
     *     every schema constraint and fail at publish time, on a schedule, having looked correct on
     *     every screen in between. The pairing is the kind of mistake a picker prevents and an API
     *     cannot, so the API checks it.
     *
     *   IT IS USABLE. A `needs_reauth` connection cannot publish, and arming something onto it would
     *     produce a publication that is immediately eligible to be held — which is coherent but is not
     *     what somebody choosing a destination meant. Refusing at the door is the honest answer.
     *
     * ONE MESSAGE FOR ALL THREE, on purpose. A client picking from the list this server sent should
     * never see any of them, and distinguishing "that connection is not yours" from "that connection
     * does not exist" would answer questions about other workspaces' rows.
     *
     * A `dry_run` publication legitimately names no connection, which is why the whole check is skipped
     * on an absent value rather than made conditional on the platform.
     */
    private function validateConnectionServesThisPlatform(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['platform', 'platform_connection_id'])
            || !$this->filled('platform_connection_id')
        ) {
            return;
        }

        $connection = PlatformConnection::query()
            ->usable()
            ->where('platform', $this->resolvedPlatform())
            ->find($this->string('platform_connection_id')->value());

        if ($connection === null) {
            $validator->errors()->add('platform_connection_id', __('publishing.validation.connection_unusable'));
        }
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

    /**
     * The review pipeline this publication is gated on, or null for none.
     *
     * Null on an UPDATE means DETACH, which is why this is read rather than merged: the DTO is the whole
     * row, and a field read only `when(filled())` would make "remove the review" unexpressible.
     */
    public function resolvedApprovalPipelineId(): ?string
    {
        $value = $this->string('approval_pipeline_id')->value();

        return $value !== '' ? $value : null;
    }

    /** @return array<string, mixed> */
    public function resolvedOptions(): array
    {
        $options = $this->input('options');

        return is_array($options) ? $options : [];
    }
}
