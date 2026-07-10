<?php

namespace App\Modules\Workflows\Services;

use App\Modules\Workflows\Agents\ScheduleAssistAgent;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Orchestrates the AI SCHEDULE ASSIST (B5): a natural-language schedule description in, a
 * structured (and SERVER-TRUSTWORTHY) schedule config out.
 *
 * The model's self-report is NEVER trusted. The flow is:
 *   1. rate-limit the caller (per user) — exceeded throws a 429 (bounds AI spend; NOT counted
 *      against the workflow run budget, which meters real runs, not planning calls).
 *   2. run the tool-less ScheduleAssistAgent and read its TEXT (a JSON string).
 *   3. parse that JSON defensively — anything malformed collapses to a safe feasible:false
 *      envelope with a generic explanation (never a 500).
 *   4. RE-VALIDATE every returned config against the SAME rules the write path uses
 *      (WorkflowScheduleRulesValidator) AND run the compiler as a final sanity gate:
 *        - main `config` failing => downgrade feasible:false, config:null, and append a
 *          validation-derived note to `unsupported`.
 *        - `alternative.config` failing => drop the alternative.
 *   5. whitelist-pick the response keys (never merge unknown model keys into the API payload).
 */
class WorkflowScheduleAssistService
{
    public function __construct(
        private WorkflowScheduleRulesValidator $rules,
        private WorkflowScheduleCompiler $compiler,
    ) {}

    /**
     * Produce the assist envelope for $prompt on behalf of $userId. $tz is an optional caller
     * timezone hint (already validated as a timezone by the FormRequest) merged into a surviving
     * config when the model omitted one.
     *
     * @return array{feasible: bool, config: array<string, mixed>|null, unsupported: array<int, string>, alternative: array{config: array<string, mixed>, note: string}|null, explanation: string}
     *
     * @throws ThrottleRequestsException when the per-user rate limit is exceeded (renders 429)
     */
    public function assist(string $prompt, string $userId, ?string $tz = null, string $language = 'pl'): array
    {
        $this->enforceRateLimit($userId);

        $raw = $this->run($prompt, $language);

        return $this->revalidate($this->parse($raw), $tz);
    }

    /**
     * Per-user throttle keyed `workflow-schedule-assist:{userId}`, limit from config. A separate
     * meter from the run budget on purpose (a planning call creates nothing). Consumes a hit and
     * throws the standard throttle exception (429) once the window is exhausted.
     */
    private function enforceRateLimit(string $userId): void
    {
        $key = 'workflow-schedule-assist:' . $userId;
        $max = (int) config('workflows.assist_rate_per_minute', 5);

        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw new ThrottleRequestsException(
                'Zbyt wiele prób pomocy AI w planowaniu harmonogramu. Spróbuj ponownie za chwilę.',
            );
        }

        RateLimiter::hit($key, 60);
    }

    /**
     * Run the tool-less agent and return its raw TEXT. A provider/transport failure is not a 500
     * for the caller — it collapses to the same generic feasible:false envelope as malformed JSON.
     */
    private function run(string $prompt, string $language): string
    {
        try {
            $response = (new ScheduleAssistAgent($language))->prompt(
                prompt: $prompt,
                provider: config('ai.provider'),
                model: config('ai.model'),
            );

            return (string) ($response->text ?? '');
        } catch (Throwable $e) {
            Log::warning('Schedule assist agent failed: ' . $e->getMessage());

            return '';
        }
    }

    /**
     * Parse the model's JSON text into the strict envelope, whitelisting ONLY the known keys.
     * Malformed/empty/non-object output yields the safe generic feasible:false envelope. Unknown
     * keys the model may have invented are dropped here — they never reach the API response.
     *
     * @return array{feasible: bool, config: array<string, mixed>|null, unsupported: array<int, string>, alternative: array{config: array<string, mixed>, note: string}|null, explanation: string}
     */
    private function parse(string $raw): array
    {
        $decoded = json_decode(trim($raw), true);

        if (!is_array($decoded)) {
            return $this->generic();
        }

        return [
            'feasible' => (bool) ($decoded['feasible'] ?? false),
            'config' => $this->pickConfig($decoded['config'] ?? null),
            'unsupported' => $this->stringList($decoded['unsupported'] ?? []),
            'alternative' => $this->pickAlternative($decoded['alternative'] ?? null),
            'explanation' => is_string($decoded['explanation'] ?? null) ? $decoded['explanation'] : '',
        ];
    }

    /**
     * Re-validate the parsed envelope against the write-path rules + compiler. This is where the
     * model stops being trusted:
     *   - a main `config` that fails validation or compile is downgraded (feasible:false, config
     *     null) and the failure reason is appended to `unsupported`.
     *   - an `alternative.config` that fails is dropped (alternative:null).
     * A surviving config with no tz inherits the caller's $tz hint (when supplied).
     *
     * @param  array{feasible: bool, config: array<string, mixed>|null, unsupported: array<int, string>, alternative: array{config: array<string, mixed>, note: string}|null, explanation: string}  $envelope
     * @return array{feasible: bool, config: array<string, mixed>|null, unsupported: array<int, string>, alternative: array{config: array<string, mixed>, note: string}|null, explanation: string}
     */
    private function revalidate(array $envelope, ?string $tz): array
    {
        // Main config: only trusted when the model claimed feasible AND it survives the gate.
        if ($envelope['feasible'] && $envelope['config'] !== null) {
            $config = $this->applyTz($envelope['config'], $tz);
            $errors = $this->gate($config);

            if ($errors === []) {
                $envelope['config'] = $config;
            } else {
                $envelope['feasible'] = false;
                $envelope['config'] = null;
                $envelope['unsupported'] = array_values(array_unique(array_merge($envelope['unsupported'], $errors)));
            }
        } else {
            // A model that says feasible:false (or gives no config) yields no config, always.
            $envelope['feasible'] = false;
            $envelope['config'] = null;
        }

        // Alternative: dropped unless its config survives the same gate.
        if ($envelope['alternative'] !== null) {
            $altConfig = $this->applyTz($envelope['alternative']['config'], $tz);

            if ($this->gate($altConfig) === []) {
                $envelope['alternative']['config'] = $altConfig;
            } else {
                $envelope['alternative'] = null;
            }
        }

        return $envelope;
    }

    /**
     * The shared gate a config must pass to be trusted: the write-path schedule rules AND a
     * successful compile (a config valid on paper but that the compiler rejects is still unusable).
     * Returns the list of failure messages ([] when the config is fully valid).
     *
     * @param  array<string, mixed>  $config
     * @return array<int, string>
     */
    private function gate(array $config): array
    {
        $errors = $this->rules->validate($config);

        if ($errors !== []) {
            return $errors;
        }

        try {
            $this->compiler->compile($config);
        } catch (Throwable $e) {
            return ['Nie udało się skompilować harmonogramu: ' . $e->getMessage()];
        }

        return [];
    }

    /**
     * Merge the caller's tz hint into a config that lacks one. An explicit tz from the model wins.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function applyTz(array $config, ?string $tz): array
    {
        if ($tz !== null && ($config['tz'] ?? null) === null) {
            $config['tz'] = $tz;
        }

        return $config;
    }

    /**
     * Whitelist a config object to { family, params, tz, times, exclusions } — drops any extra keys
     * the model added. `times` and `exclusions` are the optional extensions; a non-array value for
     * either is passed through as-is so the gate's rules reject the bad shape (the whitelist only
     * strips UNKNOWN keys, it never pre-judges the VALUE of a known one). A non-object config
     * becomes null (nothing to validate).
     *
     * @return array<string, mixed>|null
     */
    private function pickConfig(mixed $config): ?array
    {
        if (!is_array($config)) {
            return null;
        }

        $picked = [
            // A non-string family stays as-is here and is rejected by the enum rule in gate()
            // — the whitelist only strips UNKNOWN keys, it does not pre-judge values.
            'family' => $config['family'] ?? null,
            'params' => is_array($config['params'] ?? null) ? $config['params'] : [],
        ];

        if (array_key_exists('tz', $config) && (is_string($config['tz']) || $config['tz'] === null)) {
            $picked['tz'] = $config['tz'];
        }

        if (array_key_exists('times', $config)) {
            $picked['times'] = $config['times'];
        }

        if (array_key_exists('exclusions', $config)) {
            $picked['exclusions'] = $config['exclusions'];
        }

        return $picked;
    }

    /**
     * Whitelist an alternative to { config, note }. Null unless it carries a pickable config.
     *
     * @return array{config: array<string, mixed>, note: string}|null
     */
    private function pickAlternative(mixed $alternative): ?array
    {
        if (!is_array($alternative)) {
            return null;
        }

        $config = $this->pickConfig($alternative['config'] ?? null);

        if ($config === null) {
            return null;
        }

        return [
            'config' => $config,
            'note' => is_string($alternative['note'] ?? null) ? $alternative['note'] : '',
        ];
    }

    /**
     * Coerce a model-supplied list to a flat array of strings (dropping non-strings).
     *
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }

    /**
     * The safe fallback envelope for a malformed/absent model response — honest infeasibility with
     * a generic explanation, never a 500.
     *
     * @return array{feasible: bool, config: null, unsupported: array<int, string>, alternative: null, explanation: string}
     */
    private function generic(): array
    {
        return [
            'feasible' => false,
            'config' => null,
            'unsupported' => [],
            'alternative' => null,
            'explanation' => 'Nie udało się przetworzyć opisu harmonogramu. Spróbuj opisać go inaczej lub ustaw harmonogram ręcznie.',
        ];
    }
}
