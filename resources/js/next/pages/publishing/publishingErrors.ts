// publishingErrors — pure predicates over the module's refusals. No Vue, no HTTP client.
//
// The house pattern (workflows' `runNowErrors.ts`): small named readers, not one global
// error mapper. A screen asks "is this the one I know how to explain?" and gets an answer
// or null; it never pattern-matches an axios error inline.
//
// ONE RULE GOVERNS EVERY SENTENCE HERE: `message` arrives ALREADY TRANSLATED by the server,
// in the reader's language, from `lang/{pl,en}/publishing.php`. The frontend renders it
// VERBATIM and branches on `code` only to choose the VESSEL — a toast, an in-place alert, or
// an alert with a "Refresh" action. Re-writing these sentences on the client would produce a
// second vocabulary for the same refusal, and the second one is the one that drifts.

/** The refusal shape every `publication_*` 422 uses: `{code, message, context}`. */
export interface TransitionRefusal {
  code: string;
  /** Server prose, already in the reader's language. Render it verbatim. */
  message: string;
  context: Record<string, unknown>;
}

/** The five transition codes plus the review hold — the ones a screen has a vessel for. */
const REFUSAL_CODES = [
  'publication_reconcile_before_retry',
  'publication_blocked_holds',
  'publication_terminal',
  'publication_transition_not_allowed',
  'publication_transition_lost_race',
  // B6: a live review holding the row. Deliberately NOT in the server's `transitions`
  // catalog — a review hold is not an edge the machine refused — but it travels in the same
  // `{code, message, context}` envelope, so it reads the same way here.
  'publication_under_review',
] as const;

/** The HTTP status of an axios-style error, or null. */
export function statusOf(err: unknown): number | null {
  return (err as { response?: { status?: number } })?.response?.status ?? null;
}

/**
 * The transition/review refusal in this error, or null when it is some other failure.
 *
 * Recognised STRUCTURALLY (422 + a known `code`), never by the sentence — the house rule.
 */
export function transitionRefusalOf(err: unknown): TransitionRefusal | null {
  const response = (err as { response?: { status?: number; data?: unknown } })?.response;
  if (response?.status !== 422) return null;

  const data = response.data as { code?: unknown; message?: unknown; context?: unknown } | undefined;
  const code = typeof data?.code === 'string' ? data.code : null;
  if (!code || !(REFUSAL_CODES as readonly string[]).includes(code)) return null;

  return {
    code,
    message: typeof data?.message === 'string' ? data.message : '',
    context: (data?.context as Record<string, unknown>) ?? {},
  };
}

/**
 * The screen is OUT OF DATE, not broken.
 *
 * The edge existed; the row moved between this caller's read and its write, and the
 * compare-and-swap matched zero rows. Nothing was written. `context.from` is the row's REAL
 * current status, read back after the refusal — which is why the honest response is a
 * "Refresh" button and never an automatic reload: the person has to read that their click
 * changed nothing.
 */
export function isLostRace(refusal: TransitionRefusal | null): boolean {
  return refusal?.code === 'publication_transition_lost_race';
}

/** A live review is holding the row (B6). Not a permission problem — everybody gets it. */
export function isUnderReview(refusal: TransitionRefusal | null): boolean {
  return refusal?.code === 'publication_under_review';
}

/**
 * Seconds to wait after a `429`, from `Retry-After`, or a 60-second default.
 *
 * Reconciliation is throttled `6,1` in its own bucket because every call spends the
 * platform's rate limit, which is shared by the WHOLE INSTALLATION. Returning null here
 * (and showing "something went wrong") would make the one action worth repeating look
 * broken.
 */
export function throttleSecondsOf(err: unknown, fallback = 60): number {
  if (statusOf(err) !== 429) return fallback;
  const headers = (err as { response?: { headers?: Record<string, unknown> } })?.response?.headers;
  const raw = headers?.['retry-after'] ?? headers?.['Retry-After'];
  const seconds = Number(raw);
  return Number.isFinite(seconds) && seconds > 0 ? Math.ceil(seconds) : fallback;
}

/**
 * Per-field validation messages from a Laravel 422 (`{message, errors:{field:[…]}}`).
 *
 * Flattened to the FIRST message per field, which is what `FormField :error` renders. The
 * sentences are the server's and are shown verbatim under the field they name.
 */
export function fieldErrorsOf(err: unknown): Record<string, string> {
  const response = (err as { response?: { status?: number; data?: unknown } })?.response;
  if (response?.status !== 422) return {};

  const errors = (response.data as { errors?: Record<string, unknown> } | undefined)?.errors;
  if (!errors || typeof errors !== 'object') return {};

  const flat: Record<string, string> = {};
  for (const [field, messages] of Object.entries(errors)) {
    const first = Array.isArray(messages) ? messages[0] : messages;
    if (typeof first === 'string' && first !== '') flat[field] = first;
  }
  return flat;
}

/**
 * The fields the server declares `prohibited`.
 *
 * A 422 on any of these means the CLIENT sent a column it must never send — a frontend
 * defect, not a user error. There is no field on the form to hang the message on, so the
 * caller logs it and shows the server's sentence as a toast instead of silently dropping it.
 */
const PROHIBITED_FIELDS = ['status', 'remote_id', 'remote_draft_id', 'published_at', 'attempts'];

/** The prohibited field names present in a validation failure (usually none). */
export function prohibitedFieldsIn(fieldErrors: Record<string, string>): string[] {
  return PROHIBITED_FIELDS.filter((field) => field in fieldErrors);
}

/** A human message from any error: the server's own, or null when it sent none. */
export function serverMessageOf(err: unknown): string | null {
  const data = (err as { response?: { data?: { message?: unknown } } })?.response?.data;
  return typeof data?.message === 'string' && data.message !== '' ? data.message : null;
}
