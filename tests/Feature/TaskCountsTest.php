<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The per-status board counts endpoint (GET /api/tasks/counts) and the cached
 * read-layer it introduces (TasksRepository + TaskObserver invalidation).
 */
class TaskCountsTest extends TestCase
{
    use RefreshDatabase;

    private function task(User $user, TaskStatus $status): Task
    {
        return Task::factory()->create([
            'status' => $status,
            'creator_id' => $user->id,
            'assignee_type' => 'user',
            'assignee_id' => $user->id,
        ]);
    }

    public function test_counts_requires_authentication(): void
    {
        $this->getJson('/api/tasks/counts')->assertUnauthorized();
    }

    public function test_counts_returns_every_status_with_zeros_filled(): void
    {
        $user = User::factory()->create();
        $this->task($user, TaskStatus::TO_DO);
        $this->task($user, TaskStatus::TO_DO);
        $this->task($user, TaskStatus::IN_PROGRESS);
        $this->task($user, TaskStatus::DONE);

        $this->actingAs($user)
            ->getJson('/api/tasks/counts')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'counts' => ['to_do', 'in_progress', 'in_test', 'done', 'archive', 'trash'],
                    'total',
                ],
            ])
            ->assertJsonPath('data.counts.to_do', 2)
            ->assertJsonPath('data.counts.in_progress', 1)
            ->assertJsonPath('data.counts.in_test', 0) // zero explicitly filled
            ->assertJsonPath('data.counts.done', 1)
            ->assertJsonPath('data.counts.archive', 0)
            ->assertJsonPath('data.counts.trash', 0)
            // total = the four active board columns.
            ->assertJsonPath('data.total', 4);
    }

    public function test_counts_include_trash_bucket_and_exclude_it_from_active_and_total(): void
    {
        $user = User::factory()->create();
        $this->task($user, TaskStatus::TO_DO);

        // Trash exactly as TaskService::delete does: status=trash then soft-delete.
        $trashed = $this->task($user, TaskStatus::TO_DO);
        $trashed->update(['status' => TaskStatus::TRASH]);
        $trashed->delete();

        $this->actingAs($user)
            ->getJson('/api/tasks/counts')
            ->assertOk()
            ->assertJsonPath('data.counts.to_do', 1) // trashed row excluded from active
            ->assertJsonPath('data.counts.trash', 1)
            ->assertJsonPath('data.total', 1); // total excludes the trash bucket
    }

    public function test_counts_respect_index_filters(): void
    {
        $user = User::factory()->create();
        $alpha = $this->task($user, TaskStatus::TO_DO);
        $alpha->update(['title' => 'Alpha report']);
        $beta = $this->task($user, TaskStatus::TO_DO);
        $beta->update(['title' => 'Beta report']);

        // Filtered: only "Alpha" matches the search.
        $this->actingAs($user)
            ->getJson('/api/tasks/counts?search=Alpha')
            ->assertOk()
            ->assertJsonPath('data.counts.to_do', 1)
            ->assertJsonPath('data.total', 1);

        // Unfiltered afterwards sees both — proving the filtered call neither read
        // nor poisoned the unfiltered cache entry.
        $this->actingAs($user)
            ->getJson('/api/tasks/counts')
            ->assertOk()
            ->assertJsonPath('data.counts.to_do', 2)
            ->assertJsonPath('data.total', 2);
    }

    public function test_counts_are_scoped_to_the_active_workspace(): void
    {
        $user = User::factory()->create();

        $workspaceA = Workspace::factory()->create(['owner_id' => $user->id]);
        $workspaceA->users()->attach($user->id);
        $workspaceB = Workspace::factory()->create(['owner_id' => $user->id]);
        $workspaceB->users()->attach($user->id);

        // Stamp tasks into each workspace via the active tenant context (the
        // TenantAware `creating` hook stamps workspace_id in shared mode).
        $tenant = app(TenantContext::class);

        $tenant->set($workspaceA);
        $this->task($user, TaskStatus::TO_DO);
        $this->task($user, TaskStatus::TO_DO);

        $tenant->set($workspaceB);
        $this->task($user, TaskStatus::TO_DO);

        $tenant->clear();

        // Workspace A: only its two tasks. Also primes the per-workspace cache key.
        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspaceA->id)
            ->getJson('/api/tasks/counts')
            ->assertOk()
            ->assertJsonPath('data.counts.to_do', 2)
            ->assertJsonPath('data.total', 2);

        // Workspace B: only its one task. A distinct cache key means no bleed from A.
        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspaceB->id)
            ->getJson('/api/tasks/counts')
            ->assertOk()
            ->assertJsonPath('data.counts.to_do', 1)
            ->assertJsonPath('data.total', 1);
    }

    public function test_unfiltered_counts_are_cached(): void
    {
        $user = User::factory()->create();
        $this->task($user, TaskStatus::TO_DO);

        // Prime the cache.
        $this->actingAs($user)
            ->getJson('/api/tasks/counts')
            ->assertOk()
            ->assertJsonPath('data.counts.to_do', 1);

        // A second identical (unfiltered) call must be served from cache: it may not
        // touch the tasks table at all.
        DB::enableQueryLog();
        $this->actingAs($user)
            ->getJson('/api/tasks/counts')
            ->assertOk()
            ->assertJsonPath('data.counts.to_do', 1);

        $tasksQueries = collect(DB::getQueryLog())
            ->filter(fn (array $q) => str_contains($q['query'], '"tasks"'));

        $this->assertCount(0, $tasksQueries, 'Cached unfiltered counts must not re-query the tasks table.');
    }

    public function test_filtered_counts_are_not_cached(): void
    {
        $user = User::factory()->create();
        $task = $this->task($user, TaskStatus::TO_DO);
        $task->update(['title' => 'Alpha']);

        // Prime (if it were cacheable, the second call would skip the DB).
        $this->actingAs($user)->getJson('/api/tasks/counts?search=Alpha')->assertOk();

        DB::enableQueryLog();
        $this->actingAs($user)
            ->getJson('/api/tasks/counts?search=Alpha')
            ->assertOk()
            ->assertJsonPath('data.counts.to_do', 1);

        $tasksQueries = collect(DB::getQueryLog())
            ->filter(fn (array $q) => str_contains($q['query'], '"tasks"'));

        $this->assertTrue($tasksQueries->isNotEmpty(), 'Filtered counts must compute live, never from cache.');
    }

    public function test_counts_are_invalidated_when_a_task_is_created(): void
    {
        $user = User::factory()->create();
        $this->task($user, TaskStatus::TO_DO);

        // Prime the cache.
        $this->actingAs($user)
            ->getJson('/api/tasks/counts')
            ->assertJsonPath('data.counts.to_do', 1);

        // A new task through the model fires the "created" observer → cache dropped.
        $this->task($user, TaskStatus::TO_DO);

        $this->actingAs($user)
            ->getJson('/api/tasks/counts')
            ->assertJsonPath('data.counts.to_do', 2)
            ->assertJsonPath('data.total', 2);
    }

    public function test_counts_are_invalidated_on_a_status_change(): void
    {
        $user = User::factory()->create();
        $task = $this->task($user, TaskStatus::TO_DO);

        // Prime the cache.
        $this->actingAs($user)
            ->getJson('/api/tasks/counts')
            ->assertJsonPath('data.counts.to_do', 1)
            ->assertJsonPath('data.counts.in_progress', 0);

        // A status change through the model fires the "updated" observer → cache dropped.
        $task->update(['status' => TaskStatus::IN_PROGRESS]);

        $this->actingAs($user)
            ->getJson('/api/tasks/counts')
            ->assertJsonPath('data.counts.to_do', 0)
            ->assertJsonPath('data.counts.in_progress', 1);
    }
}
