<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Mockery;
use Tests\TestCase;

/**
 * The GLOBAL queue tenancy hook (App\Tenancy\QueueTenancy, registered in
 * AppServiceProvider) must re-apply the dispatching workspace to ANY job — no
 * per-job opt-in — and never leak / clobber the surrounding context.
 */
class QueueTenancyTest extends TestCase
{
    use RefreshDatabase;

    private function jobWith(array $payload): JobContract
    {
        $job = Mockery::mock(JobContract::class);
        $job->shouldReceive('payload')->andReturn($payload);

        return $job;
    }

    public function test_worker_applies_workspace_from_payload_then_clears_it(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $context = app(TenantContext::class);

        // A worker starts with no context.
        $context->clear();
        $job = $this->jobWith(['tenantWorkspaceId' => $workspace->id]);

        event(new JobProcessing('database', $job));
        $this->assertSame($workspace->id, $context->id());

        event(new JobProcessed('database', $job));
        $this->assertNull($context->id());
    }

    public function test_sync_job_preserves_the_surrounding_request_context(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $context = app(TenantContext::class);

        // In-request (sync) dispatch: the context is already set.
        $context->set($workspace);
        $job = $this->jobWith(['tenantWorkspaceId' => $workspace->id]);

        event(new JobProcessing('sync', $job));
        $this->assertSame($workspace->id, $context->id());

        // After the sync job, the request's context must still be intact.
        event(new JobProcessed('sync', $job));
        $this->assertSame($workspace->id, $context->id());

        $context->clear();
    }
}
