<?php

namespace App\Modules\Workflows\Steps;

use App\Modules\Calendar\Services\CalendarInstantResolver;
use App\Modules\Publishing\DTOs\PublicationDTO;
use App\Modules\Publishing\Enums\PublicationStatus;
use App\Modules\Publishing\Enums\PublishingPlatform;
use App\Modules\Publishing\Models\Publication;
use App\Modules\Publishing\Services\PublicationAutomationService;
use App\Modules\Variables\Enums\VariableType;
use App\Modules\Variables\Services\VariableResolver;
use App\Modules\Workflows\Enums\WorkflowStepType;
use App\Modules\Workflows\Exceptions\StepSuspended;
use App\Modules\Workflows\Models\WorkflowRun;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * PUTS SOMETHING OUT INTO THE WORLD — the step R4 exists for, and the SECOND suspending one.
 *
 * It creates a {@see Publication} through the Publishing module's automation seam, optionally hands it to
 * a review pipeline, PARKS the run ({@see StepSuspended}), and collects the outcome much later in
 * {@see resume()} — the same shape {@see GenerateContentStep} established and ADR-0039 D1 said the
 * suspend/resume engine was built for.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * THE ONE RULE THAT MATTERS MORE THAN THE REST OF THIS FILE: IT NEVER PUBLISHES
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * This step ARMS a publication. It does not call a platform, and it must never reach for
 * `PublicationPublisher::publish()`, whose own docblock warns this batch by name. Arming is what a
 * person's Schedule button does; the Publishing module's due-sweep then claims the row through the
 * atomic claim that is the ONLY thing preventing two publishes of one publication (ADR-0055 Decision 4),
 * behind a per-publication overlap lock, in a worker sized for talking to YouTube.
 *
 * A synchronous publish from here would lose all three of those AND would run the module's deliberately
 * transaction-free two-phase sequence inside a run job that forces the `sync` queue driver. The cost of
 * getting it wrong is not a failed run: it is a post that exists twice, publicly, that nothing written in
 * this application can withdraw.
 *
 * `WorkflowsPublishingBoundaryTest` reads this file's bytes and refuses three things: an IMPORT of the
 * publisher, a mention of its claimed-publish entry point, and any method call named `publish`. The
 * warning above names the class in prose only, on purpose — the pin is on the CALL SHAPE, so that the
 * reason a reader must not reach for it can still be written where they would reach.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * THE MODULE BOUNDARY
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * This class, {@see \App\Modules\Workflows\Services\PublicationWaitResolver},
 * {@see \App\Modules\Workflows\Listeners\ResumeWaitingRunOnPublicationConcluded} and
 * `StoreWorkflowRequest` are the ONLY places the Workflows → Publishing edge is crossed, and it is
 * strictly one-way: Publishing names no Workflows class anywhere, and cannot, because the run it would
 * have to name is the thing waiting on it. The same inversion the Generator edge uses — the feature side
 * fires a plain event ({@see \App\Modules\Publishing\Events\PublicationConcluded}) and this module is
 * what reacts.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * CONFIG
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 *   platform               a LITERAL `PublishingPlatform`. Literal and not a variable for the same
 *                          reason `create_event.all_day` is: it decides which OTHER field is required
 *                          (a public destination must name an account), so a run-time value would make
 *                          the definition unvalidatable at the one moment somebody could fix it.
 *   platform_connection_id the account to publish as. REQUIRED for any destination that publishes
 *                          publicly; forbidden-in-effect for `dry_run`, which has no account. Validated
 *                          at write time through the Publishing module's own authority, and RE-checked
 *                          here at run time, because a token can be revoked while a definition sleeps.
 *   title                  resolveString; REQUIRED non-blank after resolution — the adapters refuse a
 *                          titleless publication (`title_missing`), so a blank one is a run that pays
 *                          for a queue slot to fail.
 *   body                   resolveString over the caption. Null when blank: a caption-less image post is
 *                          an ordinary thing to publish.
 *   media                  a FILE union (a `generate_content` step's `image_file_ids`, a submission's
 *                          attachment, or a literal Disk pick). The ids are stored VERBATIM and are never
 *                          copied — a publication POINTS at Disk files and never owns them, which is the
 *                          opposite of `create_task.attachments` and is deliberate: one video published
 *                          to two destinations is one file and two publications.
 *   publish_at             a literal|variable DATE union. ABSENT means "as soon as it may" — which in a
 *                          module where nothing publishes synchronously is a moment already past, taken
 *                          by the next sweep. Zone-less text is read on the WORKSPACE's clock, through
 *                          the same {@see CalendarInstantResolver} `create_event` and the publishing HTTP
 *                          door use. That equivalence is not visible from any one side, so it is pinned.
 *   approval_pipeline_id   an optional workspace review pipeline. With one, NOTHING is armed until the
 *                          last approver says yes — see below.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * WITH A REVIEW PIPELINE, AND WHY THAT IS NOT A TRIGGER
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * The publication is created as a draft carrying its intended moment, a review is started, and the run
 * parks exactly as it would have anyway. When the last approver says yes,
 * `Publication::onApprovalCompleted()` arms it — and the run is still parked, waiting for the SAME thing
 * it was always waiting for: the publication's outcome.
 *
 * NO WORKFLOW IS STARTED BY AN APPROVAL. Nothing here is a trigger (ADR-0009 is untouched): a trigger
 * with that semantics was removed in Etap 5.1 and does not come back. The distinction is not academic —
 * a trigger would start a NEW run with no memory of the one that composed this content, and the outputs
 * below could never reach the steps that follow.
 *
 * A REJECTION FAILS THE STEP. The publication stays a draft, the run resumes and stops with the reason.
 * The alternative — treating a refusal as a normal completion and carrying on — would let every step
 * after this one run as though something had been published.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * WHAT ENDS THE WAIT, AND THE ONE THING THAT DOES NOT
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 *   published        outputs, and the run carries on.
 *   failed           the platform was asked and created nothing → the step FAILS with the failure code.
 *   blocked          the connection is held, so this will not go out until a person fixes it → FAILS,
 *                    for the same reason: a run cannot wait on a human repair, and `wait_timeout` would
 *                    eventually kill it anyway while reporting a timeout instead of the real cause.
 *   review rejected  FAILS.
 *   needs_reconcile  KEEPS WAITING. It means we do not know whether a post exists — the absence of an
 *                    answer, not a bad one. Concluding "failed" from it would be the exact reasoning
 *                    error the whole module is arranged to prevent, and concluding "published" would
 *                    hand later steps a remote id nobody established. A probe or a person resolves it,
 *                    and THAT resolution wakes the run. If nobody ever does, `workflows.wait_timeout`
 *                    ends the run — late and honest, rather than early and wrong.
 *
 * Outputs (see {@see outputDescriptors}): publication_id, status, remote_id, url, published_at.
 */
class PublishStep implements SuspendableWorkflowStep
{
    /**
     * The wait KIND this step parks on — the {@see \App\Modules\Workflows\Services\WaitResolverRegistry}
     * key answered by {@see \App\Modules\Workflows\Services\PublicationWaitResolver} and the prefix of the
     * indexed `waiting_key` the conclusion listener looks a parked run up by.
     */
    public const WAIT_KIND = 'publication';

    /** The `publications.title` column width — resolved titles are clamped to it. */
    private const TITLE_MAX = 255;

    /** The `body` validation ceiling on the module's own write path; a resolved caption is clamped to it. */
    private const BODY_MAX = 5000;

    public function __construct(
        private PublicationAutomationService $publications,
        private VariableResolver $resolver,
        private CalendarInstantResolver $instants,
    ) {}

    public function type(): WorkflowStepType
    {
        return WorkflowStepType::PUBLISH;
    }

    /**
     * The correlation key for one publication — `<kind>:<publication uuid>`. Built HERE so the step, the
     * wait resolver and the conclusion listener can never disagree on the key a run is parked under.
     */
    public static function correlationKey(string $publicationId): string
    {
        return self::WAIT_KIND . ':' . $publicationId;
    }

    /**
     * The five outputs a later step may reference as `{{steps.<key>.<name>}}`.
     *
     *   publication_id  the publication's uuid — provenance, and a deep link into the module.
     *   status          the settled status. `published` in practice: every other outcome fails the step,
     *                   so the value is published only on the success path. Emitted verbatim rather than
     *                   hard-coded so the descriptor stays honest if a softer settlement is introduced.
     *   remote_id       the artifact's identifier ON THE PLATFORM. The proof it exists.
     *   url             the permalink, when the platform hands one back. '' when it does not — the
     *                   identity is `remote_id`, and a url format is the platform's to change.
     *   published_at    when it actually went out, which is NOT the moment it was scheduled for: it can
     *                   differ by the queue's latency, and after a reconciliation by hours. DATE-typed,
     *                   so a following step can feed it straight into a date field.
     */
    public static function outputDescriptors(): array
    {
        return [
            ['name' => 'publication_id', 'type' => VariableType::TEXT],
            ['name' => 'status', 'type' => VariableType::TEXT],
            ['name' => 'remote_id', 'type' => VariableType::TEXT],
            ['name' => 'url', 'type' => VariableType::TEXT],
            ['name' => 'published_at', 'type' => VariableType::DATE],
        ];
    }

    public function run(array $config, WorkflowRun $run, array $context): array
    {
        $platform = $this->requirePlatform($config);
        $connectionId = $this->literalString($config, 'platform_connection_id');

        // RE-ASKED AT RUN TIME although the save already asked. A definition can sleep for months while
        // the account behind it is revoked, disconnected or simply deleted — and the failure mode of
        // publishing without a usable connection is a row that fails on every single run, unattended.
        // Refusing BEFORE anything is created is what keeps that from leaving a draft behind each time.
        if (!$this->publications->destinationIsUsable($platform, $connectionId)) {
            throw new RuntimeException(__('workflows.steps.publish.connection_unavailable'));
        }

        $publication = $this->publications->create(
            new PublicationDTO(
                title: $this->requireTitle($config),
                body: $this->resolveBody($config),
                platform: $platform,
                platformConnectionId: $connectionId,
                // NEVER set here. The DTO's `scheduledAt` is the "draft carrying a moment" a person gets
                // when they type a time while still writing; the automation seam owns when this one is
                // armed, and passing both would be two answers to one question.
                scheduledAt: null,
                media: $this->resolveMedia($config, $context),
            ),
            $this->resolvePublishAt($config, $context),
            $this->literalString($config, 'approval_pipeline_id'),
        );

        throw new StepSuspended(
            self::WAIT_KIND,
            self::correlationKey($publication->id),
            // NEVER content: `waiting_on` is persisted and read back by the run detail screen.
            ['publication_id' => $publication->id],
        );
    }

    /**
     * Collect the settled publication. $config is the config PERSISTED AT SUSPEND (the engine replays
     * `waiting_on.config` rather than re-resolving it), so nothing here can pay twice for a directive or
     * collect an outcome under a config the publication was never created with.
     *
     * NOTHING ABOUT THE PUBLICATION IS RE-DERIVED FROM THAT CONFIG. The row is the authority from the
     * moment it exists: it may have been edited before the review, re-armed by a person, or reconciled
     * into `published` hours later, and the config knows none of that.
     */
    public function resume(array $config, WorkflowRun $run, array $context, array $wait): array
    {
        $publicationId = $this->waitedPublicationId($wait);
        $publication = Publication::find($publicationId);

        if ($publication === null) {
            // GONE, not pending. Deleted or purged, so it can never reach an outcome; waiting on would
            // only convert the real cause into a misleading timeout.
            throw new RuntimeException(__('workflows.steps.publish.gone'));
        }

        if ($publication->status === PublicationStatus::PUBLISHED) {
            return [
                'publication_id' => $publication->id,
                'status' => $publication->status->value,
                'remote_id' => (string) $publication->remote_id,
                'url' => (string) $publication->remote_url,
                'published_at' => $publication->published_at?->toIso8601String() ?? '',
            ];
        }

        if (in_array($publication->status, [PublicationStatus::FAILED, PublicationStatus::BLOCKED], true)) {
            throw new RuntimeException(__('workflows.steps.publish.failed', [
                'status' => $publication->status->value,
                // A STABLE CODE, never the platform's prose (which is composed on their servers in
                // whatever language they choose) and never anything derived from a credential. It lands
                // verbatim in `workflow_runs.error`, which a person reads.
                'code' => (string) ($publication->failure_code ?? 'unknown'),
            ]));
        }

        if ($publication->approvalWasRejected()) {
            throw new RuntimeException(__('workflows.steps.publish.rejected'));
        }

        // STILL IN FLIGHT — a review that has not concluded, a row armed and waiting for its minute, a
        // publish in progress, or `needs_reconcile`, which is the absence of an answer rather than one.
        // Re-suspending on the SAME key is idempotent: the next conclusion event or sweep resumes it, and
        // `workflows.wait_timeout` still bounds it.
        throw new StepSuspended(self::WAIT_KIND, self::correlationKey($publicationId), ['publication_id' => $publicationId]);
    }

    // ---- run() internals -------------------------------------------------------

    /**
     * The destination, from a LITERAL config value. The write-side validator already required a known
     * one, so reaching here with anything else means a hand-written or imported definition — a hard step
     * failure, because there is no honest default for "where does this go".
     */
    private function requirePlatform(array $config): PublishingPlatform
    {
        $platform = PublishingPlatform::tryFrom((string) ($this->literalString($config, 'platform') ?? ''));

        if ($platform === null) {
            throw new RuntimeException('The publish step requires a known platform.');
        }

        return $platform;
    }

    /**
     * The title is already reference-resolved by the runner, so a variable that produced nothing arrives
     * as ''. A blank title HARD-FAILS: the adapters refuse a titleless publication, so letting it through
     * would spend a queue slot and an attempt to reach a failure that was knowable here.
     *
     * Clamped to the destination column for the reason `create_task` clamps its own: the length of a
     * RESOLVED value is unknowable when the workflow is written, and a raw SQL overflow is a worse
     * failure than a truncated title.
     */
    private function requireTitle(array $config): string
    {
        $value = $config['title'] ?? null;

        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException('The publish step requires a non-empty `title`.');
        }

        return mb_substr(trim($value), 0, self::TITLE_MAX);
    }

    /** The resolved caption, clamped to the module's own ceiling, or null when blank. */
    private function resolveBody(array $config): ?string
    {
        $value = $config['body'] ?? null;

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_substr($value, 0, self::BODY_MAX);
    }

    /**
     * The ORDERED Disk file ids to attach — POINTERS, never copies.
     *
     * `create_task` copies what it attaches, because a task OWNS its files. A publication does not: it
     * stores ids it never dereferences, and the same generated video going to YouTube and to Instagram is
     * one file and two publications. Copying here would multiply the bytes once per destination and leave
     * the Disk holding duplicates nobody asked for.
     *
     * Deduplicated and capped at the module's own payload guard, preserving order — the order IS the
     * carousel. An id that no longer resolves is NOT filtered out: this module deliberately never
     * dereferences media, and the only check that means anything happens at publish time, in the adapter,
     * where a missing file has a state to go to and a reason to show.
     *
     * @return array<int, string>
     */
    private function resolveMedia(array $config, array $context): array
    {
        $field = $config['media'] ?? null;

        // A BARE LIST is wrapped into the union's literal shape before it goes anywhere near the shared
        // resolver, and the wrapping is load-bearing rather than defensive. The write-side validator
        // accepts a literal that is EITHER one file id OR a list of them (`isFileUuidLiteral`), while
        // `resolveValueOrVariable` reads `['value']` out of any array that carries no `kind` — so a
        // plain list is a shape a definition can be SAVED with and that would then resolve to NOTHING.
        // For an attachment that costs a task its file; here it means the post goes out with its caption
        // and without its video, silently, publicly, and without anything failing.
        if (is_array($field) && !array_key_exists('kind', $field)) {
            $field = ['kind' => 'literal', 'value' => array_values($field)];
        }

        $resolved = $this->resolver->resolveValueOrVariable($field, $context, VariableType::FILE);

        if (!is_array($resolved)) {
            $resolved = $resolved === null ? [] : [$resolved];
        }

        $ids = array_values(array_unique(array_filter(
            $resolved,
            static fn ($value): bool => is_string($value) && $value !== '',
        )));

        return array_slice($ids, 0, Publication::MEDIA_MAX);
    }

    /**
     * WHEN it should go out, as a UTC instant — or null for "as soon as it may".
     *
     * The zone rule and the literal-text subtlety are `create_event`'s, restated here only in what they
     * mean for this step: a zone-less literal is the WORKSPACE's nine o'clock, not the server's, and the
     * rule is applied to the AUTHOR'S OWN TEXT rather than to what the variable resolver hands back —
     * because the resolver's DATE coercion stamps a zone-less value `+00:00` on the way past, after which
     * "did the author name a zone?" can only ever be answered yes.
     *
     * Publishing at the wrong hour is not the same class of mistake as drawing an event on the wrong
     * square: nothing recalls it.
     *
     * A value that resolves to nothing usable degrades to null — i.e. to "as soon as it may" — rather
     * than failing the step. That is the SOFT direction on purpose: the caller asked for this to be
     * published, and the only thing lost is a preferred minute.
     */
    private function resolvePublishAt(array $config, array $context): ?CarbonImmutable
    {
        $field = $config['publish_at'] ?? null;

        $resolved = $this->resolver->resolveValueOrVariable($field, $context, VariableType::DATE);

        if (!is_string($resolved) || $resolved === '') {
            return null;
        }

        return $this->instants->toUtc($this->literalText($field) ?? $resolved);
    }

    /** The author's own text for a field, when the field is a literal one. Null for anything else. */
    private function literalText(mixed $field): ?string
    {
        if (is_string($field)) {
            return $field !== '' ? $field : null;
        }

        if (!is_array($field) || ($field['kind'] ?? 'literal') !== 'literal') {
            return null;
        }

        $value = $field['value'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    // ---- resume() internals ----------------------------------------------------

    /**
     * The publication this wait was parked on. A wait record without one is unusable — the step cannot
     * know what settled — so it fails rather than parking again forever.
     *
     * @param  array<string, mixed>  $wait
     */
    private function waitedPublicationId(array $wait): string
    {
        $payload = is_array($wait['payload'] ?? null) ? $wait['payload'] : [];
        $publicationId = $payload['publication_id'] ?? null;

        if (!is_string($publicationId) || $publicationId === '') {
            throw new RuntimeException('The publish step resumed without a publication to collect.');
        }

        return $publicationId;
    }

    // ---- shared ----------------------------------------------------------------

    /** A literal, already-resolved string config value, trimmed — or null when absent/blank/non-string. */
    private function literalString(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
