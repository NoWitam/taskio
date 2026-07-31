<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Bot\Enums\BotActionType;
use App\Modules\Bot\Models\Bot;
use App\Modules\Bot\Models\BotAction;
use App\Modules\Bot\Services\BotActionService;
use App\Modules\Bot\Services\BotTaskInteractionService;
use App\Modules\Bot\Services\BotToolRegistry;
use App\Modules\Bot\Tools\BotToolContext;
use App\Modules\Bot\Tools\Registry\FetchUrlTool;
use App\Modules\Bot\Tools\Registry\GenerateFileTool;
use App\Modules\Bot\Tools\Registry\ReadAttachmentsTool;
use App\Modules\Bot\Tools\Registry\WebSearchTool;
use App\Modules\Bot\Tools\Support\SafeUrlGuard;
use App\Modules\Disk\Models\File;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Tools\Request as ToolRequest;
use Tests\Concerns\FakesBotExecutionAgent;
use Tests\TestCase;

/**
 * B5: the bot tool registry. Tools are invoked directly (with a real BotToolContext) or
 * through the scripted-run seam; outbound HTTP is faked (Http::fake), storage is faked.
 */
class BotToolRegistryTest extends TestCase
{
    use FakesBotExecutionAgent, RefreshDatabase;

    private function context(User $owner, array $botOverrides = []): BotToolContext
    {
        $bot = Bot::factory()->executesTasks()->create(array_merge(['creator_id' => $owner->id], $botOverrides));
        $task = Task::factory()->create([
            'creator_id' => $owner->id,
            'assignee_type' => 'bot',
            'assignee_id' => $bot->id,
        ]);

        $interaction = app()->makeWith(BotTaskInteractionService::class, ['bot' => $bot, 'task' => $task]);

        return new BotToolContext($bot, $task, $interaction, app(BotActionService::class));
    }

    // --- fetch_url ------------------------------------------------------------

    public function test_fetch_url_strips_html_and_records_tool_used(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $ctx = $this->context($owner);

        Http::fake([
            'example.com/*' => Http::response('<html><head><style>x{}</style></head><body><h1>Hello</h1><script>bad()</script><p>World</p></body></html>', 200),
        ]);

        $tool = new FetchUrlTool($ctx, new SafeUrlGuard);
        $result = (string) $tool->handle(new ToolRequest(['url' => 'https://example.com/page']));

        $this->assertStringContainsString('Hello', $result);
        $this->assertStringContainsString('World', $result);
        $this->assertStringNotContainsString('bad()', $result);
        $this->assertStringNotContainsString('<h1>', $result);

        $action = BotAction::where('type', BotActionType::ToolUsed->value)->firstOrFail();
        $this->assertSame('fetch_url', $action->payload['tool']);
        $this->assertSame('example.com', $action->payload['host']);
    }

    public function test_fetch_url_truncates_to_char_cap(): void
    {
        config(['ai.fetch_max_chars' => 50]);
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $ctx = $this->context($owner);

        Http::fake(['example.com/*' => Http::response('<p>' . str_repeat('word ', 200) . '</p>', 200)]);

        $tool = new FetchUrlTool($ctx, new SafeUrlGuard);
        $result = (string) $tool->handle(new ToolRequest(['url' => 'https://example.com/long']));

        $this->assertLessThanOrEqual(52, mb_strlen($result)); // 50 + ellipsis
    }

    public function test_fetch_url_revalidates_redirect_target(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $ctx = $this->context($owner);

        // The page redirects to a loopback address — the guard must block the target.
        Http::fake([
            'example.com/*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/secret']),
        ]);

        $tool = new FetchUrlTool($ctx, new SafeUrlGuard);
        $result = (string) $tool->handle(new ToolRequest(['url' => 'https://example.com/redirect']));

        $this->assertStringContainsString('Nie udało się pobrać', $result);
        $this->assertDatabaseMissing('bot_actions', ['type' => BotActionType::ToolUsed->value]);
    }

    /** A redirect to another PUBLIC host is re-validated + re-pinned and followed. */
    public function test_fetch_url_follows_public_redirect_across_hops(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $ctx = $this->context($owner);

        Http::fake([
            'example.com/*' => Http::response('', 302, ['Location' => 'https://example.org./final']),
            'example.org/*' => Http::response('<p>final page</p>', 200),
        ]);

        $tool = new FetchUrlTool($ctx, new SafeUrlGuard);
        $result = (string) $tool->handle(new ToolRequest(['url' => 'https://example.com/start']));

        $this->assertStringContainsString('final page', $result);
        $this->assertDatabaseHas('bot_actions', ['type' => BotActionType::ToolUsed->value]);

        // Hop 2 redirects to a TRAILING-DOT host; the re-pin must rewrite it to canonical
        // so the second-hop request carries example.org (no dot) — pin holds per hop.
        Http::assertSent(fn ($request) => parse_url($request->url(), PHP_URL_HOST) === 'example.org');
    }

    /**
     * B3 pinning seam: the validated IP is injected as CURLOPT_RESOLVE (host:port:ip) so
     * cURL connects to exactly what was validated. Http::fake bypasses cURL, so the pin
     * is asserted at the OPTION level here (the option-building step is pure/testable).
     */
    public function test_fetch_url_pin_options_carry_curlopt_resolve(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $ctx = $this->context($owner);

        $tool = new FetchUrlTool($ctx, new SafeUrlGuard);
        $options = $tool->curlPinOptions('example.com', 443, '93.184.216.34', 2048);

        $this->assertSame(['example.com:443:93.184.216.34'], $options[CURLOPT_RESOLVE]);
        $this->assertSame(2048, $options[CURLOPT_MAXFILESIZE]);
    }

    /**
     * G1: the request URL's host is rewritten to the CANONICAL validated host, so the
     * host cURL parses from the URL matches the CURLOPT_RESOLVE pin key. Asserted for the
     * three forms where canonical ≠ original.
     */
    public function test_fetch_url_rewrites_host_to_pin_key(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $ctx = $this->context($owner);
        $tool = new FetchUrlTool($ctx, new SafeUrlGuard);

        // trailing-dot FQDN -> dot stripped
        $this->assertSame(
            'https://example.com/path?q=1',
            $tool->buildPinnedUrl('https://example.com./path?q=1', 'example.com')
        );
        // decimal numeric host -> dotted quad
        $this->assertSame('http://8.8.8.8/', $tool->buildPinnedUrl('http://134744072/', '8.8.8.8'));
        // v4-mapped IPv6 literal -> unwrapped v4 (validated form)
        $this->assertSame('http://8.8.8.8/', $tool->buildPinnedUrl('http://[::ffff:8.8.8.8]/', '8.8.8.8'));
        // explicit port + fragment preserved
        $this->assertSame(
            'http://8.8.8.8:8080/a#frag',
            $tool->buildPinnedUrl('http://134744072:8080/a#frag', '8.8.8.8')
        );
        // ordinary domain unchanged (Host header / vhost unaffected)
        $this->assertSame('https://example.com/x', $tool->buildPinnedUrl('https://example.com/x', 'example.com'));
    }

    /**
     * G1 rebinding regression: a trailing-dot host validated as public must be fetched
     * with the dot-stripped canonical host so the pin applies. The recorded outgoing
     * request URL host has no trailing dot and equals the pin key.
     */
    public function test_fetch_url_trailing_dot_host_requested_as_canonical(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $ctx = $this->context($owner);

        Http::fake(['example.com/*' => Http::response('<p>ok</p>', 200)]);

        $tool = new FetchUrlTool($ctx, new SafeUrlGuard);
        $tool->handle(new ToolRequest(['url' => 'https://example.com./page']));

        Http::assertSent(function ($request) {
            $host = parse_url($request->url(), PHP_URL_HOST);

            // No trailing dot; matches what the pin key would be.
            return $host === 'example.com';
        });

        // The tool_used payload carries the canonical host.
        $action = BotAction::where('type', BotActionType::ToolUsed->value)->firstOrFail();
        $this->assertSame('example.com', $action->payload['host']);
    }

    /**
     * B4 streaming abort: the chunk reader stops once the read exceeds the cap, without
     * materializing the rest — proven with an in-memory PSR stream larger than the cap.
     */
    public function test_fetch_url_stream_reader_aborts_past_cap(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $ctx = $this->context($owner);

        $tool = new FetchUrlTool($ctx, new SafeUrlGuard);

        // 100 KB body, 10 KB cap -> must abort.
        $psr = new \GuzzleHttp\Psr7\Response(200, [], str_repeat('a', 100 * 1024));
        $response = new \Illuminate\Http\Client\Response($psr);

        $this->expectException(\RuntimeException::class);
        $tool->readCappedStream($response, 10 * 1024);
    }

    public function test_fetch_url_stream_reader_returns_body_under_cap(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $ctx = $this->context($owner);

        $tool = new FetchUrlTool($ctx, new SafeUrlGuard);

        $psr = new \GuzzleHttp\Psr7\Response(200, [], 'small body');
        $response = new \Illuminate\Http\Client\Response($psr);

        $this->assertSame('small body', $tool->readCappedStream($response, 1024));
    }

    // --- web_search -----------------------------------------------------------

    public function test_web_search_unavailable_without_key(): void
    {
        config(['ai.search.api_key' => null]);

        $owner = User::factory()->create();
        $this->actingAs($owner);
        $ctx = $this->context($owner, ['task_execution' => ['enabled' => true, 'tools' => ['web_search']]]);

        $registry = app(BotToolRegistry::class);

        $this->assertFalse(collect($registry->catalog())->firstWhere('id', 'web_search')['available']);
        // Even though the bot granted web_search, it is NOT built (unavailable).
        $this->assertSame([], $registry->grantedAvailableIds($ctx->bot));
    }

    public function test_web_search_returns_results_and_never_leaks_key(): void
    {
        config(['ai.search.api_key' => 'secret-key-123', 'ai.search.results' => 5]);

        $owner = User::factory()->create();
        $this->actingAs($owner);
        $ctx = $this->context($owner);

        Http::fake([
            'api.search.brave.com/*' => Http::response([
                'web' => ['results' => [
                    ['title' => 'Result 1', 'url' => 'https://a.example', 'description' => 'snippet 1'],
                    ['title' => 'Result 2', 'url' => 'https://b.example', 'description' => 'snippet 2'],
                ]],
            ], 200),
        ]);

        $tool = app()->makeWith(WebSearchTool::class, [
            'ctx' => $ctx,
            'provider' => app(\App\Modules\Bot\Tools\Support\SearchProvider::class),
        ]);
        $result = (string) $tool->handle(new ToolRequest(['query' => 'laravel tips']));

        $this->assertStringContainsString('Result 1', $result);
        $this->assertStringContainsString('https://a.example', $result);

        $action = BotAction::where('type', BotActionType::ToolUsed->value)->firstOrFail();
        $this->assertSame('web_search', $action->payload['tool']);
        $this->assertSame('laravel tips', $action->payload['query']);
        // The API key must NEVER appear in the audit payload.
        $this->assertStringNotContainsString('secret-key-123', json_encode($action->payload));
    }

    // --- generate_file --------------------------------------------------------

    public function test_generate_file_attaches_to_task(): void
    {
        Storage::fake();
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $ctx = $this->context($owner);

        $tool = new GenerateFileTool($ctx);
        $result = (string) $tool->handle(new ToolRequest(['name' => 'report.md', 'content' => '# Title']));

        $this->assertStringContainsString('report.md', $result);
        $file = File::where('fileable_id', $ctx->task->id)->firstOrFail();
        $this->assertSame('report.md', $file->name);
        $this->assertSame($owner->id, $file->uploader_id);
        Storage::assertExists($file->path);

        $this->assertDatabaseHas('bot_actions', ['type' => BotActionType::ToolUsed->value]);
    }

    public function test_generate_file_rejects_unsafe_extension(): void
    {
        Storage::fake();
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $ctx = $this->context($owner);

        $tool = new GenerateFileTool($ctx);
        $result = (string) $tool->handle(new ToolRequest(['name' => 'evil.php', 'content' => '<?php echo 1;']));

        $this->assertStringContainsString('Niedozwolone rozszerzenie', $result);
        $this->assertDatabaseCount('files', 0);
    }

    public function test_generate_file_sanitizes_filename(): void
    {
        Storage::fake();
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $ctx = $this->context($owner);

        $tool = new GenerateFileTool($ctx);
        $tool->handle(new ToolRequest(['name' => '../../etc/pa ss..wd.txt', 'content' => 'x']));

        $file = File::where('fileable_id', $ctx->task->id)->firstOrFail();
        $this->assertStringNotContainsString('/', $file->name);
        $this->assertStringNotContainsString('..', $file->name);
        $this->assertStringEndsWith('.txt', $file->name);
    }

    // --- read_attachments -----------------------------------------------------

    public function test_read_attachments_reads_own_text_file(): void
    {
        Storage::fake();
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $ctx = $this->context($owner);

        Storage::put('uploads/note.txt', 'attachment body');
        $ctx->task->files()->create([
            'name' => 'note.txt', 'path' => 'uploads/note.txt', 'type' => 'document',
            'mime_type' => 'text/plain', 'size' => 15, 'uploader_id' => $owner->id,
        ]);

        $tool = new ReadAttachmentsTool($ctx);
        $result = (string) $tool->handle(new ToolRequest(['name' => 'note.txt']));

        $this->assertSame('attachment body', $result);
    }

    public function test_read_attachments_rejects_binary(): void
    {
        Storage::fake();
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $ctx = $this->context($owner);

        $ctx->task->files()->create([
            'name' => 'image.png', 'path' => 'uploads/image.png', 'type' => 'image',
            'mime_type' => 'image/png', 'size' => 100, 'uploader_id' => $owner->id,
        ]);

        $tool = new ReadAttachmentsTool($ctx);
        $result = (string) $tool->handle(new ToolRequest(['name' => 'image.png']));

        $this->assertStringContainsString('nie jest tekstowy', $result);
    }

    public function test_read_attachments_cannot_reach_another_task(): void
    {
        Storage::fake();
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $ctx = $this->context($owner);

        // A file belonging to a DIFFERENT task must be unreachable.
        $otherTask = Task::factory()->create(['creator_id' => $owner->id]);
        $otherTask->files()->create([
            'name' => 'secret.txt', 'path' => 'uploads/secret.txt', 'type' => 'document',
            'mime_type' => 'text/plain', 'size' => 10, 'uploader_id' => $owner->id,
        ]);

        $tool = new ReadAttachmentsTool($ctx);
        $result = (string) $tool->handle(new ToolRequest(['name' => 'secret.txt']));

        $this->assertStringContainsString('Nie znaleziono', $result);
    }

    /** S3: content that is not valid text (NUL bytes) is rejected even with a .txt name. */
    public function test_read_attachments_rejects_binary_content_despite_text_extension(): void
    {
        Storage::fake();
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $ctx = $this->context($owner);

        Storage::put('uploads/fake.txt', "PK\x03\x04\x00\x00binary\x00payload");
        $ctx->task->files()->create([
            'name' => 'fake.txt', 'path' => 'uploads/fake.txt', 'type' => 'document',
            'mime_type' => 'text/plain', 'size' => 20, 'uploader_id' => $owner->id,
        ]);

        $tool = new ReadAttachmentsTool($ctx);
        $result = (string) $tool->handle(new ToolRequest(['name' => 'fake.txt']));

        $this->assertStringContainsString('nie jest tekstowy', $result);
    }

    /** S2: duplicate display names read the NEWEST and note the ambiguity. */
    public function test_read_attachments_ambiguous_name_reads_newest(): void
    {
        Storage::fake();
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $ctx = $this->context($owner);

        Storage::put('uploads/old.txt', 'OLD');
        Storage::put('uploads/new.txt', 'NEW');
        $old = $ctx->task->files()->create([
            'name' => 'dup.txt', 'path' => 'uploads/old.txt', 'type' => 'document',
            'mime_type' => 'text/plain', 'size' => 3, 'uploader_id' => $owner->id,
        ]);
        $old->forceFill(['created_at' => now()->subHour()])->save();

        $new = $ctx->task->files()->create([
            'name' => 'dup.txt', 'path' => 'uploads/new.txt', 'type' => 'document',
            'mime_type' => 'text/plain', 'size' => 3, 'uploader_id' => $owner->id,
        ]);
        $new->forceFill(['created_at' => now()])->save();

        $tool = new ReadAttachmentsTool($ctx);
        $result = (string) $tool->handle(new ToolRequest(['name' => 'dup.txt']));

        $this->assertStringContainsString('NEW', $result);
        $this->assertStringContainsString('najnowszy', $result);
    }

    // --- endpoint + validation ------------------------------------------------

    public function test_registry_endpoint_returns_ids_and_availability(): void
    {
        config(['ai.search.api_key' => null]);
        $owner = User::factory()->create();

        $response = $this->actingAs($owner)->getJson('/api/bots/tool-registry')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing(
            ['fetch_url', 'web_search', 'generate_file', 'read_attachments'],
            $ids
        );
        $this->assertFalse(collect($response->json('data'))->firstWhere('id', 'web_search')['available']);
        $this->assertTrue(collect($response->json('data'))->firstWhere('id', 'fetch_url')['available']);
    }

    public function test_registry_endpoint_requires_auth(): void
    {
        $this->getJson('/api/bots/tool-registry')->assertUnauthorized();
    }

    public function test_store_rejects_unknown_tool_id(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->postJson('/api/bots', [
            'name' => 'Bot', 'persona' => 'p',
            'task_execution' => ['enabled' => true, 'tools' => ['not_a_real_tool']],
        ])->assertUnprocessable()->assertJsonValidationErrors(['task_execution.tools.0']);
    }

    public function test_store_accepts_known_tool_id(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->postJson('/api/bots', [
            'name' => 'Bot', 'persona' => 'p',
            'task_execution' => ['enabled' => true, 'tools' => ['fetch_url', 'generate_file']],
        ])->assertCreated();
    }

    // --- end-to-end scripted run ---------------------------------------------

    public function test_bot_granted_fetch_url_uses_it_mid_run_then_finishes(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $bot = Bot::factory()->executesTasks()->create([
            'creator_id' => $owner->id,
            'task_execution' => ['enabled' => true, 'tools' => ['fetch_url']],
        ]);

        Http::fake(['example.com/*' => Http::response('<p>page text</p>', 200)]);

        $this->scriptBotRun([
            ['fetch_url', ['url' => 'https://example.com/research']],
            ['post_comment', ['text' => 'Based on research…']],
            ['finish'],
        ]);

        $taskId = $this->postJson('/api/tasks', [
            'title' => 'Research task', 'priority' => 'medium',
            'assignee_type' => 'bot', 'assignee_id' => $bot->id,
        ])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('bot_actions', [
            'task_id' => $taskId, 'type' => BotActionType::ToolUsed->value,
        ]);
        // No approval pipeline on this task, so finish completes it outright.
        $this->assertDatabaseHas('bot_actions', [
            'task_id' => $taskId, 'type' => BotActionType::MarkedDone->value,
        ]);
    }
}
