<?php

namespace App\Modules\Forms\Observers;

use App\Modules\Forms\Models\Form;

class FormObserver
{
    /**
     * Handle the Form "saving" event.
     * Normalize field IDs when form is being enabled (including re-enabled).
     */
    public function saving(Form $form): void
    {
        // Normalize field IDs when form is being enabled (first time or re-enabled)
        if ($form->isDirty('enabled_at') && $form->enabled_at !== null && $form->getOriginal('enabled_at') === null) {
            $form->normalizeFieldIds();
        }
    }
}
