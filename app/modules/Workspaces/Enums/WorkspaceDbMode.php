<?php

namespace App\Modules\Workspaces\Enums;

enum WorkspaceDbMode: string
{
    case Shared = 'shared';
    case Own = 'own';
}
