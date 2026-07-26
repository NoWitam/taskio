<?php

namespace App\Modules\Disk\Jobs;

use App\Modules\Disk\Services\ImageAiService;
use App\Modules\Workspaces\Enums\WorkspaceDbMode;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Services\TenantManager;
use App\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One ASYNC Disk AI image edit (F2-2). The provider call is slow (tens of seconds), so it runs off
 * the request. The status row + persisted inputs already exist ({@see ImageAiService::dispatch});
 * this job just processes them and records the outcome. A transient provider failure is retried
 * with backoff (mirrors ProcessAiApprovalJob); a worker killed mid-run is recovered by the
 * `disk:reap-stale-ai-edits` reaper (a SIGKILL/OOM never reaches failed()).
 *
 * Tenancy: QueueTenancy already re-applies the dispatching workspace on the worker, but this job
 * ALSO re-establishes it explicitly from its own stored workspace id. Both because the DiskAiEdit
 * (and its own-database routing) is tenant-scoped and the prior tenancy-leak fix in this area
 * makes an explicit, security-critical property preferable to an implicit one; and because
 * failed() can run after the queue listener has already restored the previous context.
 */
class EditDiskImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    // The provider HTTP call may run up to ai.image_timeout (120s), so the job's own alarm must
    // EXCEED it — the default worker --timeout (60s) would SIGALRM-kill a slow-but-alive edit, and
    // the queue's retry_after should be raised above this too (document the invariant
    // retry_after > $timeout > ai.image_timeout). WithoutOverlapping below makes a duplicate
    // delivery safe even if retry_after is left below the runtime.
    public int $timeout = 150;

    public function __construct(
        public string $editId,
        public string $workspaceId,
    ) {}

    /**
     * One edit runs at a time. At-least-once delivery (a visibility-timeout re-reservation when the
     * run outlives retry_after) must never double-call the BILLED provider or clobber the result, so
     * a duplicate is released back rather than run. The lock is keyed on the edit and self-expires
     * well past the job timeout so a killed worker never wedges it.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->editId))->releaseAfter(30)->expireAfter(300)];
    }

    public function handle(ImageAiService $service): void
    {
        $this->activateTenant();

        try {
            $service->process($this->editId);
        } catch (Throwable $e) {
            // Log the real cause (kept OUT of the row, which only ever shows a localized message),
            // then rethrow so the queue retries and eventually routes to failed().
            Log::info("Disk AI image edit failed for edit {$this->editId}: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * All retries exhausted (or a non-retryable throw): mark the edit failed with a localized,
     * non-secret message and drop its inputs. Restores tenancy first — failed() can run after
     * QueueTenancy has already popped this job's context.
     */
    public function failed(Throwable $e): void
    {
        $this->activateTenant();

        app(ImageAiService::class)->fail($this->editId, __('disk.ai.failed'));
    }

    /**
     * Re-apply the dispatching workspace so the tenant-scoped DiskAiEdit resolves (and, for an
     * own-database workspace, routes to the right connection). Mirrors the inline activation the
     * reaper/prune console commands use. A vanished workspace leaves the context cleared, and the
     * downstream find() simply no-ops.
     */
    private function activateTenant(): void
    {
        $workspace = Workspace::find($this->workspaceId);

        if ($workspace === null) {
            return;
        }

        app(TenantContext::class)->set($workspace);

        if ($workspace->db_mode === WorkspaceDbMode::Own) {
            app(TenantManager::class)->configure($workspace);
        }
    }
}
