// Platform-connections store for the isolated "next" frontend (Pinia setup store).
//
// A SEPARATE STORE FROM `publishing.ts`, and that is the point. The Connections screen is
// where somebody goes to repair an `APP_KEY`-rotation incident — the one failure that makes
// every stored token unreadable at once. A screen that shared its fetch lifecycle with the
// publications list would be a casualty of the very problem it exists to fix.
//
// Backend contract (VERIFIED — do NOT invent fields):
//   GET    /publishing/connections                        → { data: PlatformConnection[] }  (NOT paginated)
//   POST   /publishing/connections/{platform}/authorize    → { data: { authorize_url, expires_in } }
//                                                             + Set-Cookie (HttpOnly handshake cookie)
//   DELETE /publishing/connections/{id}                   → 204  (soft delete + the queue goes on hold)
import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { api } from '../lib/api';
import type {
  AuthorizationResponse,
  ConnectionListResponse,
  PlatformAuthorization,
  PlatformConnection,
  PublishingPlatform,
} from '../../pages/publishing/types';

export const usePublishingConnectionsStore = defineStore('next-publishing-connections', () => {
  const connections = ref<PlatformConnection[]>([]);
  const loading = ref(false);
  const loaded = ref(false);
  const errored = ref(false);
  const error = ref<string | null>(null);

  /** The platform whose handshake is starting — the button stays busy until we navigate. */
  const authorizing = ref<PublishingPlatform | null>(null);
  /** The connection id being disconnected. */
  const disconnecting = ref<string | null>(null);

  let token = 0;

  function messageOf(err: unknown): string | null {
    const data = (err as { response?: { data?: { message?: string } } })?.response?.data;
    return typeof data?.message === 'string' && data.message !== '' ? data.message : null;
  }

  /** Grouped by destination, client-side: every platform card needs every group at once. */
  const byPlatform = computed<Record<string, PlatformConnection[]>>(() => {
    const groups: Record<string, PlatformConnection[]> = {};
    for (const connection of connections.value) {
      (groups[connection.platform] ??= []).push(connection);
    }
    return groups;
  });

  /**
   * How many accounts need a person. `credentials_readable === false` counts even though the
   * server's `needs_attention` does not mention it: an account whose stored access cannot be
   * decrypted publishes nothing, and the module aside's small badge is the only way that is
   * visible from the publications screen.
   */
  const needsAttentionCount = computed(
    () =>
      connections.value.filter((c) => c.needs_attention || c.credentials_readable === false).length,
  );

  /** The APP_KEY incident: at least one account whose stored access cannot be read. */
  const hasUnreadableCredentials = computed(() =>
    connections.value.some((c) => c.credentials_readable === false),
  );

  /** Only the accounts a publication may actually go out on, for a given destination. */
  function publishableFor(platform: PublishingPlatform | null): PlatformConnection[] {
    if (!platform) return [];
    return connections.value.filter((c) => c.platform === platform && c.can_publish);
  }

  function find(id: string | null): PlatformConnection | null {
    if (!id) return null;
    return connections.value.find((c) => c.id === id) ?? null;
  }

  /**
   * The one in-flight request, shared by every caller that asks while it is running.
   *
   * FOUR SCREENS ASK FOR THIS LIST ON THE WAY IN — the module shell (for the aside badge),
   * the publications list, the detail, and the composer — and they mount within the same
   * tick. Without this, entering the module fired two or three identical `GET`s in a row.
   */
  let inFlight: Promise<void> | null = null;

  /**
   * Load the accounts. Idempotent by default, so the shared list costs one request per module
   * visit; `{ force: true }` is for the moments the data is KNOWN to have changed — the
   * Refresh button, a disconnect, and the return from a consent screen.
   */
  async function fetchConnections(options: { force?: boolean } = {}): Promise<void> {
    if (!options.force) {
      if (inFlight) return inFlight;
      if (loaded.value) return;
    }

    const myToken = (token += 1);
    loading.value = true;
    errored.value = false;
    error.value = null;

    const request = (async () => {
      try {
        const res = await api.get<ConnectionListResponse>('/publishing/connections');
        if (myToken !== token) return;
        connections.value = res.data ?? [];
        loaded.value = true;
      } catch (err) {
        if (myToken !== token) return;
        errored.value = true;
        error.value = messageOf(err);
        throw err;
      } finally {
        if (myToken === token) loading.value = false;
      }
    })();

    inFlight = request;
    // Released however it ends. The `catch` is for this bookkeeping chain only — the REAL
    // promise is the one returned, and its rejection still reaches the caller.
    void request
      .catch(() => undefined)
      .finally(() => {
        if (inFlight === request) inFlight = null;
      });

    return request;
  }

  /**
   * Start a handshake. Answers `{authorize_url, expires_in}` AND sets an `HttpOnly`,
   * `SameSite=Lax`, host-only cookie the browser must still be holding when the platform
   * redirects back.
   *
   * THE CALLER NAVIGATES THE WHOLE WINDOW to `authorize_url` — never `window.open`, never a
   * `fetch` that follows it. A popup does get the cookie, but it is blocked often enough to
   * matter, it is lost when the consent screen bounces through a second Google profile, and
   * the configured return path lands in the tab that STARTED the flow — which, with a popup,
   * is the one nobody is looking at. A `fetch` follow drops the headers a real handshake
   * needs from that point on.
   */
  async function authorize(platform: PublishingPlatform): Promise<PlatformAuthorization> {
    authorizing.value = platform;
    try {
      const res = await api.post<AuthorizationResponse>(
        `/publishing/connections/${platform}/authorize`,
      );
      return res.data;
    } catch (err) {
      // Only clear on FAILURE. On success the next thing that happens is a full-page
      // navigation, and a button that snaps back to idle first reads like nothing happened.
      authorizing.value = null;
      throw err;
    }
  }

  /**
   * Disconnect an account. Not a plain delete: in one transaction the server also puts every
   * `scheduled` publication on this connection ON HOLD — keeping its armed moment — and soft
   * deletes the row so already-published publications still resolve to the account they went
   * out on. Reconnecting the same account releases those holds and restores the moments.
   */
  async function disconnect(id: string): Promise<void> {
    disconnecting.value = id;
    try {
      await api.delete(`/publishing/connections/${id}`);
      // FORCED: the list just changed, and the idempotence guard would otherwise hand back
      // the copy that still contains the account we removed.
      await fetchConnections({ force: true }).catch(() => undefined);
    } finally {
      disconnecting.value = null;
    }
  }

  /**
   * Drop everything (a workspace switch). ACCOUNTS ARE PER WORKSPACE: without this the next
   * workspace's Connections screen would open on the previous one's accounts and its aside
   * badge would count somebody else's broken tokens. The token bump makes any response still
   * in flight land in nothing.
   */
  function resetAll(): void {
    token += 1;
    inFlight = null;
    connections.value = [];
    loading.value = false;
    loaded.value = false;
    errored.value = false;
    error.value = null;
    authorizing.value = null;
    disconnecting.value = null;
  }

  return {
    connections,
    loading,
    loaded,
    errored,
    error,
    authorizing,
    disconnecting,
    byPlatform,
    needsAttentionCount,
    hasUnreadableCredentials,
    publishableFor,
    find,
    fetchConnections,
    authorize,
    disconnect,
    resetAll,
  };
});
