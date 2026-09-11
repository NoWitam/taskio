<?php

namespace App\Modules\Publishing\Console\Concerns;

use App\Modules\Workspaces\Enums\WorkspaceDbMode;
use App\Modules\Workspaces\Enums\WorkspaceStatus;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Services\TenantManager;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ONE PASS ON EVERY DATABASE THIS INSTALLATION HAS — the shape every scheduled sweep in the product
 * already uses, written once here because B3 added a second and a third of them to this module.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHY IT IS TWO KINDS OF PASS AND NOT A LOOP OVER WORKSPACES
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * With NO workspace active, `WorkspaceScope` leaves a query unconstrained — so one unscoped pass covers
 * every shared-database workspace at once, rather than one query per workspace. Own-database workspaces
 * cannot be reached that way at all: their rows live in a connection that has to be configured first,
 * so each gets a pass of its own. Only READY ones have a database to visit; `connectionConfig()`
 * refuses to describe a tenant whose provisioning has not finished.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE TWO DETAILS THAT MAKE IT SURVIVE CONTACT WITH REALITY
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * A BROKEN TENANT DOES NOT STOP THE SWEEP. An unreachable database or a bad connection config is logged
 * and the loop continues — every workspace behind it still has publications coming due.
 *
 * THE CONTEXT IS CLEARED IN A `finally`, not at the end of the happy path. The inner catch covers a
 * tenant that breaks during ITS OWN pass; it does not cover the shared pass or the workspace listing,
 * and an exception from either used to leave the LAST configured tenant connection live on the process.
 * Under a queue worker or a long-lived runtime that is inherited by whatever runs next, which is the
 * quietest possible way for one workspace's data to be written into another's database.
 * `RefreshPlatformTokensCommand` learned this first; it is written here so the next sweep inherits it.
 *
 * The per-tenant log carries the workspace id and the exception CLASS, never the message: a query
 * exception's message embeds its bindings, and in this module those can be ciphertext columns.
 *
 * ALL THREE OF THIS MODULE'S SWEEPS USE IT — the due sweep, the reconciliation sweep and the token
 * refresh, which wrote the loop out by hand first and donated its lessons to this file.
 *
 * @mixin \Illuminate\Console\Command
 *
 * The mixin is not decoration: the per-tenant log below names the command through `$this->getName()`,
 * which is a method of the host class rather than of this trait. Without it, static analysis reports an
 * undefined method on a line that works perfectly — and, more usefully, the annotation is the trait
 * stating its one requirement of whoever uses it.
 */
trait SweepsEveryWorkspace
{
    /**
     * Run `$pass` once against the shared database, then once against each own-database workspace.
     *
     * @param  Closure(): void  $pass  runs against whatever connection is configured when it is called
     */
    protected function acrossEveryWorkspace(TenantContext $context, TenantManager $tenants, Closure $pass): void
    {
        try {
            $context->clear();
            $tenants->forget();

            $pass();

            $ownWorkspaces = Workspace::query()
                ->where('db_mode', WorkspaceDbMode::Own)
                ->where('status', WorkspaceStatus::Ready)
                ->get();

            foreach ($ownWorkspaces as $workspace) {
                try {
                    $context->set($workspace);
                    $tenants->configure($workspace);

                    $pass();
                } catch (Throwable $e) {
                    Log::error('A publishing sweep failed for one workspace; continuing with the rest.', [
                        'command' => $this->getName(),
                        'workspace_id' => $workspace->id,
                        'exception' => $e::class,
                        'code' => $e->getCode(),
                    ]);
                }
            }
        } finally {
            $context->clear();
            $tenants->forget();
        }
    }
}
