<?php

namespace App\Modules\Forms\Policies;

use App\Models\User;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormReport;

class FormReportPolicy
{
    /**
     * Determine whether the user can view any form reports.
     */
    public function viewAny(?User $user): bool
    {
        return $user !== null;
    }

    /**
     * Determine whether the user can view the form report.
     */
    public function view(?User $user, FormReport $report): bool
    {
        return $user !== null;
    }

    /**
     * Determine whether the user can create form reports.
     * Only the creator of the form can create reports for it.
     */
    public function createReport(?User $user, Form $form): bool
    {
        if ($user === null) {
            return false;
        }

        return $form->creator_id === $user->id;
    }

    /**
     * Determine whether the user can delete the form report.
     * Only the creator of the form can delete reports.
     */
    public function delete(?User $user, FormReport $report): bool
    {
        if ($user === null) {
            return false;
        }

        $report->loadMissing('form');
        
        return $report->form->creator_id === $user->id;
    }

    /**
     * Determine whether the user can restore the form report.
     */
    public function restore(?User $user, FormReport $report): bool
    {
        if ($user === null) {
            return false;
        }

        $report->loadMissing('form');
        
        return $report->form->creator_id === $user->id;
    }

    /**
     * Determine whether the user can permanently delete the form report.
     */
    public function forceDelete(?User $user, FormReport $report): bool
    {
        if ($user === null) {
            return false;
        }

        $report->loadMissing('form');
        
        return $report->form->creator_id === $user->id;
    }
}
