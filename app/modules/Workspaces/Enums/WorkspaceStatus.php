<?php

namespace App\Modules\Workspaces\Enums;

/**
 * Lifecycle of a workspace. Shared workspaces are created Ready synchronously;
 * own-database workspaces start Provisioning and a job flips them to Ready or
 * Failed once the dedicated database has been created and migrated.
 */
enum WorkspaceStatus: string
{
    case Provisioning = 'provisioning';
    case Ready = 'ready';
    case Failed = 'failed';
}
