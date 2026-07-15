<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Bot\Services\BotTaskRunManager;
use App\Modules\Workflows\Enums\WorkflowStepType;
use App\Modules\Workflows\Enums\WorkflowTriggerType;
use App\Modules\Workflows\Models\Workflow;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Modules\Workflows\Services\WorkflowRunManager;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\Concerns\FakesBotExecutionAgent;
use Tests\TestCase;

/**
 * Sweep hardening: the three scheduled sweeps (bots:reap-stale-runs,
 * workflows:reap-stale-runs, workflows:run-scheduled) iterate every own-database
 * workspace — and workflows:run-scheduled additionally iterates every due workflow.
 * One broken tenant or one corrupt workflow must be logged and skipped, never abort
 * the pass for everything behind it.
 */
class SweepTenantIsolationTest extends TestCase
{
    use FakesBotExecutionAgent, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'workflows.max_depth' => 3,
            'workflows.max_runs_per_month' => 1000,
            'workflows.max_runs_hard_cap' => 1000,
        ]);
    }

    /** @return array{0: Workspace, 1: Workspace} the failing tenant first (loop order = id order) */
    private function twoOwnWorkspaces(): array
    {
        return [
            Workspace::factory()->create(['db_mode' => 'own']),
            Workspace::factory()->create(['db_mode' => 'own']),
        ];
    }

    /**
     * TenantManager whose configure() throws for $broken; expectations for other
     * workspaces are added per test (Mockery matches expectations in order).
     */
    private function tenantManagerBrokenFor(Workspace $broken): Mockery\MockInterface
    {
        $manager = $this->mock(TenantManager::class);
        $manager->shouldReceive('forget');
        $manager->shouldReceive('configure')
            ->with(Mockery::on(fn (Workspace $ws) => $ws->is($broken)))
            ->andThrow(new RuntimeException('tenant database unreachable'));

        return $manager;
    }

    private function assertLoggedWorkspaceFailure(string $message, Workspace $broken): void
    {
        // At-least-once: in the scheduled sweep the HEALTHY tenant also logs an error in
        // the test env (no real tenant DB behind the mocked configure()) — that extra call
        // is itself proof the loop continued, not a failure of this assertion.
        Log::shouldHaveReceived('error')->atLeast()->once()->with(
            $message,
            Mockery::on(fn (array $context) => $context['workspace_id'] === $broken->id
                && $context['error'] === 'tenant database unreachable'),
        );
    }

    public function test_bot_reaper_continues_past_a_failing_tenant(): void
    {
        Log::spy();
        [$broken, $healthy] = $this->twoOwnWorkspaces();
        $this->tenantManagerBrokenFor($broken)
            ->shouldReceive('configure')
            ->with(Mockery::on(fn (Workspace $ws) => $ws->is($healthy)))
            ->once();

        // Shared pass + the healthy tenant: the broken tenant costs exactly one pass.
        $this->mock(BotTaskRunManager::class)
            ->shouldReceive('reapStaleRuns')->twice()->andReturn(0);

        $this->artisan('bots:reap-stale-runs')->assertSuccessful();

        $this->assertLoggedWorkspaceFailure(
            'Stale bot run reaper failed for workspace; continuing with remaining workspaces.',
            $broken,
        );
    }

    public function test_workflow_reaper_continues_past_a_failing_tenant(): void
    {
        Log::spy();
        [$broken, $healthy] = $this->twoOwnWorkspaces();
        $this->tenantManagerBrokenFor($broken)
            ->shouldReceive('configure')
            ->with(Mockery::on(fn (Workspace $ws) => $ws->is($healthy)))
            ->once();

        $this->mock(WorkflowRunManager::class)
            ->shouldReceive('reapStaleRuns')->twice()->andReturn(0);

        $this->artisan('workflows:reap-stale-runs')->assertSuccessful();

        $this->assertLoggedWorkspaceFailure(
            'Stale workflow run reaper failed for workspace; continuing with remaining workspaces.',
            $broken,
        );
    }

    public function test_scheduled_sweep_continues_past_a_failing_tenant(): void
    {
        Log::spy();
        [$broken, $healthy] = $this->twoOwnWorkspaces();

        // Reaching the healthy tenant's configure() proves the loop survived the throw.
        $this->tenantManagerBrokenFor($broken)
            ->shouldReceive('configure')
            ->with(Mockery::on(fn (Workspace $ws) => $ws->is($healthy)))
            ->once();

        $this->artisan('workflows:run-scheduled')->assertSuccessful();

        $this->assertLoggedWorkspaceFailure(
            'Scheduled sweep failed for workspace; continuing with remaining workspaces.',
            $broken,
        );
    }

    public function test_scheduled_sweep_continues_past_a_workflow_that_fails_to_fire(): void
    {
        Log::spy();
        $owner = User::factory()->create();
        $this->actingAs($owner);

        // Due FIRST (earlier next_due_at): the stored 99:99 time survives persistence but
        // cannot compile, so claiming it throws mid-sweep. The later valid one must still fire.
        $corrupt = Workflow::factory()->active()->create([
            'creator_id' => $owner->id,
            'trigger_type' => WorkflowTriggerType::SCHEDULE->value,
            'trigger_config' => ['schedule' => ['time' => ['mode' => 'at', 'at' => ['99:99']]]],
            'next_due_at' => now()->subMinutes(10),
        ]);
        $healthy = Workflow::factory()->active()->create([
            'creator_id' => $owner->id,
            'trigger_type' => WorkflowTriggerType::SCHEDULE->value,
            'trigger_config' => ['schedule' => ['time' => ['mode' => 'at', 'at' => ['09:00']]]],
            'next_due_at' => now()->subMinute(),
            'steps' => [
                ['type' => WorkflowStepType::CREATE_TASK->value, 'key' => 'noop', 'config' => ['title' => 'Scheduled task']],
            ],
        ]);

        $this->artisan('workflows:run-scheduled')->assertSuccessful();

        $this->assertSame(1, WorkflowRun::query()->where('workflow_id', $healthy->id)->count());
        $this->assertSame(0, WorkflowRun::query()->where('workflow_id', $corrupt->id)->count());
        Log::shouldHaveReceived('error')->once()->with(
            'Scheduled sweep failed for workflow; continuing with remaining due workflows.',
            Mockery::on(fn (array $context) => $context['workflow_id'] === $corrupt->id),
        );
    }
}
