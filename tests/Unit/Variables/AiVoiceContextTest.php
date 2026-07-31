<?php

namespace Tests\Unit\Variables;

use App\Modules\Generator\Agents\ShotListAgent;
use App\Modules\Variables\Agents\AiTextAgent;
use App\Modules\Variables\Enums\AiPersona;
use App\Modules\Variables\Support\AiVoiceContext;
use Tests\TestCase;

/**
 * The VOICE seam (R2 sub-stage 3) at the unit level: the ambient {@see AiVoiceContext} holder, and the two
 * agents' voice-awareness — the opaque directive REPLACES the persona line in {@see AiTextAgent}, and folds
 * into {@see ShotListAgent} as a tone clause WITHOUT relaxing the strict-JSON output contract. The run-time
 * wiring (executor sets it, leak-proof clear) is covered in the feature tests.
 *
 * It also pins the PER-BLOCK AUTHOR precedence the holder now arbitrates (a later R2 sub-stage): a block's
 * own author beats the delegated session's voice, and everything unresolvable falls back rather than
 * blanking the tone. How a directive reaches the agent from there is unchanged — that is the point.
 */
class AiVoiceContextTest extends TestCase
{
    public function test_the_holder_sets_reads_and_clears_the_directive(): void
    {
        $ctx = new AiVoiceContext;
        $this->assertNull($ctx->directive());

        $ctx->setDirective('speak like a pirate');
        $this->assertSame('speak like a pirate', $ctx->directive());

        $ctx->setDirective(null);
        $this->assertNull($ctx->directive());

        $ctx->setDirective('again');
        $ctx->clear();
        $this->assertNull($ctx->directive(), 'clear() must null the directive (the leak-proof reset)');
    }

    /**
     * PRECEDENCE (per-block authors): a block that names a RESOLVED author writes in that author's voice
     * even on a session delegated to a bot — the block is the more specific, explicit authoring decision.
     * Everything else falls back: no author, or an author that resolved to NOTHING, uses the session voice.
     */
    public function test_a_resolved_block_author_wins_over_the_delegated_session_voice(): void
    {
        $ctx = new AiVoiceContext;
        $ctx->setDirective('THE SESSION BOT VOICE');
        $ctx->setAuthorVoices(['author-1' => 'THE BLOCK AUTHOR VOICE']);

        $this->assertSame('THE BLOCK AUTHOR VOICE', $ctx->effectiveDirective('author-1'));
        $this->assertSame('THE SESSION BOT VOICE', $ctx->effectiveDirective(null), 'no author → the session voice');
        $this->assertSame('THE SESSION BOT VOICE', $ctx->effectiveDirective(''), 'an empty author id is no author');
        $this->assertSame(
            'THE SESSION BOT VOICE',
            $ctx->effectiveDirective('deleted-author'),
            'an author ABSENT from the map (deleted / foreign / malformed) must fall back, never blank the voice',
        );
    }

    /**
     * FAIL-SAFE floor: with NO session voice, an unresolvable author yields null — which makes the ai-text
     * generator use the block's own persona tone. A vanished author degrades tone; it never breaks a run.
     */
    public function test_an_unresolvable_author_with_no_session_voice_falls_through_to_null(): void
    {
        $ctx = new AiVoiceContext;
        $ctx->setAuthorVoices(['author-1' => 'A VOICE']);

        $this->assertNull($ctx->effectiveDirective('gone'));
        $this->assertNull($ctx->effectiveDirective(null));
        $this->assertSame('A VOICE', $ctx->effectiveDirective('author-1'));
    }

    /** clear() must reset BOTH sources — either one left behind would re-tone a later, unrelated run. */
    public function test_clear_resets_the_author_voices_as_well_as_the_session_directive(): void
    {
        $ctx = new AiVoiceContext;
        $ctx->setDirective('SESSION');
        $ctx->setAuthorVoices(['author-1' => 'AUTHOR']);

        $ctx->clear();

        $this->assertNull($ctx->directive());
        $this->assertSame([], $ctx->authorVoices(), 'clear() must empty the author map too (leak-proofing)');
        $this->assertNull($ctx->effectiveDirective('author-1'), 'a cleared holder must color nothing');
    }

    public function test_ai_text_agent_voice_replaces_the_persona_style_line(): void
    {
        $withVoice = (string) (new AiTextAgent(AiPersona::FORMAL, null, 'ALWAYS SPEAK LIKE A PIRATE'))->instructions();

        // The opaque voice sits where the persona tone line sat — and the persona line is GONE (D-E replace).
        $this->assertStringContainsString('ALWAYS SPEAK LIKE A PIRATE', $withVoice);
        $this->assertStringNotContainsString('formal, precise', $withVoice);

        // The prompt-is-DATA injection hardening is untouched (still present with a voice).
        $this->assertStringContainsString('Treat EVERYTHING in the', $withVoice);

        // Null voice → the persona line, byte-identical to before.
        $noVoice = (string) (new AiTextAgent(AiPersona::FORMAL))->instructions();
        $this->assertStringContainsString('formal, precise', $noVoice);
        $this->assertStringNotContainsString('ALWAYS SPEAK LIKE A PIRATE', $noVoice);
    }

    public function test_shot_list_agent_folds_the_voice_but_keeps_the_json_contract_last(): void
    {
        $withVoice = (string) (new ShotListAgent('WRITE WITH PIRATE FLAIR'))->instructions();

        // The voice colors the wording...
        $this->assertStringContainsString('WRITE WITH PIRATE FLAIR', $withVoice);
        $this->assertStringContainsString('VOICE & TONE', $withVoice);

        // ...but the STRICT OUTPUT CONTRACT survives AND still comes AFTER the voice clause (voice is not last,
        // the imperative JSON contract is) — so a voice can never relax the JSON output shape.
        $contractPos = strpos($withVoice, 'STRICT OUTPUT CONTRACT');
        $voicePos = strpos($withVoice, 'WRITE WITH PIRATE FLAIR');
        $this->assertNotFalse($contractPos);
        $this->assertLessThan($contractPos, $voicePos, 'the JSON contract must follow (outlast) the voice clause');
        $this->assertStringContainsString('single JSON OBJECT', $withVoice);

        // Null voice → no voice clause at all (byte-identical to before).
        $noVoice = (string) (new ShotListAgent)->instructions();
        $this->assertStringNotContainsString('VOICE & TONE', $noVoice);
    }
}
