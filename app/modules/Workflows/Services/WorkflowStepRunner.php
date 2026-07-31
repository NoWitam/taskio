<?php

namespace App\Modules\Workflows\Services;

use App\Modules\Variables\Contracts\AuthorVoiceResolver;
use App\Modules\Variables\Services\VariableResolver;
use App\Modules\Variables\Support\AiVoiceContext;
use App\Modules\Variables\Support\FunctionScope;
use App\Modules\Workflows\Enums\WorkflowRunState;
use App\Modules\Workflows\Enums\WorkflowRunStepStatus;
use App\Modules\Workflows\Exceptions\StepSuspended;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Modules\Workflows\Models\WorkflowRunStep;
use App\Modules\Workflows\Steps\SuspendableWorkflowStep;
use Throwable;

/**
 * Executes a CLAIMED run's ordered steps and finalizes the run. For each step (in the
 * workflow definition's order):
 *
 *   1. resolve the step config's `{{...}}` references against the live context,
 *   2. run the step (through its domain service),
 *   3. record a WorkflowRunStep audit row (succeeded + output | failed + error),
 *   4. merge the step output into context.steps.<key>, persisted on the run as it goes.
 *
 * Steps commit INDEPENDENTLY — there is no all-or-nothing transaction around the run (same
 * intended design as the bot tools): a workflow that created a task and then failed to
 * assign a bot leaves the task in place, and the timeline shows exactly where it stopped.
 *
 * First step failure stops the run (later steps do not execute) and releases it `failed`
 * with the step error. All steps succeeding releases it `completed`. The active run is
 * published to WorkflowRunContext for the duration and cleared in a finally (the seam Batch
 * 3's dispatcher reads to origin-tag workflow-authored re-triggers).
 *
 * SUSPEND / RESUME. A step may instead throw {@see StepSuspended}: it handed work to something
 * outside this process. The loop then unwinds, the run is parked `waiting` (context + wait
 * descriptors persisted), and NOTHING else runs — no step row is written, later steps do not
 * execute. Much later a FRESH job calls resume(), which rebuilds the context FROM THE DATABASE
 * (never a serialized continuation), re-enters at the suspended position through the step's
 * resume(), clears the wait, and carries on with the remaining steps as if nothing had happened.
 *
 * A workflow without a suspending step never reaches any of that code and behaves byte-identically
 * to before the capability existed — pinned by WorkflowRunEngineRegressionTest.
 */
class WorkflowStepRunner
{
    public function __construct(
        private WorkflowStepFactory $steps,
        private VariableResolver $resolver,
        private WorkflowRunManager $runManager,
        private WorkflowRunContext $runContext,
        private WorkflowVariableCatalogService $catalog,
        private WorkflowAiTextService $aiText,
        private AiVoiceContext $voice,
        private AuthorVoiceResolver $authorVoices,
    ) {}

    /** First pass over a freshly claimed run: every step, from position 0. */
    public function run(WorkflowRun $run): void
    {
        $this->execute($run, null);
    }

    /**
     * Continue a run that was parked `waiting` and has since been re-claimed (`claimResume`). The
     * run's own persisted `waiting_on` record says where to re-enter; a run with none is not
     * resumable and is failed rather than silently restarted from the top.
     */
    public function resume(WorkflowRun $run): void
    {
        $waitingOn = is_array($run->waiting_on) ? $run->waiting_on : null;

        if ($waitingOn === null) {
            $this->runManager->release($run, WorkflowRunState::FAILED, __('workflows.runs.resume_without_wait'));

            return;
        }

        $this->execute($run, $waitingOn);
    }

    /**
     * The one step loop, for both passes. $resumeFrom is null on a first pass and the persisted
     * `waiting_on` record on a resume.
     *
     * @param  array<string, mixed>|null  $resumeFrom
     */
    private function execute(WorkflowRun $run, ?array $resumeFrom): void
    {
        $run->loadMissing('workflow');
        $workflow = $run->workflow;
        $definition = array_values($workflow?->steps ?? []);
        $definitionHash = $this->definitionHash($definition);

        // DEFINITION-DRIFT GUARD. The workflow may have been edited (or deleted) during the wait.
        // Re-entering at a position whose step is no longer the one that suspended would silently
        // SKIP work and then mark the run `completed` — the worst possible outcome. Fail loudly
        // instead. Nothing below this point runs on a drifted resume.
        if ($resumeFrom !== null && $this->hasDefinitionDrift($workflow === null, $definition, $definitionHash, $resumeFrom)) {
            $this->runManager->release($run, WorkflowRunState::FAILED, __('workflows.runs.definition_changed'));

            return;
        }

        $startPosition = $resumeFrom !== null ? (int) $resumeFrom['position'] : 0;

        // The context steps read: `trigger` = the run's trigger payload, `steps` grows as
        // each step returns its output, `globals` = the workspace's user-created LITERAL constants
        // as a `{<key>: <value>}` map (form-independent, resolvable in every workflow). The run
        // executes with the workspace active (QueueTenancy), so globalValues() is workspace-scoped.
        //
        // On a RESUME everything here is rebuilt FROM THE DATABASE: `steps` comes back from the
        // context the suspension persisted, while globals / custom functions / the type map are
        // RE-READ LIVE. That is the deliberate semantic of the fresh-job idiom (D12): a constant
        // edited while the run waited affects the steps that run AFTER the resume, exactly as it
        // would for any run started after the edit.
        //
        // The SUSPENDED step itself is the one exception: its config is NOT re-resolved but replayed
        // from `waiting_on.config`, the already-resolved value it suspended with (see the loop), so
        // a spend-incurring directive in it is paid exactly once and the outcome is collected under
        // the very config the external work was started with.
        $context = [
            'trigger' => $run->trigger_payload ?? [],
            'steps' => $resumeFrom !== null ? (array) ($run->context['steps'] ?? []) : [],
            'globals' => $this->catalog->globalValues(),
        ];

        // The workspace's custom FUNCTIONS (workspace-scoped like globalValues), fetched once. They ride
        // the resolver/executor context under FunctionScope so a `fn:<uuid>` op in a step config resolves +
        // executes — but are kept OUT of the persisted $context (a VO is not JSON), by threading them only
        // into a per-step $execContext while $context stays the clean, persistable run state.
        $functions = $this->catalog->customFunctionOperations();

        // The run's path → variable-type map lets the resolver execute directive / if-block
        // pipelines against each reference's REAL type (recovered from the catalog, not the
        // degraded editor primitive). Built once for the whole run.
        $typeMap = $workflow !== null ? $this->catalog->runtimeTypeMap($workflow) : [];

        // SAVE/RESTORE, not set/clear — the same defect class the queue escape hatch closed. A re-triggered
        // CHILD run executes IN-PROCESS inside a step (the loop forces the `sync` driver), so this method can
        // be nested inside itself. An unconditional clear() in the finally would wipe the PARENT's published
        // run, and every row a later parent step authored would stop being stamped with it (ADR-0015). The
        // outermost frame restores null, i.e. the old clear().
        $outerRun = $this->runContext->current();
        $this->runContext->set($run);

        // The per-RUN `@[ai-text]` budget. SET (not merely inherited from a fresh instance) at the
        // start of every pass: a resumed pass re-seeds the count the suspension persisted, so a run
        // that parks does not get the cap all over again. The outer value is restored in the finally
        // because a re-triggered child run executes IN-PROCESS inside a step — it must neither spend
        // nor reset the budget of the run it is nested in.
        $outerAiTextCalls = $this->aiText->callsMade();
        $this->aiText->restoreCalls($resumeFrom !== null ? (int) ($resumeFrom['ai_text_calls'] ?? 0) : 0);

        // PER-BLOCK `@[ai-text]` AUTHORS. A workflow has no snapshot to freeze voices into (a run always
        // executes the workflow AS IT IS NOW), so they are resolved LIVE, once per pass, over the WHOLE
        // definition — one batch lookup for every step's config, never one per block. Resolving live is the
        // right answer for SUSPEND/RESUME too: a resumed pass re-enters through here and rebuilds the map
        // from the database like everything else it rebuilds, so nothing about voices has to be persisted
        // into the wait record or replayed. This must happen BEFORE the loop, because it is the loop's
        // config RESOLUTION that executes the ai-text blocks.
        //
        // TENANCY: the workspace is pinned EXPLICITLY off the run — a queued run has no active workspace, so
        // an ambient-only lookup would be unconstrained and could resolve a FOREIGN author's voice (in
        // own-database mode the run carries no id and the connection is the boundary).
        //
        // SAVE/RESTORE, exactly like the run context + the ai-text budget above and for the same reason: a
        // re-triggered CHILD run executes IN-PROCESS inside a step, and an unconditional clear() in the
        // finally would strip the PARENT's authors from every step it still has to run.
        //
        // Only the SAVE (a pure getter) sits out here — the map is INSTALLED as the first statement inside
        // the try below, because it is the one line of this prologue that reads the DATABASE. A throwable
        // escaping it from OUT here would skip the whole `finally` and leave a DEAD run published
        // PROCESS-WIDE: every later job this worker picks up would stamp its rows with it (ADR-0015) and be
        // charged for its AI spend, and the failed run's ai-text budget would ride along too.
        $outerAuthorVoices = $this->voice->authorVoices();

        try {
            // FIRST, and inside the try on purpose (see above). authorVoicesFor() additionally absorbs its
            // own failures, so the ordinary outcome of a broken lookup is a pass that loses its TONE.
            $this->voice->setAuthorVoices($this->authorVoicesFor($run, $definition));

            foreach ($definition as $position => $step) {
                if ($position < $startPosition) {
                    continue;
                }

                $key = (string) ($step['key'] ?? $position);
                $type = (string) ($step['type'] ?? '');
                $rawConfig = is_array($step['config'] ?? null) ? $step['config'] : [];

                // Only the position the wait targets re-enters through resume(); every later step
                // runs normally, so the resumed pass is an ordinary run from there on.
                $resuming = $resumeFrom !== null && $position === $startPosition;

                // Thread the functions into the context the resolver + step see (never the persisted one).
                $execContext = $functions === [] ? $context : FunctionScope::forFunctions($functions)->writeInto($context);

                // The suspended step REPLAYS the config it parked with instead of resolving again.
                // Absent only on a row written before the config was persisted — such a wait falls
                // back to re-resolution, which is the old (paid-twice) behaviour and the best that
                // can be done for it.
                $persistedConfig = $resuming && is_array($resumeFrom['config'] ?? null)
                    ? $resumeFrom['config']
                    : null;

                $config = [];

                try {
                    $config = $persistedConfig ?? $this->resolver->resolve($rawConfig, $execContext, $typeMap);
                    $instance = $this->steps->makeFromValue($type);

                    $output = $resuming && $instance instanceof SuspendableWorkflowStep
                        ? $instance->resume($config, $run, $execContext, $resumeFrom)
                        : $instance->run($config, $run, $execContext);
                } catch (StepSuspended $suspension) {
                    // ORDERED BEFORE catch (Throwable) ON PURPOSE — PHP takes the FIRST matching
                    // catch, so a later-ordered clause here would never fire and every suspension
                    // would be recorded as a step failure.
                    //
                    // No step row is written (D14): the timeline shape of existing workflows is
                    // untouched, and the row is written ONCE on resume, succeeded or failed as
                    // usual. $context (not $execContext) is persisted — the clean, JSON-safe state
                    // holding every EARLIER step's output.
                    //
                    // The RESOLVED config rides along (replayed on resume, never re-resolved), with
                    // the whole definition's fingerprint and the run's ai-text spend so far. Note a
                    // step may suspend AGAIN from resume(): this then re-parks with the replayed
                    // config, so a multi-leg wait still pays for its config exactly once.
                    $this->runManager->suspend(
                        $run,
                        $position,
                        $key,
                        $type,
                        $suspension,
                        $context,
                        is_array($config) ? $config : [],
                        $definitionHash,
                        $this->aiText->callsMade(),
                    );

                    return;
                } catch (Throwable $e) {
                    $this->recordStep($run, $position, $type, $key, WorkflowRunStepStatus::FAILED, null, $e->getMessage());
                    $this->runManager->release($run, WorkflowRunState::FAILED, $e->getMessage());

                    return;
                }

                $this->recordStep($run, $position, $type, $key, WorkflowRunStepStatus::SUCCEEDED, $output, null);

                // Publish this step's output so later steps can `{{steps.<key>.*}}` it, and
                // persist the accumulated context on the run as it advances. On the resumed step
                // the wait is CLEARED in that same write — the output and the end of the wait
                // become visible together, so no observer can see a published output while the run
                // still looks parked.
                $context['steps'][$key] = $output;
                $run->update($resuming
                    ? ['context' => $context, 'waiting_on' => null, 'waiting_key' => null, 'waiting_since' => null]
                    : ['context' => $context]);
            }

            $this->runManager->release($run, WorkflowRunState::COMPLETED);
        } finally {
            $outerRun === null ? $this->runContext->clear() : $this->runContext->set($outerRun);
            $this->aiText->restoreCalls($outerAiTextCalls);
            $this->voice->setAuthorVoices($outerAuthorVoices);
        }
    }

    /**
     * The `{authorId: opaque voice}` map for a run: every `@[ai-text]` author named anywhere in the
     * definition's step configs, resolved in ONE lookup through the Variables CONTRACT.
     *
     * BOUNDARY: Workflows asks the CONTRACT and never learns what an author actually is — the module that
     * owns authors binds the concrete. Naming it here would couple the engine to a sibling module (pinned
     * by WorkflowsGeneratorBoundaryTest).
     *
     * FAIL-SAFE end to end: the resolver never throws and simply OMITS what it cannot resolve, so a deleted
     * or foreign author costs a step its authored TONE and never its text.
     *
     * That promise is also UPHELD HERE rather than merely trusted. "Never throws" is a contract clause, not
     * a language guarantee: a severed connection, a deadlock, a tenant database missing the authors' table
     * would all surface as a throwable from an implementation that reads one. Since the value at stake is a
     * TONE and the cost of a leak is the whole run (plus, before the install moved inside the run scope, the
     * process-wide leak described there), a failure degrades to the empty map — exactly the outcome an
     * unresolvable author already has. It is deliberately NOT reported: a QueryException's message carries
     * the failed statement AND its bindings, i.e. the author ids, which never go to a log.
     *
     * @param  array<int, mixed>  $definition
     * @return array<string, string>
     */
    private function authorVoicesFor(WorkflowRun $run, array $definition): array
    {
        $ids = [];

        foreach ($definition as $step) {
            $config = is_array($step) && is_array($step['config'] ?? null) ? $step['config'] : [];

            foreach ($this->authorIdsIn($config) as $id) {
                $ids[] = $id;
            }
        }

        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return [];
        }

        try {
            return $this->authorVoices->voicesFor($ids, $run->workspace_id);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Every author id named anywhere in a (possibly deeply nested) step config. Each STRING leaf goes
     * through the SHARED {@see VariableResolver::collectAiTextAuthorIds} — the same scanner the resolver's
     * own execution walk uses, descending into nested prompts and if-block branches — so a collected author
     * is exactly one the run could actually generate with.
     *
     * @return array<int, string>
     */
    private function authorIdsIn(mixed $config): array
    {
        if (is_array($config)) {
            $ids = [];

            foreach ($config as $value) {
                foreach ($this->authorIdsIn($value) as $id) {
                    $ids[] = $id;
                }
            }

            return $ids;
        }

        return is_string($config) && $config !== '' ? $this->resolver->collectAiTextAuthorIds($config) : [];
    }

    /**
     * Has the workflow definition drifted away from the step that suspended? True when the workflow
     * is gone (deleted/trashed), the WHOLE definition no longer fingerprints the same, the position
     * no longer exists, or the step now sitting there is not the same step (key or type changed —
     * i.e. steps were reordered, removed or edited), or it is no longer a suspendable step at all
     * (nothing to hand the settled work to; re-running its `run()` could duplicate the external work).
     *
     * The FINGERPRINT is the authoritative check: the per-position checks below only see the ONE
     * position the wait targets, so an edit anywhere else — a step appended after it, a later step's
     * config rewritten, or the suspended step's OWN config changed (same key, same type) — used to
     * pass silently and alter a run already in flight. The per-position checks stay because they are
     * the precise diagnostic when the hash says something moved.
     *
     * A wait persisted BEFORE the fingerprint existed carries no hash; such a row keeps the old,
     * per-position-only guard rather than failing every legacy resume.
     *
     * @param  array<int, mixed>  $definition
     * @param  array<string, mixed>  $resumeFrom
     */
    private function hasDefinitionDrift(bool $workflowMissing, array $definition, string $definitionHash, array $resumeFrom): bool
    {
        if ($workflowMissing) {
            return true;
        }

        $persistedHash = $resumeFrom['definition_hash'] ?? null;

        if (is_string($persistedHash) && $persistedHash !== $definitionHash) {
            return true;
        }

        $position = $resumeFrom['position'] ?? null;

        if (!is_int($position) || $position < 0) {
            return true;
        }

        $step = $definition[$position] ?? null;

        if (!is_array($step)) {
            return true;
        }

        // The SAME fallback the loop keys a step with — a legacy step without a `key` is parked
        // under its position, so anything else would fail every such resume as phantom drift.
        if ((string) ($step['key'] ?? $position) !== (string) ($resumeFrom['step_key'] ?? '')) {
            return true;
        }

        if ((string) ($step['type'] ?? '') !== (string) ($resumeFrom['step_type'] ?? '')) {
            return true;
        }

        return !$this->isSuspendable((string) $step['type']);
    }

    /**
     * A fingerprint of the WHOLE ordered step definition, persisted at suspend and re-computed on
     * resume. Hashed over the same `array_values()`-normalized structure both times, so a no-op save
     * of an unchanged definition is not a false positive. A definition that cannot be encoded hashes
     * as an empty string, which never matches a persisted hash — fail loudly, not silently.
     *
     * @param  array<int, mixed>  $definition
     */
    private function definitionHash(array $definition): string
    {
        $encoded = json_encode($definition);

        return $encoded === false ? '' : sha1($encoded);
    }

    /** Whether a step type still resolves to a suspendable implementation (an unknown type does not). */
    private function isSuspendable(string $type): bool
    {
        try {
            return $this->steps->makeFromValue($type) instanceof SuspendableWorkflowStep;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function recordStep(
        WorkflowRun $run,
        int $position,
        string $type,
        string $key,
        WorkflowRunStepStatus $status,
        ?array $payload,
        ?string $error,
    ): void {
        WorkflowRunStep::create([
            'workflow_run_id' => $run->id,
            'position' => $position,
            'type' => $type,
            'key' => $key,
            'status' => $status,
            'payload' => $payload,
            'error' => $error,
        ]);
    }
}
