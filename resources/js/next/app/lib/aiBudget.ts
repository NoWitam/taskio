// aiBudget — recognizing an AI BUDGET refusal, in one place.
//
// Promoted out of `app/stores/sessions.ts` in B15a (spec §24.8 / R23). Three modules now spend AI
// against the same workspace cap — Generator, Bots, Knowledge — and the Knowledge composer may not
// import from the Generator's store: that crosses a module boundary, and a copy would mean two
// definitions of "did we run out of money" drifting apart. The store re-exports these so its own
// callers are untouched.
//
// The rule is deliberately FORWARD-COMPATIBLE: either signal alone is enough. The gate answers 429
// (the shared over-cap status in this codebase), and the body carries a typed code — a caller that
// insisted on both would break the day one of them is dropped.

/** The typed refusal code the backend puts in the body (`code` or `error`). */
export const AI_BUDGET_ERROR_CODE = 'ai_budget_exceeded';

/**
 * Recognize a BUDGET / over-cap refusal distinctly from any other failure. True when the gate
 * answers HTTP 429, OR the body carries {@link AI_BUDGET_ERROR_CODE} in `code` / `error`.
 *
 * Distinguishing it matters: a budget stop is a state with a RESET DATE and an owner-only remedy,
 * not an error to retry. Everything else is a failure to report.
 */
export function isBudgetError(err: unknown): boolean {
  const res = (err as {
    response?: { status?: number; data?: { code?: string; error?: string } };
  })?.response;
  if (!res) return false;
  if (res.status === 429) return true;
  const code = res.data?.code ?? res.data?.error;
  return code === AI_BUDGET_ERROR_CODE;
}
