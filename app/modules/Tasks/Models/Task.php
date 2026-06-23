<?php

namespace App\Modules\Tasks\Models;

use App\Casts\MarkdownTreeCast;
use App\Models\AbstractModel;
use App\Models\User;
use App\Modules\Approvals\DTOs\ApprovalQueueItem;
use App\Modules\Approvals\Interfaces\Approvable;
use App\Modules\Approvals\Models\ApprovalProcess;
use App\Modules\Approvals\Traits\HasApprovalPipeline;
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
use App\Traits\Archiving;
use App\Traits\HasCreator;
use App\Traits\TenantAware;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

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
        'creator',
        'labels',
        'files',
        'form',
        'formSubmission',
        'approvalPipeline.stages.approver',
        'pendingApprovalProcess.stage',
        'pendingApprovalProcess.approver',
        'pendingApprovalProcess.pipeline',
    ];

    protected $fillable = [
        'title',
        'description',
        'status',
        'priority',
        'deadline',
        'creator_id',
        'assigned_id',
        'form_id',
        'approval_pipeline_id',
    ];

    protected $casts = [
        'description' => MarkdownTreeCast::class,
        'priority' => TaskPriority::class,
        'status' => TaskStatus::class,
        'deadline' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

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
            FieldTracker::make('assigned_id')->withMap(function ($userId, Task $task) {
                if (!$userId) {
                    return null;
                }
                $user = User::find($userId);

                return $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar' => null, // TODO: implement avatar URL when ready
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

    public function assigned()
    {
        return $this->belongsTo(User::class, 'assigned_id');
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
        $this->update([
            'status' => TaskStatus::DONE,
            'assigned_id' => $this->creator_id,
        ]);
    }

    public function onApprovalRejected(ApprovalProcess $process): void
    {
        $context = $process->context ?? [];

        $this->update([
            'status' => TaskStatus::TO_DO,
            'assigned_id' => $context['original_assigned_id'] ?? $this->creator_id,
        ]);
    }

    public function toApprovalQueueItem(): ApprovalQueueItem
    {
        $this->loadMissing(['form', 'formSubmission', 'labels']);

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
            'original_assigned_id' => $this->assigned_id,
        ];
    }

    protected static function newFactory()
    {
        return \Database\Factories\TaskFactory::new();
    }
}
