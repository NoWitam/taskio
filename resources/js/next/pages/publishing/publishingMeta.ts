// publishingMeta — the module's pure, testable maps. No Vue, no HTTP, no i18n lookups.
//
// Everything a Publishing screen needs to turn a SERVER CODE into a design-system choice
// lives here and only here: tone → Badge variant, status → icon + band surface, platform →
// glyph, failure code → catalog key, OAuth reason → tone + whether "connect again" is the
// reader's remedy. A second copy of any of these is the one that drifts.
//
// Mirrors `pages/workflows/workflowMeta.ts` → `toneToVariant()`, deliberately: the server
// picks the MEANING, the design system picks the pixels.
import type { IconName } from '../../ui/primitives/icons';
import type {
  ConnectionStatus,
  ConnectionTone,
  PublicationStatus,
  PublishingPlatform,
  StatusTone,
} from './types';

/** The Badge variants this module uses (a subset of the primitive's union). */
export type BadgeVariant = 'neutral' | 'info' | 'success' | 'warning' | 'danger';

/**
 * Tone → Badge variant, with an EXPLICIT `null` case.
 *
 * `draft` carries `status_tone: null` rather than `'neutral'`, and that is a contract, not
 * an omission: a draft has no moment, so it has no place on the calendar's axis and never
 * reaches the grid — the null is a second, structural statement of the same fact. The
 * degrade to `neutral` happens HERE, once, on purpose — never as a `tone ?? 'neutral'`
 * scattered through templates where nobody can find it later.
 */
export function toneToVariant(tone: StatusTone | ConnectionTone | string | undefined): BadgeVariant {
  switch (tone) {
    case 'success':
      return 'success';
    case 'danger':
      return 'danger';
    case 'warning':
      return 'warning';
    case 'info':
      return 'info';
    // `'muted'` arrives on a REVOKED connection and has no variant of its own; `null` is
    // the draft's deliberate absence. Both land on neutral, and so does anything an
    // eighth status might one day send.
    default:
      return 'neutral';
  }
}

/**
 * Status → glyph. `needs_reconcile` gets `help-circle`, NOT `alert-triangle`: the meaning
 * of that state is a QUESTION, not a warning. The colour says "urgent", the glyph says
 * "nobody knows". `failed` and `needs_reconcile` share `danger` on purpose — the icon, the
 * prose and the action set carry the difference, never a third shade of red.
 */
export function statusIcon(status: PublicationStatus | string): IconName {
  switch (status) {
    case 'draft':
      return 'file-text';
    case 'scheduled':
      return 'clock';
    case 'publishing':
      return 'loader';
    case 'published':
      return 'check-circle';
    case 'failed':
      return 'x-circle';
    case 'needs_reconcile':
      return 'help-circle';
    case 'blocked':
      return 'lock';
    default:
      return 'circle';
  }
}

/** The tailwind surface classes for a status band (§7.2 / §13.1). */
export function statusBandClass(status: PublicationStatus | string): string {
  switch (status) {
    case 'scheduled':
      return 'bg-next-info-subtle text-next-info-subtle-foreground';
    case 'publishing':
    case 'blocked':
      return 'bg-next-warning-subtle text-next-warning-subtle-foreground';
    case 'published':
      return 'bg-next-success-subtle text-next-success-subtle-foreground';
    case 'failed':
    case 'needs_reconcile':
      return 'bg-next-danger-subtle text-next-danger-subtle-foreground';
    case 'draft':
    default:
      return 'bg-next-muted text-next-fg';
  }
}

/**
 * A band that reports a state somebody has to ACT on is assertive; the rest are polite.
 * `publishing` is loud-looking and still polite: nothing is expected of the reader.
 */
export function statusBandRole(status: PublicationStatus | string): 'status' | 'alert' {
  return status === 'failed' || status === 'blocked' || status === 'needs_reconcile'
    ? 'alert'
    : 'status';
}

/**
 * Destination → GENERIC glyph, with a mandatory fallback.
 *
 * Never brand logotypes: a second icon system (colourful brand SVGs beside a one-colour
 * stroke registry), its own trademark rules and its own dark mode, for four rows. The glyph
 * is never the only signal either — `platform_label` (server prose) always stands beside it.
 */
export function platformIcon(platform: PublishingPlatform | string): IconName {
  switch (platform) {
    case 'youtube':
      return 'film';
    case 'instagram':
      return 'image';
    case 'facebook':
      return 'users';
    case 'dry_run':
      return 'eye-off';
    default:
      return 'circle';
  }
}

/**
 * The destinations that can have an ACCOUNT — the Connections grid's row set.
 *
 * `dry_run` is absent: it has nothing to connect, and a card explaining "this one cannot be
 * connected" would be three sentences about something nobody tried to do.
 *
 * This list is a FRONTEND CONSTANT because the contract has no destination catalogue
 * endpoint (gap L1). It is the one place the module states the closed set, so the day the
 * backend publishes one there is a single call site to replace.
 */
export const CONNECTABLE_PLATFORMS: readonly PublishingPlatform[] = [
  'youtube',
  'instagram',
  'facebook',
];

/** Every destination, including the rehearsal — the composer's and the filter's options. */
export const ALL_PLATFORMS: readonly PublishingPlatform[] = [
  'youtube',
  'instagram',
  'facebook',
  'dry_run',
];

/** The status tabs, in LIFECYCLE order — the bar reads as the axis a row travels along. */
export const STATUS_TABS: readonly PublicationStatus[] = [
  'draft',
  'scheduled',
  'publishing',
  'published',
  'failed',
  'needs_reconcile',
  'blocked',
];

/** The three statuses the server counts as `needs_attention`, most urgent first. */
export const ATTENTION_STATUSES: readonly PublicationStatus[] = [
  // First, because it may ALREADY be in the world and nobody knows.
  'needs_reconcile',
  'failed',
  'blocked',
];

// NO `tabBadgeVariant` HERE. The tab counts are rendered by `Tabs`, which colours its own
// count badge by whether the tab is active (primary) or not (neutral) — a status tone on top
// of that would be a second, contradicting rule for the same pixel. The urgency of `failed` /
// `needs_reconcile` / `blocked` is carried by the attention banner above the bar, which the
// server counts (`needs_attention`). A map nothing calls is a map the next person extends.

// --- Failure codes ----------------------------------------------------------

/** The eight publication failure codes the server can write (B1 + B3). */
const PUBLICATION_FAILURE_CODES = [
  'title_missing',
  'publish_outcome_unknown',
  'reconciled_absent',
  'dispatch_failed',
  'publish_worker_failed',
  'reaper_stale',
  'connection_needs_reauth',
  'connection_disconnected',
] as const;

/** The four connection failure codes (`connection_failures.*`). */
const CONNECTION_FAILURE_CODES = [
  'refresh_failed',
  'refresh_unsupported',
  'credentials_unreadable',
  'disconnected_by_user',
] as const;

/**
 * The i18n key for a publication's `failure_code`, or the UNKNOWN fallback.
 *
 * The resource carries no `failure_label`: the sentences live in the SERVER catalog, which
 * this frontend never reads, so the frontend catalog holds faithful copies. B4 will add
 * adapter codes this build has never heard of — hence a fallback that names the code
 * instead of rendering a raw key or, worse, nothing.
 */
export function failureSentenceKey(code: string | null | undefined): string {
  if (!code) return '';
  return (PUBLICATION_FAILURE_CODES as readonly string[]).includes(code)
    ? `publishing.failures.${code}`
    : 'publishing.failures.unknown';
}

/** The same, for a connection's `failure_code` (`connection_failures.*`). */
export function connectionFailureSentenceKey(code: string | null | undefined): string {
  if (!code) return '';
  return (CONNECTION_FAILURE_CODES as readonly string[]).includes(code)
    ? `publishing.connectionFailures.${code}`
    : 'publishing.failures.unknown';
}

/**
 * The ONLY `failure_context` keys this build renders.
 *
 * The column is schema-less and today holds both `{stale_after_seconds: 900}` and
 * `{exception: 'Illuminate\\…'}`. An exception class name is not a message for a person, so
 * the rule is a whitelist rather than a blacklist: a key this specification does not know
 * by name does not reach the screen at all.
 */
export function staleAfterMinutes(context: Record<string, unknown> | null | undefined): number | null {
  const seconds = context?.stale_after_seconds;
  if (typeof seconds !== 'number' || !Number.isFinite(seconds) || seconds <= 0) return null;
  return Math.round(seconds / 60);
}

// --- Connections ------------------------------------------------------------

/** Connection status → glyph. Colour is never the only signal (§13.2). */
export function connectionStatusIcon(status: ConnectionStatus | string): IconName {
  switch (status) {
    case 'active':
      return 'check-circle';
    case 'needs_reauth':
      return 'alert-circle';
    case 'revoked':
      return 'x-circle';
    default:
      return 'circle';
  }
}

// --- OAuth return (§10.3) ---------------------------------------------------

/** The fourteen named reasons, in the catalog's order. */
export const OAUTH_REASONS = [
  'access_denied',
  'missing_code',
  'oauth_state_malformed',
  'oauth_state_bad_signature',
  'oauth_state_expired',
  'oauth_state_already_used',
  'oauth_state_platform_mismatch',
  'oauth_browser_mismatch',
  'token_exchange_failed',
  'token_response_unusable',
  'account_lookup_failed',
  'connection_failed',
  'workspace_unavailable',
  'unknown_platform',
] as const;

/**
 * The banner's tone for a failure reason.
 *
 * `access_denied` and every stale-link refusal are `warning`, not `danger`: the user
 * DECLINED, or clicked an old link. Nothing broke, and red would be an accusation. Danger is
 * reserved for the platform refusing after consent was given.
 */
export function oauthReasonTone(reason: string | null | undefined): 'warning' | 'danger' {
  switch (reason) {
    case 'access_denied':
    case 'missing_code':
    case 'oauth_state_malformed':
    case 'oauth_state_bad_signature':
    case 'oauth_state_expired':
    case 'oauth_state_already_used':
    case 'oauth_state_platform_mismatch':
    case 'oauth_browser_mismatch':
      return 'warning';
    default:
      // token_exchange_failed / token_response_unusable / account_lookup_failed /
      // connection_failed / workspace_unavailable / unknown_platform / anything the
      // platform passed through verbatim.
      return 'danger';
  }
}

/**
 * The sentence key for a failure reason, or the fallback for a code this build has never
 * heard of. The callback does NOT validate `reason` against a closed list — any raw
 * platform error (`server_error`, …) passes through truncated to 64 chars — so the fallback
 * is load-bearing, not defensive decoration.
 */
export function oauthReasonKey(reason: string | null | undefined): string {
  return reason && (OAUTH_REASONS as readonly string[]).includes(reason)
    ? `publishing.oauth.failures.${reason}`
    : 'publishing.oauth.failures.unknown';
}

/**
 * Whether to offer "Connect again" beside the banner.
 *
 * Two reasons say no, each for its own cause: `workspace_unavailable`'s remedy is somewhere
 * else entirely (access to the workspace), and `unknown_platform` has nothing to repeat —
 * the platform is, by definition, not one this application can connect. Everything else is
 * offered, provided the platform is known: without it there is no destination to start from.
 */
export function oauthCanRetry(reason: string | null | undefined, platform: string | null): boolean {
  if (!platform) return false;
  return reason !== 'workspace_unavailable' && reason !== 'unknown_platform';
}
