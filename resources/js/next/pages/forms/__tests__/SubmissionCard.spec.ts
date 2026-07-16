// @vitest-environment happy-dom
// SubmissionCard.spec — the additive `selectable` contract (workflows run-now picker).
//
// Default mode is unchanged: the card is clickable (opens detail) AND carries a kebab
// action menu. `selectable` mode turns the WHOLE card into a pick affordance — the
// stretched action is a real, keyboard-activatable <button> that emits `select` with
// the full submission, and the kebab is suppressed. i18n renders via setLocale('en').
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import SubmissionCard from '../SubmissionCard.vue';
import { setLocale } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import type { FormSubmission } from '../types';

function makeSubmission(overrides: Partial<FormSubmission> = {}): FormSubmission {
  return {
    id: 'sub-1',
    form_id: 'form-1',
    data: {},
    source: 'form',
    form_content_version_id: null,
    indexed_at: null,
    approved_at: '2026-07-10T09:00:00Z',
    is_approved: true,
    can_be_edited: false,
    creator: null,
    created_at: '2026-07-10T09:00:00Z',
    updated_at: null,
    ...overrides,
  };
}

function mountCard(props: Record<string, unknown> = {}) {
  return mount(SubmissionCard, {
    attachTo: document.body,
    props: { submission: makeSubmission(), ...props },
  });
}

const KEBAB = 'button[aria-label="Submission actions"]';

beforeEach(() => {
  installBrowserMocks();
  setLocale('en');
});

afterEach(() => {
  restoreBrowserMocks();
  document.body.innerHTML = '';
});

describe('SubmissionCard — default mode (unchanged)', () => {
  it('renders the kebab action menu and emits select on card click', async () => {
    const wrapper = mountCard();

    expect(wrapper.find(KEBAB).exists()).toBe(true);

    await wrapper.find('.next-entity-card__action').trigger('click');
    expect(wrapper.emitted('select')?.[0]?.[0]).toMatchObject({ id: 'sub-1' });
  });
});

describe('SubmissionCard — selectable mode', () => {
  it('hides the kebab and turns the whole card into a select button', () => {
    const wrapper = mountCard({ selectable: true });

    // Kebab suppressed.
    expect(wrapper.find(KEBAB).exists()).toBe(false);

    // The whole-card action is a real <button> (role + keyboard-activatable natively).
    const action = wrapper.find('.next-entity-card__action');
    expect(action.exists()).toBe(true);
    expect(action.element.tagName).toBe('BUTTON');
  });

  it('emits select with the full submission when activated', async () => {
    const wrapper = mountCard({ selectable: true, submission: makeSubmission({ id: 'sub-9' }) });

    await wrapper.find('.next-entity-card__action').trigger('click');
    const payload = wrapper.emitted('select')?.[0]?.[0] as FormSubmission;
    expect(payload.id).toBe('sub-9');
  });
});
