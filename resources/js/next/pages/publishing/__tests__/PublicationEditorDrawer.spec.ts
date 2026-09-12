// @vitest-environment happy-dom
// PublicationEditorDrawer.spec — the two ways this form can quietly destroy a publication.
//
// ═════════════════════════════════════════════════════════════════════════════════════════
// 1. THE SEED MUST NOT LOOK LIKE A CHANGE OF DESTINATION
// ═════════════════════════════════════════════════════════════════════════════════════════
// Changing the destination clears the account — correctly, because a YouTube channel is not
// an Instagram page. But `seed()` assigns the destination and the account in the SAME tick,
// and the watcher runs post-flush: without its `seeding` guard, merely OPENING a publication
// for editing fires as if the reader had just changed the destination. The account the record
// came with is cleared, the banner announces it, and the next "Save draft" — a whole-row PUT —
// writes `platform_connection_id: null`. Somebody fixes a typo in a title and detaches the
// account the publication was going out on. Nothing refuses that write.
//
// So this file asserts on the PAYLOAD, through the form, not on internals.
//
// ═════════════════════════════════════════════════════════════════════════════════════════
// 2. A `PUT` IS A WHOLE-ROW WRITE
// ═════════════════════════════════════════════════════════════════════════════════════════
// `publicationPayload.spec.ts` pins the builder in isolation; this pins what the FORM hands
// it after a real load + a real edit — `options` this form never renders, `media` it only
// carries, and `scheduled_at` on an armed row, whose loss leaves a publication armed forever
// and going nowhere.
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { reactive } from 'vue';

// --- Router -----------------------------------------------------------------
const routerPush = vi.fn();
vi.mock('vue-router', () => ({ useRouter: () => ({ push: routerPush }) }));

// --- Stores -----------------------------------------------------------------
const fetchPublication = vi.fn();
const updatePublication = vi.fn();
const createPublication = vi.fn();
const loadTimezone = vi.fn();
const storeMock = reactive({
  items: [] as unknown[],
  timezone: 'Europe/Warsaw' as string | null,
  fetchPublication,
  updatePublication,
  createPublication,
  loadTimezone,
});
vi.mock('../../../app/stores/publishing', () => ({ usePublishingStore: () => storeMock }));

const fetchConnections = vi.fn();
const connectionsMock = reactive({
  connections: [] as PlatformConnection[],
  loaded: true,
  errored: false,
  fetchConnections,
  publishableFor: (platform: string | null) =>
    connectionsMock.connections.filter((c) => c.platform === platform && c.can_publish),
});
vi.mock('../../../app/stores/publishingConnections', () => ({
  usePublishingConnectionsStore: () => connectionsMock,
}));

const toast = { success: vi.fn(), danger: vi.fn(), info: vi.fn(), warning: vi.fn() };
vi.mock('../../../app/composables/useToast', () => ({ useToast: () => toast }));

const confirmMock = vi.fn();
vi.mock('../../../app/composables/useConfirm', () => ({ useConfirm: () => confirmMock }));

// --- Stubs ------------------------------------------------------------------
// The two selects become native <select>s, addressed by the aria-labels the real ones carry.
vi.mock('../../../ui/forms/Select.vue', () => ({
  default: {
    name: 'SelectStub',
    props: ['modelValue', 'options', 'ariaLabel', 'placeholder'],
    emits: ['update:modelValue'],
    template:
      '<select :aria-label="ariaLabel" :value="modelValue ?? \'\'" @change="$emit(\'update:modelValue\', $event.target.value || null)">' +
      '<option value=""></option>' +
      '<option v-for="o in options" :key="o.value" :value="o.value">{{ o.label }}</option>' +
      '</select>',
  },
}));
// A plain input: the picker's own parsing has its own specs and would only add ways for this
// file to fail for reasons that are not about the drawer.
vi.mock('../../../ui/forms/DateTimePicker.vue', () => ({
  default: {
    name: 'DateTimePickerStub',
    props: ['modelValue'],
    emits: ['update:modelValue'],
    template:
      '<input data-testid="dtp" :value="modelValue ?? \'\'" @input="$emit(\'update:modelValue\', $event.target.value || null)" />',
  },
}));
// The media field reads the Disk; here it only has to hold the array the drawer seeded.
vi.mock('../PublicationMediaField.vue', () => ({
  default: {
    name: 'PublicationMediaFieldStub',
    props: ['modelValue', 'disabled'],
    template: '<div data-testid="media">{{ (modelValue ?? []).join(",") }}</div>',
  },
}));

import PublicationEditorDrawer from '../PublicationEditorDrawer.vue';
import { setLocale } from '../../../app/i18n';
import type { PlatformConnection, Publication } from '../types';

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

function connection(overrides: Partial<PlatformConnection> = {}): PlatformConnection {
  return {
    id: 'c1',
    platform: 'youtube',
    platform_label: 'YouTube',
    external_account_id: 'UCxxxx',
    account_name: 'Taskio Demo',
    status: 'active',
    status_label: 'Connected',
    status_tone: 'success',
    needs_attention: false,
    can_publish: true,
    scopes: [],
    expires_at: null,
    last_refreshed_at: null,
    failure_code: null,
    credentials_readable: true,
    creator: null,
    is_owner: true,
    can_be_disconnected: true,
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

async function mountDrawer(record: Publication | null) {
  fetchPublication.mockResolvedValue(record);
  const wrapper = mount(PublicationEditorDrawer, {
    props: { publicationId: record?.id ?? null },
    global: { stubs: { SchedulePublicationModal: true, Teleport: true } },
  });
  await flushPromises();
  return wrapper;
}

type Wrapper = Awaited<ReturnType<typeof mountDrawer>>;

/** The title field — the only real `<input>` once the picker is a stub. */
function titleInput(wrapper: Wrapper) {
  const input = wrapper
    .findAll('input')
    .find((i) => i.attributes('data-testid') !== 'dtp');
  if (!input) throw new Error('the title input did not render');
  return input;
}

function select(wrapper: Wrapper, label: string) {
  return wrapper.find(`select[aria-label="${label}"]`);
}

function buttonLabelled(wrapper: Wrapper, label: RegExp) {
  return wrapper.findAll('button').find((b) => label.test(b.text()));
}

beforeEach(() => {
  setLocale('en');
  storeMock.items = [];
  storeMock.timezone = 'Europe/Warsaw';
  connectionsMock.connections = [connection()];
  connectionsMock.loaded = true;
  connectionsMock.errored = false;
  fetchPublication.mockReset();
  updatePublication.mockReset();
  createPublication.mockReset();
  loadTimezone.mockReset().mockResolvedValue(undefined);
  fetchConnections.mockReset().mockResolvedValue(undefined);
  routerPush.mockReset();
  confirmMock.mockReset().mockResolvedValue(true);
  toast.success.mockReset();
  toast.danger.mockReset();
  toast.warning.mockReset();
  toast.info.mockReset();
});

describe('loading a publication for editing', () => {
  it('KEEPS the account the record came with — the seed is not a change of destination', async () => {
    const record = publication({ platform: 'youtube', platform_connection_id: 'c1' });
    const wrapper = await mountDrawer(record);

    // Nothing announced: the reader changed nothing.
    expect(wrapper.text()).not.toContain('The account was cleared');
    // And the select still names it.
    expect(select(wrapper, 'Account').element.value).toBe('c1');

    updatePublication.mockResolvedValue(record);
    await buttonLabelled(wrapper, /Save draft/)!.trigger('click');
    await flushPromises();

    // THE ASSERTION THIS FILE EXISTS FOR: a whole-row PUT that still carries the account.
    expect(updatePublication).toHaveBeenCalledTimes(1);
    expect(updatePublication.mock.calls[0][1]).toMatchObject({ platform_connection_id: 'c1' });
  });

  it('DOES clear it — once, with a sentence — when the reader really changes destination', async () => {
    connectionsMock.connections = [
      connection(),
      connection({ id: 'c9', platform: 'facebook', platform_label: 'Facebook', account_name: 'Page' }),
    ];
    const wrapper = await mountDrawer(publication());

    await select(wrapper, 'Destination').setValue('facebook');
    await flushPromises();

    expect(wrapper.text()).toContain('The account was cleared');
    expect(select(wrapper, 'Account').element.value).toBe('');

    const saved = publication({ platform: 'facebook', platform_connection_id: null });
    updatePublication.mockResolvedValue(saved);
    await buttonLabelled(wrapper, /Save draft/)!.trigger('click');
    await flushPromises();

    expect(updatePublication.mock.calls[0][1]).toMatchObject({
      platform: 'facebook',
      platform_connection_id: null,
    });
  });
});

describe('the PUT carries the WHOLE row, assembled by the form', () => {
  it('keeps `options`, `media` and the armed moment while only the title changes', async () => {
    const record = publication({
      status: 'scheduled',
      status_label: 'Scheduled',
      status_tone: 'info',
      // 07:00Z is 09:00 in Europe/Warsaw — the wall clock this form edits, and the one the
      // server parses back on the workspace's clock.
      scheduled_at: '2026-09-10T07:00:00.000000Z',
      media: ['file-1', 'file-2'],
      options: { privacy: 'unlisted' },
      can_be_edited: true,
    });
    const wrapper = await mountDrawer(record);

    await titleInput(wrapper).setValue('Autumn teaser v2');

    updatePublication.mockResolvedValue({ ...record, title: 'Autumn teaser v2' });
    await buttonLabelled(wrapper, /Save draft/)!.trigger('click');
    await flushPromises();

    const [id, payload] = updatePublication.mock.calls[0];
    expect(id).toBe('p1');
    expect(payload).toEqual({
      title: 'Autumn teaser v2',
      body: 'Something new is coming.',
      platform: 'youtube',
      platform_connection_id: 'c1',
      // NOT null: a PUT without this on a `scheduled` row leaves it armed forever and going
      // nowhere, with no message anywhere.
      scheduled_at: '2026-09-10T09:00',
      // Carried, never rebuilt: an omitted `media` drops every attachment.
      media: ['file-1', 'file-2'],
      // The field this form does not render and must never lose (D13).
      options: { privacy: 'unlisted' },
    });
  });
});
