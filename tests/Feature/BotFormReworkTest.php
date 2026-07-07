<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Bot\Enums\BotStatus;
use App\Modules\Bot\Models\Bot;
use App\Modules\Bot\Services\BotTaskContextBuilder;
use App\Modules\Bot\Services\BotTaskInteractionService;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bot-form rework: status collapse (active|inactive, default inactive, dedicated toggle
 * endpoint) + richer dictionary/phrases with legacy-string tolerance + prompt rendering.
 */
class BotFormReworkTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge(['name' => 'Bot', 'persona' => 'A helpful assistant.'], $overrides);
    }

    // --- status: collapse + create default + toggle endpoint ------------------

    public function test_bot_is_created_inactive_and_status_body_is_ignored(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/bots', $this->payload(['status' => 'active']))
            ->assertCreated()
            ->assertJsonPath('data.status', 'inactive');

        $this->assertSame(BotStatus::INACTIVE, Bot::firstWhere('name', 'Bot')->status);
    }

    public function test_update_does_not_change_status(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->active()->create(['creator_id' => $user->id]);

        $this->actingAs($user)
            ->putJson("/api/bots/{$bot->id}", $this->payload(['status' => 'inactive', 'name' => 'Renamed']))
            ->assertOk()
            ->assertJsonPath('data.status', 'active') // unchanged by update
            ->assertJsonPath('data.name', 'Renamed');
    }

    public function test_status_endpoint_toggles_active_and_inactive(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['creator_id' => $user->id]); // inactive

        $this->actingAs($user)
            ->patchJson("/api/bots/{$bot->id}/status", ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
        $this->assertSame(BotStatus::ACTIVE, $bot->fresh()->status);

        $this->actingAs($user)
            ->patchJson("/api/bots/{$bot->id}/status", ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');
    }

    public function test_status_endpoint_is_creator_only(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $bot = Bot::factory()->create(['creator_id' => $owner->id]);

        $this->actingAs($stranger)
            ->patchJson("/api/bots/{$bot->id}/status", ['status' => 'active'])
            ->assertForbidden();

        $this->assertSame(BotStatus::INACTIVE, $bot->fresh()->status);
    }

    public function test_status_endpoint_rejects_invalid_value(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['creator_id' => $user->id]);

        $this->actingAs($user)
            ->patchJson("/api/bots/{$bot->id}/status", ['status' => 'draft'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_can_execute_tasks_only_when_active(): void
    {
        $user = User::factory()->create();
        $inactive = Bot::factory()->create([
            'creator_id' => $user->id,
            'task_execution' => ['enabled' => true, 'tools' => []],
        ]);
        $active = Bot::factory()->active()->create([
            'creator_id' => $user->id,
            'task_execution' => ['enabled' => true, 'tools' => []],
        ]);

        $this->assertFalse($inactive->canExecuteTasks());
        $this->assertTrue($active->canExecuteTasks());
    }

    // --- text module: dictionary + phrases new shapes -------------------------

    public function test_dictionary_and_phrases_round_trip_in_object_shape(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/bots', $this->payload([
            'dictionary' => [['term' => 'CTA', 'meaning' => 'wezwanie do działania']],
            'phrases' => [['phrase' => 'Zostań z nami!', 'context' => 'zakończenie posta']],
        ]))->assertCreated();

        $response->assertJsonPath('data.dictionary.0.term', 'CTA');
        $response->assertJsonPath('data.dictionary.0.meaning', 'wezwanie do działania');
        $response->assertJsonPath('data.phrases.0.phrase', 'Zostań z nami!');
        $response->assertJsonPath('data.phrases.0.context', 'zakończenie posta');
    }

    public function test_dictionary_entry_requires_term_and_meaning(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/bots', $this->payload([
            'dictionary' => [['meaning' => 'no term']],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['dictionary.0.term']);

        $this->actingAs($user)->postJson('/api/bots', $this->payload([
            'dictionary' => [['term' => 'no meaning']],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['dictionary.0.meaning']);
    }

    public function test_phrase_entry_requires_phrase_context_optional(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/bots', $this->payload([
            'phrases' => [['context' => 'no phrase']],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['phrases.0.phrase']);

        // context omitted is valid
        $this->actingAs($user)->postJson('/api/bots', $this->payload([
            'phrases' => [['phrase' => 'Hej!']],
        ]))->assertCreated()->assertJsonPath('data.phrases.0.context', null);
    }

    public function test_legacy_bare_string_dictionary_and_phrases_read_back_normalized(): void
    {
        $user = User::factory()->create();
        // A bot stored with the OLD bare-string shape directly on the column.
        $bot = Bot::factory()->create([
            'creator_id' => $user->id,
            'dictionary' => ['engagement', 'reach'],
            'phrases' => ['Stay tuned!'],
        ]);

        $response = $this->actingAs($user)->getJson("/api/bots/{$bot->id}")->assertOk();

        $response->assertJsonPath('data.dictionary.0.term', 'engagement');
        $response->assertJsonPath('data.dictionary.0.meaning', '');
        $response->assertJsonPath('data.phrases.0.phrase', 'Stay tuned!');
        $response->assertJsonPath('data.phrases.0.context', null);
    }

    // --- agent prompt rendering ----------------------------------------------

    public function test_execution_agent_renders_dictionary_and_phrases(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $bot = Bot::factory()->executesTasks()->create([
            'creator_id' => $user->id,
            'dictionary' => [['term' => 'gwara', 'meaning' => 'język grupy']],
            'phrases' => [['phrase' => 'Lecimy!', 'context' => 'na start']],
        ]);
        $task = Task::factory()->create(['creator_id' => $user->id, 'assignee_type' => 'bot', 'assignee_id' => $bot->id]);

        $interaction = app()->makeWith(BotTaskInteractionService::class, ['bot' => $bot, 'task' => $task]);
        $context = app(BotTaskContextBuilder::class)->build($task, $bot);
        $agent = new \App\Modules\Bot\Agents\BotTaskExecutionAgent($bot, $task, $context, $interaction);

        $instructions = (string) $agent->instructions();

        $this->assertStringContainsString('gwara — język grupy', $instructions);
        $this->assertStringContainsString('Lecimy! (kontekst: na start)', $instructions);
    }
}
