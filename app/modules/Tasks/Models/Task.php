<?php

namespace App\Modules\Tasks\Models;

use App\Casts\MarkdownTreeCast;
use App\Models\AbstractModel;
use App\Models\User;
use App\Modules\Approvals\DTOs\ApprovalQueueItem;
use App\Modules\Approvals\Interfaces\Approvable;
use App\Modules\Approvals\Models\ApprovalProcess;
use App\Modules\Approvals\Traits\HasApprovalPipeline;
use App\Modules\Bot\Models\Bot;
use App\Modules\Changelog\Interfaces\HasChangelog as InterfacesHasChangelog;
use App\Modules\Changelog\Managers\BagTracker;
use App\Modules\Changelog\Managers\FieldTracker;
use App\Modules\Changelog\Managers\ModelChangelogManager;
use App\Modules\Changelog\Managers\StatusTracker;
use App\Modules\Changelog\Traits\HasChangelog;
use App\Modules\Comments\Traits\HasComments;
use App\Modules\Disk\Models\File;
use App\Modules\Disk\Traits\HasFiles;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Labels\Models\Label;
use App\Modules\Labels\Traits\HasLabels;
use App\Modules\Tasks\Enums\TaskPriority;
use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Tasks\Observers\TaskObserver;
use App\Traits\Archiving;
use App\Traits\HasCreator;
use App\Traits\TenantAware;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

#[ObservedBy(TaskObserver::class)]
class Task extends AbstractModel implements Approvable, InterfacesHasChangelog
{
    use Archiving, HasApprovalPipeline, HasChangelog, HasComments, HasCreator, HasFactory, HasFiles, HasLabels, HasUuids, SoftDeletes, TenantAware;

    protected $table = 'tasks';

    /**
     * Relations eager-loaded whenever a single Task is returned as a TaskResource,
     * so every detail endpoint produces a shape-stable response.
     */
    public const DETAIL_RELATIONS = [
        'assigned',
        'assignee',
        'creator',
        'labels',
        'files',
        'form',
        'formSubmission',
        'approvalPipeline.stages.approver',
        'approvalPipeline.stages.approverBot',
        'pendingApprovalProcess.stage',
        'pendingApprovalProcess.approver',
        'pendingApprovalProcess.approverBot',
        'pendingApprovalProcess.pipeline',
    ];

    protected $fillable = [
        'title',
        'description',
        'status',
        'priority',
        'deadline',
        'creator_id',
        'assignee_type',
        'assignee_id',
        // Virtual back-compat attribute: writing `assigned_id` maps to a User
        // assignee (assignee_type='user'). Kept fillable so legacy callers, the
        // factory and existing tests that set `assigned_id` keep working.
        'assigned_id',
        'form_id',
        'approval_pipeline_id',
    ];

    protected $casts = [
        'description' => MarkdownTreeCast::class,
        'priority' => TaskPriority::class,
        'status' => TaskStatus::class,
        'deadline' => 'date',
        'bot_runs_used' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    /** Whether the assigned bot is currently awaiting a human reply to continue. */
    public function isBotWaiting(): bool
    {
        return $this->assignee_type === 'bot' && $this->bot_run_state === 'waiting';
    }

    public function getChangelogManager(): ModelChangelogManager
    {
        return new ModelChangelogManager($this, [
            StatusTracker::make('status')->trackOriginalInUpdating(),
            FieldTracker::make('title')->withComparison(),
            FieldTracker::make('description')->withComparison(),
            FieldTracker::make('priority')->asComponent('badge')->withMap(function (?TaskPriority $priority, Task $task) {
                if (!$priority) {
                    return null;
                }

                return [
                    'label' => $priority->label(),
                    'tone' => $priority->tone(),
                    'icon' => $priority->icon(),
                    'translation_key' => 'changelog.priorityValues.' . $priority->value,
                ];
            }),
            FieldTracker::make('deadline')->withMap(function ($date, Task $task) {
                return $date ? $date->format('Y-m-d') : null;
            }),
            // Tracks the real `assignee_id` column (the legacy `assigned_id` is now a
            // virtual accessor, so getOriginal() can't see it). The actor may be a User
            // or a Bot; resolve whichever owns the id for the audit display.
            FieldTracker::make('assignee_id')->withTranslationKey('changelog.fields.assigned_id')->withMap(function ($assigneeId, Task $task) {
                if (!$assigneeId) {
                    return null;
                }

                // Bypass the member scope: changelog must render historical assignees
                // even if they are no longer (or never were) a member of the active
                // workspace — audit history must not silently lose names.
                $user = User::withoutWorkspaceMemberScope()->find($assigneeId);

                if ($user) {
                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'avatar' => null, // TODO: implement avatar URL when ready
                        'is_bot' => false,
                    ];
                }

                $bot = Bot::find($assigneeId);

                return $bot ? [
                    'id' => $bot->id,
                    'name' => $bot->name,
                    'email' => null,
                    'avatar' => null,
                    'is_bot' => true,
                ] : null;
            }),
            BagTracker::make('labels')->asClass(Label::class)->manualOnly()->withMap(function (Label $label, Task $task) {
                return [
                    'id' => $label->id,
                    'name' => $label->name,
                    'color' => $label->color,
                    'icon' => $label->icon?->value,
                ];
            }),
            BagTracker::make('files')->asClass(File::class)->manualOnly()->withTranslationKey('changelog.fields.attachments')->withMap(function (File $file, Task $task) {
                return [
                    'id' => $file->id,
                    'name' => $file->name,
                    'type' => $file->type,
                    'size' => $file->size,
                ];
            }),
            FieldTracker::make('form_id')->withMap(function ($formId, Task $task) {
                if (!$formId) {
                    return null;
                }
                $form = Form::find($formId);

                return $form ? [
                    'id' => $form->id,
                    'name' => $form->name,
                    'icon' => $form->icon?->value,
                ] : null;
            }),
        ]);
    }

    /**
     * Polymorphic assignee (User|Bot). A task may be executed by a human or a bot.
     *
     * The User branch bypasses WorkspaceMemberScope on load so a former-member
     * assignee still renders (consistent with how comment/changelog authors are
     * loaded for actor-display fields) instead of nulling out and breaking the
     * resource. The Bot branch loads normally (bots are workspace-scoped, not
     * member-scoped).
     */
    public function assignee(): \Illuminate\Database\Eloquent\Relations\MorphTo
    {
        return $this->morphTo('assignee')->constrain([
            User::class => fn ($query) => $query->withoutWorkspaceMemberScope(),
        ]);
    }

    /**
     * Back-compat User-only assignee relation. Keyed on `assignee_id`; for a bot
     * assignee the id never matches a users row, so this resolves to null — exactly
     * the "null when a bot" semantics legacy callers / resources / the frontend
     * (which expect a User) rely on. For a User assignee it resolves unchanged.
     */
    public function assigned()
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /**
     * Back-compat accessor: the legacy `assigned_id` column was replaced by the
     * polymorphic (assignee_type, assignee_id) pair. This returns the assignee id ONLY
     * when the assignee is a User (null for a bot), preserving the historic semantics
     * for the changelog tracker, resources and the approval reject-restore snapshot.
     */
    public function getAssignedIdAttribute(): ?string
    {
        return $this->assignee_type === 'user' ? $this->assignee_id : null;
    }

    /**
     * Back-compat virtual setter: writing `assigned_id` assigns the task to a User.
     * A null/empty value clears the assignee. This is NOT a real column — it maps onto
     * the polymorphic (assignee_type, assignee_id) pair so legacy write paths keep working.
     */
    public function setAssignedIdAttribute(?string $value): void
    {
        if ($value === null || $value === '') {
            $this->attributes['assignee_type'] = null;
            $this->attributes['assignee_id'] = null;

            return;
        }

        $this->attributes['assignee_type'] = 'user';
        $this->attributes['assignee_id'] = $value;
    }

    public function form()
    {
        return $this->belongsTo(Form::class, 'form_id');
    }

    public function formSubmission()
    {
        return $this->morphOne(FormSubmission::class, 'submittable');
    }

    public function isCompleted(): bool // TODO czy formularz jest poprawnie uzupełniony
    {
        return true;
    }

    public function isDeadlineOverdue(): bool
    {
        if (is_null($this->deadline)) {
            return false;
        }

        return $this->deadline->format('Y-m-d') < now()->format('Y-m-d');
    }

    public function isDeadlineAtRisk(): bool
    {
        if (is_null($this->deadline)) {
            return false;
        }

        if ($this->isDeadlineOverdue()) {
            return false;
        }

        return $this->deadline->clone()->subDays(2)->format('Y-m-d') >= now()->format('Y-m-d');
    }

    // -- Approvable interface --

    public function onApprovalCompleted(ApprovalProcess $process): void
    {
        // If this task was executed by a bot (the bot is the ORIGINAL assignee
        // snapshotted when approval started), record that the bot's work was accepted
        // and the task reached done. Users are unaffected.
        app(\App\Modules\Bot\Services\BotTaskExecutionService::class)
            ->recordMarkedDoneFromContext($this, $process->context ?? []);

        // A completed task returns to its (human) creator.
        $this->update([
            'status' => TaskStatus::DONE,
            'assignee_type' => 'user',
            'assignee_id' => $this->creator_id,
        ]);
    }

    public function onApprovalRejected(ApprovalProcess $process): void
    {
        $context = $process->context ?? [];

        // Restore the polymorphic original assignee (User OR Bot) snapshotted when the
        // approval started. Falls back to the legacy user-only key, then the creator.
        $originalType = $context['original_assignee_type']
            ?? (($context['original_assigned_id'] ?? null) ? 'user' : null);
        $originalId = $context['original_assignee_id']
            ?? $context['original_assigned_id']
            ?? null;

        $this->update([
            'status' => TaskStatus::TO_DO,
            'assignee_type' => $originalType ?? 'user',
            'assignee_id' => $originalId ?? $this->creator_id,
        ]);

        // If the restored assignee is a bot, kick off a REVISION run so it addresses the
        // rejection reasons (surfaced from the approval history in the run context).
        // Deferred to afterCommit so a sync job sees the committed reject state.
        if (($originalType ?? 'user') === 'bot') {
            $task = $this->fresh() ?? $this;
            \Illuminate\Support\Facades\DB::afterCommit(function () use ($task) {
                app(\App\Modules\Bot\Services\BotTaskExecutionService::class)->reviseAfterReject($task);
            });
        }
    }

    /**
     * @return array<int, string>
     */
    public function approvalQueueRelations(): array
    {
        return ['form', 'formSubmission', 'labels'];
    }

    public function toApprovalQueueItem(): ApprovalQueueItem
    {
        $this->loadMissing($this->approvalQueueRelations());

        $extraFields = [
            [
                'label' => __('changelog.fields.priority'),
                'value' => $this->priority->label(),
                'icon' => $this->priority->icon(),
            ],
        ];

        if ($this->deadline) {
            $extraFields[] = [
                'label' => __('changelog.fields.deadline'),
                'value' => $this->deadline->format('d.m.Y'),
                'icon' => 'calendar',
            ];
        }

        $form = null;
        if ($this->form) {
            $form = [
                'id' => $this->form->id,
                'name' => $this->form->name,
                'content' => $this->form->content,
                'submission' => $this->formSubmission?->data,
            ];
        }

        $commentsUrl = route('comments.index', ['module' => 'tasks', 'id' => $this->id]);

        return new ApprovalQueueItem(
            type_label: __('approvals.entity_types.task'),
            type_icon: 'check-circle',
            name: $this->title,
            description: $this->description,
            extra_fields: $extraFields,
            form: $form,
            comments_url: $commentsUrl,
        );
    }

    public function getApprovalContext(): array
    {
        return [
            // Polymorphic snapshot so a bot assignee is restored correctly on reject.
            'original_assignee_type' => $this->assignee_type,
            'original_assignee_id' => $this->assignee_id,
            // Back-compat key (user-only): null when the original assignee was a bot.
            'original_assigned_id' => $this->assigned_id,
        ];
    }

    protected static function newFactory()
    {
        return \Database\Factories\TaskFactory::new();
    }
}
