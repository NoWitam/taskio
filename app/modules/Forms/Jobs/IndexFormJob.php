<?php

namespace App\Modules\Forms\Jobs;

use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Services\FormAnalyticalTableService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class IndexFormJob implements ShouldQueue
{
    use Queueable, Batchable;

    public function __construct(
        private Form $form
    ) {}

    /**
     * Execute the job.
     * Creates the analytical table, then adds the bootstrap pagination job to the batch.
     */
    public function handle(FormAnalyticalTableService $analyticalTableService): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $analyticalTableService->createTable($this->form);

        $this->batch()->add([
            new BootstrapFormSubmissionsIndexJob($this->form),
        ]);
    }
}
