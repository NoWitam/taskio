<?php

namespace App\Modules\Forms\Observers;

use App\Modules\Forms\Jobs\IndexFormSubmissionJob;
use App\Modules\Forms\Models\FormSubmission;

class FormSubmissionObserver
{
    /**
     * Handle the FormSubmission "saved" event.
     * Detects when a submission is approved and dispatches:
     * - IndexFormSubmissionJob: indexes into analytical table (if form is indexed and version matches)
     */
    public function saved(FormSubmission $submission): void
    {
        // Check if submission is approved — either freshly changed or created with approval
        // wasChanged() only works on update (performUpdate calls syncChanges),
        // so for Model::create() with approved_at set inline, we also check wasRecentlyCreated
        $justApproved = $submission->wasChanged('approved_at')
            || ($submission->wasRecentlyCreated && $submission->approved_at);

        if ($justApproved && $submission->isApproved()) {
            // Index into analytical table if the form is indexed and submission version is compatible
            $form = $submission->form;
            if ($form && $form->isIndexed() && $form->isSubmissionCompatible($submission)) {
                IndexFormSubmissionJob::dispatch($submission);
            }
        }
    }
}
