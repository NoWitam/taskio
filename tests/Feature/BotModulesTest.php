<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Bot\Models\Bot;
use App\Modules\Bot\Services\BotTaskContextBuilder;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B6: the bot's 5-module restructure — the real KNOWLEDGE module (persisted + injected
 * into the execution context), the voice -> audio placeholder rename, and the removal of
 * the dead knowledge_source config.
 */
class BotModulesTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Knowledge Bot',
            'persona' => 'A helpful assistant.',
        ], $overrides);
    }

    // --- knowledge: CRUD ------------------------------------------------------

    public function test_creates_and_returns_knowledge_entries(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/bots', $this->payload([
            'knowledge' => [
                'enabled' => true,
                'entries' => [
                    ['title' => 'Brand voice', 'content' => 'Always upbeat and concise.'],
                    ['title' => 'Publishing rules', 'content' => 'No posts on weekends.'],
                ],
            ],
        ]))->assertCreated();

        $response->assertJsonPath('data.knowledge.enabled', true);
        $response->assertJsonPath('data.knowledge.entries.0.title', 'Brand voice');
        $response->assertJsonPath('data.knowledge.entries.0.content', 'Always upbeat and concise.');
        $response->assertJsonPath('data.knowledge.entries.1.title', 'Publishing rules');

        $bot = Bot::firstWhere('name', 'Knowledge Bot');
        $this->assertTrue($bot->knowledgeEnabled());
        $this->assertCount(2, $bot->knowledgeEntries());
    }

    public function test_updates_knowledge_entries(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['creator_id' => $user->id]);

        $this->actingAs($user)->putJson("/api/bots/{$bot->id}", $this->payload([
            'knowledge' => ['enabled' => true, 'entries' => [['title' => 'New fact', 'content' => 'Updated content.']]],
        ]))->assertOk()->assertJsonPath('data.knowledge.entries.0.title', 'New fact');

        $this->assertSame(
            ['enabled' => true, 'entries' => [['title' => 'New fact', 'content' => 'Updated content.']]],
            $bot->fresh()->knowledge,
        );
    }

    public function test_disabled_knowledge_module_is_not_injected_even_with_entries(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        // Entries present but the module is OFF → nothing injected.
        $bot = Bot::factory()->withKnowledge(
            [['title' => 'Secret', 'content' => 'Do not use me.']],
            enabled: false,
        )->create(['creator_id' => $user->id]);
        $task = Task::factory()->create(['creator_id' => $user->id, 'assignee_type' => 'bot', 'assignee_id' => $bot->id]);

        $context = app(BotTaskContextBuilder::class)->build($task, $bot);

        $this->assertStringNotContainsString('WIEDZA BOTA:', $context);
        $this->assertStringNotContainsString('Do not use me.', $context);
    }

    // --- knowledge: validation ------------------------------------------------

    public function test_knowledge_entry_requires_title_and_content(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/bots', $this->payload([
            'knowledge' => ['enabled' => true, 'entries' => [['content' => 'no title']]],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['knowledge.entries.0.title']);

        $this->actingAs($user)->postJson('/api/bots', $this->payload([
            'knowledge' => ['enabled' => true, 'entries' => [['title' => 'no content']]],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['knowledge.entries.0.content']);
    }

    public function test_knowledge_entry_count_is_capped(): void
    {
        $user = User::factory()->create();

        $entries = array_fill(0, 51, ['title' => 't', 'content' => 'c']);

        $this->actingAs($user)->postJson('/api/bots', $this->payload([
            'knowledge' => ['enabled' => true, 'entries' => $entries],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['knowledge.entries']);
    }

    // --- knowledge: context injection ----------------------------------------

    public function test_knowledge_entries_are_injected_into_execution_context(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $bot = Bot::factory()->withKnowledge([
            ['title' => 'Ton marki', 'content' => 'Zawsze rzeczowo i konkretnie.'],
        ])->create(['creator_id' => $user->id]);
        $task = Task::factory()->create(['creator_id' => $user->id, 'assignee_type' => 'bot', 'assignee_id' => $bot->id]);

        $context = app(BotTaskContextBuilder::class)->build($task, $bot);

        $this->assertStringContainsString('WIEDZA BOTA:', $context);
        $this->assertStringContainsString('Ton marki', $context);
        $this->assertStringContainsString('Zawsze rzeczowo i konkretnie.', $context);
    }

    public function test_no_knowledge_omits_the_section(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $bot = Bot::factory()->create(['creator_id' => $user->id]); // no knowledge
        $task = Task::factory()->create(['creator_id' => $user->id, 'assignee_type' => 'bot', 'assignee_id' => $bot->id]);

        $context = app(BotTaskContextBuilder::class)->build($task, $bot);

        $this->assertStringNotContainsString('WIEDZA BOTA:', $context);
    }

    public function test_knowledge_injection_respects_char_cap(): void
    {
        config(['ai.knowledge_max_chars' => 60]);

        $user = User::factory()->create();
        $this->actingAs($user);
        $bot = Bot::factory()->withKnowledge([
            ['title' => 'Entry one', 'content' => str_repeat('a', 100)],
            ['title' => 'Entry two', 'content' => str_repeat('b', 100)],
        ])->create(['creator_id' => $user->id]);
        $task = Task::factory()->create(['creator_id' => $user->id, 'assignee_type' => 'bot', 'assignee_id' => $bot->id]);

        $context = app(BotTaskContextBuilder::class)->build($task, $bot);

        // The second entry is beyond the cap and is replaced by the truncation marker.
        $this->assertStringContainsString('pominięto część wiedzy', $context);
        $this->assertStringNotContainsString(str_repeat('b', 100), $context);
    }

    // --- general info: icon ---------------------------------------------------

    public function test_icon_round_trips_as_general_info(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/bots', $this->payload([
            'icon' => 'sparkles',
        ]))->assertCreated()->assertJsonPath('data.icon', 'sparkles');

        $this->assertSame('sparkles', Bot::firstWhere('name', 'Knowledge Bot')->icon);
    }

    // --- audio (renamed placeholder) -----------------------------------------

    public function test_bot_resource_exposes_audio_not_voice(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['creator_id' => $user->id]);

        $response = $this->actingAs($user)->getJson("/api/bots/{$bot->id}")->assertOk();

        $data = $response->json('data');
        $this->assertArrayHasKey('audio', $data);
        $this->assertArrayNotHasKey('voice', $data);
        $this->assertNull($data['audio']);
    }

    public function test_audio_column_round_trips(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['creator_id' => $user->id, 'audio' => null]);

        $this->assertNull($bot->fresh()->audio);
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('bots', 'audio'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('bots', 'voice'));
    }
}
