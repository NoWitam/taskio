<?php

namespace App\Modules\Forms\Jobs;

use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class BootstrapFormSubmissionsIndexJob implements ShouldQueue
{
    use Queueable, Batchable;

    /**
     * @param Form $form The form being indexed
     * @param string|null $cursor Cursor for pagination (null = first page)
     * @param int $perPage Number of submissions per page
     */
    public function __construct(
        private Form $form,
        private ?string $cursor = null,
        private int $perPage = 500,
    ) {}

    /**
     * Execute the job.
     * Paginates through compatible submissions and adds IndexFormSubmissionJob
     * for each one to the batch. Self-chains if more pages exist.
     */
    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $latestVersion = $this->form->latestContentVersion();

        if (!$latestVersion) {
            return;
        }

        $paginator = FormSubmission::where('form_id', $this->form->id)
            ->whereNotNull('approved_at')
            ->whereNull('deleted_at')
            ->where('form_content_version_id', $latestVersion->id)
            ->whereNull('indexed_at')
            ->orderBy('id')
            ->cursorPaginate($this->perPage, ['*'], 'cursor', $this->cursor);

        $jobs = [];

        foreach ($paginator->items() as $submission) {
            $jobs[] = new IndexFormSubmissionJob($submission);
        }

        if ($paginator->hasMorePages()) {
            $jobs[] = new self(
                $this->form,
                $paginator->nextCursor()?->encode(),
                $this->perPage,
            );
        }

        if (!empty($jobs)) {
            $this->batch()->add($jobs);
        }
    }
}
