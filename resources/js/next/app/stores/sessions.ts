// Sessions store for the isolated "next" frontend (Pinia setup store).
//
// Owns SERVER STATE for the generation SESSION surface (R2 Generator / Templatki, sub-stage 2b): the
// workspace-level cursor-paginated LIST plus the CRUD actions and the async GENERATE / refine actions.
// A run that returns `generating` settles via the WEBSOCKET wait in `session/useSessionSettle.ts`
// (re-fetch on the `.generation-session.updated` push) — this store no longer polls. The PAGE
// owns the filter state and passes it in; this store fetches, appends, tracks cursor/loading/error, and
// reconciles the list IN PLACE after a write (ordered by created_at DESC, mirroring the backend
// `orderByDesc('created_at')`) so the UI updates without a full refetch. Mirrors `stores/templates.ts` /
// `stores/bots.ts`.
//
// The CHAT surface also needs two READ-ONLY lookups the session wire does not itself carry: the part
// SHAPES (from the content-type registry) and the slot DESCRIPTORS (from the source template). Both are
// exposed here so the detail view depends on ONE store.
//
// Backend contract (VERIFIED — do NOT invent fields):
//   GET    /generator/sessions?search=&status=&cursor=  (cursorPaginate(20), created_at desc)
//     → { data: Session[], meta: { next_cursor } }   NO `total`.
//   POST   /generator/sessions   { template_id, name?, slot_values? }   → { data: Session }   (201)
//   GET    /generator/sessions/{id}                                     → { data: Session }
//   PATCH  /generator/sessions/{id}  { name?, slot_values? }            → { data: Session }   (200; 422 if not draft/ready)
//   POST   /generator/sessions/{id}/generate                           → { data: Session }   (202; 409 if already generating)
//   DELETE /generator/sessions/{id}                                    → { message }          (200)
//   GET    /generator/content-types                                    → { data: ContentTypeDefinition[] }
//   GET    /generator/templates/{id}                                   → { data: Template }
//   POST   /generator/sessions/{id}/parts/{partKey}/save-to-disk  { name?, folder_id? }  → 201 { data: File }
//     Promote a PRODUCED image onto the user's Disk (R2 sub-stage 2c); 403 non-owner, 404 absent/foreign.
//   R2 sub-stage 2d — the per-part refine loop + archive (all reconcile list + detail with the returned Session):
//   POST   /generator/sessions/{id}/parts/{partKey}/regenerate            → 202 { data: Session }  (session now `generating`; caller WAITS for the ws event; 409 mid-run)
//   POST   /generator/sessions/{id}/parts/{partKey}/refine { instruction } → 202 { data: Session }  (caller WAITS for the ws event; 422 blank/non-refinable; 409 mid-run)
//   POST   /generator/sessions/{id}/parts/{partKey}/undo                  → 200 { data: Session }  (SYNCHRONOUS — no poll; 409 mid-run / nothing to undo)
//   POST   /generator/sessions/{id}/archive | /unarchive                 → 200 { data: Session }  (sets/clears `is_archived`)
import { defineStore } from 'pinia';
import { ref } from 'vue';
import { api } from '../lib/api';
import type {
  Session,
  SessionCreatePayload,
  SessionDelegateResponse,
  SessionFilters,
  SessionListResponse,
  SessionResponse,
  SessionUpdatePayload,
  SlotFillMode,
  SlotFillReport,
} from '../../pages/generator/sessionTypes';
import type {
  ContentTypeDefinition,
  ContentTypesResponse,
  Template,
  TemplateResponse,
} from '../../pages/generator/types';
import type { DiskFile } from '../../pages/disk/types';

/** Optional flags for a fetch (reset clears the list + cursor first). */
interface FetchOptions {
  reset?: boolean;
}

/**
 * Serialize the page's filter object into URLSearchParams. Mirrors the backend query 1:1: `search`
 * (name) + a SINGLE `status`; undefined / null / '' are skipped. (cursor is added by the caller.)
 */
export function serializeFilters(filters: SessionFilters): URLSearchParams {
  const params = new URLSearchParams();
  if (filters.search != null && filters.search !== '') {
    params.append('search', String(filters.search));
  }
  if (filters.status != null && filters.status !== ('' as unknown)) {
    params.append('status', String(filters.status));
  }
  return params;
}

/** Pull a human message out of an axios error (best-effort). */
function extractMessage(err: unknown): string {
  const res = (err as { response?: { data?: { message?: string } } })?.response;
  return res?.data?.message ?? 'generator.sessions.errors.description';
}

/**
 * The AI-budget refusal signals now live in `app/lib/aiBudget.ts` (promoted in B15a): three modules
 * spend against the same workspace cap, and the Knowledge composer may not import from this store.
 * RE-EXPORTED so every existing caller here keeps working unchanged — same constant, same function.
 */
export { AI_BUDGET_ERROR_CODE, isBudgetError } from '../lib/aiBudget';

export const useSessionsStore = defineStore('next-sessions', () => {
  // --- List state ----------------------------------------------------------
  const items = ref<Session[]>([]);
  const cursor = ref<string | null>(null);
  const hasMore = ref(true);
  const loading = ref(false);
  const loadingMore = ref(false);
  const errored = ref(false);
  const error = ref<string | null>(null);
  /**
   * Append (load-more) failure is tracked SEPARATELY from the first-page `errored`
   * so a failed page can be retried: on an append error we keep `hasMore`/`cursor`
   * intact and only set this flag, which pauses the infinite-scroll sentinel.
   */
  const loadMoreErrored = ref(false);

  // --- Detail state (the open chat session) --------------------------------
  const detail = ref<Session | null>(null);

  // Request token: a reset always supersedes work in flight.
  let token = 0;

  // --- List helpers --------------------------------------------------------
  /** Replace a session in the list in place (after an update / generate / poll). */
  function replaceInList(session: Session): void {
    const idx = items.value.findIndex((s) => s.id === session.id);
    if (idx >= 0) {
      const next = [...items.value];
      next[idx] = session;
      items.value = next;
    }
  }

  /** Remove a session from the list (after a delete). */
  function removeFromList(id: string): void {
    items.value = items.value.filter((s) => s.id !== id);
  }

  /** Keep the list + the open detail cache in sync after any single-session read/write. */
  function reconcile(session: Session): void {
    replaceInList(session);
    if (detail.value && detail.value.id === session.id) detail.value = session;
  }

  // --- List actions --------------------------------------------------------
  /**
   * Fetch one page with the given `filters`. With `{ reset: true }` (the default for a
   * filter change) the list + cursor are cleared first; otherwise the page is appended.
   */
  async function fetchSessions(
    filters: SessionFilters = {},
    { reset = true }: FetchOptions = {},
  ): Promise<void> {
    if (!reset && (loadingMore.value || loading.value || !hasMore.value)) return;

    const myToken = (token += 1);
    if (reset) {
      loading.value = true;
      items.value = [];
      cursor.value = null;
      hasMore.value = true;
    } else {
      loadingMore.value = true;
    }
    errored.value = false;
    error.value = null;
    loadMoreErrored.value = false;

    try {
      const params = serializeFilters(filters);
      if (cursor.value && !reset) params.set('cursor', cursor.value);

      const qs = params.toString();
      const response = await api.get<SessionListResponse>(`/generator/sessions${qs ? `?${qs}` : ''}`);
      if (myToken !== token) return; // superseded by a newer reset

      const incoming = response.data ?? [];
      items.value = reset ? incoming : [...items.value, ...incoming];
      cursor.value = response.meta?.next_cursor ?? null;
      hasMore.value = (response.meta?.next_cursor ?? null) !== null;
    } catch (err: unknown) {
      if (myToken !== token) return;
      error.value = extractMessage(err);
      if (reset) {
        errored.value = true;
        hasMore.value = false;
      } else {
        loadMoreErrored.value = true;
      }
    } finally {
      if (myToken === token) {
        loading.value = false;
        loadingMore.value = false;
      }
    }
  }

  /** Append the next page (infinite scroll). */
  async function loadMore(filters: SessionFilters = {}): Promise<void> {
    await fetchSessions(filters, { reset: false });
  }

  /** Retry a failed append (clears the pause flag, re-fetches the same page). */
  async function retryLoadMore(filters: SessionFilters = {}): Promise<void> {
    loadMoreErrored.value = false;
    await fetchSessions(filters, { reset: false });
  }

  /** Drop all cached list + detail state. */
  function resetAll(): void {
    items.value = [];
    cursor.value = null;
    hasMore.value = true;
    loading.value = false;
    loadingMore.value = false;
    errored.value = false;
    error.value = null;
    loadMoreErrored.value = false;
    detail.value = null;
  }

  // --- Detail read ----------------------------------------------------------
  /** Fetch a single session (`GET /generator/sessions/{id}`); caches it as `detail` + reconciles the list. */
  async function fetchSession(id: string): Promise<Session> {
    const res = await api.get<SessionResponse>(`/generator/sessions/${id}`);
    detail.value = res.data;
    replaceInList(res.data);
    return res.data;
  }

  // --- Create / update / delete --------------------------------------------
  /** Create a session from a template (`POST /generator/sessions`). Prepends (created_at desc). */
  async function createSession(payload: SessionCreatePayload): Promise<Session> {
    const res = await api.post<SessionResponse>('/generator/sessions', payload);
    const created = res.data;
    if (items.value.length > 0 || cursor.value !== null || !hasMore.value) {
      items.value = [created, ...items.value];
    }
    return created;
  }

  /** Edit a session's inputs (`PATCH /generator/sessions/{id}`). Reconciles list + detail. */
  async function patchSession(id: string, payload: SessionUpdatePayload): Promise<Session> {
    const res = await api.patch<SessionResponse>(`/generator/sessions/${id}`, payload);
    reconcile(res.data);
    return res.data;
  }

  /** Delete a session (`DELETE /generator/sessions/{id}`). Drops it from the list + detail. */
  async function deleteSession(id: string): Promise<void> {
    await api.delete<{ message: string }>(`/generator/sessions/${id}`);
    removeFromList(id);
    if (detail.value && detail.value.id === id) detail.value = null;
  }

  // --- Generate (async run) -------------------------------------------------
  /**
   * Kick off a WHOLE-SESSION run (`POST /generator/sessions/{id}/generate`) — 202 with the session now
   * `generating`. Reconciles list + detail with the returned state. A 409 (already generating) rejects;
   * the caller surfaces it. Callers then WAIT for the terminal websocket event via `useSessionSettle`
   * (`session/useSessionSettle.ts`) and re-fetch — the store no longer polls.
   */
  async function generate(id: string): Promise<Session> {
    const res = await api.post<SessionResponse>(`/generator/sessions/${id}/generate`);
    reconcile(res.data);
    return res.data;
  }

  // --- Per-part refine loop + archive (R2 sub-stage 2d) ---------------------
  /**
   * REGENERATE one part (`POST …/parts/{partKey}/regenerate`) — a fresh variation of ONLY that part. 202 with
   * the session now `generating`; reconciles list + detail and returns it. A 409 (a run already in flight)
   * rejects; the caller surfaces it. Mirrors {@link generate}: the caller WAITS for the terminal websocket
   * event, then reads `last_op_status` to toast the outcome (a failed op returns `ready` at the same version).
   */
  async function regeneratePart(id: string, partKey: string): Promise<Session> {
    const res = await api.post<SessionResponse>(
      `/generator/sessions/${id}/parts/${encodeURIComponent(partKey)}/regenerate`,
    );
    reconcile(res.data);
    return res.data;
  }

  /**
   * REFINE one part with a free-text `instruction` (`POST …/parts/{partKey}/refine`) — revises the CURRENT output
   * per the instruction. This ONE action backs BOTH the per-part "Dopracuj" affordance AND the chat composer;
   * the caller supplies the target `partKey`. 202 with the session now `generating`; reconciles + returns it. A
   * 422 (blank instruction / non-refinable part) or 409 (mid-run) rejects; the caller surfaces it. The caller
   * WAITS for the terminal websocket event then reads `last_op_status`.
   */
  async function refinePart(id: string, partKey: string, instruction: string): Promise<Session> {
    const res = await api.post<SessionResponse>(
      `/generator/sessions/${id}/parts/${encodeURIComponent(partKey)}/refine`,
      { instruction },
    );
    reconcile(res.data);
    return res.data;
  }

  /**
   * UNDO one part (`POST …/parts/{partKey}/undo`) — SYNCHRONOUS (no AI, no poll): restores the previous version
   * and discards the just-undone one. 200 with the reverted session; reconciles list + detail and returns it. A
   * 409 (a run is generating OR there is nothing to undo) rejects; the caller toasts it directly.
   */
  async function undoPart(id: string, partKey: string): Promise<Session> {
    const res = await api.post<SessionResponse>(
      `/generator/sessions/${id}/parts/${encodeURIComponent(partKey)}/undo`,
    );
    reconcile(res.data);
    return res.data;
  }

  /**
   * ARCHIVE a session (`POST …/archive`) — sets `is_archived`, exempting it from the lifecycle reaper's trash +
   * purge windows. 200 with the updated session; reconciles list + detail and returns it. Idempotent.
   */
  async function archiveSession(id: string): Promise<Session> {
    const res = await api.post<SessionResponse>(`/generator/sessions/${id}/archive`);
    reconcile(res.data);
    return res.data;
  }

  /** UN-ARCHIVE a session (`POST …/unarchive`) — clears `is_archived`. 200; reconciles list + detail and returns it. */
  async function unarchiveSession(id: string): Promise<Session> {
    const res = await api.post<SessionResponse>(`/generator/sessions/${id}/unarchive`);
    reconcile(res.data);
    return res.data;
  }

  // --- Delegate to a bot / undo the delegation (R2 sub-stage 3) -------------
  /**
   * DELEGATE an editable session to a bot (`POST /bots/{botId}/sessions/{id}/delegate`) — the bot composes its
   * VOICE and autonomously fills the in-scope slots server-side. Body carries the "gate-przed-wydatkiem"
   * `auto_generate` opt-in (DEFAULT false — the human reviews the bot-filled slots before spending) and the
   * click-time `fill_mode` ({@see SlotFillMode}; DEFAULT `gaps` — fill only the empty inputs, never overwrite
   * what the human typed; `fresh` proposes everything anew and undo restores the originals). Returns the
   * updated session (reconciled into list + detail IN PLACE) PLUS the {@see SlotFillReport} (which rides the
   * response, not the persisted wire) — the report echoes the `mode` that ran and flags `nothing_to_fill`
   * (gaps mode with no empty slots: no AI call, nothing spent, overlay still stamped). When `autoGenerate` is
   * true the backend already claimed a run (202, session now `generating`); the caller then WAITS for the
   * terminal websocket event via `useSessionSettle`, exactly like {@link generate}. Errors reject for the
   * caller to toast: 403 non-owner, 404 cross-workspace, 409 mid-run, 422 otherwise-non-editable/invalid mode.
   */
  async function delegate(
    id: string,
    botId: string,
    autoGenerate = false,
    fillMode: SlotFillMode = 'gaps',
  ): Promise<{ session: Session; fillReport: SlotFillReport }> {
    const res = await api.post<SessionDelegateResponse>(
      `/bots/${botId}/sessions/${id}/delegate`,
      { auto_generate: autoGenerate, fill_mode: fillMode },
    );
    reconcile(res.data);
    return { session: res.data, fillReport: res.fill_report };
  }

  /**
   * UNDO a delegation (`DELETE /bots/{botId}/sessions/{id}/delegate`) — clears the bot-author overlay (back to
   * the human's own voice) and RESTORES the human's pre-delegation slot values (the backend does the restore;
   * the returned session already reflects it). Reconciles list + detail IN PLACE and returns the session. A 409
   * (a run is generating) rejects; the caller toasts it.
   */
  async function undoDelegation(id: string, botId: string): Promise<Session> {
    const res = await api.delete<SessionResponse>(`/bots/${botId}/sessions/${id}/delegate`);
    reconcile(res.data);
    return res.data;
  }

  // --- Read-only lookups the chat surface needs -----------------------------
  /**
   * Fetch the code-defined CONTENT TYPE registry (`GET /generator/content-types`) — the part SHAPES
   * (`{id,label,parts:[{key,kind,label,required,config}]}`) the chat renders its turns from, IN ORDER.
   * Pure system data; the caller caches it for the page's lifetime.
   */
  async function fetchContentTypes(): Promise<ContentTypeDefinition[]> {
    const res = await api.get<ContentTypesResponse>('/generator/content-types');
    return res.data ?? [];
  }

  /**
   * Fetch the SOURCE template a session was created from (`GET /generator/templates/{id}`) — the setup
   * form reads its `slots` ({name, descriptor}) to render the typed inputs (the session wire omits the
   * recipe snapshot by design). Read-only; not cached in the store.
   */
  async function fetchSourceTemplate(templateId: string): Promise<Template> {
    const res = await api.get<TemplateResponse>(`/generator/templates/${templateId}`);
    return res.data;
  }

  // --- Save a produced image to Disk (R2 sub-stage 2c) ----------------------
  /**
   * Promote a session part's PRODUCED image onto the user's Disk ("Zapisz na Dysk"):
   * `POST /generator/sessions/{id}/parts/{partKey}/save-to-disk`. The backend re-reads the produced PNG
   * and creates a workspace-scoped {@see DiskFile} in the chosen folder (or root when `folder_id` is
   * omitted); the optional `name` defaults server-side. Returns the created File resource. NO list/detail
   * reconciliation — the new file belongs to the Disk store's domain, not the sessions list. Empty `name`
   * / `folder_id` are dropped so the server applies its own defaults.
   */
  async function saveResultToDisk(
    id: string,
    partKey: string,
    payload: { name?: string | null; folder_id?: string | null } = {},
  ): Promise<DiskFile> {
    const body: { name?: string; folder_id?: string } = {};
    const name = payload.name?.trim();
    if (name) body.name = name;
    if (payload.folder_id) body.folder_id = payload.folder_id;

    const res = await api.post<{ data: DiskFile }>(
      `/generator/sessions/${id}/parts/${encodeURIComponent(partKey)}/save-to-disk`,
      body,
    );
    return res.data;
  }

  return {
    // list state
    items,
    cursor,
    hasMore,
    loading,
    loadingMore,
    errored,
    error,
    loadMoreErrored,
    // detail state
    detail,
    // list actions
    fetchSessions,
    loadMore,
    retryLoadMore,
    resetAll,
    // list mutations (exposed for tests / advanced callers)
    replaceInList,
    removeFromList,
    // detail read
    fetchSession,
    // create / update / delete
    createSession,
    patchSession,
    deleteSession,
    // async run
    generate,
    // per-part refine loop + archive (2d)
    regeneratePart,
    refinePart,
    undoPart,
    archiveSession,
    unarchiveSession,
    // delegate to a bot / undo (2-3)
    delegate,
    undoDelegation,
    // read-only lookups
    fetchContentTypes,
    fetchSourceTemplate,
    // save-to-disk (2c)
    saveResultToDisk,
  };
});
