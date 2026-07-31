<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Bot\Jobs\GenerateBotVisualJob;
use App\Modules\Bot\Models\Bot;
use App\Modules\Bot\Services\BotVisualIdentityService;
use App\Modules\Disk\Enums\DiskAiEditStatus;
use App\Modules\Disk\Models\DiskAiEdit;
use App\Modules\Disk\Models\File;
use App\Modules\Disk\Services\ImageAiService;
use App\Modules\Variables\Models\AiUsageEvent;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Image;
use Tests\TestCase;

/**
 * B2 — CREATING the bot's likeness. Two ways in (edit a reference image / generate from the written
 * description), one async pipeline: the Disk's own image machinery (status row, daily cap, cost
 * meter gate, poll + broadcast) with a Bot-owned worker that files the result as the bot's image.
 */
class BotVisualIdentityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    private Bot $bot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $this->workspace->users()->attach($this->user->id);

        $this->actingAs($this->user)->withHeader('X-Workspace-Id', $this->workspace->id);
        app(TenantContext::class)->set($this->workspace);

        Storage::fake();

        $this->bot = Bot::factory()->withVisual()->create(['creator_id' => $this->user->id]);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    private function fakeEdited(string $bytes = 'EDITED'): void
    {
        Http::fake(['*/images/edits' => Http::response(['data' => [['b64_json' => base64_encode($bytes)]]], 200)]);
    }

    private function runJob(?DiskAiEdit $edit = null): void
    {
        $edit ??= DiskAiEdit::sole();

        (new GenerateBotVisualJob($this->bot->id, $edit->id, $this->workspace->id, $this->user->id))
            ->handle(app(ImageAiService::class), app(BotVisualIdentityService::class));
    }

    // ---- Dispatch: the two modes -----------------------------------------------------

    public function test_reference_mode_queues_one_edit_and_persists_the_uploaded_source(): void
    {
        Queue::fake();

        $this->postJson("/api/bots/{$this->bot->id}/visual/generate", [
            'mode' => 'reference',
            'reference' => UploadedFile::fake()->image('face.png'),
        ])
            ->assertStatus(202)
            ->assertJsonStructure(['data' => ['id', 'status']])
            ->assertJsonPath('data.status', 'queued');

        $edit = DiskAiEdit::sole();
        $this->assertNotNull($edit->input_image_path, 'a reference run feeds the provider an input image');
        Storage::assertExists($edit->input_image_path);
        // The composed prompt carries the SAVED identity, wardrobe included.
        $this->assertStringContainsString('A simple green summer dress.', $edit->prompt);

        // The upload became a file the BOT owns, and the module now points at it as its source.
        $reference = File::where('fileable_type', 'bot')->where('fileable_id', $this->bot->id)->sole();
        $this->assertSame($reference->id, $this->bot->fresh()->visualIdentity()['reference_file_id']);

        Queue::assertPushed(
            GenerateBotVisualJob::class,
            fn (GenerateBotVisualJob $job) => $job->editId === $edit->id
                && $job->botId === $this->bot->id
                && $job->workspaceId === $this->workspace->id
                && $job->userId === $this->user->id,
        );
    }

    public function test_reference_mode_accepts_a_file_picked_from_the_disk(): void
    {
        Queue::fake();

        $picked = File::factory()->image()->atRoot()->create();
        Storage::put($picked->path, 'DISKBYTES');

        $this->postJson("/api/bots/{$this->bot->id}/visual/generate", [
            'mode' => 'reference',
            'reference_file_id' => $picked->id,
        ])->assertStatus(202);

        // The picked file's BYTES were read into the run…
        $edit = DiskAiEdit::sole();
        $this->assertSame('DISKBYTES', Storage::get($edit->input_image_path));

        // …and it is referenced in place, never copied onto the bot (it belongs to the disk).
        $this->assertSame($picked->id, $this->bot->fresh()->visualIdentity()['reference_file_id']);
        $this->assertSame(0, File::where('fileable_type', 'bot')->count());
    }

    public function test_description_mode_queues_a_generation_with_no_input_image(): void
    {
        Queue::fake();

        $this->postJson("/api/bots/{$this->bot->id}/visual/generate", ['mode' => 'description'])
            ->assertStatus(202);

        $edit = DiskAiEdit::sole();
        // No input image IS the mode marker the worker reads (no column needed).
        $this->assertNull($edit->input_image_path);
        $this->assertStringContainsString('Subject: A cheerful red-haired illustrator', $edit->prompt);
    }

    public function test_a_reference_run_needs_exactly_one_source(): void
    {
        Queue::fake();

        $this->postJson("/api/bots/{$this->bot->id}/visual/generate", ['mode' => 'reference'])
            ->assertUnprocessable()->assertJsonValidationErrors('reference');

        $picked = File::factory()->image()->atRoot()->create();
        $this->postJson("/api/bots/{$this->bot->id}/visual/generate", [
            'mode' => 'reference',
            'reference' => UploadedFile::fake()->image('face.png'),
            'reference_file_id' => $picked->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('reference');

        Queue::assertNothingPushed();
    }

    public function test_a_foreign_file_cannot_be_used_as_a_reference(): void
    {
        Queue::fake();

        $other = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $other->users()->attach($this->user->id);

        app(TenantContext::class)->set($other);
        $foreign = File::factory()->image()->atRoot()->create();
        app(TenantContext::class)->set($this->workspace);

        $this->postJson("/api/bots/{$this->bot->id}/visual/generate", [
            'mode' => 'reference',
            'reference_file_id' => $foreign->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('reference_file_id');

        Queue::assertNothingPushed();
    }

    public function test_an_identity_with_nothing_to_draw_is_refused_before_any_spend(): void
    {
        Queue::fake();

        $blank = Bot::factory()->create(['creator_id' => $this->user->id, 'visual' => null]);

        $this->postJson("/api/bots/{$blank->id}/visual/generate", ['mode' => 'description'])
            ->assertUnprocessable()->assertJsonValidationErrors('instruction');

        $this->assertSame(0, DiskAiEdit::count());
        Queue::assertNothingPushed();
    }

    public function test_only_the_owner_may_generate(): void
    {
        Queue::fake();

        $member = User::factory()->create();
        $this->workspace->users()->attach($member->id);

        $this->actingAs($member)->withHeader('X-Workspace-Id', $this->workspace->id)
            ->postJson("/api/bots/{$this->bot->id}/visual/generate", ['mode' => 'description'])
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_the_daily_image_cap_covers_the_bot_generator_too(): void
    {
        config()->set('ai.disk_image_max_per_day', 1);
        Queue::fake();

        $this->postJson("/api/bots/{$this->bot->id}/visual/generate", ['mode' => 'description'])
            ->assertStatus(202);

        $this->postJson("/api/bots/{$this->bot->id}/visual/generate", ['mode' => 'description'])
            ->assertStatus(429);

        $this->assertSame(1, DiskAiEdit::count());
        Queue::assertPushed(GenerateBotVisualJob::class, 1);
    }

    // ---- Worker: the result becomes the bot's image -----------------------------------

    public function test_the_worker_files_the_result_as_a_bot_owned_candidate(): void
    {
        Queue::fake();
        $this->fakeEdited();

        $this->postJson("/api/bots/{$this->bot->id}/visual/generate", [
            'mode' => 'reference',
            'reference' => UploadedFile::fake()->image('face.png'),
        ])->assertStatus(202);

        $this->runJob();

        $edit = DiskAiEdit::sole();
        $this->assertSame(DiskAiEditStatus::Done, $edit->status);

        $candidate = File::where('fileable_type', 'bot')
            ->where('name', 'like', 'bot-candidate-%')
            ->sole();

        $this->assertSame($this->bot->id, $candidate->fileable_id);
        $this->assertSame('image/png', $candidate->mime_type);
        $this->assertSame('EDITED', Storage::get($candidate->path));
        // Attributed to whoever asked for it — the worker has no auth() of its own.
        $this->assertSame($this->user->id, $candidate->uploader_id);

        $this->assertSame([$candidate->id], $this->bot->fresh()->visualIdentity()['candidates']);
    }

    public function test_a_reference_run_meters_the_edit_channel(): void
    {
        Queue::fake();
        $this->fakeEdited();

        $this->postJson("/api/bots/{$this->bot->id}/visual/generate", [
            'mode' => 'reference',
            'reference' => UploadedFile::fake()->image('face.png'),
        ])->assertStatus(202);

        $this->runJob();

        $this->assertTrue(AiUsageEvent::where('channel', 'ai_image_edit')->exists());
        $this->assertFalse(AiUsageEvent::where('channel', 'ai_image_generate')->exists());
    }

    public function test_a_description_run_meters_the_generate_channel(): void
    {
        Queue::fake();
        Image::fake([base64_encode('GENERATED')]);

        $this->postJson("/api/bots/{$this->bot->id}/visual/generate", ['mode' => 'description'])
            ->assertStatus(202);

        $this->runJob();

        $this->assertTrue(AiUsageEvent::where('channel', 'ai_image_generate')->exists());
        $this->assertFalse(AiUsageEvent::where('channel', 'ai_image_edit')->exists());

        $candidate = File::where('fileable_type', 'bot')->sole();
        $this->assertSame('GENERATED', Storage::get($candidate->path));
        $this->assertSame(DiskAiEditStatus::Done, DiskAiEdit::sole()->status);
    }

    public function test_the_candidate_is_filed_before_the_edit_is_published_as_done(): void
    {
        // ORDERING pin: `done` is what wakes the client (poll + broadcast), so the candidate must
        // already exist when it fires — otherwise a refetch races the worker and shows nothing.
        $this->fakeEdited();

        $edit = app(ImageAiService::class)->prepare('SOURCE', 'a prompt');

        $statusAtHook = null;
        app(ImageAiService::class)->process($edit->id, null, function () use ($edit, &$statusAtHook) {
            $statusAtHook = DiskAiEdit::find($edit->id)->status;
        });

        $this->assertSame(DiskAiEditStatus::Processing, $statusAtHook);
        $this->assertSame(DiskAiEditStatus::Done, $edit->fresh()->status);
    }

    public function test_a_throwing_result_hook_never_buys_a_second_provider_call(): void
    {
        // The hook runs BEFORE the row goes terminal, so a hook that throws leaves the edit `processing`
        // and the queue retries the whole job (tries = 3). Without the result persisted first, that retry
        // re-enters the provider call — up to THREE paid images for one requested edit, and the workspace
        // is billed for two it never sees.
        $this->fakeEdited();

        $edit = app(ImageAiService::class)->prepare('SOURCE', 'a prompt');

        try {
            app(ImageAiService::class)->process($edit->id, null, function (): void {
                throw new \RuntimeException('materializing the result blew up');
            });
            $this->fail('the throwing hook must surface so the queue can retry the job');
        } catch (\RuntimeException $e) {
            $this->assertSame('materializing the result blew up', $e->getMessage());
        }

        // The retry: the paid result is already in hand, so only the unpaid half is replayed.
        $delivered = null;
        app(ImageAiService::class)->process($edit->id, null, function (string $image) use (&$delivered): void {
            $delivered = $image;
        });

        Http::assertSentCount(1);
        $this->assertSame(1, AiUsageEvent::where('channel', 'ai_image_edit')->count(), 'the retry must not be metered a second time');
        $this->assertSame(base64_encode('EDITED'), $delivered, 'the retry replays the hook with the SAME bytes the workspace already paid for');

        $edit->refresh();
        $this->assertSame(DiskAiEditStatus::Done, $edit->status);
        $this->assertSame(base64_encode('EDITED'), $edit->result_image);
    }

    public function test_a_hook_that_never_succeeds_leaves_a_failed_edit_not_an_endless_bill(): void
    {
        // The honest end of the story above: if the hook keeps throwing, the job exhausts its retries and
        // failed() closes the row — with exactly ONE provider call paid for, whatever the retry count.
        $this->fakeEdited();

        $edit = app(ImageAiService::class)->prepare('SOURCE', 'a prompt');
        $hook = function (): void {
            throw new \RuntimeException('still broken');
        };

        foreach (range(1, 3) as $attempt) {
            try {
                app(ImageAiService::class)->process($edit->id, null, $hook);
            } catch (\RuntimeException) {
                // the queue's retry
            }
        }

        app(ImageAiService::class)->fail($edit->id, __('disk.ai.failed'));

        Http::assertSentCount(1);
        $this->assertSame(DiskAiEditStatus::Failed, $edit->fresh()->status);
        $this->assertSame(__('disk.ai.failed'), $edit->fresh()->error);
    }

    public function test_the_candidate_strip_is_trimmed_and_the_evicted_bytes_deleted(): void
    {
        Queue::fake();
        $this->fakeEdited();

        // Fill the strip to the cap; the FIRST is the oldest and has no approval protecting it.
        $existing = collect(range(1, BotVisualIdentityService::MAX_CANDIDATES))
            ->map(function () {
                $file = File::factory()->image()->attachedTo($this->bot)->create();
                Storage::put($file->path, 'OLD');

                return $file;
            });

        $identity = $this->bot->visualIdentity();
        $identity['candidates'] = $existing->pluck('id')->all();
        $this->bot->update(['visual' => $identity]);

        $this->postJson("/api/bots/{$this->bot->id}/visual/generate", ['mode' => 'description'])
            ->assertStatus(202);

        Image::fake([base64_encode('NEW')]);
        $this->runJob();

        $candidates = $this->bot->fresh()->visualIdentity()['candidates'];
        $this->assertCount(BotVisualIdentityService::MAX_CANDIDATES, $candidates);

        $evicted = $existing->first();
        $this->assertNotContains($evicted->id, $candidates);
        $this->assertNull(File::withTrashed()->find($evicted->id), 'the evicted candidate row is gone');
        Storage::assertMissing($evicted->path);
    }

    public function test_the_approved_likeness_is_never_evicted(): void
    {
        Queue::fake();

        $existing = collect(range(1, BotVisualIdentityService::MAX_CANDIDATES))
            ->map(fn () => File::factory()->image()->attachedTo($this->bot)->create());

        $identity = $this->bot->visualIdentity();
        $identity['candidates'] = $existing->pluck('id')->all();
        // The OLDEST one is the approved one — the eviction rule must skip it.
        $identity['canonical_file_id'] = $existing->first()->id;
        $this->bot->update(['visual' => $identity]);

        $this->postJson("/api/bots/{$this->bot->id}/visual/generate", ['mode' => 'description'])
            ->assertStatus(202);

        Image::fake([base64_encode('NEW')]);
        $this->runJob();

        $candidates = $this->bot->fresh()->visualIdentity()['candidates'];
        $this->assertContains($existing->first()->id, $candidates, 'the approved likeness survives the trim');
        $this->assertNotContains($existing->get(1)->id, $candidates, 'the next-oldest went instead');
    }

    // ---- The moderation wall ---------------------------------------------------------

    public function test_a_moderation_refusal_is_recorded_as_its_own_terminal_state(): void
    {
        Queue::fake();
        Log::spy();

        // The provider renders the image and THEN refuses to hand it over.
        Http::fake(['*/images/edits' => Http::response([
            'error' => [
                'code' => 'moderation_blocked',
                'message' => 'Your request was rejected as a result of our safety system. [sexual]',
            ],
        ], 400)]);

        $this->bot->update(['visual' => array_merge($this->bot->visualIdentity(), [
            'wardrobe' => 'A swimsuit on the beach',
        ])]);

        $this->postJson("/api/bots/{$this->bot->id}/visual/generate", [
            'mode' => 'reference',
            'reference' => UploadedFile::fake()->image('face.png'),
        ])->assertStatus(202);

        $this->runJob();

        $edit = DiskAiEdit::sole();
        $this->assertSame(DiskAiEditStatus::SafetyRejected, $edit->status);
        $this->assertSame(__('disk.ai.safety'), $edit->error);
        $this->assertNull($edit->input_image_path, 'inputs are cleaned up like any terminal edit');
        $this->assertSame(0, File::where('fileable_type', 'bot')->where('name', 'like', 'bot-candidate-%')->count());

        // ONE provider call: a rejection is deterministic, so it is never retried.
        Http::assertSentCount(1);

        // The reason is logged as a CODE and a fact — never the prompt (user content).
        $prompt = $edit->prompt;
        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) use ($prompt) {
            $this->assertSame('moderation_blocked', $context['code'] ?? null);
            $this->assertStringNotContainsString($prompt, $message . json_encode($context));
            $this->assertStringNotContainsString('swimsuit', $message . json_encode($context));

            return true;
        });
    }

    public function test_a_moderation_refusal_reaches_the_client_as_a_failure_with_a_reason(): void
    {
        // The WIRE keeps the vocabulary consumers already settle on (`failed`), and the machine
        // reason rides alongside additively — so the UI can say what actually happened.
        $edit = DiskAiEdit::create([
            'status' => DiskAiEditStatus::SafetyRejected,
            'prompt' => 'x',
            'error' => __('disk.ai.safety'),
        ]);

        $this->getJson('/api/disk/ai/image/' . $edit->id)
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error', __('disk.ai.safety'))
            ->assertJsonPath('data.error_code', 'safety_rejected')
            ->assertJsonMissingPath('data.image');
    }

    public function test_an_ordinary_failure_carries_no_reason_code(): void
    {
        $edit = DiskAiEdit::create([
            'status' => DiskAiEditStatus::Failed,
            'prompt' => 'x',
            'error' => __('disk.ai.failed'),
        ]);

        $this->getJson('/api/disk/ai/image/' . $edit->id)
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonMissingPath('data.error_code');
    }

    // ---- Curation --------------------------------------------------------------------

    public function test_a_candidate_can_be_approved(): void
    {
        $candidate = File::factory()->image()->attachedTo($this->bot)->create();
        $this->bot->update(['visual' => array_merge($this->bot->visualIdentity(), [
            'candidates' => [$candidate->id],
        ])]);

        $this->postJson("/api/bots/{$this->bot->id}/visual/approve", ['file_id' => $candidate->id])
            ->assertOk()
            ->assertJsonPath('data.visual.canonical_file_id', $candidate->id)
            // It stays in the strip — approving picks one, it does not remove it.
            ->assertJsonPath('data.visual.candidates.0', $candidate->id);
    }

    public function test_approving_someone_elses_image_is_refused(): void
    {
        $other = Bot::factory()->create(['creator_id' => $this->user->id]);
        $foreign = File::factory()->image()->attachedTo($other)->create();

        $this->postJson("/api/bots/{$this->bot->id}/visual/approve", ['file_id' => $foreign->id])
            ->assertUnprocessable()->assertJsonValidationErrors('file_id');

        // A file the bot DOES own but which was never an iteration (the reference upload) is
        // refused too — approving picks from the candidate strip.
        $reference = File::factory()->image()->attachedTo($this->bot)->create();
        $this->postJson("/api/bots/{$this->bot->id}/visual/approve", ['file_id' => $reference->id])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_a_candidate_can_be_deleted_with_its_bytes(): void
    {
        $candidate = File::factory()->image()->attachedTo($this->bot)->create();
        Storage::put($candidate->path, 'BYTES');
        $this->bot->update(['visual' => array_merge($this->bot->visualIdentity(), [
            'candidates' => [$candidate->id],
        ])]);

        $this->deleteJson("/api/bots/{$this->bot->id}/visual/candidates/{$candidate->id}")
            ->assertOk()
            ->assertJsonPath('data.visual.candidates', []);

        $this->assertNull(File::withTrashed()->find($candidate->id));
        Storage::assertMissing($candidate->path);
    }

    public function test_regenerating_from_the_current_source_does_not_delete_it(): void
    {
        // REGRESSION: replacing the reference deletes the superseded one — but "iterate on this one
        // again" passes the SAME file in as both old and new, and deleting it would destroy the
        // source the module still names.
        Queue::fake();

        $source = File::factory()->image()->attachedTo($this->bot)->create();
        Storage::put($source->path, 'SOURCE');
        $this->bot->update(['visual' => array_merge($this->bot->visualIdentity(), [
            'reference_file_id' => $source->id,
        ])]);

        $this->postJson("/api/bots/{$this->bot->id}/visual/generate", [
            'mode' => 'reference',
            'reference_file_id' => $source->id,
        ])->assertStatus(202);

        $this->assertNotNull(File::find($source->id), 'the source survives being re-used');
        Storage::assertExists($source->path);
        $this->assertSame($source->id, $this->bot->fresh()->visualIdentity()['reference_file_id']);
    }

    public function test_deleting_a_candidate_that_is_also_the_source_clears_the_pointer(): void
    {
        // REGRESSION: the identity must never name bytes that no longer exist.
        $candidate = File::factory()->image()->attachedTo($this->bot)->create();
        $this->bot->update(['visual' => array_merge($this->bot->visualIdentity(), [
            'candidates' => [$candidate->id],
            'reference_file_id' => $candidate->id,
        ])]);

        $this->deleteJson("/api/bots/{$this->bot->id}/visual/candidates/{$candidate->id}")
            ->assertOk()
            ->assertJsonPath('data.visual.candidates', [])
            ->assertJsonPath('data.visual.reference_file_id', null);
    }

    public function test_the_approved_likeness_cannot_be_deleted_as_a_candidate(): void
    {
        $candidate = File::factory()->image()->attachedTo($this->bot)->create();
        $this->bot->update(['visual' => array_merge($this->bot->visualIdentity(), [
            'candidates' => [$candidate->id],
            'canonical_file_id' => $candidate->id,
        ])]);

        $this->deleteJson("/api/bots/{$this->bot->id}/visual/candidates/{$candidate->id}")
            ->assertUnprocessable();

        $this->assertNotNull(File::find($candidate->id), 'the approved image is left alone');
    }

    public function test_only_the_owner_may_curate(): void
    {
        $member = User::factory()->create();
        $this->workspace->users()->attach($member->id);

        $candidate = File::factory()->image()->attachedTo($this->bot)->create();
        $this->bot->update(['visual' => array_merge($this->bot->visualIdentity(), [
            'candidates' => [$candidate->id],
        ])]);

        $this->actingAs($member)->withHeader('X-Workspace-Id', $this->workspace->id);

        $this->postJson("/api/bots/{$this->bot->id}/visual/approve", ['file_id' => $candidate->id])
            ->assertForbidden();
        $this->deleteJson("/api/bots/{$this->bot->id}/visual/candidates/{$candidate->id}")
            ->assertForbidden();
    }
}
