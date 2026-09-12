// @vitest-environment happy-dom
// ReconcilePanel.spec — the three outcomes, the throttle, and the lost race.
//
// THE ASSERTION THIS FILE EXISTS FOR IS THE THIRD OUTCOME. When the platform cannot be
// asked, the row comes back UNCHANGED with a 200: the request succeeded, the state is
// correct, and nothing was published. Showing that as a failure — a red alert, a danger
// toast — would teach that checking "breaks", when checking is the one act worth repeating
// from this state. So the test asserts on the ABSENCE of those two things as well as on the
// presence of the polite message.
//
// The store and the toast API are mocked; i18n is the real singleton pinned to `en`.
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { h, type VNode } from 'vue';
import ReconcilePanel from '../ReconcilePanel.vue';
import { setLocale } from '../../../app/i18n';
import type { Publication } from '../types';

const reconcileMock = vi.fn();
const fetchMock = vi.fn();

vi.mock('../../../app/stores/publishing', () => ({
  usePublishingStore: () => ({
    reconcilePublication: reconcileMock,
    fetchPublication: fetchMock,
  }),
}));

const toastCalls: Array<{ variant: string; text: string }> = [];
vi.mock('../../../app/composables/useToast', () => ({
  useToast: () => ({
    success: (text: string) => toastCalls.push({ variant: 'success', text }),
    info: (text: string) => toastCalls.push({ variant: 'info', text }),
    warning: (text: string) => toastCalls.push({ variant: 'warning', text }),
    danger: (text: string) => toastCalls.push({ variant: 'danger', text }),
  }),
}));

// Render the Accordion's slot inline — its disclosure behaviour is covered elsewhere.
const AccordionStub = {
  name: 'Accordion',
  setup(_p: unknown, { slots }: { slots: Record<string, (() => VNode[]) | undefined> }) {
    return () => h('div', slots.default ? slots.default() : []);
  },
};
const AccordionItemStub = {
  name: 'AccordionItem',
  props: ['value', 'title'],
  setup(props: Record<string, unknown>, { slots }: { slots: Record<string, (() => VNode[]) | undefined> }) {
    return () =>
      h('div', [h('h3', String(props.title ?? '')), ...(slots.default ? slots.default() : [])]);
  },
};

function publication(overrides: Partial<Publication> = {}): Publication {
  return {
    id: 'p1',
    title: 'Autumn teaser',
    body: null,
    platform: 'youtube',
    platform_label: 'YouTube',
    publishes_publicly: true,
    platform_connection_id: 'c1',
    status: 'needs_reconcile',
    status_label: 'Needs checking',
    status_tone: 'danger',
    needs_attention: true,
    scheduled_at: '2026-09-10T07:00:00.000000Z',
    published_at: null,
    media: [],
    options: {},
    remote_id: null,
    remote_url: null,
    attempts: 1,
    last_attempt_at: '2026-09-10T07:00:00.000000Z',
    failure_code: 'reaper_stale',
    failure_context: { stale_after_seconds: 900 },
    approval_pipeline_id: null,
    is_in_approval: false,
    approval_state: null,
    intended_publish_at: null,
    creator: null,
    is_owner: true,
    can_be_edited: false,
    can_be_deleted: false,
    can_be_scheduled: false,
    can_be_reconciled: true,
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

function mountPanel(record: Publication = publication()) {
  return mount(ReconcilePanel, {
    props: { publication: record, timezone: 'Europe/Warsaw' },
    global: {
      stubs: { Accordion: AccordionStub, AccordionItem: AccordionItemStub },
    },
  });
}

/** The panel's only action. */
function checkButton(wrapper: ReturnType<typeof mountPanel>) {
  return wrapper.findAll('button').find((b) => /check|sprawdź/i.test(b.text()));
}

beforeEach(() => {
  setLocale('en');
  toastCalls.length = 0;
  reconcileMock.mockReset();
  fetchMock.mockReset();
  vi.useRealTimers();
});

describe('the panel itself', () => {
  it('asks the question and says what the button will NOT do', () => {
    const wrapper = mountPanel();
    const text = wrapper.text();
    expect(text).toContain('We do not know whether this went out');
    // Half the button's value is this sentence.
    expect(text).toContain('Nothing will be published or changed on the platform.');
    // And the automatic pass, so "I click nothing" is a strategy rather than a lapse.
    expect(text).toContain('roughly once an hour');
  });

  it('renders the translated failure code, not the raw key', () => {
    const wrapper = mountPanel();
    expect(wrapper.text()).toContain('This was claimed for publishing and nothing came back');
    expect(wrapper.text()).not.toContain('publishing.failures');
  });

  it('renders only whitelisted `failure_context` keys', () => {
    const wrapper = mountPanel(
      publication({
        failure_context: {
          stale_after_seconds: 900,
          exception: 'Illuminate\\Database\\QueryException',
        },
      }),
    );
    expect(wrapper.text()).toContain('waited over 15 min');
    // An exception class name is not a message for a person.
    expect(wrapper.text()).not.toContain('Illuminate');
  });

  it('replaces the button with a REASON when checking is not this reader’s to do', () => {
    const wrapper = mountPanel(publication({ can_be_reconciled: false }));
    expect(checkButton(wrapper)).toBeUndefined();
    expect(wrapper.text()).toContain('The author of this publication, or the workspace owner');
  });

  it('names each missing action instead of leaving three silences', () => {
    const wrapper = mountPanel();
    const text = wrapper.text();
    expect(text).toContain('Why can I not simply try again?');
    expect(text).toContain('a SECOND time');
    expect(text).toContain('cannot be edited now');
    expect(text).toContain('cannot be deleted');
  });
});

describe('outcome 1 — found', () => {
  it('announces success and hands the new row up', async () => {
    reconcileMock.mockResolvedValueOnce(
      publication({ status: 'published', remote_url: 'https://youtu.be/x', failure_code: null }),
    );

    const wrapper = mountPanel();
    await checkButton(wrapper)!.trigger('click');
    await flushPromises();

    expect(toastCalls).toEqual([
      { variant: 'success', text: 'The post exists on the platform. The publication is marked as published.' },
    ]);
    expect(wrapper.emitted('updated')?.[0]?.[0]).toMatchObject({ status: 'published' });
  });
});

describe('outcome 2 — proven absent', () => {
  it('is INFO, because it is the good news of the three', async () => {
    reconcileMock.mockResolvedValueOnce(
      publication({ status: 'failed', failure_code: 'reconciled_absent', can_be_scheduled: true }),
    );

    const wrapper = mountPanel();
    await checkButton(wrapper)!.trigger('click');
    await flushPromises();

    expect(toastCalls).toHaveLength(1);
    expect(toastCalls[0].variant).toBe('info');
    // Not danger: nothing was published, so scheduling is legal again.
    expect(toastCalls.some((c) => c.variant === 'danger')).toBe(false);
  });
});

describe('outcome 3 — still nobody knows', () => {
  it('is NOT an error: no danger toast, no danger alert, and the message stays in the panel', async () => {
    // The row comes back unchanged. The request succeeded and the state is correct.
    reconcileMock.mockResolvedValueOnce(publication());

    const wrapper = mountPanel();
    await checkButton(wrapper)!.trigger('click');
    await flushPromises();

    // No toast AT ALL — the message belongs where the person is already looking.
    expect(toastCalls).toEqual([]);

    const text = wrapper.text();
    expect(text).toContain('We still could not establish this');
    expect(text).toContain('Nothing changed and nothing was published');

    // And it is announced politely, not asserted as an alarm.
    const statusRegions = wrapper.findAll('[role="status"]');
    expect(statusRegions.length).toBeGreaterThan(0);

    // No escalation of any kind: the contract says a row may stay here forever.
    expect(text).not.toMatch(/\battempt \d+ of \d+\b/i);
  });

  it('leaves the action available, because repeating it is the sensible thing', async () => {
    reconcileMock.mockResolvedValueOnce(publication());
    const wrapper = mountPanel();
    await checkButton(wrapper)!.trigger('click');
    await flushPromises();
    expect(checkButton(wrapper)).toBeDefined();
  });
});

describe('429 — too many checks', () => {
  it('counts down from `Retry-After`, blocks the action, and explains the real reason', async () => {
    vi.useFakeTimers();
    reconcileMock.mockRejectedValueOnce({
      response: { status: 429, data: {}, headers: { 'retry-after': '30' } },
    });

    const wrapper = mountPanel();
    await checkButton(wrapper)!.trigger('click');
    await flushPromises();

    const button = wrapper.findAll('button').find((b) => /Check in/i.test(b.text()));
    expect(button).toBeDefined();
    expect(button!.text()).toContain('30');
    expect(button!.attributes('aria-disabled')).toBe('true');
    // The true reason, and the only one that explains why the limit is this low.
    expect(wrapper.text()).toContain('shared by the whole application');

    // It comes back BY ITSELF, with no reload and no second click to unlock it.
    vi.advanceTimersByTime(30000);
    await flushPromises();
    expect(wrapper.findAll('button').some((b) => /Check the platform/i.test(b.text()))).toBe(true);
    vi.useRealTimers();
  });

  it('falls back to a minute when the header is missing', async () => {
    vi.useFakeTimers();
    reconcileMock.mockRejectedValueOnce({ response: { status: 429, data: {}, headers: {} } });

    const wrapper = mountPanel();
    await checkButton(wrapper)!.trigger('click');
    await flushPromises();

    expect(wrapper.findAll('button').some((b) => b.text().includes('60'))).toBe(true);
    vi.useRealTimers();
  });
});

describe('422 lost race — the screen is out of date, not broken', () => {
  it('renders the SERVER’s sentence and one Refresh action, and invents nothing', async () => {
    const serverMessage =
      'Something else has already dealt with this publication — it is now “published”. Nothing was changed. Refresh to see where it stands.';
    reconcileMock.mockRejectedValueOnce({
      response: {
        status: 422,
        data: {
          code: 'publication_transition_lost_race',
          message: serverMessage,
          context: { from: 'published', to: 'published' },
        },
        headers: {},
      },
    });

    const wrapper = mountPanel();
    await checkButton(wrapper)!.trigger('click');
    await flushPromises();

    expect(wrapper.text()).toContain(serverMessage);
    expect(wrapper.findAll('button').some((b) => /refresh/i.test(b.text()))).toBe(true);
    // No toast, and above all NO automatic reload — the person has to read that their click
    // changed nothing.
    expect(toastCalls).toEqual([]);
    expect(fetchMock).not.toHaveBeenCalled();
  });
});
