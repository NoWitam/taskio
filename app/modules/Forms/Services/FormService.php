<?php

namespace App\Modules\Forms\Services;

use App\Modules\Forms\DTOs\FormDTO;
use App\Modules\Forms\Jobs\IndexFormJob;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormContentVersion;
use App\Modules\Workflows\Models\WorkflowRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;

class FormService
{
    public function __construct(
        private FormAnalyticalTableService $analyticalTableService
    ) {}

    public function create(FormDTO $dto): Form
    {
        $isAnonymous = $dto->is_anonymous;
        // Anonymous forms don't require a user-supplied name; fall back to a default.
        $name = $isAnonymous && blank($dto->name) ? __('forms.anonymousDefaultName') : $dto->name;

        $form = Form::create([
            'name' => $name,
            'icon' => $dto->icon,
            'description' => $dto->description,
            'content' => $dto->content,
            'is_anonymous' => $isAnonymous,
            // Anonymous forms are automatically enabled
            'enabled_at' => $isAnonymous ? now() : null,
            'content_version' => $isAnonymous ? 1 : 0,
            'content_updated_at' => $isAnonymous ? now() : null,
        ]);

        // Record initial content version for anonymous forms (auto-enabled)
        if ($isAnonymous && !empty($dto->content)) {
            $this->recordContentVersion($form);
        }

        return $form;
    }

    public function update(Form $form, FormDTO $dto): Form
    {
        $updateData = [
            'name' => $dto->name,
            'icon' => $dto->icon,
            'description' => $dto->description,
            'is_anonymous' => $dto->is_anonymous,
        ];

        $shouldRecordVersion = false;

        // Content is always editable
        if (!empty($dto->content)) {
            $contentChanged = $dto->content !== $form->content;
            $updateData['content'] = $dto->content;

            // Increment content version when content changes on an enabled form
            if ($contentChanged && $form->isEnabled()) {
                $updateData['content_version'] = $form->content_version + 1;
                $updateData['content_updated_at'] = now();
                $shouldRecordVersion = true;
            }
        }

        $form->update($updateData);

        // Record content version snapshot after save
        if ($shouldRecordVersion) {
            $this->recordContentVersion($form);
        }

        return $form;
    }

    public function delete(Form $form): void
    {
        $form->delete();
    }

    public function restore(Form $form): Form
    {
        $form->restore();

        return $form;
    }

    /**
     * Enable the form, making it ready to accept submissions
     *
     * @throws ValidationException if form doesn't have minimum required fields or is already enabled
     */
    public function enable(Form $form): Form
    {
        // Idempotent - if already enabled, just return the form
        if ($form->isEnabled()) {
            return $form;
        }

        // Validate that form has at least one input field
        if (!$form->hasMinimumRequiredFields()) {
            throw ValidationException::withMessages([
                'content' => ['Formularz musi zawierać co najmniej jedno pole wejściowe.'],
            ]);
        }

        $newVersion = $form->content_version + 1;

        $form->update([
            'enabled_at' => now(),
            'content_version' => $newVersion,
            'content_updated_at' => now(),
        ]);

        $form = $form->fresh();

        // Record first content version snapshot
        $this->recordContentVersion($form);

        return $form;
    }

    /**
     * Disable the form, putting it back into draft mode.
     * Preserves a backup of the current content for potential re-enable.
     * Disabling does NOT automatically unindex the form.
     *
     * @throws ValidationException if form cannot be disabled
     */
    public function disable(Form $form): Form
    {
        if ($form->isDisabled()) {
            return $form;
        }

        if ($form->is_anonymous) {
            throw ValidationException::withMessages([
                'form' => ['Formularz anonimowy nie może zostać wyłączony.'],
            ]);
        }

        $form->update([
            'enabled_at' => null,
            'content_backup' => $form->content,
        ]);

        return $form->fresh();
    }

    /**
     * Index the form, enabling advanced filtering, reporting and AI search.
     * Dispatches an async job to create the analytical table and bootstrap data.
     * Sets indexing_started_at to prevent duplicate triggers.
     * Only enabled forms can be indexed.
     *
     * @throws ValidationException if form cannot be indexed
     */
    public function indexForm(Form $form): Form
    {
        if ($form->isIndexed()) {
            return $form;
        }

        if (!$form->isEnabled()) {
            throw ValidationException::withMessages([
                'form' => ['Formularz musi być włączony przed indeksowaniem.'],
            ]);
        }

        if ($form->isIndexing()) {
            return $form;
        }

        $form->update([
            'indexing_started_at' => now(),
        ]);

        $this->dispatchIndexBatch($form);

        return $form->fresh();
    }

    /**
     * Unindex the form, removing advanced filtering capabilities.
     * Drops the dedicated analytical table.
     * Optionally creates an internal-only backup of index metadata.
     *
     * @throws ValidationException if form cannot be unindexed
     */
    public function unindex(Form $form, bool $backupIndexes = false): Form
    {
        if ($form->isUnindexed()) {
            return $form;
        }

        $updateData = [
            'indexed_at' => null,
            'indexing_started_at' => null,
        ];

        if ($backupIndexes) {
            $updateData['index_backup'] = [
                'indexed_at' => $form->indexed_at->toISOString(),
                'content_version' => $form->content_version,
                'backed_up_at' => now()->toISOString(),
                'table_schema' => $this->analyticalTableService->getTableSchema($form),
            ];
        }

        // Drop the analytical table
        $this->analyticalTableService->dropTable($form);

        $form->update($updateData);

        return $form->fresh();
    }

    /**
     * Restore indexes from backup.
     * Only allowed if the current form's content version is compatible
     * with the backed-up version (same form_content_version_id).
     *
     * @throws ValidationException if restore is not possible
     */
    public function restoreIndex(Form $form): Form
    {
        if (!$form->index_backup) {
            throw ValidationException::withMessages([
                'form' => ['Brak kopii zapasowej indeksu do przywrócenia.'],
            ]);
        }

        if ($form->isIndexed()) {
            throw ValidationException::withMessages([
                'form' => ['Formularz jest już zindeksowany. Najpierw usuń istniejący indeks.'],
            ]);
        }

        if (!$form->isEnabled()) {
            throw ValidationException::withMessages([
                'form' => ['Formularz musi być włączony przed przywróceniem indeksu.'],
            ]);
        }

        // Check compatibility: backed-up version must match current latest version
        $backupVersionId = $form->index_backup['table_schema']['form_content_version_id'] ?? null;
        $currentVersion = $form->latestContentVersion();

        if (!$backupVersionId || !$currentVersion || $backupVersionId !== $currentVersion->id) {
            throw ValidationException::withMessages([
                'form' => ['Kopia zapasowa indeksu jest niezgodna z bieżącą wersją formularza.'],
            ]);
        }

        if ($form->isIndexing()) {
            return $form;
        }

        // Start indexing (will recreate table and bootstrap data)
        $form->update([
            'indexing_started_at' => now(),
        ]);

        $this->dispatchIndexBatch($form);

        // Clear the backup after triggering restore
        $form->update([
            'index_backup' => null,
        ]);

        return $form->fresh();
    }

    public function index(Request $request)
    {
        return $this->listQuery($request)->cursorPaginate(12);
    }

    public function count(Request $request): int
    {
        return $this->listQuery($request)->count();
    }

    protected function listQuery(Request $request): Builder
    {
        return Form::query()
            // creator is polymorphic (User|WorkflowRun|Bot); load a run's workflow so
            // CreatorResource renders the automation name without an N+1 per row.
            ->with(['creator' => fn ($creator) => $creator->morphWith([WorkflowRun::class => ['workflow']])])
            ->withCount('submissions')
            ->where('is_anonymous', false)
            ->when(
                request()->boolean('trashed'),
                fn (Builder $query) => $query->onlyTrashed()
            )
            ->when(
                request()->filled('enabled'),
                fn (Builder $query) => request()->boolean('enabled') ? $query->whereNotNull('enabled_at') : $query->whereNull('enabled_at')
            )
            ->when(
                request()->filled('indexed'),
                fn (Builder $query) => request()->boolean('indexed') ? $query->whereNotNull('indexed_at') : $query->whereNull('indexed_at')
            )
            ->search(['name', 'description'], $request->get('search'))
            ->latest('created_at');
    }

    /**
     * Record a content version snapshot for the form.
     * Stores the current content and its JSON schema for historical reference.
     * Links to the previous version via parent_id for parent-child history.
     */
    protected function recordContentVersion(Form $form): FormContentVersion
    {
        $parentVersion = $form->latestContentVersion();

        return FormContentVersion::create([
            'form_id' => $form->id,
            'parent_id' => $parentVersion?->id,
            'version' => $form->content_version,
            'content' => $form->content,
            'json_schema' => $form->getJsonSchema(),
        ]);
    }

    /**
     * Dispatch the indexing batch for a form.
     * Creates a single batch containing IndexFormJob (table creation),
     * BootstrapFormSubmissionsIndexJob (pagination), and IndexFormSubmissionJob (per-submission).
     * The form is marked as indexed only when the entire batch completes.
     */
    private function dispatchIndexBatch(Form $form): void
    {
        Bus::batch([new IndexFormJob($form)])
            ->name("index-form-{$form->id}")
            ->allowFailures()
            ->then(function () use ($form) {
                $form->indexed_at = now();
                $form->saveQuietly();
            })
            ->catch(function () use ($form) {
                $form->indexing_started_at = null;
                $form->saveQuietly();
            })
            ->dispatch();
    }
}
