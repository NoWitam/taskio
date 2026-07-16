// @vitest-environment happy-dom
// CreatorBadge.spec.ts — the polymorphic "creator" identity render contract
// (Phase 3). One switch drives every detail/identity site, so this locks the four
// branches down: a user/bot renders its NAME, a workflow_run renders the localized
// "Automatyzacja: <workflow>" automation label (generic when the label is null),
// and a null/omitted creator degrades to the "System" placeholder — never blank.
//
// The locale is pinned to PL so the assertions also prove the `common.creator.*`
// keys exist in the pl catalog (the helper only carries English inline defaults).
// The api client is mocked so `setLocale`'s best-effort server sync never fires.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('../../../app/lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}));

import CreatorBadge from '../CreatorBadge.vue';
import type { Creator } from '../creator';
import { setLocale } from '../../../app/i18n';

const USER: Creator = { type: 'user', id: 'u1', name: 'Ada Lovelace', email: 'ada@example.com', avatar: null };
const BOT: Creator = { type: 'bot', id: 'b1', name: 'Draft Bot', avatar: null };
const RUN: Creator = { type: 'workflow_run', id: 'r1', run_id: 'r1', label: 'Nightly sync' };
const RUN_NO_LABEL: Creator = { type: 'workflow_run', id: 'r2', run_id: 'r2', label: null };

describe('CreatorBadge', () => {
  beforeEach(() => setLocale('pl'));
  afterEach(() => setLocale('en'));

  it('renders a user creator by name', () => {
    const wrapper = mount(CreatorBadge, { props: { creator: USER } });
    expect(wrapper.text()).toContain('Ada Lovelace');
  });

  it('renders a bot creator by name (sparkles glyph, not a user avatar)', () => {
    const wrapper = mount(CreatorBadge, { props: { creator: BOT } });
    expect(wrapper.text()).toContain('Draft Bot');
  });

  it('renders a workflow_run creator as the localized automation label', () => {
    const wrapper = mount(CreatorBadge, { props: { creator: RUN } });
    expect(wrapper.text()).toContain('Automatyzacja: Nightly sync');
  });

  it('falls back to the generic automation label when the run has no workflow name', () => {
    const wrapper = mount(CreatorBadge, { props: { creator: RUN_NO_LABEL } });
    expect(wrapper.text()).toContain('Automatyzacja');
    expect(wrapper.text()).not.toContain(':');
  });

  it('renders the System placeholder for a null creator — never blank', () => {
    const wrapper = mount(CreatorBadge, { props: { creator: null } });
    expect(wrapper.text().trim()).toBe('System');
  });

  it('honours a custom fallback for a null creator (e.g. anonymous submissions)', () => {
    const wrapper = mount(CreatorBadge, { props: { creator: null, fallback: 'Anonim' } });
    expect(wrapper.text().trim()).toBe('Anonim');
  });

  it('glyphOnly hides the label text', () => {
    const wrapper = mount(CreatorBadge, { props: { creator: USER, glyphOnly: true } });
    expect(wrapper.text()).not.toContain('Ada Lovelace');
  });
});
