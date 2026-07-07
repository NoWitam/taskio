// Bot (AI Character) domain types for the isolated "next" frontend (Batch 1).
//
// These MIRROR the VERIFIED backend contract 1:1 — no invented fields:
//   • BotListResource  (GET /bots — cursorPaginate(8), created_at desc)
//   • BotResource      (GET /bots/{id}, POST /bots, PUT /bots/{id}, restore)
//   • StoreBotRequest / UpdateBotRequest (the write body)
//
// IMPORTANT response wrapping: Bot resources have no explicit `data` key, so
// Laravel's default wrapper kicks in — EVERY response body is `{ data: ... }`.
// The store reads a single resource as `res.data` and a collection as
// `res.data` (array) + `res.meta` (cursor fields ONLY — there is NO `total`).
//
// Self-contained: NO import from the legacy `resources/js/`.

// The Bot Inbox (Batch 7) rows are TaskListResource shapes + an `inbox_state`.
// Reuse the verified Tasks list-row type (cross-page import within `next` is fine).
import type { TaskListItem } from '../tasks/types';

/** A user as returned by UserResource (the bot `creator`). */
export interface BotUser {
  id: string | number;
  name: string;
  email?: string | null;
  avatar?: string | null;
}

/**
 * The bot status enum — collapsed to a two-state toggle. A bot is either live
 * (`active`, tone success) or off (`inactive`, tone neutral). Status is NEVER sent
 * on create/update; it is toggled through `PATCH /bots/{id}/status`.
 */
export type BotStatus = 'active' | 'inactive';

/**
 * A dictionary ("gwara"/slang) entry — a word/expression the bot uses + its
 * meaning. Read + written as `{ term, meaning }` (both required, ≤255/≤500, ≤100
 * entries). Legacy bare-string entries are normalized server-side.
 */
export interface BotDictionaryEntry {
  term: string;
  meaning: string;
}

/**
 * A phrase entry — a signature catchphrase/hook + optional context (when to use
 * it). Read + written as `{ phrase, context }` (phrase required ≤255, context
 * nullable ≤500, ≤100 entries).
 */
export interface BotPhraseEntry {
  phrase: string;
  context: string | null;
}

/**
 * The task-execution module config, read + written as a single nested object.
 * Mirrors `task_execution` 1:1 (Batch 6): `{ enabled, tools }` — `knowledge_source`
 * was REMOVED (the knowledge module replaces it). The detail resource returns
 * `null` when the module was never configured.
 */
export interface BotTaskExecution {
  enabled: boolean;
  /** A string[] of enabled tool identifiers (validated against the tool registry). */
  tools: string[];
}

/**
 * A single knowledge-module entry (Batch 6). Server constraints (map 422): ≤50
 * entries, `title` required ≤255, `content` required ≤5000.
 */
export interface BotKnowledgeEntry {
  title: string;
  content: string;
}

/**
 * The knowledge module — an explicitly-enabled optional module (redesign): `enabled`
 * gates whether its `entries` are injected into the bot's execution context. Read
 * from `data.knowledge` and written back as `{ enabled, entries }`.
 */
export interface BotKnowledge {
  enabled: boolean;
  entries: BotKnowledgeEntry[];
}

/** A bot LIST row (BotListResource). */
export interface BotListItem {
  id: string;
  name: string;
  status: BotStatus;
  description: string | null;
  /** General-info icon identifier (nullable), shown on the card. */
  icon: string | null;
  /** The persona/text module is mandatory, so this is always true. */
  has_text_module: boolean;
  /** Whether the task-execution module is configured + enabled. */
  task_execution_enabled: boolean;
  /** The current user created this bot. */
  is_owner: boolean;
  created_at: string | null;
}

/**
 * The FULL bot (BotResource) — the five modules (text / task-execution / visual /
 * audio / knowledge), the creator, and the capability flags.
 */
export interface BotDetail {
  id: string;
  name: string;
  status: BotStatus;
  description: string | null;
  /** General-info icon identifier (nullable) — always-visible header. */
  icon: string | null;
  // --- 1. Text module (the mandatory persona) ---
  /** REQUIRED on write (≤10000). The bot's persona/voice text module. */
  persona: string;
  /** Optional style notes (≤5000). */
  style: string | null;
  /** Slang entries `{ term, meaning }` (accessor-normalized from legacy strings). */
  dictionary: BotDictionaryEntry[];
  /** Catchphrases `{ phrase, context }` (accessor-normalized from legacy strings). */
  phrases: BotPhraseEntry[];
  /** Plain list of topics/behaviours to avoid (unchanged). */
  prohibitions: string[];
  // --- 2. Task-execution module (nullable until configured) ---
  task_execution: BotTaskExecution | null;
  // --- 3 & 4. Not-yet-writable placeholders (always null) ---
  visual: null;
  /** Batch 6: renamed from `voice`. Placeholder, not writable/meaningful yet. */
  audio: null;
  // --- 5. Knowledge module — { enabled, entries: [{title, content}] }. ---
  knowledge: BotKnowledge;
  // --- Meta / capability flags ---
  /** `whenLoaded('creator')`. */
  creator?: BotUser | null;
  is_owner: boolean;
  can_execute_tasks: boolean;
  can_be_edited: boolean;
  can_be_deleted: boolean;
  created_at: string | null;
  updated_at: string | null;
}

// --- List query / envelopes ------------------------------------------------

/**
 * The list-screen filter state. Mirrors the `/bots` query params 1:1 — the ONLY
 * server filter is `search` (matches name). The page owns this; the store
 * serializes it.
 */
export interface BotFilters {
  search?: string;
}

/**
 * Cursor-paginated list envelope from `/bots`. Meta carries cursor fields ONLY —
 * there is NO `total` (cursorPaginate(8)).
 */
export interface BotListMeta {
  next_cursor: string | null;
}
export interface BotListResponse {
  data: BotListItem[];
  meta: BotListMeta;
}

/** Detail envelope from `GET /bots/{id}` + the create/update/restore calls. */
export interface BotDetailResponse {
  data: BotDetail;
}

// --- Write payload (StoreBotRequest / UpdateBotRequest) --------------------

/**
 * The task-execution sub-payload. Mirrors `task_execution` validation (Batch 6):
 * nullable object `{ enabled:bool, tools:string[] }` — `knowledge_source` was
 * REMOVED. When the module is left disabled with no config the editor sends `null`.
 */
export interface BotTaskExecutionPayload {
  enabled: boolean;
  tools: string[];
}

/**
 * The bot write body. Mirrors the FormRequest 1:1:
 *   name (req ≤255), description (nullable ≤2500), persona (REQUIRED ≤10000),
 *   style (nullable ≤5000), dictionary ({term,meaning}[] ≤100), phrases
 *   ({phrase,context?}[] ≤100), prohibitions (string[]), task_execution (nullable
 *   `{ enabled, tools }`), knowledge (`{ enabled, entries }`). `status` is NEVER
 *   sent here (toggled via `PATCH /bots/{id}/status`); `visual`/`audio` are NOT
 *   writable (placeholders, omitted entirely).
 */
export interface BotWritePayload {
  name: string;
  description?: string | null;
  /** General-info icon identifier (nullable). */
  icon?: string | null;
  persona: string;
  style?: string | null;
  dictionary?: BotDictionaryEntry[] | null;
  phrases?: BotPhraseEntry[] | null;
  prohibitions?: string[] | null;
  task_execution?: BotTaskExecutionPayload | null;
  /** Knowledge module — `{ enabled, entries }`. */
  knowledge?: BotKnowledge;
}

/** Body for `PATCH /bots/{id}/status` — toggle a bot's live status (creator-only). */
export interface BotStatusPayload {
  status: BotStatus;
}

// --- Bot actions (BotActionResource, cursor-paginated) — Batch 2 -----------

/**
 * The bot action TYPE enum (verified). Each value drives an i18n label + icon in
 * the action-history timeline. Batch 2:
 *   task_started · form_filled · commented · submitted_to_test · marked_done ·
 *   execution_failed (the only error state — surfaces `error`/`status`).
 * Batch 4 (interactive execution) adds:
 *   question_asked   — `payload.question: string` (bot asked + WAITS for a human)
 *   resumed          — `payload.run: int` (a human reply resumed the bot)
 *   revision_started — `payload.run: int` (re-run after an approval reject)
 *   handed_over      — `status: 'handed_over'` (run cap hit; handed to a human)
 * And `task_started` now repeats per run: `payload.run: int` +
 *   `payload.trigger: 'initial' | 'resume' | 'revision'`.
 * Batch 5 (tool registry) adds:
 *   tool_used — `payload.tool: BotToolId` + per-tool fields (see below).
 */
export type BotActionType =
  | 'task_started'
  | 'form_filled'
  | 'commented'
  | 'submitted_to_test'
  | 'marked_done'
  | 'execution_failed'
  | 'question_asked'
  | 'resumed'
  | 'revision_started'
  | 'handed_over'
  | 'tool_used';

/** The `trigger` carried on a repeated `task_started` action's payload (Batch 4). */
export type BotActionTrigger = 'initial' | 'resume' | 'revision';

/**
 * The known bot TOOL ids (Batch 5), validated server-side against the registry.
 *   fetch_url        — `payload.{ host, url }`
 *   web_search       — `payload.{ query, results:int }`
 *   generate_file    — `payload.{ file:string }` (appears as a task attachment)
 *   read_attachments — `payload.{ action:'list'|'read', count?, file? }`
 * A bot's saved `tools[]` may contain an id NOT in this union (a tool removed
 * server-side) — callers must tolerate an unknown string, never drop it.
 */
export type BotToolId =
  | 'fetch_url'
  | 'web_search'
  | 'generate_file'
  | 'read_attachments';

/**
 * One tool-registry entry (`GET /api/bots/tool-registry`). `available` is false
 * when the tool can't run in the current environment (e.g. `web_search` with no
 * search API key configured). The editor renders ONLY available tools as options.
 */
export interface BotToolRegistryEntry {
  id: string;
  available: boolean;
}

/** Envelope for `GET /api/bots/tool-registry` → `{ data: [...] }`. */
export interface BotToolRegistryResponse {
  data: BotToolRegistryEntry[];
}

/**
 * One bot action (BotActionResource) from:
 *   GET /bots/{bot}/actions?cursor=&type=   (the bot's whole history)
 *   GET /tasks/{task}/bot-actions?cursor=   (one task's bot activity)
 * Both endpoints return the SAME shape, cursor-paginated. Mirrors the resource
 * 1:1 — no invented fields. `payload` is a free-form object; `error` is non-null
 * only for `execution_failed`.
 */
export interface BotAction {
  id: string;
  bot_id: string;
  task_id: string | null;
  type: BotActionType;
  payload: Record<string, unknown> | null;
  status: string | null;
  error: string | null;
  created_at: string | null;
  updated_at: string | null;
}

/** Cursor-paginated envelope for the two bot-action endpoints. */
export interface BotActionsResponse {
  data: BotAction[];
  meta: { next_cursor: string | null };
}

// --- Bot Inbox (Batch 7) ---------------------------------------------------

/**
 * The execution-state buckets a bot's task can be in. RENDER ORDER is exactly this
 * (matches the backend `buckets` object order):
 *   queued · running · waiting · in_approval · revision · failed · done
 */
export type BotInboxState =
  | 'queued'
  | 'running'
  | 'waiting'
  | 'in_approval'
  | 'revision'
  | 'failed'
  | 'done';

/** The fixed bucket render order (shared by the store + the bucket bar). */
export const BOT_INBOX_STATES: BotInboxState[] = [
  'queued',
  'running',
  'waiting',
  'in_approval',
  'revision',
  'failed',
  'done',
];

/**
 * One inbox row — a TaskListResource row PLUS its `inbox_state`. Carries the same
 * fields the bot-tasks list already renders (id/title/priority/deadline/assignee/
 * labels/bot_waiting/…) — see `TaskListItem`.
 */
export interface BotInboxTask extends TaskListItem {
  inbox_state: BotInboxState;
}

/** The per-bucket counts (`meta.buckets`), keyed by state. */
export type BotInboxBuckets = Record<BotInboxState, number>;

/**
 * Envelope for `GET /api/bots/{id}/inbox?state=&cursor=`:
 *   { data: BotInboxTask[], meta: { next_cursor }, buckets, runs_this_month }.
 * `buckets` + `runs_this_month` are present on every page (the store keeps the
 * latest). Absent bucket keys default to 0.
 */
export interface BotInboxResponse {
  data: BotInboxTask[];
  meta: { next_cursor: string | null };
  buckets: Partial<BotInboxBuckets>;
  runs_this_month: number;
}
