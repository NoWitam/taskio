// generator/types — the TEMPLATE wire contract (R2 Generator / Templatki, sub-stage 1, REWORK).
//
// A TEMPLATE is a reusable, workspace-scoped RECIPE for a finished post (a "content factory"): declared
// typed SLOTS (the inputs), a `content_type` (which parts the recipe is made of) and a per-part `content`
// map authored AS THE POST (static text + slot values + first-class inline `@[ai-text]` blocks; a media
// PLAN for images). The prompt-era `prompt_body` / `parameters` model is GONE.
//
// These MIRROR the backend contract 1:1 (ContentTypeResource + TemplateResource + Store/UpdateTemplateRequest
// + the draft-friendly catalog / per-part preview endpoints) — do NOT invent fields:
//   GET  /generator/content-types → {data:[{id,label,parts:[{key,kind,label,required,config}]}]}
//   GET/POST       /generator/templates            (cursorPaginate(20), orderBy name)
//   GET/PUT/DELETE /generator/templates/{id}
//   POST           /generator/catalog   body {slots:[{name, descriptor}]}
//                    → {variables, operations, types}   (SAME shape the workflow catalog returns)
//   POST           /generator/preview   body {content_type, content, slots, slot_values}
//                    → {data:{parts:{<partKey>:{rendered} | {plan}}}}
import type { Creator } from '../../ui/patterns/creator';
import type {
  CatalogOperation,
  CatalogType,
  CatalogVariable,
  CatalogVariableDescriptor,
} from '../workflows/types';

// --- Content types (the code-defined registry, served by /generator/content-types) --------------

/** A content-type id (the v1 system set; a string union kept OPEN so a future user-created type parses). */
export type ContentTypeId = 'post' | 'post_with_image' | 'video_script' | (string & {});

/**
 * The CLOSED behavioral vocabulary a content-type PART is built from (D8) — ALL editor / preview
 * switching keys on this kind, NEVER on the content-type id, so a recombined type costs zero rework.
 *
 * `script` / `scene_plan` are LEGACY (video_script Phase B dropped them from the authoring composition,
 * which now composes `[shot_list, storyboard]`) but are KEPT so an OLD session snapshot still renders +
 * previews. `shot_list` = a structured short-video script authored as a creative `{brief}`; `storyboard`
 * = one AI image per shot, authored as an optional `{style, filters}`.
 */
export type PartKind =
  | 'text_body'
  | 'image_plan'
  | 'script'
  | 'scene_plan'
  | 'shot_list'
  | 'storyboard';

/** One PART of a content-type recipe (mirrors ContentTypePart::toArray). */
export interface ContentTypePart {
  key: string;
  kind: PartKind;
  label: string;
  required: boolean;
  config: Record<string, unknown>;
}

/** A content-type definition (mirrors ContentTypeDefinition::toArray). */
export interface ContentTypeDefinition {
  id: ContentTypeId;
  label: string;
  parts: ContentTypePart[];
}

/** `GET /generator/content-types` envelope. */
export interface ContentTypesResponse {
  data: ContentTypeDefinition[];
}

// --- Slots (KEPT verbatim — the slot model + validator + catalog + subfields are unchanged) ------

/**
 * One DECLARED slot: a user-named typed input the content references as `slots.<name>`.
 * `descriptor` is the SAME type-system descriptor the consts / functions editors author, so the
 * shared catalog / editor consume it unchanged.
 */
export interface TemplateSlot {
  name: string;
  description?: string | null;
  descriptor: CatalogVariableDescriptor;
}

// --- Per-part content shapes (mirror the backend validators / render service EXACTLY) ------------

/** A text_body / script part = `{markdown}` (the authored post BODY / scenario). */
export interface BodyContent {
  markdown: string;
}

/** An image plan's BASE — where the image STARTS. */
export type ImageBaseKind = 'disk_file' | 'from_slot' | 'ai_generate';

/** The base config (only its own member is carried on the wire). */
export interface ImageBase {
  kind: ImageBaseKind;
  /** disk_file: an OPAQUE Disk file id (existence validated at session/execution, sub-stage 2). */
  file?: string | null;
  /** from_slot: the NAME of a declared file-typed slot, filled per session. */
  slot?: string | null;
  /** ai_generate (D6, disabled until sub-stage 6): a text→image prompt (markdown w/ slots). */
  prompt?: string | null;
}

/** The deterministic pixel / geometry ops — the imageOps.ts vocabulary (chain steps). */
export type PixelOp =
  | 'grayscale'
  | 'sepia'
  | 'invert'
  | 'warm'
  | 'cool'
  | 'brightness'
  | 'contrast'
  | 'saturation'
  | 'crop'
  | 'rotate'
  | 'flip';

/** One pixel op in the filter chain (`params` present only for the ops that take them). */
export interface PixelFilter {
  kind: 'pixel';
  op: PixelOp;
  params?: Record<string, unknown>;
}

/** One AI edit in the filter chain — a provider image-edit prompt (markdown w/ slots) + optional mask. */
export interface AiEditFilter {
  kind: 'ai_edit';
  prompt: string;
  mask?: string | Record<string, unknown> | null;
}

/** One step in the ordered filter chain — a deterministic pixel op OR an AI edit. */
export type ImageFilterStep = PixelFilter | AiEditFilter;

/**
 * Whether a DELEGATED session's frozen creator may appear in THIS image (mirrors
 * `ImagePlanValidator::CHARACTER_MODES`):
 *   auto   the default — the character rides the image whenever the session has one,
 *   never  this image is never drawn from the character (product shots, logos, charts).
 * `auto` is the ABSENCE of the key on the wire (the same convention as a storyboard's `max_shots`): an
 * unauthored plan means "whatever the run has", so only the deliberate `never` is written.
 */
export type ImageCharacterMode = 'auto' | 'never';

/** An image_plan part = a base + an ordered filter CHAIN (mirrors the variable pipeline). */
export interface ImagePlanContent {
  base: ImageBase | null;
  filters: ImageFilterStep[];
  /** Absent = `auto` (see {@link ImageCharacterMode}); only `'never'` is ever written. */
  character?: ImageCharacterMode;
}

/** One scene of a scene_plan — a narration body + an OPTIONAL nested image plan (D4). */
export interface Scene {
  narration: BodyContent;
  image_plan?: ImagePlanContent | null;
}

/** A scene_plan part = an ORDERED list of scenes (lean v1 authoring). */
export interface ScenePlanContent {
  scenes: Scene[];
}

/**
 * A shot_list part = a creative `{brief}` (video_script Phase B). The brief is a text_body-like markdown
 * body (static text + slot values + `parts.*` + if-blocks + `@[ai-text]`) that STEERS the AI shot-list
 * generation — the STRUCTURE (hook / shots / cta) is baked server-side, so there is no structured-field
 * authoring here. Mirrors the backend write validator (`content.<part>.brief.markdown`).
 */
export interface ShotListContent {
  brief: BodyContent;
}

/** The authorable range of a storyboard's shot cap: 1 … the platform ceiling (`generator.storyboard_max_shots`). */
export const STORYBOARD_MIN_SHOTS = 1;

/**
 * The PLATFORM ceiling on shots in one run. Mirrors the backend default `generator.storyboard_max_shots`;
 * the server stays authoritative (a value above its live ceiling is a 422 on `content.<part>.max_shots`),
 * this constant only keeps the input from offering an obviously-doomed number.
 */
export const STORYBOARD_MAX_SHOTS = 8;

/**
 * A storyboard part = an OPTIONAL `{style?, filters?, max_shots?}` (video_script Phase B + the direction
 * layer). The storyboard has NO base — the base is the auto `ai_generate` per shot (the authored `style` +
 * the shot's on-screen `visual`) — so an author only supplies an optional style prompt (a body-like markdown
 * editor), an optional filter chain applied to every shot's image (the SAME chain authoring an image_plan
 * uses), and an optional per-recipe shot cap. Mirrors the write validator (`content.<part>.style.markdown` +
 * `content.<part>.filters` + `content.<part>.max_shots`).
 */
export interface StoryboardContent {
  style?: BodyContent | null;
  filters: ImageFilterStep[];
  /**
   * The OPTIONAL authored shot cap — a whole number in [1, platform ceiling]. The author may only ever
   * TIGHTEN the ceiling (the run's effective cap is `min(authored, ceiling)`). ABSENT (not `0`, not `null`)
   * when unauthored: the backend validator rejects a non-integer and anything outside the range, so an
   * emitted `0` would 422.
   */
  max_shots?: number | null;
}

/** The per-part authored content map, keyed by the content type's part keys. */
export type TemplateContent = Record<string, unknown>;

// --- The template resource + write payload --------------------------------------------------------

/** A TEMPLATE (TemplateResource) with its server-computed capability flags. */
export interface Template {
  id: string;
  name: string;
  description: string | null;
  /** A ContentTypeRegistry id — its PARTS drive the data-driven editor + preview. */
  content_type: ContentTypeId;
  slots: TemplateSlot[];
  /** The authored per-part content, keyed by the content type's part keys. */
  content: TemplateContent;
  /** `whenLoaded('creator')` — polymorphic (user | workflow_run | bot | null). */
  creator?: Creator | null;
  is_owner: boolean;
  can_be_edited: boolean;
  can_be_deleted: boolean;
  created_at: string | null;
  updated_at: string | null;
}

/** The template write body (Store/Update). Mirrors the FormRequest 1:1. */
export interface TemplateWritePayload {
  name: string;
  description?: string | null;
  content_type: ContentTypeId;
  slots: TemplateSlot[];
  content: TemplateContent;
}

/** The templates list-screen filter state (mirrors the `/generator/templates` query — search only). */
export interface TemplateFilters {
  search?: string;
}

/** Cursor-paginated templates list envelope. Meta carries cursor fields ONLY (no `total`). */
export interface TemplateListResponse {
  data: Template[];
  meta: { next_cursor: string | null };
}

/** Detail envelope from a single-template read / create / update. */
export interface TemplateResponse {
  data: Template;
}

// --- Catalog (draft-friendly) ----------------------------------------------

/** One slot in a catalog / preview request — only `{name, descriptor}` is needed. */
export interface TemplateCatalogSlot {
  name: string;
  descriptor: CatalogVariableDescriptor;
}

/** `POST /generator/catalog` request body — the CURRENT draft slots. */
export interface TemplateCatalogRequest {
  slots: TemplateCatalogSlot[];
}

/**
 * `POST /generator/catalog` response — the SAME `{variables, operations, types}` shape the
 * workflow catalog returns, so it feeds straight into the shared editor. `variables` carries the
 * `slots.<name>` typed inputs + workspace `globals.*`; `operations` the built-ins + `fn:<uuid>`
 * custom functions. (`fields` is workflow-only and absent here.)
 */
export interface GeneratorCatalog {
  variables: CatalogVariable[];
  operations?: CatalogOperation[];
  types?: CatalogType[];
}

// --- Preview (faithful, per-part) -----------------------------------------------------

/** `POST /generator/preview` request body. */
export interface TemplatePreviewRequest {
  content_type: string;
  /** The per-part authored content map to render (any draft shape; render is fail-soft). */
  content: TemplateContent;
  slots: TemplateCatalogSlot[];
  /** Sample value per slot NAME (typed by the slot's descriptor). */
  slot_values: Record<string, unknown>;
}

/** A text_body / script part preview → the faithfully rendered string. */
export interface TextPartPreview {
  rendered: string;
}

/** The base summary of an image-plan preview. */
export interface ImageBasePreview {
  kind: string | null;
  label: string;
  slot?: string | null;
  file?: string | null;
  prompt?: string;
}

/** One filter summary of an image-plan preview. */
export interface ImageFilterPreview {
  kind: string;
  op?: string | null;
  label: string;
  params?: Record<string, unknown> | null;
  prompt?: string;
}

/** The resolved image-plan summary (base + ordered filter chain). */
export interface ImagePlanSummary {
  base: ImageBasePreview | null;
  filters: ImageFilterPreview[];
}

/** An image_plan part preview → a PLAN summary (no image executed). */
export interface ImagePlanPreview {
  plan: ImagePlanSummary;
}

/** One scene of a scene-plan preview. */
export interface ScenePreview {
  narration: string;
  image: ImagePlanSummary | null;
}

/** A scene_plan part preview → a per-scene summary. */
export interface ScenePlanPreview {
  plan: { scenes: ScenePreview[] };
}

/**
 * A shot_list part preview → the resolved creative BRIEF (video_script Phase B). No AI runs in a preview,
 * so the shots themselves are produced only in a real run; the preview faithfully shows the resolved brief.
 */
export interface ShotListPartPreview {
  brief: string;
}

/**
 * The resolved storyboard PLAN summary — the resolved style prompt + the authored per-shot filter chain +
 * the OPTIONAL authored shot cap. The server ALWAYS emits `max_shots` (null when unauthored or malformed),
 * so the preview can state how many frames the recipe will produce.
 */
export interface StoryboardPlanSummary {
  style: string;
  filters: ImageFilterPreview[];
  max_shots: number | null;
}

/** A storyboard part preview → a PLAN summary (style + filters; no image executed, no base). */
export interface StoryboardPreview {
  plan: StoryboardPlanSummary;
}

/** A part preview is one of the per-kind shapes. */
export type PartPreview =
  | TextPartPreview
  | ImagePlanPreview
  | ScenePlanPreview
  | ShotListPartPreview
  | StoryboardPreview;

/** `POST /generator/preview` result — the per-part map (unwrapped from the `data` envelope). */
export interface TemplatePreviewResult {
  parts: Record<string, PartPreview>;
}
