// @vitest-environment happy-dom
// PublicationCard.spec — the attention accent, which is a MEANING and not a decoration.
//
// §13.1 fixes what the two colours say, and the pair is easy to "tidy" into one:
//
//   • RED (`danger`) — `failed` and `needs_reconcile`: this row needs a DECISION, here, from
//     the person reading the list.
//   • AMBER (`warning`) — `blocked`: the cause is somewhere else entirely (an account that
//     stopped working) and twelve held publications are twelve symptoms of ONE repair. Red
//     here would put twelve alarms in the list for a single broken connection, and the
//     Connections screen — the red one, where the fix actually is — would stop standing out.
//
// The accent is also a LEFT EDGE, never a filled card: twenty red cards in a list stop meaning
// anything. And it is never alone — the failure sentence stands in the footer beside it.
//
// ═════════════════════════════════════════════════════════════════════════════════════════
// AND THE KEBAB, WHICH IS GATED TWICE OVER (§16.4 pkt 7)
// ═════════════════════════════════════════════════════════════════════════════════════════
// A menu whose only entry duplicates clicking the card teaches that the kebab is SOMETIMES
// empty — and then nobody opens it when it is not. So a row with nothing to offer renders no
// kebab at all, and `publishing` is exactly that row: it belongs to the process sending it,
// every `can_be_*` is false, and there is no remote url yet.
//
// The arming entry is also NAMED FOR WHAT IT DOES. On an armed row `POST …/schedule` is not
// what happens — that edge does not exist and the server answers
// `publication_transition_not_allowed` — so the entry is "Change the time" and the call
// behind it is a whole-row `PUT`. A menu that still said "Schedule" there would offer an
// action whose only possible outcome is a refusal.
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { h, type VNode } from 'vue';

vi.mock('vue-router', () => ({ useRouter: () => ({ push: vi.fn() }) }));

import PublicationCard from '../PublicationCard.vue';
import { setLocale } from '../../../app/i18n';
import type { Publication } from '../types';

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

// Render the trigger + the menu items inline, skipping the Popover's teleport and
// positioning (the menu's own behaviour has the overlay specs).
const DropdownMenuStub = {
  name: 'DropdownMenu',
  setup(_props: unknown, { slots }: { slots: Record<string, ((arg?: unknown) => VNode[]) | undefined> }) {
    return () =>
      h('div', { class: 'dm' }, [
        slots.trigger ? slots.trigger({ props: {} }) : null,
        h('ul', { class: 'dm-list' }, slots.default ? slots.default() : []),
      ]);
  },
};

const DropdownMenuItemStub = {
  name: 'DropdownMenuItem',
  props: ['icon', 'label', 'disabled', 'destructive'],
  setup(
    props: Record<string, unknown>,
    { slots, emit }: { slots: Record<string, (() => VNode[]) | undefined>; emit: (e: string) => void },
  ) {
    return () =>
      h(
        'button',
        {
          class: 'dm-item',
          'data-label': props.label,
          'data-disabled': props.disabled ? 'true' : undefined,
          onClick: () => emit('select'),
        },
        slots.default ? slots.default() : [],
      );
  },
};

function mountCard(record: Publication) {
  return mount(PublicationCard, {
    props: { publication: record, timezone: 'Europe/Warsaw', connections: null },
    global: {
      stubs: {
        RouterLink: true,
        DropdownMenu: DropdownMenuStub,
        DropdownMenuItem: DropdownMenuItemStub,
      },
    },
  });
}

/** The kebab's entries, in order, by the label a reader sees. */
function menuLabels(wrapper: ReturnType<typeof mountCard>): string[] {
  return wrapper.findAll('.dm-item').map((b) => b.text().trim());
}

/**
 * The rendered card's class list. The accent falls through `EntityCard` → `Card` → `Surface`
 * and lands on the one element that carries `next-entity-card`, which is what is asked for
 * here rather than the wrapper's own node (a leading comment in `EntityCard`'s template makes
 * the component's root a fragment).
 */
function classesOf(record: Publication): string {
  const card = mountCard(record).find('.next-entity-card');
  if (!card.exists()) throw new Error('the entity card did not render');
  return card.attributes('class') ?? '';
}

beforeEach(() => {
  setLocale('en');
});

describe('the attention accent', () => {
  it('is AMBER on a held publication — the repair is somewhere else, not here', () => {
    const held = publication({
      status: 'blocked',
      status_label: 'On hold',
      status_tone: 'warning',
      needs_attention: true,
      failure_code: 'connection_needs_reauth',
      scheduled_at: '2026-09-10T07:00:00.000000Z',
    });

    const classes = classesOf(held);
    expect(classes).toContain('border-l-next-warning');
    // Red is reserved for a row that needs a DECISION here (§13.1).
    expect(classes).not.toContain('border-l-next-danger');
  });

  it('is RED on the two states that need a decision from this reader', () => {
    for (const status of ['failed', 'needs_reconcile'] as const) {
      const classes = classesOf(
        publication({ status, status_tone: 'danger', needs_attention: true, failure_code: 'reaper_stale' }),
      );
      expect(classes, status).toContain('border-l-next-danger');
      expect(classes, status).not.toContain('border-l-next-warning');
    }
  });

  it('is an EDGE and not a filled card, and is absent when nothing is wrong', () => {
    const held = classesOf(
      publication({ status: 'blocked', status_tone: 'warning', needs_attention: true }),
    );
    expect(held).toContain('border-l-2');
    expect(held).not.toContain('bg-next-warning');

    const calm = classesOf(publication({ status: 'scheduled', status_tone: 'info' }));
    expect(calm).not.toContain('border-l-2');
  });

  it('never lets colour carry it alone — the failure sentence stands in the footer', () => {
    const wrapper = mountCard(
      publication({
        status: 'blocked',
        status_label: 'On hold',
        status_tone: 'warning',
        needs_attention: true,
        failure_code: 'connection_disconnected',
      }),
    );
    expect(wrapper.text()).toContain('the account this goes out on was disconnected');
  });
});

describe('the kebab', () => {
  /** The row that belongs to the process sending it: every flag false, no url yet. */
  const goingOut = publication({
    status: 'publishing',
    status_label: 'Publishing',
    status_tone: 'warning',
    can_be_edited: false,
    can_be_deleted: false,
    can_be_scheduled: false,
    can_be_reconciled: false,
    last_attempt_at: '2026-09-10T07:00:00.000000Z',
  });

  it('DOES NOT RENDER on a `publishing` row — an empty menu teaches that menus are empty', () => {
    const wrapper = mountCard(goingOut);

    expect(wrapper.find('.dm').exists()).toBe(false);
    expect(menuLabels(wrapper)).toEqual([]);
    // Not even the trigger: an "Open" that repeats clicking the card is not an action.
    expect(
      wrapper.findAll('button').some((b) => b.attributes('aria-label')?.startsWith('Publication actions')),
    ).toBe(false);
  });

  it('renders as soon as the row has ONE thing to offer beyond Open', () => {
    // `can_be_deleted` alone is enough — the gate is "anything at all", not a quorum.
    const wrapper = mountCard({ ...goingOut, can_be_deleted: true });
    expect(wrapper.find('.dm').exists()).toBe(true);
    expect(menuLabels(wrapper)).toContain('Delete');
  });

  it('names arming "Change the time" on an ARMED row, never "Schedule"', () => {
    const armed = publication({
      status: 'scheduled',
      status_label: 'Scheduled',
      status_tone: 'info',
      scheduled_at: '2026-09-10T07:00:00.000000Z',
    });
    const wrapper = mountCard(armed);

    expect(menuLabels(wrapper)).toContain('Change the time');
    // `scheduled → scheduled` is not an edge; the server would refuse the call that word
    // promises, so the word must not be there.
    expect(menuLabels(wrapper)).not.toContain('Schedule');
    expect(menuLabels(wrapper)).not.toContain('Schedule again');
  });

  it('says "Schedule" on a draft and "Schedule again" on a row that failed', () => {
    expect(menuLabels(mountCard(publication()))).toContain('Schedule');
    expect(
      menuLabels(mountCard(publication({ status: 'failed', failure_code: 'dispatch_failed' }))),
    ).toContain('Schedule again');
  });

  it('swaps the delete wording on a published row — the post is not what goes', () => {
    const wrapper = mountCard(
      publication({
        status: 'published',
        status_label: 'Published',
        status_tone: 'success',
        published_at: '2026-09-10T07:00:00.000000Z',
        remote_url: 'https://youtu.be/x',
        can_be_edited: false,
        can_be_scheduled: false,
      }),
    );
    const items = menuLabels(wrapper);
    expect(items).toContain('Open on the platform');
    expect(items).toContain('Delete our record');
    expect(items).not.toContain('Delete');
  });
});
