// Publishing (R4) wire types — 1:1 with the backend resources. NOTHING here is invented.
//
// Sources of truth, read field-for-field:
//   • app/modules/Publishing/Http/Resources/PublicationResource.php
//   • app/modules/Publishing/Http/Resources/PlatformConnectionResource.php
//   • app/modules/Publishing/Http/Resources/PublicationCountsResource.php
//   • app/modules/Publishing/Http/Resources/PlatformAuthorizationResource.php
//   • docs/backend/publishing-api.md
//
// TWO FIELDS THAT LOOK REDUNDANT AND ARE NOT:
//   `status` is the stable code a client BRANCHES on; `status_label` is the same status in
//   the reader's language and is rendered VERBATIM. There is no client-side status
//   vocabulary in this module — a screen that owned one would silently omit the eighth
//   status the day one is added.
//
//   `is_in_approval` answers "is somebody deciding about this right now" (which is what the
//   capability flags turn on); `approval_state` answers what a CONCLUDED review left behind.
//   A publication a reviewer turned down is a `draft` again — the same status as one nobody
//   has looked at — so `status` alone cannot tell "not sent yet" from "sent and refused".
import type { Creator } from '../../ui/patterns/creator';

/** Where a publication goes. `dry_run` is NOT a platform — it publishes nothing. */
export type PublishingPlatform = 'youtube' | 'instagram' | 'facebook' | 'dry_run';

/** The seven states of the publication state machine. Branch on these, never on prose. */
export type PublicationStatus =
  | 'draft'
  | 'scheduled'
  | 'publishing'
  | 'published'
  | 'failed'
  | 'needs_reconcile'
  | 'blocked';

/** The server's chosen MEANING for a status colour. `null` for `draft` — deliberately. */
export type StatusTone = 'info' | 'warning' | 'success' | 'danger' | null;

/** Connection lifecycle. `revoked` rows survive so published rows still name their account. */
export type ConnectionStatus = 'active' | 'needs_reauth' | 'revoked';

/** A connection's tone. `'muted'` has no Badge variant of its own — see publishingMeta. */
export type ConnectionTone = 'success' | 'danger' | 'muted' | string | null;

/** The latest approval process's own status, or null when never reviewed. */
export type ApprovalState = 'pending' | 'approved' | 'rejected';

/** ONE publication, in full. Every key exists in `PublicationResource::toArray()`. */
export interface Publication {
  id: string;
  title: string;
  body: string | null;

  platform: PublishingPlatform;
  /** Ready server prose — rendered verbatim, never translated client-side. */
  platform_label: string;
  /** `false` only for `dry_run`. Never compare `platform !== 'dry_run'` yourself. */
  publishes_publicly: boolean;
  platform_connection_id: string | null;

  status: PublicationStatus;
  status_label: string;
  status_tone: StatusTone;
  needs_attention: boolean;

  /** ISO UTC instants. Rendered on the WORKSPACE's clock, never the browser's. */
  scheduled_at: string | null;
  published_at: string | null;

  /** Ordered Disk file uuids. NOT expanded into file objects — ask the Disk for those. */
  media: string[];
  /** Schema-less, owned by the adapter. No editor (D13) — but it MUST survive a PUT. */
  options: Record<string, unknown>;

  remote_id: string | null;
  remote_url: string | null;

  attempts: number;
  last_attempt_at: string | null;
  /** A stable code the CLIENT translates. Never platform prose. */
  failure_code: string | null;
  failure_context: Record<string, unknown> | null;

  // --- Review (B6) ---------------------------------------------------------
  approval_pipeline_id: string | null;
  /** Somebody is deciding about this right now; both capability flags turn on it. */
  is_in_approval: boolean;
  /** The latest decision — the ONLY way a rejection is visible. Null = never reviewed. */
  approval_state: ApprovalState | null;
  /**
   * When this means to go out, whichever half of its life it is in: `scheduled_at` once
   * armed, the moment a review is holding while it is not. One field, so no screen has to
   * know which column the answer came from.
   */
  intended_publish_at: string | null;

  creator?: Creator | null;
  /** Human authorship only. NEVER a gate — gate on `can_be_*` (ADR-0015). */
  is_owner: boolean;

  can_be_edited: boolean;
  can_be_deleted: boolean;
  can_be_scheduled: boolean;
  /** True for exactly one status (`needs_reconcile`) — the "check the platform" gate. */
  can_be_reconciled: boolean;

  created_at: string | null;
  updated_at: string | null;
}

/** ONE connected account. `access_token`/`refresh_token` never cross this boundary. */
export interface PlatformConnection {
  id: string;
  platform: PublishingPlatform;
  platform_label: string;

  /** The platform's public id — the only way to tell two same-named accounts apart. */
  external_account_id: string;
  account_name: string;

  status: ConnectionStatus;
  status_label: string;
  status_tone: ConnectionTone;
  needs_attention: boolean;
  /** THE gate for offering this account in a picker. Never compare with `'active'`. */
  can_publish: boolean;

  /** What the platform GRANTED — not what was asked for. */
  scopes: string[];
  /** `null` means "the platform did not say", NEVER "expired". */
  expires_at: string | null;
  last_refreshed_at: string | null;
  failure_code: string | null;
  /** `false` = the APP_KEY-rotation incident. The screen must look different (§9.6). */
  credentials_readable: boolean;

  creator?: Creator | null;
  is_owner: boolean;
  can_be_disconnected: boolean;

  created_at: string | null;
  updated_at: string | null;
}

/** `GET /publishing/counts` → always all seven keys, `0` where there are none. */
export interface PublicationCounts {
  counts: Record<PublicationStatus, number>;
  total: number;
  /** `failed + needs_reconcile + blocked`, computed SERVER-side. Never summed here. */
  needs_attention: number;
}

/** List filters, mirroring the server's query params 1:1. */
export interface PublicationFilters {
  /** ONE status (the endpoint takes no multi-status filter). */
  status?: PublicationStatus;
  platform?: PublishingPlatform[];
  /** Literal, not a pattern. */
  search?: string;
  /** ISO days, bounds on `scheduled_at`. */
  scheduled_from?: string;
  scheduled_to?: string;
}

/**
 * The write payload. A `PUT` is a WHOLE-ROW WRITE, not a patch: every column named here is
 * assigned on every update, so a field the form omits is NULLED in the database. That is
 * why `options` — which has no editor — is part of this type.
 */
export interface PublicationWritePayload {
  title: string;
  body: string | null;
  platform: PublishingPlatform;
  platform_connection_id: string | null;
  /** Local wall clock `yyyy-mm-ddTHH:mm` (read on the workspace's clock) or null. */
  scheduled_at: string | null;
  media: string[];
  options: Record<string, unknown>;
}

/** `POST /publishing/connections/{platform}/authorize` → the handshake start. */
export interface PlatformAuthorization {
  authorize_url: string;
  expires_in: number;
}

// --- Response envelopes ------------------------------------------------------
export interface PublicationResponse {
  data: Publication;
}
export interface PublicationListResponse {
  data: Publication[];
  meta?: { next_cursor?: string | null };
}
export interface PublicationCountsResponse {
  data: PublicationCounts;
}
export interface ConnectionListResponse {
  data: PlatformConnection[];
}
export interface AuthorizationResponse {
  data: PlatformAuthorization;
}

// --- OAuth return (§10) ------------------------------------------------------
/** The callback's outcome key. `RESULT_KEY = 'connection'`, values `connected`/`failed`. */
export type OAuthResult = 'connected' | 'failed';

/**
 * The fourteen NAMED reasons a callback can report. A fifteenth shape exists and is not in
 * this union on purpose: a platform's own raw `error` value passes through verbatim
 * (truncated to 64 chars), so every consumer needs a fallback for an unknown string.
 */
export type OAuthReason =
  | 'unknown_platform'
  | 'access_denied'
  | 'missing_code'
  | 'workspace_unavailable'
  | 'connection_failed'
  | 'oauth_state_malformed'
  | 'oauth_state_bad_signature'
  | 'oauth_state_expired'
  | 'oauth_state_already_used'
  | 'oauth_state_platform_mismatch'
  | 'oauth_browser_mismatch'
  | 'token_exchange_failed'
  | 'token_response_unusable'
  | 'account_lookup_failed';

/** What the Connections screen read out of the URL on arrival. */
export interface OAuthReturn {
  result: OAuthResult;
  /** MAY be absent — the controller filters a null platform on `unknown_platform`. */
  platform: PublishingPlatform | null;
  /** Present only on failure; may be any string the platform sent. */
  reason: string | null;
}
