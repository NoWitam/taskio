<?php

namespace App\Modules\Variables\Services;

use App\Modules\Variables\Agents\AiTextAgent;
use App\Modules\Variables\Contracts\MeteredAiCall;
use App\Modules\Variables\Enums\AiPersona;
use App\Modules\Variables\Support\AiVoiceContext;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Agent;
use Throwable;

/**
 * The SHARED, fail-closed AI-text generator both Workflows and (R2) Generator resolve `@[ai-text]`
 * through. It runs the tool-less {@see AiTextAgent} with provider/model from config('ai'), trims and
 * length-caps the result, and NEVER throws — any provider/transport failure resolves to '' (the exact
 * fail-closed contract every callback of the resolver already follows).
 *
 * The provider call is routed through the {@see MeteredAiCall} seam so the cost METER can gate it
 * BEFORE spend (an over-cap workspace's provider call never fires) and record its token usage: the
 * closure RETURNS the agent's TextResponse, and the ledger reads `->usage` off it. The meter's
 * over-cap {@see \App\Modules\Variables\Exceptions\AiBudgetExceededException} is a Throwable, so it is
 * swallowed by the same fail-closed catch — an over-budget directive just resolves to ''.
 *
 * This is NOT the per-run budget: the Workflows-side decorator keeps its own INSTANCE call-count
 * budget and delegates here (Variables never names a Workflows class — the one-way boundary). Nothing
 * about the prompt (which may carry form values) is ever logged; only the fact of a failure.
 */
class AiTextGenerationService
{
    public function __construct(
        private MeteredAiCall $meter,
        private AiVoiceContext $voice,
    ) {}

    /**
     * Generate the text for one resolved ai-text prompt, or '' (fail-closed) on a blank prompt, an
     * over-cap budget, or any generation failure. $personaId colors the tone only (null = neutral);
     * $maxChars length-caps the output (a field's own DB limit still applies on top); $purposeHint
     * frames the lead instruction sentence for the calling field (null = a generic short field);
     * $lengthGuidance replaces the output contract's LENGTH rule for the calling field (null — every
     * Workflows path — keeps today's single-field clause byte-identical).
     *
     * $authorId is the calling BLOCK's author, ranked by {@see AiVoiceContext::effectiveDirective()} against
     * the ambient session voice. It is deliberately the LAST parameter: every existing call site passes the
     * earlier ones POSITIONALLY, so appending keeps them compiling untouched (callers that do have an author
     * pass it by NAME). Null + no session voice ⇒ the persona line, byte-identical to before.
     */
    public function generate(string $prompt, ?string $personaId, int $maxChars, ?string $purposeHint = null, ?string $lengthGuidance = null, ?string $authorId = null): string
    {
        $prompt = trim($prompt);

        if ($prompt === '') {
            return '';
        }

        $persona = AiPersona::fromNullable($personaId);

        // VOICE (R2 sub-stage 3): when a delegated run set the ambient voice, it REPLACES the resolved
        // persona in the agent's system instruction (D-E); when null — every non-delegated path, incl.
        // the whole Workflows side, which never sets it — behavior is BYTE-IDENTICAL to before.
        //
        // PER-BLOCK AUTHOR (R2, this sub-stage): the holder ranks the block's own author ABOVE the session
        // voice and hands back whichever applies. An author that resolved to nothing (deleted / foreign /
        // malformed) is not in the map, so this falls back to the session voice and then to the persona —
        // fail-SAFE by construction, and with no author and no session voice the result is unchanged.
        $voice = $this->voice->effectiveDirective($authorId);

        try {
            $response = $this->meter->meter('ai_text', fn () => (new AiTextAgent($persona, $purposeHint, $voice, $lengthGuidance))->prompt(
                prompt: $prompt,
                provider: config('ai.provider'),
                model: config('ai.model'),
            ));

            $text = trim((string) ($response->text ?? ''));
        } catch (Throwable $e) {
            // Fail-closed. Covers a provider/transport failure AND the meter's over-cap gate
            // (AiBudgetExceededException) — both resolve to '' so a directive never breaks a run.
            // Only the failure FACT is logged, never the prompt (it may carry user form values).
            Log::warning('AI text generation failed: ' . $e->getMessage());

            return '';
        }

        return $this->truncate($text, $maxChars);
    }

    /**
     * Generate text through a CALLER-SUPPLIED agent (a structured/JSON-contract agent, e.g. the Generator's
     * shot-list agent), metered + length-capped + FAIL-CLOSED to '' exactly like {@see generate}. The agent
     * carries its own baked instruction + output contract; this only owns the metered, gate-before-spend
     * provider call (channel `ai_text`) and the fail-closed posture. The prompt is DATA the agent frames as
     * such (injection-hardened) — it is NEVER logged (only the failure fact). Returns the RAW model text so
     * the caller can defensively parse it (never trusting the model to emit clean JSON).
     *
     * $timeout OPTIONALLY overrides the per-call provider timeout (laravel/ai defaults to 60s). NULL — every
     * existing caller — makes the provider call BYTE-IDENTICAL to before; a caller with its own latency
     * budget (the creative-direction derivation, which runs INSIDE the run job's fixed SIGALRM window) passes
     * a tighter one so a hung provider cannot eat the whole job timeout.
     */
    public function generateWith(Agent $agent, string $prompt, int $maxChars, ?int $timeout = null): string
    {
        $prompt = trim($prompt);

        if ($prompt === '') {
            return '';
        }

        try {
            $response = $this->meter->meter('ai_text', fn () => $timeout === null
                ? $agent->prompt(
                    prompt: $prompt,
                    provider: config('ai.provider'),
                    model: config('ai.model'),
                )
                : $agent->prompt(
                    prompt: $prompt,
                    provider: config('ai.provider'),
                    model: config('ai.model'),
                    timeout: $timeout,
                ));

            $text = trim((string) ($response->text ?? ''));
        } catch (Throwable $e) {
            // Fail-closed, identical to generate(): a provider/transport failure AND the meter's over-cap
            // gate (AiBudgetExceededException) both resolve to '' so a structured directive never breaks a run.
            Log::warning('AI structured text generation failed: ' . $e->getMessage());

            return '';
        }

        return $this->truncate($text, $maxChars);
    }

    /** Length-cap the generated text to $max (multibyte-safe); $max <= 0 means no cap. */
    private function truncate(string $text, int $max): string
    {
        return $max > 0 && mb_strlen($text) > $max ? mb_substr($text, 0, $max) : $text;
    }
}
