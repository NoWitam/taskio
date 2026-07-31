<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Disk\Models\File;
use App\Modules\Disk\Services\FileService;
use App\Modules\Generator\Enums\GenerationRunMode;
use App\Modules\Generator\Enums\GenerationSessionStatus;
use App\Modules\Generator\Enums\SlotScopePolicy;
use App\Modules\Generator\Exceptions\GenerationBudgetExceeded;
use App\Modules\Generator\Jobs\RunGenerationSessionJob;
use App\Modules\Generator\Models\GenerationSession;
use App\Modules\Generator\Models\Template;
use App\Modules\Generator\Services\GeneratedImageExporter;
use App\Modules\Generator\Services\GeneratedImageStore;
use App\Modules\Generator\Services\GenerationSessionRunManager;
use App\Modules\Generator\Services\SessionAutomationService;
use App\Modules\Generator\Services\SessionDelegationService;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * The GENERATOR AUTOMATION SEAM (R2 sub-stage 5, BE-1): the HTTP-free, primitives-only entry points a
 * server-side caller uses to run a template — create-from-template + the wait status
 * ({@see SessionAutomationService}), the SCOPED slot fill ({@see SessionDelegationService::applySlotValues}),
 * the async dispatch's explicit connection, the budget refusal's non-HTTP message, and the extracted
 * durable image export ({@see GeneratedImageExporter}).
 *
 * NO AI is ever exercised here: every run is Queue::fake()d (the gate/claim/dispatch is the unit under
 * test, never the worker), so no provider is contacted.
 *
 * The two highest-risk properties are pinned by what this file does NOT change: the whole
 * BotSessionDelegationTest suite still exercises `applyBotSlotValues()` unchanged (byte-preserved
 * behaviour through the new shared core), and GeneratedImageServeAndSaveTest still exercises the
 * save-to-disk endpoint unchanged (byte-preserved behaviour through the extracted exporter). This file adds
 * only what those cannot cover: the AUTOMATION scope, the explicit connection, and the non-HTTP surfaces.
 */
class GeneratorAutomationSeamTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $this->workspace->users()->attach($this->user->id);

        $this->actingAs($this->user)->withHeader('X-Workspace-Id', $this->workspace->id);
        app(TenantContext::class)->set($this->workspace);

        Storage::fake();
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    private function automation(): SessionAutomationService
    {
        return app(SessionAutomationService::class);
    }

    private function delegation(): SessionDelegationService
    {
        return app(SessionDelegationService::class);
    }

    /** A recipe with one required text slot, one required FILE slot and one deferred composite. */
    private function mixedSlots(): array
    {
        return [
            ['name' => 'topic', 'description' => 'The subject', 'descriptor' => ['base' => 'text', 'nullable' => false, 'array' => false]],
            ['name' => 'attachment', 'description' => 'A file', 'descriptor' => ['base' => 'file', 'nullable' => false, 'fields' => [
                ['key' => 'id', 'descriptor' => ['base' => 'text']],
                ['key' => 'name', 'descriptor' => ['base' => 'text']],
                ['key' => 'type', 'descriptor' => ['base' => 'text']],
                ['key' => 'size', 'descriptor' => ['base' => 'number']],
                ['key' => 'url', 'descriptor' => ['base' => 'text']],
            ]]],
            ['name' => 'rows', 'description' => 'Deferred', 'descriptor' => ['base' => 'object', 'nullable' => true, 'array' => true, 'fields' => [
                ['key' => 'a', 'descriptor' => ['base' => 'text']],
            ]]],
        ];
    }

    private function mixedSession(array $slotValues = []): GenerationSession
    {
        return GenerationSession::factory()
            ->snapshot('post', ['body' => ['markdown' => 'Body']], $this->mixedSlots(), $slotValues)
            ->create(['creator_id' => $this->user->id]);
    }

    private function diskImage(): File
    {
        return File::factory()->image()->inFolder()->create(['uploader_id' => $this->user->id]);
    }

    // ---- createFromTemplate ----------------------------------------------------

    public function test_it_creates_a_session_from_a_live_template_with_the_recipe_snapshotted(): void
    {
        $template = Template::factory()->create(['creator_id' => $this->user->id]);

        $session = $this->automation()->createFromTemplate($template, ['topic' => 'launch']);

        $this->assertSame($template->id, $session->template_id);
        $this->assertSame($template->content_type, $session->content_type);
        $this->assertSame(GenerationSessionStatus::Draft, $session->status);
        $this->assertSame(['topic' => 'launch'], $session->slot_values);
        // `author_voices` is the frozen per-block `@[ai-text]` author map — empty here (this recipe names
        // no author), but always present: the snapshot is the authority for a run's voices too.
        $this->assertSame([
            'content_type' => $template->content_type,
            'slots' => $template->slots,
            'content' => $template->content,
            'author_voices' => [],
        ], $session->recipe_snapshot);
        $this->assertSame($this->workspace->id, $session->workspace_id);
    }

    public function test_a_later_template_edit_never_changes_an_existing_sessions_recipe(): void
    {
        $template = Template::factory()->create(['creator_id' => $this->user->id]);
        $session = $this->automation()->createFromTemplate($template, []);
        $snapshot = $session->recipe_snapshot;

        $template->update([
            'content' => ['body' => ['markdown' => 'COMPLETELY DIFFERENT']],
            'slots' => [['name' => 'other', 'descriptor' => ['base' => 'text', 'nullable' => true, 'array' => false]]],
        ]);

        $this->assertSame($snapshot, $session->fresh()->recipe_snapshot);
    }

    public function test_the_creator_is_stamped_ambiently_and_never_set_by_the_service(): void
    {
        // The service passes NO creator: HasCreator stamps it from the AMBIENT context at insert time,
        // which is exactly what makes an automated run attribute correctly later.
        $template = Template::factory()->create(['creator_id' => $this->user->id]);

        $other = User::factory()->create();
        $this->workspace->users()->attach($other->id);
        $this->actingAs($other);

        $session = $this->automation()->createFromTemplate($template, []);

        $this->assertSame($other->id, $session->creator_id);
        $this->assertSame('user', $session->creator_type);
    }

    public function test_the_name_defaults_to_the_template_name_and_an_explicit_name_wins(): void
    {
        $template = Template::factory()->create(['creator_id' => $this->user->id]);

        $this->assertSame($template->name, $this->automation()->createFromTemplate($template, [])->name);
        $this->assertSame($template->name, $this->automation()->createFromTemplate($template, [], '  ')->name);
        $this->assertSame('Weekly post', $this->automation()->createFromTemplate($template, [], 'Weekly post')->name);
    }

    // ---- terminalStatusFor -----------------------------------------------------

    public function test_it_reports_a_sessions_wait_status_and_null_when_it_is_gone(): void
    {
        $draft = GenerationSession::factory()->create(['creator_id' => $this->user->id]);
        $running = GenerationSession::factory()->status(GenerationSessionStatus::Generating)->create(['creator_id' => $this->user->id]);
        $ready = GenerationSession::factory()->status(GenerationSessionStatus::Ready)->create(['creator_id' => $this->user->id]);
        $failed = GenerationSession::factory()->status(GenerationSessionStatus::Failed)->create(['creator_id' => $this->user->id]);

        $this->assertSame('draft', $this->automation()->terminalStatusFor($draft->id));
        $this->assertSame('generating', $this->automation()->terminalStatusFor($running->id));
        $this->assertSame('ready', $this->automation()->terminalStatusFor($ready->id));
        $this->assertSame('failed', $this->automation()->terminalStatusFor($failed->id));

        $ready->delete();
        $this->assertNull($this->automation()->terminalStatusFor($ready->id));
        $this->assertNull($this->automation()->terminalStatusFor((string) Str::uuid()));
        $this->assertNull($this->automation()->terminalStatusFor('not-a-uuid'));
    }

    public function test_the_wait_status_is_workspace_scoped(): void
    {
        $other = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $other->users()->attach($this->user->id);

        app(TenantContext::class)->set($other);
        $foreign = GenerationSession::factory()->status(GenerationSessionStatus::Ready)->create(['creator_id' => $this->user->id]);
        app(TenantContext::class)->set($this->workspace);

        $this->assertNull($this->automation()->terminalStatusFor($foreign->id));
    }

    // ---- the AUTOMATION slot scope (D4) ----------------------------------------

    public function test_the_automation_scope_fills_a_file_slot_with_the_servers_own_snapshot(): void
    {
        $file = $this->diskImage();
        $session = $this->mixedSession();

        $report = $this->delegation()->applySlotValues($session, ['attachment' => $file->id], SlotScopePolicy::Automation);

        $this->assertContains('attachment', $report['filled']);

        // What is PERSISTED is the SERVER's snapshot of the resolved row — never the caller's map.
        $this->assertSame([
            'id' => $file->id,
            'name' => $file->name,
            'mime_type' => $file->mime_type,
            'size' => $file->size,
            'url' => $file->serveUrl(),
        ], $session->fresh()->slot_values['attachment']);
    }

    public function test_a_file_slot_also_accepts_the_snapshot_map_form_and_ignores_its_other_keys(): void
    {
        $file = $this->diskImage();
        $session = $this->mixedSession();

        $this->delegation()->applySlotValues($session, [
            'attachment' => ['id' => $file->id, 'name' => 'FORGED NAME', 'url' => 'https://evil.example/x'],
        ], SlotScopePolicy::Automation);

        $stored = $session->fresh()->slot_values['attachment'];
        $this->assertSame($file->name, $stored['name']);
        $this->assertSame($file->serveUrl(), $stored['url']);
    }

    public function test_a_foreign_workspace_file_never_resolves_and_is_dropped(): void
    {
        $other = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $other->users()->attach($this->user->id);

        app(TenantContext::class)->set($other);
        $foreignFile = $this->diskImage();
        app(TenantContext::class)->set($this->workspace);

        $session = $this->mixedSession();

        $report = $this->delegation()->applySlotValues($session, ['attachment' => $foreignFile->id], SlotScopePolicy::Automation);

        $this->assertContains(['name' => 'attachment', 'reason' => 'invalid'], $report['skipped']);
        $this->assertArrayNotHasKey('attachment', $session->fresh()->slot_values ?? []);
        $this->assertContains('attachment', $report['unfilled_required']);
    }

    public function test_an_absent_deleted_or_malformed_file_reference_is_dropped_cleanly(): void
    {
        $trashed = $this->diskImage();
        $trashed->delete();

        foreach ([
            'gone' => $trashed->id,
            'unknown' => (string) Str::uuid(),
            'garbage' => 'not-a-uuid; drop table files',
            'wrong_type' => 12345,
        ] as $case => $value) {
            $session = $this->mixedSession();

            $report = $this->delegation()->applySlotValues($session, ['attachment' => $value], SlotScopePolicy::Automation);

            $this->assertContains(['name' => 'attachment', 'reason' => 'invalid'], $report['skipped'], $case);
            $this->assertArrayNotHasKey('attachment', $session->fresh()->slot_values ?? [], $case);
        }
    }

    public function test_a_foreign_workspace_file_never_resolves_with_no_active_tenant_context(): void
    {
        // The hardening case. WorkspaceScope::apply() is a documented NO-OP when no workspace is active
        // (queue jobs, console), so an ambient-scope-only lookup would run UNCONSTRAINED and resolve a
        // FOREIGN file — persisting its name + live serve URL into slot_values. The automation seam is
        // reachable from a queued step whose tenancy a listener may have cleared/restored, so the file
        // resolution must be safe INDEPENDENTLY of the ambient context: it is scoped by the SESSION's
        // own workspace.
        $other = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $other->users()->attach($this->user->id);

        app(TenantContext::class)->set($other);
        $foreignFile = $this->diskImage();
        app(TenantContext::class)->set($this->workspace);

        $session = $this->mixedSession();
        $ownFile = $this->diskImage();

        app(TenantContext::class)->clear();

        $foreign = $this->delegation()->applySlotValues($session, ['attachment' => $foreignFile->id], SlotScopePolicy::Automation);

        $this->assertContains(['name' => 'attachment', 'reason' => 'invalid'], $foreign['skipped']);
        $this->assertArrayNotHasKey('attachment', $session->fresh()->slot_values ?? []);

        // ...and the session's OWN workspace still resolves without an ambient context (the seam stays
        // usable from a worker; it is not simply refusing everything).
        $own = $this->delegation()->applySlotValues($session, ['attachment' => $ownFile->id], SlotScopePolicy::Automation);

        $this->assertContains('attachment', $own['filled']);
        $this->assertSame($ownFile->id, $session->fresh()->slot_values['attachment']['id']);
    }

    public function test_a_file_whose_name_carries_a_nul_byte_is_refused(): void
    {
        // The NON-file path is NUL-guarded by the shared ConstantTypeValidator ("the injection mask
        // assumes resolved values are NUL-free"); the file branch bypasses that authority BY DESIGN
        // (a file is a reference, not a literal), so it must carry its own guard — a NUL in a rendered
        // value collides with the resolver's embedded-directive stash tokens and corrupts a part.
        //
        // The hostile name is injected on RETRIEVAL rather than persisted: the test connection is
        // PostgreSQL, which cannot store a NUL byte in a text column at all. The guard exists for every
        // other way such a value can reach the snapshot builder (a tenant connection on a driver that
        // accepts it, a mutator, an imported row) — UpdateFileRequest itself admits the name.
        $file = $this->diskImage();
        $session = $this->mixedSession();

        File::retrieved(function (File $retrieved): void {
            $retrieved->name = "poster\0.png";
        });

        $report = $this->delegation()->applySlotValues($session, ['attachment' => $file->id], SlotScopePolicy::Automation);

        $this->assertContains(['name' => 'attachment', 'reason' => 'invalid'], $report['skipped']);
        $this->assertArrayNotHasKey('attachment', $session->fresh()->slot_values ?? []);
    }

    public function test_a_disk_trashed_file_never_resolves(): void
    {
        // A file the user threw into the Disk trash is not theirs to reference any more. The disk-trash
        // MARKER is the authority on its own — deliberately not the soft-delete (detaching a task
        // attachment soft-deletes its file too, and that is NOT disk trash), so the lookup excludes the
        // marker explicitly rather than leaning on SoftDeletes to imply it.
        $marked = $this->diskImage();
        $marked->disk_trashed_at = now();
        $marked->save();

        $session = $this->mixedSession();

        $report = $this->delegation()->applySlotValues($session, ['attachment' => $marked->id], SlotScopePolicy::Automation);

        $this->assertContains(['name' => 'attachment', 'reason' => 'invalid'], $report['skipped']);
        $this->assertArrayNotHasKey('attachment', $session->fresh()->slot_values ?? []);

        // The real user-facing path (trash = marker + soft-delete) is refused too.
        $trashed = $this->diskImage();
        app(FileService::class)->trash($trashed);

        $full = $this->delegation()->applySlotValues($this->mixedSession(), ['attachment' => $trashed->id], SlotScopePolicy::Automation);
        $this->assertContains(['name' => 'attachment', 'reason' => 'invalid'], $full['skipped']);
    }

    public function test_a_non_disk_native_file_still_resolves(): void
    {
        // The PRIMARY use case: a form-submission / task attachment (fileable = the owning resource) and
        // a temp upload (no container yet) are NOT disk-native, and must stay usable as a slot value —
        // that is exactly what `{{trigger.fields.attachment}}` hands an automated run. The tenant scope,
        // not the container kind, is the boundary.
        $attachment = File::factory()->image()->attachedTo(Task::factory()->create([
            'creator_id' => $this->user->id,
            'assigned_id' => $this->user->id,
        ]))->create(['uploader_id' => $this->user->id]);

        $temp = File::factory()->image()->create(['uploader_id' => $this->user->id]);

        foreach (['attachment' => $attachment, 'temp' => $temp] as $case => $file) {
            $session = $this->mixedSession();

            $report = $this->delegation()->applySlotValues($session, ['attachment' => $file->id], SlotScopePolicy::Automation);

            $this->assertContains('attachment', $report['filled'], $case);
            $this->assertSame($file->id, $session->fresh()->slot_values['attachment']['id'], $case);
        }
    }

    public function test_a_required_file_slot_rejects_an_empty_value_and_an_optional_one_clears(): void
    {
        $session = $this->mixedSession();
        $required = $this->delegation()->applySlotValues($session, ['attachment' => null], SlotScopePolicy::Automation);
        $this->assertContains(['name' => 'attachment', 'reason' => 'invalid'], $required['skipped']);

        $optionalSlots = [
            ['name' => 'attachment', 'description' => 'A file', 'descriptor' => ['base' => 'file', 'nullable' => true, 'fields' => [
                ['key' => 'id', 'descriptor' => ['base' => 'text']],
            ]]],
        ];
        $optional = GenerationSession::factory()
            ->snapshot('post', ['body' => ['markdown' => 'Body']], $optionalSlots, ['attachment' => ['id' => 'x']])
            ->create(['creator_id' => $this->user->id]);

        $report = $this->delegation()->applySlotValues($optional, ['attachment' => null], SlotScopePolicy::Automation);

        $this->assertContains('attachment', $report['filled']);
        $this->assertNull($optional->fresh()->slot_values['attachment']);
    }

    public function test_the_bot_scope_still_refuses_the_very_same_file_slot(): void
    {
        // The divergence is a PARAMETER, not a fork: the same value, the same session, the same code path —
        // only the scope differs (a bot's values come from a model that could fabricate a reference).
        $file = $this->diskImage();
        $session = $this->mixedSession();

        $report = $this->delegation()->applySlotValues($session, ['attachment' => $file->id], SlotScopePolicy::Bot);

        $this->assertContains(['name' => 'attachment', 'reason' => 'out_of_scope'], $report['skipped']);
        $this->assertArrayNotHasKey('attachment', $session->fresh()->slot_values ?? []);
    }

    public function test_the_automation_scope_still_revalidates_every_other_value(): void
    {
        $session = $this->mixedSession();

        $report = $this->delegation()->applySlotValues($session, [
            'topic' => 'a good topic',
            'rows' => [['a' => 'x']],   // deferred composite — out of scope for EVERY policy
            'ghost' => 'nope',          // unknown slot
        ], SlotScopePolicy::Automation);

        $this->assertSame(['topic'], $report['filled']);
        $this->assertContains(['name' => 'rows', 'reason' => 'out_of_scope'], $report['skipped']);
        $this->assertContains(['name' => 'ghost', 'reason' => 'unknown_slot'], $report['skipped']);

        // NUL bytes + type mismatches are still refused by the shared descriptor authority.
        $nul = $this->delegation()->applySlotValues($this->mixedSession(), ['topic' => "bad\0value"], SlotScopePolicy::Automation);
        $this->assertContains(['name' => 'topic', 'reason' => 'invalid'], $nul['skipped']);

        $typed = $this->delegation()->applySlotValues($this->mixedSession(), ['topic' => ['not', 'text']], SlotScopePolicy::Automation);
        $this->assertContains(['name' => 'topic', 'reason' => 'invalid'], $typed['skipped']);
    }

    public function test_a_non_editable_session_persists_nothing_under_any_scope(): void
    {
        $file = $this->diskImage();
        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Generating)
            ->snapshot('post', ['body' => ['markdown' => 'Body']], $this->mixedSlots(), [])
            ->create(['creator_id' => $this->user->id]);

        $report = $this->delegation()->applySlotValues($session, ['attachment' => $file->id], SlotScopePolicy::Automation);

        $this->assertSame([], $report['filled']);
        $this->assertSame([], $session->fresh()->slot_values);
    }

    // ---- claimAndDispatch: the DEFAULT path is unchanged, an explicit one is honored ----

    public function test_the_default_dispatch_path_uses_the_ambient_default_connection(): void
    {
        Queue::fake();

        $session = GenerationSession::factory()->create(['creator_id' => $this->user->id]);

        $this->assertTrue(app(GenerationSessionRunManager::class)->claimAndDispatch($session));

        Queue::assertPushed(
            RunGenerationSessionJob::class,
            fn ($job) => $job->sessionId === $session->id && $job->connection === config('queue.default'),
        );
    }

    public function test_the_http_run_entry_points_still_dispatch_on_the_default_connection(): void
    {
        Queue::fake();

        $session = GenerationSession::factory()->create(['creator_id' => $this->user->id]);

        $this->postJson("/api/generator/sessions/{$session->id}/generate")->assertStatus(202);

        Queue::assertPushed(
            RunGenerationSessionJob::class,
            fn ($job) => $job->sessionId === $session->id && $job->connection === config('queue.default'),
        );
    }

    public function test_an_explicit_connection_is_honored_for_every_mode(): void
    {
        Queue::fake();

        $session = GenerationSession::factory()->create(['creator_id' => $this->user->id]);

        $this->assertTrue(
            app(GenerationSessionRunManager::class)->claimAndDispatch($session, GenerationRunMode::Full, null, null, 'redis'),
        );

        Queue::assertPushed(
            RunGenerationSessionJob::class,
            fn ($job) => $job->sessionId === $session->id && $job->connection === 'redis',
        );
        $this->assertSame(GenerationSessionStatus::Generating, $session->fresh()->status);
    }

    // ---- the budget refusal is legible OUTSIDE http -----------------------------

    public function test_the_budget_refusal_carries_its_localized_message_without_http(): void
    {
        $exception = new GenerationBudgetExceeded;

        $this->assertNotSame('', $exception->getMessage());
        $this->assertSame(__('generator.sessions.ai_budget_exceeded'), $exception->getMessage());
    }

    public function test_the_budget_refusals_http_contract_is_unchanged(): void
    {
        $response = (new GenerationBudgetExceeded)->render();

        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame([
            'code' => 'ai_budget_exceeded',
            'message' => __('generator.sessions.ai_budget_exceeded'),
        ], $response->getData(true));
    }

    // ---- the extracted image exporter ------------------------------------------

    public function test_the_exporter_saves_a_nested_storyboard_shot_image_to_the_disk(): void
    {
        $session = GenerationSession::factory()->status(GenerationSessionStatus::Ready)->create(['creator_id' => $this->user->id]);
        $version = app(GeneratedImageStore::class)->storeVersion($session->id, 'storyboard.1', 'SHOT-BYTES');

        $session->update(['results' => ['storyboard' => [
            'kind' => 'storyboard',
            'status' => 'ok',
            'shots' => [
                ['index' => 0, 'image_status' => 'failed', 'image_error' => 'x'],
                ['index' => 1, 'image_status' => 'ok', 'image' => ['mime' => 'image/png', 'width' => 1, 'height' => 1, 'version' => $version], 'part_key' => 'storyboard.1'],
            ],
        ]]]);

        $file = app(GeneratedImageExporter::class)->saveToDisk($session, 'storyboard.1');

        $this->assertSame(__('generator.sessions.saved_image_name') . '.png', $file->name);
        $this->assertSame($this->workspace->id, $file->workspace_id);
        $this->assertSame('SHOT-BYTES', Storage::get($file->path));
    }

    public function test_the_exporter_refuses_a_part_with_no_produced_image(): void
    {
        $session = GenerationSession::factory()->status(GenerationSessionStatus::Ready)->create(['creator_id' => $this->user->id]);

        $this->expectException(NotFoundHttpException::class);

        app(GeneratedImageExporter::class)->saveToDisk($session, 'image');
    }

    public function test_the_non_aborting_entry_point_returns_null_where_the_http_one_aborts(): void
    {
        // A queued caller has no HTTP response to abort into: "there is nothing to export" is an ordinary
        // outcome (a failed / never-run / undone part), not a 404. The HTTP path keeps aborting.
        $session = GenerationSession::factory()->status(GenerationSessionStatus::Ready)->create(['creator_id' => $this->user->id]);

        $this->assertNull(app(GeneratedImageExporter::class)->saveToDiskIfPresent($session, 'image'));
        $this->assertNull(app(GeneratedImageExporter::class)->saveToDiskIfPresent($session, 'storyboard.7'));

        try {
            app(GeneratedImageExporter::class)->saveToDisk($session, 'image');
            $this->fail('the HTTP entry point must still abort 404');
        } catch (NotFoundHttpException) {
            $this->assertTrue(true);
        }
    }

    public function test_both_exporter_entry_points_export_identically_when_an_image_exists(): void
    {
        $session = GenerationSession::factory()->status(GenerationSessionStatus::Ready)->create(['creator_id' => $this->user->id]);
        $version = app(GeneratedImageStore::class)->storeVersion($session->id, 'storyboard.1', 'SHOT-BYTES');

        $session->update(['results' => ['storyboard' => [
            'kind' => 'storyboard',
            'status' => 'ok',
            'shots' => [
                ['index' => 0, 'image_status' => 'failed', 'image_error' => 'x'],
                ['index' => 1, 'image_status' => 'ok', 'image' => ['mime' => 'image/png', 'width' => 1, 'height' => 1, 'version' => $version], 'part_key' => 'storyboard.1'],
            ],
        ]]]);

        $viaHttp = app(GeneratedImageExporter::class)->saveToDisk($session, 'storyboard.1', 'shot.png');
        $viaRun = app(GeneratedImageExporter::class)->saveToDiskIfPresent($session, 'storyboard.1', 'shot.png');

        $this->assertNotNull($viaRun);
        $this->assertSame($viaHttp->name, $viaRun->name);
        $this->assertSame($viaHttp->mime_type, $viaRun->mime_type);
        $this->assertSame($viaHttp->workspace_id, $viaRun->workspace_id);
        $this->assertSame(Storage::get($viaHttp->path), Storage::get($viaRun->path));
        $this->assertSame('SHOT-BYTES', Storage::get($viaRun->path));
    }
}
