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
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';

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

function mountCard(record: Publication) {
  return mount(PublicationCard, {
    props: { publication: record, timezone: 'Europe/Warsaw', connections: null },
    global: { stubs: { RouterLink: true } },
  });
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
