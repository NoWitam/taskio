<?php

use App\Modules\Forms\Jobs\SyncFormSubmissionFieldsJob;
use App\Modules\Forms\Models\FormSubmission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * IMPORTANT: Make sure `php artisan queue:work` is running before running this migration!
     * This migration dispatches Jobs to the queue for asynchronous processing.
     */
    public function up(): void
    {
        // Get all approved submissions
        FormSubmission::onlyApproved()
            ->chunk(100, function ($submissions) {
                foreach ($submissions as $submission) {
                    // Dispatch job to queue for each submission
                    SyncFormSubmissionFieldsJob::dispatch($submission);
                }
            });

        // Note: The actual processing happens asynchronously in the queue.
        // Monitor progress with: SELECT COUNT(*) FROM form_submission_fields;
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Truncate the table (CASCADE will handle this)
        DB::table('form_submission_fields')->truncate();
    }
};
