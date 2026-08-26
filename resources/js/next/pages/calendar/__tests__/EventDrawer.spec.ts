// @vitest-environment happy-dom
// EventDrawer.spec — the Calendar's ONLY write surface.
//
// Everything asserted here is something the drawer decides ALONE, and every one of them fails
// silently when it is wrong — which is the reason each gets a test rather than a comment:
//
//   • 422 ROUTING. The server's messages are already translated; they belong under their own
//     fields, verbatim, with the caret moved to the first one. A toast instead would put a
//     field problem in a corner of the screen and leave the field looking fine.
//   • can_* GATING, NEVER is_owner. An event a workflow run created has `is_owner: false` for
//     everybody, including the workspace owner who is allowed to fix it. Gate on ownership and
//     the owner is locked out of their own workspace's automated events.
//   • THE DISCRIMINATOR SWITCH MUST NOT DESTROY WHAT WAS TYPED. Both time groups live in the
//     draft; only one is ever sent. Flip and flip back and the user's own hour comes back.
//   • EXACTLY ONE TIME GROUP ON THE WIRE. The other is FORBIDDEN (a 422), not ignored.
//   • DELETING IS CONFIRMED, and the copy promises nothing about undo (there is no restore
//     endpoint, only a soft delete).
//   • THERE IS NO COLOUR CONTROL. Colour on the calendar is a dictionary of MEANINGS — a task
//     deadline is coloured by priority, a run by its result — and an event that let a human
//     pick from the same six values made red mean "urgent", "failed" and nothing at all in
//     one grid. Asserted as an absence, in the form AND on the wire, because a removal is
//     exactly the kind of change a later edit puts back without noticing.
//
// The two date/time PICKERS are stubbed to plain inputs on purpose. Their parsing has its own
// specs; here they would only add a way for this file to fail for reasons that are not about
// the drawer.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { reactive } from 'vue';

vi.mock('../../../app/lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}));

// --- Store (the real `buildEventPayload` is kept: it is what assembles the wire body) --------
const fetchEvent = vi.fn();
const clearEvent = vi.fn();
const saveEvent = vi.fn();
const deleteEvent = vi.fn();
const storeMock = reactive({
  eventDetail: null as unknown,
  eventLoading: false,
  eventError: null as string | null,
  saving: false,
  // The WINDOW half of the store. The drawer reads it for exactly one sentence — "this series
  // has no occurrences in the month on screen" — which is only true when the `event` source
  // was actually asked AND answered.
  occurrences: [] as unknown[],
  loaded: false,
  currentQuery: null as { sources?: string[] } | null,
  unavailableSources: [] as { source: string; reason: string }[],
  fetchEvent,
  clearEvent,
  saveEvent,
  deleteEvent,
});
vi.mock('../../../app/stores/calendar', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../../app/stores/calendar')>();
  return { ...actual, useCalendarStore: () => storeMock };
});

const toast = { success: vi.fn(), danger: vi.fn(), info: vi.fn(), warning: vi.fn() };
vi.mock('../../../app/composables/useToast', () => ({ useToast: () => toast }));

const confirmMock = vi.fn();
vi.mock('../../../app/composables/useConfirm', () => ({ useConfirm: () => confirmMock }));

// --- Picker stubs -----------------------------------------------------------
// Plain inputs. The real pickers parse and format text and have their own specs; here they
// would only give this file ways to fail for reasons that are not about the drawer.
// (Inlined per factory: `vi.mock` is hoisted above every top-level binding.)
vi.mock('../../../ui/forms/DatePicker.vue', () => ({
  default: {
    name: 'DatePickerStub',
    props: ['modelValue', 'readonly', 'ariaLabel', 'locale'],
    emits: ['update:modelValue'],
    template:
      '<input :aria-label="ariaLabel" :value="modelValue ?? \'\'" @input="$emit(\'update:modelValue\', $event.target.value || null)" />',
  },
}));
vi.mock('../../../ui/forms/TimePicker.vue', () => ({
  default: {
    name: 'TimePickerStub',
    props: ['modelValue', 'readonly', 'ariaLabel', 'locale'],
    emits: ['update:modelValue'],
    template:
      '<input :aria-label="ariaLabel" :value="modelValue ?? \'\'" @input="$emit(\'update:modelValue\', $event.target.value || null)" />',
  },
}));

import EventDrawer from '../EventDrawer.vue';
import { setLocale } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import { RECURRENCE_COUNT_MAX } from '../recurrencePresets';
import type { CalendarEvent } from '../types';

const TIMEZONE = 'Europe/Warsaw';

function event(over: Partial<CalendarEvent> = {}): CalendarEvent {
  return {
    id: 'evt-1',
    title: 'Nagranie odcinka',
    description: 'Studio B',
    all_day: false,
    start_date: null,
    // 12:30Z = 14:30 in Europe/Warsaw, so every wall-clock assertion below is a real conversion.
    starts_at: '2026-08-10T12:30:00+00:00',
    ends_at: '2026-08-10T13:30:00+00:00',
    recurrence: null,
    recurrence_timezone: null,
    recurrence_label: null,
    subject: null,
    creator: null,
    is_owner: true,
    can_be_edited: true,
    can_be_deleted: true,
    created_at: '2026-08-01T10:00:00+00:00',
    updated_at: '2026-08-01T10:00:00+00:00',
    ...over,
  } as CalendarEvent;
}

function mountDrawer(props: Partial<Record<string, unknown>> = {}) {
  return mount(EventDrawer, {
    attachTo: document.body,
    props: {
      open: true,
      eventId: 'evt-1',
      mode: 'view',
      timezone: TIMEZONE,
      browserZone: null,
      locale: 'en',
      seedDate: null,
      ...props,
    },
  });
}

/** The drawer is teleported to <body>, so the panel is queried from the document. */
function panel(): HTMLElement {
  const el = document.querySelector<HTMLElement>('.next-drawer');
  if (!el) throw new Error('the drawer panel did not render');
  return el;
}

function text(): string {
  return panel().textContent ?? '';
}

function buttonLabelled(label: string): HTMLButtonElement | null {
  return (
    Array.from(panel().querySelectorAll('button')).find(
      (b) => (b.textContent ?? '').trim() === label,
    ) ?? null
  );
}

function inputLabelled(label: string): HTMLInputElement {
  const el = panel().querySelector<HTMLInputElement>(`[aria-label="${label}"]`);
  if (!el) throw new Error(`no control labelled "${label}"`);
  return el;
}

async function type(label: string, value: string): Promise<void> {
  const el = inputLabelled(label);
  el.value = value;
  el.dispatchEvent(new Event('input', { bubbles: true }));
  await flushPromises();
}

beforeEach(() => {
  installBrowserMocks();
  setLocale('en');
  storeMock.eventDetail = null;
  storeMock.eventLoading = false;
  storeMock.eventError = null;
  storeMock.saving = false;
  storeMock.occurrences = [];
  storeMock.loaded = false;
  storeMock.currentQuery = null;
  storeMock.unavailableSources = [];
  fetchEvent.mockReset().mockResolvedValue(null);
  clearEvent.mockReset();
  saveEvent.mockReset().mockResolvedValue(event());
  deleteEvent.mockReset().mockResolvedValue(undefined);
  confirmMock.mockReset().mockResolvedValue(true);
  toast.success.mockReset();
  toast.danger.mockReset();
});

afterEach(() => {
  restoreBrowserMocks();
  document.body.innerHTML = '';
});

describe('EventDrawer — view mode', () => {
  it('shows the moment in the WORKSPACE zone, and names that zone', async () => {
    storeMock.eventDetail = event();
    const wrapper = mountDrawer();
    await flushPromises();

    // 12:30Z rendered as the team's 14:30 — the whole reason `meta.timezone` exists.
    expect(text()).toContain('14:30');
    expect(text()).toContain('15:30');
    expect(text()).toContain(TIMEZONE);
    expect(text()).toContain('Studio B');
    wrapper.unmount();
  });

  it('renders an all-day event with NO time at all — not 00:00, not a dash', async () => {
    storeMock.eventDetail = event({
      all_day: true,
      start_date: '2026-08-12',
      starts_at: null,
      ends_at: null,
    });
    const wrapper = mountDrawer();
    await flushPromises();

    expect(text()).toContain('all day');
    expect(text()).not.toMatch(/\d{2}:\d{2}/);
    // No zone row either: a DAY does not have one, and printing it would imply otherwise.
    expect(text()).not.toContain(TIMEZONE);
    wrapper.unmount();
  });

  it('offers Edit and Delete when the POLICY allows them', async () => {
    storeMock.eventDetail = event();
    const wrapper = mountDrawer();
    await flushPromises();

    expect(buttonLabelled('Edit event')).not.toBeNull();
    expect(buttonLabelled('Delete')).not.toBeNull();
    wrapper.unmount();
  });

  /**
   * THE ONE THAT MATTERS. An event a workflow run created is `is_owner: false` for everybody —
   * there is no human author. The workspace owner may still edit it, and the server says so
   * through `can_be_edited`. Gating on ownership would hide the button from the one person who
   * can actually fix an automation's mistake.
   */
  it('gates on can_be_edited / can_be_deleted, NEVER on is_owner', async () => {
    storeMock.eventDetail = event({
      is_owner: false,
      can_be_edited: true,
      can_be_deleted: true,
      creator: { type: 'workflow_run', id: 'run-1', name: 'Nightly export' },
    } as Partial<CalendarEvent>);
    const wrapper = mountDrawer();
    await flushPromises();

    expect(buttonLabelled('Edit event')).not.toBeNull();
    expect(buttonLabelled('Delete')).not.toBeNull();
    wrapper.unmount();
  });

  it('explains the absence of the actions rather than showing an empty footer', async () => {
    storeMock.eventDetail = event({ is_owner: false, can_be_edited: false, can_be_deleted: false });
    const wrapper = mountDrawer();
    await flushPromises();

    expect(buttonLabelled('Edit event')).toBeNull();
    expect(buttonLabelled('Delete')).toBeNull();
    expect(text()).toContain('can be edited by its author or by the workspace owner');
    wrapper.unmount();
  });

  it('shows the panel’s SHAPE while loading, never a spinner', async () => {
    storeMock.eventLoading = true;
    const wrapper = mountDrawer();
    await flushPromises();

    expect(panel().querySelectorAll('.next-skeleton, [class*="skeleton"]').length).toBeGreaterThan(0);
    wrapper.unmount();
  });
});

describe('EventDrawer — create mode', () => {
  it('seeds the clicked DAY and defaults to all-day — a click on a square invents no hour', async () => {
    const wrapper = mountDrawer({ mode: 'create', eventId: null, seedDate: '2026-08-12' });
    await flushPromises();

    expect(inputLabelled('Starts').value).toBe('2026-08-12');
    // No time controls at all while all-day is on.
    expect(panel().querySelector('[aria-label="Start time"]')).toBeNull();
    wrapper.unmount();
  });

  it('keeps Save unavailable — and EXPLAINS why — until the event has a title', async () => {
    const wrapper = mountDrawer({ mode: 'create', eventId: null, seedDate: '2026-08-12' });
    await flushPromises();

    const save = buttonLabelled('Save');
    expect(save?.disabled).toBe(true);
    expect(save?.getAttribute('title')).toContain('title');

    await type('Title', 'Premiere');
    expect(buttonLabelled('Save')?.disabled).toBe(false);
    wrapper.unmount();
  });

  it('sends ONLY the day for an all-day event — the timed keys are absent, not empty', async () => {
    const wrapper = mountDrawer({ mode: 'create', eventId: null, seedDate: '2026-08-12' });
    await flushPromises();
    await type('Title', 'Premiere');

    buttonLabelled('Save')?.click();
    await flushPromises();

    const [payload, id] = saveEvent.mock.calls[0];
    expect(id).toBeNull();
    expect(payload).toMatchObject({ all_day: true, start_date: '2026-08-12' });
    expect(payload).not.toHaveProperty('starts_at');
    expect(payload).not.toHaveProperty('ends_at');
    expect(toast.success).toHaveBeenCalled();
    wrapper.unmount();
  });
});

/**
 * COLOUR IS NOT AUTHORED HERE — and the tests are absences on purpose.
 *
 * Colour in this module is a DICTIONARY OF MEANINGS: a task deadline takes its colour from
 * its priority, a workflow run from its result, a schedule from the one colour that says "a
 * projection, not a fact". The event drawer used to offer the same six values as a free
 * pick, and the pick carried no meaning at all — so a single grid had red saying "urgent",
 * "failed", and nothing. The control is gone, and so is `color` from the write surface and
 * from the event resource. Occurrences keep a colour; the server assigns it.
 */
describe('EventDrawer — colour is not authored here', () => {
  it('offers no colour control in create mode', async () => {
    const wrapper = mountDrawer({ mode: 'create', eventId: null, seedDate: '2026-08-12' });
    await flushPromises();

    expect(panel().querySelector('[aria-label="Colour"]')).toBeNull();
    // There IS a combobox on this form now — the repeat control — so the assertion names the
    // one it must not be. "No combobox at all" was only ever a proxy for "no colour picker",
    // and a proxy that stops being true is worse than no assertion.
    const comboboxes = Array.from(panel().querySelectorAll('[role="combobox"]')).map((el) =>
      el.getAttribute('aria-label'),
    );
    expect(comboboxes).not.toContain('Colour');
    expect(text()).not.toContain('Colour');
    wrapper.unmount();
  });

  it('offers no colour control when editing, and sends no colour on save', async () => {
    storeMock.eventDetail = event();
    const wrapper = mountDrawer({ mode: 'edit' });
    await flushPromises();

    expect(panel().querySelector('[aria-label="Colour"]')).toBeNull();
    expect(text()).not.toContain('Colour');

    buttonLabelled('Save')?.click();
    await flushPromises();

    const [payload] = saveEvent.mock.calls[0];
    expect('color' in payload).toBe(false);
    wrapper.unmount();
  });

  /**
   * THE MIGRATION CASE. An event saved back when a colour could be chosen may still arrive
   * carrying one from a server that has not caught up. The form reads only the keys it owns,
   * so the extra field must neither break the render nor be echoed back into a whole-event
   * write that would try to re-author a column the write surface no longer has.
   */
  it('opens an event that still carries a stale colour, and does not echo it back', async () => {
    storeMock.eventDetail = { ...event(), color: 'danger' } as unknown as CalendarEvent;
    const wrapper = mountDrawer({ mode: 'edit' });
    await flushPromises();

    // The form is intact: the title the user is here to edit is loaded and Save is live.
    expect(inputLabelled('Title').value).toBe('Nagranie odcinka');
    expect(buttonLabelled('Save')?.disabled).toBe(false);

    buttonLabelled('Save')?.click();
    await flushPromises();

    const [payload] = saveEvent.mock.calls[0];
    expect('color' in payload).toBe(false);
    expect(toast.danger).not.toHaveBeenCalled();
    wrapper.unmount();
  });

  /**
   * THE STALE-BUNDLE CASE. `color` is `prohibited` on the write surface now, not ignored, so
   * a browser still running the bundle that had the picker posts a key the server refuses BY
   * NAME. Nothing on this form is called `color` any more, so the message has no field to
   * render under — and a Save that silently does nothing is the failure this drawer is
   * written to avoid everywhere else. It is raised at the top instead, verbatim.
   */
  it('raises a 422 about a field this form no longer has, instead of swallowing it', async () => {
    storeMock.eventDetail = event();
    saveEvent.mockRejectedValue({
      status: 422,
      fieldErrors: { color: 'An event does not take a colour.' },
      message: 'The given data was invalid.',
    });

    const wrapper = mountDrawer({ mode: 'edit' });
    await flushPromises();

    buttonLabelled('Save')?.click();
    await flushPromises();
    await flushPromises();

    expect(text()).toContain('An event does not take a colour.');
    expect(toast.danger).toHaveBeenCalled();
    wrapper.unmount();
  });

  /** …and the view surface no longer names a colour that meant nothing. */
  it('shows no colour badge in view mode', async () => {
    storeMock.eventDetail = event();
    const wrapper = mountDrawer();
    await flushPromises();

    for (const name of ['Neutral', 'Accent', 'Green', 'Amber', 'Red', 'Blue']) {
      expect(text(), `view mode still names "${name}"`).not.toContain(name);
    }
    wrapper.unmount();
  });
});

describe('EventDrawer — the all-day switch', () => {
  async function openTimedEdit() {
    storeMock.eventDetail = event();
    const wrapper = mountDrawer({ mode: 'edit' });
    await flushPromises();
    return wrapper;
  }

  it('decomposes the stored instant into the WORKSPACE wall clock', async () => {
    const wrapper = await openTimedEdit();

    expect(inputLabelled('Start date').value).toBe('2026-08-10');
    expect(inputLabelled('Start time').value).toBe('14:30');
    wrapper.unmount();
  });

  /**
   * Flip to all-day and back: the hour the user had must still be there. Both groups live in the
   * draft precisely so this round trip costs nothing — and the proof is the payload, because a
   * value that survives only on screen is not the value that gets saved.
   */
  it('does not destroy the typed hour when the discriminator is flipped and flipped back', async () => {
    const wrapper = await openTimedEdit();

    // Kept before the loaded end (15:30) so the only thing under test is the round trip — an
    // inverted pair would legitimately block Save and prove nothing about the draft.
    await type('Start time', '13:15');

    const toggle = panel().querySelector<HTMLButtonElement>('[role="switch"]');
    expect(toggle).not.toBeNull();

    toggle!.click();
    await flushPromises();
    expect(panel().querySelector('[aria-label="Start time"]')).toBeNull();

    toggle!.click();
    await flushPromises();
    expect(inputLabelled('Start time').value).toBe('13:15');

    buttonLabelled('Save')?.click();
    await flushPromises();

    const [payload] = saveEvent.mock.calls[0];
    // 13:15 Warsaw in August is +02:00. The offset is EXPLICIT: a zone-less value would be read
    // by the server in UTC and drawn two hours away, with nothing reporting it.
    expect(payload.starts_at).toBe('2026-08-10T13:15:00+02:00');
    expect(payload).not.toHaveProperty('start_date');
    wrapper.unmount();
  });

  it('sends every moment with an explicit offset — never a bare wall clock', async () => {
    const wrapper = await openTimedEdit();

    buttonLabelled('Save')?.click();
    await flushPromises();

    const [payload] = saveEvent.mock.calls[0];
    expect(payload.starts_at).toMatch(/[+-]\d{2}:\d{2}$/);
    expect(payload.ends_at).toMatch(/[+-]\d{2}:\d{2}$/);
    wrapper.unmount();
  });

  /**
   * The pointer a `create_event` step set is carried through untouched. `PUT` writes every
   * column, so leaving it out of the body severs the link between a run and what it produced —
   * and nothing anywhere would say so.
   */
  it('carries the subject pointer from the GET into the PUT', async () => {
    storeMock.eventDetail = event({ subject: { type: 'task', id: 'task-9' } });
    const wrapper = mountDrawer({ mode: 'edit' });
    await flushPromises();

    buttonLabelled('Save')?.click();
    await flushPromises();

    const [payload, id] = saveEvent.mock.calls[0];
    expect(id).toBe('evt-1');
    expect(payload).toMatchObject({ subject_type: 'task', subject_id: 'task-9' });
    wrapper.unmount();
  });
});

describe('EventDrawer — save failures', () => {
  /**
   * A 422 is a FIELD problem. The server's copy is already translated (`lang/{pl,en}/calendar.php`)
   * and is rendered verbatim under the control it belongs to. A toast would put the message
   * somewhere the user is not looking, next to a field that looks fine.
   */
  it('routes 422 messages under their own fields, verbatim, and raises no toast', async () => {
    storeMock.eventDetail = event();
    saveEvent.mockRejectedValue({
      status: 422,
      fieldErrors: { title: 'Give the event a title, please.' },
      message: 'The given data was invalid.',
    });

    const wrapper = mountDrawer({ mode: 'edit' });
    await flushPromises();

    buttonLabelled('Save')?.click();
    await flushPromises();
    await flushPromises();

    // The SERVER's sentence, not one of ours — the field-level copy is not re-worded client-side.
    expect(text()).toContain('Give the event a title, please.');
    expect(toast.danger).not.toHaveBeenCalled();
    // …and it is attached to the control it belongs to, not floated at the top of the panel.
    expect(document.querySelector('.next-drawer input[aria-describedby$="-message"]')).not.toBeNull();
    wrapper.unmount();
  });

  /**
   * The UX spec (§12.5) wants the caret on the first field the server rejected; `EventDrawer`
   * implements that by querying `[aria-invalid="true"]` inside the panel.
   *
   * This test used to be recorded as an EXPECTED FAILURE: no control ever carried that
   * attribute, because every `next` control derived its state as
   * `props.ariaInvalid ?? field?.invalid.value` while `ariaInvalid` was a `boolean` prop with
   * no declared default — Vue cast its absence to `false`, and `false ?? x` is `false`, so the
   * FormField branch was dead app-wide. The controls now declare `ariaInvalid: undefined` so
   * absence really is absence; see `ui/forms/__tests__/ariaInvalidDelegation.spec.ts`.
   */
  it('moves focus to the first field the server rejected', async () => {
    storeMock.eventDetail = event();
    saveEvent.mockRejectedValue({
      status: 422,
      fieldErrors: { title: 'Give the event a title, please.' },
      message: 'The given data was invalid.',
    });

    const wrapper = mountDrawer({ mode: 'edit' });
    await flushPromises();

    buttonLabelled('Save')?.click();
    await flushPromises();
    await flushPromises();

    const invalid = document.querySelector('.next-drawer [aria-invalid="true"]');
    expect(invalid).not.toBeNull();
    expect(document.activeElement).toBe(invalid);
    wrapper.unmount();
  });

  /**
   * Anything else is not the user's mistake: the form KEEPS what was typed, says what happened at
   * the top, and toasts. Losing a half-written event to a network blip is the one outcome worth
   * engineering against.
   */
  it('keeps the form filled on a non-422 failure and says so at the top', async () => {
    storeMock.eventDetail = event();
    saveEvent.mockRejectedValue({ status: 500, fieldErrors: {}, message: 'Server exploded' });

    const wrapper = mountDrawer({ mode: 'edit' });
    await flushPromises();
    await type('Title', 'Half-written thought');

    buttonLabelled('Save')?.click();
    await flushPromises();

    expect(text()).toContain('Server exploded');
    expect(toast.danger).toHaveBeenCalled();
    expect(inputLabelled('Title').value).toBe('Half-written thought');
    wrapper.unmount();
  });
});

describe('EventDrawer — deleting', () => {
  it('asks first, and does nothing when the answer is no', async () => {
    storeMock.eventDetail = event();
    confirmMock.mockResolvedValue(false);

    const wrapper = mountDrawer();
    await flushPromises();

    buttonLabelled('Delete')?.click();
    await flushPromises();

    expect(confirmMock).toHaveBeenCalled();
    expect(deleteEvent).not.toHaveBeenCalled();
    wrapper.unmount();
  });

  it('deletes on confirmation — and the confirmation promises no undo', async () => {
    storeMock.eventDetail = event();

    const wrapper = mountDrawer();
    await flushPromises();

    buttonLabelled('Delete')?.click();
    await flushPromises();

    expect(deleteEvent).toHaveBeenCalledWith('evt-1');
    expect(wrapper.emitted('deleted')).toBeTruthy();
    expect(toast.success).toHaveBeenCalled();

    // Rows are soft-deleted but there is no restore endpoint, so the copy must not imply one.
    const options = confirmMock.mock.calls[0][0];
    expect(`${options.title} ${options.message}`).not.toMatch(/undo|restore|recover/i);
    wrapper.unmount();
  });
});

// ─────────────────────────────────────────────────────────────────────────────────────────
// SERIES — the scope is chosen BEFORE the form, and the seeding follows it.
// ─────────────────────────────────────────────────────────────────────────────────────────
//
// The GET returns the series' ANCHOR, never the square that was clicked. So:
//
//   • a form seeded from the anchor and saved as `scope=occurrence` would exclude the CLICKED
//     day and detach a duplicate on the ANCHOR day — the user loses the Tuesday they were
//     editing and gains a copy somewhere else;
//   • a form seeded from the occurrence and saved as `scope=series` would MOVE the anchor and
//     cut off the series' past.
//
// Each seeding is right for exactly one scope, and neither failure reports anything. The
// assertions below are what keep the pairing intact.

/** A weekly series anchored on Monday 2026-08-10, with one day already excluded. */
function seriesEvent(over: Partial<CalendarEvent> = {}): CalendarEvent {
  return event({
    recurrence: {
      day: { mode: 'weekdays', weekdays: [1] },
      month: null,
      exclusions: { dates: ['2026-08-24'] },
      until: null,
    },
    recurrence_timezone: TIMEZONE,
    recurrence_label: 'Weekly on Mon',
    ...over,
  } as Partial<CalendarEvent>);
}

/** The scope dialog is a Modal, teleported to <body> like the drawer. */
function modal(): HTMLElement | null {
  return document.querySelector<HTMLElement>('.next-modal');
}

function modalButton(label: string): HTMLButtonElement | null {
  const root = modal();
  if (!root) return null;
  return (
    (Array.from(root.querySelectorAll('button')).find(
      (b) => (b.textContent ?? '').trim() === label,
    ) as HTMLButtonElement | undefined) ?? null
  );
}

function chooseScope(value: string): void {
  modal()?.querySelector<HTMLInputElement>(`[data-radio-value="${value}"]`)?.click();
}

/** The overlay stack's listener is on `document` in the capture phase, so any bubbling target does. */
function pressEscape(target: EventTarget): void {
  target.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
}

describe('EventDrawer — the scope dialog comes first', () => {
  it('asks about the scope before switching into edit mode, for a SERIES', async () => {
    storeMock.eventDetail = seriesEvent();
    const wrapper = mountDrawer({
      occurrenceDate: '2026-09-14',
      occurrenceStartsAt: '2026-09-14T12:30:00+00:00',
    });
    await flushPromises();

    buttonLabelled('Edit event')?.click();
    await flushPromises();

    expect(modal()).not.toBeNull();
    // Crucially NOT yet: the form must not exist before the scope that shapes it does.
    expect(wrapper.emitted('request-edit')).toBeUndefined();

    chooseScope('following');
    await flushPromises();
    // NO WEEKDAY in a label that follows a preposition: `Intl` hands weekdays back in the
    // nominative and Polish wants a genitive after "od", which no formatter option supplies.
    // The dialog's context line above still names the full day, weekday included.
    modalButton('Edit from September 14, 2026')?.click();
    await flushPromises();

    expect(wrapper.emitted('request-edit')?.[0]).toEqual(['following']);
    wrapper.unmount();
  });

  /**
   * THE DIALOG IS A LAYER ABOVE THE DRAWER, AND ESCAPE ONLY EVER DISMISSES THE TOP ONE.
   *
   * This is the one claim about the two overlays' layering that is decidable without a browser, and
   * it is the one with teeth. The other claim — that the dialog PAINTS above the drawer — is a
   * question about stacking contexts and computed z-index, which no DOM emulator resolves; it is
   * reported as uncovered rather than approximated here by reading a class name or a `calc()` string,
   * both of which would pass while the real screen was wrong.
   *
   * The failure this pins is documented one layer down, for a Select inside a Modal
   * (`ui/forms/__tests__/SelectEscapeInModal.dom.spec.ts`): the stack's Escape listener is on
   * `document` in the CAPTURE phase, so an overlay that does not register loses the race and the
   * WRONG layer closes. Here that would mean Escape over "what do you want to edit?" tearing down the
   * whole drawer — a question dismissed and an editing session ended by the key that means "never
   * mind, not that question". The Drawer/Modal pair has a spec each and no spec for the pair, and the
   * scope dialog is where the app actually stacks them.
   */
  it('Escape dismisses the DIALOG and leaves the drawer beneath it standing', async () => {
    storeMock.eventDetail = seriesEvent();
    // OPENED AFTER MOUNT, unlike every other test in this file. The Drawer registers with the shared
    // overlay stack from a NON-IMMEDIATE watch on `open`, so a drawer mounted already-open is never
    // on the stack — and a test written that way would be asking about a layer that is not there.
    const wrapper = mountDrawer({
      open: false,
      occurrenceDate: '2026-09-14',
      occurrenceStartsAt: '2026-09-14T12:30:00+00:00',
    });
    await wrapper.setProps({ open: true });
    await flushPromises();

    buttonLabelled('Edit event')?.click();
    await flushPromises();
    expect(modal()).not.toBeNull();

    // 1st Escape → the question goes away; the drawer does not.
    pressEscape(modal() as HTMLElement);
    await flushPromises();

    expect(modal()).toBeNull();
    expect(document.querySelector('.next-drawer')).not.toBeNull();
    // Dismissing the question is not answering it: no scope was chosen, so nothing may proceed.
    expect(wrapper.emitted('request-edit')).toBeUndefined();
    // …and the drawer did not even ASK to close, which is the half a stale stack would get wrong
    // while the panel above stayed on screen looking fine.
    expect(wrapper.emitted('update:open')).toBeUndefined();

    // 2nd Escape → now the layer underneath is the top one, and it asks to close. The page owns the
    // drawer's `open`, so the request is what this component controls and therefore what is asserted.
    pressEscape(document.querySelector('.next-drawer') as HTMLElement);
    await flushPromises();

    expect(wrapper.emitted('update:open')?.at(-1)).toEqual([false]);
    wrapper.unmount();
  });

  it('asks nothing for a ONE-OFF event — that path is unchanged, byte for byte', async () => {
    storeMock.eventDetail = event();
    const wrapper = mountDrawer();
    await flushPromises();

    buttonLabelled('Edit event')?.click();
    await flushPromises();

    expect(modal()).toBeNull();
    expect(wrapper.emitted('request-edit')?.[0]).toEqual(['series']);
    wrapper.unmount();
  });

  it('falls back to the whole series — and SAYS so — when no occurrence was named', async () => {
    storeMock.eventDetail = seriesEvent();
    // A stale deep link: `?scope=occurrence` with no `on` beside it.
    const wrapper = mountDrawer({ mode: 'edit', scope: 'occurrence', occurrenceDate: null });
    await flushPromises();

    expect(text()).toContain('Opened without naming an occurrence');
    // …and the repeat control is back, because this really is a whole-series edit now.
    expect(text()).toContain('Repeats');
    wrapper.unmount();
  });

  it('ignores a scoped link SILENTLY when the event no longer repeats', async () => {
    storeMock.eventDetail = event();
    const wrapper = mountDrawer({ mode: 'edit', scope: 'occurrence', occurrenceDate: '2026-09-14' });
    await flushPromises();

    // A stale link is not a user's mistake, so there is nothing to tell them about.
    expect(text()).not.toContain('Opened without naming an occurrence');
    expect(text()).not.toContain('You are editing');
    wrapper.unmount();
  });
});

describe('EventDrawer — the form is seeded from whichever day the scope is about', () => {
  it('seeds from the ANCHOR under `scope=series`', async () => {
    storeMock.eventDetail = seriesEvent();
    const wrapper = mountDrawer({ mode: 'edit', scope: 'series' });
    await flushPromises();

    expect(inputLabelled('Start date').value).toBe('2026-08-10');
    expect(text()).toContain('You are editing the WHOLE series');
    wrapper.unmount();
  });

  it('seeds from the CLICKED OCCURRENCE under `scope=following`, with the anchor’s own duration', async () => {
    storeMock.eventDetail = seriesEvent();
    const wrapper = mountDrawer({
      mode: 'edit',
      scope: 'following',
      occurrenceDate: '2026-09-14',
      occurrenceStartsAt: '2026-09-14T12:30:00+00:00',
    });
    await flushPromises();

    expect(inputLabelled('Start date').value).toBe('2026-09-14');
    expect(inputLabelled('Start time').value).toBe('14:30');
    // The anchor's hour-long span, applied to this occurrence as a fixed interval.
    expect(inputLabelled('End date').value).toBe('2026-09-14');
    expect(inputLabelled('End time').value).toBe('15:30');
    wrapper.unmount();
  });

  it('offers NO repeat control under `scope=occurrence`, and says why', async () => {
    storeMock.eventDetail = seriesEvent();
    const wrapper = mountDrawer({
      mode: 'edit',
      scope: 'occurrence',
      occurrenceDate: '2026-09-14',
      occurrenceStartsAt: '2026-09-14T12:30:00+00:00',
    });
    await flushPromises();

    // One occurrence of a series is not itself a series (422 `occurrence_has_no_rule`). With
    // no control there is nothing to send, so the whole error class is unreachable.
    const comboboxes = Array.from(panel().querySelectorAll('[role="combobox"]')).map((el) =>
      el.getAttribute('aria-label'),
    );
    expect(comboboxes).not.toContain('Repeats');
    expect(text()).toContain('This occurrence will stop belonging to the series');
    wrapper.unmount();
  });
});

describe('EventDrawer — what a scoped save puts on the wire', () => {
  it('carries the rule back UNTOUCHED when only the title was edited', async () => {
    storeMock.eventDetail = seriesEvent();
    const wrapper = mountDrawer({ mode: 'edit', scope: 'series' });
    await flushPromises();

    await type('Title', 'Sprint review — renamed');
    buttonLabelled('Save')?.click();
    await flushPromises();

    const [payload] = saveEvent.mock.calls[0];
    expect(payload.recurrence.day).toEqual({ mode: 'weekdays', weekdays: [1] });
    // Dropping this would RESURRECT the day somebody deleted one at a time.
    expect(payload.recurrence.exclusions).toEqual({ dates: ['2026-08-24'] });
    // `series` is the wire default and stays unnamed — the payload is what it always was.
    expect(payload).not.toHaveProperty('scope');
    expect(payload).not.toHaveProperty('occurrence_date');
    wrapper.unmount();
  });

  it('sends NO rule under `scope=occurrence`, and names the day it detaches', async () => {
    storeMock.eventDetail = seriesEvent();
    const wrapper = mountDrawer({
      mode: 'edit',
      scope: 'occurrence',
      occurrenceDate: '2026-09-14',
      occurrenceStartsAt: '2026-09-14T12:30:00+00:00',
    });
    await flushPromises();

    buttonLabelled('Save')?.click();
    await flushPromises();

    const [payload] = saveEvent.mock.calls[0];
    expect(payload).not.toHaveProperty('recurrence');
    expect(payload.scope).toBe('occurrence');
    // The day the SERVER published on the square — never re-derived from an instant and a zone.
    expect(payload.occurrence_date).toBe('2026-09-14');
    wrapper.unmount();
  });

  it('sends the rule AND the split day under `scope=following`', async () => {
    storeMock.eventDetail = seriesEvent();
    const wrapper = mountDrawer({
      mode: 'edit',
      scope: 'following',
      occurrenceDate: '2026-09-14',
      occurrenceStartsAt: '2026-09-14T12:30:00+00:00',
    });
    await flushPromises();

    buttonLabelled('Save')?.click();
    await flushPromises();

    const [payload] = saveEvent.mock.calls[0];
    expect(payload.scope).toBe('following');
    expect(payload.occurrence_date).toBe('2026-09-14');
    // 2026-09-14 is a Monday, so the weekly rule still fits its new anchor.
    expect(payload.recurrence.day).toEqual({ mode: 'weekdays', weekdays: [1] });
    wrapper.unmount();
  });

  it('names the SCOPE in the success toast — three operations must not report identically', async () => {
    storeMock.eventDetail = seriesEvent();
    saveEvent.mockResolvedValue(event({ id: 'evt-detached' }));
    const wrapper = mountDrawer({
      mode: 'edit',
      scope: 'occurrence',
      occurrenceDate: '2026-09-14',
      occurrenceStartsAt: '2026-09-14T12:30:00+00:00',
    });
    await flushPromises();

    buttonLabelled('Save')?.click();
    await flushPromises();

    expect(toast.success).toHaveBeenCalledWith('Saved this occurrence as a separate event');
    // A 201 reaches us as "the id we got back is not the id we asked for". Nothing carries the
    // old id forward: the drawer reports the row that came back and closes.
    expect(wrapper.emitted('saved')?.[0]?.[0]).toMatchObject({ id: 'evt-detached' });
    wrapper.unmount();
  });
});

describe('EventDrawer — a rule this form cannot express is not rewritten', () => {
  it('shows "Another rule" and echoes the descriptor byte for byte', async () => {
    storeMock.eventDetail = seriesEvent({
      // Two weekdays: accepted by the API, outside this control's vocabulary.
      recurrence: {
        day: { mode: 'weekdays', weekdays: [1, 3] },
        month: null,
        exclusions: { dates: ['2026-08-24'] },
        until: '2026-12-31',
      },
      recurrence_label: 'Weekly on Mon, Wed',
    } as Partial<CalendarEvent>);
    const wrapper = mountDrawer({ mode: 'edit', scope: 'series' });
    await flushPromises();

    expect(text()).toContain('Another rule');
    // The SERVER's sentence about it, not one composed here.
    expect(text()).toContain('Weekly on Mon, Wed');
    expect(text()).toContain('Choosing a different repeat will replace the current rule.');

    // Editing the title must not narrow "Mondays and Wednesdays" to "Mondays".
    await type('Title', 'Renamed');
    buttonLabelled('Save')?.click();
    await flushPromises();

    const [payload] = saveEvent.mock.calls[0];
    expect(payload.recurrence.day).toEqual({ mode: 'weekdays', weekdays: [1, 3] });
    expect(payload.recurrence.until).toBe('2026-12-31');
    wrapper.unmount();
  });
});

describe('EventDrawer — where a rule’s 422 lands', () => {
  it('keeps a `recurrence.*` message OFF the top alert — there is a control for it', async () => {
    storeMock.eventDetail = seriesEvent();
    saveEvent.mockRejectedValue({
      status: 422,
      fieldErrors: { 'recurrence.until': 'This series would have no occurrences left.' },
      message: null,
    });
    const wrapper = mountDrawer({ mode: 'edit', scope: 'series' });
    await flushPromises();

    buttonLabelled('Save')?.click();
    await flushPromises();

    // Under its control, and no toast: a field problem belongs at the field. (The scope
    // banner is an Alert too, so the assertion is about WHERE the message is, not about
    // there being no alerts on screen.)
    expect(text()).toContain('This series would have no occurrences left.');
    const inAnAlert = Array.from(panel().querySelectorAll('.next-alert')).some((el) =>
      (el.textContent ?? '').includes('This series would have no occurrences left.'),
    );
    expect(inAnAlert).toBe(false);
    expect(toast.danger).not.toHaveBeenCalled();
    wrapper.unmount();
  });

  it('DOES raise a key there is no control for — that is a client defect, not a user state', async () => {
    storeMock.eventDetail = seriesEvent();
    saveEvent.mockRejectedValue({
      status: 422,
      fieldErrors: { 'recurrence.tz': 'This field is not accepted.' },
      message: null,
    });
    const wrapper = mountDrawer({ mode: 'edit', scope: 'series' });
    await flushPromises();

    buttonLabelled('Save')?.click();
    await flushPromises();

    expect(panel().querySelector('.next-alert')?.textContent).toContain('This field is not accepted.');
    wrapper.unmount();
  });
});

describe('EventDrawer — deleting a series', () => {
  it('deletes at the chosen scope, naming the day', async () => {
    storeMock.eventDetail = seriesEvent();
    const wrapper = mountDrawer({
      occurrenceDate: '2026-09-14',
      occurrenceStartsAt: '2026-09-14T12:30:00+00:00',
    });
    await flushPromises();

    buttonLabelled('Delete')?.click();
    await flushPromises();
    expect(modal()).not.toBeNull();
    // Never the plain confirm — a series has three different deletions behind one verb.
    expect(confirmMock).not.toHaveBeenCalled();

    modalButton('Delete this occurrence')?.click();
    await flushPromises();

    expect(deleteEvent).toHaveBeenCalledWith('evt-1', {
      scope: 'occurrence',
      occurrenceDate: '2026-09-14',
    });
    expect(toast.success).toHaveBeenCalledWith('Deleted this occurrence');
    expect(wrapper.emitted('deleted')).toBeTruthy();
    wrapper.unmount();
  });

  it('keeps a refusal INSIDE the dialog, where the remedy it names lives', async () => {
    storeMock.eventDetail = seriesEvent();
    deleteEvent.mockRejectedValue({
      status: 422,
      fieldErrors: {
        occurrence_date:
          'This series already has the maximum number of skipped days (50). Split the series instead.',
      },
      message: null,
    });
    const wrapper = mountDrawer({ occurrenceDate: '2026-09-14' });
    await flushPromises();

    buttonLabelled('Delete')?.click();
    await flushPromises();
    modalButton('Delete this occurrence')?.click();
    await flushPromises();

    // Still open, with the message beside the option that failed — the fix ("split the
    // series") is another option of this very dialog.
    expect(modal()).not.toBeNull();
    expect(modal()?.textContent).toContain('Split the series instead.');
    expect(wrapper.emitted('deleted')).toBeUndefined();
    wrapper.unmount();
  });
});

describe('EventDrawer — "this series has nothing in the month on screen"', () => {
  it('says it when the `event` source was asked and answered with nothing for this series', async () => {
    storeMock.eventDetail = seriesEvent();
    storeMock.loaded = true;
    storeMock.currentQuery = { sources: [] }; // no filter = every source
    const wrapper = mountDrawer();
    await flushPromises();

    expect(text()).toContain('This series has no occurrences in the month on screen.');
    // The actions are facts about DATES the resource carries — never "the next occurrence",
    // which would mean projecting the cadence in the client.
    expect(text()).toContain('Show the start of the series');
    wrapper.unmount();
  });

  it('stays SILENT when the source was filtered out — "we did not ask" is a different sentence', async () => {
    storeMock.eventDetail = seriesEvent();
    storeMock.loaded = true;
    storeMock.currentQuery = { sources: ['task'] };
    const wrapper = mountDrawer();
    await flushPromises();

    expect(text()).not.toContain('This series has no occurrences');
    wrapper.unmount();
  });

  it('stays SILENT when the source could not answer', async () => {
    storeMock.eventDetail = seriesEvent();
    storeMock.loaded = true;
    storeMock.currentQuery = { sources: [] };
    storeMock.unavailableSources = [{ source: 'event', reason: 'failed' }];
    const wrapper = mountDrawer();
    await flushPromises();

    expect(text()).not.toContain('This series has no occurrences');
    wrapper.unmount();
  });

  it('stays SILENT when the series DOES have a square in the window', async () => {
    storeMock.eventDetail = seriesEvent();
    storeMock.loaded = true;
    storeMock.currentQuery = { sources: [] };
    storeMock.occurrences = [
      { id: 'event:evt-1:2026-08-10', subject: { type: 'calendar_event', id: 'evt-1' } },
    ];
    const wrapper = mountDrawer();
    await flushPromises();

    expect(text()).not.toContain('This series has no occurrences');
    wrapper.unmount();
  });
});

describe('EventDrawer — the series block in view mode', () => {
  it('prefers the CLICKED square’s sentence over the event’s own, and composes neither', async () => {
    storeMock.eventDetail = seriesEvent();
    const wrapper = mountDrawer({
      occurrenceDate: '2026-09-14',
      occurrenceCadenceLabel: 'Co tydzień: pon.',
    });
    await flushPromises();

    // The square's own label wins — it came from the same refresh the user is looking at.
    expect(text()).toContain('Co tydzień: pon.');
    expect(text()).not.toContain('Weekly on Mon');
    expect(text()).toContain('Selected occurrence');
    expect(text()).toContain('no end');
    wrapper.unmount();
  });

  it('falls back to the event’s own sentence when no square was clicked', async () => {
    storeMock.eventDetail = seriesEvent();
    const wrapper = mountDrawer();
    await flushPromises();

    expect(text()).toContain('Weekly on Mon');
    wrapper.unmount();
  });

  it('shows the rule’s time zone only when it differs from the grid’s', async () => {
    storeMock.eventDetail = seriesEvent();
    const same = mountDrawer();
    await flushPromises();
    expect(text()).not.toContain('Rule time zone');
    same.unmount();
    document.body.innerHTML = '';

    storeMock.eventDetail = seriesEvent({
      recurrence_timezone: 'America/New_York',
    } as Partial<CalendarEvent>);
    const different = mountDrawer();
    await flushPromises();
    expect(text()).toContain('Rule time zone');
    expect(text()).toContain('America/New_York');
    different.unmount();
  });
});

describe('EventDrawer — a refusal about the CHOICE itself', () => {
  it('says it once, with a way back into the dialog, and keeps the form filled', async () => {
    storeMock.eventDetail = seriesEvent();
    saveEvent.mockRejectedValue({
      status: 422,
      fieldErrors: { occurrence_date: 'This series has no occurrence on that day.' },
      message: null,
    });
    const wrapper = mountDrawer({
      mode: 'edit',
      scope: 'occurrence',
      occurrenceDate: '2026-09-15',
      occurrenceStartsAt: '2026-09-15T12:30:00+00:00',
    });
    await flushPromises();

    await type('Title', 'Still typed');
    buttonLabelled('Save')?.click();
    await flushPromises();

    const alerts = Array.from(panel().querySelectorAll('.next-alert')).map((el) => el.textContent ?? '');
    const carrying = alerts.filter((body) => body.includes('This series has no occurrence on that day.'));
    // ONCE. `scope`/`occurrence_date` have no control, but they do have a home — counting
    // them as homeless would print the same sentence in two alerts at once.
    expect(carrying).toHaveLength(1);
    expect(carrying[0]).toContain('Choose the scope again');
    // Nothing typed is lost: only the scope has to be redone.
    expect(inputLabelled('Title').value).toBe('Still typed');
    wrapper.unmount();
  });
});

/**
 * "AFTER N TIMES" — THE HALF OF THE CONTRACT THAT ONLY EXISTS BETWEEN THE TWO SIDES.
 *
 * `recurrenceStateToWire` has its own spec proving it emits `count` alone, and the server has its
 * own proving a count becomes a day. Neither says anything about the JOIN: whether the body this
 * FORM assembles is the body the server accepts, and whether the server's ANSWER is one this form
 * can seed a control from. That join is where "N times" silently becomes "does not repeat" on the
 * second open, and neither side reports it, because each is behaving exactly as specified.
 *
 * The server half of the same agreement — the identical body posted, and the identical block
 * asserted on the way back — is
 * `CalendarEventRecurrenceTest::test_the_repeat_controls_count_body_round_trips_as_the_resolved_date`.
 * The two are deliberately written against the same literal shape, so a change to one that is not
 * made to the other goes red on one side instead of quiet on both.
 */
describe('EventDrawer — "after N times" is a way of saying a date', () => {
  /** Click a radio by its wire value (they carry no aria-label — the LABEL is the visible text). */
  function chooseEndMode(value: string): void {
    panel().querySelector<HTMLInputElement>(`[data-radio-value="${value}"]`)?.click();
  }

  /** Open the repeat select and click the option with this exact label. */
  async function chooseRepeat(label: string): Promise<void> {
    panel().querySelector<HTMLElement>('[role="combobox"][aria-label="Repeats"]')?.click();
    await flushPromises();
    const option = Array.from(document.body.querySelectorAll<HTMLElement>('[role="option"]')).find(
      (el) => (el.textContent ?? '').trim() === label,
    );
    if (!option) throw new Error(`no repeat option labelled "${label}"`);
    option.click();
    await flushPromises();
  }

  it('puts `count` alone on the wire, and SAYS up front that it will come back as a date', async () => {
    // 2026-08-10 is a Monday, so "Every Monday" is a preset this day can anchor.
    const wrapper = mountDrawer({ mode: 'create', eventId: null, seedDate: '2026-08-10' });
    await flushPromises();
    await type('Title', 'Standup');

    await chooseRepeat('Every Monday');
    chooseEndMode('count');
    await flushPromises();
    await type('After a number of repeats', '10');

    // L11 / §24.5.3: the control promises nothing it cannot keep. Without this sentence the user
    // saves "10 times", reopens, sees a date and concludes the save was lost.
    expect(text()).toContain('reopen it and you will see a date, not a number');

    buttonLabelled('Save')?.click();
    await flushPromises();

    const [payload] = saveEvent.mock.calls[0];
    // The literal body the server half of this pair posts.
    expect(payload.recurrence).toEqual({
      day: { mode: 'weekdays', weekdays: [1] },
      month: null,
      count: 10,
    });
    // `until` and `count` are mutually exclusive on the wire (422 `end_is_one_thing`) — the key is
    // ABSENT rather than null, which is a different payload and a different refusal.
    expect(payload.recurrence).not.toHaveProperty('until');
    wrapper.unmount();
  });

  /**
   * THE CEILING IS A MIRROR OF A NUMBER THIS CLIENT CANNOT READ, AND IT HAS ONE HOME.
   *
   * The real cap is `calendar.recurrence_count_max` (env `CALENDAR_RECURRENCE_COUNT_MAX`,
   * default 366), enforced in `StoreCalendarEventRequest::maxRecurrenceCount()`. It is NOT on
   * any payload this frontend receives — not in the occurrences `meta`, not on
   * `CalendarEventResource` — so the control can only mirror it, which is why the mirror is a
   * named constant carrying the config key in its own comment rather than a literal buried in
   * a template.
   *
   * WHAT THIS TEST CAN AND CANNOT PROVE: it cannot prove the two sides AGREE — nothing on this
   * side can, until the number is published. It proves the frontend has exactly ONE copy of it,
   * so a deployment that moves the cap is a one-line correction with a comment pointing at the
   * file to correct it from, instead of a hunt through a template.
   */
  it('takes the counter’s ceiling from the one place that mirrors the server’s cap', async () => {
    const wrapper = mountDrawer({ mode: 'create', eventId: null, seedDate: '2026-08-10' });
    await flushPromises();

    await chooseRepeat('Every Monday');
    chooseEndMode('count');
    await flushPromises();

    const counter = inputLabelled('After a number of repeats');
    expect(counter.getAttribute('max')).toBe(String(RECURRENCE_COUNT_MAX));
    // The spinbutton says the same thing to a screen reader as the styling does to an eye.
    expect(counter.getAttribute('aria-valuemax')).toBe(String(RECURRENCE_COUNT_MAX));
    expect(counter.getAttribute('min')).toBe('1');

    // The value the server ships with, restated so a change on either side is at least VISIBLE
    // in a diff. `config/calendar.php` is the source of truth; this line is a reminder, not one.
    expect(RECURRENCE_COUNT_MAX).toBe(366);
    wrapper.unmount();
  });

  /**
   * The ceiling is an AFFORDANCE, never a gate. `NumberInput` is deliberately not given
   * `clampOnBlur`, so an over-cap number is flagged and still reaches the server — which is the
   * direction that matters if a deployment RAISES the cap: the control would be marking a legal
   * value out of range, and silently swallowing it would make that stale hint the final word.
   */
  it('flags an over-cap count without swallowing it', async () => {
    const wrapper = mountDrawer({ mode: 'create', eventId: null, seedDate: '2026-08-10' });
    await flushPromises();
    await type('Title', 'Standup');

    await chooseRepeat('Every Monday');
    chooseEndMode('count');
    await flushPromises();
    await type('After a number of repeats', String(RECURRENCE_COUNT_MAX + 1));

    expect(inputLabelled('After a number of repeats').getAttribute('aria-invalid')).toBe('true');

    buttonLabelled('Save')?.click();
    await flushPromises();

    // The server gets the number that was typed, and answers on `recurrence.count` — which
    // `RecurrenceField` renders under this very field.
    const [payload] = saveEvent.mock.calls[0];
    expect(payload.recurrence.count).toBe(RECURRENCE_COUNT_MAX + 1);
    wrapper.unmount();
  });

  it('reads the server’s ANSWER back as a date, with no counter anywhere', async () => {
    // Exactly what the server returns after resolving `count: 10` against a Monday anchor: only
    // `until`, never a count. Ten Mondays from 2026-08-10 is 2026-10-12.
    storeMock.eventDetail = seriesEvent({
      recurrence: {
        day: { mode: 'weekdays', weekdays: [1] },
        month: null,
        exclusions: null,
        until: '2026-10-12',
      },
      recurrence_label: 'Weekly on Mon',
    } as Partial<CalendarEvent>);
    const wrapper = mountDrawer({ mode: 'edit', scope: 'series' });
    await flushPromises();

    // The end reads as a DATE…
    expect(panel().querySelector<HTMLInputElement>('[data-radio-value="until"]')?.checked).toBe(true);
    expect(inputLabelled('On a date').value).toBe('2026-10-12');

    // …and there is no counter to disagree with it. A form that kept one would drift from the row
    // on the first edit made from anywhere else.
    expect(panel().querySelector('[aria-label="After a number of repeats"]')).toBeNull();
    expect(text()).not.toContain('reopen it and you will see a date, not a number');

    // A second save carries the resolved DATE, so nothing re-resolves a stale count against a
    // cadence somebody may have changed since.
    await type('Title', 'Standup, renamed');
    buttonLabelled('Save')?.click();
    await flushPromises();

    const [payload] = saveEvent.mock.calls[0];
    expect(payload.recurrence.until).toBe('2026-10-12');
    expect(payload.recurrence).not.toHaveProperty('count');
    wrapper.unmount();
  });
});

/**
 * THE RULE'S CLOCK IS NOT ALWAYS THE GRID'S CLOCK — and this is the state a workspace lands in by
 * changing its timezone, not an exotic one.
 *
 * A rule is stamped with the workspace zone AT THE MOMENT OF THE WRITE (L13) and never re-derived.
 * The form, meanwhile, recognises a stored rule against the anchor day IT holds, which it read on the
 * workspace's CURRENT clock. When those two clocks disagree by enough to move the anchor across
 * midnight, the day the rule was built for and the day the form is looking at are different weekdays,
 * recognition fails, and the control degrades to "Another rule" — selected, disabled, echoed back
 * byte for byte.
 *
 * THAT DEGRADATION IS THE SAFE DIRECTION AND IT HAD NO FIXTURE. The alternative — matching anyway —
 * would rewrite a Monday series into a Sunday one on the first edit of its TITLE, silently, because
 * somebody changed a workspace setting nobody connects with a cadence. The existing "Another rule"
 * spec covers a rule the control cannot SPEAK (two weekdays); this covers a rule it speaks perfectly
 * and still must not claim, which is a different cause with the same required outcome.
 */
describe('EventDrawer — a rule stamped on another clock is frozen, not re-read', () => {
  /**
   * A weekly-on-MONDAY rule whose anchor instant lands on SUNDAY when read in the grid's zone.
   *
   * 21:00Z on 2026-08-09 is 11:00 on MONDAY the 10th in Pacific/Kiritimati (UTC+14) — the clock the
   * rule was stamped on, where `weekdays: [1]` is exactly right — and 23:00 on SUNDAY the 9th in
   * Europe/Warsaw (+02:00), which is the clock this form reads the anchor on. A Sunday cannot anchor
   * "every Monday", so the control has a rule it can speak and an anchor that contradicts it.
   */
  function foreignClockSeries(): CalendarEvent {
    return event({
      starts_at: '2026-08-09T21:00:00+00:00',
      ends_at: '2026-08-09T22:00:00+00:00',
      recurrence: {
        day: { mode: 'weekdays', weekdays: [1] },
        month: null,
        exclusions: { dates: ['2026-08-24'] },
        until: '2026-12-31',
      },
      recurrence_timezone: 'Pacific/Kiritimati',
      recurrence_label: 'Weekly on Mon',
    } as Partial<CalendarEvent>);
  }

  it('shows the rule as one it cannot claim, with the SERVER’s sentence about it', async () => {
    storeMock.eventDetail = foreignClockSeries();
    const wrapper = mountDrawer({ mode: 'edit', scope: 'series' });
    await flushPromises();

    // The form is looking at the SUNDAY while the rule was built for the Monday — that is the whole
    // disagreement, and asserting it here is what stops this test passing for an unrelated reason.
    expect(inputLabelled('Start date').value).toBe('2026-08-09');

    expect(text()).toContain('Another rule');
    // The sentence about a stored rule is the SERVER's, always. This client composes no cadence prose.
    expect(text()).toContain('Weekly on Mon');
    expect(text()).toContain('Choosing a different repeat will replace the current rule.');
    wrapper.unmount();
  });

  it('echoes the descriptor byte for byte when only the title is edited', async () => {
    storeMock.eventDetail = foreignClockSeries();
    const wrapper = mountDrawer({ mode: 'edit', scope: 'series' });
    await flushPromises();

    await type('Title', 'Renamed');
    buttonLabelled('Save')?.click();
    await flushPromises();

    const [payload] = saveEvent.mock.calls[0];
    // Not narrowed, not re-derived from the day the form is holding, and not dropped.
    expect(payload.recurrence.day).toEqual({ mode: 'weekdays', weekdays: [1] });
    expect(payload.recurrence.month).toBeNull();
    expect(payload.recurrence.until).toBe('2026-12-31');
    // Carried, never authored: a whole-event write that dropped this would resurrect the day
    // somebody removed one at a time.
    expect(payload.recurrence.exclusions).toEqual({ dates: ['2026-08-24'] });
    wrapper.unmount();
  });

  it('is a state of THIS clock only — the same rule on the grid’s own clock is recognised', async () => {
    // The control test. Same rule, same anchor weekday, stamped on the zone the grid is drawn in:
    // recognition succeeds and the select names the preset. Without this, the pair above would pass
    // just as happily against a control that could never recognise anything at all.
    storeMock.eventDetail = seriesEvent();
    const wrapper = mountDrawer({ mode: 'edit', scope: 'series' });
    await flushPromises();

    expect(text()).toContain('Every Monday');
    expect(text()).not.toContain('Another rule');
    wrapper.unmount();
  });
});

/**
 * WHEN THE SERIES STARTS IS RECKONED ON THE RULE'S CLOCK — ONE READING, NOT THREE.
 *
 * The drawer answers "which day does this series begin on" in three places: whether the named
 * occurrence is the first one, the "Series starts" row, and the month "Show the start of the
 * series" navigates to. They are the same question and were not getting the same answer — one
 * read the anchor on the STAMPED clock and two on the WINDOW's, so a workspace that changed
 * timezone after a series was written made the drawer NAME A DAY THE GRID HAS NO SQUARE ON.
 *
 * THE STAMPED CLOCK IS THE RIGHT ONE by the server's own rule: `EventCalendarSource` publishes
 * every `occurrence_date` as `$moment->setTimezone($rule->timezone())`. The rule's zone decides
 * which day an occurrence lands on, so anything that names one of those days is reckoned there
 * or it is naming something else.
 *
 * The visible damage is cosmetic — a date in a read-only row, a month button one month out. The
 * CAUSE is not: it is the same pair of drifting clocks that produced a blocking defect on the
 * server side of this chapter, and the fixture below is the shape that finds it.
 */
describe('EventDrawer — the series’ first day is read on the rule’s clock', () => {
  /**
   * A weekly series whose anchor instant falls on a different DAY *and* a different MONTH on
   * each clock. 21:00Z on 2026-08-31 is 23:00 on MONDAY 31 AUGUST in Europe/Warsaw (the window)
   * and 11:00 on TUESDAY 1 SEPTEMBER in Pacific/Kiritimati, UTC+14 (the rule's own stamp).
   *
   * The month boundary is the point: a start-of-series button computed on the wrong clock sends
   * the grid to a month whose squares do not include the one it promised.
   */
  function acrossMonthBoundary(): CalendarEvent {
    return event({
      starts_at: '2026-08-31T21:00:00+00:00',
      ends_at: '2026-08-31T22:00:00+00:00',
      recurrence: {
        day: { mode: 'weekdays', weekdays: [2] },
        month: null,
        exclusions: null,
        until: null,
      },
      recurrence_timezone: 'Pacific/Kiritimati',
      recurrence_label: 'Weekly on Tue',
    } as Partial<CalendarEvent>);
  }

  /** One meta row's VALUE, addressed by its label — `text()` would mix in the other rows. */
  function metaRow(label: string): string {
    const row = Array.from(panel().querySelectorAll('.next-description-list__item')).find(
      (el) => (el.querySelector('dt')?.textContent ?? '').trim() === label,
    );
    if (!row) throw new Error(`no meta row labelled "${label}"`);
    return row.querySelector('dd')?.textContent?.trim() ?? '';
  }

  /** The state in which the two navigation buttons render at all. */
  function seriesWithNothingOnScreen(detail: CalendarEvent): void {
    storeMock.eventDetail = detail;
    storeMock.loaded = true;
    storeMock.currentQuery = { sources: [] };
    storeMock.occurrences = [];
  }

  it('names the day the RULE puts the first occurrence on, not the day the window does', async () => {
    storeMock.eventDetail = acrossMonthBoundary();
    const wrapper = mountDrawer();
    await flushPromises();

    const start = metaRow('Series starts');
    expect(start).toContain('Tuesday');
    expect(start).toContain('September');
    // The window's reading of the same instant, which this row used to print.
    expect(start).not.toContain('Monday');
    expect(start).not.toContain('August');

    // The MOMENT is still shown on the window's clock, and must stay that way: it answers
    // "when is this, for me" rather than "which day is this series' first".
    expect(metaRow('When')).toContain('August');
    wrapper.unmount();
  });

  it('sends "show the start of the series" to the month the first square is in', async () => {
    seriesWithNothingOnScreen(acrossMonthBoundary());
    const wrapper = mountDrawer();
    await flushPromises();

    const button = buttonLabelled('Show the start of the series (September 2026)');
    expect(button).not.toBeNull();
    button?.click();
    await flushPromises();

    // `2026-08` would land the reader on a grid that does not contain the day just named.
    expect(wrapper.emitted('go-to-month')?.[0]).toEqual(['2026-09']);
    wrapper.unmount();
  });

  /**
   * The control test. The ordinary series — stamped on the zone the grid is drawn in — must
   * keep naming exactly the day it always did. Without this, both assertions above would pass
   * against a drawer that had simply started reading some other zone.
   */
  it('is unchanged for a rule stamped on the grid’s own clock', async () => {
    seriesWithNothingOnScreen(seriesEvent());
    const wrapper = mountDrawer();
    await flushPromises();

    // 12:30Z on 2026-08-10 = 14:30 Monday 10 August in Europe/Warsaw, on either reading.
    const start = metaRow('Series starts');
    expect(start).toContain('Monday');
    expect(start).toContain('August');
    expect(buttonLabelled('Show the start of the series (August 2026)')).not.toBeNull();
    wrapper.unmount();
  });

  /** An all-day series has no instant to reckon: its `start_date` is a zone-free day already. */
  it('leaves an all-day series’ zone-free day alone', async () => {
    seriesWithNothingOnScreen(
      seriesEvent({
        all_day: true,
        start_date: '2026-08-10',
        starts_at: null,
        ends_at: null,
        recurrence_timezone: 'Pacific/Kiritimati',
      } as Partial<CalendarEvent>),
    );
    const wrapper = mountDrawer();
    await flushPromises();

    expect(metaRow('Series starts')).toContain('August');
    expect(buttonLabelled('Show the start of the series (August 2026)')).not.toBeNull();
    wrapper.unmount();
  });
});

/**
 * THE SCOPE DIALOG REACHED WITHOUT A SQUARE — the deep-link path, end to end.
 *
 * `SeriesScopeModal.spec` pins the dialog's own behaviour; this pins that the drawer actually
 * puts it in that state, because the drawer is the only thing that decides whether an
 * `occurrenceDate` reaches it. A `?event=<id>` link, a search result, or a series with nothing
 * in the window on screen all arrive here with none.
 */
describe('EventDrawer — a series opened without a day', () => {
  it('offers only the scope that can succeed', async () => {
    storeMock.eventDetail = seriesEvent();
    const wrapper = mountDrawer({ occurrenceDate: null });
    await flushPromises();

    buttonLabelled('Delete')?.click();
    await flushPromises();

    const dialog = modal();
    expect(dialog).not.toBeNull();
    const scoped = (value: string) =>
      dialog?.querySelector<HTMLInputElement>(`[data-radio-value="${value}"]`);
    expect(scoped('occurrence')?.disabled).toBe(true);
    expect(scoped('following')?.disabled).toBe(true);
    expect(scoped('series')?.checked).toBe(true);
    // The reflex path: Enter on the default now asks for the one delete the server can perform.
    expect(modalButton('Delete the whole series')).not.toBeNull();
    expect(modalButton('Delete this occurrence')).toBeNull();
    wrapper.unmount();
  });

  /** The other direction: a square WAS clicked, so the narrow scopes are the point. */
  it('offers all three when a day was named', async () => {
    storeMock.eventDetail = seriesEvent();
    const wrapper = mountDrawer({ occurrenceDate: '2026-09-14' });
    await flushPromises();

    buttonLabelled('Delete')?.click();
    await flushPromises();

    expect(
      modal()?.querySelector<HTMLInputElement>('[data-radio-value="occurrence"]')?.disabled,
    ).toBe(false);
    expect(modalButton('Delete this occurrence')).not.toBeNull();
    wrapper.unmount();
  });
});
