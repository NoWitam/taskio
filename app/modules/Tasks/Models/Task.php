<?php

namespace App\Modules\Tasks\Models;

use App\Casts\MarkdownTreeCast;
use App\Models\AbstractModel;
use App\Models\User;
use App\Modules\Changelog\Interfaces\HasChangelog as InterfacesHasChangelog;
use App\Modules\Changelog\Managers\FieldTracker;
use App\Modules\Changelog\Managers\BagTracker;
use App\Modules\Changelog\Managers\StatusTracker;
use App\Modules\Changelog\Managers\ModelChangelogManager;
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
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends AbstractModel implements InterfacesHasChangelog
{
    use HasCreator, HasUuids, SoftDeletes, HasFiles, HasLabels, HasComments, HasChangelog, Archiving;
    
    protected $table = 'tasks';

    protected $fillable = [
        'title',
        'description',
        'status',
        'priority',
        'deadline',
        'creator_id',
        'assigned_id',
        'form_id',
    ];

    protected $casts = [
        'description' => MarkdownTreeCast::class,
        'priority' => TaskPriority::class,
        'status' => TaskStatus::class,
        'deadline' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'archived_at' => 'datetime'
    ];

    public function getChangelogManager(): ModelChangelogManager
    {
        return new ModelChangelogManager($this, [
            StatusTracker::make('status')->trackOriginalInUpdating(),
            FieldTracker::make('title')->withComparison(),
            FieldTracker::make('description')->withComparison(),
            FieldTracker::make('priority')->asComponent('badge')->withMap(function (?TaskPriority $priority, Task $task) {
                if (!$priority) return null;
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
                if (!$userId) return null;
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
                    'size' => $file->size
                ];
            }),
            FieldTracker::make('form_id')->withMap(function ($formId, Task $task) {
                if (!$formId) return null;
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
        if(is_null($this->deadline)) {
            return false;
        }

        return $this->deadline->format('Y-m-d') < now()->format('Y-m-d');
    }

    public function isDeadlineAtRisk(): bool
    {
        if(is_null($this->deadline)) {
            return false;
        }

        if($this->isDeadlineOverdue()) {
            return false;
        }

        return $this->deadline->clone()->subDays(2)->format('Y-m-d') >= now()->format('Y-m-d');
    }
}
