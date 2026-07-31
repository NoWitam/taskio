<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Generator\Enums\GenerationSessionStatus;
use App\Modules\Generator\Models\GenerationSession;
use App\Modules\Generator\Services\GeneratedImageStore;
use App\Modules\Generator\Services\GenerationSessionLifecycleService;
use App\Modules\Generator\Services\SessionIdentityImageStore;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Services\TenantManager;
use App\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * The lifecycle REAPER of generation SESSIONS (R2 sub-stage 2d) — stale-`generating` recovery, retention
 * (trash → purge), produced-image blob GC, and the archive/unarchive endpoints. Archive is a blanket FREEZE:
 * an archived session is exempt from EVERY reaper step. Setup mirrors GenerationSessionGenerateTest (a real
 * workspace + active tenancy) so WorkspaceScope + the blob store namespace resolve exactly as in production.
 */
class GenerationSessionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake();

        $this->user = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $this->workspace->users()->attach($this->user->id);

        $this->actingAs($this->user)->withHeader('X-Workspace-Id', $this->workspace->id);
        app(TenantContext::class)->set($this->workspace);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    // ---- helpers ---------------------------------------------------------------

    private function lifecycle(): GenerationSessionLifecycleService
    {
        return app(GenerationSessionLifecycleService::class);
    }

    /** Rewrite a row's timestamps directly (query builder → no model timestamp/soft-delete interference). */
    private function ageTimestamps(GenerationSession $session, array $timestamps): void
    {
        GenerationSession::withoutGlobalScopes()->whereKey($session->id)->update($timestamps);
    }

    /** A trashed session whose trash (deleted_at) is aged into the past. */
    private function trashedSince(CarbonInterface $deletedAt, array $attributes = []): GenerationSession
    {
        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Ready)
            ->create(['creator_id' => $this->user->id] + $attributes);

        $session->delete();
        $this->ageTimestamps($session, ['deleted_at' => $deletedAt]);

        return $session;
    }

    // ---- stale-`generating` recovery -------------------------------------------

    public function test_reap_stale_fails_a_generating_session_past_the_stale_window(): void
    {
        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Generating)
            ->create(['creator_id' => $this->user->id]);
        $this->ageTimestamps($session, ['updated_at' => now()->subMinutes(40)]); // > 1800s default

        $this->assertSame(1, $this->lifecycle()->reapStale());
        $this->assertSame(GenerationSessionStatus::Failed, $session->fresh()->status);
    }

    public function test_reap_stale_leaves_a_recent_generating_session_untouched(): void
    {
        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Generating)
            ->create(['creator_id' => $this->user->id]);
        $this->ageTimestamps($session, ['updated_at' => now()->subMinutes(2)]); // well inside the window

        $this->assertSame(0, $this->lifecycle()->reapStale());
        $this->assertSame(GenerationSessionStatus::Generating, $session->fresh()->status);
    }

    public function test_reap_stale_exempts_an_archived_generating_session(): void
    {
        // Archive is a blanket freeze — even a long-stranded generating archived session is never auto-failed.
        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Generating)
            ->create(['creator_id' => $this->user->id, 'archived_at' => now()]);
        $this->ageTimestamps($session, ['updated_at' => now()->subMinutes(40)]);

        $this->assertSame(0, $this->lifecycle()->reapStale());
        $this->assertSame(GenerationSessionStatus::Generating, $session->fresh()->status);
    }

    // ---- trash (retention) -----------------------------------------------------

    public function test_trash_soft_deletes_a_non_archived_session_past_the_trash_window(): void
    {
        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Ready)
            ->create(['creator_id' => $this->user->id]);
        $this->ageTimestamps($session, ['updated_at' => now()->subWeeks(2)]); // > 604800s default

        $this->assertSame(1, $this->lifecycle()->trashStale());
        $this->assertSoftDeleted('generation_sessions', ['id' => $session->id]);
    }

    public function test_trash_leaves_a_recently_touched_session_untouched(): void
    {
        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Ready)
            ->create(['creator_id' => $this->user->id]);
        $this->ageTimestamps($session, ['updated_at' => now()->subDay()]); // inside the week window

        $this->assertSame(0, $this->lifecycle()->trashStale());
        $this->assertNotSoftDeleted('generation_sessions', ['id' => $session->id]);
    }

    public function test_trash_exempts_an_archived_session(): void
    {
        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Ready)
            ->create(['creator_id' => $this->user->id, 'archived_at' => now()]);
        $this->ageTimestamps($session, ['updated_at' => now()->subWeeks(2)]);

        $this->assertSame(0, $this->lifecycle()->trashStale());
        $this->assertNotSoftDeleted('generation_sessions', ['id' => $session->id]);
    }

    // ---- purge (retention + blob GC) -------------------------------------------

    public function test_purge_force_deletes_a_trashed_session_and_gcs_its_blobs(): void
    {
        $session = $this->trashedSince(now()->subMonths(2)); // > 2592000s default

        // A produced-image blob under the session's own prefix (stored with the active tenant, as a run would).
        $store = app(GeneratedImageStore::class);
        $store->storeVersion($session->id, 'image', 'PNGBYTES');
        $blob = $store->path($session->id, 'image', 1);
        Storage::assertExists($blob);

        // Run the REAL scheduled command: it sweeps the shared DB with the tenant context CLEARED, so the
        // purge must derive the blob prefix from the ROW's workspace_id — this is the load-bearing GC path.
        $this->artisan('generator:reap-sessions')->assertSuccessful();

        $this->assertSame(0, GenerationSession::withTrashed()->whereKey($session->id)->count(), 'the row must be force-deleted');
        Storage::assertMissing($blob);
    }

    public function test_purge_leaves_a_recently_trashed_session_untouched(): void
    {
        $session = $this->trashedSince(now()->subDays(3)); // inside the month window

        $this->assertSame(0, $this->lifecycle()->purgeTrashed());
        $this->assertSame(1, GenerationSession::withTrashed()->whereKey($session->id)->count());
    }

    public function test_purge_exempts_an_archived_then_trashed_session(): void
    {
        // A user archived a session, then trashed it: archive still exempts it from purge (its blobs stay).
        $session = $this->trashedSince(now()->subMonths(2), ['archived_at' => now()]);

        $this->assertSame(0, $this->lifecycle()->purgeTrashed());
        $this->assertSame(1, GenerationSession::withTrashed()->whereKey($session->id)->count());
    }

    public function test_an_archived_then_trashed_session_still_gives_up_its_frozen_likeness(): void
    {
        // The one thing the archive freeze must NOT hold on to forever: a delegated session froze a COPY of
        // a real person's face. Once the row has been in the trash past the purge window it is unreachable
        // (there is no restore), so every further day of keeping that copy is retention nobody asked for.
        // The row itself still stays — archive means archive.
        $session = $this->trashedSince(now()->subMonths(2), ['archived_at' => now()]);

        $identity = app(SessionIdentityImageStore::class);
        $identity->put($session->id, 'bot-1', 'FROZEN-FACE');
        $likeness = $identity->path($session->id, 'bot-1');
        Storage::assertExists($likeness);

        // The REAL scheduled command: the shared-DB pass runs with the tenant context cleared, so the prefix
        // has to be derived from the ROW, exactly like the produced-image GC beside it.
        $this->artisan('generator:reap-sessions')->assertSuccessful();

        Storage::assertMissing($likeness);
        $this->assertSame(1, GenerationSession::withTrashed()->whereKey($session->id)->count(), 'the archived row itself is still exempt');
    }

    public function test_a_recently_trashed_archived_session_keeps_its_frozen_likeness(): void
    {
        $session = $this->trashedSince(now()->subDays(3), ['archived_at' => now()]);

        $identity = app(SessionIdentityImageStore::class);
        $identity->put($session->id, 'bot-1', 'FROZEN-FACE');
        // Resolved while the tenant is still active — the sweep clears the context.
        $likeness = $identity->path($session->id, 'bot-1');

        $this->artisan('generator:reap-sessions')->assertSuccessful();

        Storage::assertExists($likeness);
    }

    // ---- archive / unarchive endpoints -----------------------------------------

    public function test_creator_can_archive_and_unarchive_a_session(): void
    {
        $session = GenerationSession::factory()->create(['creator_id' => $this->user->id]);

        $this->postJson("/api/generator/sessions/{$session->id}/archive")
            ->assertOk()
            ->assertJsonPath('data.is_archived', true)
            ->assertJsonPath('data.can_archive', true);
        $this->assertNotNull($session->fresh()->archived_at);

        $this->postJson("/api/generator/sessions/{$session->id}/unarchive")
            ->assertOk()
            ->assertJsonPath('data.is_archived', false);
        $this->assertNull($session->fresh()->archived_at);
    }

    public function test_a_foreign_workspace_session_is_404_on_archive(): void
    {
        $other = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $other->users()->attach($this->user->id);
        $foreign = GenerationSession::factory()->create(['creator_id' => $this->user->id, 'workspace_id' => $other->id]);

        // The active workspace is $this->workspace; the foreign row is scoped out → 404 at bind.
        $this->postJson("/api/generator/sessions/{$foreign->id}/archive")->assertNotFound();
    }

    public function test_a_non_creator_cannot_archive(): void
    {
        $stranger = User::factory()->create();
        $this->workspace->users()->attach($stranger->id);
        $session = GenerationSession::factory()->create(['creator_id' => $this->user->id]);

        $this->actingAs($stranger)->withHeader('X-Workspace-Id', $this->workspace->id)
            ->postJson("/api/generator/sessions/{$session->id}/archive")
            ->assertForbidden();
    }

    public function test_an_archived_session_survives_the_sweep(): void
    {
        // Archived AND long-idle: the sweep must neither trash nor fail it (blanket freeze).
        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Ready)
            ->create(['creator_id' => $this->user->id, 'archived_at' => now()]);
        $this->ageTimestamps($session, ['updated_at' => now()->subWeeks(6)]);

        $this->artisan('generator:reap-sessions')->assertSuccessful();

        $this->assertNotSoftDeleted('generation_sessions', ['id' => $session->id]);
        $this->assertSame(GenerationSessionStatus::Ready, $session->fresh()->status);
    }

    // ---- per-tenant hardening --------------------------------------------------

    public function test_the_sweep_continues_past_a_failing_tenant(): void
    {
        // Mirrors SweepTenantIsolationTest: one own-DB tenant throwing during the sweep must be logged and
        // skipped, never abort the pass for the shared DB or the tenants behind it.
        Log::spy();
        app(TenantContext::class)->clear();

        $broken = Workspace::factory()->create(['db_mode' => 'own']);
        $healthy = Workspace::factory()->create(['db_mode' => 'own']);

        $tenants = $this->mock(TenantManager::class);
        $tenants->shouldReceive('forget');
        $tenants->shouldReceive('configure')
            ->with(Mockery::on(fn (Workspace $ws) => $ws->is($broken)))
            ->andThrow(new RuntimeException('tenant database unreachable'));
        $tenants->shouldReceive('configure')
            ->with(Mockery::on(fn (Workspace $ws) => $ws->is($healthy)))
            ->once();

        // Shared pass + the healthy tenant each run every step; the broken tenant throws at configure
        // before any step, so each is called exactly twice.
        $lifecycle = $this->mock(GenerationSessionLifecycleService::class);
        $lifecycle->shouldReceive('reapStaleFrames')->twice()->andReturn(0);
        $lifecycle->shouldReceive('reapStale')->twice()->andReturn(0);
        $lifecycle->shouldReceive('trashStale')->twice()->andReturn(0);
        $lifecycle->shouldReceive('purgeTrashed')->twice()->andReturn(0);
        $lifecycle->shouldReceive('purgeArchivedIdentityImages')->twice()->andReturn(0);

        $this->artisan('generator:reap-sessions')->assertSuccessful();

        Log::shouldHaveReceived('error')->atLeast()->once()->with(
            'Generation session reaper failed for workspace; continuing with remaining workspaces.',
            Mockery::on(fn (array $context) => $context['workspace_id'] === $broken->id
                && $context['error'] === 'tenant database unreachable'),
        );
    }
}
