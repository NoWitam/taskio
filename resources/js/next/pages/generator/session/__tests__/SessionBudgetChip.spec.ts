// @vitest-environment happy-dom
// SessionBudgetChip.spec — the compact inline budget signal (R2 sub-stage 4). HIDDEN when uncapped or below
// the warn threshold; amber "N% of budget" once warn_reached; red "Budget reached" when blocked. Never
// color-only — the wallet glyph + text carry it.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { setLocale } from '../../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../../__tests__/helpers/dom';
import SessionBudgetChip from '../SessionBudgetChip.vue';
import type { AiUsageSummary } from '../../../../app/stores/aiUsage';

function summary(overrides: Partial<AiUsageSummary> = {}): AiUsageSummary {
  return {
    currency: 'USD',
    estimated: true,
    cost_used: 4,
    cost_cap: 10,
    cost_remaining: 6,
    cap_source: 'workspace',
    warn_ratio: 0.8,
    warn_reached: false,
    blocked: false,
    period: { month: '2026-07', resets_at: '2026-08-01T00:00:00Z' },
    tokens_used: 0,
    per_channel: [],
    per_actor: [],
    can_manage: false,
    ...overrides,
  };
}

describe('SessionBudgetChip', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('renders nothing when summary is null', () => {
    const wrapper = mount(SessionBudgetChip, { props: { summary: null } });
    expect(wrapper.text()).toBe('');
  });

  it('renders nothing when uncapped', () => {
    const wrapper = mount(SessionBudgetChip, {
      props: { summary: summary({ cap_source: 'unlimited', cost_cap: 0 }) },
    });
    expect(wrapper.text()).toBe('');
  });

  it('is hidden below the warn threshold (ok state)', () => {
    const wrapper = mount(SessionBudgetChip, { props: { summary: summary({ cost_used: 4 }) } });
    expect(wrapper.text()).toBe('');
  });

  it('shows an amber "N% of budget" when warn_reached', () => {
    const wrapper = mount(SessionBudgetChip, {
      props: { summary: summary({ cost_used: 8, warn_reached: true }) },
    });
    expect(wrapper.text()).toContain('80% of budget');
  });

  it('shows "Budget reached" when blocked', () => {
    const wrapper = mount(SessionBudgetChip, {
      props: { summary: summary({ cost_used: 10, warn_reached: true, blocked: true }) },
    });
    expect(wrapper.text()).toContain('Budget reached');
  });
});
