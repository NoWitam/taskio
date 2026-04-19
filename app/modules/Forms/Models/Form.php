<?php

namespace App\Modules\Forms\Models;

use App\Enums\IconEnum;
use App\Models\AbstractModel;
use App\Modules\Changelog\Enums\ChangelogEvent;
use App\Modules\Changelog\Interfaces\HasChangelog as InterfacesHasChangelog;
use App\Modules\Changelog\Managers\FieldTracker;
use App\Modules\Changelog\Managers\ModelChangelogManager;
use App\Modules\Changelog\Traits\HasChangelog;
use App\Modules\Forms\Enums\FormElementType;
use App\Modules\Forms\Observers\FormObserver;
use App\Traits\HasCreator;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\JsonSchema\JsonSchema;

#[ObservedBy(FormObserver::class)]
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
        'indexed_at',
        'indexing_started_at',
        'content_version',
        'content_updated_at',
        'content_backup',
        'index_backup',
        'creator_id',
    ];

    protected $casts = [
        'content' => 'array',
        'content_backup' => 'array',
        'index_backup' => 'array',
        'is_anonymous' => 'boolean',
        'icon' => IconEnum::class,
        'enabled_at' => 'datetime',
        'indexed_at' => 'datetime',
        'indexing_started_at' => 'datetime',
        'content_version' => 'integer',
        'content_updated_at' => 'datetime',
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
                ->withMap(fn($value) => $value ? 'enabled' : 'disabled')
                ->forEvent(ChangelogEvent::ENABLED),
            FieldTracker::make('indexed_at')
                ->withMap(fn($value) => $value ? 'indexed' : 'unindexed')
                ->forEvent(ChangelogEvent::INDEXED),
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
     * Scope to filter only indexed forms
     */
    public function scopeOnlyIndexed(Builder $query): void
    {
        $query->whereNotNull('indexed_at');
    }

    /**
     * Scope to filter only unindexed forms
     */
    public function scopeOnlyUnindexed(Builder $query): void
    {
        $query->whereNull('indexed_at');
    }

    // ========================================
    // Activation status axis
    // ========================================

    /**
     * Check if the form is enabled
     */
    public function isEnabled(): bool
    {
        return $this->enabled_at !== null;
    }

    /**
     * Check if the form is disabled
     */
    public function isDisabled(): bool
    {
        return $this->enabled_at === null;
    }

    // ========================================
    // Index status axis
    // ========================================

    /**
     * Check if the form is indexed
     */
    public function isIndexed(): bool
    {
        return $this->indexed_at !== null;
    }

    /**
     * Check if the form is unindexed
     */
    public function isUnindexed(): bool
    {
        return $this->indexed_at === null;
    }

    // ========================================
    // Centralized capability checks
    // ========================================

    /**
     * Forms are always editable (both enabled and disabled).
     * When disabled: draft mode — relaxed validation.
     * When enabled: every save must pass full enablement validation.
     */
    public function canBeEdited(): bool
    {
        return true;
    }

    /**
     * Whether the form is in draft mode (disabled = draft).
     * Draft mode allows saving without full validation.
     */
    public function isDraft(): bool
    {
        return $this->isDisabled();
    }

    /**
     * Only enabled forms can be filled (submissions created).
     */
    public function canBeFilled(): bool
    {
        return $this->isEnabled();
    }

    /**
     * Only enabled forms can be assigned to entities.
     * Exception: assignment is allowed when entity is being created
     * or moved as archived / waiting-for-unarchive (handled at call site).
     */
    public function canBeAssigned(): bool
    {
        return $this->isEnabled();
    }

    /**
     * A form can be enabled only if the currently saved draft satisfies
     * all validation rules required for enabled state.
     */
    public function canBeEnabled(): bool
    {
        return $this->isDisabled() && $this->hasMinimumRequiredFields();
    }

    /**
     * An enabled form can be disabled. Anonymous forms cannot be disabled.
     */
    public function canBeDisabled(): bool
    {
        return $this->isEnabled() && !$this->is_anonymous;
    }

    /**
     * Only enabled forms can be indexed.
     * Cannot be indexed if already indexed or if indexing is in progress.
     */
    public function canBeIndexed(): bool
    {
        return $this->isEnabled() && $this->isUnindexed() && !$this->isIndexing();
    }

    /**
     * Only indexed forms can be unindexed.
     */
    public function canBeUnindexed(): bool
    {
        return $this->isIndexed();
    }

    /**
     * Index backup can be restored only if the form is enabled, unindexed,
     * has a backup, and the backup is compatible with the current content version.
     */
    public function canRestoreIndex(): bool
    {
        if (!$this->index_backup || $this->isIndexed() || !$this->isEnabled()) {
            return false;
        }

        $backupVersionId = $this->index_backup['table_schema']['form_content_version_id'] ?? null;
        $currentVersion = $this->latestContentVersion();

        return $backupVersionId && $currentVersion && $backupVersionId === $currentVersion->id;
    }

    /**
     * Check if the form is currently being indexed (async job in progress).
     */
    public function isIndexing(): bool
    {
        return $this->indexing_started_at !== null && $this->indexed_at === null;
    }

    /**
     * Get the available filter capabilities for this form.
     * Indexed forms get advanced field-level filters; unindexed only basic submission filters.
     */
    public function getAvailableFilters(): array
    {
        $basic = ['search', 'date_range', 'source', 'creator', 'approval_status'];

        if (!$this->isIndexed()) {
            return $basic;
        }

        return array_merge($basic, ['field_values', 'advanced_search', 'aggregate_stats']);
    }

    /**
     * Get the reporting mode for this form.
     * Indexed → 'advanced' (SQL over analytical table), unindexed → 'basic' (JSON scanning).
     */
    public function getReportingMode(): string
    {
        return $this->isIndexed() ? 'advanced' : 'basic';
    }

    /**
     * Check if a submission is compatible with the current form version.
     * Uses the form_content_version_id FK — submissions without a version link
     * (legacy) are considered incompatible.
     */
    public function isSubmissionCompatible(FormSubmission $submission): bool
    {
        $latestVersion = $this->latestContentVersion();

        if (!$latestVersion || !$submission->form_content_version_id) {
            return false;
        }

        return $submission->form_content_version_id === $latestVersion->id;
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

            // Check nested elements in sections and repeaters
            if (isset($element['config']['children']) && is_array($element['config']['children'])) {
                if ($this->hasInputFieldsInElements($element['config']['children'])) {
                    return true;
                }
            }

            // Check grid columns
            if (isset($element['config']['columns']) && is_array($element['config']['columns'])) {
                foreach ($element['config']['columns'] as $column) {
                    if (isset($column['element']) && is_array($column['element'])) {
                        if ($this->hasInputFieldsInElements([$column['element']])) {
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

    public function reports(): HasMany
    {
        return $this->hasMany(FormReport::class);
    }

    public function contentVersions(): HasMany
    {
        return $this->hasMany(FormContentVersion::class);
    }

    /**
     * Get the latest content version record for this form.
     * Uses orderByDesc('id') since UUIDv7 IDs are time-ordered.
     */
    public function latestContentVersion(): ?FormContentVersion
    {
        return $this->contentVersions()->orderByDesc('id')->first();
    }

    /**
     * Normalize field IDs from random strings to readable snake_case keys
     * Called automatically when form is being enabled
     */
    public function normalizeFieldIds(): void
    {
        if (empty($this->content)) {
            return;
        }

        $this->content = FormElementType::normalizeElements($this->content);
    }

    /**
     * Generate JsonSchema from form structure
     * Returns an array representation of the schema
     */
    public function getJsonSchema(): array
    {
        if (empty($this->content)) {
            return JsonSchema::object([])->toArray();
        }

        $properties = FormElementType::buildJsonSchema($this->content);
        
        return JsonSchema::object($properties)->toArray();
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory()
    {
        return \Database\Factories\FormFactory::new();
    }
}
