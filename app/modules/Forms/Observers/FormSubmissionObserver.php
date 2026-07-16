<?php

namespace App\Modules\Forms\Observers;

use App\Modules\Forms\Jobs\IndexFormSubmissionJob;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Workflows\Enums\WorkflowTriggerType;
use App\Modules\Workflows\Services\WorkflowDispatchService;
use App\Modules\Workflows\Services\WorkflowTriggerPayloadFactory;
use Illuminate\Support\Facades\DB;

class FormSubmissionObserver
{
    /**
     * Handle the FormSubmission "saved" event.
     * Detects when a submission is approved and dispatches:
     * - IndexFormSubmissionJob: indexes into analytical table (if form is indexed and version matches)
     * - the workflow form_submitted trigger (a Taskio submission counts as "submitted" once
     *   approved — a task's form stays a draft while worked on and is confirmed on completion,
     *   so we fire at the SAME approved-transition the indexer uses, never for drafts).
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

            // Fire the workflow trigger after commit so the run sees the committed submission
            // (and its derived task) — the single afterCommit layer for this seam.
            DB::afterCommit(function () use ($submission) {
                $payload = app(WorkflowTriggerPayloadFactory::class)->fromFormSubmission($submission);
                app(WorkflowDispatchService::class)->dispatch(WorkflowTriggerType::FORM_SUBMITTED, $payload);
            });
        }
    }
}
