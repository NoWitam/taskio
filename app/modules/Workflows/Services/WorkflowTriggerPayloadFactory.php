<?php

namespace App\Modules\Workflows\Services;

use App\Modules\Disk\Models\File;
use App\Modules\Forms\Enums\FormElementType;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Tasks\Models\Task;
use Illuminate\Support\Str;

/**
 * Builds the WHITELISTED trigger payload snapshot for each trigger type — the single place
 * that decides what `{{trigger.*}}` sees. Both the real event trigger (via the dispatch
 * service) and MANUAL runs build their payload here, so a step behaves identically however
 * the run was started.
 *
 * A payload is an array of SCALARS and IDS (plus a submission's whitelisted answer map): never
 * a full model dump, never a secret, never a relation graph. This keeps the persisted
 * trigger_payload small, safe to log, and stable across batches. The concrete shapes
 * (documented per method):
 *
 *   form_submitted
 *     submission:   {id}
 *     form:         {id, name, is_anonymous}
 *     task:         {…task snapshot} | null   (present only for a task-attached submission)
 *     source:       'manual' | 'task'          (normalized from the submittable morph)
 *     submitted_at: ISO-8601 instant the submission was approved (approved_at)
 *     fields:       {<fieldId>: <scalar|array>, …}  (the submission's whitelisted answers)
 *
 *   schedule
 *     scheduled_at: ISO-8601 UTC instant the run was started for. Carries no domain entity —
 *     a schedule run has no triggering row — so `{{trigger.scheduled_at}}` is all a step sees.
 */
class WorkflowTriggerPayloadFactory
{
    /**
     * schedule: the minimal snapshot for a cadence-fired run. Both the due-sweep and a manual
     * schedule run build the payload here so `{{trigger.scheduled_at}}` has identical shape
     * however the run started. $scheduledAt defaults to now (a manual run's fire instant).
     */
    public function fromSchedule(?\Carbon\CarbonInterface $scheduledAt = null): array
    {
        return ['scheduled_at' => ($scheduledAt ?? now())->toIso8601String()];
    }

    /**
     * form_submitted: the submission + form snapshot, the normalized `source`, the approved
     * timestamp, and the whitelisted answer map. A task-attached submission ALSO carries the
     * task snapshot (else task is null). `form.is_anonymous` is carried in the payload so the
     * dispatch `anonymous` match needs no extra query.
     */
    public function fromFormSubmission(FormSubmission $submission): array
    {
        $submission->loadMissing('form');

        $task = $this->attachedTask($submission);

        return [
            'submission' => [
                'id' => $submission->id,
            ],
            'form' => [
                'id' => $submission->form_id,
                'name' => $submission->form?->name,
                'is_anonymous' => (bool) $submission->form?->is_anonymous,
            ],
            'task' => $task ? $this->taskSnapshot($task) : null,
            'source' => $this->normalizeSource($submission),
            'submitted_at' => $submission->approved_at?->toIso8601String(),
            'fields' => $this->whitelistFields($submission),
        ];
    }

    /**
     * The Task a submission is attached to, or null. A Task-attached submission carries the
     * `task` morph alias; a manual (Form) submission carries no task. Matched robustly against
     * BOTH the enforced morph alias ('task') and the FQCN, since a directly-seeded row may
     * store either form.
     */
    private function attachedTask(FormSubmission $submission): ?Task
    {
        if (!$this->isTaskSource($submission->submittable_type)) {
            return null;
        }

        return Task::find($submission->submittable_id);
    }

    /**
     * Normalize the submission's morph `submittable_type` to a stable source label:
     * a Task-attached submission is `'task'`, everything else (a manual Form submission) is
     * `'manual'`. Robust to the enforced morph alias, the FQCN, or a legacy value.
     */
    private function normalizeSource(FormSubmission $submission): string
    {
        return $this->isTaskSource($submission->submittable_type) ? 'task' : 'manual';
    }

    /** Whether a raw submittable_type refers to a Task (morph alias 'task' or the Task FQCN). */
    private function isTaskSource(?string $submittableType): bool
    {
        if ($submittableType === null) {
            return false;
        }

        return $submittableType === (new Task)->getMorphClass()
            || ltrim($submittableType, '\\') === ltrim(Task::class, '\\');
    }

    /**
     * The submission's answers, keyed by field id, as `{{trigger.fields.<id>}}` sees them.
     * The `data` column is cast to an array; we whitelist to SCALAR and ARRAY values only so
     * no object/resource can leak into the persisted payload. A malformed/empty data column
     * yields an empty map.
     *
     * File answers are the exception: a raw file uuid is enriched into the snapshot list the
     * FILE variable speaks ({id, name, mime_type, size}), so `{{trigger.fields.<id>}}` and the
     * `file` condition operators see a real file rather than an opaque id.
     *
     * @return array<string, mixed>
     */
    private function whitelistFields(FormSubmission $submission): array
    {
        $data = $submission->data;

        if (!is_array($data)) {
            return [];
        }

        $whitelisted = array_filter(
            $data,
            fn ($value) => is_scalar($value) || is_array($value) || $value === null,
        );

        return $this->enrichFileFields($whitelisted, $submission);
    }

    /**
     * Replace every file answer (a uuid, or a list of them) with its resolved snapshot list.
     * Field ids are unique across a normalized form, so a key-based recursive replace reaches a
     * file nested in a section or repeated in a repeater without ambiguity. An unanswered or
     * unresolvable file becomes [] — exactly what the file operators read as "empty".
     *
     * @param  array<string, mixed>  $whitelisted
     * @return array<string, mixed>
     */
    private function enrichFileFields(array $whitelisted, FormSubmission $submission): array
    {
        $content = $submission->form?->content;

        if (!is_array($content) || $content === []) {
            return $whitelisted;
        }

        $snapshots = [];

        foreach (FormElementType::collectFileAnswers($content, $submission->data ?? []) as $answer) {
            $snapshots[$answer['field']] = $this->fileSnapshots($answer['value']);
        }

        return $snapshots === [] ? $whitelisted : $this->replaceByKey($whitelisted, $snapshots);
    }

    /**
     * Resolve a file answer into the snapshot list the FILE variable speaks. A single-file input
     * yields a one-element list; an empty/malformed answer yields []. Files are re-queried under
     * the workspace scope, so a foreign or trashed id simply drops out.
     *
     * @return array<int, array{id: string, name: string, mime_type: ?string, size: ?int}>
     */
    private function fileSnapshots(mixed $value): array
    {
        $ids = collect(is_array($value) ? $value : [$value])
            ->filter(fn ($v) => is_string($v) && Str::isUuid($v))
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return File::query()
            ->whereIn('id', $ids->all())
            ->get()
            ->map(fn (File $file) => [
                'id' => $file->id,
                'name' => $file->name,
                'mime_type' => $file->mime_type,
                'size' => $file->size,
            ])
            ->all();
    }

    /**
     * Recursively replace values whose key is a resolved file field. A replaced value is never
     * recursed into (the snapshot list is terminal); other arrays (sections, repeater items)
     * are walked so a nested file answer is enriched too.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, array<int, mixed>>  $snapshots
     * @return array<string, mixed>
     */
    private function replaceByKey(array $data, array $snapshots): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && array_key_exists($key, $snapshots)) {
                $data[$key] = $snapshots[$key];
            } elseif (is_array($value)) {
                $data[$key] = $this->replaceByKey($value, $snapshots);
            }
        }

        return $data;
    }

    /**
     * The task snapshot for a task-attached submission. label_ids is materialized here so any
     * in-memory condition check never re-queries the pivot.
     *
     * @return array<string, mixed>
     */
    private function taskSnapshot(Task $task): array
    {
        $labelIds = $task->relationLoaded('labels')
            ? $task->labels->pluck('id')->all()
            : $task->labels()->pluck('labels.id')->all();

        return [
            'id' => $task->id,
            'title' => $task->title,
            'status' => $task->status?->value,
            'priority' => $task->priority?->value,
            // creator is polymorphic: emit the morph type alongside the id so a condition can
            // distinguish a human creator from a run/bot-created (system) task.
            'creator_id' => $task->creator_id,
            'creator_type' => $task->creator_type,
            'assignee_type' => $task->assignee_type,
            'assignee_id' => $task->assignee_id,
            'form_id' => $task->form_id,
            'approval_pipeline_id' => $task->approval_pipeline_id,
            'label_ids' => array_values($labelIds),
        ];
    }
}
