<?php

namespace App\Modules\Workflows\Steps;

use App\Modules\Tasks\DTOs\TaskDTO;
use App\Modules\Tasks\Enums\TaskPriority;
use App\Modules\Tasks\Services\TaskService;
use App\Modules\Workflows\Enums\WorkflowStepType;
use App\Modules\Workflows\Enums\WorkflowVariableType;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Modules\Workflows\Services\WorkflowVariableResolver;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * Creates a task from the step's resolved config, THROUGH TaskService::create() (so the
 * task's transaction, label attach, file guard, and bot-dispatch side effects all fire as
 * they would for a user-created task). A workflow-created task starts in TO_DO.
 *
 * The step reaches ENTITY-FORM PARITY with the create-task form (attachments deferred):
 *
 *   - title        resolveString (directives + flat tokens + literals); REQUIRED non-blank
 *                  after resolution — a blank title HARD-FAILS the step (RuntimeException;
 *                  the runner records a step failure and stops the run). A titleless task
 *                  is meaningless, so this is the one field that fails the whole run.
 *   - description  resolveString over the markdown body. Passed to TaskService as a plain
 *                  string; the Task model's MarkdownTreeCast::set converts it via
 *                  MarkdownTree::fromPlainText — the SAME sink the API write path uses (the
 *                  request sends a `nullable|string` description and the cast owns storage
 *                  serialization). The step invents no new storage path.
 *   - priority     structured literal|variable union coerced to an enum string, matched
 *                  against TaskPriority. Unknown / unresolved / null → SOFT-DEFAULT to
 *                  MEDIUM (mirrors the pre-B4 behavior and the form's implicit default) —
 *                  a bad priority should not abort a run that can still create a useful task.
 *   - deadline     structured literal|variable union coerced to a date (ISO string) →
 *                  Carbon, or null on any unresolvable/blank/unparseable value (SOFT — a
 *                  missing deadline is a valid task, exactly like the nullable form field).
 *   - labels       literal array of Label ids passed straight to TaskDTO.labels;
 *                  TaskService attaches only ids that resolve (Label::whereIn(...)->pluck),
 *                  so a nonexistent / foreign id is SILENTLY IGNORED (never throws) — the
 *                  same lenient attach the API path performs.
 *   - assignee     literal polymorphic (assignee_type ∈ user|bot + assignee_id uuid),
 *                  both-or-neither; mirrors TaskDTO's polymorphic assignee. A bot assignee
 *                  goes through TaskService's normal maybeDispatch (only an execution-
 *                  capable bot on a TO_DO task starts a run).
 *   - form_id / approval_pipeline_id  literal uuids, optional.
 *
 * Output: task_id, title.
 */
class CreateTaskStep implements WorkflowStep
{
    public function __construct(
        private TaskService $tasks,
        private WorkflowVariableResolver $resolver,
    ) {}

    public function type(): WorkflowStepType
    {
        return WorkflowStepType::CREATE_TASK;
    }

    public static function outputDescriptors(): array
    {
        return [
            ['name' => 'task_id', 'type' => WorkflowVariableType::TEXT],
            ['name' => 'title', 'type' => WorkflowVariableType::TEXT],
        ];
    }

    public function run(array $config, WorkflowRun $run, array $context): array
    {
        $title = $this->requireString($config, 'title');

        [$assigneeType, $assigneeId] = $this->resolveAssignee($config);

        $task = $this->tasks->create(new TaskDTO(
            title: $title,
            description: $this->resolveDescription($config),
            priority: $this->resolvePriority($config, $context),
            deadline: $this->resolveDeadline($config, $context),
            assigneeType: $assigneeType,
            assigneeId: $assigneeId,
            labels: $this->resolveLabels($config),
            attachments: [],
            form_id: $this->literalUuid($config, 'form_id'),
            approval_pipeline_id: $this->literalUuid($config, 'approval_pipeline_id'),
        ));

        return [
            'task_id' => $task->id,
            'title' => $task->title,
        ];
    }

    /**
     * The title is already reference-resolved by the runner (resolveString ran over the
     * whole config), so a variable that produced nothing arrives as ''. A blank title is a
     * hard failure — the runner records the step failed and stops the run.
     */
    private function requireString(array $config, string $key): string
    {
        $value = $config[$key] ?? null;

        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException("create_task step requires a non-empty `{$key}`.");
        }

        return $value;
    }

    /**
     * The resolved markdown body, or null when absent/blank. Passed as a plain string;
     * MarkdownTreeCast::set serializes it to the stored tree (the same sink the API uses).
     */
    private function resolveDescription(array $config): ?string
    {
        $value = $config['description'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * priority via the literal|variable union, coerced to an enum string then matched
     * against TaskPriority. Anything unknown/unresolved SOFT-DEFAULTS to MEDIUM.
     */
    private function resolvePriority(array $config, array $context): TaskPriority
    {
        $resolved = $this->resolver->resolveValueOrVariable(
            $config['priority'] ?? null,
            $context,
            WorkflowVariableType::ENUM,
        );

        return TaskPriority::tryFrom((string) ($resolved ?? '')) ?? TaskPriority::MEDIUM;
    }

    /**
     * deadline via the literal|variable union, coerced to an ISO date string then to Carbon.
     * Any unresolvable/blank/unparseable value SOFT-resolves to null (a task with no deadline).
     */
    private function resolveDeadline(array $config, array $context): ?Carbon
    {
        $resolved = $this->resolver->resolveValueOrVariable(
            $config['deadline'] ?? null,
            $context,
            WorkflowVariableType::DATE,
        );

        if (!is_string($resolved) || $resolved === '') {
            return null;
        }

        try {
            return Carbon::parse($resolved);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The literal label ids. Non-array config yields no labels; non-string entries are
     * dropped. TaskService attaches only ids that exist, so foreign/unknown ids are
     * silently ignored (never throw).
     *
     * @return array<int, string>
     */
    private function resolveLabels(array $config): array
    {
        $labels = $config['labels'] ?? [];

        if (!is_array($labels)) {
            return [];
        }

        return array_values(array_filter($labels, 'is_string'));
    }

    /**
     * The polymorphic assignee pair from literal config: both keys or neither. A partial
     * pair (only one of type/id) is treated as no assignee — the same both-or-neither
     * invariant the request enforces.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveAssignee(array $config): array
    {
        $type = $config['assignee_type'] ?? null;
        $id = $config['assignee_id'] ?? null;

        if (!is_string($type) || $type === '' || !is_string($id) || $id === '') {
            return [null, null];
        }

        return [$type, $id];
    }

    /** A literal uuid config value, or null when absent/blank/non-string. */
    private function literalUuid(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
