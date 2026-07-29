<?php

namespace App\Modules\Workflows\Steps;

use App\Modules\Disk\Models\Folder;
use App\Modules\Generator\Enums\GenerationRunMode;
use App\Modules\Generator\Enums\SlotScopePolicy;
use App\Modules\Generator\Exceptions\GenerationBudgetExceeded;
use App\Modules\Generator\Models\GenerationSession;
use App\Modules\Generator\Models\Template;
use App\Modules\Generator\Services\GeneratedImageExporter;
use App\Modules\Generator\Services\GenerationSessionRunManager;
use App\Modules\Generator\Services\SessionAutomationService;
use App\Modules\Generator\Services\SessionContentProjector;
use App\Modules\Generator\Services\SessionDelegationService;
use App\Modules\Variables\Enums\VariableType;
use App\Modules\Variables\Services\VariableResolver;
use App\Modules\Workflows\Enums\WorkflowStepType;
use App\Modules\Workflows\Exceptions\StepSuspended;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Modules\Workflows\Support\RealQueueConnection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Runs a Generator TEMPLATE from a workflow and publishes the produced content (R2 sub-stage 5) — the FIRST
 * and only SUSPENDING step: the generation is long, AI-metered work that belongs on the real queue, so the
 * step starts it, PARKS the run ({@see StepSuspended}), and collects the outcome much later in resume().
 *
 * THE MODULE BOUNDARY. This class is the ONE place the Workflows → Generator edge is crossed, and it is
 * strictly one-way: it calls only the Generator's HTTP-free AUTOMATION seams, and the Generator names no
 * Workflows class anywhere (pinned by GeneratorModuleBoundaryTest + WorkflowsGeneratorBoundaryTest). The
 * seams used, in order, are exactly the ones that seam documents:
 *   CREATE   {@see SessionAutomationService::createFromTemplate}   (snapshot-authoritative)
 *   FILL     {@see SessionDelegationService::applySlotValues}      under {@see SlotScopePolicy::Automation}
 *   RUN      {@see GenerationSessionRunManager::claimAndDispatch}  the single budget-gated claim point
 *   WAIT     {@see SessionAutomationService::terminalStatusFor}    a plain status string, nothing else
 *   COLLECT  {@see SessionContentProjector} + {@see GeneratedImageExporter}
 *
 * ── run() ──────────────────────────────────────────────────────────────────────────────────────────────
 *   1. resolve `template_id` through the TENANT-SCOPED {@see Template} model. `createFromTemplate` validates
 *      neither the workspace nor liveness BY DESIGN (it takes a model), so the workspace boundary is THIS
 *      lookup: a missing / foreign / deleted id HARD-FAILS the step.
 *   2. resolve each DECLARED slot the config maps, through the same value-or-variable union CreateTaskStep
 *      uses, at the slot's OWN type (recovered from its descriptor).
 *   3. create the session, then FILL it under the AUTOMATION scope, which re-validates every value against
 *      its descriptor (and resolves a FILE reference through the tenant-scoped File model) and REPORTS what
 *      it dropped.
 *   4. HARD-FAIL when the fill report leaves ANY required slot unfilled — naming the slots. Generating from
 *      a half-filled recipe would spend AI money on content the author did not ask for.
 *   5. claim + dispatch on the REAL queue connection ({@see RealQueueConnection}) and SUSPEND.
 *
 * ── resume() ───────────────────────────────────────────────────────────────────────────────────────────
 *   `null` (gone) and `failed` are TERMINAL FAILURES — a vanished session can never settle, so "keep
 *   waiting" would strand the run until the wait timeout and then report a misleading timeout instead of
 *   the real cause. `generating`/`draft` RE-SUSPEND on the SAME key (idempotent: the next settle event or
 *   sweep resumes it). Only `ready` collects, and it does so INSIDE the run — see the attribution invariant.
 *
 * ── EXPORT ATTRIBUTION INVARIANT (do not move this) ────────────────────────────────────────────────────
 * The produced images are exported to the Disk in the RESUME phase, i.e. inside the workflow-run context, so
 * `HasCreator` stamps each new Disk file with the RUN (`uploader_type = 'workflow_run'`). Exporting inside
 * the GENERATION worker instead would run with no run context and (usually) no authenticated user, leaving
 * a NULL uploader on a real user-visible file. Note also that {@see GeneratedImageExporter} performs NO
 * authorization at all: it is safe here ONLY because the run exports a session IT created moments earlier
 * from a template the workflow author was authorized to use — never a session id supplied from outside.
 *
 * ── COST ───────────────────────────────────────────────────────────────────────────────────────────────
 * The pre-run AI-$ gate lives in `claimAndDispatch`, so an over-cap workspace is refused BEFORE the session
 * is claimed and nothing is billed. The refusal is re-raised as an ordinary step failure carrying the
 * LOCALIZED, non-secret budget prose (the run's `error` column is plain text, and the same key is what the
 * HTTP 429 renders, so the automated and interactive refusals read identically).
 *
 * Outputs (see {@see outputDescriptors}): session_id, content, image_file_ids, status, has_failed_parts.
 */
class GenerateContentStep implements SuspendableWorkflowStep
{
    /**
     * The wait KIND this step parks on — the {@see \App\Modules\Workflows\Services\WaitResolverRegistry} key
     * answered by {@see \App\Modules\Workflows\Services\GenerationSessionWaitResolver} and the prefix of the
     * indexed `waiting_key` the settle listener looks a parked run up by.
     */
    public const WAIT_KIND = 'generation_session';

    public function __construct(
        private VariableResolver $resolver,
        private SessionAutomationService $automation,
        private SessionDelegationService $delegation,
        private GenerationSessionRunManager $runs,
        private SessionContentProjector $projector,
        private GeneratedImageExporter $images,
        private RealQueueConnection $realQueue,
    ) {}

    public function type(): WorkflowStepType
    {
        return WorkflowStepType::GENERATE_CONTENT;
    }

    /**
     * The correlation key for one generation session — `<kind>:<session uuid>`. Built HERE so the step, the
     * wait resolver and the settle listener can never disagree on the key the run is parked under.
     */
    public static function correlationKey(string $sessionId): string
    {
        return self::WAIT_KIND . ':' . $sessionId;
    }

    /**
     * The five outputs a later step may reference as `{{steps.<key>.<name>}}`. They flow AUTOMATICALLY into
     * the variable catalog (and the FE picker) through the step class's static descriptors, so nothing else
     * has to learn about this step.
     *
     *   session_id       the generation session's uuid — provenance / a deep link into the generator.
     *   content          the ASSEMBLED text of the finished piece (the server-side sibling of the FE's final
     *                    post composition). '' when the recipe is image-only.
     *   image_file_ids   the ids of the Disk files this step exported — FILE-typed, so a following
     *                    create_task can reference it straight into `attachments`.
     *   status           the session's settled status. `ready` in practice: a `failed` session hard-fails
     *                    the step (the run stops), so the value is published only on the ready path. It is
     *                    still emitted verbatim rather than hard-coded so the descriptor stays honest if a
     *                    softer settlement is ever introduced.
     *   has_failed_parts TRUE when the run finished `ready` but some part (or a per-item image) failed —
     *                    per-part fail-soft. Lets an author gate a publish step on completeness.
     */
    public static function outputDescriptors(): array
    {
        return [
            ['name' => 'session_id', 'type' => VariableType::TEXT],
            ['name' => 'content', 'type' => VariableType::TEXT],
            ['name' => 'image_file_ids', 'type' => VariableType::FILE],
            ['name' => 'status', 'type' => VariableType::TEXT],
            ['name' => 'has_failed_parts', 'type' => VariableType::BOOLEAN],
        ];
    }

    public function run(array $config, WorkflowRun $run, array $context): array
    {
        $this->assertGenerationCanLeaveThisProcess();

        $template = $this->requireTemplate($config);

        // The session is created EMPTY and filled through the AUTOMATION scope, never seeded with the raw
        // resolved map: createFromTemplate stores what it is given AS GIVEN (the lenient human-draft
        // posture), so seeding first would persist untrusted values that the fill path then refuses. One
        // write path, one validation authority.
        $session = $this->automation->createFromTemplate($template, [], $this->literalString($config, 'name'));

        $report = $this->delegation->applySlotValues(
            $session,
            $this->resolveSlotValues($template, $config, $context),
            SlotScopePolicy::Automation,
        );

        // Before any spend. The draft session is deliberately LEFT BEHIND on this failure: it shows the
        // author exactly what the automation managed to fill, and the generator's own idle-trash reaper
        // cleans it up.
        $this->assertRequiredSlotsFilled($report);

        try {
            $claimed = $this->runs->claimAndDispatch(
                $session,
                GenerationRunMode::Full,
                null,
                null,
                // MANDATORY. The run job forces the `sync` driver around the step loop, so an unqualified
                // dispatch would execute the whole generation INLINE — inside this step, under this
                // process — and the suspension would be pointless. A null here is NOT "the default
                // connection": claimAndDispatch falls back to config('queue.default'), which is the very
                // value the run job mutated to `sync`. Both run jobs guarantee a published value, and
                // assertGenerationCanLeaveThisProcess() refuses the run if that ever stops being true.
                $this->realQueue->current(),
            );
        } catch (GenerationBudgetExceeded $e) {
            // Re-localized at THROW time rather than reusing the exception's construction-time message: the
            // message this step raises is persisted verbatim in the run/step error columns, so it is
            // composed from the shared key in the run's own locale.
            throw new RuntimeException(__(GenerationBudgetExceeded::MESSAGE_KEY), 0, $e);
        }

        if (!$claimed) {
            // Unreachable for a session created one statement ago (only an already-`generating` session
            // loses the claim), but never assumed: a silently unstarted run would park forever.
            throw new RuntimeException('The generate_content step could not start the generation session.');
        }

        throw new StepSuspended(
            self::WAIT_KIND,
            self::correlationKey($session->id),
            // NEVER content or secrets: waiting_on is persisted and read by the run detail.
            ['session_id' => $session->id],
        );
    }

    /**
     * Collect the settled generation. $config is the config PERSISTED AT SUSPEND (the engine replays
     * `waiting_on.config` rather than re-resolving it), so it is the very config the session was started
     * with — which is what makes reading `folder_id` here correct.
     */
    public function resume(array $config, WorkflowRun $run, array $context, array $wait): array
    {
        $sessionId = $this->waitedSessionId($wait);
        $status = $this->automation->terminalStatusFor($sessionId);

        if ($status === null) {
            // GONE, not pending. The session was deleted / purged / never belonged to this workspace, so it
            // can never settle; waiting on would only convert the real cause into a misleading timeout.
            throw new RuntimeException('The generation this step was waiting for no longer exists, so its content could not be collected.');
        }

        if ($status === 'failed') {
            throw new RuntimeException('The generation this step was waiting for failed, so there is no content to collect.');
        }

        if ($status !== 'ready') {
            // `generating` — and `draft`, which a fresh full claim would have moved past, but which the
            // wait resolver also reports as PENDING; treating both as "not settled" keeps the two sides of
            // the wait in lock-step and can never end a wait on an unsettled session. Re-suspending on the
            // SAME key is idempotent: the settle event or the next sweep resumes it, and the wait timeout
            // still bounds it.
            throw new StepSuspended(self::WAIT_KIND, self::correlationKey($sessionId), ['session_id' => $sessionId]);
        }

        $session = GenerationSession::find($sessionId);

        if ($session === null) {
            // Lost between the status read and here (a purge racing the resume) — the same terminal case.
            throw new RuntimeException('The generation this step was waiting for no longer exists, so its content could not be collected.');
        }

        return [
            'session_id' => $session->id,
            'content' => $this->projector->project($session),
            'image_file_ids' => $this->exportImages($session, $config, $run),
            'status' => $session->status->value,
            'has_failed_parts' => $session->hasFailedParts(),
        ];
    }

    // ---- run() internals -------------------------------------------------------

    /**
     * REFUSE to start a generation this process would end up running INLINE.
     *
     * {@see GenerationSessionRunManager::claimAndDispatch} falls back to `config('queue.default')` when it
     * is handed no connection — and both run jobs MUTATE that value to `sync` around the step loop. So a
     * null {@see RealQueueConnection::current()} inside a run pass does not mean "dispatch normally": it
     * resolves to `sync`, the whole generation executes inside this step, and the settle event fires while
     * the run is still `running` (only the sweep would ever recover it).
     *
     * Both run jobs publish the pre-override connection, so this cannot happen today — which is exactly why
     * it is asserted rather than assumed. It runs FIRST, before the session exists, so the refusal leaves no
     * orphan draft and costs nothing. A `sync` deployment is unaffected: there the published value IS
     * `sync`, and running in-process is that deployment's correct behavior.
     */
    private function assertGenerationCanLeaveThisProcess(): void
    {
        if ($this->realQueue->current() === null && Queue::getDefaultDriver() === 'sync') {
            throw new RuntimeException('The generate_content step cannot reach the real queue connection, so its generation would run inline; the run was refused instead.');
        }
    }

    /**
     * The workflow's chosen template, from the TENANT-SCOPED model. The write-side validator already
     * required a workspace-scoped id, so reaching here with none means the template was deleted (or the
     * definition was hand-written / API-authored against another workspace) — a hard step failure either
     * way, because the whole recipe is missing.
     *
     * The `templates` table carries no soft-delete column today, so "deleted" and "gone" are the same
     * case; should one be added, the model's own global scope makes this lookup exclude it automatically.
     */
    private function requireTemplate(array $config): Template
    {
        $id = $config['template_id'] ?? null;

        if (!is_string($id) || !Str::isUuid($id)) {
            throw new RuntimeException('The generate_content step requires a template_id.');
        }

        $template = Template::find($id);

        if ($template === null) {
            throw new RuntimeException("The generate_content step's template ({$id}) no longer exists in this workspace.");
        }

        return $template;
    }

    /**
     * The {slotName: value} map to fill, resolved from `config.slots`. Each mapped value goes through the
     * SAME value-or-variable union CreateTaskStep uses, but at the slot's OWN type — recovered from its
     * stored descriptor ({@see VariableType::fromDescriptor}) rather than guessed — so a date slot gets an
     * ISO string, a number a number, a file the referenced file.
     *
     * A slot the config does NOT map is left out entirely (rather than mapped to null), so the fill report
     * reports it as unfilled from the session's own values instead of as a dropped invalid write. A slot
     * mapped in the config but no longer DECLARED by the template (the author edited the recipe after
     * writing the step) is likewise skipped here — and if it was a required one, the required-slot check
     * fails the step loudly.
     *
     * @return array<string, mixed>
     */
    private function resolveSlotValues(Template $template, array $config, array $context): array
    {
        $mapped = is_array($config['slots'] ?? null) ? $config['slots'] : [];
        $values = [];

        foreach ($this->declaredSlots($template) as $name => $descriptor) {
            if (!array_key_exists($name, $mapped)) {
                continue;
            }

            $type = VariableType::fromDescriptor($descriptor);
            $resolved = $this->resolver->resolveValueOrVariable($mapped[$name], $context, $type);

            // A FILE resolution yields a LIST of ids (a file variable carries a snapshot list), but a file
            // SLOT holds ONE reference — the only file shape the automation scope offers. Take the first
            // id; an empty list stays null so a required file slot is reported unfilled rather than
            // written as garbage.
            $values[$name] = $type === VariableType::FILE ? $this->firstFileId($resolved) : $resolved;
        }

        return $values;
    }

    /**
     * The template's DECLARED slots as `name => descriptor`. Malformed entries (no name / no descriptor)
     * are skipped — the same tolerance the generator's own slot readers have.
     *
     * @return array<string, array<string, mixed>>
     */
    private function declaredSlots(Template $template): array
    {
        $slots = is_array($template->slots) ? $template->slots : [];
        $out = [];

        foreach ($slots as $slot) {
            if (is_array($slot) && is_string($slot['name'] ?? null) && is_array($slot['descriptor'] ?? null)) {
                $out[$slot['name']] = $slot['descriptor'];
            }
        }

        return $out;
    }

    /** The first id of a resolved FILE value, or null when nothing resolved. */
    private function firstFileId(mixed $resolved): ?string
    {
        $first = (is_array($resolved) ? array_values($resolved) : [])[0] ?? null;

        return is_string($first) && $first !== '' ? $first : null;
    }

    /**
     * A required slot with no usable value HARD-FAILS the step, naming the offenders. "Required" is
     * `descriptor.nullable !== true` — there is no `required` key in a descriptor — and the fill report's
     * `unfilled_required` is the single authority (it is computed over the merged values, so it already
     * accounts for values a human filled earlier and for values the scope dropped).
     *
     * @param  array{filled: array<int, string>, skipped: array<int, array{name: string, reason: string}>, unfilled_required: array<int, string>}  $report
     */
    private function assertRequiredSlotsFilled(array $report): void
    {
        $unfilled = $report['unfilled_required'] ?? [];

        if (!is_array($unfilled) || $unfilled === []) {
            return;
        }

        throw new RuntimeException(
            'The generate_content step could not fill the required template slot(s): ' . implode(', ', $unfilled) . '.',
        );
    }

    // ---- resume() internals ----------------------------------------------------

    /**
     * The session id this wait was parked on. A wait record without one is unusable — the step cannot know
     * what settled — so it fails rather than parking again forever.
     *
     * @param  array<string, mixed>  $wait
     */
    private function waitedSessionId(array $wait): string
    {
        $payload = is_array($wait['payload'] ?? null) ? $wait['payload'] : [];
        $sessionId = $payload['session_id'] ?? null;

        if (!is_string($sessionId) || $sessionId === '') {
            throw new RuntimeException('The generate_content step resumed without a generation session to collect.');
        }

        return $sessionId;
    }

    /**
     * Export EVERY produced image of the settled session onto the workspace's Disk and return the new file
     * ids. The session's own image blobs are TRANSIENT (the lifecycle reaper purges them), so an automated
     * run that does not export loses its own output — the export is what makes the result durable.
     *
     * Uses the NON-ABORTING entry point on purpose: {@see GeneratedImageExporter::saveToDisk} aborts 404 for
     * a part with no current image, which is an HTTP contract and would surface out of a queued step as an
     * opaque failure. For a run "this part produced nothing" is an ordinary outcome, so it is skipped.
     *
     * Attribution: this happens inside the run, so HasCreator stamps every file with the RUN — see the class
     * docblock's invariant.
     *
     * @return array<int, string>
     */
    private function exportImages(GenerationSession $session, array $config, WorkflowRun $run): array
    {
        $folderId = $this->exportFolderId($config, $run);
        $ids = [];

        foreach ($this->images->producedImagePartKeys($session) as $partKey) {
            $file = $this->images->saveToDiskIfPresent($session, $partKey, $this->imageName($partKey), $folderId);

            if ($file !== null) {
                $ids[] = $file->id;
            }
        }

        return $ids;
    }

    /**
     * The Disk folder the exported images land in, or null for the Disk root.
     *
     * The id is RE-RESOLVED through the tenant-scoped model first, even though the write-side validator
     * already scoped it: the folder may have been deleted while the run waited, and FileService resolves a
     * folder with `findOrFail` — a 404 thrown out of a queued step would discard content the run already
     * PAID to generate. Degrading to the Disk root keeps the output (the loud, lossy alternative is worse
     * here) and the reason is logged with ids only, never content.
     */
    private function exportFolderId(array $config, WorkflowRun $run): ?string
    {
        $id = $this->literalString($config, 'folder_id');

        if ($id === null) {
            return null;
        }

        $folder = Str::isUuid($id) ? Folder::find($id) : null;

        if ($folder === null) {
            Log::warning('generate_content: the configured Disk folder is gone; exporting the generated images to the Disk root instead.', [
                'run_id' => $run->id,
                'folder_id' => $id,
            ]);

            return null;
        }

        return (string) $folder->getKey();
    }

    /**
     * The Disk name of one exported image. Disambiguated by PART KEY so a multi-image recipe (a storyboard)
     * does not produce a folder full of identically-named files; a file name is not an identity, so no
     * uniqueness is attempted.
     */
    private function imageName(string $partKey): string
    {
        return __('workflows.steps.generate_content.image_name') . ' - ' . $partKey . '.png';
    }

    // ---- shared ----------------------------------------------------------------

    /** A literal, already-resolved string config value, trimmed — or null when absent/blank/non-string. */
    private function literalString(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
