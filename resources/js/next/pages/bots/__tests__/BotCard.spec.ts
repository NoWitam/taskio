// @vitest-environment happy-dom
// BotCard.spec — the APPEARANCE chip's four states.
//
// The chip reports OPERATIONAL READINESS, not "was this configured": the module toggle and an approved
// likeness are independent, and only both together mean "this bot will appear on its images". The two
// half-states are exactly the ones that surprise a user (an enabled module quietly produces
// character-less pictures; an approved likeness is quietly ignored while the module is off), so each is
// its own message with its own icon — never a colour difference alone.
import { describe, it, expect, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { setLocale } from '../../../app/i18n';
import { en } from '../../../app/i18n/en';
import BotCard from '../BotCard.vue';
import Badge from '../../../ui/primitives/Badge.vue';
import type { BotListItem } from '../types';

function bot(over: Partial<BotListItem> = {}): BotListItem {
  return {
    id: 'b1',
    name: 'Ada',
    status: 'active',
    description: null,
    icon: null,
    has_text_module: true,
    task_execution_enabled: false,
    visual_enabled: false,
    visual_has_image: false,
    is_owner: true,
    created_at: null,
    ...over,
  };
}

function mountCard(over: Partial<BotListItem> = {}) {
  return mount(BotCard, { props: { bot: bot(over) }, global: { stubs: { DropdownMenu: true } } });
}

/** The meta-footer badge that carries an Appearance state, if any. */
function appearanceBadge(wrapper: ReturnType<typeof mountCard>) {
  const labels = [
    en.bots.card.visualReady,
    en.bots.card.visualNoImage,
    en.bots.card.visualOff,
  ];
  return wrapper
    .findAllComponents(Badge)
    .find((b) => labels.includes(b.text().trim()));
}

describe('BotCard — the Appearance chip', () => {
  beforeEach(() => setLocale('en'));

  it('module ON + likeness approved → the bot will appear on its images', () => {
    const wrapper = mountCard({ visual_enabled: true, visual_has_image: true });
    const badge = appearanceBadge(wrapper)!;
    expect(badge.text()).toBe(en.bots.card.visualReady);
    expect(badge.props('variant')).toBe('primary');
    expect(badge.props('icon')).toBe('palette');
    expect(badge.attributes('title')).toBe(en.bots.card.visualReadyTitle);
    wrapper.unmount();
  });

  it('module ON + NO likeness → a warning: the images will have no character', () => {
    const wrapper = mountCard({ visual_enabled: true, visual_has_image: false });
    const badge = appearanceBadge(wrapper)!;
    expect(badge.text()).toBe(en.bots.card.visualNoImage);
    expect(badge.props('variant')).toBe('warning');
    expect(badge.props('icon')).toBe('alert-triangle');
    expect(badge.attributes('title')).toBe(en.bots.card.visualNoImageTitle);
    wrapper.unmount();
  });

  it('likeness approved but module OFF → neutral, and says the likeness is unused', () => {
    const wrapper = mountCard({ visual_enabled: false, visual_has_image: true });
    const badge = appearanceBadge(wrapper)!;
    expect(badge.text()).toBe(en.bots.card.visualOff);
    expect(badge.props('variant')).toBe('neutral');
    expect(badge.attributes('title')).toBe(en.bots.card.visualOffTitle);
    wrapper.unmount();
  });

  it('neither → NO chip (the module was simply never touched)', () => {
    const wrapper = mountCard();
    expect(appearanceBadge(wrapper)).toBeUndefined();
    wrapper.unmount();
  });

  it('the retired "Visual · soon" chip is gone; Audio is still coming', () => {
    const wrapper = mountCard({ visual_enabled: true, visual_has_image: true });
    expect(wrapper.text()).not.toContain('Visual · soon');
    expect(wrapper.text()).toContain(en.bots.modules.audioSoon);
    wrapper.unmount();
  });
});
