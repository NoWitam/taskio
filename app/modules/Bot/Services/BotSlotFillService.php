<?php

namespace App\Modules\Bot\Services;

use App\Modules\Bot\Agents\BotSlotFillAgent;
use App\Modules\Bot\Enums\SlotFillMode;
use App\Modules\Generator\Models\GenerationSession;
use App\Modules\Generator\Services\SessionDelegationService;
use App\Modules\Variables\Enums\VariableType;
use App\Modules\Variables\Services\AiTextGenerationService;
use App\Modules\Variables\Support\MeterContext;

/**
 * Runs the AUTONOMOUS slot-fill for a delegation (R2 sub-stage 3): it turns the session's IN-SCOPE slot
 * schema into a prose prompt, makes ONE metered structured AI call, defensively parses the model's
 * `{slotName: value}` map, and hands it to the Generator's re-validating persist path — returning the fill
 * report.
 *
 * WHICH slots it offers is the human's explicit click-time choice ({@see SlotFillMode}, `fill_mode` on the
 * delegate endpoint), NOT a property of the engine:
 *   - `gaps` (the default) offers only the EMPTY in-scope slots, passes the human's own inputs as read-only
 *     context, and — the server-side half — REFUSES any already-filled slot coming back from the model. With
 *     no gap at all it returns immediately: no prompt, no provider call, nothing billed (`nothing_to_fill`).
 *   - `fresh` offers every in-scope slot and demands a DIFFERENT take on the values already there; undo stays
 *     a full restore because the overlay snapshotted `slot_values_before` before any of this ran.
 * Each call also carries a one-off VARIATION TOKEN so repeated delegations are not the same request twice
 * (see {@see variationLine} — it is load-bearing, not noise).
 *
 * The single AI call goes STRAIGHT through the shared, budgeted, fail-closed {@see AiTextGenerationService::generateWith}
 * seam (ONE `ai_text` call, gate-before-spend on the monthly cap) — NOT the per-run GeneratorAiTextService
 * budget, because this is a PRE-run fill, not part of a generation run. The ambient {@see MeterContext} is
 * tagged with the session so the spend attributes to it, and cleared in a `finally`. FAIL-CLOSED exactly
 * like {@see \App\Modules\Generator\Services\ShotListRenderer}: a blank / over-cap / unparseable reply fills
 * NOTHING (the session stays a draft) — never throws. The schema, values, and model output are DATA and are
 * NEVER logged.
 *
 * BOUNDARY: it names ONLY Generator + Variables classes — the one-way Bot → Generator/Variables edge. The
 * per-slot re-validation (NUL bytes, type, scope) lives in {@see SessionDelegationService::applyBotSlotValues},
 * so this class never trusts the raw model map.
 */
class BotSlotFillService
{
    /**
     * Length cap on the RAW model reply (the JSON map). Generous — a runaway reply is truncated before the
     * defensive parse, and each value is re-validated + clamped downstream anyway.
     */
    private const MAX_CHARS = 8000;

    /**
     * Length cap on ONE slot's PREVIOUS/CONTEXT value as rendered into the prompt. A slot can legitimately
     * hold a long brief; echoing an unbounded one back would let a single slot crowd out the schema (and the
     * request) for no benefit — the model only needs enough of it to write something coherent / different.
     */
    private const MAX_VALUE_CHARS = 600;

    public function __construct(
        private SessionDelegationService $delegation,
        private AiTextGenerationService $aiText,
        private MeterContext $meterContext,
    ) {}

    /**
     * Autonomously fill $session's in-scope slots under $mode and persist the type-valid ones. Returns the
     * per-slot fill report ({@see SessionDelegationService::applyBotSlotValues}) PLUS the `mode` that actually
     * ran and `nothing_to_fill`.
     *
     * NO AI CALL — and therefore NO SPEND — when the mode has nothing to offer: a session with no bot-fillable
     * slots at all, or (the `gaps` case, defect 1) one whose every in-scope slot is already filled. That is the
     * single early return below: it is reached BEFORE the prompt is even built, so "nothing to fill" costs
     * nothing. The report still reports the unfilled required slots, and the caller has already stamped the
     * delegation overlay (the bot still becomes the author — that part is not about slots).
     *
     * @return array{filled: array<int, string>, skipped: array<int, array{name: string, reason: string}>, unfilled_required: array<int, string>, mode: string, nothing_to_fill: bool}
     */
    public function fill(GenerationSession $session, SlotFillMode $mode = SlotFillMode::Gaps): array
    {
        $slots = $this->delegation->introspectSlots($session);

        // The SCOPE (which descriptors are offerable at all) stays entirely the Generator's SlotScopePolicy —
        // the mode only FILTERS that output, never re-implements it.
        $offered = $mode === SlotFillMode::Gaps ? $this->emptySlots($slots) : $slots;

        if ($offered === []) {
            return $this->report($this->delegation->applyBotSlotValues($session, []), $mode, true);
        }

        $prompt = $this->schemaPrompt($mode, $offered, $mode === SlotFillMode::Gaps ? $this->filledSlots($slots) : []);

        $this->meterContext->setSession($session->id);
        // ACTOR (R2 sub-stage 4): this autonomous PRE-run fill has no auth()/run, so tag the meter
        // EXPLICITLY with the session's actor (the bot author for a delegation) so the fill spend
        // attributes to the bot. Cleared in the SAME finally as the session tag.
        [$actorType, $actorId] = $session->meterActor();
        $this->meterContext->setActor($actorType, $actorId);

        try {
            $raw = $this->aiText->generateWith(new BotSlotFillAgent, $prompt, self::MAX_CHARS);
        } finally {
            $this->meterContext->clearSession();
            $this->meterContext->clearActor();
        }

        [$proposed, $refused] = $this->restrictToOffered($this->parse($raw), $slots, $offered);

        $report = $this->delegation->applyBotSlotValues($session, $proposed);
        $report['skipped'] = array_merge($report['skipped'], $refused);

        return $this->report($report, $mode, false);
    }

    /**
     * The SERVER-SIDE half of the `gaps` promise: a proposed key that names an in-scope slot which was NOT
     * offered under this mode (i.e. a slot the human already filled) is REFUSED here, before the persist path
     * ever sees it — so "never touch a value the human typed" holds even if the model ignores the instruction
     * or an injected slot description talks it into filling everything. The prompt is a request, this is the
     * authority (security.md: server-side authoritative).
     *
     * A key that is NOT a declared in-scope slot is passed THROUGH untouched, so the Generator seam keeps
     * classifying it exactly as before (`unknown_slot` / `out_of_scope`) — this refusal only adds the one
     * outcome that could not previously happen.
     *
     * @param  array<string, mixed>  $values  the untrusted {slotName: value} map from the model
     * @param  array<int, array{name: string, ...}>  $inScope  every in-scope slot (introspectSlots' output)
     * @param  array<int, array{name: string, ...}>  $offered  the subset this mode actually asked for
     * @return array{0: array<string, mixed>, 1: array<int, array{name: string, reason: string}>}
     */
    private function restrictToOffered(array $values, array $inScope, array $offered): array
    {
        $inScopeNames = $this->nameSet($inScope);
        $offeredNames = $this->nameSet($offered);

        $kept = [];
        $refused = [];

        foreach ($values as $name => $value) {
            $name = (string) $name;

            if (isset($inScopeNames[$name]) && !isset($offeredNames[$name])) {
                $refused[] = ['name' => $name, 'reason' => 'already_filled'];

                continue;
            }

            $kept[$name] = $value;
        }

        return [$kept, $refused];
    }

    /**
     * Build the prose slot-SCHEMA prompt for $mode. One line per slot to fill (name, a human type summary and
     * the description) framed as the DATA to fill; the behavioural rules themselves live in the agent's system
     * instruction, so this only says WHICH mode is running and WHAT the slots are.
     *
     *   - GAPS  the slots to fill carry NO value (they are empty by definition — that was defect 1: listing a
     *           `[current: …]` with no instruction to change it made the model echo it straight back). The
     *           already-filled slots follow as an explicitly READ-ONLY context block so the proposal stays
     *           coherent with what the human wrote.
     *   - FRESH every slot is listed, its existing value labelled the author's PREVIOUS take, with an explicit
     *           demand for a different one.
     *
     * The context block deliberately carries only IN-SCOPE slots — never a file slot's snapshot (which would
     * put a signed URL into a provider request for no benefit).
     *
     * @param  array<int, array{name: string, description: string|null, descriptor: array<string, mixed>, value: mixed}>  $offered
     * @param  array<int, array{name: string, description: string|null, descriptor: array<string, mixed>, value: mixed}>  $context
     */
    private function schemaPrompt(SlotFillMode $mode, array $offered, array $context): string
    {
        $header = $mode === SlotFillMode::Fresh
            ? 'MODE: FRESH — you are taking this session over. Propose a NEW value for EVERY slot listed below and return the JSON object mapping each slot name to its value.'
            : 'MODE: GAPS — fill ONLY the slots listed below and return the JSON object mapping each of those slot names to its value.';

        $listHeader = $mode === SlotFillMode::Fresh
            ? 'SLOTS TO FILL — where a PREVIOUS value written by the author is shown, your proposal MUST differ from it: do not repeat it verbatim and do not merely reword it.'
            : 'SLOTS TO FILL (all currently empty):';

        $lines = [];

        foreach ($offered as $slot) {
            $line = $this->slotLine($slot);

            if ($mode === SlotFillMode::Fresh && !$this->isEmptyValue($slot['value'] ?? null)) {
                $line .= ' [previous: ' . $this->encodeValue($slot['value']) . ']';
            }

            $lines[] = $line;
        }

        $prompt = $header . "\n" . $this->variationLine() . "\n\n" . $listHeader . "\n" . implode("\n", $lines);

        if ($context === []) {
            return $prompt;
        }

        $contextLines = [];

        foreach ($context as $slot) {
            $contextLines[] = $this->slotLine($slot) . ' = ' . $this->encodeValue($slot['value']);
        }

        return $prompt . "\n\nAUTHOR CONTEXT — inputs the human has ALREADY written. Read-only: stay coherent"
            . " with them, never return these names and never restate their values.\n" . implode("\n", $contextLines);
    }

    /** One schema line: `- name (type): description` — the shape both the fill list and the context block share. */
    private function slotLine(array $slot): string
    {
        $line = '- ' . $slot['name'] . ' (' . $this->describeType($slot['descriptor']) . ')';

        if (($slot['description'] ?? null) !== null && $slot['description'] !== '') {
            $line .= ': ' . $slot['description'];
        }

        return $line;
    }

    /**
     * The per-call VARIATION TOKEN line. **DO NOT DELETE THIS AS NOISE** — it is the fix for the second
     * owner-reported defect ("it keeps returning the same values"): the prompt used to be BYTE-IDENTICAL on
     * every delegation of the same session, which is exactly the input a provider (and its prompt cache) turns
     * into the same completion. A one-off token makes each attempt a distinct request.
     *
     * WHY NOT temperature: the shared, budgeted seam ({@see AiTextGenerationService::generateWith}) reaches the
     * provider through laravel/ai's `Agent::prompt()`, whose signature carries NO sampling knob — temperature is
     * read by REFLECTION off a compile-time `#[Temperature]` class attribute
     * ({@see \Laravel\Ai\Gateway\TextGenerationOptions::forAgent}). It is therefore a constant baked into an
     * agent class, not something the seam can carry per call or a config default can drive, so the only
     * per-call knob available on that seam is the message itself.
     *
     * The token is meaningless by construction (hex, no semantics), the agent instruction tells the model to
     * ignore it, it cannot affect the defensive parse (it never appears in the reply we accept — and a value
     * echoing it is still re-validated per descriptor like any other), and — like the rest of the prompt — it
     * is NEVER logged.
     */
    private function variationLine(): string
    {
        return 'VARIATION TOKEN (a meaningless one-off marker, not content): ' . bin2hex(random_bytes(4));
    }

    /**
     * Render one slot value into the prompt: compact JSON (so the model sees the exact typed shape), length-capped.
     */
    private function encodeValue(mixed $value): string
    {
        $encoded = (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return mb_strlen($encoded) > self::MAX_VALUE_CHARS ? mb_substr($encoded, 0, self::MAX_VALUE_CHARS) . '…' : $encoded;
    }

    /**
     * The in-scope slots with NO usable value — the `gaps` offer. "Empty" MIRRORS the Generator's own
     * unfilled-required rule (null / '' / []), so a gap here is the same thing the session resource already
     * calls unfilled. Note `false` and `0` are VALUES, not gaps.
     *
     * @param  array<int, array{name: string, description: string|null, descriptor: array<string, mixed>, value: mixed}>  $slots
     * @return array<int, array{name: string, description: string|null, descriptor: array<string, mixed>, value: mixed}>
     */
    private function emptySlots(array $slots): array
    {
        return array_values(array_filter($slots, fn (array $slot) => $this->isEmptyValue($slot['value'] ?? null)));
    }

    /**
     * The complement of {@see emptySlots}: the in-scope slots the human HAS filled — the `gaps` read-only context.
     *
     * @param  array<int, array{name: string, description: string|null, descriptor: array<string, mixed>, value: mixed}>  $slots
     * @return array<int, array{name: string, description: string|null, descriptor: array<string, mixed>, value: mixed}>
     */
    private function filledSlots(array $slots): array
    {
        return array_values(array_filter($slots, fn (array $slot) => !$this->isEmptyValue($slot['value'] ?? null)));
    }

    private function isEmptyValue(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /**
     * A `{name: true}` lookup over an introspected slot list.
     *
     * @param  array<int, array{name: string, ...}>  $slots
     * @return array<string, true>
     */
    private function nameSet(array $slots): array
    {
        $out = [];

        foreach ($slots as $slot) {
            $out[$slot['name']] = true;
        }

        return $out;
    }

    /**
     * Decorate the Generator's fill report with the two keys the delegation response adds: the `mode` that
     * ACTUALLY ran (so the UI can be honest about which choice executed) and `nothing_to_fill` (true when the
     * bot had nothing to do and therefore nothing was spent). The `{filled, skipped, unfilled_required}` keys
     * are passed through unchanged.
     *
     * @param  array{filled: array<int, string>, skipped: array<int, array{name: string, reason: string}>, unfilled_required: array<int, string>}  $report
     * @return array{filled: array<int, string>, skipped: array<int, array{name: string, reason: string}>, unfilled_required: array<int, string>, mode: string, nothing_to_fill: bool}
     */
    private function report(array $report, SlotFillMode $mode, bool $nothingToFill): array
    {
        return $report + ['mode' => $mode->value, 'nothing_to_fill' => $nothingToFill];
    }

    /**
     * A short human type summary for the schema line (base + optional/list flags + enum option keys). NOT a
     * contract — the strict types live in the agent instruction; this only helps the model choose sensibly.
     *
     * @param  array<string, mixed>  $descriptor
     */
    private function describeType(array $descriptor): string
    {
        $base = is_string($descriptor['base'] ?? null) ? $descriptor['base'] : 'text';
        $summary = $base;

        if ($base === VariableType::ENUM->value) {
            $keys = [];

            foreach (is_array($descriptor['options'] ?? null) ? $descriptor['options'] : [] as $option) {
                if (is_array($option) && is_scalar($option['key'] ?? null)) {
                    $keys[] = (string) $option['key'];
                }
            }

            if ($keys !== []) {
                $summary .= ' [' . implode(', ', $keys) . ']';
            }
        }

        if (($descriptor['array'] ?? false) === true) {
            $summary .= ' (list)';
        }

        if (($descriptor['nullable'] ?? false) === true) {
            $summary .= ' (optional)';
        }

        return $summary;
    }

    /**
     * DEFENSIVE parse of the raw model reply into a `{slotName: value}` map — MIRRORS ShotListRenderer: blank
     * → empty map; strip an optional ```json … ``` fence; balance-scan for the FIRST top-level JSON object
     * (string/escape aware, so a `}` inside a value never closes it early); a non-object decode → empty map.
     * NEVER throws. Values are NOT trusted here — the persist path re-validates each against its descriptor.
     *
     * @return array<string, mixed>
     */
    private function parse(string $raw): array
    {
        $raw = trim($raw);

        if ($raw === '') {
            return [];
        }

        $json = $this->extractJsonObject($raw);
        $decoded = $json === null ? null : json_decode($json, true);

        if (!is_array($decoded) || array_is_list($decoded)) {
            return [];
        }

        return $decoded;
    }

    /**
     * Strip an optional ```json … ``` (or bare ``` … ```) fence, then balance-scan for the FIRST complete
     * top-level JSON object. Returns the object substring, or null when no `{ … }` is found. String/escape
     * aware (a brace inside a JSON string literal is ignored). MIRRORS ShotListRenderer::extractJsonObject.
     */
    private function extractJsonObject(string $raw): ?string
    {
        $raw = (string) preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($raw));

        $start = strpos($raw, '{');

        if ($start === false) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($raw);

        for ($i = $start; $i < $length; $i++) {
            $char = $raw[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($raw, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }
}
