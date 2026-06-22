<?php

namespace App\Tenancy;

use App\Modules\Workspaces\Enums\WorkspaceDbMode;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Services\TenantManager;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Queue;

/**
 * GLOBAL tenant propagation across the queue boundary.
 *
 * Any job dispatched while a workspace is active carries that workspace id in its
 * payload, and the worker re-applies the workspace context (+ own-DB connection)
 * before the job runs — so jobs that create tenant-aware models (e.g. a report's
 * generated file) stamp the correct workspace_id even though they run outside the
 * HTTP request. No per-job opt-in is needed.
 *
 * The active context is saved/restored around each job (a stack), so a job
 * dispatched on the SYNC queue (in-process, inside a request) never clears the
 * request's own context, and a long-running worker never leaks context between
 * jobs.
 */
class QueueTenancy
{
    /** Payload key carrying the dispatching workspace id. */
    private const KEY = 'tenantWorkspaceId';

    /** Saved context ids, innermost last (handles nested/sync dispatches). */
    private static array $stack = [];

    public static function register(): void
    {
        // Stamp the active workspace onto every job dispatched while one is set.
        Queue::createPayloadUsing(function (): array {
            $id = app(TenantContext::class)->id();

            return $id !== null ? [self::KEY => $id] : [];
        });

        // Before a job runs: remember the current context, then switch to the
        // job's workspace.
        Queue::before(function (JobProcessing $event): void {
            self::$stack[] = app(TenantContext::class)->id();
            self::activate($event->job->payload()[self::KEY] ?? null);
        });

        // After it finishes (success or failure): restore the previous context.
        Queue::after(fn (JobProcessed $event) => self::restore());
        app('events')->listen(JobExceptionOccurred::class, fn () => self::restore());
    }

    /** Apply (or clear) the workspace context for a given id. */
    private static function activate(?string $id): void
    {
        $context = app(TenantContext::class);
        $manager = app(TenantManager::class);

        $workspace = $id !== null ? Workspace::find($id) : null;

        if ($workspace === null) {
            $context->clear();
            $manager->forget();

            return;
        }

        $context->set($workspace);

        if ($workspace->db_mode === WorkspaceDbMode::Own) {
            $manager->configure($workspace);
        }
    }

    /** Restore the context captured before the current job. */
    private static function restore(): void
    {
        self::activate(array_pop(self::$stack) ?: null);
    }
}
