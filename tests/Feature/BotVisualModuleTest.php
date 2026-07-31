<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Bot\Models\Bot;
use App\Modules\Disk\Models\File;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B1 — the bot's VISUAL module as a saveable thing: its shape, its caps, its normalization, and the
 * OWNERSHIP rule on every file id it carries.
 */
class BotVisualModuleTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Visual Bot',
            'persona' => 'A helpful assistant.',
        ], $overrides);
    }

    private function visual(array $overrides = []): array
    {
        return array_merge([
            'enabled' => true,
            'descriptor' => 'A red-haired illustrator in her late twenties.',
            'aesthetic' => 'Soft flat illustration, warm palette.',
            'wardrobe' => 'A simple green summer dress.',
            'prohibitions' => ['no logos'],
        ], $overrides);
    }

    // ---- Persistence + normalization -------------------------------------------------

    public function test_it_creates_and_returns_the_visual_module(): void
    {
        $this->postJson('/api/bots', $this->payload(['visual' => $this->visual()]))
            ->assertCreated()
            ->assertJsonPath('data.visual.enabled', true)
            ->assertJsonPath('data.visual.descriptor', 'A red-haired illustrator in her late twenties.')
            ->assertJsonPath('data.visual.wardrobe', 'A simple green summer dress.')
            ->assertJsonPath('data.visual.prohibitions.0', 'no logos')
            // The image-bearing half starts empty and is filled by the generator, not by the client.
            ->assertJsonPath('data.visual.candidates', [])
            ->assertJsonPath('data.visual.canonical_file_id', null);

        $bot = Bot::firstWhere('name', 'Visual Bot');
        $this->assertTrue($bot->visualEnabled());
        $this->assertSame('A simple green summer dress.', $bot->visualIdentity()['wardrobe']);
    }

    public function test_a_disabled_module_keeps_its_content(): void
    {
        // `enabled` is a toggle, not an eraser — same posture as the knowledge module.
        $bot = Bot::factory()->withVisual()->create(['creator_id' => $this->user->id]);

        $this->putJson("/api/bots/{$bot->id}", $this->payload([
            'visual' => $this->visual(['enabled' => false]),
        ]))
            ->assertOk()
            ->assertJsonPath('data.visual.enabled', false)
            ->assertJsonPath('data.visual.descriptor', 'A red-haired illustrator in her late twenties.');

        $bot->refresh();
        $this->assertFalse($bot->visualEnabled());
        $this->assertNotNull($bot->visualIdentity()['descriptor']);
    }

    public function test_a_legacy_null_module_reads_back_as_null_without_error(): void
    {
        $bot = Bot::factory()->create(['creator_id' => $this->user->id, 'visual' => null]);

        $this->assertNull($bot->visualIdentity());
        $this->assertFalse($bot->visualEnabled());

        $this->getJson("/api/bots/{$bot->id}")
            ->assertOk()
            ->assertJsonPath('data.visual', null);
    }

    public function test_a_malformed_stored_module_is_normalized_on_read(): void
    {
        // Whatever ends up in the json column, reads come back as the complete typed shape.
        $bot = Bot::factory()->create([
            'creator_id' => $this->user->id,
            'visual' => ['enabled' => 1, 'descriptor' => '', 'prohibitions' => ['ok', '', 42]],
        ]);

        $identity = $bot->visualIdentity();

        $this->assertTrue($identity['enabled']);
        $this->assertNull($identity['descriptor']);
        $this->assertSame(['ok'], $identity['prohibitions']);
        $this->assertSame([], $identity['candidates']);
        $this->assertNull($identity['canonical_file_id']);
    }

    public function test_an_absent_module_leaves_the_stored_one_untouched(): void
    {
        // THE data-safety property: candidates/canonical are written asynchronously by the
        // generator, so an ordinary save that knows nothing about `visual` must not blank them.
        $bot = Bot::factory()->create(['creator_id' => $this->user->id]);
        $file = $this->botFile($bot);
        $bot->update(['visual' => $this->visual([
            'candidates' => [$file->id],
            'canonical_file_id' => $file->id,
            'reference_file_id' => null,
            'prompt' => 'Subject: …',
        ])]);

        $this->putJson("/api/bots/{$bot->id}", $this->payload(['name' => 'Renamed']))
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed')
            ->assertJsonPath('data.visual.candidates.0', $file->id);

        $this->assertSame([$file->id], $bot->fresh()->visualIdentity()['candidates']);
    }

    public function test_the_list_exposes_the_module_flags(): void
    {
        $bot = Bot::factory()->create(['creator_id' => $this->user->id]);
        $file = $this->botFile($bot);
        $bot->update(['visual' => $this->visual(['candidates' => [$file->id], 'canonical_file_id' => $file->id])]);

        Bot::factory()->create(['creator_id' => $this->user->id, 'name' => 'Plain', 'visual' => null]);

        $response = $this->getJson('/api/bots')->assertOk();

        $rows = collect($response->json('data'))->keyBy('name');
        $this->assertTrue($rows[$bot->name]['visual_enabled']);
        $this->assertTrue($rows[$bot->name]['visual_has_image']);
        $this->assertFalse($rows['Plain']['visual_enabled']);
        $this->assertFalse($rows['Plain']['visual_has_image']);
    }

    // ---- Caps ------------------------------------------------------------------------

    public function test_it_caps_the_text_fields(): void
    {
        $this->postJson('/api/bots', $this->payload([
            'visual' => $this->visual(['descriptor' => str_repeat('a', 241)]),
        ]))->assertUnprocessable()->assertJsonValidationErrors(['visual.descriptor']);

        $this->postJson('/api/bots', $this->payload([
            'visual' => $this->visual(['aesthetic' => str_repeat('a', 2001)]),
        ]))->assertUnprocessable()->assertJsonValidationErrors(['visual.aesthetic']);

        $this->postJson('/api/bots', $this->payload([
            'visual' => $this->visual(['wardrobe' => str_repeat('a', 501)]),
        ]))->assertUnprocessable()->assertJsonValidationErrors(['visual.wardrobe']);
    }

    public function test_it_caps_the_prohibitions_list(): void
    {
        $this->postJson('/api/bots', $this->payload([
            'visual' => $this->visual(['prohibitions' => array_fill(0, 51, 'x')]),
        ]))->assertUnprocessable()->assertJsonValidationErrors(['visual.prohibitions']);

        $this->postJson('/api/bots', $this->payload([
            'visual' => $this->visual(['prohibitions' => [str_repeat('x', 256)]]),
        ]))->assertUnprocessable()->assertJsonValidationErrors(['visual.prohibitions.0']);
    }

    public function test_it_caps_the_candidate_strip(): void
    {
        $bot = Bot::factory()->create(['creator_id' => $this->user->id]);
        $ids = collect(range(1, 7))->map(fn () => $this->botFile($bot)->id)->all();

        $this->putJson("/api/bots/{$bot->id}", $this->payload([
            'visual' => $this->visual(['candidates' => $ids]),
        ]))->assertUnprocessable()->assertJsonValidationErrors(['visual.candidates']);
    }

    // ---- Ownership of file ids -------------------------------------------------------

    public function test_a_candidate_must_belong_to_this_bot(): void
    {
        $bot = Bot::factory()->create(['creator_id' => $this->user->id]);
        $other = Bot::factory()->create(['creator_id' => $this->user->id]);

        // Another bot's image, and a plain disk file: neither is an iteration of THIS bot.
        foreach ([$this->botFile($other)->id, File::factory()->image()->atRoot()->create()->id] as $foreign) {
            $this->putJson("/api/bots/{$bot->id}", $this->payload([
                'visual' => $this->visual(['candidates' => [$foreign]]),
            ]))->assertUnprocessable()->assertJsonValidationErrors(['visual.candidates.0']);

            $this->putJson("/api/bots/{$bot->id}", $this->payload([
                'visual' => $this->visual(['canonical_file_id' => $foreign]),
            ]))->assertUnprocessable()->assertJsonValidationErrors(['visual.canonical_file_id']);
        }
    }

    public function test_the_bots_own_image_is_accepted(): void
    {
        $bot = Bot::factory()->create(['creator_id' => $this->user->id]);
        $file = $this->botFile($bot);

        $this->putJson("/api/bots/{$bot->id}", $this->payload([
            'visual' => $this->visual(['candidates' => [$file->id], 'canonical_file_id' => $file->id]),
        ]))
            ->assertOk()
            ->assertJsonPath('data.visual.candidates.0', $file->id)
            ->assertJsonPath('data.visual.canonical_file_id', $file->id);
    }

    /**
     * The approved likeness must be one of the SUBMITTED candidates — the same rule the approve endpoint
     * enforces, now mirrored on the plain write path. No legitimate flow produces a canonical outside the
     * strip (eviction never removes it, its DELETE is refused), so a bot-owned id that is not in
     * `candidates` can only be a hand-crafted PUT — and accepting it would make the two write paths
     * disagree about what an "approved likeness" is.
     */
    public function test_the_canonical_must_be_one_of_the_submitted_candidates(): void
    {
        $bot = Bot::factory()->create(['creator_id' => $this->user->id]);
        $inStrip = $this->botFile($bot);
        $ownButOutside = $this->botFile($bot);

        $this->putJson("/api/bots/{$bot->id}", $this->payload([
            'visual' => $this->visual(['candidates' => [$inStrip->id], 'canonical_file_id' => $ownButOutside->id]),
        ]))->assertUnprocessable()->assertJsonValidationErrors(['visual.canonical_file_id']);
    }

    /**
     * The module is overwritten WHOLE: a payload whose `candidates` omit an entry REALLY drops it. This is
     * the exact invariant the editor's form/server state split exists for (a stale snapshot would silently
     * discard a candidate the async generator filed in the meantime) — the FE side is pinned three ways,
     * and this is the server half it depends on.
     */
    public function test_a_stale_candidates_payload_really_drops_the_omitted_candidate(): void
    {
        $bot = Bot::factory()->create(['creator_id' => $this->user->id]);
        $kept = $this->botFile($bot);
        $dropped = $this->botFile($bot);

        $this->putJson("/api/bots/{$bot->id}", $this->payload([
            'visual' => $this->visual(['candidates' => [$kept->id, $dropped->id]]),
        ]))->assertOk();

        $this->putJson("/api/bots/{$bot->id}", $this->payload([
            'visual' => $this->visual(['candidates' => [$kept->id]]),
        ]))->assertOk()->assertJsonPath('data.visual.candidates', [$kept->id]);

        $this->assertSame([$kept->id], $bot->fresh()->visualIdentity()['candidates']);
    }

    public function test_a_disk_native_file_is_accepted_only_as_the_reference(): void
    {
        $bot = Bot::factory()->create(['creator_id' => $this->user->id]);
        $diskFile = File::factory()->image()->atRoot()->create();

        // "Pick from Disk" is a legitimate SOURCE for a (re)generation…
        $this->putJson("/api/bots/{$bot->id}", $this->payload([
            'visual' => $this->visual(['reference_file_id' => $diskFile->id]),
        ]))->assertOk()->assertJsonPath('data.visual.reference_file_id', $diskFile->id);

        // …but never an approved likeness (covered above for candidates/canonical).
        $this->assertSame($diskFile->id, $bot->fresh()->visualIdentity()['reference_file_id']);
    }

    public function test_a_file_from_another_workspace_is_refused(): void
    {
        $bot = Bot::factory()->create(['creator_id' => $this->user->id]);

        $other = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $other->users()->attach($this->user->id);

        app(TenantContext::class)->set($other);
        $foreign = File::factory()->image()->atRoot()->create();
        app(TenantContext::class)->set($this->workspace);

        $this->putJson("/api/bots/{$bot->id}", $this->payload([
            'visual' => $this->visual(['reference_file_id' => $foreign->id]),
        ]))->assertUnprocessable()->assertJsonValidationErrors(['visual.reference_file_id']);
    }

    public function test_garbage_and_unknown_ids_are_refused_cleanly(): void
    {
        $bot = Bot::factory()->create(['creator_id' => $this->user->id]);

        // A non-uuid must be a validation error, never a database error.
        $this->putJson("/api/bots/{$bot->id}", $this->payload([
            'visual' => $this->visual(['reference_file_id' => 'not-a-uuid']),
        ]))->assertUnprocessable()->assertJsonValidationErrors(['visual.reference_file_id']);

        $this->putJson("/api/bots/{$bot->id}", $this->payload([
            'visual' => $this->visual(['canonical_file_id' => (string) \Illuminate\Support\Str::uuid()]),
        ]))->assertUnprocessable()->assertJsonValidationErrors(['visual.canonical_file_id']);
    }

    public function test_nothing_can_be_bot_owned_at_create_time(): void
    {
        // There is no bot yet, so no file can belong to it — a candidate on create is always wrong.
        $bot = Bot::factory()->create(['creator_id' => $this->user->id]);

        $this->postJson('/api/bots', $this->payload([
            'visual' => $this->visual(['candidates' => [$this->botFile($bot)->id]]),
        ]))->assertUnprocessable()->assertJsonValidationErrors(['visual.candidates.0']);
    }

    // ---- Bot files stay out of the Disk ----------------------------------------------

    public function test_bot_images_never_appear_in_the_disk_browser(): void
    {
        $bot = Bot::factory()->create(['creator_id' => $this->user->id]);
        $botFile = $this->botFile($bot);
        $diskFile = File::factory()->image()->atRoot()->create();

        // The bot owns its image (that is what keeps it out of the disk), and only its own.
        $this->assertSame([$botFile->id], $bot->visualImages()->pluck('id')->all());

        $items = $this->getJson('/api/disk/items')->assertOk()->json('data');
        $ids = collect($items)->pluck('id')->all();

        $this->assertContains($diskFile->id, $ids);
        $this->assertNotContains($botFile->id, $ids);

        // …nor under the read-only "Zasoby" tree, which only walks REGISTERED resource types.
        $tree = $this->getJson('/api/disk/resources')->assertOk()->json('data');
        $this->assertNotContains('bot', collect($tree)->pluck('type')->all());
    }

    /** An image the BOT owns (`fileable_type = 'bot'`) — what the generator produces. */
    private function botFile(Bot $bot): File
    {
        return File::factory()->image()->attachedTo($bot)->create();
    }
}
