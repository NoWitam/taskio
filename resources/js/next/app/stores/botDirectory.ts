// botDirectory — a tiny id → {name, status, visual facts} RESOLVER cache for bot references embedded
// in content or in authored configuration (the per-block AUTHOR of an `@[ai-text]` block; the
// `generate_content` workflow step's session AUTHOR).
//
// WHY NOT `useBotsStore`: that store is the BROWSE + editor store — its `fetchBot` writes the single
// global `detail` ref that the bot detail page renders. Resolving a chip through it would stomp on
// whatever the user is looking at. This store owns nothing but a lookup map.
//
// Contract (VERIFIED — do NOT invent fields):
//   GET /bots/{id} → { data: { id, name, status, visual: {enabled, canonical_file_id, …}|null, … } }
//   404 / 403      → the id is gone (or not ours) ⇒ MISSING — a definitive verdict.
//   anything else  → UNRESOLVED — we do NOT know; never render "deleted" for a network blip.
//
// There is NO batch endpoint (`GET /bots?ids[]=` does not exist), so resolving N DISTINCT authors in
// one document costs N requests. In practice a document has 1–3 distinct authors, and every id is
// requested AT MOST ONCE per session: results are cached by id and concurrent callers for the same id
// share the single in-flight promise. Chips therefore never fan out per instance.
//
// TWO PROPERTIES OF THE INPUT SHAPE THE STORE:
//   • The id is UNTRUSTED. It comes from document content, not from a picker, so it is only ever
//     interpolated into the request path after passing the same uuid check the backend applies
//     (`Str::isUuid` — see BotAuthorVoiceResolver::uuidsOnly). Anything else is a definitive MISSING
//     and never reaches the network.
//   • The cache is per-WORKSPACE. Bot ids are workspace-scoped while content can travel between
//     workspaces (a copied template or block), so an entry resolved in workspace A must not answer
//     for the same id met in workspace B. The active workspace is read from the auth store — the
//     same source the api client puts in `X-Workspace-Id` — and a change (including logout, which
//     clears it) invalidates everything cached.
import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { api } from '../lib/api';
import { useAuthStore } from './auth';
import type { BotStatus } from '../../ui/data/botStatus';

/**
 * Laravel's `Str::isUuid` shape, mirrored 1:1: 8-4-4-4-12 hex, case-insensitive, version-agnostic.
 * A non-matching id can never be a bot primary key, so refusing it locally costs nothing and keeps a
 * crafted author id from turning into an arbitrary authenticated GET on our own origin.
 */
const UUID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

function isUuid(id: string): boolean {
  return UUID_PATTERN.test(id);
}

/** Lifecycle of one directory entry. */
export type BotDirectoryState = 'loading' | 'resolved' | 'missing' | 'unresolved';

/**
 * The VISUAL module's two at-a-glance facts — the SAME pair `BotListResource` publishes
 * (`visual_enabled` / `visual_has_image`) and the delegate dialog reads: the toggle, and whether an
 * APPROVED likeness exists. They are independent (a bot can have one with the module off), and only
 * BOTH together mean "this bot puts its face on the images".
 */
export interface BotDirectoryVisual {
  enabled: boolean;
  hasImage: boolean;
}

export interface BotDirectoryEntry {
  id: string;
  state: BotDirectoryState;
  /** The bot's CURRENT name — authoritative over any stored display snapshot. */
  name: string | null;
  status: BotStatus | null;
  /**
   * The visual facts, or `null` when they are NOT KNOWN — which is a different thing from "no
   * likeness". Only a real `GET /bots/{id}` fills this (it is always non-null after one, even for a
   * bot that never configured the module); a `prime()`d entry, a MISSING one and an UNRESOLVED one
   * all leave it null. A caller that renders what a bot BRINGS must therefore treat null as "say
   * nothing yet" rather than as "brings nothing".
   */
  visual: BotDirectoryVisual | null;
}

interface BotLookupResponse {
  data: {
    id: string | number;
    name: string;
    status?: BotStatus | null;
    visual?: { enabled?: boolean | null; canonical_file_id?: string | null } | null;
  };
}

/** Read an HTTP status off an axios-shaped error (best-effort). */
function statusOf(err: unknown): number | null {
  const res = (err as { response?: { status?: number } })?.response;
  return typeof res?.status === 'number' ? res.status : null;
}

export const useBotDirectoryStore = defineStore('next-bot-directory', () => {
  const entries = ref<Record<string, BotDirectoryEntry>>({});
  /** In-flight resolutions, keyed by id — the deduplication seam. */
  const inFlight = new Map<string, Promise<void>>();
  /**
   * The workspace the cached entries belong to (`undefined` until the first write, `null` when there
   * is no current workspace). Compared against the live value on every read/write.
   */
  const scope = ref<string | null | undefined>(undefined);

  /** The workspace the api client is currently scoping requests to. */
  function currentScope(): string | null {
    const id = useAuthStore().currentWorkspaceId;
    return id == null ? null : String(id);
  }

  /** Drop every cached verdict (e.g. on a workspace switch / logout). */
  function reset(): void {
    entries.value = {};
    inFlight.clear();
  }

  /** Re-scope the cache to the active workspace, clearing it when the workspace changed. */
  function ensureScope(): void {
    const active = currentScope();
    if (scope.value === active) return;
    scope.value = active;
    reset();
  }

  function set(id: string, patch: Omit<BotDirectoryEntry, 'id'>): void {
    entries.value = { ...entries.value, [id]: { id, ...patch } };
  }

  /**
   * The current entry for an id, or `null` when it was never asked for — or when it was asked for in
   * a DIFFERENT workspace. This is a pure read (renderers call it), so it does not clear the stale
   * cache itself; the `resolve()` the consumer already fires does that on the write path.
   */
  function entry(id: string | null | undefined): BotDirectoryEntry | null {
    if (!id) return null;
    if (scope.value !== currentScope()) return null;
    return entries.value[id] ?? null;
  }

  /**
   * Resolve `id` at most once. A cached `resolved` / `missing` verdict short-circuits; an
   * `unresolved` one does NOT (a failure is retryable), but only through `retry()` so a re-render
   * cannot spin on a failing id. Concurrent callers await the same promise.
   */
  function resolve(id: string | null | undefined, force = false): Promise<void> {
    if (!id) return Promise.resolve();
    ensureScope();
    const existing = entries.value[id];
    if (!force && existing && existing.state !== 'unresolved') return Promise.resolve();
    // An id that is not uuid-shaped is refused BEFORE any request: the backend would 404 it anyway,
    // and interpolating it would let document content aim an authenticated GET wherever it liked.
    if (!isUuid(id)) {
      set(id, { state: 'missing', name: null, status: null, visual: null });
      return Promise.resolve();
    }
    const pending = inFlight.get(id);
    if (pending) return pending;

    set(id, {
      state: 'loading',
      name: existing?.name ?? null,
      status: existing?.status ?? null,
      visual: existing?.visual ?? null,
    });

    const request = api
      .get<BotLookupResponse>(`/bots/${encodeURIComponent(id)}`)
      .then((res) => {
        const bot = res.data;
        set(id, {
          state: 'resolved',
          name: bot?.name ?? null,
          status: bot?.status ?? null,
          // ALWAYS an object after a real read — a bot that never configured the module answers
          // `visual: null`, which is "the module is off", NOT "we don't know" (see the field's doc).
          visual: {
            enabled: bot?.visual?.enabled === true,
            hasImage: !!bot?.visual?.canonical_file_id,
          },
        });
      })
      .catch((err: unknown) => {
        const code = statusOf(err);
        // 404/403 are the ONLY definitive "this author is gone" answers. Everything else
        // (network failure, 5xx, timeout) leaves the question open.
        const gone = code === 404 || code === 403;
        set(id, {
          state: gone ? 'missing' : 'unresolved',
          name: gone ? null : (existing?.name ?? null),
          status: gone ? null : (existing?.status ?? null),
          visual: gone ? null : (existing?.visual ?? null),
        });
      })
      .finally(() => {
        inFlight.delete(id);
      });

    inFlight.set(id, request);
    return request;
  }

  /** Re-ask for an id whose last answer was inconclusive (the "Try again" affordance). */
  function retry(id: string | null | undefined): Promise<void> {
    return resolve(id, true);
  }

  /**
   * Feed the cache from data we already hold (e.g. a picker page) — no request. NOT uuid-guarded on
   * purpose: this input is a server payload we already fetched, not document content, and nothing is
   * interpolated into a URL here.
   *
   * A priming caller may not carry the VISUAL facts (the picker options don't), so an already-known
   * `visual` is PRESERVED rather than reset — priming a name must never downgrade a fact we paid a
   * request for. `visual` stays null (= not known) until a real read fills it.
   */
  function prime(bot: {
    id: string;
    name: string;
    status?: BotStatus | null;
    visual?: BotDirectoryVisual | null;
  }): void {
    if (!bot?.id) return;
    ensureScope();
    set(bot.id, {
      state: 'resolved',
      name: bot.name,
      status: bot.status ?? null,
      visual: bot.visual ?? entries.value[bot.id]?.visual ?? null,
    });
  }

  const known = computed(() => entries.value);

  return { entries, known, entry, resolve, retry, prime, reset };
});
