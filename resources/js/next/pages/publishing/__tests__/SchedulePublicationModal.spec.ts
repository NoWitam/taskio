// @vitest-environment happy-dom
// SchedulePublicationModal.spec — one button, two completely different events.
//
// ═════════════════════════════════════════════════════════════════════════════════════════
// `POST …/schedule` ANSWERS 200 FOR BOTH, AND ONLY THE BODY SAYS WHICH
// ═════════════════════════════════════════════════════════════════════════════════════════
// With an approval pipeline attached and no approval yet, the server does NOT arm: it parks
// the moment in `arm_on_approval_at`, opens a review, and answers 200 with the row still a
// `draft` and `is_in_approval: true`. "Scheduled for Friday" would then be a promise nobody
// made — the publication goes out when a PERSON says so, or never.
//
// The tempting shortcut is to pick the sentence from what this screen believed BEFORE the
// click (`approval_pipeline_id !== null`). That reading is stale by definition: a pipeline
// can be attached, or an approval concluded, between the row landing on screen and the button
// being pressed. So the outcome is read off the RESPONSE, and the test proves it by handing
// back a review outcome for a row this screen was sure it was arming.
//
// ═════════════════════════════════════════════════════════════════════════════════════════
// AND THE DIALOG MUST NOT VANISH MID-FLIGHT
// ═════════════════════════════════════════════════════════════════════════════════════════
// Escape and the scrim are blocked while the request is in the air: this is the one moment
// where dismissing the dialog leaves somebody with no answer to "did something just start
// going out into the world?". A refusal about the moment stays AT THE FIELD for the same
// reason — a toast in the corner about a date, with the date still on screen and looking
// fine, is a correction nobody connects to anything.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { reactive } from 'vue';

// --- Stores -----------------------------------------------------------------
const schedulePublication = vi.fn();
const updatePublication = vi.fn();
const fetchPublication = vi.fn();
const storeMock = reactive({ schedulePublication, updatePublication, fetchPublication });
vi.mock('../../../app/stores/publishing', () => ({ usePublishingStore: () => storeMock }));

const toast = { success: vi.fn(), danger: vi.fn(), info: vi.fn(), warning: vi.fn() };
vi.mock('../../../app/composables/useToast', () => ({ useToast: () => toast }));

const confirmMock = vi.fn();
vi.mock('../../../app/composables/useConfirm', () => ({ useConfirm: () => confirmMock }));

// A plain input: the picker's own parsing has its own specs and would only add ways for this
// file to fail for reasons that are not about the modal.
vi.mock('../../../ui/forms/DateTimePicker.vue', () => ({
  default: {
    name: 'DateTimePickerStub',
    props: ['modelValue'],
    emits: ['update:modelValue'],
    template:
      '<input data-testid="dtp" :value="modelValue ?? \'\'" @input="$emit(\'update:modelValue\', $event.target.value || null)" />',
  },
}));

import SchedulePublicationModal from '../SchedulePublicationModal.vue';
import { setLocale } from '../../../app/i18n';
import type { Publication } from '../types';

function publication(overrides: Partial<Publication> = {}): Publication {
  return {
    id: 'p1',
    title: 'Autumn teaser',
    body: 'Something new is coming.',
    platform: 'youtube',
    platform_label: 'YouTube',
    publishes_publicly: true,
    platform_connection_id: 'c1',
    status: 'draft',
    status_label: 'Draft',
    status_tone: null,
    needs_attention: false,
    scheduled_at: null,
    published_at: null,
    media: [],
    options: {},
    remote_id: null,
    remote_url: null,
    attempts: 0,
    last_attempt_at: null,
    failure_code: null,
    failure_context: null,
    approval_pipeline_id: null,
    is_in_approval: false,
    approval_state: null,
    intended_publish_at: null,
    creator: null,
    is_owner: true,
    can_be_edited: true,
    can_be_deleted: true,
    can_be_scheduled: true,
    can_be_reconciled: false,
    created_at: '2026-09-01T10:00:00.000000Z',
    updated_at: '2026-09-01T10:00:00.000000Z',
    ...overrides,
  };
}

/** The Modal teleports to <body>, so every query below reads the document. */
function dialog(): HTMLElement | null {
  return document.querySelector('[role="dialog"]');
}

function bodyText(): string {
  return document.body.textContent ?? '';
}

function buttonLabelled(label: RegExp): HTMLButtonElement {
  const found = Array.from(document.querySelectorAll('button')).find((b) =>
    label.test(b.textContent ?? ''),
  );
  if (!found) throw new Error(`no button matching ${label} — found: ${
    Array.from(document.querySelectorAll('button')).map((b) => b.textContent?.trim()).join(' | ')
  }`);
  return found as HTMLButtonElement;
}

/** Type a wall-clock moment into the (stubbed) picker. */
async function setMoment(value: string): Promise<void> {
  const input = document.querySelector('[data-testid="dtp"]') as HTMLInputElement;
  input.value = value;
  input.dispatchEvent(new Event('input'));
  await flushPromises();
}

async function mountModal(record: Publication = publication()) {
  const wrapper = mount(SchedulePublicationModal, {
    props: { publication: record, timezone: 'Europe/Warsaw' },
    attachTo: document.body,
  });
  await flushPromises();
  return wrapper;
}

beforeEach(() => {
  setLocale('en');
  schedulePublication.mockReset();
  updatePublication.mockReset();
  fetchPublication.mockReset();
  confirmMock.mockReset().mockResolvedValue(true);
  toast.success.mockReset();
  toast.danger.mockReset();
  toast.info.mockReset();
  toast.warning.mockReset();
});

afterEach(() => {
  document.body.innerHTML = '';
});

describe('outcome 1 — the publication is ARMED', () => {
  it('says so with the moment, hands the row up, and closes', async () => {
    const armed = publication({ status: 'scheduled', scheduled_at: '2026-09-10T07:00:00.000000Z' });
    schedulePublication.mockResolvedValue(armed);

    const wrapper = await mountModal();
    await setMoment('2026-09-10T09:00');
    buttonLabelled(/^Schedule$/).click();
    await flushPromises();

    expect(schedulePublication).toHaveBeenCalledWith('p1', '2026-09-10T09:00');
    expect(toast.success.mock.calls[0][0]).toMatch(/^Scheduled for /);
    expect(wrapper.emitted('done')?.[0]?.[0]).toMatchObject({ status: 'scheduled' });
    expect(wrapper.emitted('close')).toHaveLength(1);
  });
});

describe('outcome 2 — the publication went TO A REVIEWER', () => {
  it('is told apart by the RESPONSE, not by what this screen believed before the click', async () => {
    // The row on screen carries NO pipeline, so the modal opened saying "Schedule"…
    const beforeClick = publication({ approval_pipeline_id: null });
    // …and the server answers that a review now holds it. (A pipeline was attached, or an
    // approval concluded, between the read and the write.)
    schedulePublication.mockResolvedValue(
      publication({
        status: 'draft',
        approval_pipeline_id: 'pipe-1',
        is_in_approval: true,
        approval_state: 'pending',
        intended_publish_at: '2026-09-10T07:00:00.000000Z',
      }),
    );

    const wrapper = await mountModal(beforeClick);
    expect(bodyText()).toContain('Schedule the publication');

    await setMoment('2026-09-10T09:00');
    buttonLabelled(/^Schedule$/).click();
    await flushPromises();

    // NOT "Scheduled for…": nobody has agreed to this going out yet.
    expect(toast.success.mock.calls[0][0]).toMatch(/^Sent for approval for /);
    expect(toast.success.mock.calls[0][0]).not.toMatch(/^Scheduled/);
    expect(wrapper.emitted('done')?.[0]?.[0]).toMatchObject({ is_in_approval: true });
  });

  it('drops the moment clause when the response carries none', async () => {
    schedulePublication.mockResolvedValue(
      publication({ is_in_approval: true, intended_publish_at: null, scheduled_at: null }),
    );

    await mountModal();
    await setMoment('2026-09-10T09:00');
    buttonLabelled(/^Schedule$/).click();
    await flushPromises();

    expect(toast.success).toHaveBeenCalledWith('Sent for approval');
  });

  it('overrules the screen in the OTHER direction too — a concluded review that armed it', async () => {
    // The mirror of the test above, and the half a stale read gets right by accident. The row
    // on screen has a pipeline attached and unapproved, so the modal opened saying "Send for
    // approval" — and between the read and the write the approval concluded, so the server
    // ARMED it and answers with a `scheduled` row. Announcing "Sent for approval" would tell
    // somebody to go and chase a decision that has already been made.
    const withPipeline = publication({ approval_pipeline_id: 'pipe-1', approval_state: null });
    schedulePublication.mockResolvedValue(
      publication({
        status: 'scheduled',
        approval_pipeline_id: 'pipe-1',
        approval_state: 'approved',
        is_in_approval: false,
        scheduled_at: '2026-09-10T07:00:00.000000Z',
      }),
    );

    await mountModal(withPipeline);
    await setMoment('2026-09-10T09:00');
    buttonLabelled(/^Send for approval$/).click();
    await flushPromises();

    expect(toast.success.mock.calls[0][0]).toMatch(/^Scheduled for /);
    expect(toast.success.mock.calls[0][0]).not.toMatch(/approval/);
  });

  it('names the act honestly UP FRONT when the screen already knows a review is next', async () => {
    // A pipeline attached and unapproved: the button must not say "Schedule".
    const withPipeline = publication({ approval_pipeline_id: 'pipe-1', approval_state: null });
    await mountModal(withPipeline);

    expect(bodyText()).toContain('Send for approval');
    expect(bodyText()).toContain('Approving it is what schedules it');
    // "Publish now" has no meaning when a person still has to say yes.
    expect(
      Array.from(document.querySelectorAll('button')).some((b) => /Publish now/.test(b.textContent ?? '')),
    ).toBe(false);
  });
});

describe('a refusal ABOUT THE MOMENT stays at the field', () => {
  it('renders the server’s sentence under the picker and keeps the dialog open', async () => {
    const serverSentence = 'The time must be in the future.';
    schedulePublication.mockRejectedValue({
      response: {
        status: 422,
        data: { message: 'Invalid data.', errors: { scheduled_at: [serverSentence] } },
        headers: {},
      },
    });

    const wrapper = await mountModal();
    await setMoment('2020-01-01T09:00');
    buttonLabelled(/^Schedule$/).click();
    await flushPromises();

    // THE ASSERTION THIS BLOCK EXISTS FOR: the dialog is still there, with the moment the
    // person typed still in it, and the correction beside the control that produced it.
    expect(dialog()).not.toBeNull();
    expect(bodyText()).toContain(serverSentence);
    expect(wrapper.emitted('close')).toBeUndefined();
    expect(wrapper.emitted('done')).toBeUndefined();
    // A field problem is not a toast.
    expect(toast.danger).not.toHaveBeenCalled();
  });

  it('refuses an EMPTY moment itself — arming would otherwise have to invent one', async () => {
    const wrapper = await mountModal();
    buttonLabelled(/^Schedule$/).click();
    await flushPromises();

    expect(schedulePublication).not.toHaveBeenCalled();
    expect(dialog()).not.toBeNull();
    expect(wrapper.emitted('done')).toBeUndefined();
  });
});

describe('while the request is in the air the dialog cannot be dismissed', () => {
  /** Start a request that never settles, leaving the modal in its in-flight state. */
  async function submitAndHang() {
    schedulePublication.mockReturnValue(new Promise(() => {}));
    const wrapper = await mountModal();
    await setMoment('2026-09-10T09:00');
    buttonLabelled(/^Schedule$/).click();
    await flushPromises();
    return wrapper;
  }

  function clickScrim(): void {
    // The scrim is the `aria-hidden` sheet behind the panel.
    const scrim = document.querySelector('.next-overlay-root [aria-hidden="true"]') as HTMLElement;
    scrim.click();
  }

  it('ignores a click on the SCRIM mid-flight, and honours it when nothing is pending', async () => {
    const busy = await submitAndHang();
    clickScrim();
    await flushPromises();
    expect(dialog(), 'mid-flight').not.toBeNull();
    expect(busy.emitted('close'), 'mid-flight').toBeUndefined();
    busy.unmount();
    document.body.innerHTML = '';

    // The same gesture on the same dialog with nothing pending — so the assertion above is
    // about the FLIGHT and not about a scrim that never worked.
    const idle = await mountModal();
    clickScrim();
    await flushPromises();
    expect(idle.emitted('close'), 'idle').toHaveLength(1);
  });

  it('blocks Escape mid-flight and honours it when idle — as a real keypress', async () => {
    // This used to be a prop-only assertion, with a comment explaining why a keypress test
    // would pass from nothing: `Modal.vue` registered inside `watch(open, …)` with no
    // `immediate`, so an overlay ALREADY open at first render (this modal's exact shape —
    // `open = ref(true)`, parent gates with `v-if`) never registered for Escape at all.
    // The primitive is fixed (`immediate: true` + guarded teardown; see Modal.spec.ts,
    // "mounted-already-open"), so the promise in that comment is kept: end-to-end keydown.
    const pressEscape = () =>
      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));

    const busy = await submitAndHang();
    pressEscape();
    await flushPromises();
    expect(busy.emitted('close'), 'mid-flight Escape must be ignored').toBeFalsy();
    busy.unmount();
    document.body.innerHTML = '';

    const idle = await mountModal();
    pressEscape();
    await flushPromises();
    expect(idle.emitted('close'), 'idle Escape closes').toHaveLength(1);
  });

  it('keeps Cancel and "Publish now" inert while submitting, so only one request can start', async () => {
    const busy = await submitAndHang();

    expect(buttonLabelled(/Cancel/).disabled).toBe(true);
    expect(buttonLabelled(/Publish now/).disabled).toBe(true);
    // The primary reports itself busy rather than looking idle.
    expect(buttonLabelled(/^Schedule$/).getAttribute('aria-busy')).toBe('true');

    buttonLabelled(/Cancel/).click();
    await flushPromises();
    expect(busy.emitted('close')).toBeUndefined();
  });
});

describe('changing the time on an ARMED row', () => {
  it('is a whole-row PUT, never a second `POST …/schedule`', async () => {
    const armed = publication({
      status: 'scheduled',
      scheduled_at: '2026-09-10T07:00:00.000000Z',
      media: ['file-1'],
      options: { privacy: 'unlisted' },
    });
    updatePublication.mockResolvedValue({ ...armed, scheduled_at: '2026-09-11T07:00:00.000000Z' });

    const wrapper = await mountModal(armed);
    await setMoment('2026-09-11T09:00');
    buttonLabelled(/^Change the time$/).click();
    await flushPromises();

    // `scheduled → scheduled` is not an edge; the state machine would refuse it.
    expect(schedulePublication).not.toHaveBeenCalled();
    // And the PUT carries the WHOLE row: an omitted key is nulled in the database.
    expect(updatePublication.mock.calls[0][1]).toEqual({
      title: 'Autumn teaser',
      body: 'Something new is coming.',
      platform: 'youtube',
      platform_connection_id: 'c1',
      scheduled_at: '2026-09-11T09:00',
      media: ['file-1'],
      options: { privacy: 'unlisted' },
    });
    expect(toast.success).toHaveBeenCalledWith('Time changed');
    expect(wrapper.emitted('done')).toHaveLength(1);
  });
});
