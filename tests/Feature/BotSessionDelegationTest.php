<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Bot\Agents\BotSlotFillAgent;
use App\Modules\Bot\Models\Bot;
use App\Modules\Generator\Enums\GenerationSessionStatus;
use App\Modules\Generator\Jobs\RunGenerationSessionJob;
use App\Modules\Generator\Models\GenerationSession;
use App\Modules\Generator\Services\GenerationSessionExecutor;
use App\Modules\Generator\Services\GenerationSessionRunManager;
use App\Modules\Generator\Services\SessionDelegationService;
use App\Modules\Generator\Services\TemplateVariableCatalog;
use App\Modules\Variables\Agents\AiTextAgent;
use App\Modules\Variables\Models\AiUsageEvent;
use App\Modules\Variables\Services\AiTextGenerationService;
use App\Modules\Variables\Services\VariableCatalog;
use App\Modules\Variables\Support\AiVoiceContext;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Bots in the generator (R2 sub-stage 3): delegating an editable session to a bot — the SNAPSHOTTED voice +
 * author overlay, the autonomous, re-validated slot-fill + fill report, reversibility (undo / re-delegate),
 * ownership, the auto_generate opt-in, the state guards, the metering, and the leak-proof voice run.
 *
 * Setup mirrors GenerationSessionGenerateTest: a real workspace + active tenancy, so the delegate flow's
 * meter (the real LedgerMeteredAiCall) records the session-tagged row the metering assertion reads.
 */
class BotSessionDelegationTest extends TestCase
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

        // Creative-direction layer OFF: these cases pin delegation, which predates it, and an un-scripted
        // derivation would be a REAL provider call. The layer is covered by CreativeDirectionTest.
        config()->set('generator.direction.enabled', false);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    // ---- helpers ---------------------------------------------------------------

    private function bot(array $overrides = []): Bot
    {
        return Bot::factory()->create($overrides + ['creator_id' => $this->user->id, 'icon' => 'robot']);
    }

    /** The typed slot matrix a bot may fill (+ a required FILE slot that is never bot-fillable). */
    private function richSlots(): array
    {
        return [
            ['name' => 'topic', 'description' => 'The subject', 'descriptor' => ['base' => 'text', 'nullable' => false, 'array' => false]],
            ['name' => 'count', 'description' => 'How many', 'descriptor' => ['base' => 'number', 'nullable' => true, 'array' => false]],
            ['name' => 'active', 'description' => 'On?', 'descriptor' => ['base' => 'boolean', 'nullable' => true, 'array' => false]],
            ['name' => 'when', 'description' => 'A date', 'descriptor' => ['base' => 'date', 'nullable' => true, 'array' => false]],
            ['name' => 'tone', 'description' => 'Tone', 'descriptor' => ['base' => 'enum', 'nullable' => true, 'array' => false, 'options' => [['key' => 'casual'], ['key' => 'formal']]]],
            ['name' => 'tags', 'description' => 'Tags', 'descriptor' => ['base' => 'text', 'nullable' => true, 'array' => true]],
            ['name' => 'meta', 'description' => 'Meta', 'descriptor' => ['base' => 'object', 'nullable' => true, 'array' => false, 'fields' => [
                ['key' => 'a', 'descriptor' => ['base' => 'text']],
                ['key' => 'b', 'descriptor' => ['base' => 'number']],
            ]]],
            ['name' => 'attachment', 'description' => 'A file', 'descriptor' => ['base' => 'file', 'nullable' => false, 'fields' => [
                ['key' => 'id', 'descriptor' => ['base' => 'text']],
                ['key' => 'name', 'descriptor' => ['base' => 'text']],
                ['key' => 'type', 'descriptor' => ['base' => 'text']],
                ['key' => 'size', 'descriptor' => ['base' => 'number']],
                ['key' => 'url', 'descriptor' => ['base' => 'text']],
            ]]],
        ];
    }

    private function richSession(array $slotValues = [], ?string $creatorId = null): GenerationSession
    {
        return GenerationSession::factory()
            ->snapshot('post', ['body' => ['markdown' => 'Write a post about topic.']], $this->richSlots(), $slotValues)
            ->create(['creator_id' => $creatorId ?? $this->user->id]);
    }

    private function delegate(string $botId, string $sessionId, array $body = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/bots/{$botId}/sessions/{$sessionId}/delegate", $body);
    }

    /** Every USER message the slot-fill agent was prompted with, in order (the scripted seam records them). */
    private array $prompts = [];

    /**
     * Script the slot-fill seam AND capture the prompt it was handed. NEVER a real provider — the fake gateway
     * answers, so no key is ever used.
     */
    private function fakeFill(string $reply): void
    {
        $this->prompts = [];

        BotSlotFillAgent::fake(function (string $prompt) use ($reply) {
            $this->prompts[] = $prompt;

            return $reply;
        });
    }

    /** Every in-scope slot of {@see richSlots} filled (the file slot is never bot-fillable, so it stays empty). */
    private function fullyFilledValues(): array
    {
        return [
            'topic' => 'human topic',
            'count' => 2,
            'active' => false,
            'when' => '2026-01-01',
            'tone' => 'formal',
            'tags' => ['human'],
            'meta' => ['a' => 'human', 'b' => 1],
        ];
    }

    private function runJob(GenerationSession $session): void
    {
        (new RunGenerationSessionJob($session->id, $this->workspace->id))->handle(app(GenerationSessionRunManager::class));
    }

    /** An `@[ai-text]("…")` directive exactly as the editor encodes it (so a run makes a live ai-text call). */
    private function aiText(string $prompt): string
    {
        $payload = json_encode(['v' => 1, 'data' => ['id' => 'ai_1', 'personaId' => null, 'prompt' => $prompt, 'labels' => []]]);

        return '@[ai-text]("' . str_replace('"', '\\"', $payload) . '")';
    }

    // ---- delegate: overlay + autonomous typed slot-fill ------------------------

    public function test_delegate_stamps_the_overlay_and_fills_valid_typed_slots(): void
    {
        BotSlotFillAgent::fake(fn () => json_encode([
            'topic' => 'Launch day',
            'count' => 5,
            'active' => true,
            'when' => '2026-08-01',
            'tone' => 'casual',
            'tags' => ['a', 'b'],
            'meta' => ['a' => 'hello', 'b' => 3],
            'attachment' => 'not-a-file', // out of scope (file) — dropped
            'ghost' => 'nope',            // unknown slot — dropped
        ]));

        $bot = $this->bot(['name' => 'Scribe']);
        $session = $this->richSession();

        $res = $this->delegate($bot->id, $session->id)->assertOk();

        // The whole overlay is stamped (snapshotted author + voice); creator (owner) unchanged.
        $res->assertJsonPath('data.is_delegated', true)
            ->assertJsonPath('data.bot_author.id', $bot->id)
            ->assertJsonPath('data.bot_author.name', 'Scribe')
            ->assertJsonPath('data.is_owner', true)
            ->assertJsonPath('data.status', 'draft');

        // Every valid typed value is persisted through the re-validating path.
        $fresh = $session->fresh();
        $this->assertSame('Launch day', $fresh->slot_values['topic']);
        $this->assertSame(5, $fresh->slot_values['count']);
        $this->assertTrue($fresh->slot_values['active']);
        $this->assertSame('2026-08-01', $fresh->slot_values['when']);
        $this->assertSame('casual', $fresh->slot_values['tone']);
        $this->assertSame(['a', 'b'], $fresh->slot_values['tags']);
        $this->assertSame(['a' => 'hello', 'b' => 3], $fresh->slot_values['meta']);

        // The out-of-scope + unknown values were DROPPED (never persisted raw).
        $this->assertArrayNotHasKey('attachment', $fresh->slot_values);
        $this->assertArrayNotHasKey('ghost', $fresh->slot_values);

        // The fill report describes each outcome; the required FILE slot is a SOFT unfilled signal.
        $report = $res->json('fill_report');
        $this->assertEqualsCanonicalizing(['topic', 'count', 'active', 'when', 'tone', 'tags', 'meta'], $report['filled']);
        $this->assertContains(['name' => 'attachment', 'reason' => 'out_of_scope'], $report['skipped']);
        $this->assertContains(['name' => 'ghost', 'reason' => 'unknown_slot'], $report['skipped']);
        $this->assertSame(['attachment'], $report['unfilled_required']);
        $res->assertJsonPath('data.unfilled_required_slots', ['attachment']);
    }

    public function test_an_invalid_typed_value_is_dropped_not_persisted(): void
    {
        BotSlotFillAgent::fake(fn () => json_encode([
            'topic' => 'ok text',
            'count' => 'not-a-number', // wrong type → dropped
        ]));

        $bot = $this->bot();
        $session = $this->richSession();

        $res = $this->delegate($bot->id, $session->id)->assertOk();

        $fresh = $session->fresh();
        $this->assertSame('ok text', $fresh->slot_values['topic']);
        $this->assertArrayNotHasKey('count', $fresh->slot_values);
        $this->assertContains(['name' => 'count', 'reason' => 'invalid'], $res->json('fill_report.skipped'));
    }

    public function test_a_nul_byte_value_is_rejected_and_never_persisted(): void
    {
        // A NUL byte would forge the resolver's injection mask — ConstantTypeValidator rejects it.
        BotSlotFillAgent::fake(fn () => json_encode(['topic' => "bad\0value"]));

        $bot = $this->bot();
        $session = $this->richSession();

        $res = $this->delegate($bot->id, $session->id)->assertOk();

        $this->assertArrayNotHasKey('topic', $session->fresh()->slot_values);
        $this->assertContains(['name' => 'topic', 'reason' => 'invalid'], $res->json('fill_report.skipped'));
    }

    public function test_a_file_slot_is_never_bot_filled_and_the_session_stays_draft(): void
    {
        // The bot proposes nothing fillable; the required file slot stays reported and the session a draft.
        BotSlotFillAgent::fake(fn () => '{}');

        $bot = $this->bot();
        $session = $this->richSession();

        $res = $this->delegate($bot->id, $session->id)->assertOk();

        $this->assertSame(GenerationSessionStatus::Draft, $session->fresh()->status);
        $this->assertContains('attachment', $res->json('fill_report.unfilled_required'));
    }

    // ---- fill_mode: gaps vs fresh (the two owner-reported defects) --------------

    public function test_gaps_mode_fills_only_the_empty_slots_and_never_touches_a_human_value(): void
    {
        $this->fakeFill((string) json_encode(['count' => 4, 'tone' => 'casual']));

        $bot = $this->bot();
        $session = $this->richSession(['topic' => 'human original']);

        $res = $this->delegate($bot->id, $session->id, ['fill_mode' => 'gaps'])->assertOk();

        // The human's own input survives BYTE-IDENTICAL; only the empty slots were filled.
        $fresh = $session->fresh();
        $this->assertSame('human original', $fresh->slot_values['topic']);
        $this->assertSame(4, $fresh->slot_values['count']);
        $this->assertSame('casual', $fresh->slot_values['tone']);

        $res->assertJsonPath('fill_report.mode', 'gaps')
            ->assertJsonPath('fill_report.nothing_to_fill', false);
        $this->assertEqualsCanonicalizing(['count', 'tone'], $res->json('fill_report.filled'));

        // The PROMPT: `topic` is offered NOWHERE — it appears only in the read-only AUTHOR CONTEXT block, and
        // nothing in the request asks the model to change anything that is already there.
        $prompt = $this->prompts[0];
        [$toFill, $context] = explode('AUTHOR CONTEXT', $prompt, 2);
        $this->assertStringContainsString('MODE: GAPS', $toFill);
        $this->assertStringNotContainsString('- topic ', $toFill, 'a filled slot is never offered in gaps mode');
        $this->assertStringContainsString('- topic ', $context);
        $this->assertStringContainsString('human original', $context);
        $this->assertStringNotContainsString('[previous:', $prompt);
        $this->assertStringNotContainsString('MUST differ', $prompt, 'gaps must not ask for a different take');
    }

    public function test_gaps_mode_refuses_a_model_value_for_a_slot_the_human_already_filled(): void
    {
        // The model ignores the instruction (or an injected description talked it into it) and proposes a value
        // for the ALREADY-FILLED slot. The SERVER refuses it — the prompt is a request, not the authority.
        $this->fakeFill((string) json_encode(['topic' => 'bot tried to overwrite', 'count' => 9]));

        $bot = $this->bot();
        $session = $this->richSession(['topic' => 'human original']);

        $res = $this->delegate($bot->id, $session->id, ['fill_mode' => 'gaps'])->assertOk();

        $fresh = $session->fresh();
        $this->assertSame('human original', $fresh->slot_values['topic'], 'a non-offered slot can never be written');
        $this->assertSame(9, $fresh->slot_values['count']);
        $this->assertSame(['count'], $res->json('fill_report.filled'));
        $this->assertContains(['name' => 'topic', 'reason' => 'already_filled'], $res->json('fill_report.skipped'));
    }

    public function test_gaps_mode_with_nothing_empty_makes_no_ai_call_and_still_stamps_the_overlay(): void
    {
        $this->fakeFill('{"topic": "must never be asked for"}');

        $bot = $this->bot();
        $session = $this->richSession($this->fullyFilledValues());

        $res = $this->delegate($bot->id, $session->id, ['fill_mode' => 'gaps'])->assertOk();

        // NOTHING was spent: the seam was never invoked and no usage event exists.
        $this->assertSame([], $this->prompts);
        BotSlotFillAgent::assertNeverPrompted();
        $this->assertSame(0, AiUsageEvent::count());

        // …and the report says so honestly, while the delegation overlay IS stamped (the bot is now the author).
        $res->assertJsonPath('fill_report.nothing_to_fill', true)
            ->assertJsonPath('fill_report.mode', 'gaps')
            ->assertJsonPath('fill_report.filled', [])
            ->assertJsonPath('data.is_delegated', true)
            ->assertJsonPath('data.bot_author.id', $bot->id);

        // The human's inputs are untouched; the required FILE slot is still reported (a soft signal).
        $this->assertSame($this->fullyFilledValues(), $session->fresh()->slot_values);
        $this->assertSame(['attachment'], $res->json('fill_report.unfilled_required'));
    }

    public function test_fresh_mode_replaces_every_in_scope_slot_and_asks_for_a_different_take(): void
    {
        $this->fakeFill((string) json_encode([
            'topic' => 'bot topic',
            'count' => 42,
            'active' => true,
            'when' => '2026-09-09',
            'tone' => 'casual',
            'tags' => ['bot'],
            'meta' => ['a' => 'bot', 'b' => 7],
        ]));

        $bot = $this->bot();
        $session = $this->richSession($this->fullyFilledValues());

        $res = $this->delegate($bot->id, $session->id, ['fill_mode' => 'fresh'])->assertOk();

        // A FILLED session's values DO change — "take it over and do it your way".
        $fresh = $session->fresh();
        $this->assertSame('bot topic', $fresh->slot_values['topic']);
        $this->assertSame(42, $fresh->slot_values['count']);
        $this->assertTrue($fresh->slot_values['active']);
        $this->assertSame(['bot'], $fresh->slot_values['tags']);
        $res->assertJsonPath('fill_report.mode', 'fresh')
            ->assertJsonPath('fill_report.nothing_to_fill', false);
        $this->assertEqualsCanonicalizing(
            ['topic', 'count', 'active', 'when', 'tone', 'tags', 'meta'],
            $res->json('fill_report.filled'),
        );

        // The PROMPT labels the current values as the author's PREVIOUS take and demands a different one.
        $prompt = $this->prompts[0];
        $this->assertStringContainsString('MODE: FRESH', $prompt);
        $this->assertStringContainsString('PREVIOUS value written by the author', $prompt);
        $this->assertStringContainsString('MUST differ from it: do not repeat it verbatim', $prompt);
        $this->assertStringContainsString('[previous: "human topic"]', $prompt);
        $this->assertStringNotContainsString('AUTHOR CONTEXT', $prompt, 'fresh offers everything — nothing is context-only');
    }

    public function test_two_consecutive_fresh_fills_send_different_prompts(): void
    {
        // The scripted reply RE-PROPOSES the values already there, so the session state (and therefore the whole
        // schema) is identical on both delegations: the ONLY thing that may differ is the variation token. This
        // is the regression pin for "it keeps returning the same values" — a byte-identical prompt is exactly
        // what makes a provider hand back the same completion twice.
        $values = $this->fullyFilledValues();
        $this->fakeFill((string) json_encode($values));

        $bot = $this->bot();
        $session = $this->richSession($values);

        $this->delegate($bot->id, $session->id, ['fill_mode' => 'fresh'])->assertOk();
        $this->delegate($bot->id, $session->id, ['fill_mode' => 'fresh'])->assertOk();

        $this->assertCount(2, $this->prompts);
        [$first, $second] = $this->prompts;

        $this->assertNotSame($first, $second, 'two fresh delegations must not send the same request twice');

        $token = function (string $prompt): string {
            $this->assertSame(1, preg_match('/^VARIATION TOKEN[^\n]*: (\S+)$/m', $prompt, $m), 'the variation token line is missing');

            return $m[1];
        };
        $this->assertNotSame($token($first), $token($second));

        // …and NOTHING ELSE varies: strip the token line and the two prompts are identical.
        $withoutToken = fn (string $prompt) => (string) preg_replace('/^VARIATION TOKEN[^\n]*$/m', '', $prompt);
        $this->assertSame($withoutToken($first), $withoutToken($second));
    }

    public function test_fill_mode_defaults_to_gaps_and_an_unknown_mode_is_rejected(): void
    {
        $this->fakeFill((string) json_encode(['count' => 1]));

        $bot = $this->bot();
        $session = $this->richSession(['topic' => 'human original']);

        // ABSENT → gaps (the non-destructive reading), so a caller that never learned about `fill_mode` is safe.
        $this->delegate($bot->id, $session->id)
            ->assertOk()
            ->assertJsonPath('fill_report.mode', 'gaps');
        $this->assertSame('human original', $session->fresh()->slot_values['topic']);
        $this->assertStringContainsString('MODE: GAPS', $this->prompts[0]);

        // An unknown mode is a validation error, never a silent fallback.
        $this->delegate($bot->id, $session->id, ['fill_mode' => 'wild'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('fill_mode');
    }

    public function test_undo_after_a_fresh_delegation_restores_the_humans_original_values(): void
    {
        $this->fakeFill((string) json_encode(['topic' => 'bot topic', 'tone' => 'casual', 'count' => 99]));

        $bot = $this->bot();
        $session = $this->richSession(['topic' => 'human original', 'tone' => 'formal']);

        $this->delegate($bot->id, $session->id, ['fill_mode' => 'fresh'])->assertOk();
        $this->assertSame('bot topic', $session->fresh()->slot_values['topic']);

        // `slot_values_before` was snapshotted BEFORE the fresh fill (the controller stamps the overlay first),
        // so undo is a FULL revert — this is what makes `fresh` safe to offer at all.
        $this->deleteJson("/api/bots/{$bot->id}/sessions/{$session->id}/delegate")->assertOk();
        $this->assertSame(['topic' => 'human original', 'tone' => 'formal'], $session->fresh()->slot_values);
    }

    // ---- snapshot-not-live + reversibility -------------------------------------

    public function test_editing_or_deleting_the_bot_never_changes_a_delegated_sessions_voice(): void
    {
        BotSlotFillAgent::fake(fn () => '{}');

        $bot = $this->bot(['persona' => 'ORIGINAL PERSONA']);
        $session = $this->richSession();

        $this->delegate($bot->id, $session->id)->assertOk();
        $frozenVoice = $session->fresh()->botVoice();
        $this->assertStringContainsString('ORIGINAL PERSONA', (string) $frozenVoice);

        // Mutate then delete the bot AFTER delegation.
        $bot->update(['persona' => 'CHANGED PERSONA']);
        $bot->delete();

        // The session reads its own snapshot, never the live bot — voice + author are frozen.
        $reloaded = $session->fresh();
        $this->assertSame($frozenVoice, $reloaded->botVoice());
        $this->assertStringNotContainsString('CHANGED PERSONA', (string) $reloaded->botVoice());
        $this->assertSame($bot->id, $reloaded->botAuthor()['id']);
    }

    public function test_undo_clears_the_whole_overlay_back_to_the_human_voice(): void
    {
        BotSlotFillAgent::fake(fn () => '{}');

        $bot = $this->bot();
        $session = $this->richSession();
        $this->delegate($bot->id, $session->id)->assertOk();
        $this->assertTrue($session->fresh()->isDelegated());

        $this->deleteJson("/api/bots/{$bot->id}/sessions/{$session->id}/delegate")
            ->assertOk()
            ->assertJsonPath('data.is_delegated', false)
            ->assertJsonPath('data.bot_author', null);

        $reloaded = $session->fresh();
        $this->assertNull($reloaded->bot_author_id);
        $this->assertNull($reloaded->bot_delegation);
        $this->assertNull($reloaded->botVoice());
    }

    public function test_undo_restores_the_pre_delegation_slot_values_with_no_data_loss(): void
    {
        // A human pre-filled `topic`. The bot OVERWRITES `topic` and also fills `count`. Overwriting is the
        // FRESH mode's job — the default (`gaps`) deliberately never touches a human value, so the overwrite
        // this case reverts has to be asked for explicitly.
        BotSlotFillAgent::fake(fn () => json_encode(['topic' => 'bot overwrote', 'count' => 7]));

        $bot = $this->bot();
        $session = $this->richSession(['topic' => 'human original']);

        // After delegate the bot's values are persisted (topic overwritten, count added).
        $this->delegate($bot->id, $session->id, ['fill_mode' => 'fresh'])->assertOk();
        $afterDelegate = $session->fresh();
        $this->assertSame('bot overwrote', $afterDelegate->slot_values['topic']);
        $this->assertSame(7, $afterDelegate->slot_values['count']);

        // After UNDO slot_values EXACTLY equals the pre-delegation state — the human's `topic` restored, the
        // bot's `count` gone. Zero data loss, zero bot residue (a TRUE revert, not a voice-only clear).
        $this->deleteJson("/api/bots/{$bot->id}/sessions/{$session->id}/delegate")->assertOk();
        $this->assertSame(['topic' => 'human original'], $session->fresh()->slot_values);
    }

    public function test_undo_restores_the_empty_pre_delegation_state_when_no_slots_were_pre_filled(): void
    {
        BotSlotFillAgent::fake(fn () => json_encode(['topic' => 'bot filled', 'count' => 3]));

        $bot = $this->bot();
        $session = $this->richSession(); // no pre-filled slots

        $this->delegate($bot->id, $session->id)->assertOk();
        $this->assertSame('bot filled', $session->fresh()->slot_values['topic']);

        // Undo returns slot_values to the pre-delegation (empty) state — the bot's fills are fully discarded.
        $this->deleteJson("/api/bots/{$bot->id}/sessions/{$session->id}/delegate")->assertOk();
        $this->assertSame([], $session->fresh()->slot_values);
    }

    public function test_a_non_overlapping_human_prefill_survives_the_bot_merge(): void
    {
        // The bot fills `topic`; the human already filled a DIFFERENT slot (`tone`).
        BotSlotFillAgent::fake(fn () => json_encode(['topic' => 'bot topic']));

        $bot = $this->bot();
        $session = $this->richSession(['tone' => 'formal']);

        $this->delegate($bot->id, $session->id)->assertOk();

        $fresh = $session->fresh();
        $this->assertSame('formal', $fresh->slot_values['tone'], 'the non-overlapping human prefill survives the merge');
        $this->assertSame('bot topic', $fresh->slot_values['topic']);
    }

    public function test_re_delegation_overwrites_the_whole_overlay_with_no_stale_bleed(): void
    {
        BotSlotFillAgent::fake(fn () => '{}');

        $botA = $this->bot(['persona' => 'ALPHA PERSONA', 'dictionary' => [['term' => 'alpha-term', 'meaning' => 'A']]]);
        $botB = $this->bot(['persona' => 'BETA PERSONA', 'dictionary' => [['term' => 'beta-term', 'meaning' => 'B']]]);
        $session = $this->richSession();

        $this->delegate($botA->id, $session->id)->assertOk();
        $this->delegate($botB->id, $session->id)->assertOk();

        $voice = (string) $session->fresh()->botVoice();
        $this->assertStringContainsString('beta-term', $voice);
        $this->assertStringNotContainsString('alpha-term', $voice, 'bot A material must not bleed into bot B');
        $this->assertSame($botB->id, $session->fresh()->botAuthor()['id']);
    }

    // ---- ownership + workspace scope -------------------------------------------

    public function test_the_owner_keeps_mutation_on_a_delegated_session(): void
    {
        BotSlotFillAgent::fake(fn () => '{}');

        $bot = $this->bot();
        $session = $this->richSession();
        $this->delegate($bot->id, $session->id)->assertOk();

        // The human owner can still edit inputs AND undo the delegation.
        $this->patchJson("/api/generator/sessions/{$session->id}", ['slot_values' => ['topic' => 'owner set']])
            ->assertOk()
            ->assertJsonPath('data.slot_values.topic', 'owner set');
        $this->deleteJson("/api/bots/{$bot->id}/sessions/{$session->id}/delegate")->assertOk();
    }

    public function test_a_non_owner_cannot_delegate(): void
    {
        BotSlotFillAgent::fake(fn () => '{}');

        $other = User::factory()->create();
        $this->workspace->users()->attach($other->id);
        $bot = $this->bot();
        $session = $this->richSession(); // created by $this->user

        $this->actingAs($other)->withHeader('X-Workspace-Id', $this->workspace->id);
        $this->delegate($bot->id, $session->id)->assertStatus(403);

        $this->assertFalse($session->fresh()->isDelegated());
    }

    public function test_a_cross_workspace_bot_is_rejected_at_bind(): void
    {
        BotSlotFillAgent::fake(fn () => '{}');

        $session = $this->richSession();

        // A bot that lives in ANOTHER workspace.
        $other = Workspace::factory()->create(['owner_id' => $this->user->id]);
        app(TenantContext::class)->set($other);
        $foreignBot = Bot::factory()->create(['creator_id' => $this->user->id]);
        app(TenantContext::class)->set($this->workspace);

        $this->delegate($foreignBot->id, $session->id)->assertStatus(404);
        $this->assertFalse($session->fresh()->isDelegated());
    }

    // ---- metering + auto_generate + state guards -------------------------------

    public function test_the_slot_fill_is_one_metered_ai_text_call_tagged_with_the_session(): void
    {
        Queue::fake();
        BotSlotFillAgent::fake(fn () => json_encode(['topic' => 'x']));

        $bot = $this->bot();
        $session = $this->richSession();

        $this->delegate($bot->id, $session->id)->assertOk();

        // EXACTLY one ai_text spend, tagged with THIS session (MeterContext), and nothing left untagged.
        $this->assertSame(1, AiUsageEvent::where('channel', 'ai_text')->where('session_id', $session->id)->count());
        $this->assertSame(1, AiUsageEvent::where('channel', 'ai_text')->count());
        $this->assertSame(0, AiUsageEvent::whereNull('session_id')->count());
    }

    public function test_a_session_with_no_offerable_slots_makes_no_metered_call(): void
    {
        // A recipe whose ONLY slot is a file → nothing offerable → no AI spend at all.
        BotSlotFillAgent::fake(fn () => throw new \RuntimeException('must not call the provider'));

        $bot = $this->bot();
        $session = GenerationSession::factory()
            ->snapshot('post', ['body' => ['markdown' => 'x']], [
                ['name' => 'attachment', 'description' => 'file', 'descriptor' => ['base' => 'file', 'nullable' => true, 'fields' => [
                    ['key' => 'id', 'descriptor' => ['base' => 'text']],
                    ['key' => 'name', 'descriptor' => ['base' => 'text']],
                    ['key' => 'type', 'descriptor' => ['base' => 'text']],
                    ['key' => 'size', 'descriptor' => ['base' => 'number']],
                    ['key' => 'url', 'descriptor' => ['base' => 'text']],
                ]]],
            ], [])
            ->create(['creator_id' => $this->user->id]);

        $this->delegate($bot->id, $session->id)->assertOk();
        $this->assertSame(0, AiUsageEvent::count());
        $this->assertTrue($session->fresh()->isDelegated());
    }

    public function test_auto_generate_opt_in_claims_a_run_while_the_default_does_not(): void
    {
        Queue::fake();
        BotSlotFillAgent::fake(fn () => json_encode(['topic' => 'x']));

        $bot = $this->bot();

        // Default: ready-to-generate, NO run (gate-przed-wydatkiem).
        $s1 = $this->richSession();
        $this->delegate($bot->id, $s1->id)->assertOk()->assertJsonPath('data.status', 'draft');
        Queue::assertNothingPushed();

        // Opt-in: claim + queue a run, 202, now generating. The 202 body is built AFTER the claim, so it
        // reflects the post-claim state (`generating` + `can_generate:false`), never the stale pre-claim draft.
        $s2 = $this->richSession();
        $this->delegate($bot->id, $s2->id, ['auto_generate' => true])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'generating')
            ->assertJsonPath('data.can_generate', false);
        Queue::assertPushed(RunGenerationSessionJob::class, fn ($job) => $job->sessionId === $s2->id);
    }

    public function test_delegating_a_generating_session_is_a_409(): void
    {
        BotSlotFillAgent::fake(fn () => '{}');

        $bot = $this->bot();
        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Generating)
            ->snapshot('post', ['body' => ['markdown' => 'x']], $this->richSlots(), [])
            ->create(['creator_id' => $this->user->id]);

        $this->delegate($bot->id, $session->id)->assertStatus(409);
        $this->assertFalse($session->fresh()->isDelegated());
    }

    public function test_undoing_a_generating_session_is_a_409(): void
    {
        BotSlotFillAgent::fake(fn () => '{}');

        $bot = $this->bot();
        $session = $this->richSession();

        // Delegate while editable, THEN the session enters a run.
        $this->delegate($bot->id, $session->id)->assertOk();
        $session->update(['status' => GenerationSessionStatus::Generating]);

        // Undo mid-run is rejected (mirrors the delegate guard) — the overlay is left intact.
        $this->deleteJson("/api/bots/{$bot->id}/sessions/{$session->id}/delegate")->assertStatus(409);
        $this->assertTrue($session->fresh()->isDelegated());
    }

    // ---- can_undo_delegation capability flag -----------------------------------

    /** Stamp the delegation overlay DIRECTLY (bypassing the editable guard) so a session can be delegated in ANY state. */
    private function delegateDirect(GenerationSession $session, Bot $bot): void
    {
        app(SessionDelegationService::class)->applyDelegation(
            $session,
            'a bot voice',
            ['id' => $bot->id, 'name' => $bot->name, 'icon' => 'robot'],
            $bot->id,
        );
    }

    private function show(string $sessionId): \Illuminate\Testing\TestResponse
    {
        return $this->getJson("/api/generator/sessions/{$sessionId}");
    }

    public function test_can_undo_delegation_mirrors_the_backend_undo_guard_across_states_and_ownership(): void
    {
        $bot = $this->bot();

        // Delegated + FAILED → TRUE (the key case: the delegate/undo affordance stays even after a failed run,
        // where `can_delegate` is false because a failed session is not editable).
        $failed = $this->richSession();
        $this->delegateDirect($failed, $bot);
        $failed->update(['status' => GenerationSessionStatus::Failed]);
        $this->show($failed->id)->assertOk()
            ->assertJsonPath('data.can_undo_delegation', true)
            ->assertJsonPath('data.can_delegate', false);

        // Delegated + READY → TRUE.
        $ready = $this->richSession();
        $this->delegateDirect($ready, $bot);
        $ready->update(['status' => GenerationSessionStatus::Ready]);
        $this->show($ready->id)->assertOk()->assertJsonPath('data.can_undo_delegation', true);

        // Delegated + GENERATING → FALSE (an in-flight run — undo is a 409).
        $generating = $this->richSession();
        $this->delegateDirect($generating, $bot);
        $generating->update(['status' => GenerationSessionStatus::Generating]);
        $this->show($generating->id)->assertOk()->assertJsonPath('data.can_undo_delegation', false);

        // NOT delegated → FALSE (nothing to undo).
        $plain = $this->richSession();
        $this->show($plain->id)->assertOk()
            ->assertJsonPath('data.is_delegated', false)
            ->assertJsonPath('data.can_undo_delegation', false);

        // Non-owner (canUpdate false) on a delegated session → FALSE (a member may VIEW but not undo).
        $other = User::factory()->create();
        $this->workspace->users()->attach($other->id);
        $this->actingAs($other)->withHeader('X-Workspace-Id', $this->workspace->id);
        $this->show($ready->id)->assertOk()->assertJsonPath('data.can_undo_delegation', false);
    }

    // ---- voice reaches the run + leak-proof ------------------------------------

    public function test_a_delegated_run_renders_in_the_bot_voice_then_clears_it_so_a_later_spend_sees_none(): void
    {
        $bot = $this->bot(['persona' => 'PIRATE PERSONA']);
        // A body carrying a live @[ai-text] directive, so the run actually invokes the ai-text agent.
        $session = GenerationSession::factory()
            ->snapshot('post', ['body' => ['markdown' => 'Intro ' . $this->aiText('Describe the topic')]], [
                ['name' => 'topic', 'description' => 'The subject', 'descriptor' => ['base' => 'text', 'nullable' => false, 'array' => false]],
            ], ['topic' => 'sea shanties'])
            ->create(['creator_id' => $this->user->id]);

        // Stamp a known voice directly (focus on the run wiring, not the fill path).
        app(SessionDelegationService::class)->applyDelegation(
            $session,
            'ALWAYS SPEAK LIKE A PIRATE',
            ['id' => $bot->id, 'name' => $bot->name, 'icon' => 'robot'],
            $bot->id,
        );
        $session->update(['status' => GenerationSessionStatus::Generating]);

        // Record the AMBIENT voice at each ai-text call: the executor sets it for the delegated run, and
        // must have CLEARED it (finally) before any later, non-delegated spend.
        $seen = [];
        AiTextAgent::fake(function () use (&$seen) {
            $seen[] = app(AiVoiceContext::class)->directive();

            return 'OUT';
        });

        $this->runJob($session);

        // A later, standalone ai-text spend with NO session/voice in scope.
        app(AiTextGenerationService::class)->generate('a standalone prompt', null, 100);

        $this->assertSame('ALWAYS SPEAK LIKE A PIRATE', $seen[0], 'the delegated run must render in the bot voice');
        $this->assertNull($seen[1], 'the voice must be cleared after the run (leak-proof) — a later spend sees none');
    }

    public function test_a_throw_mid_render_on_a_delegated_session_still_clears_the_voice(): void
    {
        $bot = $this->bot();
        $session = $this->richSession(['topic' => 'sea shanties']);

        app(SessionDelegationService::class)->applyDelegation(
            $session,
            'ALWAYS SPEAK LIKE A PIRATE',
            ['id' => $bot->id, 'name' => $bot->name, 'icon' => 'robot'],
            $bot->id,
        );

        // A SCRIPTED failing seam: the shared catalog throws mid-render — AFTER execute() set the delegated
        // voice, BEFORE its finally. It captures the ambient voice at throw-time to prove it WAS in scope.
        $throwingCatalog = new class(app(VariableCatalog::class)) extends TemplateVariableCatalog
        {
            public ?string $voiceAtThrow = null;

            public bool $threw = false;

            public function executionContext(array $slots, array $slotValues): array
            {
                $this->threw = true;
                $this->voiceAtThrow = app(AiVoiceContext::class)->directive();

                throw new \RuntimeException('scripted mid-run failure');
            }
        };
        $this->app->instance(TemplateVariableCatalog::class, $throwingCatalog);

        try {
            app(GenerationSessionExecutor::class)->execute($session->fresh());
            $this->fail('the scripted seam should have thrown out of execute()');
        } catch (\RuntimeException $e) {
            $this->assertSame('scripted mid-run failure', $e->getMessage());
        }

        $this->assertTrue($throwingCatalog->threw, 'the scripted seam must have run mid-render');
        $this->assertSame('ALWAYS SPEAK LIKE A PIRATE', $throwingCatalog->voiceAtThrow, 'the delegated voice must be set during the run');
        $this->assertNull(app(AiVoiceContext::class)->directive(), 'the finally must clear the voice despite the throw');

        // A subsequent STANDALONE ai-text spend (no session/voice in scope) sees no leaked directive.
        $seen = 'unset';
        AiTextAgent::fake(function () use (&$seen) {
            $seen = app(AiVoiceContext::class)->directive();

            return 'OUT';
        });
        app(AiTextGenerationService::class)->generate('a standalone prompt', null, 100);

        $this->assertNull($seen, 'a later standalone ai-text spend sees no leaked voice after the throw');
    }
}
