// @vitest-environment happy-dom
// TextDiffView.spec — the COMPONENT around the pure diff (spec R20 / DC10).
//
// `textDiff.spec.ts` covers the maths; nothing covered the three props the composer leans on
// (`emptyLabel`, `maxHeight`, the coarse banner) or the accessibility gap that made the diff
// unreadable to a screen reader while it is the thing a user accepts content from.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { setLocale, translate } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import TextDiffView from '../TextDiffView.vue';

const t = translate;

describe('TextDiffView', () => {
  beforeEach(() => {
    installBrowserMocks();
    setLocale('en');
  });
  afterEach(() => {
    restoreBrowserMocks();
    document.body.innerHTML = '';
  });

  it('renders the neutral "no changes" copy when the two texts match', () => {
    const wrapper = mount(TextDiffView, { props: { old: 'same', current: 'same' } });
    expect(wrapper.text()).toContain(t('textDiff.noChanges'));
    wrapper.unmount();
  });

  it('lets a host say something more specific than "no changes"', () => {
    const wrapper = mount(TextDiffView, {
      props: { old: 'same', current: 'same', emptyLabel: 'Identical to the stored version' },
    });
    expect(wrapper.text()).toContain('Identical to the stored version');
    expect(wrapper.text()).not.toContain(t('textDiff.noChanges'));
    wrapper.unmount();
  });

  it('applies the host’s maxHeight to the scroll region', () => {
    const wrapper = mount(TextDiffView, { props: { old: 'a', current: 'b', maxHeight: '24rem' } });
    const region = wrapper.find('[role="group"]');
    expect(region.attributes('style')).toContain('max-height: 24rem');
    wrapper.unmount();
  });

  // --- DC10: the diff must be AUDIBLE, not only visible ---------------------

  it('prefixes every changed row with a visually-hidden "Added" / "Removed"', () => {
    const wrapper = mount(TextDiffView, { props: { old: 'alpha\nbeta', current: 'alpha\ngamma' } });

    const hidden = wrapper.findAll('.sr-only').map((n) => n.text());
    // Both directions are announced — without this, a listener hears the same words twice with no
    // way to tell which is the old text and which is the new.
    expect(hidden.some((text) => text.includes(t('textDiff.rowRemoved')))).toBe(true);
    expect(hidden.some((text) => text.includes(t('textDiff.rowAdded')))).toBe(true);
    wrapper.unmount();
  });

  it('does NOT prefix unchanged context rows', () => {
    const wrapper = mount(TextDiffView, { props: { old: 'keep\nbeta', current: 'keep\ngamma' } });

    // One removal + one addition; the untouched "keep" line gets nothing.
    expect(wrapper.findAll('.sr-only')).toHaveLength(2);
    wrapper.unmount();
  });

  it('keeps the +/− gutter hidden from assistive tech (the prefix carries it instead)', () => {
    const wrapper = mount(TextDiffView, { props: { old: 'a', current: 'b' } });

    const gutters = wrapper.findAll('[aria-hidden="true"]');
    expect(gutters.length).toBeGreaterThan(0);
    wrapper.unmount();
  });

  // --- The coarse fallback --------------------------------------------------

  it('announces the COARSE comparison rather than silently dropping to whole blocks', () => {
    // Past `MAX_LINES` the engine degrades to one del + one add. That is an honest bound, and the
    // component says so — otherwise a long document looks like a broken diff.
    const old = Array.from({ length: 3200 }, (_, i) => `old ${i}`).join('\n');
    const current = Array.from({ length: 3200 }, (_, i) => `new ${i}`).join('\n');

    const wrapper = mount(TextDiffView, { props: { old, current } });
    expect(wrapper.text()).toContain(t('textDiff.coarse'));
    wrapper.unmount();
  });

  it('does not show the coarse banner for an ordinary diff', () => {
    const wrapper = mount(TextDiffView, { props: { old: 'alpha', current: 'beta' } });
    expect(wrapper.text()).not.toContain(t('textDiff.coarse'));
    wrapper.unmount();
  });
});
