<?php

namespace App\Modules\Generator\Services;

use App\Modules\Generator\Support\CreativeDirectionContext;
use App\Modules\Variables\Contracts\AiTextGenerator;
use App\Modules\Variables\Services\AiTextGenerationService;
use App\Modules\Variables\Support\AiVoiceContext;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Agent;

/**
 * The Generator-side implementation of the Variables {@see AiTextGenerator} contract (bound ONLY where
 * the {@see GenerationSessionExecutor}'s resolver needs it) for an `@[ai-text]` directive in a template
 * recipe. It is the exact parallel of the Workflows-side ai-text decorator (WorkflowAiTextService): a THIN
 * DECORATOR that keeps the PER-SESSION call-count budget unique to a session run and delegates the actual
 * generation (agent call + trim/truncate + fail-closed + cost metering) to the shared
 * {@see AiTextGenerationService} promoted down into the Variables layer.
 *
 * It:
 *   - refuses a blank prompt (no wasted call, no budget spent),
 *   - enforces a PER-SESSION call budget so one recipe can't fan out into unbounded AI spend,
 *   - then delegates with the POST-length char cap + a POST purpose hint.
 *
 * BOUNDARY: it names ONLY Variables classes (the contract + the shared service), never a Workflows class
 * — Generator → Variables stays one-way (pinned by GeneratorModuleBoundaryTest).
 *
 * BUDGET SCOPE: the call counter is INSTANCE state. The service is resolved fresh together with the
 * resolver for each session run (the executor's contextual binding builds a new one per resolution, and
 * neither is a container singleton), so the counter naturally scopes to ONE run — do NOT bind it as a
 * singleton. Nothing about the prompt (which may carry slot values) is ever logged; only the budget FACT.
 */
class GeneratorAiTextService implements AiTextGenerator
{
    /**
     * The lead instruction wording for a generation-session field — passed as the shared agent's
     * purpose hint so the model frames the output as a piece of a social-media POST (not a workflow
     * field). Held as a const so a test can assert the instruction lead without a real provider.
     */
    private const PURPOSE_HINT = "a piece of social-media post content\n(for example a post body, a caption, or a short video-script narration)";

    /**
     * The OUTPUT-CONTRACT length rule for a generation-session field, replacing the shared agent's
     * "single field / do not pad" default (B1.2). That default is a WORKFLOW-FIELD prior (a task title, a
     * report name) and actively suppressed the depth a real post needs — the owner's flat, shallow output.
     *
     * SCALE FIRST, DEPTH SECOND: this rule reaches EVERY `@[ai-text]` block in a recipe, including one whose
     * authored prompt asks for a six-word caption (the PURPOSE_HINT even names "a caption"), and a SYSTEM
     * rule beats the user's brief. So "develop it fully" must not be unconditional, or the layer would bloat
     * the deliberately short fields it was never aimed at. Held as a const so a test can pin the instruction
     * without a real provider.
     */
    private const LENGTH_GUIDANCE = 'Write a COMPLETE piece of social-media content — match the length the request asks for: when it asks for something short (a caption, a headline, one line), be short; otherwise develop it fully, with real substance. Never pad artificially.';

    /** AI calls made so far in this session run (see BUDGET SCOPE above). */
    private int $calls = 0;

    public function __construct(
        private AiTextGenerationService $generator,
        private CreativeDirectionContext $direction,
        private AiVoiceContext $voice,
    ) {}

    /**
     * Generate the text for one resolved ai-text prompt, or '' (fail-closed) on a blank prompt or an
     * exhausted per-session budget. Anything past the budget guard is delegated to the shared service,
     * which itself trims, meters, length-caps, and fails closed on any provider error.
     *
     * DIRECTION (B2): when the run derived a creative direction, its TEXT projection is prepended to the
     * PROMPT (the USER message) as a fenced DATA block, so every `@[ai-text]` block in the recipe — and a
     * later refine of that text — writes to the SAME brief instead of being an island. With no direction
     * (kill switch off, derivation failed, or an isolated op on a session that never stored one) the prompt
     * is passed through BYTE-IDENTICALLY.
     */
    public function generate(string $prompt, ?string $personaId): string
    {
        if (trim($prompt) === '') {
            return '';
        }

        if ($this->calls >= $this->maxCalls()) {
            Log::warning('Generator ai-text: per-session call budget reached; directive resolved to empty.');

            return '';
        }

        $this->calls++;

        return $this->generator->generate(
            $this->withDirection($prompt),
            $personaId,
            (int) config('generator.ai_text_max_chars', 5000),
            self::PURPOSE_HINT,
            self::LENGTH_GUIDANCE,
        );
    }

    /**
     * Prefix the resolved prompt with the run's creative DIRECTION as a fenced DATA section, or return it
     * UNCHANGED when there is none. The direction is model-DERIVED content (laundered from slot values), so
     * it may only ever ride the USER message — never the agent's system instruction, whose prompt-is-DATA
     * hardening is exactly what frames this block (D7).
     *
     * VOICE WINS ON TONE: a delegated run already carries the bot's voice in the system instruction, so the
     * direction's `tone` is dropped from the projection rather than competing with it.
     */
    private function withDirection(string $prompt): string
    {
        $section = $this->direction->direction()?->forText($this->voice->directive() === null);

        return $section === null ? $prompt : $section . "\n\n" . $prompt;
    }

    /**
     * Generate STRUCTURED text through a caller-supplied agent (the shot-list JSON agent) under the SAME
     * per-session call budget as {@see generate} — a shot-list call counts as ONE `ai_text` call and shares
     * the shared per-run ceiling, so a video_script run can't fan out into unbounded structured spend. Blank
     * prompt / exhausted budget → '' (fail-closed), delegated with the POST-length char cap; the shared
     * service owns the meter + fail-closed-to-'' posture. The prompt is never logged (only the budget fact).
     *
     * NO ambient DIRECTION prefix here (unlike {@see generate}): a structured caller is executor-reachable
     * and composes the direction projection its OWN agent needs (the shot list wants through-line / arc /
     * subject / setting / duration — NOT the text projection). Prefixing here too would put TWO different
     * projections of the same direction in one prompt.
     */
    public function generateWith(Agent $agent, string $prompt): string
    {
        if (trim($prompt) === '') {
            return '';
        }

        if ($this->calls >= $this->maxCalls()) {
            Log::warning('Generator ai-text: per-session call budget reached; structured directive resolved to empty.');

            return '';
        }

        $this->calls++;

        return $this->generator->generateWith($agent, $prompt, (int) config('generator.ai_text_max_chars', 5000));
    }

    /**
     * The per-session ai-text call ceiling (cost guard); config-driven. The literal fallback MUST equal the
     * shipped `config/generator.php` default — it is what applies if the key ever goes missing, and the run
     * job's TIMEOUT INVARIANT (max_calls x ai.text_timeout + the direction call < the job's 300s SIGALRM
     * window) is stated against that default. Pinned by GeneratorBudgetFallbackTest.
     */
    private function maxCalls(): int
    {
        return (int) config('generator.ai_text_max_calls_per_session', 4);
    }
}
