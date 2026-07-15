<?php

namespace App\Modules\Forms\Policies;

use App\Models\User;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormReport;
use App\Policies\Concerns\ChecksRecordOwnership;

class FormReportPolicy
{
    use ChecksRecordOwnership;

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
     * Determine whether the user can delete the form report.
     * Only the creator of the form can delete reports.
     */
    public function delete(?User $user, FormReport $report): bool
    {
        $report->loadMissing('form');

        return $this->ownsOrManagesSystemRecord($report->form, $user);
    }

    /**
     * Determine whether the user can restore the form report.
     */
    public function restore(?User $user, FormReport $report): bool
    {
        $report->loadMissing('form');

        return $this->ownsOrManagesSystemRecord($report->form, $user);
    }

    /**
     * Determine whether the user can permanently delete the form report.
     */
    public function forceDelete(?User $user, FormReport $report): bool
    {
        $report->loadMissing('form');

        return $this->ownsOrManagesSystemRecord($report->form, $user);
    }
}
