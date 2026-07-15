// @vitest-environment happy-dom
// WorkflowRunTimeline — the form_submitted trigger item (Item 1). It is a LEAN item
// composed ENTIRELY from the ONE run-show response's resolved `form` block (id + name
// + description + icon) — NO second API call. The description shows only when present;
// when the `form` block is absent (deleted/foreign trigger form) it falls back to the
// name-only `trigger_payload.form`. The whole card is a NEW-TAB link to the form. The
// runs store + router + toast are mocked; the diff drawer is stubbed. A forms-store
// spy is installed only to assert it is NEVER called.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import { setLocale } from '../../../app/i18n';
import type { WorkflowRun } from '../types';

// A form_submitted run WITH the resolved `form` block (name + description + icon).
const baseRun: WorkflowRun = {
  id: 'r1',
  state: 'completed',
  state_label: 'Completed',
  state_tone: 'success',
  origin: 'event',
  trigger_type: 'form_submitted',
  depth: 0,
  origin_run_id: null,
  error: null,
  started_at: '2026-01-01T00:00:00Z',
  finished_at: '2026-01-01T00:00:05Z',
  created_at: '2026-01-01T00:00:00Z',
  duration_seconds: 5,
  form: { id: 'form-9', name: 'Contact form', description: 'Reach the team', icon: null },
  trigger_payload: {
    form: { id: 'form-9', name: 'Contact form', is_anonymous: false },
    submission: { id: 'sub-1' },
    source: 'manual',
    submitted_at: '2026-01-01T00:00:00Z',
    fields: {},
  },
  steps: [],
};

const fetchRun = vi.fn();
vi.mock('../../../app/stores/workflowRuns', () => ({
  useWorkflowRunsStore: () => ({ fetchRun, retryRun: vi.fn() }),
}));

// A spy that must stay untouched: the run preview is a SINGLE call; the form is NOT
// fetched separately. The component should not even import the forms store — this mock
// exists only so an accidental fetch would show up here.
const fetchForm = vi.fn();
vi.mock('../../../app/stores/forms', () => ({
  useFormsStore: () => ({ fetchForm }),
}));

vi.mock('../../../app/composables/useToast', () => ({
  useToast: () => ({ success: vi.fn(), danger: vi.fn() }),
}));

// The router resolves the form's detail href from its id.
vi.mock('vue-router', () => ({
  useRouter: () => ({
    resolve: (loc: { params?: { id?: string } }) => ({ href: `/forms/${loc.params?.id ?? ''}` }),
    push: vi.fn(),
    replace: vi.fn(),
  }),
}));

import WorkflowRunTimeline from '../WorkflowRunTimeline.vue';

function mountTimeline() {
  return mount(WorkflowRunTimeline, {
    attachTo: document.body,
    props: { workflowId: 'wf-1', runId: 'r1' },
    global: { stubs: { SubmissionPreviewDrawer: true } },
  });
}

beforeEach(() => {
  installBrowserMocks();
  setLocale('en');
  fetchRun.mockReset();
  fetchForm.mockReset();
});
afterEach(() => {
  restoreBrowserMocks();
  document.body.innerHTML = '';
});

describe('WorkflowRunTimeline — form_submitted item is a LEAN item (no fetch)', () => {
  it('renders name + description from the resolved run.form and never fetches the form', async () => {
    fetchRun.mockResolvedValue(baseRun);
    const wrapper = mountTimeline();
    await flushPromises();

    expect(wrapper.text()).toContain('Contact form');
    expect(wrapper.text()).toContain('Reach the team');
    // The ONE run-show call is the only fetch — the form is NOT fetched separately.
    expect(fetchRun).toHaveBeenCalledTimes(1);
    expect(fetchForm).not.toHaveBeenCalled();
    wrapper.unmount();
  });

  it('opens the form in a new tab as a whole-card link', async () => {
    fetchRun.mockResolvedValue(baseRun);
    const wrapper = mountTimeline();
    await flushPromises();

    const anchor = wrapper.findAll('a').find((a) => a.attributes('href') === '/forms/form-9');
    expect(anchor).toBeTruthy();
    expect(anchor?.attributes('target')).toBe('_blank');
    expect(anchor?.attributes('rel')).toBe('noopener noreferrer');
    wrapper.unmount();
  });

  it('skips the description when the resolved form has none', async () => {
    fetchRun.mockResolvedValue({
      ...baseRun,
      form: { id: 'form-9', name: 'Contact form', description: null, icon: null },
    });
    const wrapper = mountTimeline();
    await flushPromises();

    expect(wrapper.text()).toContain('Contact form');
    expect(wrapper.text()).not.toContain('Reach the team');
    wrapper.unmount();
  });

  it('falls back to trigger_payload.form (name only) when run.form is absent — still no fetch, still a new-tab link', async () => {
    const withoutForm = { ...baseRun };
    delete (withoutForm as { form?: unknown }).form;
    fetchRun.mockResolvedValue(withoutForm as WorkflowRun);
    const wrapper = mountTimeline();
    await flushPromises();

    expect(wrapper.text()).toContain('Contact form');
    expect(fetchForm).not.toHaveBeenCalled();
    const anchor = wrapper.findAll('a').find((a) => a.attributes('href') === '/forms/form-9');
    expect(anchor).toBeTruthy();
    expect(anchor?.attributes('target')).toBe('_blank');
    wrapper.unmount();
  });
});
