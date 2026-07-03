<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Bot\Models\Bot;
use App\Modules\Comments\DTOs\CommentDTO;
use App\Modules\Comments\Services\CommentService;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase A regression: a comment author became polymorphic (User|Bot). Humans still
 * post as themselves over HTTP; bots author comments via CommentService::create with
 * a bot author DTO. The resource keeps the legacy id/name/email shape.
 */
class CommentPolymorphicAuthorTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_comment_renders_user_author(): void
    {
        $user = User::factory()->create();
        $task = Task::factory()->create(['creator_id' => $user->id, 'assignee_id' => $user->id, 'assignee_type' => 'user']);

        $this->actingAs($user)
            ->postJson("/api/tasks/{$task->id}/comments", ['content' => 'human comment'])
            ->assertCreated()
            ->assertJsonPath('data.author.id', $user->id)
            ->assertJsonPath('data.author.email', $user->email)
            ->assertJsonPath('data.author.type', 'user')
            ->assertJsonPath('data.author.is_bot', false);
    }

    public function test_bot_authored_comment_renders_bot_author(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['creator_id' => $user->id]);
        $task = Task::factory()->create(['creator_id' => $user->id, 'assignee_id' => $user->id, 'assignee_type' => 'user']);

        $comment = app(CommentService::class)->create(
            $task,
            CommentDTO::fromBot($bot, 'bot generated result')
        );

        $this->assertSame('bot', $comment->author_type);
        $this->assertSame($bot->id, $comment->author_id);

        $response = $this->actingAs($user)
            ->getJson("/api/tasks/{$task->id}/comments")
            ->assertOk();

        $response->assertJsonPath('data.0.author.type', 'bot');
        $response->assertJsonPath('data.0.author.id', $bot->id);
        $response->assertJsonPath('data.0.author.name', $bot->name);
        $response->assertJsonPath('data.0.author.email', null);
        $response->assertJsonPath('data.0.author.is_bot', true);
    }

    public function test_http_comment_cannot_forge_a_bot_author(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['creator_id' => $user->id]);
        $task = Task::factory()->create(['creator_id' => $user->id, 'assignee_id' => $user->id, 'assignee_type' => 'user']);

        // Even if a client injects author_type/author_id, the HTTP path must author the
        // comment as the authenticated user — only the internal job path (fromBot) can
        // create a bot-authored comment.
        $this->actingAs($user)
            ->postJson("/api/tasks/{$task->id}/comments", [
                'content' => 'sneaky',
                'author_type' => 'bot',
                'author_id' => $bot->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.author.type', 'user')
            ->assertJsonPath('data.author.id', $user->id)
            ->assertJsonPath('data.author.is_bot', false);
    }
}
