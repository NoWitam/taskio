// publicationActions — which affordances a publication offers, as a pure function.
//
// WHY THIS IS A MODULE AND NOT A COMPUTED IN THREE COMPONENTS. The card menu, the detail
// band and the composer footer all have to answer the same question — "may this be armed,
// and if not, what do we tell the reader?" — and the answer has three named closures on top
// of the capability flags. Three copies of those closures is three chances to keep one and
// forget another. It is also the only part of the affordance logic that can be tested
// without mounting anything.
//
// THE FLAGS COME FIRST, ALWAYS. `can_be_edited` / `can_be_deleted` / `can_be_scheduled` /
// `can_be_reconciled` route through the policy and compose WHO is asking with WHERE the row
// is in its life. Nothing here gates on `is_owner` (a publication created by a workflow run
// legitimately disagrees with it — ADR-0015) and nothing here re-reads the transition table.
//
// The three additions below are NOT a second transition table: they are three places where
// the flag alone would offer a button whose write refuses or, worse, succeeds into a wall.
// Each is named, argued and reported as a backend gap.
import type { PlatformConnection, Publication } from './types';

/** What the arming affordance is CALLED, which is also what it DOES. */
export type ArmKind =
  /** `draft` with nothing attached → `POST …/schedule` arms it. */
  | 'schedule'
  /** `failed` / `blocked` → the same call, but the reader is trying again. */
  | 'reschedule'
  /**
   * A pipeline is attached and there is no approval yet: `POST …/schedule` PARKS the moment
   * and opens a review. The row comes back a `draft` with `is_in_approval: true`. Calling
   * that button "Schedule" would promise a publication that nobody has agreed to yet.
   */
  | 'submitForReview'
  /**
   * Already `scheduled`. `POST …/schedule` would be refused
   * (`publication_transition_not_allowed` — a move into the same status is not an edge), so
   * the affordance is a `PUT` with the whole row and a new moment.
   */
  | 'changeTime'
  /** No arming affordance at all (`publishing`, `published`, `needs_reconcile`). */
  | 'none';

/** Why arming is offered but blocked, or null when nothing blocks it. */
export type ArmBlockReason =
  /**
   * A public destination with no account. `can_be_scheduled` is true and the write would
   * SUCCEED — `SchedulePublicationRequest` validates only the moment — and then the
   * publication would go out at its minute and fail in the adapter. (Gap L3.)
   */
  | 'noAccount'
  /**
   * `blocked` whose connection is still broken. The edge `blocked → scheduled` is legal and
   * nothing refuses it; the row would simply be armed into the same wall at the next sweep.
   * (Gap L5 — requires the connections list to answer at all.)
   */
  | 'connectionBroken'
  /** A review is holding the row. The flag says so already; this names it for the copy. */
  | 'underReview';

/** The resolved arming affordance for one publication. */
export interface ArmAffordance {
  kind: ArmKind;
  /** Non-null → render the control DISABLED WITH A VISIBLE REASON, never silently gone. */
  blockedBy: ArmBlockReason | null;
  /** Convenience: the control may be activated. */
  enabled: boolean;
}

/**
 * How a connection referenced by a publication currently stands.
 *
 * `unknown` is a real answer and is NOT treated as broken: the connections list may not have
 * loaded (or may have failed), and disabling an action because a secondary request has not
 * come back yet would be a worse lie than letting the server refuse.
 */
export type ConnectionHealth = 'usable' | 'unusable' | 'unknown';

export function connectionHealth(
  connectionId: string | null,
  connections: PlatformConnection[] | null | undefined,
): ConnectionHealth {
  if (!connectionId) return 'unknown';
  if (!connections || connections.length === 0) return 'unknown';
  const found = connections.find((c) => c.id === connectionId);
  if (!found) return 'unknown';
  // `can_publish` is the server's own gate. Never compared with `'active'` here.
  return found.can_publish ? 'usable' : 'unusable';
}

/**
 * The arming affordance, flags first.
 *
 * @param connections the workspace's connections when known — omit or pass null and the
 *                    `connectionBroken` closure simply does not fire (see ConnectionHealth).
 */
export function armAffordance(
  publication: Publication,
  connections?: PlatformConnection[] | null,
): ArmAffordance {
  const { status } = publication;

  // Already armed: the affordance is "change the time", and it is an edit, not a transition.
  if (status === 'scheduled') {
    if (publication.can_be_edited) {
      return { kind: 'changeTime', blockedBy: null, enabled: true };
    }
    return {
      kind: 'none',
      blockedBy: publication.is_in_approval ? 'underReview' : null,
      enabled: false,
    };
  }

  // THE FLAG IS THE FIRST GATE, ALWAYS.
  if (!publication.can_be_scheduled) {
    // A live review is the one refusal worth naming: `can_be_scheduled` is false for it, and
    // a reader who is told nothing goes looking for a permission they have not lost.
    if (publication.is_in_approval) {
      return { kind: 'none', blockedBy: 'underReview', enabled: false };
    }
    return { kind: 'none', blockedBy: null, enabled: false };
  }

  // The flag is also true for statuses that have no arming EDGE (`published` is terminal;
  // `needs_reconcile` is reconcile-only). Those never reach here in practice because the
  // policy says no, but the status test keeps this honest if the policy ever widens.
  if (status !== 'draft' && status !== 'failed' && status !== 'blocked') {
    return { kind: 'none', blockedBy: null, enabled: false };
  }

  const kind: ArmKind = hasPendingReview(publication)
    ? 'submitForReview'
    : status === 'draft'
      ? 'schedule'
      : 'reschedule';

  // Closure 1 (L3): a public destination with nobody to publish as.
  if (publication.publishes_publicly && publication.platform_connection_id === null) {
    return { kind, blockedBy: 'noAccount', enabled: false };
  }

  // Closure 2 (L5): on hold, and the account that put it on hold is still broken.
  if (status === 'blocked' && connectionHealth(publication.platform_connection_id, connections) === 'unusable') {
    return { kind, blockedBy: 'connectionBroken', enabled: false };
  }

  return { kind, blockedBy: null, enabled: true };
}

/**
 * Does arming this publication OPEN A REVIEW rather than arm it?
 *
 * `PublicationService::schedule()` parks the moment and starts an approval process whenever a
 * pipeline is attached and the latest process is not `approved`. `approval_state` is exactly
 * that latest process's status, so this is a direct reading of the server's own condition —
 * not a guess about what approval means.
 */
export function hasPendingReview(publication: Publication): boolean {
  return publication.approval_pipeline_id !== null && publication.approval_state !== 'approved';
}

/** What actually happened after a successful `POST …/schedule` (200 either way). */
export type ScheduleOutcome = 'armed' | 'submittedForReview';

/**
 * Read the outcome off the RESPONSE, not off what the screen believed before clicking.
 *
 * Both outcomes are a `200` carrying a full `PublicationResource`, and they are genuinely
 * different events: one says the publication will go out by itself at that moment, the other
 * says a person now has to say yes. A publication that is armed is never simultaneously under
 * review — arming is exactly what a concluded approval does — so the flag separates them
 * cleanly, and `status` is checked too so a future third outcome cannot masquerade as arming.
 */
export function scheduleOutcomeOf(publication: Publication): ScheduleOutcome {
  if (publication.is_in_approval) return 'submittedForReview';
  return publication.status === 'scheduled' ? 'armed' : 'submittedForReview';
}

/**
 * The moment to quote back in the confirmation.
 *
 * `intended_publish_at` answers for BOTH halves of a publication's life — the armed
 * `scheduled_at`, or the moment a review is holding — so no caller has to know which column
 * the answer came from.
 */
export function intendedMomentOf(publication: Publication): string | null {
  return publication.intended_publish_at ?? publication.scheduled_at;
}

/**
 * Which delete confirmation to show — four situations, four different sentences.
 *
 * The `published` case is the one that matters: deleting a published publication is allowed,
 * which looks inconsistent beside an uneditable `needs_reconcile` until it is said plainly.
 * Deleting hides OUR RECORD and changes nothing in the world; editing would leave a record
 * that actively lies about a post that still exists.
 */
export type DeleteCopyKind = 'plain' | 'scheduled' | 'blocked' | 'published';

export function deleteCopyKind(publication: Publication): DeleteCopyKind {
  switch (publication.status) {
    case 'scheduled':
      return 'scheduled';
    case 'blocked':
      return 'blocked';
    case 'published':
      return 'published';
    default:
      return 'plain';
  }
}

/**
 * Does this row's kebab have anything in it beyond "Open"?
 *
 * A menu whose only entry duplicates clicking the card teaches that the kebab is sometimes
 * empty, so a `publishing` row renders no kebab at all.
 */
export function hasRowActions(publication: Publication): boolean {
  return (
    publication.remote_url !== null ||
    publication.can_be_edited ||
    publication.can_be_deleted ||
    publication.can_be_reconciled ||
    armAffordance(publication).kind !== 'none'
  );
}
