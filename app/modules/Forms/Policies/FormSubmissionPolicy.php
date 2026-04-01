<?php

namespace App\Modules\Forms\Policies;

use App\Models\User;
use App\Modules\Forms\Models\FormSubmission;

class FormSubmissionPolicy
{
    /**
     * Determine whether the user can view any form submissions.
     */
    public function viewAny(?User $user): bool
    {
        return $user !== null;
    }

    /**
     * Determine whether the user can view the form submission.
     */
    public function view(?User $user, FormSubmission $submission): bool
    {
        return $user !== null;
    }

    /**
     * Determine whether the user can create form submissions.
     */
    public function create(?User $user): bool
    {
        return $user !== null;
    }

    /**
     * Determine whether the user can update the form submission.
     * Only draft (non-approved) submissions can be edited.
     */
    public function update(?User $user, FormSubmission $submission): bool
    {
        if ($user === null) {
            return false;
        }

        // Only drafts can be edited
        return $submission->canBeEdited();
    }

    /**
     * Determine whether the user can delete the form submission.
     */
    public function delete(?User $user, FormSubmission $submission): bool
    {
        return $user !== null;
    }

    /**
     * Determine whether the user can restore the form submission.
     */
    public function restore(?User $user, FormSubmission $submission): bool
    {
        return $user !== null;
    }

    /**
     * Determine whether the user can permanently delete the form submission.
     */
    public function forceDelete(?User $user, FormSubmission $submission): bool
    {
        return $user !== null;
    }
}
