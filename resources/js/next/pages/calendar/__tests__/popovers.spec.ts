// @vitest-environment happy-dom
// popovers.spec — the two read-only overlays: the DAY sheet and the OCCURRENCE preview.
//
// (Both are `Modal`s rather than anchored `Popover`s — see the components' own docblocks: the
// design system's Popover wraps its trigger, and wrapping a `gridcell` would break the one
// place on this screen where the ARIA roles are load-bearing. The spec's file names are kept.)
//
// TWO THINGS ARE TESTED HERE THAT NOTHING ELSE CAN REACH:
//
//   FOCUS COMES BACK. These overlays are the ONLY keyboard route to an individual chip (the
//   chips are deliberately not tab stops). If focus does not return to the trigger on close, a
//   keyboard user is dropped at the top of the document and has to walk the whole page back to
//   the day they were on — every time. It works today because `useFocusTrap` remembers the
//   previously focused element; that is machinery nobody looks at, so it is pinned rather than
//   trusted.
//
//   AN UNKNOWN SUBJECT TYPE PRODUCES NO BUTTON AND NO ERROR. R4 Publishing will add a morph
//   alias this build has never seen. The preview must still render everything it CAN say, and
//   must not offer an "Open" that goes nowhere. This is the same promise as the source filter
//   built from `meta.sources`, at the other end of the interaction.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

import DayPopover from '../DayPopover.vue';
import OccurrencePopover from '../OccurrencePopover.vue';
import { setLocale } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import type { CalendarOccurrence } from '../types';

const TIMEZONE = 'Europe/Warsaw';

function occurrence(over: Partial<CalendarOccurrence> = {}): CalendarOccurrence {
  return {
    id: 'task:1',
    source: 'task',
    editable: false,
    all_day: true,
    start_date: '2026-08-10',
    starts_at: null,
    ends_at: null,
    title: 'Ship the thing',
    color: 'danger',
    badge: { label: 'In progress', color: 'warning' },
    dense: false,
    cadence_label: null,
    subject: { type: 'task', id: 'task-1' },
    ...over,
  } as CalendarOccurrence;
}

/** The overlay is teleported to <body>. */
function overlay(): HTMLElement {
  const el = document.querySelector<HTMLElement>('[role="dialog"]');
  if (!el) throw new Error('the overlay did not render');
  return el;
}

function buttons(): HTMLButtonElement[] {
  return Array.from(overlay().querySelectorAll('button'));
}

function buttonLabelled(label: string): HTMLButtonElement | null {
  return buttons().find((b) => (b.textContent ?? '').trim() === label) ?? null;
}

beforeEach(() => {
  installBrowserMocks();
  setLocale('en');
});

afterEach(() => {
  restoreBrowserMocks();
  document.body.innerHTML = '';
});

describe('OccurrencePopover — what it can and cannot say', () => {
  function mountPreview(over: Partial<CalendarOccurrence> = {}, open = true) {
    return mount(OccurrencePopover, {
      attachTo: document.body,
      props: {
        open,
        occurrence: occurrence(over),
        timezone: TIMEZONE,
        locale: 'en',
        sourceLabel: 'Task deadlines',
      },
    });
  }

  it('is built ENTIRELY from the occurrence — title, badge, when, source', async () => {
    const wrapper = mountPreview();
    await flushPromises();

    const text = overlay().textContent ?? '';
    expect(text).toContain('Ship the thing');
    // Server prose, rendered verbatim.
    expect(text).toContain('In progress');
    expect(text).toContain('all day');
    expect(text).toContain('Task deadlines');
    wrapper.unmount();
  });

  it('never prints a zone beside a DAY — a day does not have one', async () => {
    const wrapper = mountPreview();
    await flushPromises();

    expect(overlay().textContent ?? '').not.toContain(TIMEZONE);
    expect(overlay().textContent ?? '').not.toMatch(/\d{2}:\d{2}/);
    wrapper.unmount();
  });

  it('prints the zone AND the wall clock for a moment', async () => {
    const wrapper = mountPreview({
      all_day: false,
      start_date: null,
      starts_at: '2026-08-10T07:00:00+00:00',
      ends_at: '2026-08-10T08:30:00+00:00',
      source: 'workflow_run',
      subject: { type: 'workflow_run', id: 'run-1' },
    });
    await flushPromises();

    const text = overlay().textContent ?? '';
    expect(text).toContain('09:00'); // +02:00 in August
    expect(text).toContain('10:30');
    expect(text).toContain(TIMEZONE);
    wrapper.unmount();
  });

  it('says "from" rather than a range when the end is genuinely unknown', async () => {
    const wrapper = mountPreview({
      all_day: false,
      start_date: null,
      starts_at: '2026-08-10T07:00:00+00:00',
      ends_at: null,
      source: 'workflow_run',
      subject: { type: 'workflow_run', id: 'run-1' },
    });
    await flushPromises();

    expect(overlay().textContent ?? '').toMatch(/from 09:00/i);
    wrapper.unmount();
  });

  it('offers "Open" for a subject it can route to, and emits the route', async () => {
    const wrapper = mountPreview();
    await flushPromises();

    const open = buttonLabelled('Open');
    expect(open).not.toBeNull();

    open!.click();
    await flushPromises();

    expect(wrapper.emitted('open')?.[0][0]).toMatchObject({ path: '/tasks', query: { task: 'task-1' } });
    wrapper.unmount();
  });

  /**
   * THE R4 PROMISE, at the click end. A fifth source will hand this screen an alias it has
   * never heard of; the preview must degrade to "everything I can say" rather than to a dead
   * link or a blank overlay.
   */
  it('renders normally for an UNKNOWN subject type and offers NO button', async () => {
    const wrapper = mountPreview({
      source: 'publication',
      subject: { type: 'publication', id: 'pub-1' },
      title: 'A post goes out',
    });
    await flushPromises();

    expect(overlay().textContent ?? '').toContain('A post goes out');
    expect(buttonLabelled('Open')).toBeNull();
    expect(buttonLabelled('Open the list')).toBeNull();
    wrapper.unmount();
  });

  it('returns focus to whatever opened it', async () => {
    const trigger = document.createElement('button');
    trigger.textContent = 'chip';
    document.body.appendChild(trigger);
    trigger.focus();
    expect(document.activeElement).toBe(trigger);

    const wrapper = mountPreview({}, false);
    await flushPromises();

    await wrapper.setProps({ open: true });
    await flushPromises();
    expect(overlay().contains(document.activeElement)).toBe(true);

    await wrapper.setProps({ open: false });
    await flushPromises();

    expect(document.activeElement).toBe(trigger);
    wrapper.unmount();
  });
});

describe('DayPopover — one day, unfolded', () => {
  function mountDay(occurrences: CalendarOccurrence[], open = true) {
    return mount(DayPopover, {
      attachTo: document.body,
      props: {
        open,
        iso: '2026-08-10',
        occurrences,
        timezone: TIMEZONE,
        locale: 'en',
        sourceLabelOf: (id: string) => `Source ${id}`,
      },
    });
  }

  /**
   * The day sheet is the surface that shows the WHOLE series — it is where "+N more" and a
   * folded dense chip both lead. Folding again here would leave the series with nowhere at all
   * to be read.
   */
  it('lists every occurrence UNFOLDED, including a dense series', async () => {
    const series = Array.from({ length: 8 }, (_, i) =>
      occurrence({
        id: `s${i}`,
        source: 'workflow_schedule',
        all_day: false,
        start_date: null,
        starts_at: `2026-08-10T0${i}:00:00+00:00`,
        dense: true,
        subject: { type: 'workflow', id: 'wf-1' },
        title: `Fire ${i}`,
      }),
    );

    const wrapper = mountDay(series);
    await flushPromises();

    expect(overlay().querySelectorAll('.next-occurrence-chip')).toHaveLength(8);
    expect(overlay().textContent ?? '').toContain('8');
    wrapper.unmount();
  });

  it('says the day is empty rather than showing an empty box, and still offers to create', async () => {
    const wrapper = mountDay([]);
    await flushPromises();

    expect(overlay().textContent ?? '').toContain('Nothing on this day');
    expect(buttons().some((b) => (b.textContent ?? '').includes('Add an event on this day'))).toBe(true);
    wrapper.unmount();
  });

  it('is the keyboard route to a single chip — every row is a real button that emits', async () => {
    const wrapper = mountDay([occurrence({ id: 'one', title: 'Ship the thing' })]);
    await flushPromises();

    const chip = overlay().querySelector<HTMLButtonElement>('.next-occurrence-chip');
    expect(chip).not.toBeNull();
    chip!.click();
    await flushPromises();

    expect((wrapper.emitted('select')?.[0]?.[0] as CalendarOccurrence).id).toBe('one');
    wrapper.unmount();
  });

  it('returns focus to whatever opened it', async () => {
    const trigger = document.createElement('button');
    trigger.textContent = '+3 more';
    document.body.appendChild(trigger);
    trigger.focus();

    const wrapper = mountDay([occurrence()], false);
    await flushPromises();

    await wrapper.setProps({ open: true });
    await flushPromises();
    expect(overlay().contains(document.activeElement)).toBe(true);

    await wrapper.setProps({ open: false });
    await flushPromises();

    expect(document.activeElement).toBe(trigger);
    wrapper.unmount();
  });
});
