<?php

namespace App\Modules\Forms\Policies;

use App\Models\User;
use App\Modules\Forms\Models\Form;
use App\Policies\Concerns\ChecksRecordOwnership;

class FormPolicy
{
    use ChecksRecordOwnership;

    /**
     * Determine whether the user can view any forms.
     */
    public function viewAny(?User $user): bool
    {
        return $user !== null;
    }

    /**
     * Determine whether the user can view the form.
     */
    public function view(?User $user, Form $form): bool
    {
        return $user !== null;
    }

    /**
     * Determine whether the user can create forms.
     */
    public function create(?User $user): bool
    {
        return $user !== null;
    }

    /**
     * Determine whether the user can update the form.
     */
    public function update(?User $user, Form $form): bool
    {
        return $this->ownsOrManagesSystemRecord($form, $user);
    }

    /**
     * Determine whether the user can delete the form.
     */
    public function delete(?User $user, Form $form): bool
    {
        return $this->ownsOrManagesSystemRecord($form, $user);
    }

    /**
     * Determine whether the user can restore the form.
     */
    public function restore(?User $user, Form $form): bool
    {
        return $this->ownsOrManagesSystemRecord($form, $user);
    }

    /**
     * Determine whether the user can permanently delete the form.
     */
    public function forceDelete(?User $user, Form $form): bool
    {
        return $this->ownsOrManagesSystemRecord($form, $user);
    }

    /**
     * Determine whether the user can enable the form.
     */
    public function enable(?User $user, Form $form): bool
    {
        return $this->ownsOrManagesSystemRecord($form, $user);
    }

    /**
     * Determine whether the user can disable the form.
     */
    public function disable(?User $user, Form $form): bool
    {
        return $this->ownsOrManagesSystemRecord($form, $user);
    }

    /**
     * Determine whether the user can index the form.
     */
    public function index(?User $user, Form $form): bool
    {
        return $this->ownsOrManagesSystemRecord($form, $user);
    }

    /**
     * Determine whether the user can unindex the form.
     */
    public function unindex(?User $user, Form $form): bool
    {
        return $this->ownsOrManagesSystemRecord($form, $user);
    }

    /**
     * Determine whether the user can create form reports.
     * Only the creator of the form (or the workspace owner for a system form) can create reports.
     */
    public function createReport(?User $user, Form $form): bool
    {
        return $this->ownsOrManagesSystemRecord($form, $user);
    }
}
