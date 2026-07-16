<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\InteractsWithTenantContext;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantJobContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_captures_and_restores_the_active_workspace(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);

        $context = app(TenantContext::class);
        $context->set($workspace);

        // Capture at "dispatch" time.
        $job = new class
        {
            use InteractsWithTenantContext;
        };
        $job->rememberTenant();

        // The queue worker starts with no context.
        $context->clear();
        $this->assertNull($context->id());

        // Middleware re-applies it before handle().
        $job->restoreTenant();
        $this->assertSame($workspace->id, $context->id());

        $context->clear();
    }
}
