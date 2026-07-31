<?php

namespace App\Modules\Generator\Services;

use App\Modules\Generator\Models\GenerationSession;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

/**
 * The per-RUN image call budget, held where the RUN is held: on the session row.
 *
 * WHY THIS EXISTS. {@see ImageChainExecutor} used to count its `ai_generate` / `ai_edit` provider calls in
 * instance fields, and that was correct exactly as long as ONE run meant ONE job — the executor is resolved
 * fresh per run, so the counters scoped themselves. Fanning a storyboard out into one job PER FRAME
 * dissolves that guarantee silently: each frame job builds its own container and would start counting from
 * zero, so `generator.image_generate_max_calls_per_session` would bound one frame instead of one run and an
 * 8-shot storyboard could spend 8 x the ceiling. A budget that only holds inside a process is not a budget
 * once the work leaves the process.
 *
 * RESERVE, DON'T READ-THEN-WRITE. Frame jobs run CONCURRENTLY, so "check the count, then increment it" is a
 * lost-update waiting to happen — two frames both read 7 of 8 and both spend. Every reservation is therefore
 * ONE conditional UPDATE:
 *
 *   UPDATE generation_sessions SET ai_generate_calls = ai_generate_calls + 1
 *   WHERE id = ? AND ai_generate_calls < <max>
 *
 * The database serializes the row, so the affected-row count IS the verdict: 1 means this caller owns one
 * call of the budget, 0 means it is spent. Same guarded-UPDATE discipline as the session claim
 * ({@see GenerationSessionRunManager::claimAndDispatch}) and the workflow resume claim.
 *
 * SCOPE = ONE RUN, RESET AT CLAIM. The counters are zeroed by EVERY claim (whole run and part op alike), so
 * the documented semantics are unchanged — a regenerate/refine still gets its own fresh per-run budget, and
 * a whole run still shares one cumulative budget across all of its image work. What changed is only that
 * "the run" now spans the session job plus its frame jobs.
 *
 * RESERVE-BEFORE-SPEND, and the reservation is NOT refunded when the provider then fails: identical to the
 * previous behavior (the counter was incremented before the call), and deliberate — a failing provider is
 * the case where an unrefunded budget protects the run from retry-storming a broken upstream.
 *
 * AMBIENT, like {@see \App\Modules\Variables\Support\MeterContext} and {@see CreativeDirectionContext}: a
 * container SINGLETON the session executor binds around a render and clears in the SAME finally as the meter
 * / voice / direction tags, so the deep image seam reads it without threading a parameter through the chain.
 * UNBOUND it answers false to {@see isBound} and the chain executor falls back to its own instance counters
 * — the pre-existing behavior, which keeps a direct non-session caller (the chain's own tests) working
 * exactly as before.
 */
class SessionImageBudget
{
    private ?string $sessionId = null;

    /** Bind the budget to a session for the duration of one render scope. */
    public function bind(string $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    /** Release the binding — called in the SAME finally as the meter/voice/direction tags (leak-proof). */
    public function clear(): void
    {
        $this->sessionId = null;
    }

    /** Whether a session is currently bound (false → the caller uses its own local counters). */
    public function isBound(): bool
    {
        return $this->sessionId !== null;
    }

    /**
     * Atomically reserve ONE `ai_generate` (text→image base) call against the run's persisted budget.
     * True → the caller owns the call and may spend it; false → the run is at its ceiling.
     */
    public function reserveGenerate(int $max): bool
    {
        return $this->reserve('ai_generate_calls', $max);
    }

    /**
     * Atomically reserve ONE `ai_edit` call against the run's persisted budget. True → reserved; false →
     * the run is at its ceiling (the chain then SKIPS that filter step, the refine path fails soft — both
     * unchanged, see {@see ImageChainExecutor}).
     */
    public function reserveEdit(int $max): bool
    {
        return $this->reserve('ai_edit_calls', $max);
    }

    /**
     * The one guarded UPDATE. Unbound (no session in scope) it refuses, so a caller MUST check
     * {@see isBound} first and use its own counters — refusing is the fail-CLOSED answer for a budget that
     * has no ledger to charge. A non-positive ceiling likewise refuses without touching the row.
     */
    private function reserve(string $column, int $max): bool
    {
        if ($this->sessionId === null || $max <= 0) {
            return false;
        }

        return GenerationSession::query()
            ->whereKey($this->sessionId)
            ->where($column, '<', $max)
            ->update([$column => $this->incrementOf($column)]) === 1;
    }

    /**
     * The `<column> + 1` SQL expression, built from a CLOSED set of literal column names rather than by
     * interpolating the argument — the value never reaches the statement as data, so there is nothing for a
     * future caller to inject through even if one day the column were chosen dynamically.
     */
    private function incrementOf(string $column): Expression
    {
        return match ($column) {
            'ai_generate_calls' => DB::raw('ai_generate_calls + 1'),
            'ai_edit_calls' => DB::raw('ai_edit_calls + 1'),
        };
    }
}
