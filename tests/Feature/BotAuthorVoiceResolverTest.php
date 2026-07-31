<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Bot\Models\Bot;
use App\Modules\Bot\Services\BotAuthorVoiceResolver;
use App\Modules\Bot\Services\BotVoiceComposer;
use App\Modules\Variables\Support\AiVoiceContext;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The Bot-side {@see BotAuthorVoiceResolver} — the concrete behind the Variables AuthorVoiceResolver seam
 * that turns an `@[ai-text]` block's AUTHOR id into a bot's opaque voice.
 *
 * The properties pinned here are the ones a per-block author makes dangerous if they slip:
 *   - the TENANT boundary holds from the EXPLICIT workspace id, WITHOUT an ambient tenant context — the
 *     queue posture, where WorkspaceScope is a documented no-op and an ambient-only lookup would happily
 *     resolve another workspace's bot voice;
 *   - a malformed id is refused CLEANLY (dropped before the query) rather than raising a uuid cast error;
 *   - many authors cost exactly ONE query (a per-block author must never become an N+1);
 *   - anything unresolvable is simply ABSENT (fail-SAFE), never null and never an exception.
 */
class BotAuthorVoiceResolverTest extends TestCase
{
    use RefreshDatabase;

    private function workspaceFor(User $user): Workspace
    {
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $workspace->users()->attach($user->id);

        return $workspace;
    }

    /** Build inside $workspace as the ACTIVE shared tenant, so created rows are stamped with its id. */
    private function within(Workspace $workspace, Closure $build)
    {
        $context = app(TenantContext::class);
        $context->set($workspace);

        try {
            return $build();
        } finally {
            $context->clear();
        }
    }

    private function resolver(): BotAuthorVoiceResolver
    {
        return app(BotAuthorVoiceResolver::class);
    }

    /** Run $callback with NO active tenant context (a queued run) and count the queries it issues. */
    private function countQueries(Closure $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $callback();

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    public function test_it_composes_the_voice_of_a_bot_in_the_given_workspace(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);

        $bot = $this->within($workspace, fn () => Bot::factory()->create([
            'creator_id' => $user->id,
            'persona' => 'A blunt product engineer.',
            'style' => 'Short sentences.',
        ]));

        $voices = $this->resolver()->voicesFor([$bot->id], $workspace->id);

        $this->assertSame([$bot->id], array_keys($voices));
        $this->assertSame(
            app(BotVoiceComposer::class)->compose($bot),
            $voices[$bot->id],
            'the author voice must be the SAME composed directive a delegated session uses (no fork)',
        );
    }

    /**
     * THE isolation pin. With NO active workspace — exactly how a queued generation run executes — the
     * global WorkspaceScope adds nothing, so only the EXPLICIT predicate stands between a recipe and a
     * foreign bot's authored voice.
     */
    public function test_a_bot_from_another_workspace_never_reaches_the_map(): void
    {
        $userA = User::factory()->create();
        $workspaceA = $this->workspaceFor($userA);
        $userB = User::factory()->create();
        $workspaceB = $this->workspaceFor($userB);

        $botA = $this->within($workspaceA, fn () => Bot::factory()->create(['creator_id' => $userA->id]));
        $botB = $this->within($workspaceB, fn () => Bot::factory()->create(['creator_id' => $userB->id]));

        $this->assertFalse(app(TenantContext::class)->isShared(), 'the queue posture: no ambient workspace');

        $voices = $this->resolver()->voicesFor([$botA->id, $botB->id], $workspaceA->id);

        $this->assertSame([$botA->id], array_keys($voices));
        $this->assertArrayNotHasKey($botB->id, $voices);

        // And the ambient scope, when it IS active, can only narrow further — never widen.
        $narrowed = $this->within($workspaceA, fn () => $this->resolver()->voicesFor([$botA->id, $botB->id], $workspaceA->id));
        $this->assertSame([$botA->id], array_keys($narrowed));
    }

    /**
     * THE hardening pin. "No workspace id" is legitimate ONLY in own-database mode, where the dedicated
     * connection is the boundary. In shared mode (or with no tenancy at all — a queued job, a console
     * command) it would mean a lookup with NO tenant predicate: EVERY workspace's bots. The resolver refuses
     * outright and hands back an empty map, so a caller that forgets to pin the workspace loses the authored
     * TONE and can never read a foreign voice. The refusal lives in the resolver, not in each caller.
     */
    public function test_a_missing_workspace_is_refused_outright_unless_the_tenant_is_own_database(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);
        $bot = $this->within($workspace, fn () => Bot::factory()->create(['creator_id' => $user->id]));

        // No tenancy at all (the queue posture) — an unpinned lookup is refused, and never reaches the DB.
        $this->assertFalse(app(TenantContext::class)->isShared());
        $this->assertSame(0, $this->countQueries(function () use ($bot) {
            $this->assertSame([], $this->resolver()->voicesFor([$bot->id], null));
            $this->assertSame([], $this->resolver()->voicesFor([$bot->id], ''));
        }));

        // A SHARED workspace being active changes nothing: the ambient scope is not a substitute for the
        // explicit pin (it is a documented no-op the moment the same code runs on a worker).
        $this->within($workspace, function () use ($bot) {
            $this->assertSame([], $this->resolver()->voicesFor([$bot->id], null));
        });

        // Pinned explicitly, the very same call resolves — so the refusal is about the MISSING id, not the id.
        $this->assertSame([$bot->id], array_keys($this->resolver()->voicesFor([$bot->id], $workspace->id)));
    }

    public function test_malformed_ids_are_dropped_before_the_query_and_never_break_it(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);
        $bot = $this->within($workspace, fn () => Bot::factory()->create(['creator_id' => $user->id]));

        $voices = $this->resolver()->voicesFor(
            ['not-a-uuid', '', '  ', '00000000-0000-0000-0000', $bot->id],
            $workspace->id,
        );

        $this->assertSame([$bot->id], array_keys($voices));

        // An ALL-garbage list must not even reach the database (nothing could ever match a uuid key).
        $this->assertSame(0, $this->countQueries(function () use ($workspace) {
            $this->assertSame([], $this->resolver()->voicesFor(['nope', 'still-nope'], $workspace->id));
            $this->assertSame([], $this->resolver()->voicesFor([], $workspace->id));
        }));
    }

    /**
     * THE spelling pin. A uuid column matches CASE-INSENSITIVELY, so an UPPERCASE author id (reachable
     * through an API-created template or an import) resolves a row and IS PAID FOR — and then, if the map
     * came back keyed the way the DATABASE echoes the id rather than the way the CALLER spelled it, misses
     * in {@see AiVoiceContext::effectiveDirective}, which looks the id up exactly as its directive spells it.
     * A paid query whose voice is silently thrown away is the worst of both worlds.
     */
    public function test_an_author_id_in_a_different_case_is_keyed_the_way_the_caller_spelled_it(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);
        $bot = $this->within($workspace, fn () => Bot::factory()->create(['creator_id' => $user->id]));

        $upper = strtoupper((string) $bot->id);
        $this->assertNotSame($upper, $bot->id, 'the fixture must actually differ in case');

        $voices = $this->resolver()->voicesFor([$upper], $workspace->id);

        $this->assertSame([$upper], array_keys($voices));
        $this->assertSame(
            app(BotVoiceComposer::class)->compose($bot),
            $voices[$upper] ?? null,
        );

        // And the voice must actually reach the block: this is the exact lookup the ai-text seam performs.
        $context = app(AiVoiceContext::class);
        $context->setAuthorVoices($voices);

        try {
            $this->assertNotNull($context->effectiveDirective($upper), 'the block must find the voice it paid for');
        } finally {
            $context->clear();
        }
    }

    /**
     * Two blocks may name the SAME author in DIFFERENT case. Both spellings must be voiced (each block looks
     * itself up by its own bytes), and it must still cost ONE query.
     */
    public function test_the_same_author_spelled_two_ways_voices_both_spellings_in_one_query(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);
        $bot = $this->within($workspace, fn () => Bot::factory()->create(['creator_id' => $user->id]));

        $lower = (string) $bot->id;
        $upper = strtoupper($lower);

        $voices = null;
        $queries = $this->countQueries(function () use (&$voices, $lower, $upper, $workspace) {
            $voices = $this->resolver()->voicesFor([$lower, $upper], $workspace->id);
        });

        $this->assertSame(1, $queries);
        $this->assertEqualsCanonicalizing([$lower, $upper], array_keys($voices));
        $this->assertSame($voices[$lower], $voices[$upper]);
    }

    public function test_many_authors_cost_exactly_one_query(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);

        $bots = $this->within($workspace, fn () => Bot::factory()->count(4)->create(['creator_id' => $user->id]));
        $ids = $bots->pluck('id')->all();

        $voices = null;
        $queries = $this->countQueries(function () use (&$voices, $ids, $workspace) {
            // Duplicated + unknown ids ride along: de-duplication happens before the single lookup.
            $voices = $this->resolver()->voicesFor(
                array_merge($ids, [$ids[0], (string) Str::uuid()]),
                $workspace->id,
            );
        });

        $this->assertSame(1, $queries, 'a per-block author must never turn into an N+1');
        $this->assertEqualsCanonicalizing($ids, array_keys($voices));
    }

    public function test_an_unknown_or_deleted_author_is_simply_absent(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);

        $kept = $this->within($workspace, fn () => Bot::factory()->create(['creator_id' => $user->id]));
        $deleted = $this->within($workspace, fn () => Bot::factory()->create(['creator_id' => $user->id]));
        $this->within($workspace, fn () => $deleted->delete());

        $voices = $this->resolver()->voicesFor(
            [$kept->id, $deleted->id, (string) Str::uuid()],
            $workspace->id,
        );

        $this->assertSame([$kept->id], array_keys($voices));
        $this->assertArrayNotHasKey($deleted->id, $voices, 'a deleted author must degrade the tone, not the run');
    }
}
