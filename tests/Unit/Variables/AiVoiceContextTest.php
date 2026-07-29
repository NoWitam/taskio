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
