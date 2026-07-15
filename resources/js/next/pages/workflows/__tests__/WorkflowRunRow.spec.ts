// @vitest-environment happy-dom
// WorkflowRunRow.spec — the B3 badge collapse (one relabelled SOURCE badge, no
// redundant trigger_type badge) + the B5 `showWorkflow` column (the parent-workflow
// identity the GLOBAL cross-workflow runs list renders). The i18n singleton is pinned
// to `en` so the relabelled origin reads "Form submission".
import { describe, it, expect, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import WorkflowRunRow from '../WorkflowRunRow.vue';
import Badge from '../../../ui/primitives/Badge.vue';
import { setLocale } from '../../../app/i18n';
import type { WorkflowRun, WorkflowRunWorkflow } from '../types';

function run(overrides: Partial<WorkflowRun> = {}): WorkflowRun {
  return {
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
    steps_count: 2,
    ...overrides,
  };
}

const workflow: WorkflowRunWorkflow = {
  id: 'wf-9',
  name: 'Publish nightly digest',
  icon: 'workflow',
  status: 'active',
  trigger_type: 'schedule',
};

beforeEach(() => {
  setLocale('en');
});

describe('WorkflowRunRow — B3 source badge collapse', () => {
  it('renders ONE relabelled source badge (state + source only — no trigger badge)', () => {
    const wrapper = mount(WorkflowRunRow, { props: { run: run() } });
    // State badge + the single source badge = 2 (was 3 before the collapse).
    expect(wrapper.findAllComponents(Badge)).toHaveLength(2);
    // The `event` origin now reads as a form submission, not the generic "Event".
    expect(wrapper.text()).toContain('Form submission');
    expect(wrapper.text()).not.toContain('Event');
  });
});

describe('WorkflowRunRow — B5 showWorkflow column', () => {
  it('renders the parent workflow name when showWorkflow + run.workflow are present', () => {
    const wrapper = mount(WorkflowRunRow, {
      props: { run: run({ workflow }), showWorkflow: true },
    });
    expect(wrapper.text()).toContain('Publish nightly digest');
  });

  it('omits the workflow column when showWorkflow is off (per-workflow feed)', () => {
    const wrapper = mount(WorkflowRunRow, { props: { run: run({ workflow }) } });
    expect(wrapper.text()).not.toContain('Publish nightly digest');
  });

  it('degrades gracefully when the row carries no workflow', () => {
    const wrapper = mount(WorkflowRunRow, { props: { run: run(), showWorkflow: true } });
    // No workflow object → no column, and it must not throw.
    expect(wrapper.text()).toContain('Form submission');
  });
});
