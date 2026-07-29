// @vitest-environment happy-dom
// SessionBudgetBanner.spec — the blocked panel (R2 sub-stage 4). Owner sees a "raise the limit" CTA (emits
// `manage`); a member sees the read-only "contact your owner" instruction (NO owner control that would 403).
// The owner affordance is gated on `canManage` (server-authoritative), and the reset date is shown.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { setLocale } from '../../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../../__tests__/helpers/dom';
import SessionBudgetBanner from '../SessionBudgetBanner.vue';
import type { AiUsageSummary } from '../../../../app/stores/aiUsage';

function summary(overrides: Partial<AiUsageSummary> = {}): AiUsageSummary {
  return {
    currency: 'USD',
    estimated: true,
    cost_used: 10,
    cost_cap: 10,
    cost_remaining: 0,
    cap_source: 'workspace',
    warn_ratio: 0.8,
    warn_reached: true,
    blocked: true,
    period: { month: '2026-07', resets_at: '2026-08-01T00:00:00Z' },
    tokens_used: 0,
    per_channel: [],
    per_actor: [],
    can_manage: false,
    ...overrides,
  };
}

describe('SessionBudgetBanner', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('renders the blocked title + reset date', () => {
    const wrapper = mount(SessionBudgetBanner, { props: { summary: summary(), canManage: false } });
    expect(wrapper.text()).toContain('AI budget reached');
    expect(wrapper.text()).toContain('Resets on');
    expect(wrapper.find('[role="alert"]').exists()).toBe(true);
  });

  it('owner: shows a "raise the limit" CTA that emits manage', async () => {
    const wrapper = mount(SessionBudgetBanner, { props: { summary: summary(), canManage: true } });
    const cta = wrapper.find('button');
    expect(wrapper.text()).toContain('Raise the limit');
    await cta.trigger('click');
    expect(wrapper.emitted('manage')).toBeTruthy();
    wrapper.unmount();
  });

  it('member: shows the read-only "contact your owner" instruction, no owner CTA', () => {
    const wrapper = mount(SessionBudgetBanner, { props: { summary: summary(), canManage: false } });
    expect(wrapper.text()).toContain('Ask your workspace owner');
    expect(wrapper.text()).not.toContain('Raise the limit');
    wrapper.unmount();
  });
});
