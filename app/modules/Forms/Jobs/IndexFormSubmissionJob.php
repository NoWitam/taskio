<?php

namespace App\Modules\Forms\Jobs;

use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Forms\Services\FormAnalyticalTableService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class IndexFormSubmissionJob implements ShouldQueue
{
    use Queueable, Batchable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private FormSubmission $submission
    ) {}

    /**
     * Execute the job.
     * Indexes a single submission into the form's analytical table.
     * Works both during batch bootstrap (form is indexing) and for new submissions (form is indexed).
     */
    public function handle(FormAnalyticalTableService $analyticalTableService): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        // Skip already indexed submissions
        if ($this->submission->indexed_at) {
            return;
        }

        $form = $this->submission->form;

        if (!$form) {
            return;
        }

        // Allow indexing during bootstrap (isIndexing) and for new submissions (isIndexed)
        if (!$form->isIndexed() && !$form->isIndexing()) {
            return;
        }

        $analyticalTableService->indexSubmission($form, $this->submission);
    }
}
