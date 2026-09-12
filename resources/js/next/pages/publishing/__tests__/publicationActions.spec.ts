// publicationActions.spec — the affordance model, including the three named closures (D5).
//
// This is where the module's most consequential UI decisions live, and each one has a way of
// being wrong that looks right:
//
//   • Offering "Schedule" on an already-`scheduled` publication. `can_be_scheduled` is TRUE
//     there (the status is editable), and the write would answer
//     `publication_transition_not_allowed` — a move into the same status is not an edge.
//   • Offering an enabled "Schedule" on a public destination with no account. Nothing
//     refuses that write; the publication is armed and then fails in the adapter at its
//     minute, in public.
//   • Reporting "Scheduled for Friday" after a call that actually opened a REVIEW. Both
//     answers are a 200 with a full resource, and only `is_in_approval` tells them apart.
import { describe, it, expect } from 'vitest';
import {
  armAffordance,
  connectionHealth,
  deleteCopyKind,
  hasPendingReview,
  hasRowActions,
  intendedMomentOf,
  scheduleOutcomeOf,
} from '../publicationActions';
import type { PlatformConnection, Publication } from '../types';

function publication(overrides: Partial<Publication> = {}): Publication {
  return {
    id: 'p1',
    title: 'Autumn teaser',
    body: 'Something new is coming.',
    platform: 'youtube',
    platform_label: 'YouTube',
    publishes_publicly: true,
    platform_connection_id: 'c1',
    status: 'draft',
    status_label: 'Draft',
    status_tone: null,
    needs_attention: false,
    scheduled_at: null,
    published_at: null,
    media: [],
    options: {},
    remote_id: null,
    remote_url: null,
    attempts: 0,
    last_attempt_at: null,
    failure_code: null,
    failure_context: null,
    approval_pipeline_id: null,
    is_in_approval: false,
    approval_state: null,
    intended_publish_at: null,
    creator: null,
    is_owner: true,
    can_be_edited: true,
    can_be_deleted: true,
    can_be_scheduled: true,
    can_be_reconciled: false,
    created_at: '2026-09-01T10:00:00.000000Z',
    updated_at: '2026-09-01T10:00:00.000000Z',
    ...overrides,
  };
}

function connection(overrides: Partial<PlatformConnection> = {}): PlatformConnection {
  return {
    id: 'c1',
    platform: 'youtube',
    platform_label: 'YouTube',
    external_account_id: 'UCxxxx',
    account_name: 'Taskio Demo',
    status: 'active',
    status_label: 'Connected',
    status_tone: 'success',
    needs_attention: false,
    can_publish: true,
    scopes: [],
    expires_at: null,
    last_refreshed_at: null,
    failure_code: null,
    credentials_readable: true,
    creator: null,
    is_owner: true,
    can_be_disconnected: true,
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

describe('armAffordance — the ordinary cases', () => {
  it('offers "Schedule" on a draft with an account', () => {
    const result = armAffordance(publication(), [connection()]);
    expect(result.kind).toBe('schedule');
    expect(result.enabled).toBe(true);
    expect(result.blockedBy).toBeNull();
  });

  it('calls it "Schedule again" from failed and from blocked', () => {
    expect(armAffordance(publication({ status: 'failed' }), [connection()]).kind).toBe('reschedule');
    expect(armAffordance(publication({ status: 'blocked' }), [connection()]).kind).toBe('reschedule');
  });

  it('offers nothing at all while something else owns the row', () => {
    for (const status of ['publishing', 'published', 'needs_reconcile'] as const) {
      const result = armAffordance(publication({ status, can_be_scheduled: false }));
      expect(result.kind, status).toBe('none');
      expect(result.enabled, status).toBe(false);
    }
  });

  it('respects the FLAG before anything else', () => {
    const result = armAffordance(publication({ can_be_scheduled: false }));
    expect(result.kind).toBe('none');
  });
});

describe('armAffordance — D5 closure 1: already scheduled', () => {
  it('offers "Change the time", NOT "Schedule", when the row is already armed', () => {
    // `can_be_scheduled` is true here — `scheduled` is an editable status — but the write
    // would be refused, because a move into the same status is not an edge in the table.
    const result = armAffordance(
      publication({ status: 'scheduled', can_be_scheduled: true, can_be_edited: true }),
      [connection()],
    );
    expect(result.kind).toBe('changeTime');
    expect(result.enabled).toBe(true);
  });

  it('offers nothing when an armed row may not be edited either', () => {
    const result = armAffordance(
      publication({ status: 'scheduled', can_be_scheduled: true, can_be_edited: false }),
    );
    expect(result.kind).toBe('none');
  });
});

describe('armAffordance — D5 closure 2: a public destination with no account', () => {
  it('disables arming WITH A REASON when nobody is named to publish as', () => {
    // Nothing would refuse this write; the publication would be armed and fail at its minute.
    const result = armAffordance(
      publication({ publishes_publicly: true, platform_connection_id: null }),
      [connection()],
    );
    expect(result.kind).toBe('schedule');
    expect(result.enabled).toBe(false);
    expect(result.blockedBy).toBe('noAccount');
  });

  it('leaves a rehearsal alone — it publishes nothing and needs no account', () => {
    const result = armAffordance(
      publication({ platform: 'dry_run', publishes_publicly: false, platform_connection_id: null }),
    );
    expect(result.enabled).toBe(true);
    expect(result.blockedBy).toBeNull();
  });
});

describe('armAffordance — D5 closure 3: on hold behind a broken account', () => {
  it('disables re-arming while the connection is still unusable', () => {
    const result = armAffordance(publication({ status: 'blocked' }), [
      connection({ status: 'needs_reauth', can_publish: false, needs_attention: true }),
    ]);
    expect(result.kind).toBe('reschedule');
    expect(result.enabled).toBe(false);
    expect(result.blockedBy).toBe('connectionBroken');
  });

  it('allows re-arming once the connection works again', () => {
    const result = armAffordance(publication({ status: 'blocked' }), [connection()]);
    expect(result.enabled).toBe(true);
  });

  it('does NOT block on a connections list it has not got', () => {
    // An unanswered secondary request must not disable an action: the server can refuse, and
    // a locally invented refusal cannot be appealed.
    expect(armAffordance(publication({ status: 'blocked' })).enabled).toBe(true);
    expect(armAffordance(publication({ status: 'blocked' }), []).enabled).toBe(true);
    expect(armAffordance(publication({ status: 'blocked' }), null).enabled).toBe(true);
  });
});

describe('connectionHealth', () => {
  it('reads `can_publish`, not the status word', () => {
    expect(connectionHealth('c1', [connection({ can_publish: true, status: 'needs_reauth' })])).toBe(
      'usable',
    );
    expect(connectionHealth('c1', [connection({ can_publish: false })])).toBe('unusable');
  });

  it('is `unknown` when there is nothing to read', () => {
    expect(connectionHealth(null, [connection()])).toBe('unknown');
    expect(connectionHealth('c9', [connection()])).toBe('unknown');
    expect(connectionHealth('c1', null)).toBe('unknown');
  });
});

describe('a review changes what arming MEANS', () => {
  it('names the act "send for approval" when a pipeline is attached and unapproved', () => {
    const result = armAffordance(
      publication({ approval_pipeline_id: 'pipe-1', approval_state: null }),
      [connection()],
    );
    expect(result.kind).toBe('submitForReview');
  });

  it('treats a REJECTED publication as still needing review', () => {
    // A turned-down publication is a `draft` again and says nothing about it on `status`;
    // `approval_state` is the only place that fact lives.
    expect(
      hasPendingReview(publication({ approval_pipeline_id: 'pipe-1', approval_state: 'rejected' })),
    ).toBe(true);
  });

  it('goes back to plain scheduling once an approval is in hand', () => {
    const approved = publication({ approval_pipeline_id: 'pipe-1', approval_state: 'approved' });
    expect(hasPendingReview(approved)).toBe(false);
    expect(armAffordance(approved, [connection()]).kind).toBe('schedule');
  });

  it('names the live review when the flags have gone quiet', () => {
    const result = armAffordance(
      publication({ is_in_approval: true, can_be_scheduled: false, can_be_edited: false }),
    );
    expect(result.kind).toBe('none');
    expect(result.blockedBy).toBe('underReview');
  });
});

describe('scheduleOutcomeOf — two outcomes, both 200', () => {
  it('reports ARMED when the row came back scheduled', () => {
    const armed = publication({
      status: 'scheduled',
      is_in_approval: false,
      scheduled_at: '2026-09-10T07:00:00.000000Z',
      intended_publish_at: '2026-09-10T07:00:00.000000Z',
    });
    expect(scheduleOutcomeOf(armed)).toBe('armed');
  });

  it('reports SUBMITTED when the row came back a draft under review', () => {
    // This is the B6 behaviour: `POST …/schedule` parked the moment and opened a review.
    // Announcing "scheduled" here would promise a publication nobody has approved.
    const submitted = publication({
      status: 'draft',
      is_in_approval: true,
      approval_pipeline_id: 'pipe-1',
      approval_state: 'pending',
      scheduled_at: null,
      intended_publish_at: '2026-09-10T07:00:00.000000Z',
    });
    expect(scheduleOutcomeOf(submitted)).toBe('submittedForReview');
  });

  it('never reports ARMED for a row that did not reach `scheduled`', () => {
    const neither = publication({ status: 'draft', is_in_approval: false });
    expect(scheduleOutcomeOf(neither)).toBe('submittedForReview');
  });

  it('lets the REVIEW FLAG win over the status, not the other way round', () => {
    // `is_in_approval` is the discriminator; `status` is the belt-and-braces. The server
    // does not produce this combination today — arming is exactly what a concluded approval
    // does — but a screen that read `status` first would announce "Scheduled" the day the
    // two ever disagreed, which is the announcement that must never be wrong.
    const contradictory = publication({ status: 'scheduled', is_in_approval: true });
    expect(scheduleOutcomeOf(contradictory)).toBe('submittedForReview');
  });
});

describe('intendedMomentOf', () => {
  it('answers for both halves of a publication’s life', () => {
    expect(
      intendedMomentOf(publication({ intended_publish_at: '2026-09-10T07:00:00.000000Z' })),
    ).toBe('2026-09-10T07:00:00.000000Z');
    // Older payloads / a row with only the armed column still answer.
    expect(
      intendedMomentOf(
        publication({ intended_publish_at: null, scheduled_at: '2026-09-11T07:00:00.000000Z' }),
      ),
    ).toBe('2026-09-11T07:00:00.000000Z');
    expect(intendedMomentOf(publication())).toBeNull();
  });
});

describe('deleteCopyKind — four situations, four sentences', () => {
  it('distinguishes them', () => {
    expect(deleteCopyKind(publication({ status: 'draft' }))).toBe('plain');
    expect(deleteCopyKind(publication({ status: 'failed' }))).toBe('plain');
    expect(deleteCopyKind(publication({ status: 'scheduled' }))).toBe('scheduled');
    expect(deleteCopyKind(publication({ status: 'blocked' }))).toBe('blocked');
    // The one that matters: deleting hides OUR RECORD; the post stays on the platform.
    expect(deleteCopyKind(publication({ status: 'published' }))).toBe('published');
  });
});

describe('hasRowActions', () => {
  it('is false for a row whose only entry would repeat clicking the card', () => {
    const inFlight = publication({
      status: 'publishing',
      can_be_edited: false,
      can_be_deleted: false,
      can_be_scheduled: false,
      can_be_reconciled: false,
    });
    expect(hasRowActions(inFlight)).toBe(false);
  });

  it('is true when a published row at least links to the post', () => {
    const published = publication({
      status: 'published',
      remote_url: 'https://youtu.be/x',
      can_be_edited: false,
      can_be_scheduled: false,
      can_be_reconciled: false,
    });
    expect(hasRowActions(published)).toBe(true);
  });

  it('is true for a row that can only be checked', () => {
    const unknownOutcome = publication({
      status: 'needs_reconcile',
      can_be_edited: false,
      can_be_deleted: false,
      can_be_scheduled: false,
      can_be_reconciled: true,
    });
    expect(hasRowActions(unknownOutcome)).toBe(true);
  });
});
