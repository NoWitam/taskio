<?php

namespace App\Modules\Forms\Observers;

use App\Modules\Forms\Models\Form;

class FormObserver
{
    /**
     * Handle the Form "saving" event.
     * Normalize field IDs when form is being enabled for the first time.
     */
    public function saving(Form $form): void
    {
        // Check if enabled_at is being set for the first time
        if ($form->isDirty('enabled_at') && $form->enabled_at !== null && $form->getOriginal('enabled_at') === null) {
            // Normalize field IDs before saving
            $form->normalizeFieldIds();
        }
    }
}
