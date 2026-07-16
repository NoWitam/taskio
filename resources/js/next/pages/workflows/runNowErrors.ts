// Pure 422-bag → i18n-key mapper for the run-now modal (next, §6.3).
//
// **5.1: the run 422 bag has EXACTLY two keys.** The run endpoint's error bag maps
// by KEY — never by message prose (the backend messages are Polish and may change;
// matching them was the brittle pattern this file replaced after the B6 review):
//   • `target_id` — the target is missing OR unresolvable. The backend folds an
//                   empty target into its not-found lookup and the modal
//                   pre-validates emptiness client-side, so a SERVER `target_id`
//                   always reads as "not found".
//                                              → workflows.run.errors.targetNotFound
//   • `workflow`  — run budget cap reached     → workflows.run.errors.capReached
//
// The REV 1 `approval_process` / `noConcludedApproval` arm is DELETED — that trigger
// (approval_finished) no longer exists, so the backend never emits that key. Both
// remaining keys are inspected; an unrecognized 422 → the generic key. The
// "targetRequired" copy is CLIENT-side only (an empty required field never submits).
//
// Extracted so the mapping is unit-testable without a component. The mapper returns
// an i18n KEY (the modal calls `t()` on it) plus a `field` hint so the modal can pin
// the message to the id input when relevant.

/** Where the mapped message should surface in the modal. */
export type RunNowErrorField = 'target_id' | 'workflow' | 'generic';

export interface RunNowErrorResult {
  /** The i18n key the modal renders (already namespaced under workflows.run.errors). */
  key: string;
  /** Which field the error belongs to (drives whether the id input is flagged). */
  field: RunNowErrorField;
}

/** A Laravel-style validation error bag: field → messages[]. */
export type RunNowErrorBag = Record<string, string[] | string | undefined>;

const GENERIC: RunNowErrorResult = {
  key: 'workflows.run.errors.generic',
  field: 'generic',
};

/**
 * Map a 422 error bag onto a single displayable error, purely by bag KEY.
 * Precedence: the concrete target failure first (it is the actionable field error),
 * then the workflow cap, then the generic fallback.
 */
export function mapRunNowError(bag: RunNowErrorBag | null | undefined): RunNowErrorResult {
  if (!bag || typeof bag !== 'object') return GENERIC;

  if (bag.target_id != null) {
    return { key: 'workflows.run.errors.targetNotFound', field: 'target_id' };
  }

  if (bag.workflow != null) {
    return { key: 'workflows.run.errors.capReached', field: 'workflow' };
  }

  return GENERIC;
}

/**
 * Extract the validation error bag from an axios-style error (422). Laravel wraps
 * it under `response.data.errors`. Returns null for a non-422 / shape mismatch so
 * the caller falls back to the generic key.
 */
export function extractErrorBag(err: unknown): RunNowErrorBag | null {
  const response = (err as { response?: { status?: number; data?: { errors?: unknown } } })?.response;
  if (!response || response.status !== 422) return null;
  const errors = response.data?.errors;
  if (!errors || typeof errors !== 'object') return null;
  return errors as RunNowErrorBag;
}
