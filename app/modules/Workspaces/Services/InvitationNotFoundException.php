<?php

namespace App\Modules\Workspaces\Services;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Thrown when a public invitation token resolves to no row. Renders as a plain
 * 404 (never 401), so the SPA's accept page handles it inline rather than the
 * api client's 401-redirect interceptor firing.
 */
class InvitationNotFoundException extends NotFoundHttpException
{
    public function __construct()
    {
        parent::__construct('Invitation not found.');
    }
}
