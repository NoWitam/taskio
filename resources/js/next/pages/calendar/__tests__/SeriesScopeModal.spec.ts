// @vitest-environment happy-dom
// SeriesScopeModal.spec — the dialog that makes "edit this Tuesday" stop meaning "edit every
// Tuesday".
//
// FIVE PROPERTIES, and each of them is the difference between an informed choice and a
// destructive one made by reflex:
//
//   1. THE CONFIRM BUTTON NAMES THE CHOICE. Three operations behind one word ("Save") give a
//      voice-control user nothing to say and a reader nothing to check before committing.
//   2. THE NARROWEST SCOPE IS PRE-SELECTED. A reflexive Enter must do the SMALLEST possible
//      damage; the button label is what stops that default from being a hidden one.
//   3. EVERY OPTION SAYS WHAT HAPPENS TO THE PAST. That is the only axis on which the three
//      choices differ, and nothing else on screen shows it.
//   4. THE FIRST-OCCURRENCE NOTE IS ADDED ONLY WHERE IT IS PROVABLE. The server decides
//      whether a split leaves anything behind by PROJECTING the series, which this client
//      cannot reproduce — so the only claim made is the one direction that is certain.
//   5. A SERVER REFUSAL STAYS INSIDE THE DIALOG. The realistic one names ANOTHER OPTION OF
//      THIS SAME DIALOG as the remedy, so the dialog stays open, the choice stays selected,
//      and the other options stay live.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';

import SeriesScopeModal from '../SeriesScopeModal.vue';
import { setLocale } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

function mountModal(props: Record<string, unknown> = {}) {
  return mount(SeriesScopeModal, {
    attachTo: document.body,
    props: {
      open: true,
      mode: 'edit',
      occurrenceDate: '2026-09-14',
      firstOccurrence: false,
      locale: 'en',
      busy: false,
      error: null,
      ...props,
    },
  });
}

function panel(): HTMLElement {
  const el = document.querySelector<HTMLElement>('.next-modal');
  if (!el) throw new Error('the modal panel did not render');
  return el;
}

function text(): string {
  return panel().textContent ?? '';
}

function radio(value: string): HTMLInputElement {
  const el = panel().querySelector<HTMLInputElement>(`[data-radio-value="${value}"]`);
  if (!el) throw new Error(`no option for scope "${value}"`);
  return el;
}

/** The footer's primary button — the one whose label has to name the choice. */
function confirmButton(): HTMLButtonElement {
  const buttons = Array.from(panel().querySelectorAll('footer button'));
  const last = buttons[buttons.length - 1] as HTMLButtonElement | undefined;
  if (!last) throw new Error('no confirm button');
  return last;
}

function choose(value: string): void {
  radio(value).click();
}

beforeEach(() => {
  installBrowserMocks();
  setLocale('en');
});

afterEach(() => {
  restoreBrowserMocks();
  setLocale('en');
  document.body.innerHTML = '';
});

describe('SeriesScopeModal — editing', () => {
  /** Property 2. */
  it('opens on the NARROWEST scope', async () => {
    const wrapper = mountModal();
    await wrapper.vm.$nextTick();

    expect(radio('occurrence').checked).toBe(true);
    expect(radio('series').checked).toBe(false);
    wrapper.unmount();
  });

  /** Property 1. */
  it('renames the confirm button as the choice changes', async () => {
    const wrapper = mountModal();
    await wrapper.vm.$nextTick();
    expect(confirmButton().textContent?.trim()).toBe('Edit this occurrence');

    choose('following');
    await wrapper.vm.$nextTick();
    // The date is in the label, so "from when" is answered without reading the option again.
    expect(confirmButton().textContent).toContain('Edit from');
    expect(confirmButton().textContent).toContain('September');

    choose('series');
    await wrapper.vm.$nextTick();
    expect(confirmButton().textContent?.trim()).toBe('Edit the whole series');
    wrapper.unmount();
  });

  /** Property 3. */
  it('says what happens to the past on every option', async () => {
    const wrapper = mountModal();
    await wrapper.vm.$nextTick();

    expect(text()).toContain('Earlier occurrences stay as they were');
    expect(text()).toContain('including the ones that already happened');
    expect(text()).toContain('The rest of the series is unchanged');
    wrapper.unmount();
  });

  it('names the occurrence above the options, as real text rather than a tooltip', async () => {
    const wrapper = mountModal();
    await wrapper.vm.$nextTick();

    expect(text()).toContain('Selected occurrence:');
    expect(text()).toContain('September');
    wrapper.unmount();
  });

  /** Property 4. */
  it('adds the whole-series warning to "following" ONLY at the first occurrence', async () => {
    const later = mountModal();
    await later.vm.$nextTick();
    expect(text()).not.toContain('This is the first occurrence of the series');
    later.unmount();
    document.body.innerHTML = '';

    const first = mountModal({ firstOccurrence: true });
    await first.vm.$nextTick();
    expect(text()).toContain('This is the first occurrence of the series');
    first.unmount();
  });

  it('emits the chosen scope, and nothing until it is confirmed', async () => {
    const wrapper = mountModal();
    await wrapper.vm.$nextTick();

    choose('following');
    await wrapper.vm.$nextTick();
    expect(wrapper.emitted('confirm')).toBeUndefined();

    confirmButton().click();
    await wrapper.vm.$nextTick();
    expect(wrapper.emitted('confirm')?.[0]).toEqual(['following']);
    wrapper.unmount();
  });
});

describe('SeriesScopeModal — deleting', () => {
  it('asks a different question, and its confirm button is destructive', async () => {
    const wrapper = mountModal({ mode: 'delete' });
    await wrapper.vm.$nextTick();

    expect(text()).toContain('What should be deleted?');
    expect(confirmButton().textContent?.trim()).toBe('Delete this occurrence');
    wrapper.unmount();
  });

  /**
   * The irreversibility sentence is on the WHOLE-SERIES option only. On all three it would be
   * noise instead of a warning, and the other two really are not the same loss.
   */
  it('warns about permanence only where the loss is largest', async () => {
    const wrapper = mountModal({ mode: 'delete' });
    await wrapper.vm.$nextTick();
    const body = text();

    expect(body).toContain('This cannot be undone from the interface.');
    expect(body.indexOf('This cannot be undone')).toBeGreaterThan(body.indexOf('The other occurrences stay'));
    wrapper.unmount();
  });

  /** Property 5. */
  it('renders a server refusal under the chosen option, leaving the others usable', async () => {
    const message = 'This series already has the maximum number of skipped days (50). Split the series instead.';
    const wrapper = mountModal({ mode: 'delete', error: message });
    await wrapper.vm.$nextTick();

    const alert = panel().querySelector('[role="alert"]');
    expect(alert?.textContent).toContain(message);
    // The choice that failed is still selected — resetting it would look like nothing happened.
    expect(radio('occurrence').checked).toBe(true);
    // …and the remedy the message names is one click away.
    expect(radio('following').disabled).toBe(false);
    expect(radio('series').disabled).toBe(false);
    wrapper.unmount();
  });

  it('does not fire twice while a request is in flight', async () => {
    const wrapper = mountModal({ mode: 'delete', busy: true });
    await wrapper.vm.$nextTick();

    confirmButton().click();
    confirmButton().click();
    await wrapper.vm.$nextTick();
    expect(wrapper.emitted('confirm')).toBeUndefined();
    wrapper.unmount();
  });

  /**
   * The options stay in the tree while a delete runs — deliberately NOT disabled. The failure
   * that actually happens points at one of them, so taking them out of reach for the duration
   * would hide the remedy at exactly the moment it becomes relevant.
   */
  it('keeps the options reachable while a request is in flight', async () => {
    const wrapper = mountModal({ mode: 'delete', busy: true });
    await wrapper.vm.$nextTick();

    expect(radio('following').disabled).toBe(false);
    wrapper.unmount();
  });
});

/**
 * NO DAY NAMED — THE STATE A DEEP LINK ARRIVES IN.
 *
 * `?event=<id>` with no square behind it (a link, a search result, a series with nothing in the
 * window on screen) leaves `occurrenceDate` null. Both scoped writes are NAMED BY that day —
 * the server requires `occurrence_date` beside `scope != series` — so with none there is
 * nothing for them to be about: a scoped delete is a guaranteed 422, and a scoped edit degrades
 * back to the whole series anyway.
 *
 * WHAT MAKES IT A DEFECT RATHER THAN A ROUGH EDGE is that the impossible option was the
 * PRE-SELECTED one. A reader reached it without choosing it, and one reflexive Enter on a delete
 * spent a round trip on a refusal the dialog could have declined to ask for.
 *
 * BOTH DIRECTIONS ARE ASSERTED HERE ON PURPOSE. A guard that disables the two options whenever
 * it feels like it is a worse bug than the one it replaces — it would take "edit this Tuesday"
 * away from every user who clicked a Tuesday — so the ordinary case is pinned in the same block,
 * against the same helpers, rather than left to the specs above.
 */
describe('SeriesScopeModal — opened without a day', () => {
  it('disables both scoped options and opens on the only one that can act', async () => {
    const wrapper = mountModal({ occurrenceDate: null });
    await wrapper.vm.$nextTick();

    expect(radio('occurrence').disabled).toBe(true);
    expect(radio('following').disabled).toBe(true);
    // The one scope that names no day and needs none.
    expect(radio('series').disabled).toBe(false);
    expect(radio('series').checked).toBe(true);
    expect(radio('occurrence').checked).toBe(false);

    // The confirm button still NAMES the choice, and now it names one that can happen.
    expect(confirmButton().textContent?.trim()).toBe('Edit the whole series');
    wrapper.unmount();
  });

  /** The regression in the other direction — the case that must keep working. */
  it('leaves every option live, and the narrowest pre-selected, when a day IS named', async () => {
    const wrapper = mountModal({ occurrenceDate: '2026-09-14' });
    await wrapper.vm.$nextTick();

    expect(radio('occurrence').disabled).toBe(false);
    expect(radio('following').disabled).toBe(false);
    expect(radio('series').disabled).toBe(false);
    expect(radio('occurrence').checked).toBe(true);
    expect(confirmButton().textContent?.trim()).toBe('Edit this occurrence');
    wrapper.unmount();
  });

  /**
   * The hints are built around `{date}`. With nothing to interpolate, `deleteHint` rendered as
   * " will disappear from the series." — a sentence opening on a space, with no subject. What
   * replaces it says WHY the option is off and WHERE the day comes from.
   */
  it('replaces the subject-less {date} sentence with one that explains the absence', async () => {
    const wrapper = mountModal({ mode: 'delete', occurrenceDate: null });
    await wrapper.vm.$nextTick();

    expect(text()).not.toContain('will disappear from the series');
    expect(text()).toContain('opened without naming a day');
    expect(text()).toContain('Click the day on the grid');
    // The context line names an occurrence; there is none to name.
    expect(text()).not.toContain('Selected occurrence:');
    // The option that CAN act keeps its own sentence — this is a swap, not a blanket.
    expect(text()).toContain('together with its whole history');
    wrapper.unmount();
  });

  it('cannot be talked into a scoped answer by clicking', async () => {
    const wrapper = mountModal({ mode: 'delete', occurrenceDate: null });
    await wrapper.vm.$nextTick();

    choose('occurrence');
    await wrapper.vm.$nextTick();
    expect(radio('series').checked).toBe(true);

    confirmButton().click();
    await wrapper.vm.$nextTick();
    // The only scope that could be emitted is the only one that can succeed.
    expect(wrapper.emitted('confirm')?.[0]).toEqual(['series']);
    wrapper.unmount();
  });

  /**
   * THE OTHER ROUTE INTO THE SAME UNUSABLE STATE. `RadioGroup` only knows a GROUP-wide
   * `disabled`, so its arrow-key navigation moves the SELECTION before anything asks whether
   * the target radio can be chosen — a keyboard user would land on "Only this occurrence" with
   * no click involved. Pointer-only guarding would have left that door open.
   */
  it('cannot be talked into one with the arrow keys either', async () => {
    const wrapper = mountModal({ mode: 'delete', occurrenceDate: null });
    await wrapper.vm.$nextTick();

    for (const key of ['ArrowUp', 'ArrowDown', 'Home', 'End']) {
      radio('series').dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true }));
      await wrapper.vm.$nextTick();
      expect(radio('series').checked).toBe(true);
    }
    wrapper.unmount();
  });

  /** The same keys must still MOVE when the options are real — the control test for the above. */
  it('still moves with the arrow keys when a day IS named', async () => {
    const wrapper = mountModal({ occurrenceDate: '2026-09-14' });
    await wrapper.vm.$nextTick();

    radio('occurrence').dispatchEvent(
      new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }),
    );
    await wrapper.vm.$nextTick();
    expect(radio('following').checked).toBe(true);
    wrapper.unmount();
  });

  it('translates the explanation too', async () => {
    setLocale('pl');
    const wrapper = mountModal({ occurrenceDate: null });
    await wrapper.vm.$nextTick();

    expect(text()).toContain('otwarto bez wskazania dnia');
    expect(confirmButton().textContent?.trim()).toBe('Edytuj całą serię');
    wrapper.unmount();
  });
});

describe('SeriesScopeModal — reopening', () => {
  it('forgets the previous answer', async () => {
    const wrapper = mountModal();
    await wrapper.vm.$nextTick();
    choose('series');
    await wrapper.vm.$nextTick();
    expect(radio('series').checked).toBe(true);

    await wrapper.setProps({ open: false });
    await wrapper.setProps({ open: true });
    await wrapper.vm.$nextTick();

    // A dialog that remembers eventually performs the wrong operation on somebody's reflex.
    expect(radio('occurrence').checked).toBe(true);
    wrapper.unmount();
  });
});

describe('SeriesScopeModal — Polish', () => {
  it('translates the whole dialog, including the button that names the choice', async () => {
    setLocale('pl');
    const wrapper = mountModal();
    await wrapper.vm.$nextTick();

    expect(text()).toContain('Co chcesz edytować?');
    expect(confirmButton().textContent?.trim()).toBe('Edytuj to wystąpienie');
    wrapper.unmount();
  });
});

// The dialog is a Modal, so Escape / scrim behaviour is the design system's and is covered by
// Modal's own spec. Asserting it again here would only give this file a way to fail for
// reasons that are not about scopes.
