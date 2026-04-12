<?php

namespace App\Modules\Forms\Observers;

use App\Modules\Forms\Jobs\SyncFormSubmissionFieldsJob;
use App\Modules\Forms\Models\FormSubmission;

class FormSubmissionObserver
{
    /**
     * Handle the FormSubmission "saved" event.
     * Detects when a submission is approved and dispatches job to sync fields.
     */
    public function saved(FormSubmission $submission): void
    {
        // Check if approved_at was changed and submission is now approved
        if ($submission->wasChanged('approved_at') && $submission->isApproved()) {
            // Dispatch job to queue for asynchronous processing
            SyncFormSubmissionFieldsJob::dispatch($submission);
        }
    }
}
