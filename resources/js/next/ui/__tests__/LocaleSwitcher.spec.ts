// @vitest-environment happy-dom
// LocaleSwitcher.spec — the click the whole locale fix rests on: pressing the segment that is ALREADY
// lit.
//
// A user whose account was stamped `locale = 'en'` by an old migration default, but whose browser
// renders Polish, sees two languages at once. The stored column outranks the header the client sends,
// so the ONLY lever that can correct the account is pressing "PL" — the segment already highlighted.
// `setLocale()` in `app/i18n` had its "already selected, do nothing" early return removed for exactly
// that reason, and that removal is pinned at module level in `app/i18n/__tests__/i18n.spec.ts`.
//
// What is NOT pinned there is the component. A short-circuit reintroduced HERE (`@click="loc !==
// currentLocale && setLocale(loc)"`, a `:disabled` on the active segment) would restore the whole bug
// with the module-level test still green. This spec is that guard: the switcher hands every press to
// i18n and decides nothing itself.
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils';
import LocaleSwitcher from '../LocaleSwitcher.vue';
import { setLocale } from '../../app/i18n';
import { en } from '../../app/i18n/en';

// The real i18n singleton drives what the component renders (active locale, translated labels); only
// the switch itself is replaced, so a press is observable without asserting on network or storage.
// It has to be swapped inside `useI18n()` — the composable closes over the module-local binding, so
// replacing the bare `setLocale` export would leave the component calling the original.
const { setLocaleSpy } = vi.hoisted(() => ({ setLocaleSpy: vi.fn() }));

vi.mock('../../app/i18n', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../app/i18n')>();

  return {
    ...actual,
    useI18n: () => ({ ...actual.useI18n(), setLocale: setLocaleSpy }),
  };
});

function segments(wrapper: VueWrapper): DOMWrapper<HTMLButtonElement>[] {
  return wrapper.findAll<HTMLButtonElement>('button');
}

/** The lit segment — the one the user is told is already selected. */
function activeSegment(wrapper: VueWrapper): DOMWrapper<HTMLButtonElement> {
  const found = segments(wrapper).filter((b) => b.attributes('aria-pressed') === 'true');
  expect(found, 'exactly one segment must read as pressed').toHaveLength(1);

  return found[0];
}

function segmentLabelled(wrapper: VueWrapper, text: string): DOMWrapper<HTMLButtonElement> {
  const found = segments(wrapper).find((b) => b.text() === text);
  expect(found, `no segment labelled ${text}`).toBeTruthy();

  return found!;
}

beforeEach(() => {
  setLocaleSpy.mockClear();
});

describe('LocaleSwitcher', () => {
  it('renders one segment per available locale and marks the active one as pressed', () => {
    setLocale('pl');

    const wrapper = mount(LocaleSwitcher);

    expect(segments(wrapper)).toHaveLength(2);
    expect(segmentLabelled(wrapper, 'PL').attributes('aria-pressed')).toBe('true');
    expect(segmentLabelled(wrapper, 'EN').attributes('aria-pressed')).toBe('false');
  });

  /**
   * THE REGRESSION GUARD. Pressing the lit segment must reach i18n — that call is a user asking the
   * server to re-record a language it has wrong.
   */
  it('calls setLocale when the user presses the segment that is already active', async () => {
    setLocale('pl');
    const wrapper = mount(LocaleSwitcher);
    setLocaleSpy.mockClear();

    await activeSegment(wrapper).trigger('click');

    expect(setLocaleSpy).toHaveBeenCalledWith('pl');
  });

  it('does so for the other locale too — the bug is not specific to Polish', async () => {
    setLocale('en');
    const wrapper = mount(LocaleSwitcher);
    setLocaleSpy.mockClear();

    await activeSegment(wrapper).trigger('click');

    expect(setLocaleSpy).toHaveBeenCalledWith('en');
  });

  it('hands over every press of the active segment, not just the first', async () => {
    // A user who does not see an immediate change presses again; each press is a fresh assertion to
    // the server, so the component must not remember that it already sent one.
    setLocale('pl');
    const wrapper = mount(LocaleSwitcher);
    setLocaleSpy.mockClear();

    await activeSegment(wrapper).trigger('click');
    await activeSegment(wrapper).trigger('click');
    await activeSegment(wrapper).trigger('click');

    expect(setLocaleSpy).toHaveBeenCalledTimes(3);
    expect(setLocaleSpy.mock.calls).toEqual([['pl'], ['pl'], ['pl']]);
  });

  it('leaves the active segment pressable — no disabled/aria-disabled on the current choice', () => {
    setLocale('pl');

    const active = activeSegment(mount(LocaleSwitcher));

    expect(active.attributes('disabled')).toBeUndefined();
    expect(active.attributes('aria-disabled')).toBeUndefined();
  });

  it('switches language when the inactive segment is pressed', async () => {
    setLocale('pl');
    const wrapper = mount(LocaleSwitcher);
    setLocaleSpy.mockClear();

    await segmentLabelled(wrapper, 'EN').trigger('click');

    expect(setLocaleSpy).toHaveBeenCalledWith('en');
  });

  it('labels the group and both segments from the catalog, in the active language', () => {
    setLocale('en');

    const wrapper = mount(LocaleSwitcher);

    const group = wrapper.find('[role="group"]');
    expect(group.attributes('aria-label')).toBe(en.language.label);
    expect(segmentLabelled(wrapper, 'PL').attributes('aria-label')).toBe(en.language.polish);
    expect(segmentLabelled(wrapper, 'EN').attributes('aria-label')).toBe(en.language.english);
  });
});
