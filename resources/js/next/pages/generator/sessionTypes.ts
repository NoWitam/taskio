// generator/sessionTypes — the generation SESSION wire contract (R2 Generator / Templatki, sub-stage 2b).
//
// A SESSION is one execution of a TEMPLATE recipe into finished content: it snapshots the recipe at
// creation, carries the user's filled `slot_values`, an async `status`, and a per-part `results` map the
// chat surface renders as conversation turns. These MIRROR the backend contract 1:1
// ({@see \App\Modules\Generator\Http\Resources\GenerationSessionResource}) — do NOT invent fields.
//
// VERIFIED endpoints (base `/api/generator`):
//   GET    /generator/sessions?search=&status=&cursor=  → {data: Session[], meta:{next_cursor}}  (created_at desc)
//   POST   /generator/sessions   {template_id, name?, slot_values?}          → 201 {data: Session}
//   GET    /generator/sessions/{id}                                          → {data: Session}
//   PATCH  /generator/sessions/{id}  {name?, slot_values?}                   → 200 {data: Session}  (422 if not draft/ready)
//   POST   /generator/sessions/{id}/generate                                 → 202 {data: Session}  (409 if already generating)
//   DELETE /generator/sessions/{id}                                          → 200 {message}
// R2 sub-stage 2d (the per-part refine loop + archive):
//   POST   /generator/sessions/{id}/parts/{partKey}/regenerate               → 202 {data: Session}  (poll; 409 mid-run)
//   POST   /generator/sessions/{id}/parts/{partKey}/refine  {instruction}    → 202 {data: Session}  (poll; 422 blank/non-refinable, 409 mid-run)
//   POST   /generator/sessions/{id}/parts/{partKey}/undo                     → 200 {data: Session}  (SYNC; 409 mid-run / nothing to undo)
//   POST   /generator/sessions/{id}/archive | /unarchive                     → 200 {data: Session}
// The recipe SNAPSHOT is intentionally NOT emitted; the FE re-reads the part SHAPES from
// `GET /generator/content-types` and the slot DESCRIPTORS from the source template
// (`GET /generator/templates/{id}`), exactly as the resource docblock prescribes.
import type { Creator } from '../../ui/patterns/creator';
import type { ContentTypeId, PartKind } from './types';

/** The async lifecycle of a session (mirrors {@see GenerationSessionStatus}). */
export type SessionStatus = 'draft' | 'generating' | 'ready' | 'failed';

/** Per-part terminal status inside `results` (mirrors the executor's per-part outcome). */
export type SessionPartResultStatus = 'ok' | 'failed' | 'deferred';

/**
 * The PRODUCED-image metadata a media part carries once it has run (R2 sub-stage 2c). The bytes are NOT
 * on the wire — the chat fetches them from the authorized serve endpoint
 * (`GET /generator/sessions/{id}/parts/{partKey}/image`, always a PNG). `width`/`height` let the UI
 * reserve space before the blob loads.
 */
export interface ProducedImage {
  mime: string;
  width: number;
  height: number;
  /** The stored version of this produced image (R2 sub-stage 2d); the serve endpoint resolves the current one. */
  version?: number;
}

/** A scene's optional image outcome inside a produced `scene_plan` result. */
export type SceneImageStatus = 'ok' | 'failed' | 'none';

/**
 * One scene of a produced `scene_plan` part (R2 sub-stage 2c). `narration` is the already-rendered text
 * (MarkdownViewer source). Its optional image is fetched from the serve endpoint using `part_key`
 * (present only when `image_status === 'ok'`); a per-scene failure carries a localized `image_error`.
 */
export interface SessionScene {
  narration: string;
  image_status: SceneImageStatus;
  image?: ProducedImage | null;
  /** The storage key to fetch this scene's produced image from the serve endpoint (`image_status === 'ok'`). */
  part_key?: string;
  /** A localized, non-secret failure message when `image_status === 'failed'`. */
  image_error?: string;
  /** The machine-readable reason for a failed scene image, when the server has one (additive). */
  image_error_code?: SessionImageErrorCode;
}

/**
 * The per-shot image lifecycle of a produced `storyboard` (the distributed-frames stage). A storyboard part
 * ANNOUNCES its beats and renders each image in its OWN queue job, so a shot lives through a lifetime the FE
 * can observe on a page reload mid-run:
 *   pending    announced + queued, nothing spent,
 *   rendering  a frame job claimed it and is talking to the provider,
 *   ok/failed  terminal (byte-identical to the pre-distribution shape — the transient keys are dropped).
 * Mirrors {@see \App\Modules\Generator\Support\StoryboardFrame} 1:1.
 */
export type StoryboardImageStatus = 'pending' | 'rendering' | 'ok' | 'failed';

/**
 * A MACHINE-READABLE reason a produced image failed, additive to the human `error` / `image_error`.
 * `image_safety` = the provider RENDERED the picture and then refused to hand it over (its own moderation).
 * It is the one image failure a user can actually fix, and the fix is the CHARACTER's description or
 * wardrobe — not the plan — so the UI promotes it over the generic prose. Kept open (`string`) so an
 * unforeseen code still falls back to the server's message instead of breaking the wire.
 */
export type SessionImageErrorCode = 'image_safety' | (string & {});

/**
 * One SHOT of a produced `shot_list` result (video_script Phase B): the on-screen `visual`, the spoken
 * `voiceover`, and a `seconds` duration. Mirrors the ShotListRenderer's normalized shot 1:1.
 */
export interface ShotListShot {
  visual: string;
  voiceover: string;
  seconds: number;
  /**
   * Whether the session's frozen CREATOR is VISIBLE in this beat (R2 sub-stage 3). Emitted only for a run
   * that HAS a character; absent means false. It is what makes the storyboard draw that beat FROM the
   * character's reference image rather than from a description.
   */
  features_character?: boolean;
}

/**
 * One SHOT of a produced `storyboard` result (video_script Phase B) — mirrors {@see SessionScene} but
 * richer: the descriptive beat (`index`, `visual`, `voiceover`, `seconds`) rides the entry even on image
 * failure, and its produced image is served + saved via `part_key` (`storyboard.<i>`). `image_status` walks
 * the {@link StoryboardImageStatus} lifetime — a session that is `generating` with partial results is
 * FETCHABLE, so a page reload mid-run legitimately reads `pending` / `rendering` and must render a loading
 * arm, not an empty hole. A failed shot carries a localized `image_error` (+ an optional machine-readable
 * `image_error_code` — the SHOT key differs from a part result's `error_code` on purpose: it mirrors the
 * wire, where per-item image failures are namespaced `image_*` exactly like `image_error`).
 */
export interface StoryboardShot {
  index: number;
  visual: string;
  voiceover: string;
  seconds: number;
  image_status: StoryboardImageStatus;
  image?: ProducedImage | null;
  /** The storage key to fetch/save this shot's produced image (`image_status === 'ok'`) — `storyboard.<i>`. */
  part_key?: string;
  /** A localized, non-secret failure message when `image_status === 'failed'`. */
  image_error?: string;
  /** The machine-readable reason for a failed frame, when the server has one (additive). */
  image_error_code?: SessionImageErrorCode;
  /** Whether the session's frozen creator is VISIBLE in this beat (absent = false) — see {@link ShotListShot}. */
  features_character?: boolean;
}

/**
 * One produced PART result. A `text_body` / `script` part resolves LIVE (`ok` + `text`) or `failed`
 * (+ a localized `error`). Since R2 sub-stage 2c the media parts EXECUTE server-side:
 *   - `image_plan`  → `ok` + `image` ({@see ProducedImage}, bytes fetched via the serve endpoint) OR
 *     `failed` + a localized `error`.
 *   - `scene_plan`  → `ok` + `scenes` ({@see SessionScene}[]) — each scene renders its narration + its
 *     optional produced image.
 * `deferred` should no longer occur post-2c but the UI keeps a defensive fallback for it.
 */
export interface SessionPartResult {
  kind: PartKind;
  status: SessionPartResultStatus;
  /** Resolved markdown for a produced text_body / script part (`status === 'ok'`). */
  text?: string;
  /** A localized, non-secret failure message for a `failed` part. */
  error?: string;
  /**
   * The machine-readable reason for a `failed` part, when the server has one (ADDITIVE — absent on older
   * results and on failures with no classified cause). Today: `image_safety`. See
   * {@link SessionImageErrorCode}.
   */
  error_code?: SessionImageErrorCode;
  /** A produced image for an `image_plan` part (`status === 'ok'`); bytes are served separately. */
  image?: ProducedImage | null;
  /** The produced scenes for a `scene_plan` part (`status === 'ok'`). */
  scenes?: SessionScene[] | null;
  /** The hook line of a produced `shot_list` part (`status === 'ok'`; may be '' — e.g. a bare-array reply). */
  hook?: string;
  /** The call-to-action line of a produced `shot_list` part (`status === 'ok'`; may be ''). */
  cta?: string;
  /**
   * The produced SHOTS — a `shot_list` result carries {@link ShotListShot}[] (hook / voiceover / seconds),
   * a `storyboard` result carries {@link StoryboardShot}[] (per-shot nested image). Narrowed by `kind` in
   * the card. `null`/absent otherwise.
   */
  shots?: ShotListShot[] | StoryboardShot[] | null;
  /**
   * A `shot_list`'s parse flag (`status === 'ok'`): `false` = the model returned non-JSON we kept as raw
   * `text` with `shots: []` (the card shows the raw text + a "couldn't structure" note).
   */
  parse_ok?: boolean;
  /**
   * A CROSS-PART coherence hint (Phase A): `true` when an UPSTREAM part was refined after this one was
   * produced (e.g. the storyboard after a shot_list refine). Flows VERBATIM in `results` — the FE surfaces a
   * "may be out of date" badge; there is NO auto-cascade. A regenerate of this part clears it (fresh result);
   * a full generate clears all.
   */
  stale?: boolean;
  /**
   * The current VERSION of this produced part (R2 sub-stage 2d): `1` on the first produce, `+1` on each
   * regenerate / refine. The chat renders it as a "Wersja N" badge. Present on `ok` `text_body` / `script`
   * / `image_plan` / `shot_list` results; `scene_plan` / `storyboard` carry their versions per nested image,
   * not at the top level.
   */
  version?: number;
}

/** The per-part result map, keyed by the content type's part keys (`null` until a run produced it). */
export type SessionResults = Record<string, SessionPartResult>;

/** The outcome of the MOST RECENT per-part regenerate / refine op (R2 sub-stage 2d). */
export type SessionOpStatus = 'ok' | 'failed';

/**
 * The lean per-part undo state (R2 sub-stage 2d): `undo_depth` prior versions are available to undo to, and
 * `can_undo` is server-authoritative (creator AND the session is `ready`). A part with no prior version is
 * simply ABSENT from the map — the FE treats a missing key as no-undo.
 */
export interface PartHistoryEntry {
  can_undo: boolean;
  undo_depth: number;
}

/** The per-part undo map, keyed by part key. */
export type SessionPartHistory = Record<string, PartHistoryEntry>;

/**
 * The SNAPSHOTTED bot AUTHOR of a delegated session (R2 sub-stage 3) — `{id, name, icon}`, read off the
 * delegation overlay (never the live bot, so a later bot edit/delete cannot change a delegated session's
 * author). Null when the session is not delegated. ADDITIVE to `creator`: a delegated session still shows the
 * human as owner/creator; the bot is an EXTRA author chip. Mirrors {@see GenerationSession::botAuthor} 1:1.
 */
export interface BotAuthor {
  id: string;
  name: string;
  /** General-info icon identifier the bot carries (nullable / absent). */
  icon?: string | null;
}

/** Why the bot's proposed value for a slot was DROPPED (R2 sub-stage 3): the model named an input the
 * template doesn't declare (`unknown_slot`), a declared-but-not-bot-fillable slot such as a file/composite
 * (`out_of_scope`), or a value that failed its descriptor's type check (`invalid`). */
export type SlotSkipReason = 'unknown_slot' | 'out_of_scope' | 'invalid' | 'already_filled';

/**
 * HOW a delegation should fill the session's inputs — chosen by the human at click time (the
 * "Zleć botowi" dialog), sent as `fill_mode`:
 * - `gaps` (DEFAULT, non-destructive): fill ONLY the slots that are currently empty; a value the human
 *   typed is never touched.
 * - `fresh`: propose NEW values for ALL in-scope slots, deliberately different from what is there now —
 *   this REPLACES the current inputs (undo restores them).
 */
export type SlotFillMode = 'gaps' | 'fresh';

/**
 * The AUTONOMOUS slot-fill outcome of a delegation (R2 sub-stage 3). Mirrors the backend fill report 1:1
 * ({@see SessionDelegationService::applyBotSlotValues}). This rides the delegate RESPONSE only — it is NOT a
 * persisted `Session` field (the FE holds the latest report in local state to render the report panel).
 */
export interface SlotFillReport {
  /** Slot names the bot set (accepted + type-valid). */
  filled: string[];
  /** Slots the bot proposed but the server dropped, each with a reason to localize. */
  skipped: Array<{ name: string; reason: SlotSkipReason }>;
  /** Required slots still empty after the fill — the human must complete these (esp. a required FILE slot). */
  unfilled_required: string[];
  /** The mode the server actually ran (echoes `fill_mode`; `gaps` when the request omitted it). */
  mode: SlotFillMode;
  /**
   * TRUE when there was nothing for the bot to do — `gaps` mode with no empty slots. The server made NO AI
   * call and spent nothing, but the delegation overlay IS still stamped (the bot is the author). Rendered as
   * its own honest state, never as a "filled 0 inputs" panel.
   */
  nothing_to_fill: boolean;
}

/**
 * The art-direction facets of a {@link CreativeDirection}. Whitelisted server-side to exactly these four
 * keys (unknown facets are dropped); each is a normalized, length-capped string. Any facet may be absent.
 */
export interface CreativeDirectionVisualStyle {
  medium?: string | null;
  palette?: string | null;
  lighting?: string | null;
  camera?: string | null;
}

/**
 * The CREATIVE DIRECTION derived for one generation run (the direction layer) — the small, shared creative
 * frame every part of the run was made to, so a session's post body, its shot list and its storyboard frames
 * serve ONE piece. Mirrors {@see \App\Modules\Generator\Support\CreativeDirection::toArray()} 1:1.
 *
 * EVERY field is optional/nullable: the object is model-derived and normalized field by field server-side
 * (unknown keys dropped, strings type-checked + control-stripped + length-capped, beats bounded, the duration
 * ranged), and an all-empty direction is collapsed to `null` rather than emitted as an empty shell.
 *
 * SECURITY: every value is model-derived content laundered from user-supplied slot values. Render it as
 * PLAIN TEXT (interpolation only) — never `v-html`, never through a markdown renderer.
 */
export interface CreativeDirection {
  /** The one thing the piece must land (a paragraph field). */
  message?: string | null;
  goal?: string | null;
  audience?: string | null;
  tone?: string | null;
  /** The narrative spine shared by every part (a paragraph field). */
  through_line?: string | null;
  /** The ordered narrative beats (bounded server-side; may be an empty array). */
  arc_beats?: string[] | null;
  /** The recurring subject that must stay identical across frames. */
  subject?: string | null;
  setting?: string | null;
  /** The art direction shared by every generated image. */
  visual_style?: CreativeDirectionVisualStyle | null;
  /** The target total duration in whole seconds (out-of-range values are dropped server-side). */
  duration_target_seconds?: number | null;
  /** Cross-frame continuity notes (a paragraph field). */
  continuity_notes?: string | null;
}

/** A generation SESSION (GenerationSessionResource) with its server-authoritative capability flags. */
export interface Session {
  id: string;
  name: string;
  /** Provenance — the template this session was created from (its slots feed the setup form). */
  template_id: string;
  /** The snapshotted content type id — its PARTS drive the rendered turns, in order. */
  content_type: ContentTypeId;
  status: SessionStatus;
  /** The user's filled inputs, keyed by slot NAME. */
  slot_values: Record<string, unknown>;
  /** The per-part outputs; `null` until a run has produced them. Each `ok` result carries its `version`. */
  results: SessionResults | null;
  /**
   * The CREATIVE DIRECTION derived for the current run (the direction layer) — read-only provenance the chat
   * surface renders as a collapsed card.
   *
   * DETAIL-ONLY: the sessions INDEX uses a lean projection ({@see GenerationSessionResource::lean}) that omits
   * the key entirely, so a LIST row never carries it — hence `?`. A DETAIL read always emits the key, `null`
   * when the layer is disabled, the derivation failed, or the session predates the layer. A FULL run CLAIMS
   * the session and nulls it (it is re-derived per run), so it is legitimately `null` while `generating` and
   * arrives with the settled session.
   */
  creative_direction?: CreativeDirection | null;
  /**
   * Per-part undo state (R2 sub-stage 2d): `{ <partKey>: {can_undo, undo_depth} }`. Only parts with a prior
   * version appear; a missing key means no-undo. Drives the "Cofnij" affordance + the "Wersja N" badge trigger.
   */
  part_history?: SessionPartHistory;
  /**
   * Outcome of the MOST RECENT per-part regenerate / refine op (R2 sub-stage 2d), cleared at the next op:
   * `'ok'` / `'failed'` / `null` (`null` = no part op since the last claim or a full generate). After a part
   * op poll SETTLES the FE reads this to toast a failed refine instead of silently accepting an identical result.
   */
  last_op_status: SessionOpStatus | null;
  /** A localized, non-secret failure message when `last_op_status === 'failed'`, else `null` (R2 sub-stage 2d). */
  last_op_error: string | null;
  /** `whenLoaded('creator')` — polymorphic (user | workflow_run | bot | null). */
  creator?: Creator | null;
  is_owner: boolean;
  /** May kick off a run (whole-session generate OR a per-part regenerate/refine) AND not already mid-run. */
  can_generate: boolean;
  /** May edit inputs AND the state allows it (draft / ready). */
  can_edit: boolean;
  can_be_deleted: boolean;
  /**
   * The snapshotted bot AUTHOR of a delegated session (R2 sub-stage 3), or null when undelegated. ADDITIVE to
   * `creator` (the human owner stays shown) — drives the "Authored by {bot}" chip on the header + list rows.
   */
  bot_author: BotAuthor | null;
  /** Whether a bot currently authors this session (the delegation overlay is present) (R2 sub-stage 3). */
  is_delegated: boolean;
  /**
   * Whether this session FROZE a character LIKENESS with its delegation — i.e. whether its images are drawn
   * from the author's approved likeness rather than from a description alone (R2 sub-stage 3). A FLAG, never
   * the identity itself (the descriptor / wardrobe / aesthetic are prompt material and stay off the wire).
   * Emitted by BOTH the list and the detail projection.
   */
  has_character_image: boolean;
  /** May delegate to a bot (creator AND editable — the same rights delegate needs) (R2 sub-stage 3). */
  can_delegate: boolean;
  /**
   * May UNDO an existing delegation (R2 sub-stage 3): owner && is_delegated && status !== 'generating'. Unlike
   * {@link can_delegate} this is NOT gated on an editable draft/ready state — a `failed` delegated session
   * returns TRUE so the human can always revert a bot author + bot-overwritten inputs (undo is only blocked
   * mid-run). Gate the "Undo delegation" affordance on THIS, not on `can_delegate`.
   */
  can_undo_delegation: boolean;
  /**
   * The required slots still empty — a SOFT signal (NOT a hard generate-gate). The human should complete these;
   * a required FILE slot is surfaced as a "needs your file" state since files are never bot-filled (R2 s-3).
   */
  unfilled_required_slots: string[];
  /** The archive marker is set (R2 sub-stage 2d): the session is exempt from the reaper's trash + purge windows. */
  is_archived: boolean;
  /** May archive / unarchive (creator; the same `update` gate as generate / edit) (R2 sub-stage 2d). */
  can_archive: boolean;
  archived_at: string | null;
  created_at: string | null;
  updated_at: string | null;
}

/** The create body (`POST /generator/sessions`). Mirrors StoreGenerationSessionRequest 1:1. */
export interface SessionCreatePayload {
  template_id: string;
  name?: string | null;
  slot_values?: Record<string, unknown>;
}

/** The edit body (`PATCH /generator/sessions/{id}`). Mirrors UpdateGenerationSessionRequest 1:1. */
export interface SessionUpdatePayload {
  name?: string;
  slot_values?: Record<string, unknown>;
}

/**
 * The sessions list-screen filter state. Mirrors the `/generator/sessions` query 1:1: a `search`
 * (name) and a SINGLE `status` (the backend `tryFrom`s one status value — NOT a multi-select).
 */
export interface SessionFilters {
  search?: string;
  status?: SessionStatus;
}

/** Cursor-paginated sessions list envelope. Meta carries cursor fields ONLY (no `total`). */
export interface SessionListResponse {
  data: Session[];
  meta: { next_cursor: string | null };
}

/** Detail envelope from a single-session read / create / update / generate. */
export interface SessionResponse {
  data: Session;
}

/**
 * The DELEGATE response (`POST /bots/{bot}/sessions/{session}/delegate`) — the updated session PLUS the
 * autonomous fill report (added server-side via `->additional(['fill_report' => …])`, so it sits alongside
 * `data`, not inside it). `202` when `auto_generate` kicked off a run, `200` otherwise; the body shape is the
 * same. UNDO (`DELETE …/delegate`) returns a plain {@see SessionResponse} (no report).
 */
export interface SessionDelegateResponse {
  data: Session;
  fill_report: SlotFillReport;
}
