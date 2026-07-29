<?php

namespace App\Modules\Generator\Services;

use App\Modules\Generator\Agents\ShotListAgent;
use App\Modules\Generator\Support\CreativeDirection;
use App\Modules\Generator\Support\JsonObjectExtractor;
use App\Modules\Variables\Support\AiVoiceContext;

/**
 * Turns a creative BRIEF (or a directed REVISION of a current shot list) into a STRUCTURED short-video shot
 * list (video_script rework Phase B) — the single responsibility of this class. It composes the model input,
 * makes ONE metered structured AI call through the shared, budgeted, fail-closed ai-text seam
 * ({@see GeneratorAiTextService::generateWith} → {@see \App\Modules\Variables\Services\AiTextGenerationService})
 * — counting as ONE `ai_text` call on the per-session budget — and DEFENSIVELY parses the raw model text into a
 * normalized shape, NEVER throwing to the caller.
 *
 * STRUCTURED-OUTPUT SPIKE: laravel/ai v0.4.3 supports native JSON-schema output, but we use PROMPT-AND-PARSE
 * (see {@see ShotListAgent}) — the strict JSON contract is baked into the agent instruction and this parser is
 * the belt-and-suspenders the contract requires either way. It:
 *   - strips a ```json … ``` fence if present, then balance-scans for the FIRST top-level JSON object,
 *   - decodes + coerces: hook→string, cta→string, shots→list of {visual:string, voiceover:string, seconds:int>=0}
 *     (malformed shot entries dropped), CLAMPED to the run's EFFECTIVE shot cap (passed in by the executor —
 *     min(authored `content.storyboard.max_shots`, the `generator.storyboard_max_shots` platform ceiling)),
 *   - flattens a readable `text` (the `parts.shot_list` contribution + the FE display body).
 *
 * RETURN CONTRACT:
 *   - a normalized array {hook, shots:[…], cta, text, parse_ok:true} on a well-formed JSON object,
 *   - {hook:'', shots:[], cta:'', text:<raw>, parse_ok:false} when the model returned NON-blank but unparseable
 *     (keep the raw as text so the author sees what came back),
 *   - NULL when the call returned BLANK (a failed / over-cap / empty provider reply) — the caller fails the
 *     part soft.
 * The brief / instruction / model output are DATA and are NEVER logged (only failure facts, elsewhere).
 */
class ShotListRenderer
{
    public function __construct(
        private GeneratorAiTextService $aiText,
        private AiVoiceContext $voice,
    ) {}

    /**
     * Generate a fresh shot list from a (resolved) creative brief. Null when the model returns blank.
     *
     * $maxShots is the run's EFFECTIVE shot cap, threaded in EXPLICITLY by the executor (the single source
     * of truth — see {@see \App\Modules\Generator\Services\GenerationSessionExecutor::effectiveShotCap}) so
     * the agent's instructed bound, this parse clamp and the storyboard fan-out cannot drift.
     * $direction is the run's derived creative direction (B2) or null; its shot-list projection rides the
     * USER message as a fenced DATA block (never the system instruction — D7).
     *
     * @return array{hook: string, shots: array<int, array{visual: string, voiceover: string, seconds: int}>, cta: string, text: string, parse_ok: bool}|null
     */
    public function generate(string $brief, int $maxShots, ?CreativeDirection $direction = null): ?array
    {
        return $this->call("Write the shot list for this creative brief.\n\nCREATIVE BRIEF:\n" . $brief, $maxShots, $direction);
    }

    /**
     * Revise a CURRENT shot list per a (resolved) instruction — the shot_list refine op. The current list is
     * passed as JSON DATA + the instruction as the directed change; the model returns a NEW full shot list.
     * Null when the model returns blank (a failed no-op the refiner preserves the current list for).
     *
     * @param  array{hook?: mixed, shots?: mixed, cta?: mixed, text?: mixed}  $current  the current shot_list result
     * @return array{hook: string, shots: array<int, array{visual: string, voiceover: string, seconds: int}>, cta: string, text: string, parse_ok: bool}|null
     */
    public function revise(array $current, string $instruction, int $maxShots, ?CreativeDirection $direction = null): ?array
    {
        return $this->call($this->revisePrompt($current, $instruction), $maxShots, $direction);
    }

    /**
     * Make the one structured call and parse the result. A blank reply → null (fail-soft); otherwise the
     * defensively-parsed normalized shape.
     *
     * @return array{hook: string, shots: array<int, array{visual: string, voiceover: string, seconds: int}>, cta: string, text: string, parse_ok: bool}|null
     */
    private function call(string $prompt, int $maxShots, ?CreativeDirection $direction): ?array
    {
        // The shot-list PROJECTION of the run's direction, as a fenced DATA section prefixing the USER
        // message — or null when there is no direction (or it carries nothing this consumer uses, e.g. a
        // visual-only direction). Then the prompt is BYTE-IDENTICAL to the pre-direction composition.
        // TONE is dropped while a bot VOICE is active: the delegated voice wins on tone.
        $section = $direction?->forShotList($this->voice->directive() === null);

        // VOICE (R2 sub-stage 3): a delegated run sets the ambient voice, which the agent folds in as a
        // scoped tone clause (the JSON contract stays authoritative). Null on every non-delegated run, so
        // the agent + this parse are byte-identical to before. generateWith() itself stays voice-agnostic
        // (the CALLER builds the voice-aware agent), so the meter path is unchanged.
        // DIRECTION (B2): only the FACT that a direction block ACTUALLY rides the prompt reaches the agent —
        // a trusted, content-free framing flag. The block's content stays in the USER message (D7).
        $agent = new ShotListAgent($this->voice->directive(), $maxShots, $section !== null);

        $raw = $this->aiText->generateWith($agent, $section === null ? $prompt : $section . "\n\n" . $prompt);

        return $this->parse($raw, $maxShots);
    }

    /**
     * DEFENSIVE parse of the raw model text. Blank → null. A decodable JSON object → parse_ok:true normalized.
     * A non-blank, non-decodable reply → parse_ok:false with the raw kept as text + no shots.
     *
     * BARE-ARRAY handling: a model may deviate from the object contract and return a TOP-LEVEL JSON ARRAY of
     * shots (`[{"visual":…,"voiceover":…,"seconds":…}, …]`). The object-scanner below would grab that array's
     * FIRST inner `{…}` and read a single shot as the root — silently yielding `parse_ok:true, shots:[]` and
     * DISCARDING the list. So a de-fenced reply that decodes to a JSON LIST is caught first and treated as the
     * candidate shots array: it runs through the SAME {@see normalizeShots} and, if it yields ≥1 valid shot, a
     * normalized `{hook:'', shots:[…], cta:'', …, parse_ok:true}`; if it yields none (not actually shots), the
     * usual non-blank-unparseable path (`parse_ok:false`, raw kept as text) — never a silent `parse_ok:true`.
     *
     * @return array{hook: string, shots: array<int, array{visual: string, voiceover: string, seconds: int}>, cta: string, text: string, parse_ok: bool}|null
     */
    private function parse(string $raw, int $maxShots): ?array
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        $list = $this->extractJsonList($raw);

        if ($list !== null) {
            $shots = $this->normalizeShots($list, $maxShots);

            if ($shots === []) {
                return ['hook' => '', 'shots' => [], 'cta' => '', 'text' => $raw, 'parse_ok' => false];
            }

            return [
                'hook' => '',
                'shots' => $shots,
                'cta' => '',
                'text' => $this->flatten('', $shots, ''),
                'parse_ok' => true,
            ];
        }

        $json = $this->extractJsonObject($raw);
        $decoded = $json === null ? null : json_decode($json, true);

        if (!is_array($decoded)) {
            return ['hook' => '', 'shots' => [], 'cta' => '', 'text' => $raw, 'parse_ok' => false];
        }

        $hook = is_string($decoded['hook'] ?? null) ? trim($decoded['hook']) : '';
        $cta = is_string($decoded['cta'] ?? null) ? trim($decoded['cta']) : '';
        $shots = $this->normalizeShots($decoded['shots'] ?? null, $maxShots);

        return [
            'hook' => $hook,
            'shots' => $shots,
            'cta' => $cta,
            'text' => $this->flatten($hook, $shots, $cta),
            'parse_ok' => true,
        ];
    }

    /**
     * Coerce the model's shots to a clean ordered list of {visual, voiceover, seconds}. A shot missing a
     * string visual/voiceover is DROPPED (malformed); seconds is coerced to a non-negative int (0 when
     * absent/invalid). The list is CLAMPED to the run's EFFECTIVE shot cap so a runaway model can't inflate
     * the cost (the storyboard iterates the SAME cap — one source, threaded in by the executor).
     *
     * @return array<int, array{visual: string, voiceover: string, seconds: int}>
     */
    private function normalizeShots(mixed $shots, int $maxShots): array
    {
        if (!is_array($shots)) {
            return [];
        }

        $cap = max(1, $maxShots);
        $out = [];

        foreach (array_values($shots) as $shot) {
            if (count($out) >= $cap) {
                break;
            }

            if (!is_array($shot) || !is_string($shot['visual'] ?? null) || !is_string($shot['voiceover'] ?? null)) {
                continue;
            }

            $visual = trim($shot['visual']);
            $voiceover = trim($shot['voiceover']);

            if ($visual === '' && $voiceover === '') {
                continue;
            }

            $out[] = [
                'visual' => $visual,
                'voiceover' => $voiceover,
                'seconds' => $this->coerceSeconds($shot['seconds'] ?? null),
            ];
        }

        return $out;
    }

    /** A shot's seconds as a non-negative int (a numeric string / float rounds; anything else → 0). */
    private function coerceSeconds(mixed $seconds): int
    {
        if (is_int($seconds)) {
            return max(0, $seconds);
        }

        if (is_numeric($seconds)) {
            return max(0, (int) round((float) $seconds));
        }

        return 0;
    }

    /**
     * The BARE-ARRAY escape hatch: strip an optional ```json … ``` fence, then decode the WHOLE reply — when it
     * is a top-level JSON LIST (`array_is_list`, a plausible deviation from the object contract) return it as
     * the candidate shots array, else null (an object / prose-wrapped reply falls through to the object scan).
     * Kept separate from {@see extractJsonObject} so the proven object path is untouched.
     *
     * @return array<int, mixed>|null
     */
    private function extractJsonList(string $raw): ?array
    {
        $unfenced = (string) preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($raw));
        $decoded = json_decode($unfenced, true);

        return is_array($decoded) && array_is_list($decoded) ? $decoded : null;
    }

    /**
     * Strip an optional ```json … ``` (or bare ``` … ```) fence, then balance-scan for the FIRST complete
     * top-level JSON object. The scan itself lives in the SHARED {@see JsonObjectExtractor} (extracted, not
     * forked, so the creative-direction parse uses the SAME proven string/escape-aware walk).
     */
    private function extractJsonObject(string $raw): ?string
    {
        return JsonObjectExtractor::extract($raw);
    }

    /**
     * The readable flattening: the hook, each `Shot N: visual / voiceover (Ns)`, then the cta — used for the
     * FE display body AND the `parts.shot_list` cross-part contribution (a later text part could reference the
     * script). Empty pieces are skipped so a blank hook/cta doesn't leave dangling separators.
     *
     * @param  array<int, array{visual: string, voiceover: string, seconds: int}>  $shots
     */
    private function flatten(string $hook, array $shots, string $cta): string
    {
        $lines = [];

        if ($hook !== '') {
            $lines[] = $hook;
        }

        foreach ($shots as $i => $shot) {
            $lines[] = 'Shot ' . ($i + 1) . ': ' . $shot['visual'] . ' / ' . $shot['voiceover'] . ' (' . $shot['seconds'] . 's)';
        }

        if ($cta !== '') {
            $lines[] = $cta;
        }

        return implode("\n", $lines);
    }

    /**
     * The model input for a revision: the current shot list as JSON DATA + the directed instruction (the
     * run's direction block, when present, is prefixed by {@see call} so a revision stays inside the SAME
     * creative frame as the original). The agent frames the whole request as data (injection-hardened), so
     * the instruction guides the rewrite only.
     *
     * @param  array{hook?: mixed, shots?: mixed, cta?: mixed, text?: mixed}  $current
     */
    private function revisePrompt(array $current, string $instruction): string
    {
        $currentJson = json_encode([
            'hook' => is_string($current['hook'] ?? null) ? $current['hook'] : '',
            'shots' => is_array($current['shots'] ?? null) ? $current['shots'] : [],
            'cta' => is_string($current['cta'] ?? null) ? $current['cta'] : '',
        ]);

        return 'Revise the shot list below according to the instruction. Return the FULL revised shot list as a '
            . "JSON object in the same shape.\n\nINSTRUCTION:\n" . $instruction . "\n\nCURRENT SHOT LIST:\n" . (string) $currentJson;
    }
}
