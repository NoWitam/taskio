<?php

namespace App\Modules\Forms\Models;

use App\Enums\IconEnum;
use App\Models\AbstractModel;
use App\Models\User;
use App\Modules\Changelog\Enums\ChangelogEvent;
use App\Modules\Changelog\Interfaces\HasChangelog as InterfacesHasChangelog;
use App\Modules\Changelog\Managers\FieldTracker;
use App\Modules\Changelog\Managers\ModelChangelogManager;
use App\Modules\Changelog\Traits\HasChangelog;
use App\Modules\Forms\Enums\FormElementType;
use App\Traits\HasCreator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Form extends AbstractModel implements InterfacesHasChangelog
{
    use HasCreator, HasUuids, SoftDeletes, HasChangelog, HasFactory;

    protected $table = 'forms';

    protected $fillable = [
        'name',
        'icon',
        'description',
        'content',
        'is_anonymous',
        'enabled_at',
        'creator_id',
    ];

    protected $casts = [
        'content' => 'array',
        'is_anonymous' => 'boolean',
        'icon' => IconEnum::class,
        'enabled_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function getChangelogManager(): ModelChangelogManager
    {
        // Anonymous forms don't have changelog tracking
        if ($this->is_anonymous) {
            return new ModelChangelogManager($this, []);
        }

        return new ModelChangelogManager($this, [
            FieldTracker::make('name')->withComparison(),
            FieldTracker::make('icon')->withMap(function (?IconEnum $icon, Form $form) {
                return $icon?->value;
            }),
            FieldTracker::make('description')->withComparison(),
            FieldTracker::make('enabled_at')
                ->withMap(fn($value) => $value ? 'enabled' : null)
                ->forEvent(ChangelogEvent::ENABLED),
        ]);
    }

    /**
     * Scope to filter only enabled forms
     */
    public function scopeOnlyEnabled(Builder $query): void
    {
        $query->whereNotNull('enabled_at');
    }

    /**
     * Scope to filter only disabled (not enabled) forms
     */
    public function scopeOnlyDisabled(Builder $query): void
    {
        $query->whereNull('enabled_at');
    }

    /**
     * Check if the form is enabled
     */
    public function isEnabled(): bool
    {
        return $this->enabled_at !== null;
    }

    /**
     * Check if the form content can be edited
     * Content can be edited only when form is not enabled yet
     */
    public function canBeEdited(): bool
    {
        return !$this->isEnabled();
    }

    /**
     * Check if the form has at least one input field
     * Required before enabling the form
     */
    public function hasMinimumRequiredFields(): bool
    {
        if (empty($this->content)) {
            return false;
        }

        return $this->hasInputFieldsInElements($this->content);
    }

    /**
     * Recursively check if elements contain at least one input field
     */
    private function hasInputFieldsInElements(array $elements): bool
    {
        foreach ($elements as $element) {
            if (!isset($element['type'])) {
                continue;
            }

            $type = FormElementType::tryFrom($element['type']);
            
            if ($type && $type->isInputElement()) {
                return true;
            }

            // Check nested elements in sections, grids, and repeaters
            if (isset($element['elements']) && is_array($element['elements'])) {
                if ($this->hasInputFieldsInElements($element['elements'])) {
                    return true;
                }
            }

            // Check grid columns
            if (isset($element['columns']) && is_array($element['columns'])) {
                foreach ($element['columns'] as $column) {
                    if (isset($column['elements']) && is_array($column['elements'])) {
                        if ($this->hasInputFieldsInElements($column['elements'])) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class);
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory()
    {
        return \Database\Factories\FormFactory::new();
    }
}
