// @vitest-environment happy-dom
// PublicationStatusBand.spec — seven screens, not one screen with seven badges.
//
// ═════════════════════════════════════════════════════════════════════════════════════════
// WHAT THIS FILE PINS, AND WHY EACH OF IT FAILS SILENTLY
// ═════════════════════════════════════════════════════════════════════════════════════════
//   • THE LIVE REGION ROLE. `failed` / `blocked` are `alert` (assertive) because somebody has
//     to act; everything else is `status`. `publishing` is the trap: it looks urgent — a
//     spinning glyph on an amber surface — and nothing is expected of the reader, so an
//     assertive band there would interrupt a screen reader to say "carry on waiting". A
//     single `:role="…"` simplification collapses all seven into one and nothing on screen
//     changes by a pixel.
//   • THE ACTION SET PER VARIANT. Every affordance is gated on a `can_be_*` flag; an absent
//     button is a deliberate statement, and the band's whole argument is that the ABSENCE
//     comes with a sentence. `publishing` in particular must offer nothing at all: the row
//     belongs to the process sending it.
//   • THE WITHHELD ARMING BUTTON. `aria-disabled` is an ANNOUNCEMENT, not a behaviour — the
//     click still arrives. So the refusal has to be enforced in `onArm()` as well as painted,
//     and both halves are asserted here: a button that looks live, lights up under the cursor
//     and does nothing is worse than one that is plainly inert.
//
// i18n is the real singleton pinned to `en` — the assertions read the catalog's own prose,
// never this file's invention.
import { describe, it, expect, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import PublicationStatusBand from '../PublicationStatusBand.vue';
import { setLocale } from '../../../app/i18n';
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

function mountBand(record: Publication, connections: PlatformConnection[] | null = null, pipelineName?: string) {
  return mount(PublicationStatusBand, {
    props: { publication: record, timezone: 'Europe/Warsaw', connections, pipelineName },
  });
}

type Wrapper = ReturnType<typeof mountBand>;

/** Every control on the band, by its visible label — what a reader can actually reach. */
function labels(wrapper: Wrapper): string[] {
  return wrapper.findAll('button, a').map((b) => b.text().trim());
}

function control(wrapper: Wrapper, label: RegExp) {
  return wrapper.findAll('button, a').find((b) => label.test(b.text()));
}

beforeEach(() => {
  setLocale('en');
});

// ── The seven variants ──────────────────────────────────────────────────────

describe('the band announces itself POLITELY unless somebody has to act', () => {
  it('is `status` for draft, scheduled, publishing and published', () => {
    const polite: Publication[] = [
      publication({ status: 'draft' }),
      publication({ status: 'scheduled', scheduled_at: '2026-09-10T07:00:00.000000Z' }),
      publication({ status: 'publishing', can_be_edited: false, can_be_deleted: false, can_be_scheduled: false }),
      publication({ status: 'published', published_at: '2026-09-10T07:00:00.000000Z', can_be_edited: false, can_be_scheduled: false }),
    ];
    for (const record of polite) {
      expect(mountBand(record).find('section').attributes('role'), record.status).toBe('status');
    }
  });

  it('is `alert` for the two states somebody has to do something about', () => {
    for (const status of ['failed', 'blocked'] as const) {
      const wrapper = mountBand(publication({ status, failure_code: 'dispatch_failed' }));
      expect(wrapper.find('section').attributes('role'), status).toBe('alert');
    }
  });
});

describe('draft', () => {
  it('offers arming, editing and deleting, and says plainly that nothing is scheduled', () => {
    const wrapper = mountBand(publication());
    expect(wrapper.text()).toContain('This goes nowhere until you schedule it');
    expect(labels(wrapper)).toEqual(['Schedule', 'Edit', 'Delete']);
  });
});

describe('scheduled', () => {
  it('renames arming to "Change the time" — it is an edit, not a transition', () => {
    const wrapper = mountBand(
      publication({ status: 'scheduled', scheduled_at: '2026-09-10T07:00:00.000000Z' }),
    );
    const names = labels(wrapper);
    expect(names).toContain('Change the time');
    // "Schedule" would promise a second arming of something already armed; the server
    // refuses that edge outright (`publication_transition_not_allowed`).
    expect(names).not.toContain('Schedule');
  });

  it('names the one way to stop it, because there is no un-schedule', () => {
    const wrapper = mountBand(
      publication({ status: 'scheduled', scheduled_at: '2026-09-10T07:00:00.000000Z' }),
    );
    expect(wrapper.text()).toContain('the publication has to be deleted');
  });
});

describe('publishing', () => {
  const going = (extra: Partial<Publication> = {}) =>
    publication({
      status: 'publishing',
      can_be_edited: false,
      can_be_deleted: false,
      can_be_scheduled: false,
      last_attempt_at: '2026-09-10T07:00:00.000000Z',
      ...extra,
    });

  it('offers NOTHING — the row belongs to the process sending it', () => {
    expect(labels(mountBand(going()))).toEqual([]);
  });

  it('says so in a sentence rather than leaving three silences', () => {
    const wrapper = mountBand(going());
    expect(wrapper.text()).toContain('Going out now');
    expect(wrapper.text()).toContain('it cannot be changed or deleted from here');
  });

  it('drops the moment clause entirely when there is no moment to name', () => {
    const wrapper = mountBand(going({ last_attempt_at: null }));
    expect(wrapper.text()).toContain('it cannot be changed or deleted from here');
    // Never a dangling "This started " with nothing after it.
    expect(wrapper.text()).not.toContain('This started');
  });
});

describe('published', () => {
  it('leads with the road to the artifact and offers the record-deletion wording', () => {
    const wrapper = mountBand(
      publication({
        status: 'published',
        published_at: '2026-09-10T07:00:00.000000Z',
        remote_url: 'https://youtu.be/x',
        can_be_edited: false,
        can_be_scheduled: false,
      }),
    );
    const names = labels(wrapper);
    expect(names).toContain('Open on the platform');
    // NOT "Delete": what goes is our record, and the post stays in the world.
    expect(names).toContain('Delete our record');
    expect(names).not.toContain('Edit');
    expect(wrapper.text()).toContain('The record is not rewritten');
  });
});

describe('failed', () => {
  it('says WHY in the translated failure sentence and offers a second attempt', () => {
    const wrapper = mountBand(publication({ status: 'failed', failure_code: 'dispatch_failed' }));
    expect(wrapper.text()).toContain('This could not be handed to a worker');
    // Never a raw key on screen.
    expect(wrapper.text()).not.toContain('publishing.failures');
    expect(labels(wrapper)).toContain('Schedule again');
  });

  it('renders the UNKNOWN fallback, naming the code, for something this build never heard of', () => {
    const wrapper = mountBand(publication({ status: 'failed', failure_code: 'quota_exceeded' }));
    expect(wrapper.text()).toContain('a reason this version of the application does not describe yet');
    expect(wrapper.text()).toContain('quota_exceeded');
  });
});

describe('blocked', () => {
  it('leads with the CAUSE, which is on another screen entirely', () => {
    const wrapper = mountBand(
      publication({ status: 'blocked', failure_code: 'connection_needs_reauth' }),
      [connection()],
    );
    const names = labels(wrapper);
    expect(names[0]).toBe('Fix the connection');
    expect(names).toContain('Schedule again');
    expect(wrapper.text()).toContain('the account this goes out on needs reconnecting');
  });
});

// ── A live review ───────────────────────────────────────────────────────────

describe('a publication held for approval', () => {
  const held = publication({ can_be_edited: false, can_be_scheduled: false, is_in_approval: true });

  it('names the pipeline and offers the way to the review', () => {
    const wrapper = mountBand(held, null, 'Editorial sign-off');
    expect(wrapper.text()).toContain('Held for approval: Editorial sign-off.');
    expect(labels(wrapper)).toContain('See the approval');
  });

  it('falls back to the generic sentence when the pipeline has no name here', () => {
    const wrapper = mountBand(held);
    expect(wrapper.text()).toContain('Held for approval.');
    expect(wrapper.text()).not.toContain('{pipeline}');
  });

  it('names the hold instead of leaving the missing arming button unexplained', () => {
    const wrapper = mountBand(held);
    expect(wrapper.text()).toContain('This is with an approver right now');
    // The button itself is GONE (the flags say no); only the reason stands.
    expect(labels(wrapper)).not.toContain('Schedule');
  });

  it('says a refusal happened — the only place a rejected review is visible', () => {
    const wrapper = mountBand(
      publication({ is_in_approval: false, approval_state: 'rejected' }),
    );
    expect(wrapper.text()).toContain('An approver turned this down');
  });
});

// ── The withheld arming button ──────────────────────────────────────────────

describe('arming offered but WITHHELD', () => {
  /** A public destination with nobody to publish as (gap L3). */
  const noAccount = publication({ platform_connection_id: null });
  /** On hold, and the account that put it there is still broken (gap L5). */
  const brokenConnection = publication({ status: 'blocked', failure_code: 'connection_needs_reauth' });
  const brokenList = [connection({ can_publish: false, status: 'needs_reauth' })];

  it('paints the refusal — the house inert look, not `aria-disabled` alone', () => {
    for (const [name, wrapper] of [
      ['noAccount', mountBand(noAccount)],
      ['connectionBroken', mountBand(brokenConnection, brokenList)],
    ] as const) {
      const button = control(wrapper, /Schedule/)!;
      expect(button, name).toBeDefined();
      expect(button.attributes('aria-disabled'), name).toBe('true');
      // `aria-disabled` is a promise to assistive technology the pointer never sees. The
      // house inert classes are what a sighted reader gets instead.
      const classes = button.attributes('class') ?? '';
      expect(classes, name).toContain('opacity-60');
      expect(classes, name).toContain('cursor-not-allowed');
    }
  });

  it('states the reason beside it, in words, per closure', () => {
    expect(mountBand(noAccount).text()).toContain('Choose the account this goes out on first');
    expect(mountBand(brokenConnection, brokenList).text()).toContain('Fix the connection first');
  });

  it('REFUSES the activation as well as painting it — click and Enter both emit nothing', async () => {
    const wrapper = mountBand(noAccount);
    const button = control(wrapper, /Schedule/)!;

    // A native <button> turns Enter into a click, so one guard has to cover both roads;
    // the keydown is triggered too, to pin that nothing else listens for it.
    await button.trigger('keydown', { key: 'Enter' });
    await button.trigger('click');

    expect(wrapper.emitted('schedule')).toBeUndefined();
  });

  it('still emits when nothing blocks it — the guard is a refusal, not a dead button', async () => {
    const wrapper = mountBand(publication());
    await control(wrapper, /Schedule/)!.trigger('click');
    expect(wrapper.emitted('schedule')).toHaveLength(1);
  });
});
