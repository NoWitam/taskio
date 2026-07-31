<?php

namespace Tests\Unit\Variables;

use App\Modules\Variables\Agents\AiTextAgent;
use App\Modules\Variables\Contracts\AiTextGenerator;
use App\Modules\Variables\Enums\AiPersona;
use App\Modules\Variables\Services\AiTextGenerationService;
use App\Modules\Variables\Services\OperationExecutor;
use App\Modules\Variables\Services\VariableResolver;
use App\Modules\Variables\Support\AiVoiceContext;
use App\Modules\Variables\Support\PassthroughMeteredAiCall;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\TestCase;

/**
 * The PER-BLOCK AUTHOR seam of `@[ai-text]`, at the two points it touches the Variables layer:
 *
 *   1. COLLECTION — {@see VariableResolver::collectAiTextAuthorIds} gathers the authors of every block the
 *      resolver would actually EXECUTE (nested prompts, all if-block branches, honoring the ai-text depth
 *      cap), de-duplicated, so an upper layer can resolve them in ONE batch lookup.
 *   2. APPLICATION — {@see AiTextGenerationService} asks the voice holder which directive applies to the
 *      calling block and feeds the agent accordingly.
 *
 * The two properties this file exists for are the SAFETY ones: an author that resolves to NOTHING must
 * still produce text (fail-SAFE, degrading only the tone), and a block with NO author must produce the
 * agent instruction BYTE-IDENTICALLY to before the feature existed.
 */
class AiTextAuthorSeamTest extends TestCase
{
    /** A generator double: the collection walk never calls it, and the service tests bypass it entirely. */
    private function inertGenerator(): AiTextGenerator
    {
        return new class implements AiTextGenerator
        {
            public function generate(string $prompt, ?string $personaId, ?string $authorId): string
            {
                return '';
            }
        };
    }

    private function resolver(): VariableResolver
    {
        return new VariableResolver(new OperationExecutor, $this->inertGenerator());
    }

    /** An `@[ai-text]("…")` directive encoded exactly as the editor does. */
    private function aiText(string $prompt, ?string $authorId = null): string
    {
        $payload = json_encode([
            'v' => 1,
            'data' => ['id' => 'ai_1', 'personaId' => null, 'authorId' => $authorId, 'prompt' => $prompt, 'labels' => []],
        ]);

        return '@[ai-text]("' . str_replace('"', '\\"', (string) $payload) . '")';
    }

    // ---- collection -----------------------------------------------------------

    public function test_it_collects_authors_from_nested_blocks_and_if_block_branches_deduplicated(): void
    {
        // An author reused across blocks, one nested INSIDE another block's prompt, and one living in a
        // branch that would NOT win at runtime (a batch lookup must cover every branch, like the reference
        // scan does — the winning branch is only known at execution time).
        $nested = $this->aiText('inner', 'author-inner');
        $outer = $this->aiText('outer ' . $nested, 'author-a');

        $condition = [
            'variableId' => 'trigger.fields.topic',
            'pipeline' => [['stepId' => 's1', 'operationId' => 'text_is_not_empty', 'args' => [], 'outputType' => 'boolean']],
            'resultType' => 'boolean',
        ];

        $markdown = $outer . "\n"
            . '```if-block ' . json_encode(['id' => 'if_1', 'v' => 1]) . "\n"
            . '[[IF ' . json_encode(['id' => 'b1', 'condition' => $condition]) . ']]' . "\n"
            . $this->aiText('winner', 'author-a') . "\n"
            . '[[ELSE ' . json_encode(['id' => 'b2']) . ']]' . "\n"
            . $this->aiText('loser', 'author-else') . "\n```";

        // Order follows the shared walk (if-blocks first, then the inline layer — exactly as the reference
        // scan traverses), and `author-a` appears once despite being named by two blocks.
        $this->assertSame(
            ['author-a', 'author-else', 'author-inner'],
            $this->resolver()->collectAiTextAuthorIds($markdown),
        );
    }

    public function test_it_ignores_blocks_without_an_author_and_malformed_payloads(): void
    {
        $noAuthor = $this->aiText('plain');
        $emptyAuthor = $this->aiText('plain', '');
        $broken = '@[ai-text]("not-json")';

        $this->assertSame([], $this->resolver()->collectAiTextAuthorIds($noAuthor . ' ' . $emptyAuthor . ' ' . $broken));
        $this->assertSame([], $this->resolver()->collectAiTextAuthorIds('no directives at all'));
    }

    public function test_it_honors_the_ai_text_depth_cap_exactly_as_resolution_does(): void
    {
        // Four nested levels. The resolver caps ai-text at depth 3, so the LEVEL-4 block is never generated
        // — collecting its author would mean paying for a lookup whose voice can never be used.
        $level4 = $this->aiText('four', 'author-4');
        $level3 = $this->aiText('three ' . $level4, 'author-3');
        $level2 = $this->aiText('two ' . $level3, 'author-2');
        $level1 = $this->aiText('one ' . $level2, 'author-1');

        $this->assertSame(
            ['author-1', 'author-2', 'author-3'],
            $this->resolver()->collectAiTextAuthorIds($level1),
        );
    }

    public function test_collecting_authors_leaves_the_reference_scan_untouched(): void
    {
        // Both public entry points run the SAME walk; adding the author sink must not change what the
        // long-standing reference scan reports.
        $markdown = $this->aiText('draft {{trigger.fields.topic}}', 'author-a') . ' and {{globals.brand}}';

        $this->assertSame(
            ['trigger.fields.topic', 'globals.brand'],
            $this->resolver()->collectReferenceIds($markdown),
        );
        $this->assertSame(['author-a'], $this->resolver()->collectAiTextAuthorIds($markdown));
    }

    // ---- application (the generation service) ---------------------------------

    private function service(AiVoiceContext $voice): AiTextGenerationService
    {
        return new AiTextGenerationService(new PassthroughMeteredAiCall, $voice);
    }

    /**
     * THE fail-SAFE pin. An author id that resolved to NOTHING (deleted bot, foreign workspace, garbage)
     * with no session voice must NOT blank the generation and must NOT leave the agent voice-less: the
     * block's persona tone applies, exactly as if no author had been named. A vanished author may cost a
     * run its intended tone — it may never cost it its text.
     */
    public function test_an_unresolvable_author_still_generates_with_the_persona_tone(): void
    {
        AiTextAgent::fake(fn () => 'OUT');

        $voice = new AiVoiceContext; // no session voice, and no author ever resolved
        $text = $this->service($voice)->generate('write a title', null, 2000, authorId: 'a-deleted-author');

        $this->assertSame('OUT', $text, 'a missing author must never fail the generation closed');

        AiTextAgent::assertPrompted(
            fn (AgentPrompt $prompt): bool => str_contains(
                (string) $prompt->agent->instructions(),
                AiPersona::NEUTRAL->styleInstruction(),
            ),
        );
    }

    /**
     * BYTE PIN: no author + no session voice ⇒ the agent instruction is EXACTLY the pre-feature one (the
     * default persona line, the default length clause, the untouched hardening). This is what makes the
     * whole author layer inert for every block, workflow and recipe authored before it.
     */
    public function test_no_author_and_no_session_voice_produce_the_original_instruction_byte_for_byte(): void
    {
        AiTextAgent::fake(fn () => 'OUT');

        $expected = (string) (new AiTextAgent)->instructions();

        $this->assertSame('OUT', $this->service(new AiVoiceContext)->generate('write a title', null, 2000));

        AiTextAgent::assertPrompted(
            fn (AgentPrompt $prompt): bool => (string) $prompt->agent->instructions() === $expected,
        );
    }

    /**
     * A RESOLVED author replaces the persona line — and outranks a delegated session's voice, which is the
     * precedence decision the holder owns (the block is the more specific authoring choice).
     */
    public function test_a_resolved_author_voice_replaces_the_persona_and_outranks_the_session_voice(): void
    {
        AiTextAgent::fake(fn () => 'OUT');

        $voice = new AiVoiceContext;
        $voice->setDirective('THE SESSION BOT VOICE');
        $voice->setAuthorVoices(['author-1' => 'THE BLOCK AUTHOR VOICE']);

        $this->assertSame('OUT', $this->service($voice)->generate('write a title', 'formal', 2000, authorId: 'author-1'));

        AiTextAgent::assertPrompted(function (AgentPrompt $prompt): bool {
            $instructions = (string) $prompt->agent->instructions();

            return str_contains($instructions, 'THE BLOCK AUTHOR VOICE')
                && !str_contains($instructions, 'THE SESSION BOT VOICE')
                && !str_contains($instructions, AiPersona::FORMAL->styleInstruction());
        });
    }

    /** With no author on the block, a delegated session's voice still applies — unchanged behavior. */
    public function test_a_block_without_an_author_still_uses_the_session_voice(): void
    {
        AiTextAgent::fake(fn () => 'OUT');

        $voice = new AiVoiceContext;
        $voice->setDirective('THE SESSION BOT VOICE');
        $voice->setAuthorVoices(['author-1' => 'THE BLOCK AUTHOR VOICE']);

        $this->assertSame('OUT', $this->service($voice)->generate('write a title', null, 2000));

        AiTextAgent::assertPrompted(
            fn (AgentPrompt $prompt): bool => str_contains((string) $prompt->agent->instructions(), 'THE SESSION BOT VOICE'),
        );
    }
}
