<?php

namespace App\Modules\Workspaces\Enums;

/**
 * Lifecycle of a workspace invitation. A pending invite becomes accepted when
 * the recipient joins, revoked when an admin cancels it, or expired once its
 * expiry passes (expiry is also enforced at read/accept time, not only here).
 */
enum WorkspaceInvitationStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Revoked = 'revoked';
    case Expired = 'expired';
}
