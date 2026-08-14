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
    expect(panel().querySelector('[role="combobox"]')).toBeNull();
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
