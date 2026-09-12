// @vitest-environment happy-dom
// PublicationDetailView.spec — the THREE different answers to "which account?" (§16.4 pkt 8).
//
// ═════════════════════════════════════════════════════════════════════════════════════════
// "NOT CHOSEN", "DISCONNECTED" AND "COULD NOT BE LOADED" ARE THREE FACTS, NOT ONE
// ═════════════════════════════════════════════════════════════════════════════════════════
// All three render as "the Account row has no account name in it", which is exactly why they
// collapse into one sentence the moment somebody tidies this up — and each collapse sends the
// reader somewhere useless:
//
//   • NOT CHOSEN (`platform_connection_id === null`). Nobody picked an account. The remedy is
//     the composer, and "Not chosen" says so.
//   • DISCONNECTED (an id that the LOADED, un-errored list does not contain). The account is
//     gone: `PlatformConnectionService::index()` queries without `withTrashed()`, and
//     disconnecting soft-deletes the row. This is the screen somebody opens to find out WHY a
//     publication is on hold, and "Not chosen" here sends them to the composer to pick an
//     account that is not in that list either — a loop with no exit.
//   • COULD NOT BE LOADED (the same absence, but the list errored or never arrived). Our
//     REQUEST failed. Saying "The account was disconnected" accuses the workspace of losing an
//     account it still has, on the strength of one failed GET — and the reader may then go and
//     reconnect a working account, which puts its publications on hold for real.
//
// The discriminator between the last two is entirely in the CONNECTIONS store's `loaded` /
// `errored`, not in the publication, so nothing about the row on screen hints at which
// sentence is right. That is what makes this worth a mounted test rather than a comment.
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { reactive } from 'vue';

// --- Router -----------------------------------------------------------------
const routeMock = reactive({
  params: { id: 'p1' } as Record<string, string>,
  name: 'next.publishing.publication.overview',
  query: {} as Record<string, unknown>,
});
vi.mock('vue-router', () => ({
  useRoute: () => routeMock,
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
}));

// --- Stores -----------------------------------------------------------------
const fetchPublication = vi.fn();
const storeMock = reactive({
  detail: null as Publication | null,
  detailLoading: false,
  detailError: null as string | null,
  detailStatus: null as number | null,
  timezone: 'Europe/Warsaw' as string | null,
  fetchPublication,
  loadTimezone: vi.fn().mockResolvedValue(undefined),
  upsertIntoList: vi.fn(),
  deletePublication: vi.fn(),
});
vi.mock('../../../app/stores/publishing', () => ({ usePublishingStore: () => storeMock }));

const connectionsMock = reactive({
  connections: [] as PlatformConnection[],
  loaded: true,
  errored: false,
  fetchConnections: vi.fn().mockResolvedValue(undefined),
  find: (id: string | null) => connectionsMock.connections.find((c) => c.id === id) ?? null,
});
vi.mock('../../../app/stores/publishingConnections', () => ({
  usePublishingConnectionsStore: () => connectionsMock,
}));

vi.mock('../../../app/composables/useToast', () => ({
  useToast: () => ({ success: vi.fn(), danger: vi.fn(), info: vi.fn(), warning: vi.fn() }),
}));
vi.mock('../../../app/composables/useConfirm', () => ({ useConfirm: () => vi.fn() }));

import PublicationDetailView from '../PublicationDetailView.vue';
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
    status: 'blocked',
    status_label: 'On hold',
    status_tone: 'warning',
    needs_attention: true,
    scheduled_at: '2026-09-10T07:00:00.000000Z',
    published_at: null,
    media: [],
    options: {},
    remote_id: null,
    remote_url: null,
    attempts: 0,
    last_attempt_at: null,
    failure_code: 'connection_disconnected',
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

/** The three sentences, so a test can assert one AND the absence of the other two. */
const SENTENCES = {
  disconnected: 'The account was disconnected',
  notChosen: 'Not chosen',
  unknown: 'the account could not be loaded',
};

async function mountDetail(record: Publication) {
  storeMock.detail = record;
  fetchPublication.mockResolvedValue(record);
  const wrapper = mount(PublicationDetailView, {
    global: {
      stubs: {
        RouterLink: true,
        // The band, the panels and the composer all have their own specs; here they would
        // only add ways for this file to fail for reasons that are not about the account row.
        PublicationStatusBand: true,
        ReconcilePanel: true,
        PublicationApprovalPanel: true,
        PublicationMediaField: true,
        SchedulePublicationModal: true,
      },
    },
  });
  await flushPromises();
  return wrapper;
}

/** Exactly one of the three sentences may be on screen. */
function assertOnly(text: string, expected: keyof typeof SENTENCES): void {
  for (const [name, sentence] of Object.entries(SENTENCES)) {
    if (name === expected) expect(text, name).toContain(sentence);
    else expect(text, name).not.toContain(sentence);
  }
}

beforeEach(() => {
  setLocale('en');
  routeMock.params = { id: 'p1' };
  routeMock.name = 'next.publishing.publication.overview';
  storeMock.detail = null;
  storeMock.detailLoading = false;
  storeMock.detailError = null;
  storeMock.detailStatus = null;
  storeMock.timezone = 'Europe/Warsaw';
  connectionsMock.connections = [];
  connectionsMock.loaded = true;
  connectionsMock.errored = false;
  fetchPublication.mockReset();
});

describe('the account the publication goes out on', () => {
  it('names it, with the server’s own status prose, when the list has it', async () => {
    connectionsMock.connections = [connection()];
    const wrapper = await mountDetail(publication());

    expect(wrapper.text()).toContain('Taskio Demo');
    expect(wrapper.text()).toContain('Connected');
    // None of the three absences applies.
    for (const sentence of Object.values(SENTENCES)) {
      expect(wrapper.text()).not.toContain(sentence);
    }
  });

  it('says DISCONNECTED when the loaded list does not contain the named account', async () => {
    // The row names `c1`; the list answered, without error, and `c1` is not in it — the index
    // never returns soft-deleted connections, so it was disconnected.
    connectionsMock.connections = [connection({ id: 'c-other', account_name: 'Another' })];
    connectionsMock.loaded = true;
    connectionsMock.errored = false;

    const wrapper = await mountDetail(publication({ platform_connection_id: 'c1' }));
    assertOnly(wrapper.text(), 'disconnected');
  });

  it('says NOT CHOSEN only when the row genuinely names nobody', async () => {
    const wrapper = await mountDetail(publication({ platform_connection_id: null }));
    assertOnly(wrapper.text(), 'notChosen');
  });

  it('says COULD NOT BE LOADED when the list ERRORED — never that an account is gone', async () => {
    connectionsMock.connections = [];
    connectionsMock.loaded = false;
    connectionsMock.errored = true;

    const wrapper = await mountDetail(publication({ platform_connection_id: 'c1' }));
    assertOnly(wrapper.text(), 'unknown');
  });

  it('says the same while the list simply has not arrived yet', async () => {
    // `loaded: false` with no error: the request is still out. An absence that has not been
    // established is not a fact about the account.
    connectionsMock.connections = [];
    connectionsMock.loaded = false;
    connectionsMock.errored = false;

    const wrapper = await mountDetail(publication({ platform_connection_id: 'c1' }));
    assertOnly(wrapper.text(), 'unknown');
  });

  it('marks the disconnected account as a WARNING, so colour is not the only signal either', async () => {
    connectionsMock.connections = [connection({ id: 'c-other' })];
    const wrapper = await mountDetail(publication({ platform_connection_id: 'c1' }));

    const badge = wrapper
      .findAll('.next-badge')
      .find((b) => b.text().includes(SENTENCES.disconnected));
    expect(badge).toBeDefined();
    expect(badge!.attributes('class')).toContain('bg-next-warning-subtle');
  });

  it('offers no account sentence at all for a REHEARSAL that names nobody', async () => {
    // `dry_run` has nothing to connect; "Not chosen" would be three words about something
    // nobody tried to do.
    const wrapper = await mountDetail(
      publication({
        platform: 'dry_run',
        platform_label: 'Test run',
        publishes_publicly: false,
        platform_connection_id: null,
      }),
    );
    for (const sentence of Object.values(SENTENCES)) {
      expect(wrapper.text()).not.toContain(sentence);
    }
    expect(wrapper.text()).toContain('—');
  });
});

describe('a failure to load the publication itself', () => {
  it('answers a 404 concretely, not with "something went wrong"', async () => {
    storeMock.detail = null;
    storeMock.detailStatus = 404;
    fetchPublication.mockRejectedValue({ response: { status: 404 } });

    const wrapper = mount(PublicationDetailView, {
      global: { stubs: { RouterLink: true, PublicationStatusBand: true, ReconcilePanel: true } },
    });
    await flushPromises();

    expect(wrapper.text()).toContain('No such publication');
    expect(wrapper.text()).not.toContain('The publications could not be loaded');
  });
});
