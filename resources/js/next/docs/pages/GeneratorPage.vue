<script setup lang="ts">
// Gallery: Generator (content templates + generation sessions) module — the module
// overview, the R2 sub-stage 1 TEMPLATE contract on the CONTENT-RECIPE model (this
// page's prior revision documented a rejected "prompt + parameters" model — see
// docs/decisions/ADR-0032-generator-content-recipe.md for the rework record), and
// the R2 sub-stage 2 (2a-2d, all SHIPPED) SESSION contract — the chat surface that
// actually EXECUTES a recipe: fill slots, generate, then iteratively refine
// (regenerate / instructed refine / undo) before saving an image to Disk. A template
// is a typed RECIPE: a `content_type` (which PARTS it is made of) + declared typed
// SLOTS (its inputs) + a per-part `content` map authored AS THE FINISHED POST
// (static text + slot values + first-class inline @[ai-text] AI blocks; a declared
// MEDIA plan for an image). A session SNAPSHOTS a recipe and runs it for real: live
// budgeted @[ai-text], a server-side image chain (pixel ops + AI edit), a real
// $/token cost meter, and a bounded per-part undo history. The video_script rework
// (Phase A: a general cross-part `parts.<key>` context primitive; Phase B: `video_script`
// recomposed to structured `shot_list` + executor-iterated `storyboard`) is documented
// in §18/§19 — see docs/decisions/ADR-0035-video-script-cross-part-storyboard.md.
// `ai_generate` execution shipped in R2 sub-stage 6 (§7/§15, no longer disabled).
// A session may be DELEGATED to a bot (R2 sub-stage 3, shipped) — a reversible,
// snapshotted overlay: the bot autonomously fills the session's in-scope inputs and
// becomes its content AUTHOR (its own voice colors every text part, incl. the
// shot_list voiceover), while the human stays the OWNER — see §20 and
// docs/decisions/ADR-0036-bot-delegation-generation-sessions.md.
// A content-QUALITY rework (§21) sits on top of all of the above: an adaptive shot
// count + a narrative contract that degrades coherently at a low shot cap (instead of
// a fixed "3 to 5" that silently overrode a brief's stated duration), plus a
// once-per-run CREATIVE DIRECTION every generation in that run is made to (so a post's
// body/image, or a storyboard's frames, read as one piece instead of independent,
// mutually-blind AI calls) — see docs/decisions/ADR-0038-creative-direction-layer.md.
// A `generate_content` WORKFLOW step (R2 sub-stage 5, shipped) now consumes a Template
// end-to-end with no human in the loop — see §11's automation note and
// docs/decisions/ADR-0039-workflow-suspend-resume-and-generate-content.md; the step
// itself, its config/output contract, and the generic suspend/resume engine it runs on
// are documented in resources/js/next/docs/pages/WorkflowsPage.vue, not here.
// Documents the IMPLEMENTED behavior of app/modules/Generator/ (+ the Bot-module
// delegation edge); the variable system + AI-cost meter it reuses live in the shared
// Variables module (one engine — never a fork). Deferred/planned items — bot autonomy
// beyond slot-fill, file/deep-composite slot bot-fill, a bot delegating a
// workflow-driven generation, a user-created content type, native structured output for
// shot_list, image-to-image chaining for exact cross-frame character consistency — are
// called out explicitly in the "Planned / deferred" section.
//
// Sections:
//   1. Module overview & concepts (a template is a RECIPE, not a prompt; one engine; the one-way boundary)
//   2. Content types & parts (the code-defined registry)
//   3. Generator API endpoints (templates)
//   4. Request/resource shapes + capability flags
//   5. Slots — declared typed inputs
//   6. Authoring the body — first-class AI blocks
//   7. Image plan — base + filter chain
//   8. Scene plan — an ordered list of scenes (legacy)
//   9. Live preview — faithful, per-part server render
//   10. Declare vs. execute — a template's boundary with a generation session
//   11. Generation Sessions — overview + the async run/status machine
//   12. Sessions API endpoints
//   13. The chat surface — fill slots, generate, per-part turns
//   14. The refine loop — regenerate, instructed refine, undo, versions
//   15. The image chain + AI cost limits ($ gate, per-workspace cap, actor attribution, pre-run 429 — R2 sub-stage 4)
//   16. Lifecycle — archive, trash, purge
//   17. Frontend module
//   18. Cross-part context — parts.<key> (video_script rework Phase A)
//   19. Video script rework — structured shot_list + storyboard (Phase B)
//   20. Bots in the generator — session delegation (R2 sub-stage 3)
//   21. Content quality — narrative contract + the creative direction layer
//   22. Planned / deferred
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';
import Alert from '../../ui/feedback/Alert.vue';

// ── Content types (code-defined registry) ──────────────────────────────────
const contentTypeRows: ApiRow[] = [
  { name: 'post',            type: 'file-text', description: 'One part: body → text_body (required). A plain text post.' },
  { name: 'post_with_image', type: 'image',     description: 'Two parts: body → text_body (required); image → image_plan (optional). A post that ALSO carries a declared media plan for one image.' },
  { name: 'video_script',    type: 'film',      description: 'Two parts: shot_list → shot_list (required); storyboard → storyboard (optional). A STRUCTURED short-video shot list (hook / timed shots / cta) + an executor-iterated storyboard (one AI image per shot). Reworked (video_script rework, Phase B) — see §18/§19 below; PREVIOUSLY composed script + scene_plan, kept legacy-only for an existing session snapshot.' },
];

// ── Part kinds (the CLOSED vocabulary every editor/validator/renderer switches on) ──
const partKindRows: ApiRow[] = [
  { name: 'text_body', type: '{ markdown }', description: 'The post BODY, authored AS the finished post: static text + slot values + inline @[ai-text] AI blocks.' },
  { name: 'image_plan', type: '{ base, filters[] }', description: 'A declared MEDIA plan for one image: where it starts (base) + an ordered transform chain (filters). See §7.' },
  { name: 'shot_list', type: '{ brief: { markdown } }', description: 'A creative BRIEF that feeds ONE structured AI call at run time → a coherent hook / timed shots / cta (video_script rework). See §19.' },
  { name: 'storyboard', type: '{ style?, filters?, max_shots? }', description: 'An OPTIONAL style + filter chain + a per-recipe shot-cap tightening knob; the executor generates one AI image PER shot of the sibling shot_list at run time — no authored base (video_script rework). See §19/§21.' },
  { name: 'script *(legacy)*',    type: '{ markdown }', description: 'A video SCENARIO — validates/renders through the identical text_body path. No longer offered by any content type today (dropped from video_script); kept only so an existing session snapshot still renders it.' },
  { name: 'scene_plan *(legacy)*', type: '{ scenes[] }', description: 'An ordered list of scenes, each a narration + an optional nested image_plan (D4). Same legacy status as script — see §8.' },
];

// ── Generator endpoints ─────────────────────────────────────────────────────
const endpointRows: ApiRow[] = [
  { name: 'GET /generator/content-types',    type: '—',                        description: 'The code-defined content-type catalog the editor builds its data-driven sections from → { data: [{ id, label, parts }] }. Any workspace member (viewAny).' },
  { name: 'GET /generator/templates',        type: '?search=&cursor=',        description: 'List templates (TemplateResource[]). Cursor-paginated, 20/page, orderBy name. Any workspace member (viewAny).' },
  { name: 'POST /generator/templates',       type: 'TemplateWritePayload',    description: 'Create a template. Any member (create); the creator is stamped (HasCreator). Returns { data: Template }.' },
  { name: 'GET /generator/templates/{id}',   type: '—',                       description: 'Fetch one template (TemplateResource, creator loaded). Any member (view).' },
  { name: 'PUT /generator/templates/{id}',   type: 'TemplateWritePayload',    description: 'Update. Same rules as POST. Creator-only (update).' },
  { name: 'DELETE /generator/templates/{id}', type: '—',                      description: 'Delete (hard). Creator-only (delete). Returns { message }.' },
  { name: 'POST /generator/catalog',         type: '{ slots: [{ name, descriptor }] }', description: 'DRAFT-FRIENDLY, server-authoritative variable catalog for the CURRENT draft slots → { data: { variables, operations, types } }. Fail-soft (malformed slots skipped). Unchanged by the content-recipe rework.' },
  { name: 'POST /generator/preview',         type: '{ content_type, content, slots, slot_values }', description: 'FAITHFUL, PER-PART server-side render of an (unsaved) recipe against sample slot values → { data: { parts: { <key>: {rendered} | {plan} } } }. Runs the SAME shared resolver a real generation would; @[ai-text] inert-but-labeled this sub-stage; an image_plan renders a PLAN SUMMARY only — no image executed.' },
];

// ── TemplateWritePayload fields ─────────────────────────────────────────────
const writePayloadRows: ApiRow[] = [
  { name: 'name',         type: 'string',                       description: 'Required, max 255. A user-facing label — NOT unique.' },
  { name: 'description',  type: 'string | null',                description: 'Optional, max 2000.' },
  { name: 'content_type', type: "'post' | 'post_with_image' | 'video_script'", description: 'Required. One of ContentTypeRegistry::ids() — an unknown value is a 422.' },
  { name: 'slots',        type: '{ name, description?, descriptor }[]', description: 'Required key (`present`, array). DEEP-validated per slot: a safe/unique/non-reserved name + a well-formed descriptor. Unchanged by this rework.' },
  { name: 'content',      type: 'Record<partKey, unknown>',      description: 'Required key (`present`, object) keyed by the SELECTED content type’s part keys. Each value’s shape is decided by its part’s KIND (§2/§6–§8). An unknown part key, or a missing REQUIRED part, is a 422; an OPTIONAL part may be entirely absent.' },
];

// ── TemplateResource fields ─────────────────────────────────────────────────
const resourceRows: ApiRow[] = [
  { name: 'id',             type: 'string',                     description: 'UUID.' },
  { name: 'name',           type: 'string',                     description: '' },
  { name: 'description',    type: 'string | null',              description: '' },
  { name: 'content_type',   type: "'post' | 'post_with_image' | 'video_script'", description: 'The ContentTypeRegistry wire id.' },
  { name: 'slots',          type: '{ name, description, descriptor }[]', description: 'Each declared slot, shaped to exactly these three keys; a malformed stored slot is skipped.' },
  { name: 'content',        type: 'Record<partKey, unknown>',   description: 'The authored per-part content, exactly as validated on write.' },
  { name: 'creator',        type: 'Creator | null',             description: "whenLoaded — the polymorphic creator (user | workflow_run | bot). See CreatorBadge / docs/backend/creator-attribution.md. A template is ALWAYS user-created today." },
  { name: 'is_owner',       type: 'boolean',                    description: 'HUMAN creator match (isOwnedBy) — presentational. Gate actions on can_be_*, not this.' },
  { name: 'can_be_edited',  type: 'boolean',                    description: 'Creator-only. Server-authoritative — gate the UI on this.' },
  { name: 'can_be_deleted', type: 'boolean',                    description: 'Creator-only.' },
  { name: 'created_at',     type: 'string (ISO 8601) | null',   description: '' },
  { name: 'updated_at',     type: 'string (ISO 8601) | null',   description: '' },
];

// ── Slot bases ──────────────────────────────────────────────────────────────
const slotBaseRows: ApiRow[] = [
  { name: 'text',    type: 'scalar',              description: 'A string.' },
  { name: 'number',  type: 'scalar',              description: 'An int/float.' },
  { name: 'boolean', type: 'scalar',              description: 'true / false.' },
  { name: 'date',    type: 'scalar',              description: 'A Carbon-parseable date.' },
  { name: 'enum',    type: 'scalar + options',    description: 'A value from a declared option list ({ key, label? }).' },
  { name: 'object',  type: 'structural + fields', description: 'A nested record — its declared fields are referenced as slots.<name>.<field>. Authored exactly like an object constant.' },
  { name: 'file',    type: 'structural (fixed)',  description: 'A reusable Disk file input — a FIXED composite (no user-authored fields), see the subfield table below. The ONLY slot base an image_plan’s from_slot base may name.' },
  { name: 'nullable: true', type: 'orthogonal flag', description: 'The value may resolve empty. Exposes a "no value" toggle in the preview.' },
  { name: 'array: true',    type: 'orthogonal flag', description: 'A list of the base element. NOT offered for the structural bases (object/file) — array-of-container is deferred. A multi-select is enum + array (there is no separate "multi" base).' },
  { name: 'time', type: '— NOT authorable —', description: 'A slot may not declare the TIME base (it has no runtime semantics yet in the shared type system).' },
];

// ── file composite subfields ────────────────────────────────────────────────
const fileSubfieldRows: ApiRow[] = [
  { name: 'slots.<name>.id',   type: 'text',   description: "The disk file's id (uuid)." },
  { name: 'slots.<name>.name', type: 'text',   description: 'The file name.' },
  { name: 'slots.<name>.type', type: 'text',   description: "A human-friendly alias for the file's mime_type." },
  { name: 'slots.<name>.size', type: 'number', description: 'Byte size.' },
  { name: 'slots.<name>.url',  type: 'text',   description: 'The access-controlled serve URL — never a raw storage path.' },
];

// ── Body variable sources (the picker feed) ─────────────────────────────────
const pickerSourceRows: ApiRow[] = [
  { name: 'slots.<name>',    type: "source: 'slots'", description: "The template's own DECLARED typed inputs. An object/file slot expands to its subfields (slots.<name>.<field>) in the picker tree." },
  { name: 'globals.<key>',   type: "source: 'globals'", description: "The workspace's user-authored CONSTANTS (the Variables module), grouped under a “Globals” node. An object global expands to its declared fields." },
  { name: 'fn:<uuid>',       type: 'operation',        description: "The workspace's custom FUNCTIONS, offered as pipeline operations alongside the built-ins." },
  { name: 'built-in ops',    type: 'operation pipeline', description: 'The shared operation catalog (built-ins ∪ custom functions) — a picked variable can be transformed by a pipeline before it lands in a part.' },
];

// ── ai-text personas (closed tone set) ──────────────────────────────────────
const aiPersonaRows: ApiRow[] = [
  { name: 'neutral',  type: 'default', description: 'Clear, neutral, professional tone.' },
  { name: 'friendly', type: '—',       description: 'Warm, approachable, conversational tone.' },
  { name: 'formal',   type: '—',       description: 'Precise, businesslike, respectful tone.' },
  { name: 'concise',  type: '—',       description: 'As short and direct as possible.' },
];

// ── Image-plan base kinds (D5 + D6) ─────────────────────────────────────────
const imageBaseRows: ApiRow[] = [
  { name: 'disk_file',   type: "{ kind, file: <id> }", description: 'A fixed Disk file, picked via the shared DiskFilePickerModal. BOUNDARY: the id is an OPAQUE string on the wire — its existence/ownership is validated at execution (a later sub-stage), not at write, so Generator stays Disk-decoupled.' },
  { name: 'from_slot',   type: "{ kind, slot: <name> }", description: 'A DECLARED file-typed slot (descriptor base: \'file\'), filled per generation session. The name must match a declared file slot exactly.' },
  { name: 'ai_generate', type: "{ kind, prompt: <markdown> }", description: 'A text→image prompt, directive-validated like a body. LIVE since R2 sub-stage 6 — a selectable, savable base in the picker; a real generation Session resolves the prompt and generates the base image for real (metered ai_image_generate). The video_script rework’s storyboard (§19) also uses ai_generate internally, per shot, but with NO authored base field at all — the executor builds it automatically from the shot list.' },
];

// ── Pixel ops (the filter chain’s deterministic vocabulary) ─────────────────
const pixelOpRows: ApiRow[] = [
  { name: 'grayscale / sepia / invert / warm / cool', type: 'no params', description: 'Deterministic color casts.' },
  { name: 'brightness / contrast / saturation',       type: '{ amount: -100..100 }', description: 'Tonal adjustments.' },
  { name: 'crop',                                      type: '{ rect: {x,y,w,h} } (0..1 fractions)', description: 'A canvas-relative crop rectangle.' },
  { name: 'rotate',                                        type: '{ quarterTurns: 1..3 }', description: '90° / 180° / 270°.' },
  { name: 'flip',                                              type: "{ axis: 'horizontal' | 'vertical' }", description: '' },
];

const filterKindRows: ApiRow[] = [
  { name: 'pixel',   type: "{ kind, op, params? }", description: 'One deterministic op from the table above, reusing the imageOps.ts pure functions (the SAME ones the Disk image editor uses) — but this filter CHAIN is a new ordered pipeline, not the Disk editor itself (that editor is single-filter).' },
  { name: 'ai_edit', type: "{ kind, prompt: <markdown>, mask? }", description: 'A provider image-edit prompt (directive-validated like a body) + an optional mask reference. Reuses Disk\\Services\\ImageAiService::edit() — called for real by a generation Session (§15), never by the template endpoints on this page.' },
];

// ── Generation Sessions (R2 sub-stage 2, all of 2a-2d shipped) ─────────────

// ── Status machine ───────────────────────────────────────────────────────────
const sessionStatusRows: ApiRow[] = [
  { name: 'draft',      type: 'yes', description: 'Created, filling slot_values; no run yet.' },
  { name: 'generating', type: 'no',  description: 'An async run (whole-session OR a per-part regenerate/refine) is claimed and in flight.' },
  { name: 'ready',      type: 'yes', description: 'The most recent run finished; inspect results PER PART — an individual part may still be failed while the session itself is ready.' },
  { name: 'failed',     type: 'no — re-generate to retry', description: 'The WHOLE run threw (an infra fault — not a per-part failure, which stays ready).' },
];

// ── Session CRUD + whole-session run ────────────────────────────────────────
const sessionCrudRows: ApiRow[] = [
  { name: 'GET /generator/sessions',          type: '?search=&status=&cursor=', description: 'List (SessionResource[]). Cursor-paginated, 20/page, orderBy created_at DESC (unlike Templates, which orders by name). Any workspace member.' },
  { name: 'POST /generator/sessions',         type: '{ template_id, name?, slot_values? }', description: 'Create FROM a template — SNAPSHOTS its recipe into recipe_snapshot. 201, status: draft. An invalid/foreign/unviewable template_id is 422.' },
  { name: 'GET /generator/sessions/{id}',     type: '—', description: 'Fetch one (SessionResource, creator loaded). Any workspace member.' },
  { name: 'PATCH /generator/sessions/{id}',   type: '{ name?, slot_values? }', description: 'Edit the two user-mutable inputs. Creator-only, AND only while draft/ready (422 status if generating/failed).' },
  { name: 'DELETE /generator/sessions/{id}',  type: '—', description: 'Soft-delete (trash) — NOT a hard delete, unlike a Template. The lifecycle reaper purges it later.' },
  { name: 'POST /generator/sessions/{id}/generate', type: '—', description: 'Claim + queue a WHOLE-SESSION run (clears prior results/history/blobs). 202, status: generating. 409 if a run is already in flight; 429 ai_budget_exceeded if the workspace is already over its AI $ cap (§15).' },
];

// ── Per-part refine loop + image + archive ──────────────────────────────────
const sessionPartRows: ApiRow[] = [
  { name: 'POST …/parts/{partKey}/regenerate',   type: '—',                    description: 'Claim + queue a fresh variation of ONE part, from the SAME snapshot. 202; 404 unknown partKey; 409 mid-run; 429 over AI budget (§15).' },
  { name: 'POST …/parts/{partKey}/refine',       type: '{ instruction }',      description: 'Claim + queue an instructed REVISION of ONE part\'s current output. 202; 404 unknown part; 422 blank instruction / non-refinable part (scene_plan); 409 mid-run; 429 over AI budget (§15).' },
  { name: 'POST …/parts/{partKey}/undo',         type: '—',                    description: 'SYNCHRONOUS (no AI, no claim) — restore the previous version, discard the undone one. 200; 409 mid-run or nothing to undo.' },
  { name: 'GET …/parts/{partKey}/image',         type: '—',                    description: 'Stream the part\'s CURRENT produced image inline (always PNG). Any workspace member (read-gated).' },
  { name: 'POST …/parts/{partKey}/save-to-disk', type: '{ name?, folder_id? }', description: 'Promote the current produced image onto Disk (FileService::storeDiskContent). 201 FileResource; 404 no current image. Double-authorized (session update + Disk create).' },
  { name: 'POST …/archive  /  …/unarchive',      type: '—',                    description: 'Freeze / un-freeze the session from the lifecycle reaper (§16). 200, creator-only, idempotent.' },
];

// ── The refine loop's 3 distinct affordances ────────────────────────────────
const refineLoopRows: ApiRow[] = [
  { name: 'Generuj ponownie (regenerate)', type: 'POST …/regenerate', description: 'A FRESH variation from the SAME snapshot + slot values — a re-roll, not a revision.' },
  { name: 'Dopracuj (refine)',             type: 'POST …/refine',    description: 'A REVISION of the CURRENT output guided by free text — a text part gets an AI rewrite (current text + instruction as data); an image part gets an AI edit of the current bytes; a shot_list gets a directed structured revision; storyboard.<i> gets an AI edit of that shot. A BARE scene_plan/storyboard composite is not refinable.' },
  { name: 'Cofnij (undo)',                 type: 'POST …/undo',      description: 'SYNCHRONOUS, no AI call — restore the previous version and discard the just-undone one. No redo.' },
];

// ── Cost meter (R2 sub-stage 4 — $-first gate cutover, ADR-0037) ───────────
const costMeterRows: ApiRow[] = [
  { name: 'Workspace monthly $ cap', type: 'AiUsageService::cap() — ai_monthly_cost_cap (workspace) or AI_MONTHLY_COST_CAP (env, default 0 = disabled)', description: 'GATE-BEFORE-SPEND, DOLLAR-based since R2 sub-stage 4: a workspace\'s calendar-month SUM(estimated_cost) is checked BEFORE a provider call runs. Shared by EVERY AI spender app-wide (Workflows @[ai-text], Disk AI edits, Generator sessions). 0 (the shipped default) byte-preserves prior behavior. The legacy monthly_token_cap no longer gates anything (telemetry only).' },
  { name: 'Per-channel pricing', type: 'ai.meter.pricing.<channel> — per_1k_tokens (ai_text) or per_call (ai_image_edit / ai_image_generate)', description: 'The estimated_cost formula: ai_text prices REAL provider tokens per 1k; the two image channels price a flat $ per call (an image edit/generate has no real token count). Every price is operator-maintained + env-overridable (AI_PRICE_TEXT_PER_1K, AI_PRICE_IMAGE_EDIT_PER_CALL, AI_PRICE_IMAGE_GENERATE_PER_CALL). Every $ figure is an ESTIMATE, never billed on.' },
  { name: 'Actor attribution', type: 'actor_type / actor_id (user | bot | workflow_run)', description: 'Every spend is tagged with WHO drove it via App\\Support\\Meter\\MeterActorResolver (mirrors HasCreator\'s precedence). A session run tags the session\'s owner, or the delegated BOT (§20) when the session carries a bot-author overlay — resolved names, never stored.' },
  { name: 'Pre-run 429 gate', type: 'GenerationSessionRunManager::claimAndDispatch()', description: 'A workspace ALREADY at/over its $ cap is refused UP FRONT — HTTP 429 { code: \'ai_budget_exceeded\' } — before the session is even claimed, on all four run entry points (generate, regenerate, refine, delegate auto_generate). Distinct from the mid-run fail-soft below, which handles a run that CROSSES the cap while already in flight.' },
  { name: 'ai_text_max_calls_per_session', type: 'generator.ai_text_max_calls_per_session (4)', description: 'A per-RUN ceiling on @[ai-text] provider calls — bounds one recipe\'s fan-out, NOT a dollar budget. A shot_list generate/refine (§19) counts as ONE of these calls too. Exhausted ⇒ that block (or the shot_list) resolves to \'\'/fails soft (the run still completes).' },
  { name: 'image_edit_max_calls_per_session', type: 'generator.image_edit_max_calls_per_session (8)', description: 'A per-RUN ceiling on ai_edit provider calls, cumulative across every image part AND every storyboard shot in the run. Kept in LOCK-STEP with storyboard_max_shots (also 8): an authored storyboard filter chain runs on EVERY shot, so ONE ai_edit filter across a full 8-shot storyboard is 8 edits. Exhausted ⇒ the over-budget filter STEP is SKIPPED and the image is still produced WITHOUT it (graceful degradation, image_status stays ok) — only an image REFINE hard-fails (as a no-op that preserves the current image), since there the edit IS the whole operation.' },
  { name: 'image_generate_max_calls_per_session', type: 'generator.image_generate_max_calls_per_session (8)', description: 'A per-RUN ceiling on ai_generate (text→image base) provider calls, cumulative across every image part in the run. Kept in LOCK-STEP with storyboard_max_shots (also 8) so a FULL video_script storyboard\'s per-shot generates all fit in one run — if ever lowered below the ceiling, the last shots of a long list come back frameless. Exhausted ⇒ ONLY that shot/part fails.' },
  { name: 'storyboard_max_shots', type: 'generator.storyboard_max_shots (8)', description: 'The PLATFORM CEILING on shots in one video_script run (§19; adaptive since §21\'s creative-direction layer) — the REAL cost bound, not a metering ceiling. A template MAY tighten it per recipe (content.storyboard.max_shots, §19); the run\'s EFFECTIVE cap is min(authored, ceiling), one value threaded into the shot-list agent\'s instructed bound, the parse clamp, AND the storyboard\'s per-shot image iteration so they cannot drift.' },
  { name: 'Session tagging', type: 'MeterContext', description: 'Every ai_text / ai_image_edit / ai_image_generate spend recorded during a run or part-op carries this session\'s id — read by the workspace usage summary\'s per-channel/per-actor breakdown; a dedicated per-session spend readout is not built yet.' },
];

// ── Lifecycle reaper windows ─────────────────────────────────────────────────
const lifecycleRows: ApiRow[] = [
  { name: 'Stale recovery', type: 'generator.session_stale_after (1800s / 30 min)', description: 'A session stuck in generating past the cutoff → failed. Recovers a worker SIGKILL/OOM that never fired the run job\'s failed() hook — nothing else does.' },
  { name: 'Trash', type: 'generator.session_trash_after (604800s / ~1 week)', description: 'A NON-archived session idle (updated_at) past the cutoff → soft-deleted. Any activity (edit/refine) resets the clock.' },
  { name: 'Purge', type: 'generator.session_purge_after (2592000s / ~1 month)', description: 'A trashed, NON-archived session past the cutoff → force-deleted AND its produced-image blobs garbage-collected.' },
];

// ── Cross-part context (parts.<key>, video_script rework Phase A) ───────────
const crossPartLayerRows: ApiRow[] = [
  { name: 'The editor catalog', type: 'POST /generator/catalog {content_type, part_key}', description: 'Offers parts.<earlierKey> variables ONLY for the parts declared before part_key — so the picker never even shows a forward/self reference as a choice.' },
  { name: 'The write validator', type: 'TemplateSlotValidator', description: 'Rejects a forward/self/unknown parts.<key> reference as 422 — wherever the shared scanner finds it (a directive, an @[ai-text] prompt, an if-block condition/body, a flat {{parts.<key>}} token).' },
  { name: 'The session executor', type: 'GenerationSessionExecutor', description: 'Independently re-derives the earlier-only scope from the snapshot at BOTH a whole run (accumulates top-to-bottom from empty) and an isolated per-part op (seeds from the session\'s STORED results, strictly-earlier keys only) — a forward/self reference resolves EMPTY even if a hypothetical validator bug let it through.' },
];

// ── shot_list — the structured JSON contract (video_script rework Phase B) ──
const shotListParseRows: ApiRow[] = [
  { name: 'A well-formed JSON object', type: '{hook, shots:[{visual,voiceover,seconds}], cta}', description: 'Optionally fenced in ```json … ``` — parsed normally: {status:\'ok\', hook, shots, cta, text, parse_ok:true}.' },
  { name: 'A bare top-level JSON array', type: '[{visual,voiceover,seconds}, …]', description: 'A model deviating from the object contract — treated AS the shots list: {status:\'ok\', hook:\'\', shots, cta:\'\', text, parse_ok:true}.' },
  { name: 'Non-blank, non-decodable text', type: 'prose / malformed JSON', description: 'Kept as the raw reply: {status:\'ok\', hook:\'\', shots:[], cta:\'\', text:<raw>, parse_ok:false} — so the author sees what came back.' },
  { name: 'Blank', type: '— (failed / over-cap / empty reply)', description: 'The PART fails soft: {status:\'failed\', error}.' },
];

// ── storyboard.<i> per-shot addressing (reuses the EXISTING per-part op contract) ──
const storyboardAddressingRows: ApiRow[] = [
  { name: 'POST …/parts/storyboard.<i>/regenerate', type: '—', description: 'Re-images JUST shot i (the sibling shot list\'s CURRENT visual + the storyboard\'s authored style/filters) — other shots untouched.' },
  { name: 'POST …/parts/storyboard.<i>/refine', type: '{ instruction }', description: 'An AI EDIT of shot i\'s CURRENT image bytes — the same ai_edit seam an image_plan refine uses.' },
  { name: 'POST …/parts/storyboard.<i>/undo', type: '—', description: 'Restores shot i\'s previous image version; deletes the just-undone blob. Synchronous, like every other undo.' },
  { name: 'GET …/parts/storyboard.<i>/image', type: '—', description: 'Streams shot i\'s current produced image.' },
  { name: 'POST …/parts/storyboard.<i>/save-to-disk', type: '{ name?, folder_id? }', description: 'Promotes shot i\'s current image onto Disk.' },
];

// ── Bots in the generator — session delegation (R2 sub-stage 3) ─────────────
const delegationEndpointRows: ApiRow[] = [
  { name: 'POST /bots/{bot}/sessions/{session}/delegate', type: '{ auto_generate?: boolean, fill_mode?: \'gaps\' | \'fresh\' }', description: 'Compose the bot\'s voice, autonomously fill in-scope slots under fill_mode (AT MOST ONE metered ai_text call — none at all when there is nothing to fill), stamp the overlay. 200 (or 202 when auto_generate claimed a run). Owner-only (session update). fill_mode is OPTIONAL, DEFAULTS to gaps (the non-destructive reading). 403 not owner; 404 cross-workspace; 409 mid-run; 422 not editable (failed) OR fill_mode is not gaps/fresh; 429 ONLY when auto_generate is true AND the workspace is already over its AI $ cap — the fill itself never 429s, it just fills nothing (§15).' },
  { name: 'DELETE /bots/{bot}/sessions/{session}/delegate', type: '—', description: 'Undo: clear the overlay AND restore the pre-delegation slot_values from the snapshot. 200. Owner-only. 409 mid-run. Idempotent otherwise.' },
];
const delegationOverlayRows: ApiRow[] = [
  { name: 'bot_author', type: '{id,name,icon} | null', description: 'The SNAPSHOTTED bot-author {id,name,icon} — read off the overlay, never the live bot. null when undelegated.' },
  { name: 'is_delegated', type: 'boolean', description: 'Whether the delegation overlay is present.' },
  { name: 'can_delegate', type: 'boolean', description: 'owner AND status.isEditable() (draft/ready) — gates the "Delegate to bot" action.' },
  { name: 'can_undo_delegation', type: 'boolean', description: 'owner AND is_delegated AND status !== generating — gates "Undo delegation". NOT the same gate as can_delegate: a delegated FAILED session stays revertible even though it is not editable.' },
  { name: 'unfilled_required_slots', type: 'string[]', description: 'SOFT signal — required slots still without a usable value (incl. a required FILE slot, which the bot can never fill). NOT a hard generate-gate.' },
];
const fillReportReasonRows: ApiRow[] = [
  { name: 'filled', type: 'string[]', description: 'Slot names accepted and merged into slot_values. Whether a PRIOR human fill survives that merge depends on fill_mode: gaps never offers an already-filled slot, so it is untouched; fresh deliberately offers and overwrites every in-scope slot, prior fills included.' },
  { name: 'skipped — unknown_slot', type: 'reason', description: 'The model proposed a name that is not a declared slot on this session\'s snapshot.' },
  { name: 'skipped — out_of_scope', type: 'reason', description: 'A declared slot, but a FILE or deep-composite base — never offered to the bot in the first place.' },
  { name: 'skipped — invalid', type: 'reason', description: 'Re-validated against the slot\'s descriptor (the SAME authority a human write uses) and rejected — dropped, never persisted.' },
  { name: 'skipped — already_filled', type: 'reason', description: 'gaps mode ONLY — the model proposed a value for a slot the human had already filled; refused server-side (belt AND braces on top of it never being offered), so a value the human typed can never be overwritten.' },
  { name: 'unfilled_required', type: 'string[]', description: 'Required slots still empty after the fill — mirrors the resource\'s unfilled_required_slots.' },
  { name: 'mode', type: '\'gaps\' | \'fresh\'', description: 'The fill_mode that ACTUALLY ran (echoes the request, or gaps when the request omitted it) — so the UI can be honest about which choice executed.' },
  { name: 'nothing_to_fill', type: 'boolean', description: 'true when the bot had nothing to do — gaps mode with no empty in-scope slots (or no bot-fillable slots at all). NO AI call was made and NOTHING was spent; the delegation overlay is still stamped (the bot still becomes the author).' },
];

// ── Content quality — narrative contract + creative direction layer (ADR-0038) ─
const narrativeContractRows: ApiRow[] = [
  { name: 'Adaptive shot count', type: 'ShotListAgent::instructions()', description: '"Between 3 and {the run\'s EFFECTIVE cap}" (or "no more than {cap}" when the cap itself sits below 3) replaces the old fixed "3 to 5" — which previously OVERRODE a brief\'s stated duration (the diagnosed root cause of a 1–2 minute brief producing a ~15s script). 5–30s per shot is now a GUIDE, not a rule.' },
  { name: 'Precedence when they conflict', type: '—', description: 'A stated duration (brief or derived direction) AND the shot bound are BINDING; the 5–30s guide yields — the model is told to LENGTHEN beats past 30s rather than shorten the piece.' },
  { name: 'Degrading STORY block', type: 'ShotListAgent::storyClause(int $maxShots)', description: 'Through-line / escalation / payoff-set-up-earlier / continuous voiceover / subject-consistency — most stated ACROSS shots, so literally impossible at a cap of 1 and barely expressible at 2. THREE variants (>=3 / ==2 / ==1) rather than one unsatisfiable MUST at a low cap (which would make the model silently break a rule, arbitrarily).' },
];

const directionFieldRows: ApiRow[] = [
  { name: 'message, goal, audience, tone', type: 'string | null', description: 'One-line each, capped at 240 chars.' },
  { name: 'through_line', type: 'string | null', description: 'The spine — a protagonist/subject with something at stake, capped at 600 chars.' },
  { name: 'arc_beats', type: 'string[]', description: 'Ordered setup → escalation → payoff, capped at 12 entries.' },
  { name: 'subject, setting', type: 'string | null', description: '"subject" is a REUSABLE descriptor every later step repeats verbatim (keeps a recurring character/object worded identically across parts).' },
  { name: 'visual_style', type: '{medium, palette, lighting, camera}', description: 'How the piece LOOKS — the shared anchor that keeps independently generated images in one visual world (not the same pixels — see the honest limitation below).' },
  { name: 'duration_target_seconds', type: 'int | null', description: 'Whole seconds, only when the recipe states or clearly implies a duration.' },
  { name: 'continuity_notes', type: 'string | null', description: 'Free text, capped at 600 chars.' },
];

const directionInjectionRows: ApiRow[] = [
  { name: 'Every @[ai-text] block', type: 'forText()', description: 'message/goal/audience/tone/through_line, prefixed as a fenced "CREATIVE DIRECTION (data)" block ahead of the resolved prompt (GeneratorAiTextService::generate()).' },
  { name: 'shot_list generate/revise', type: 'forShotList()', description: 'through_line/arc_beats/subject/setting/duration_target_seconds(+tone), prefixed the same way (ShotListRenderer); ShotListAgent\'s SYSTEM instruction gets only a trusted, content-free "a direction block is present" flag — never the block\'s own content.' },
  { name: 'Every ai_generate image base', type: 'forImage()', description: 'visual_style + subject + continuity_notes ONLY (no goal/audience/message — an image model would try to DRAW them). Composed as an unfenced prose anchor AHEAD of the authored prompt/style/visual for a plain image_plan, a scene\'s image, AND every storyboard shot (prefixed there by a per-frame CONTINUITY clause too). Identity-resolved once composed, like the shot-list\'s own visual output.' },
];

const directionConfigRows: ApiRow[] = [
  { name: 'generator.direction.enabled', type: 'boolean (default true)', description: 'The kill switch. false ⇒ nothing derived/read/injected anywhere — byte-identical to a pre-layer run (pinned by a test).' },
  { name: 'generator.direction.max_chars', type: 'int (default 4000)', description: 'Length cap on the model\'s raw JSON reply, before it is even parsed.' },
  { name: 'generator.direction.max_input_chars', type: 'int (default 6000)', description: 'Hard cap on the derivation INPUT (the no-op-previewed recipe + the scalar slot digest).' },
  { name: 'ai.direction_timeout', type: 'seconds (default 30)', description: 'Tighter than ai.text_timeout (60s) so the one derivation call cannot eat into the run job\'s fixed 300s SIGALRM window (4 × 60 + 30 = 270s < 300s). A hung derivation fails closed; the run proceeds direction-less.' },
];
</script>

<template>
  <StoryPage
    title="Generator module (content templates + generation sessions)"
    description="A top-level Generator area with reusable, workspace-scoped content TEMPLATES (R2 sub-stage 1, content-recipe rework) and generation SESSIONS (R2 sub-stage 2, shipped) — the chat surface that executes a recipe for real. A template is a RECIPE, not a prompt — a content_type + declared typed SLOTS + a per-part content map authored as the finished post. A session snapshots a recipe, fills the slots, generates via a live budgeted AI-text call + a server image chain, and stays iteratively refinable (regenerate / instructed refine / undo) before a produced image is saved to Disk. The video_script rework adds a general cross-part `parts.<key>` context primitive plus a structured shot_list + executor-iterated storyboard (§18/§19). A session may also be DELEGATED to a bot (R2 sub-stage 3, shipped) — the bot autonomously fills its inputs and becomes the content's author, rendering in its own voice, while the human stays the owner (§20). A content-quality rework (§21) makes every generation in a run serve ONE shared creative direction and fixed a narrative contract that previously overrode a brief's stated duration/beat count. Documents implemented behavior only; remaining planned items (a user-created content type, native structured output for shot_list, bot autonomy beyond slot-fill, image-to-image chaining for exact cross-frame character consistency) are called out explicitly. The generate_content workflow step (R2 sub-stage 5) is DONE — see §11. Backend: app/modules/Generator/ + app/modules/Bot/ (the delegation edge). See docs/decisions/ADR-0032-generator-content-recipe.md, ADR-0033-ai-cost-meter.md, ADR-0034-generation-sessions.md, ADR-0035-video-script-cross-part-storyboard.md, ADR-0036-bot-delegation-generation-sessions.md, ADR-0037-ai-cost-limits.md (R2 sub-stage 4 — the $-first cost gate + per-workspace cap + actor attribution + pre-run 429, §15) and ADR-0038-creative-direction-layer.md (§21)."
  >

    <!-- 1. Module overview -->
    <StorySection title="Module overview — a template is a RECIPE, not a prompt">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          A <strong>Template</strong> is a stored, workspace-scoped, user-created RECIPE for a finished
          post — a "content factory": define its shape once, mass-produce concrete posts by filling a few
          typed inputs, with AI doing the delegated parts. It bundles a
          <code class="font-next-mono">content_type</code> (which PARTS the recipe is made of — see §2), a
          list of DECLARED typed <strong>slots</strong> (its named inputs), and a per-part
          <code class="font-next-mono">content</code> map authored AS THE FINISHED POST (never as a
          prompt-style instruction). A Template is a <strong>DEFINITION only</strong> — §1-§9 on this page
          cover authoring, storing, and faithfully PREVIEWING it, per part. Actually RUNNING one — a
          generation <strong>Session</strong> — is a separate concept, fully shipped as of R2 sub-stage 2
          (see §10 for the boundary between the two, then §11-§16 for the session engine itself).
        </p>

        <Alert variant="info" size="sm">
          <strong>This page replaces a rejected model.</strong> An earlier revision of this sub-stage
          modeled a template as an identity + a fixed <code class="font-next-mono">type</code> + a single
          <code class="font-next-mono">prompt_body</code> string + free-form
          <code class="font-next-mono">parameters</code>. That "prompt with parameters" shape was rejected
          before the sub-stage was accepted — it had no place for a media plan, and treated "the whole post
          is AI" as a special case instead of just one big <code class="font-next-mono">@[ai-text]</code>
          block. See <code class="font-next-mono">docs/decisions/ADR-0032-generator-content-recipe.md</code>.
        </Alert>

        <Alert variant="info" size="sm">
          <strong>One engine, not a fork.</strong> A template's variables reuse the SAME typed variable
          system the Workflows automation is built on — both CONSUME the shared
          <code class="font-next-mono">Variables</code> module (the type-system descriptors, the catalog
          composition, and the interpolation engine: the resolver, the operation executor, the pipeline
          validator). A slot surfaces as a <code class="font-next-mono">slots.&lt;name&gt;</code> catalog
          variable exactly as a workspace constant surfaces as
          <code class="font-next-mono">globals.&lt;key&gt;</code>, so the shared markdown editor, the
          variable picker, and the pipeline builder work here unchanged.
        </Alert>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-2 font-next-semibold text-next-fg">Shape</p>
          <pre class="overflow-x-auto rounded-next-md bg-next-muted p-next-3 font-next-mono text-next-xs text-next-fg">Template (definition)
  content_type    (post | post_with_image | video_script — a ContentTypeRegistry id)
  slots[]         (DECLARED typed inputs: { name, description?, descriptor })
  content         (a per-part map, keyed by the type's part keys; each part's shape
                    is decided by its KIND — text_body/script/image_plan/scene_plan)

Consumed by the shared Variables engine:
  catalog   POST /generator/catalog   →  the slots.* + globals.* variables the editor offers
  preview   POST /generator/preview   →  the FAITHFUL, PER-PART server-side render (real resolver)</pre>
        </div>

        <div class="grid grid-cols-1 gap-next-3 next-sm:grid-cols-2">
          <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
            <p class="mb-next-1 font-next-semibold text-next-fg">Backend module</p>
            <ul class="flex flex-col gap-next-1 font-next-mono text-next-xs text-next-muted-foreground">
              <li>app/modules/Generator/Models/Template.php</li>
              <li>app/modules/Generator/Enums/PartKind.php</li>
              <li>app/modules/Generator/Support/ContentTypeDefinition.php, ContentTypePart.php</li>
              <li>app/modules/Generator/Services/ContentTypeRegistry.php</li>
              <li>app/modules/Generator/Services/TemplateService.php</li>
              <li>app/modules/Generator/Services/TemplateVariableCatalog.php</li>
              <li>app/modules/Generator/Services/TemplateSlotValidator.php</li>
              <li>app/modules/Generator/Services/TemplateContentValidator.php</li>
              <li>app/modules/Generator/Services/ImagePlanValidator.php</li>
              <li>app/modules/Generator/Services/TemplateRenderService.php</li>
              <li>app/modules/Generator/Support/NoOpAiTextGenerator.php</li>
              <li>app/modules/Generator/Http/Controllers/ (content-types + CRUD + catalog + preview)</li>
              <li>app/modules/Generator/Policies/TemplatePolicy.php</li>
            </ul>
          </div>
          <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
            <p class="mb-next-1 font-next-semibold text-next-fg">One-way module boundary</p>
            <p class="text-next-xs text-next-muted-foreground">
              The Generator module <strong>consumes the Variables module and imports NOTHING from Workflows
              — ever</strong>. The TEMPLATE endpoints on this page additionally stay Disk-decoupled: an
              image plan's <code class="font-next-mono">disk_file</code> base id is stored OPAQUE (never
              looked up against Disk at write time). A generation <strong>Session</strong> (§11-§16) is the
              module's ONE deliberate exception — its image chain reads/writes Disk files at EXECUTION time
              (R2 sub-stage 2c) — so <code class="font-next-mono">Generator → Disk</code> is now an allowed,
              narrowly-scoped edge; <code class="font-next-mono">Generator → Workflows</code> stays
              forbidden everywhere. The preview binds a
              Generator-owned <code class="font-next-mono">NoOpAiTextGenerator</code> for the same reason —
              the shared resolver depends only on the Variables contract.
              <code class="font-next-mono">TemplateVariableCatalog</code> is the Generator twin of the
              workflow catalog — each builds its OWN domain variables and DELEGATES the shared pieces
              (globals, functions, types, operations) to the same
              <code class="font-next-mono">VariableCatalog</code>.
            </p>
          </div>
        </div>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-2 font-next-semibold text-next-fg">Auth + tenant scope</p>
          <p class="text-next-xs text-next-muted-foreground">
            All endpoints require <code class="font-next-mono">auth:sanctum</code> and
            <code class="font-next-mono">X-Workspace-Id</code>. The <code class="font-next-mono">TenantAware</code>
            trait scopes every query to the active workspace via
            <code class="font-next-mono">WorkspaceScope</code> (shared mode) or the tenant connection (own
            mode). <code class="font-next-mono">TemplatePolicy</code> gates READ on membership (any member)
            and MUTATION on ownership (the creator) — mirrors ConstantPolicy / CustomFunctionPolicy.
          </p>
        </div>
      </div>
    </StorySection>

    <!-- 2. Content types & parts -->
    <StorySection title="Content types & parts — the code-defined registry">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          A content type is DATA, not code — held in <code class="font-next-mono">ContentTypeRegistry</code>
          (no <code class="font-next-mono">content_types</code> table this sub-stage, the same precedent as
          consts/functions). It only DECLARES which PARTS a recipe is made of and whether each is required;
          a content type carries no behavior of its own. <strong>Every</strong> behavioral switch — the
          editor's data-driven sections, the write validator, the preview renderer — keys on a part's
          <code class="font-next-mono">kind</code> (a CLOSED, four-member vocabulary), <strong>never</strong>
          on the content type's <code class="font-next-mono">id</code>. A future user-created content type
          would only RECOMBINE these kinds — zero rework to any of them.
        </p>
        <ApiTable title="The 3 system content types (GET /generator/content-types)" type-header="Icon" :rows="contentTypeRows" />
        <ApiTable title="PartKind — the CLOSED vocabulary every part's behavior switches on" type-header="Content shape" :rows="partKindRows" />
        <Alert variant="info" size="sm">
          The editor's create-wizard STEP 1 is a card grid (<code class="font-next-mono">ContentTypePicker.vue</code>)
          fed straight from <code class="font-next-mono">GET /generator/content-types</code>, each card
          showing the parts it is made of. Editing an existing template locks its saved type — changing the
          recipe shape is a "start a new template" action, not an in-place migration.
        </Alert>
      </div>
    </StorySection>

    <!-- 3. Endpoints -->
    <StorySection title="Generator API endpoints">
      <div class="flex flex-col gap-next-4">
        <ApiTable title="Template endpoints (auth:sanctum + X-Workspace-Id)" :rows="endpointRows" type-header="Query / Body" />
        <Alert variant="info" size="sm">
          The static <code class="font-next-mono">content-types</code>,
          <code class="font-next-mono">catalog</code>, and <code class="font-next-mono">preview</code>
          routes are declared BEFORE the <code class="font-next-mono">generator/templates</code> resource,
          so those segments never bind as a <code class="font-next-mono">{template}</code> id.
          <code class="font-next-mono">catalog</code>/<code class="font-next-mono">preview</code> are POST
          because the whole in-progress DRAFT rides the request body, and both authorize on
          <code class="font-next-mono">viewAny</code> (any workspace member) — a preview/catalog runs on
          an UNSAVED template, so neither ever 422s on template content.
        </Alert>
      </div>
    </StorySection>

    <!-- 4. Request/resource shapes -->
    <StorySection title="Request and resource shapes">
      <div class="flex flex-col gap-next-4">
        <ApiTable title="TemplateWritePayload (POST body / PUT body)" :rows="writePayloadRows" />
        <ApiTable title="TemplateResource (index / show / store / update)" :rows="resourceRows" />
        <Alert variant="info" size="sm">
          There is <strong>no status/active concept</strong> here (unlike a Workflow) — a template is a
          plain reusable definition. Capability flags mirror the Constant/CustomFunction convention:
          <code class="font-next-mono">is_owner</code> is presentational, and
          <code class="font-next-mono">can_be_edited</code>/<code class="font-next-mono">can_be_deleted</code>
          are the server-authoritative gates the UI reads. The list envelope carries cursor meta only
          (<code class="font-next-mono">meta.next_cursor</code>) — no <code class="font-next-mono">total</code>.
          There is <strong>no legacy <code class="font-next-mono">type</code> /
          <code class="font-next-mono">prompt_body</code> / <code class="font-next-mono">parameters</code></strong>
          on the wire anywhere — the rework replaced them in place.
        </Alert>
      </div>
    </StorySection>

    <!-- 5. Slots -->
    <StorySection title="Slots — declared typed inputs">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          A <strong>slot</strong> is a DECLARED typed input a part's content references as
          <code class="font-next-mono">slots.&lt;name&gt;</code> — like a custom function's argument, but
          carrying a full type <code class="font-next-mono">descriptor</code>. Each is
          <code class="font-next-mono">{ name, description?, descriptor }</code>; the
          <code class="font-next-mono">descriptor</code> is the SAME
          <code class="font-next-mono">{ base, nullable?, array?, options?, fields? }</code> shape the
          constants/functions editors author, validated by the shared
          <code class="font-next-mono">ConstantTypeValidator</code> (plus the slot-only
          <code class="font-next-mono">file</code> composite) — so a slot's type can never accept a shape
          the resolver/catalog cannot understand. <strong>Unchanged by the content-recipe rework</strong> —
          only WHERE a body's directives live moved, from a single
          <code class="font-next-mono">prompt_body</code> to <code class="font-next-mono">content.&lt;part&gt;.markdown</code>
          per part.
        </p>
        <ApiTable title="Authorable slot bases (TemplateSlotsPanel type Select)" type-header="Kind" :rows="slotBaseRows" />

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-1 font-next-semibold text-next-fg">Slot names</p>
          <ul class="flex list-disc flex-col gap-next-1 pl-next-5 text-next-xs text-next-muted-foreground">
            <li>A safe identifier — <code class="font-next-mono">^[a-zA-Z_][a-zA-Z0-9_]&#123;0,62&#125;$</code> (letters, digits, underscores; not starting with a digit).</li>
            <li>DISTINCT across the template's slots.</li>
            <li>Not a RESERVED scope root — <code class="font-next-mono">slots</code>, <code class="font-next-mono">globals</code>, <code class="font-next-mono">trigger</code>, <code class="font-next-mono">steps</code>, <code class="font-next-mono">input</code>, <code class="font-next-mono">element</code>, <code class="font-next-mono">index</code> — so a slot can never shadow a resolution root. Validated client-side (friendly copy) AND server-side (authoritative).</li>
          </ul>
        </div>

        <ApiTable title="file composite subfields (fixed — VariableType::fileSubfieldTypes())" type-header="Type" :rows="fileSubfieldRows" />

        <Alert variant="info" size="sm">
          <strong>Subfields are individually referenceable.</strong> An object slot's declared fields
          (<code class="font-next-mono">slots.product.name</code>) and a file slot's fixed subfields
          (<code class="font-next-mono">slots.image.url</code>) are each a KNOWN reference that
          write-validates and type-flows from the subfield's own base — via the SAME descriptor descent the
          workflow catalog uses, never a fork. An <strong>unknown</strong> subfield of a known object/file
          slot is REJECTED at write, symmetric with the unknown-slot check. A repeater / array&lt;object&gt;
          per-element path stays deferred (the R2 loop — see Planned). A <strong>file</strong> slot is also
          the ONLY thing an image plan's <code class="font-next-mono">from_slot</code> base may name — see §7.
        </Alert>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-1 font-next-semibold text-next-fg text-next-sm">Example — a post template's slots</p>
          <pre class="overflow-x-auto rounded-next-md bg-next-muted p-next-3 font-next-mono text-next-xs text-next-fg">"slots": [
  &#123; "name": "topic",    "descriptor": &#123; "base": "text" &#125; &#125;,
  &#123; "name": "tone",     "descriptor": &#123; "base": "enum", "options": [&#123; "key": "fun" &#125;, &#123; "key": "serious" &#125;] &#125; &#125;,
  &#123; "name": "hashtags", "descriptor": &#123; "base": "text", "array": true &#125; &#125;,
  &#123; "name": "hero",     "descriptor": &#123; "base": "file", "nullable": true &#125; &#125;
]
// referenced in a part's markdown as: slots.topic, slots.tone, slots.hashtags, slots.hero.url</pre>
        </div>
      </div>
    </StorySection>

    <!-- 6. Authoring the body -->
    <StorySection title="Authoring the body — first-class AI blocks (text_body / script)">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          A <code class="font-next-mono">text_body</code>/<code class="font-next-mono">script</code> part's
          <code class="font-next-mono">content.&lt;key&gt;.markdown</code> is authored AS THE FINISHED
          POST — static text + slot values, never a prompt-style instruction. It is authored in the SHARED
          <code class="font-next-mono">ui/editor/MarkdownEditor</code> — the same one the workflow step
          fields use — fed the template catalog. Two buttons PROMOTE
          <code class="font-next-mono">@[ai-text]</code> to a first-class affordance:
          <strong>"AI fragment"</strong> inserts one inline AI block at the cursor;
          <strong>"Whole post by AI"</strong> inserts the SAME block — "the whole post is AI" is not a
          separate mode, it is simply one big <code class="font-next-mono">@[ai-text]</code> block occupying
          the whole body. Both reuse the existing chip command (<code class="font-next-mono">insertAiText</code>)
          — the chip itself is never forked.
        </p>
        <ApiTable title="What the variable picker offers (POST /generator/catalog)" type-header="Wire" :rows="pickerSourceRows" />

        <Alert variant="info" size="sm">
          <strong>The catalog is LIVE.</strong> As the user declares slots, the editor re-fetches
          <code class="font-next-mono">POST /generator/catalog</code> (debounced) with the current valid
          <code class="font-next-mono">{ name, descriptor }</code> draft slots, so the
          <code class="font-next-mono">&#123;</code>-insert browser, the chip pipeline, and the arg-variable
          pool immediately offer <code class="font-next-mono">slots.&lt;name&gt;</code> (and its object/file
          subfields) before the form is valid or saved. Unchanged by this rework.
        </Alert>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-1 font-next-semibold text-next-fg text-next-sm">Write-time validation (TemplateContentValidator → TemplateSlotValidator)</p>
          <p class="text-next-xs text-next-muted-foreground">
            On save, each part's <code class="font-next-mono">content.&lt;key&gt;.markdown</code> is checked
            through the SAME body authority a workflow directive uses — now PART-AGNOSTIC (it takes the
            exact field key to report errors under, so the identical check gates a
            <code class="font-next-mono">text_body</code>/<code class="font-next-mono">script</code> part, a
            scene's narration, and an image plan's <code class="font-next-mono">ai_edit</code>/
            <code class="font-next-mono">ai_generate</code> prompt). Every decodable
            <code class="font-next-mono">@[variable]</code> directive is checked against the template
            reference index (declared slots + globals): an unknown
            <code class="font-next-mono">slots.&lt;name&gt;</code> (or unknown object/file subfield) is a
            <code class="font-next-mono">422</code>, and each directive's PIPELINE is type-flowed through the
            shared <code class="font-next-mono">PipelineValidator</code>. <strong>Fail-soft on noise,
            fail-closed on error:</strong> a malformed/undecodable directive renders inert at runtime, so it
            is SKIPPED here rather than falsely rejected. An UNKNOWN part key, or a missing REQUIRED part,
            is also a <code class="font-next-mono">422</code> — this is data-driven per the selected content
            type (§2), an OPTIONAL part may be omitted entirely.
          </p>
        </div>

        <ApiTable title="@[ai-text] personas (closed tone set, label-less on the wire)" :rows="aiPersonaRows" />
      </div>
    </StorySection>

    <!-- 7. Image plan -->
    <StorySection title="Image plan — a declared base + an ordered filter chain">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          An <code class="font-next-mono">image_plan</code> part — a
          <code class="font-next-mono">post_with_image</code>'s optional <code class="font-next-mono">image</code>
          part, or a scene's optional image (§8) — is a DECLARED media plan, mirroring the shape of a
          Variables pipeline: a <code class="font-next-mono">base</code> (where the image STARTS) plus an
          ordered <code class="font-next-mono">filters</code> CHAIN (how it is transformed). This is
          <strong>authoring only</strong> — see §10 for what that means: nothing here fetches, generates, or
          edits an actual image.
        </p>
        <ApiTable title="Image base kinds" type-header="Wire" :rows="imageBaseRows" />
        <ApiTable title="Filter chain — one entry, in order" type-header="Wire" :rows="filterKindRows" />
        <ApiTable title="Pixel ops (ImagePlanValidator::PIXEL_OPS)" type-header="Params" :rows="pixelOpRows" />

        <Alert variant="info" size="sm">
          <strong><code class="font-next-mono">ai_generate</code> is LIVE (R2 sub-stage 6).</strong> The
          write path accepts it, the preview resolves its prompt into the plan summary, and the editor's base
          picker offers it as a normal, selectable, savable choice — a real generation Session resolves the
          prompt and generates the base image for real through the metered
          <code class="font-next-mono">ai_image_generate</code> seam (see §15). The video_script rework's
          <code class="font-next-mono">storyboard</code> part (§19) also drives an
          <code class="font-next-mono">ai_generate</code> call per shot, but that one is fully AUTOMATIC —
          a storyboard has no authored <code class="font-next-mono">base</code> field at all.
        </Alert>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-1 font-next-semibold text-next-fg text-next-sm">Example — a from_slot base + a two-step chain</p>
          <pre class="overflow-x-auto rounded-next-md bg-next-muted p-next-3 font-next-mono text-next-xs text-next-fg">"image": &#123;
  "base": &#123; "kind": "from_slot", "slot": "photo" &#125;,
  "filters": [
    &#123; "kind": "pixel", "op": "grayscale" &#125;,
    &#123; "kind": "pixel", "op": "brightness", "params": &#123; "amount": 20 &#125; &#125;,
    &#123; "kind": "ai_edit", "prompt": "In the style of &#123;slots.topic&#125;." &#125;
  ]
&#125;</pre>
        </div>

        <Alert variant="info" size="sm">
          The editor's base picker for <code class="font-next-mono">disk_file</code> REUSES the shared
          <code class="font-next-mono">DiskFilePickerModal</code> so a user picks a file by name (the wire
          still only carries its opaque id). The chain builder offers add / remove / reorder for pixel steps
          and AI-edit steps alike; a pixel step's param control (amount / crop rect / rotation / axis) is
          chosen by its op. An optional, OFF-by-default client dry-run
          (<code class="font-next-mono">imagePlan.ts::dryRunPixelChain()</code>) reuses the SAME pure
          <code class="font-next-mono">imageOps.ts</code> functions the Disk image editor uses for the
          deterministic ops only — never the AI-edit steps, and never on by default.
        </Alert>
      </div>
    </StorySection>

    <!-- 8. Scene plan -->
    <StorySection title="Scene plan — an ordered list of scenes (legacy)">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <Alert variant="warning" size="sm">
          <strong>Legacy — no longer authorable.</strong> The video_script rework (Phase B, §19) dropped
          <code class="font-next-mono">scene_plan</code> from <code class="font-next-mono">video_script</code>'s
          composition (replaced by <code class="font-next-mono">shot_list</code>/<code class="font-next-mono">storyboard</code>);
          no content type declares a <code class="font-next-mono">scene_plan</code>-kind part today, so the
          create-wizard never offers this section for a NEW template. The shape and its validation below are
          UNCHANGED and still fully editable for a template authored BEFORE the rework
          (<code class="font-next-mono">TemplateScenePlanPart.vue</code> still renders it) — and it still
          renders/refines at the SESSION layer for an existing session's snapshot (§14). See
          <code class="font-next-mono">docs/decisions/ADR-0035-video-script-cross-part-storyboard.md</code>.
        </Alert>
        <p class="text-next-muted-foreground">
          A <code class="font-next-mono">scene_plan</code> part is an ORDERED list of scenes, each a
          NARRATION (a <code class="font-next-mono">text_body</code>-like markdown body) plus an OPTIONAL
          nested <code class="font-next-mono">image_plan</code> — the identical shape §7 describes, reused
          verbatim per scene. This shape is <strong>reserved now, with LEAN v1 authoring</strong>: it is
          fully modeled and validated, but a template's scene list may be entirely empty — the part itself
          is optional.
        </p>
        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-1 font-next-semibold text-next-fg text-next-sm">Example</p>
          <pre class="overflow-x-auto rounded-next-md bg-next-muted p-next-3 font-next-mono text-next-xs text-next-fg">"scene_plan": &#123;
  "scenes": [
    &#123; "narration": &#123; "markdown": "Open on &#123;slots.topic&#125;." &#125; &#125;,
    &#123;
      "narration": &#123; "markdown": "Wide shot." &#125;,
      "image_plan": &#123;
        "base": &#123; "kind": "ai_generate", "prompt": "A wide shot of &#123;slots.topic&#125;." &#125;,
        "filters": [ &#123; "kind": "pixel", "op": "sepia" &#125; ]
      &#125;
    &#125;
  ]
&#125;</pre>
        </div>
        <Alert variant="info" size="sm">
          The scene editor (<code class="font-next-mono">TemplateScenePlanPart.vue</code>) is deliberately
          modest: add / remove / reorder scenes by button, a plain narration editor
          (<code class="font-next-mono">TemplateBodyPart.vue</code>, reused verbatim) and an opt-in switch
          that reveals the SAME image-plan builder §7 uses
          (<code class="font-next-mono">TemplateImagePlanPart.vue</code>, also reused verbatim — nothing about
          a scene's image is a separate component). The SCHEMA and validation are final; drag-to-reorder and
          richer per-scene metadata are UX polish left for later (see Planned).
        </Alert>
      </div>
    </StorySection>

    <!-- 9. Live preview -->
    <StorySection title="Live preview — faithful, per-part server-side render">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          The editor's right column is a FAITHFUL live preview. It authors one SAMPLE VALUE per declared
          slot (typed by the slot's descriptor via the shared
          <code class="font-next-mono">TypedLiteralInput</code>) and calls
          <code class="font-next-mono">POST /generator/preview</code> (debounced) with
          <code class="font-next-mono">&#123; content_type, content, slots, slot_values &#125;</code>. The
          backend renders EVERY declared part of the selected type, by KIND, through the SAME shared
          <code class="font-next-mono">VariableResolver</code> the workflow run path uses — the real
          executor, directives, if-blocks, pipelines, custom functions, and NUL-mask injection guards.
          <strong>The interpolation engine runs on the BACKEND — the FE never re-implements it.</strong>
          Each part's result renders BY KIND: a text/script part through the shared
          <code class="font-next-mono">MarkdownViewer</code>; an image-plan part as a PLAN card (base +
          ordered chain); a scene-plan part as a per-scene summary.
        </p>

        <Alert variant="warning" size="sm">
          <strong><code class="font-next-mono">@[ai-text]</code> renders INERT-but-LABELED this sub-stage.</strong>
          The preview resolver is bound with the Generator's <code class="font-next-mono">NoOpAiTextGenerator</code>
          (it never calls a model), which emits a labeled <code class="font-next-mono">[AI: &lt;resolved prompt&gt;]</code>
          placeholder — never empty for a non-blank prompt — so an author sees exactly WHERE an AI block
          lands and against WHICH resolved prompt, without a real AI call running. A persistent legend
          states blocks are "generated at run time". Real, budgeted, persona-aware generation arrives with
          a generation Session (see §10 / Planned).
        </Alert>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-1 font-next-semibold text-next-fg text-next-sm">States + fail-soft</p>
          <ul class="flex list-disc flex-col gap-next-1 pl-next-5 text-next-xs text-next-muted-foreground">
            <li><strong>Seeding hint</strong> when no slots are declared, <strong>loading skeleton</strong> while a render is in flight, <strong>error + retry</strong> on a failed call, and the <strong>rendered per-part output</strong> on success.</li>
            <li>Every path is FAIL-SOFT: an unresolvable reference becomes <code class="font-next-mono">null</code>/<code class="font-next-mono">''</code>/an empty plan, and a hard <code class="font-next-mono">assert_present</code> (the one directive path that re-raises) is caught and degraded rather than surfacing a 500 — a preview is advisory, never a run.</li>
            <li>A nullable slot exposes an explicit "no value" toggle; an object/file slot authors a per-field sample snapshot; an array slot authors a repeatable element list.</li>
            <li>An <code class="font-next-mono">image_plan</code> part's preview is NEVER an actual image — only a resolved-text PLAN summary (base label + ordered filter labels, each AI prompt resolved against the sample slot values).</li>
          </ul>
        </div>
      </div>
    </StorySection>

    <!-- 10. Declare vs. execute -->
    <StorySection title="Declare vs. execute — a template's boundary with a generation session">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          Every capability on THIS page (§1–§9) sits behind ONE boundary: authoring a Template — its content
          type, its slots, its per-part content including an image plan's base + filter chain — is a pure
          DECLARATION. It is write-validated FAIL-CLOSED (so a malformed recipe can never be saved) and
          preview-rendered FAIL-SOFT (so a draft always shows something, best-effort).
          <strong>Nothing on the TEMPLATE endpoints EXECUTES anything.</strong> Execution is a
          <strong>generation Session</strong> (R2 sub-stage 2 — 2a-2d, all SHIPPED): §11 onward documents it.
        </p>
        <div class="grid grid-cols-1 gap-next-3 next-sm:grid-cols-2">
          <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
            <p class="mb-next-1 font-next-semibold text-next-fg">A Template preview (§9) — declaration only</p>
            <ul class="flex list-disc flex-col gap-next-1 pl-next-5 text-next-xs text-next-muted-foreground">
              <li>The SHARED resolver's directive/pipeline/if-block engine — a real text render.</li>
              <li>A resolved-TEXT plan summary for an image plan (base label + ordered filter labels) — no image executed.</li>
              <li>An inert-but-labeled <code class="font-next-mono">[AI: …]</code> placeholder for every <code class="font-next-mono">@[ai-text]</code> block — no real AI call.</li>
            </ul>
          </div>
          <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
            <p class="mb-next-1 font-next-semibold text-next-fg">A generation Session run (§11-§16) — real execution</p>
            <ul class="flex list-disc flex-col gap-next-1 pl-next-5 text-next-xs text-next-muted-foreground">
              <li>A real, budgeted, METERED AI-text call for each <code class="font-next-mono">@[ai-text]</code> block.</li>
              <li>Resolving an image plan's <code class="font-next-mono">disk_file</code>/<code class="font-next-mono">from_slot</code> base to real bytes — the existence/ownership check Disk-decoupled authoring defers happens HERE.</li>
              <li>Running the filter chain for real — pixel ops applied via Imagick, <code class="font-next-mono">ai_edit</code> steps calling <code class="font-next-mono">ImageAiService::edit()</code> (metered).</li>
              <li>Real AI-cost metering — a gate-before-spend workspace calendar-month token cap + a recorded ledger.</li>
              <li>An iterative per-part refine loop (regenerate / instructed refine / undo, versioned) and saving a produced image onto Disk.</li>
              <li><code class="font-next-mono">ai_generate</code> is RUNNABLE (R2 sub-stage 6) — a real generation resolves its prompt and generates the base image through the metered <code class="font-next-mono">ai_image_generate</code> seam.</li>
            </ul>
          </div>
        </div>
      </div>
    </StorySection>

    <!-- 11. Generation Sessions — overview -->
    <StorySection title="Generation Sessions — overview + the async run/status machine">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          A <strong>Session</strong> is ONE execution of a Template recipe. Creating one
          (<code class="font-next-mono">POST /generator/sessions &#123;template_id&#125;</code>) SNAPSHOTS that
          template's <code class="font-next-mono">&#123;content_type, slots, content&#125;</code> into
          <code class="font-next-mono">recipe_snapshot</code> — every later render (the first generate, a
          regenerate, a refine) reads ONLY that snapshot + the session's own
          <code class="font-next-mono">slot_values</code>, never the live template. Editing or deleting the
          source template afterward never changes an existing session.
        </p>
        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-2 font-next-semibold text-next-fg">Shape</p>
          <pre class="overflow-x-auto rounded-next-md bg-next-muted p-next-3 font-next-mono text-next-xs text-next-fg">GenerationSession
  template_id       (provenance only — nullable, no FK; the session outlives an edited/deleted template)
  recipe_snapshot     (&#123;content_type, slots, content&#125; captured at creation — never re-read)
  slot_values             (the user's filled inputs)
  results                    (per-part outcome map — see §12 "The results map")
  status                        (draft → generating → ready | failed)
  history + archived_at            (bounded per-part undo stack; the archive-freeze marker)</pre>
        </div>
        <ApiTable title="Status machine (GenerationSessionStatus)" type-header="Editable?" :rows="sessionStatusRows" />
        <Alert variant="info" size="sm">
          <strong>The claim is atomic and race-free.</strong> Kicking off ANY run — the whole-session
          generate OR a per-part regenerate/refine — is a guarded
          <code class="font-next-mono">UPDATE … WHERE status IN (draft,ready,failed)</code>; only the caller
          that wins the affected-row race gets to queue the job. A concurrent double-click, a second tab, or
          a whole-session generate racing a per-part refine all see a <code class="font-next-mono">409</code>
          — never a double (billed) run. Exactly ONE async op runs per session at a time. A run is queued
          off the request (<code class="font-next-mono">RunGenerationSessionJob</code>); on the terminal
          transition the backend broadcasts <code class="font-next-mono">.generation-session.updated</code>
          on the private channel <code class="font-next-mono">generator.workspace.&#123;workspaceId&#125;</code>.
          The FE WAITS for that push (<code class="font-next-mono">useSessionSettle()</code>) then re-fetches
          <code class="font-next-mono">GET /generator/sessions/&#123;id&#125;</code> ONCE for the results —
          it does not poll (see
          <code class="font-next-mono">docs/decisions/ADR-0034-generation-sessions.md</code>).
        </Alert>
        <Alert variant="info" size="sm">
          <strong>Fail-soft per part; fail-closed only at the run level.</strong> A broken directive, an
          unresolvable image base, or an exhausted per-run AI budget degrades ONLY that part to
          <code class="font-next-mono">&#123;status:'failed', error&#125;</code> — every other part still runs,
          and the session still lands <code class="font-next-mono">ready</code>. Only an infra fault
          escaping the whole run (e.g. a database error) fails the SESSION itself
          (<code class="font-next-mono">status: 'failed'</code>).
        </Alert>
        <Alert variant="info" size="sm">
          <strong>A workflow can run a template too (R2 sub-stage 5).</strong> A
          <code class="font-next-mono">generate_content</code> workflow step
          (<code class="font-next-mono">resources/js/next/docs/pages/WorkflowsPage.vue</code>) runs a
          session end-to-end with no human in the loop, through this module's ONE HTTP-free automation
          seam (<code class="font-next-mono">Generator\Services\SessionAutomationService</code>) — the
          SAME claim/status-machine/image-chain/refine-loop engine described on this page, not a second
          implementation. Such a session is created INSIDE the workflow run, so its
          <code class="font-next-mono">creator</code> is <code class="font-next-mono">workflow_run</code>
          (visible in the sessions list like any other creator) and every image it produces is exported
          to Disk with <code class="font-next-mono">uploader_type='workflow_run'</code>. It is otherwise
          an ORDINARY session — openable in the chat, refinable, undoable, archivable by any workspace
          member the usual creator-only gate allows. See
          <code class="font-next-mono">docs/backend/generator-sessions-api.md</code> → "Automation seam
          (R2 sub-stage 5)" and
          <code class="font-next-mono">docs/decisions/ADR-0039-workflow-suspend-resume-and-generate-content.md</code>.
        </Alert>
      </div>
    </StorySection>

    <!-- 12. Sessions API endpoints -->
    <StorySection title="Sessions API endpoints">
      <div class="flex flex-col gap-next-4">
        <ApiTable title="Session CRUD + run endpoints (auth:sanctum + X-Workspace-Id)" :rows="sessionCrudRows" type-header="Method" />
        <ApiTable title="Per-part refine loop + image + archive (R2 sub-stage 2c/2d)" :rows="sessionPartRows" type-header="Method" />
        <Alert variant="info" size="sm">
          Full request/response shapes, error codes, and worked examples:
          <code class="font-next-mono">docs/backend/generator-sessions-api.md</code>. A foreign-workspace or
          soft-deleted <code class="font-next-mono">&#123;session&#125;</code> 404s at route-model binding —
          never 403. Every mutation + the generate/regenerate/refine/undo/archive actions are CREATOR-only
          (<code class="font-next-mono">GenerationSessionPolicy</code>); read (list/show/serve) is any
          workspace member.
        </Alert>
        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-1 font-next-semibold text-next-fg text-next-sm">The <code class="font-next-mono">results</code> map — one entry per part key</p>
          <pre class="overflow-x-auto rounded-next-md bg-next-muted p-next-3 font-next-mono text-next-xs text-next-fg">&#123;
  "body":  &#123; "kind": "text_body",  "status": "ok", "text": "…generated text…", "version": 2 &#125;,
  "image": &#123; "kind": "image_plan", "status": "ok",
             "image": &#123; "mime": "image/png", "width": 1024, "height": 1024, "version": 1 &#125;,
             "version": 1 &#125;
&#125;
// a failed part: &#123; kind, status:'failed', error: '&lt;localized, non-secret&gt;' &#125; — no text/image key at all</pre>
          <p class="mt-next-2 text-next-xs text-next-muted-foreground">
            An <code class="font-next-mono">image_plan</code> result never carries bytes — the chat fetches
            them from the serve endpoint. Every <code class="font-next-mono">error</code> is a LOCALIZED,
            NON-SECRET string — never the resolved prompt, the refine instruction, or a provider response. A
            <code class="font-next-mono">shot_list</code> result additionally carries
            <code class="font-next-mono">&#123;hook, shots, cta, text, parse_ok&#125;</code>; a
            <code class="font-next-mono">storyboard</code> result carries per-shot
            <code class="font-next-mono">shots</code> (nested images, no top-level <code class="font-next-mono">version</code>);
            any result may carry <code class="font-next-mono">stale: true</code> — see §18/§19 below for the
            video_script rework's shapes.
          </p>
        </div>
      </div>
    </StorySection>

    <!-- 13. The chat surface -->
    <StorySection title="The chat surface — fill slots, generate, per-part turns">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          <code class="font-next-mono">SessionChatView.vue</code> reads a session as a CONVERSATION: a
          collapsible <strong>Setup</strong> turn (the typed slot form, expanded while
          <code class="font-next-mono">draft</code>, collapsed to a one-line input-chip summary once the
          session has generated at least once — still editable), then one turn per DECLARED part, in the
          content type's order, once results exist. While
          <code class="font-next-mono">generating</code>, per-part skeleton turns show the run is in flight.
          At ≥ <code class="font-next-mono">next-xl</code> a docked <strong>"Gotowy post"</strong> rail
          (<code class="font-next-mono">FinalPostPane.vue</code>) pins the assembled result + its Save
          action alongside the scrolling conversation; below that breakpoint the same body
          (<code class="font-next-mono">FinalPostBody.vue</code>) renders as the LAST turn instead — the
          content is always reachable, never a competing layout.
        </p>
        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-1 font-next-semibold text-next-fg text-next-sm">Per-part turn body, by kind (<code class="font-next-mono">SessionResultCard.vue</code>)</p>
          <ul class="flex list-disc flex-col gap-next-1 pl-next-5 text-next-xs text-next-muted-foreground">
            <li><strong>text_body / script</strong> — the produced markdown via the shared <code class="font-next-mono">MarkdownViewer</code>; a failed part shows a danger Alert with the localized error instead (other parts still render).</li>
            <li><strong>image_plan</strong> — the produced image (<code class="font-next-mono">SessionPartImage.vue</code>, bytes fetched from the authorized serve endpoint) or a failed danger Alert.</li>
            <li><strong>scene_plan</strong> <em>(legacy)</em> — each scene's narration + its optional produced image / per-scene error, each scene image individually savable.</li>
            <li><strong>shot_list</strong> — the hook / timed shots / cta (video_script rework, §19); a non-JSON reply renders its raw text with a "couldn't structure" note (<code class="font-next-mono">parse_ok:false</code>).</li>
            <li><strong>storyboard</strong> — each shot's beat + its produced image / per-shot error, each shot image individually regeneratable/refinable/savable via <code class="font-next-mono">storyboard.&lt;i&gt;</code> (§19); a "may be out of date" badge when <code class="font-next-mono">stale</code>.</li>
          </ul>
        </div>
        <p class="text-next-muted-foreground">
          A sticky <strong>composer</strong> at the bottom (<code class="font-next-mono">SessionComposer.vue</code>)
          is a free-text instruction + send, targeting a produced part (defaulting to the primary text part; a
          Select appears once more than one part is refinable) — it calls the SAME
          <code class="font-next-mono">refine</code> endpoint the per-part "Dopracuj" affordance uses, so the
          composer is just another way to reach the same refine loop (§14).
        </p>
        <Alert variant="info" size="sm">
          The FE NEVER interpolates a directive or runs a pixel op client-side for a session result — every
          turn's content is exactly what the server produced. "Nowa sesja" (<code class="font-next-mono">TemplatePickerModal.vue</code>)
          only picks a template; the user fills slot values inside the chat's Setup turn, not in a separate wizard.
        </Alert>
      </div>
    </StorySection>

    <!-- 14. The refine loop -->
    <StorySection title="The refine loop — regenerate, instructed refine, undo, versions">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          Once a part has a produced ("ok") result, THREE distinct affordances act on it — not one
          "edit" concept:
        </p>
        <ApiTable title="Per-part refine-loop affordances" type-header="Endpoint" :rows="refineLoopRows" />
        <Alert variant="info" size="sm">
          <strong>A failed part-op is a content no-op, but the outcome is still surfaced.</strong> A failed
          regenerate/refine leaves the current good result and history UNTOUCHED (it must never clobber a
          working result or push a bogus entry onto the undo stack) — but
          <code class="font-next-mono">last_op_status</code> / <code class="font-next-mono">last_op_error</code>
          on the session resource are stamped with the outcome so the chat can toast a genuine failure
          instead of silently settling back to the SAME version with no signal anything happened. Both are
          cleared at the next claim.
        </Alert>
        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-1 font-next-semibold text-next-fg text-next-sm">Version + history bookkeeping</p>
          <p class="text-next-xs text-next-muted-foreground">
            Every regenerate/refine bumps a per-part <code class="font-next-mono">version</code> (a
            "Wersja N" badge in the UI) and pushes the PRIOR result onto that part's BOUNDED
            <code class="font-next-mono">history</code> stack (capped at
            <code class="font-next-mono">generator.history_max_versions</code>, default 20 — the oldest
            entry is dropped, and its produced-image blob garbage-collected, on overflow). The session
            resource exposes only the lean <code class="font-next-mono">part_history</code> summary
            (<code class="font-next-mono">&#123; can_undo, undo_depth &#125;</code> per part with a prior version) —
            never the full stack. Produced images are stored VERSIONED from the start
            (<code class="font-next-mono">generator-sessions/&lt;workspace&gt;/&lt;session&gt;/&lt;partKey&gt;/&lt;version&gt;.png</code>),
            which is what lets Undo restore real bytes with zero storage-layer changes.
          </p>
        </div>
      </div>
    </StorySection>

    <!-- 15. The image chain + cost meter + budget limits -->
    <StorySection title="The image chain + AI cost limits (R2 sub-stage 4, ADR-0037)">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          An <code class="font-next-mono">image_plan</code> part EXECUTES synchronously inside the session's
          async job (no nested queue): <strong>resolve the base</strong> to real bytes — the module's ONE
          deliberate <code class="font-next-mono">Generator → Disk</code> READ edge (a
          <code class="font-next-mono">disk_file</code>/<code class="font-next-mono">from_slot</code> id is
          looked up tenant-scoped, so a foreign id is simply not found; an
          <code class="font-next-mono">ai_generate</code> base runs a real, metered text→image call, see
          §7) — then <strong>run the ordered filter chain</strong> via <code class="font-next-mono">Imagick</code>:
          a <code class="font-next-mono">pixel</code> step is an EXACT server-side re-expression of the
          browser editor's math (<code class="font-next-mono">imageOps.ts</code> is the fidelity authority —
          Rec.601 luma, matching rounding, HDRI-clamped), an <code class="font-next-mono">ai_edit</code> step
          calls <code class="font-next-mono">Disk\Services\ImageAiService::edit()</code> — metered and
          budget-gated. The produced PNG is stored as a new VERSION; the JSON result never carries bytes.
        </p>
        <Alert variant="info" size="sm">
          A REFINE of an image part skips the base/chain entirely: the CURRENT produced bytes are the base,
          and the instruction IS the one <code class="font-next-mono">ai_edit</code> prompt.
        </Alert>

        <p class="text-next-muted-foreground">
          <strong>The cost meter is $-first since R2 sub-stage 4.</strong> Every AI spend a session drives —
          <code class="font-next-mono">ai_text</code>, <code class="font-next-mono">ai_image_edit</code>,
          <code class="font-next-mono">ai_image_generate</code> — routes through the shared D7 ledger seam
          (real since R2 sub-stage 2a), which now GATES on an ESTIMATED DOLLAR figure
          (<code class="font-next-mono">estimated_cost</code>, computed from a per-channel
          <code class="font-next-mono">pricing</code> config map) instead of a token count. Every $ figure
          on the wire and in the UI carries an "estimated" caveat — this is a soft operational budget, never
          a real bill.
        </p>
        <ApiTable title="Cost meter + budget config" type-header="Scope" :rows="costMeterRows" />
        <Alert variant="warning" size="sm">
          <strong>Two independent budgets stack.</strong> The workspace-wide MONTHLY $ cap is a hard
          gate-before-spend shared by every AI spender in the app (Workflows, Disk, Generator) — `0`
          (the shipped default) disables it. ON TOP of it, a session run carries its OWN per-run CALL-COUNT
          ceilings (the table above) purely to bound one recipe's fan-out, not a dollar budget. An
          exhausted text budget resolves that <code class="font-next-mono">@[ai-text]</code> block to
          <code class="font-next-mono">''</code> (the run still completes, MID-RUN fail-soft); an exhausted
          image budget fails ONLY that image part. This is DISTINCT from the pre-run 429 gate below, which
          fires BEFORE a run is even claimed. See
          <code class="font-next-mono">docs/decisions/ADR-0033-ai-cost-meter.md</code> (the original seam)
          and <code class="font-next-mono">docs/decisions/ADR-0037-ai-cost-limits.md</code> (the $ cutover).
        </Alert>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-1 font-next-semibold text-next-fg text-next-sm">Workspace AI-usage page (owner cap editor)</p>
          <p class="text-next-xs text-next-muted-foreground">
            A dedicated <code class="font-next-mono">settings/ai-usage</code> page (reached from the
            user-menu "AI usage" entry) shows the current-month meter for ANY workspace member — a
            green/amber/red <code class="font-next-mono">Progress</code> bar paired with a
            <code class="font-next-mono">StatusBadge</code> (never color-only), the $ used/cap/remaining
            headline figures, a per-channel <code class="font-next-mono">StatsGrid</code>, and a per-actor
            breakdown (who spent it — a member, a bot, or an automation — with a graceful fallback label
            when the resolved name is null). An UNCAPPED workspace renders a clean "no limit" line instead
            of a scary empty meter. The OWNER additionally sees a cap editor
            (<code class="font-next-mono">AiUsageCapEditor.vue</code>) — three mutually-exclusive modes
            mapping 1:1 to the backend semantics: set a $ limit, explicit "no limit" for this workspace, or
            "use the platform default" — gated on the server-authoritative
            <code class="font-next-mono">summary.can_manage</code> flag, never a client role guess. See
            <code class="font-next-mono">docs/backend/workspace-ai-usage-api.md</code> for the endpoint
            contract.
          </p>
        </div>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-1 font-next-semibold text-next-fg text-next-sm">Inline budget signals inside a session</p>
          <p class="text-next-xs text-next-muted-foreground">
            <code class="font-next-mono">SessionBudgetChip.vue</code> — a compact wallet-icon
            <code class="font-next-mono">Badge</code> near the composer — stays HIDDEN for an uncapped
            workspace and otherwise shows nothing until the warn threshold is reached (an amber "N% of
            budget" chip), then red "Budget reached" once <code class="font-next-mono">blocked</code>.
            <code class="font-next-mono">SessionBudgetBanner.vue</code> — a dismissible danger
            <code class="font-next-mono">Alert</code> — surfaces from a TYPED signal only
            (<code class="font-next-mono">isBudgetError()</code> recognizing the 429's
            <code class="font-next-mono">code: 'ai_budget_exceeded'</code>, or
            <code class="font-next-mono">summary.blocked</code>), never re-derived heuristically; it shows
            when the window resets and a role-appropriate CTA — an owner links straight to the AI-usage page
            to raise the limit, a member is told to contact the workspace owner
            (<code class="font-next-mono">canManage</code>, server-authoritative). The Generate button, the
            composer's send action, and every per-part regenerate/refine affordance DISABLE proactively when
            <code class="font-next-mono">summary.blocked</code> is true — the same 429 the backend would
            return anyway, surfaced before the click rather than after.
          </p>
        </div>
      </div>
    </StorySection>

    <!-- 16. Lifecycle -->
    <StorySection title="Lifecycle — archive, trash, purge">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          A scheduled reaper (<code class="font-next-mono">generator:reap-sessions</code>, every 5 minutes)
          sweeps the shared database and every own-database workspace, applying three windows in order:
        </p>
        <ApiTable title="Lifecycle windows (config-driven, 60s floor)" type-header="Default" :rows="lifecycleRows" />
        <Alert variant="info" size="sm">
          <strong>Archive is a blanket FREEZE</strong>, not merely a trash exemption — an ARCHIVED session
          (<code class="font-next-mono">POST …/archive</code>, creator-only) is exempt from ALL three
          windows, INCLUDING stale-recovery: a session stuck in <code class="font-next-mono">generating</code>
          past the stale window is normally auto-failed, but not if it is archived.
          <code class="font-next-mono">POST …/unarchive</code> re-enrolls it in every window. The chat header
          shows an "Archiwum" badge and an archive/unarchive action in its overflow menu, gated on the
          server-authoritative <code class="font-next-mono">can_archive</code> flag.
        </Alert>
      </div>
    </StorySection>

    <!-- 17. Frontend module -->
    <StorySection title="Frontend module">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          The Generator area is a top-level module (icon <code class="font-next-mono">sparkles</code>) with
          TWO pages, reached from its own module nav: <strong>Templates</strong> ("Szablony") and
          <strong>Sessions</strong> ("Sesje"). Opening a session promotes it to the module aside's SELECTED
          RESOURCE block (icon + name + StatusBadge + back-to-list) above a one-item chat nav, mirroring how
          Forms/Workflows show an open resource — the layout PREFETCHES the open session so the aside
          identity + the chat page render immediately.
        </p>
        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-1 font-next-semibold text-next-fg text-next-sm">Templates page</p>
          <p class="text-next-xs text-next-muted-foreground">
            The list carries the MANDATORY FilterBar + <code class="font-next-mono">#top</code> Saved Views
            tab bar, a single <code class="font-next-mono">search</code> filter, cursor infinite scroll,
            row-shaped skeletons, an EmptyState, and an error state with retry. Create opens with a
            content-type PICKER step (§2); create/edit then open a wide right Drawer laid out in two
            columns: the DATA-DRIVEN authoring form (one section per declared part) on the left, the
            faithful per-part live preview on the right.
          </p>
        </div>
        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-1 font-next-semibold text-next-fg text-next-sm">Sessions page</p>
          <p class="text-next-xs text-next-muted-foreground">
            The list mirrors Templates: FilterBar + Saved Views, a debounced <code class="font-next-mono">search</code>
            + a single <code class="font-next-mono">status</code> Select, cursor infinite scroll, skeletons,
            EmptyState. "Nowa sesja" opens <code class="font-next-mono">TemplatePickerModal.vue</code> (pick
            a template → create → route to the chat). Opening a row routes to
            <code class="font-next-mono">SessionChatView.vue</code> — the full-height chat surface §13-§16
            describe.
          </p>
        </div>
        <div class="grid grid-cols-1 gap-next-3 next-sm:grid-cols-2">
          <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
            <p class="mb-next-1 font-next-semibold text-next-fg">Templates — pages &amp; components</p>
            <ul class="flex flex-col gap-next-1 font-next-mono text-next-xs text-next-muted-foreground">
              <li>pages/generator/GeneratorModuleLayout.vue</li>
              <li>pages/generator/TemplatesView.vue, TemplateRow.vue</li>
              <li>pages/generator/TemplateEditorDrawer.vue, ContentTypePicker.vue</li>
              <li>pages/generator/TemplateSlotsPanel.vue</li>
              <li>pages/generator/TemplateBodyPart.vue</li>
              <li>pages/generator/TemplateImagePlanPart.vue</li>
              <li>pages/generator/TemplateFilterChain.vue (shared pixel/ai_edit chain builder)</li>
              <li>pages/generator/TemplateShotListPart.vue (video_script rework)</li>
              <li>pages/generator/TemplateStoryboardPart.vue (video_script rework)</li>
              <li>pages/generator/TemplateScenePlanPart.vue (legacy)</li>
              <li>pages/generator/TemplatePreview.vue, TemplatePartPreview.vue</li>
              <li>pages/generator/ImagePlanPreviewCard.vue</li>
              <li>pages/generator/types.ts, templateSlots.ts, templateContent.ts</li>
              <li>pages/generator/imagePlan.ts, templateMeta.ts, templateCatalog.ts</li>
              <li>app/stores/templates.ts</li>
              <li>route: next.generator.templates</li>
            </ul>
          </div>
          <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
            <p class="mb-next-1 font-next-semibold text-next-fg">Sessions — pages &amp; components</p>
            <ul class="flex flex-col gap-next-1 font-next-mono text-next-xs text-next-muted-foreground">
              <li>pages/generator/SessionsView.vue, SessionRow.vue</li>
              <li>pages/generator/TemplatePickerModal.vue</li>
              <li>pages/generator/session/SessionChatView.vue</li>
              <li>pages/generator/session/SessionSlotSetupCard.vue, SlotValuesForm.vue</li>
              <li>pages/generator/session/SessionTurn.vue, SessionResultCard.vue</li>
              <li>pages/generator/session/SessionComposer.vue</li>
              <li>pages/generator/session/SessionPartImage.vue, sessionImages.ts</li>
              <li>pages/generator/session/SessionSaveToDiskModal.vue</li>
              <li>pages/generator/session/FinalPostPane.vue, FinalPostBody.vue</li>
              <li>pages/generator/session/sessionStatus.ts, sessionGating.ts</li>
              <li>pages/generator/sessionTypes.ts (the wire contract, mirrored 1:1)</li>
              <li>app/stores/sessions.ts (CRUD + async run)</li>
              <li>pages/generator/session/useSessionSettle.ts (websocket settle wait)</li>
              <li>routes: next.generator.sessions(.detail)</li>
            </ul>
          </div>
        </div>
        <Alert variant="info" size="sm">
          <code class="font-next-mono">templateCatalog.ts</code> REUSES the workflow catalog→editor
          transforms verbatim; the slot type-builder reuses the constants descriptor model. The editor's
          part sections are RENDERED FROM THE DEFINITION (a <code class="font-next-mono">v-for</code> over
          <code class="font-next-mono">definition.parts</code>, switching per <code class="font-next-mono">kind</code>)
          — it never hard-codes a post/image/video layout, so a content type recombining the same kinds
          differently needs no new editor code. <code class="font-next-mono">sessionGating.ts</code> is the
          SINGLE capability switch for the session surface (`SESSION_CAPABILITIES`) — every affordance
          renders from day one, wired on as its endpoint shipped (2b → 2c → 2d); as of 2d, nothing on the
          session surface remains gated.
        </Alert>
      </div>
    </StorySection>

    <!-- 18. Cross-part context (video_script rework Phase A) -->
    <StorySection title="Cross-part context — the parts.<key> root (video_script rework Phase A)">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          A content-type part's authored body — a <code class="font-next-mono">text_body</code>/
          <code class="font-next-mono">script</code> markdown, a scene's narration, an image-plan prompt, a
          <code class="font-next-mono">shot_list</code>'s brief, a <code class="font-next-mono">storyboard</code>'s
          style — may reference an EARLIER part's GENERATED output as
          <code class="font-next-mono">parts.&lt;key&gt;</code>. This is a GENERAL engine primitive added to
          the shared resolver (not specific to <code class="font-next-mono">video_script</code>) — any
          content type/part benefits. It resolves EXACTLY like a
          <code class="font-next-mono">globals.&lt;key&gt;</code> reference: a plain whitelisted dotted
          lookup over a stored, post-render STRING, riding the same NUL-mask injection guard. See
          <code class="font-next-mono">docs/decisions/ADR-0035-video-script-cross-part-storyboard.md</code>
          for the full design record.
        </p>

        <Alert variant="warning" size="sm">
          <strong>Text-only, earlier-only, acyclic by construction.</strong> Only a part whose <code class="font-next-mono">ok</code>
          result is a rendered STRING (<code class="font-next-mono">text_body</code> /
          <code class="font-next-mono">script</code> / <code class="font-next-mono">shot_list</code>)
          contributes — an <code class="font-next-mono">image_plan</code>/<code class="font-next-mono">scene_plan</code>/
          <code class="font-next-mono">storyboard</code> result has no natural flattened text and
          contributes nothing. A part may reference ONLY a part declared BEFORE it — a forward, self, or
          unknown reference is always rejected/empty, never resolved against a later part's output.
        </Alert>

        <ApiTable title="Earlier-only enforced at THREE independent layers (defense in depth)" type-header="Layer" :rows="crossPartLayerRows" />

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-1 font-next-semibold text-next-fg text-next-sm">One shared scanner, so the write gate and the staleness scan can never drift</p>
          <p class="text-next-xs text-next-muted-foreground">
            A flat <code class="font-next-mono">@[variable]</code> regex is BLIND to a reference nested
            inside an <code class="font-next-mono">@[ai-text]</code> prompt or an if-block condition/body,
            and to the transitional flat <code class="font-next-mono">&#123;&#123;…&#125;&#125;</code>
            token form. BOTH the write-time gate AND the runtime staleness scan (below) instead walk every
            string leaf of the authored content through
            <code class="font-next-mono">VariableResolver::collectReferenceIds()</code> — a scanner that
            mirrors the resolver's OWN parsing exactly, so a reference is found WHEREVER the resolver would
            actually resolve one. Hardened across two review rounds specifically because a flat-regex
            approach missed those nested forms.
          </p>
        </div>

        <Alert variant="info" size="sm">
          <strong>Staleness — a PASSIVE FE hint, never an auto-cascaded re-run.</strong> Refining or
          regenerating an UPSTREAM part marks every already-produced DOWNSTREAM dependent
          <code class="font-next-mono">stale: true</code> in its <code class="font-next-mono">results</code>
          entry — the chat renders a "may be out of date" badge. There is NO auto-regenerate (cost control:
          an AI spend is never triggered silently). A full <code class="font-next-mono">generate</code>
          clears every part's results (and so every stale flag); regenerating the stale part itself replaces
          its result with a fresh, non-stale one. A <code class="font-next-mono">storyboard</code> is also
          marked stale when its sibling <code class="font-next-mono">shot_list</code> changes, even though
          that dependency is a direct STRUCTURAL read, not a <code class="font-next-mono">parts.*</code>
          reference (see §19).
        </Alert>

        <Alert variant="warning" size="sm">
          <strong>The TEMPLATE preview does NOT populate <code class="font-next-mono">parts</code>.</strong>
          <code class="font-next-mono">POST /generator/preview</code> (§9) renders each part in isolation
          against ONE shared context built once — it does not accumulate a
          <code class="font-next-mono">parts</code> map across parts the way a real session run does. A
          <code class="font-next-mono">parts.&lt;key&gt;</code> reference in a preview therefore always
          resolves EMPTY, exactly like any other unpopulated whitelisted root — a real session
          <code class="font-next-mono">generate</code> (§11) is the only place cross-part context actually
          resolves. This is a deliberate scope-limit, not a bug.
        </Alert>
      </div>
    </StorySection>

    <!-- 19. Video script rework: shot_list + storyboard -->
    <StorySection title="Video script rework — structured shot_list + storyboard (Phase B)">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          The owner rejected the ORIGINAL <code class="font-next-mono">video_script</code>
          (<code class="font-next-mono">script</code> + <code class="font-next-mono">scene_plan</code>, §8)
          as useless for producing a real short-form TikTok/Reels video: no coherent hook/shots/timing
          structure, and no way for one part to build on another's real output. It now composes
          <code class="font-next-mono">[shot_list, storyboard]</code>. See
          <code class="font-next-mono">docs/decisions/ADR-0035-video-script-cross-part-storyboard.md</code>
          for the full design record.
        </p>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-1 font-next-semibold text-next-fg text-next-sm">shot_list — <code class="font-next-mono">&#123; "brief": &#123; "markdown": "…" &#125; &#125;</code></p>
          <p class="text-next-xs text-next-muted-foreground">
            Authored as a creative BRIEF — NOT the finished script — exactly like a
            <code class="font-next-mono">text_body</code> (static text + slot values +
            <code class="font-next-mono">parts.&lt;earlierKey&gt;</code> references + pipelines + if-blocks
            + <code class="font-next-mono">@[ai-text]</code>). At a real session run it feeds ONE metered
            structured AI call (<code class="font-next-mono">ShotListRenderer</code> →
            <code class="font-next-mono">ShotListAgent</code>) that returns a coherent hook / ordered timed
            shots / cta. <strong>Deliberately PROMPT-AND-PARSE, not native structured output</strong> —
            <code class="font-next-mono">laravel/ai</code> v0.4.3's <code class="font-next-mono">HasStructuredOutput</code>
            is available but not used, so the call rides the EXISTING metered/budgeted ai-text seam with
            zero new metering wiring (ADR-0035, D6).
          </p>
        </div>

        <ApiTable title="shot_list's defensive parse (ShotListRenderer)" type-header="Model reply" :rows="shotListParseRows" />

        <Alert variant="info" size="sm">
          A malformed shot entry (missing string <code class="font-next-mono">visual</code>/<code class="font-next-mono">voiceover</code>)
          is dropped; <code class="font-next-mono">seconds</code> coerces to a non-negative int. The shots
          list is CLAMPED to the run's EFFECTIVE shot cap — <code class="font-next-mono">min(authored
          content.storyboard.max_shots, generator.storyboard_max_shots)</code>, platform ceiling default 8
          (see §21) — so a runaway model reply can never inflate the storyboard's later image cost, and the
          SAME cap bounds the agent's instructed count so a recipe asking for fewer beats gets a shot list
          WRITTEN for that count, not a longer one truncated after the fact.
          <code class="font-next-mono">shot_list</code> is REFINABLE: a free-text instruction feeds the
          CURRENT list (as data) + the instruction to the SAME renderer's revise mode → a NEW structured
          list; a blank/unparseable revision is a failed no-op (the current good list is preserved).
        </Alert>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-1 font-next-semibold text-next-fg text-next-sm">storyboard — <code class="font-next-mono">&#123; "style"?: &#123;markdown&#125;, "filters"?: [...], "max_shots"?: int &#125;</code></p>
          <p class="text-next-xs text-next-muted-foreground">
            OPTIONAL authoring, NO <code class="font-next-mono">base</code> field at all — a session ALWAYS
            auto-generates each shot's base image from the shot's own on-screen <code class="font-next-mono">visual</code>.
            <code class="font-next-mono">style</code> is an optional resolvable body prepended to that
            auto-generated prompt; <code class="font-next-mono">filters</code> is the SAME ordered
            pixel/<code class="font-next-mono">ai_edit</code> chain §7 describes, reused verbatim (shared
            <code class="font-next-mono">TemplateFilterChain.vue</code> builder);
            <code class="font-next-mono">max_shots</code> (added with §21's creative-direction layer) is an
            OPTIONAL whole number 1..8 that TIGHTENS the platform ceiling for THIS recipe — an author may
            only ever tighten it, never raise it (422 otherwise); clearing the field in the editor sends the
            key ABSENT, never <code class="font-next-mono">0</code>. At run time the executor reads the
            sibling <code class="font-next-mono">shot_list</code>'s STRUCTURED shots by DIRECT
            intra-composition (not via <code class="font-next-mono">parts.*</code>, which is text-only) and
            generates ONE image per shot, bounded by the run's EFFECTIVE shot cap.
            The shot's <code class="font-next-mono">visual</code> is resolved with an IDENTITY function — it
            is the shot-list AI's OWN output and must never be re-interpreted as a directive. Per-shot
            FAIL-SOFT — a broken shot never sinks the others; the part itself is never
            <code class="font-next-mono">failed</code>.
          </p>
        </div>

        <ApiTable title="Per-shot addressing (storyboard.<i>) — reuses the EXISTING per-part op contract, no new endpoints" type-header="Body" :rows="storyboardAddressingRows" />

        <Alert variant="warning" size="sm">
          The BARE <code class="font-next-mono">storyboard</code> part (no index) is NOT free-text refinable
          — a multi-shot composite has no single current output to revise (422, the same rule
          <code class="font-next-mono">scene_plan</code> already had); regenerate/undo on the bare key still
          work (they re-render/restore ALL shots). Refining or regenerating
          <code class="font-next-mono">shot_list</code> marks the sibling <code class="font-next-mono">storyboard</code>
          <code class="font-next-mono">stale</code> (§18) — a structural dependency, not a
          <code class="font-next-mono">parts.*</code> reference.
        </Alert>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-1 font-next-semibold text-next-fg text-next-sm">Legacy back-compat — snapshot-authoritative (unchanged in kind from D1)</p>
          <p class="text-next-xs text-next-muted-foreground">
            <code class="font-next-mono">script</code>/<code class="font-next-mono">scene_plan</code> stay in
            the closed <code class="font-next-mono">PartKind</code> vocabulary and are still fully render-/
            refine-/undo-capable, but <code class="font-next-mono">ContentTypeRegistry::all()</code> no
            longer lists them under <code class="font-next-mono">video_script</code> — a NEW template can
            never author them again (§8 is marked legacy). An EXISTING session's stored
            <code class="font-next-mono">recipe_snapshot</code> still renders exactly as it always did
            (<code class="font-next-mono">ContentTypeRegistry::partsForSnapshot()</code>) — a registry
            recomposition can never corrupt an existing session, mirroring the "never re-read the live
            template" invariant (§11) one level down.
          </p>
        </div>
      </div>
    </StorySection>

    <!-- 20. Bots in the generator — session delegation (R2 sub-stage 3) -->
    <StorySection title="Bots in the generator — session delegation (R2 sub-stage 3)">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          A human may DELEGATE an editable session (draft/ready) to a workspace bot: the bot
          <strong>autonomously fills</strong> its in-scope slots and becomes the session's content
          <strong>AUTHOR</strong> — every subsequently rendered text part (and the
          <code class="font-next-mono">shot_list</code> voiceover/hook/cta, §19) reads in the bot's voice.
          The human <code class="font-next-mono">creator</code> is UNCHANGED — full ownership
          (edit/refine/undo/archive/delete) stays with the human; this is a REVERSIBLE, SNAPSHOTTED overlay,
          not an ownership transfer. See
          <code class="font-next-mono">docs/decisions/ADR-0036-bot-delegation-generation-sessions.md</code>
          for the full design record.
        </p>

        <ApiTable title="Delegate / undo endpoints (Bot module — the ONE new Bot → Generator edge)" type-header="Body" :rows="delegationEndpointRows" />

        <Alert variant="warning" size="sm">
          <strong>Bot → Generator is strictly ONE-WAY.</strong> The Bot module composes the bot's voice +
          calls the Generator's delegation seams (<code class="font-next-mono">SessionDelegationService</code>)
          and the Variables ai-text seam, passing only primitives/opaque strings; the Generator and Variables
          modules import NOTHING from Bot. Pinned by <code class="font-next-mono">GeneratorModuleBoundaryTest</code>
          and <code class="font-next-mono">BotModuleBoundaryTest</code> (both directions).
        </Alert>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-1 font-next-semibold text-next-fg text-next-sm">The opaque voice — one ambient seam, no fork of AiPersona</p>
          <p class="text-next-xs text-next-muted-foreground">
            <code class="font-next-mono">Bot\Services\BotVoiceComposer</code> folds the bot's persona / style /
            dictionary / phrases / prohibitions into ONE opaque string, snapshotted into the overlay at
            delegation time. <code class="font-next-mono">Variables\Support\AiVoiceContext</code> — the exact
            twin of <code class="font-next-mono">MeterContext</code> — carries it around every render scope
            (whole-run, per-part regenerate, per-part refine), cleared in the SAME
            <code class="font-next-mono">finally</code> as the meter's session tag. When present, it
            <strong>REPLACES</strong> the resolved <code class="font-next-mono">AiPersona</code> tone line in
            <code class="font-next-mono">AiTextAgent</code>'s system instruction (never both at once); a
            non-delegated run never sets it, so behavior is byte-identical to before this feature. The SAME
            directive reaches <code class="font-next-mono">ShotListAgent</code> (§19) as an ADDITIVE tone
            clause — the strict <code class="font-next-mono">&#123;hook, shots, cta&#125;</code> JSON contract
            is unchanged. The <code class="font-next-mono">storyboard</code> IMAGE prompt is untouched — it
            stays the authored <code class="font-next-mono">style</code> only; the voice colors TEXT output,
            not image-generation prompts.
          </p>
        </div>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-1 font-next-semibold text-next-fg text-next-sm">Autonomous slot-fill — scoped to plain typed inputs</p>
          <p class="text-next-xs text-next-muted-foreground">
            <code class="font-next-mono">BotSlotFillService</code> makes ONE metered
            <code class="font-next-mono">ai_text</code> call (gate-before-spend on the SAME monthly cap,
            session-tagged — §15) through a prompt-and-parse agent mirroring
            <code class="font-next-mono">ShotListAgent</code>'s defensive-parse posture. A <strong>FILE</strong>
            slot or a <strong>deep composite</strong> (an <code class="font-next-mono">array&lt;object&gt;</code>,
            or an object nesting another object/file beyond one level) is NEVER offered to the bot — a bot has
            no Disk access and must never be able to forge a file reference. Every proposed value is
            RE-VALIDATED against the SAME slot-descriptor authority a human write uses before persisting; an
            invalid/unknown/out-of-scope value is dropped, never stored. Accepted values MERGE into the
            existing <code class="font-next-mono">slot_values</code> — but whether a human's OWN prior fill
            survives that merge is a <code class="font-next-mono">fill_mode</code> choice, not a blanket
            guarantee (see below).
          </p>
        </div>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-1 font-next-semibold text-next-fg text-next-sm">fill_mode — a click-time choice, not an engine guess</p>
          <p class="text-next-xs text-next-muted-foreground">
            Two owner-reported defects drove this: delegating a session with fields already filled still made
            (and billed) an AI call that just echoed the same values back, and repeated delegations of the
            same session kept returning identical values (a byte-identical prompt every time). The fix is an
            explicit request field, <code class="font-next-mono">fill_mode: 'gaps' | 'fresh'</code>
            (<code class="font-next-mono">App\Modules\Bot\Enums\SlotFillMode</code>) — OPTIONAL, DEFAULTS to
            <code class="font-next-mono">gaps</code>, an unknown value is a <code class="font-next-mono">422</code>
            at <code class="font-next-mono">errors.fill_mode</code>.
          </p>
          <ul class="mt-next-2 flex flex-col gap-next-1 text-next-xs text-next-muted-foreground">
            <li>
              <strong class="text-next-fg">gaps</strong> (default, non-destructive) — fills ONLY the slots
              that are currently empty; a value the human typed is never offered to the model and, belt AND
              braces, a proposal for it coming back anyway is REFUSED server-side
              (<code class="font-next-mono">already_filled</code>). The already-filled slots still travel as
              read-only AUTHOR CONTEXT so the proposal stays coherent with what the human wrote. With no gap
              at all there is NO provider call and nothing is billed
              (<code class="font-next-mono">nothing_to_fill: true</code>) — the overlay is still stamped, the
              bot still becomes the author.
            </li>
            <li>
              <strong class="text-next-fg">fresh</strong> — "take it over and do it your way": every in-scope
              slot is offered and the model is told to propose a DIFFERENT take than what is there now.
              Destructive by design, and safe only because <code class="font-next-mono">DELETE …/delegate</code>
              restores <code class="font-next-mono">slot_values_before</code> in full.
            </li>
          </ul>
          <p class="mt-next-2 text-next-xs text-next-muted-foreground">
            Every fill request also carries a per-call VARIATION TOKEN line in the prompt (the fix for the
            second defect): the shared, budgeted <code class="font-next-mono">AiTextGenerationService::
            generateWith()</code> seam reaches the provider through laravel/ai's <code class="font-next-mono">
            Agent::prompt()</code>, whose signature carries no sampling knob — temperature is a compile-time
            <code class="font-next-mono">#[Temperature]</code> CLASS attribute read by reflection in
            <code class="font-next-mono">TextGenerationOptions::forAgent()</code>, not something a caller can
            set per call. The message itself is therefore the only per-call knob available, so two consecutive
            <code class="font-next-mono">fresh</code> prompts differ only by that meaningless, never-logged
            token.
          </p>
        </div>

        <ApiTable title="The fill_report (delegate response, additional keys)" type-header="Field" :rows="fillReportReasonRows" />

        <Alert variant="info" size="sm">
          A required FILE slot the bot could not fill surfaces in
          <code class="font-next-mono">unfilled_required</code> with a distinct "needs your file" affordance
          in the chat (<code class="font-next-mono">SessionFillReportPanel.vue</code>) — the session stays a
          draft until the human completes it. No AI call runs at all when the session has no bot-fillable
          slots.
        </Alert>

        <ApiTable title="GenerationSessionResource — the 5 new delegation fields" type-header="Field" :rows="delegationOverlayRows" />

        <Alert variant="warning" size="sm">
          <strong>Undo is a FULL, reversible restore, not merely an overlay-clear.</strong> Delegation
          snapshots the session's <code class="font-next-mono">slot_values</code> AS THEY ARE at delegation
          time (before the autonomous fill runs) into <code class="font-next-mono">bot_delegation.slot_values_before</code>;
          <code class="font-next-mono">DELETE …/delegate</code> restores exactly that snapshot before nulling
          the overlay. A <em>delegate → undo</em> round trip fully reverts the bot's fill (and anything it
          overwrote) with ZERO data loss. Undo works on <code class="font-next-mono">draft</code>/
          <code class="font-next-mono">ready</code>/<code class="font-next-mono">failed</code> — a delegated
          FAILED session stays revertible — but 409s on <code class="font-next-mono">generating</code>.
          Re-delegating an already-delegated session OVERWRITES the whole overlay (no stale bleed from a prior
          bot).
        </Alert>

        <Alert variant="info" size="sm">
          <strong>Gate-przed-wydatkiem (gate-before-spend) applies here too.</strong>
          <code class="font-next-mono">auto_generate</code> DEFAULTS to <code class="font-next-mono">false</code>
          — delegating fills the inputs and STOPS at <code class="font-next-mono">ready</code> for the human
          to review the fill report before any generation run spends AI budget. Ticking "Auto-generate after
          filling" in <code class="font-next-mono">DelegateBotDialog.vue</code> is a SEPARATE, explicit opt-in
          that claims + dispatches through the SAME <code class="font-next-mono">GenerationSessionRunManager</code>
          a manual "Generuj" click uses.
        </Alert>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-1 font-next-semibold text-next-fg text-next-sm">Chat surface — pages/generator/session/</p>
          <p class="text-next-xs text-next-muted-foreground">
            <code class="font-next-mono">DelegateBotDialog.vue</code> — bot picker (single-select) + a
            FILL MODE chooser (radio, default <code class="font-next-mono">gaps</code>, re-asserted on every
            open exactly like the toggle below) + auto-generate toggle (default OFF, re-asserted on every
            open). <code class="font-next-mono">BotAuthorChip.vue</code>
            — the "Authored by {name}" chip, ADDITIVE to the human's own CreatorBadge (a delegated session
            still shows the human as owner). <code class="font-next-mono">SessionFillReportPanel.vue</code> —
            renders the fill report inline in the chat: a MODE chip (which choice actually ran), then either
            filled / skipped-with-reason / unfilled-required (with a distinct "needs your file" line and a
            "Complete inputs" action), or — when <code class="font-next-mono">nothing_to_fill</code> is true —
            a distinct "had nothing to fill, no AI call, nothing spent" state instead of a misleading "filled
            0 inputs" summary. The header's overflow menu
            exposes "Delegate to bot" (gated on <code class="font-next-mono">can_delegate</code>, disabled with
            a reason when mid-run vs. non-editable) and "Undo delegation" (gated on
            <code class="font-next-mono">can_undo_delegation</code>, confirmed).
          </p>
        </div>
      </div>
    </StorySection>

    <!-- 21. Content quality — narrative contract + creative direction layer -->
    <StorySection title="Content quality — narrative contract + the creative direction layer (ADR-0038)">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          A QUALITY rework on top of the whole session engine above, not a new sub-stage of its own: it fixes
          two related problems diagnosed empirically, not theorized. First, <code class="font-next-mono">ShotListAgent</code>'s
          system contract used to say "3 to 5 SHOTS … Keep it tight" — a rule that OVERRODE whatever duration
          the brief actually asked for (a "1–2 minute video" brief reliably produced a ~15-second script).
          Second, every generation inside a run was an INDEPENDENT AI call that had never seen any other's
          output — a <code class="font-next-mono">post_with_image</code>'s body and image could describe
          different things; five storyboard frames could read as five different productions. See
          <code class="font-next-mono">docs/decisions/ADR-0038-creative-direction-layer.md</code> for the
          full design record (the problem, every decision, the alternatives considered, and the honest
          limitation below).
        </p>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-1 font-next-semibold text-next-fg text-next-sm">B1 — narrative contract upgrades (zero AI cost)</p>
          <p class="text-next-xs text-next-muted-foreground">
            Two prompt-only fixes to <code class="font-next-mono">ShotListAgent</code> (§19), which apply
            even with the direction layer's kill switch off — they cost nothing extra and are a PREREQUISITE
            for B2 below (a shared creative frame is only useful if the agent receiving it can actually honor
            a stated duration/beat count instead of silently overriding it).
          </p>
        </div>
        <ApiTable title="ShotListAgent narrative contract (ShotListAgent::instructions() / storyClause())" type-header="Mechanism" :rows="narrativeContractRows" />

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-1 font-next-semibold text-next-fg text-next-sm">B2 — the creative direction layer</p>
          <p class="text-next-xs text-next-muted-foreground">
            A FULL run derives ONE small creative frame up front (<code class="font-next-mono">Services\CreativeDirectionService::derive()</code>
            → <code class="font-next-mono">Agents\CreativeDirectionAgent</code>, ONE extra metered
            <code class="font-next-mono">ai_text</code> call) and persists it to a new nullable
            <code class="font-next-mono">creative_direction</code> json column on <code class="font-next-mono">generation_sessions</code>
            (additive migrations, central + tenant). Every later generation in the SAME run — and every
            later ISOLATED per-part regenerate/refine — is made to it, so the finished piece reads as ONE
            work rather than N mutually-blind AI calls.
            <strong>Wire note:</strong> <code class="font-next-mono">creative_direction</code> is
            DETAIL-ONLY — the sessions LIST projection
            (<code class="font-next-mono">GenerationSessionResource::lean()</code>) omits the key entirely
            (re-normalizing a stored direction costs a dozen regex passes per row and no list screen reads
            it); read it from a single-session GET.
          </p>
        </div>
        <ApiTable title="CreativeDirection — the normalized shape (all fields nullable; all-empty ⇒ the whole column is null, never an empty object)" type-header="Type" :rows="directionFieldRows" />

        <Alert variant="warning" size="sm">
          <strong>Derived from the AUTHORED recipe, never the resolved brief.</strong> The derivation input is
          the SAME no-op-previewed rendering the template editor's live preview (§9) already produces
          (<code class="font-next-mono">@[ai-text]</code> shows as <code class="font-next-mono">[AI: &lt;resolved prompt&gt;]</code>,
          no model call) plus a size-capped digest of the run's SCALAR slot values (file/composite slots
          excluded) — driven by the SAME snapshot-authoritative part list the executor itself renders, so a
          legacy <code class="font-next-mono">[script, scene_plan]</code> snapshot is directed by the body it
          will actually render. Two reasons: deriving from the RESOLVED brief would re-run (and re-bill) every
          nested <code class="font-next-mono">@[ai-text]</code> block the direction needs as input; and a
          resolved brief is already one model's paraphrase, so a direction derived from it would extract that
          paraphrase, not the author's own stated constraint. <strong>No recipe → no call at all</strong> — a
          slot digest alone (e.g. just <code class="font-next-mono">&#123;topic: "Espresso"&#125;</code>)
          never triggers a derivation, so nothing is ever invented — or billed — from slot values with no
          recipe.
        </Alert>

        <ApiTable title="Injection — fenced USER-message DATA, per consumer (never a system instruction)" type-header="Projection" :rows="directionInjectionRows" />

        <Alert variant="info" size="sm">
          <strong>Trusted framing vs. untrusted content, kept strictly apart.</strong> The direction is
          MODEL-DERIVED content laundered from user-supplied slot values — the SAME trust level as any other
          resolved prompt value, never the elevated trust of an agent's own system instruction. Every
          injection point above rides the USER message as DATA; the one thing that ever reaches a SYSTEM
          instruction is a trusted, content-free FLAG ("a direction block is present"), never the block's own
          text. <strong>Voice wins on tone</strong> (§20): a delegated session already carries the bot's voice
          in the system instruction, so the direction's <code class="font-next-mono">tone</code> field is
          dropped from the text/shot-list projections rather than competing with it.
        </Alert>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-1 font-next-semibold text-next-fg text-next-sm">Lifecycle — derive once, null on a full claim, preserve on a part-op claim</p>
          <p class="text-next-xs text-next-muted-foreground">
            <code class="font-next-mono">GenerationSessionRunManager::claimAndDispatch()</code> NULLS
            <code class="font-next-mono">creative_direction</code> on a FULL-mode claim (each full run
            derives fresh) and PRESERVES it on a part-op claim (a regenerate/refine stays inside the SAME
            frame the full run established, at zero extra cost). Published for the whole render scope via the
            ambient <code class="font-next-mono">Support\CreativeDirectionContext</code> — the
            Generator-owned twin of <code class="font-next-mono">AiVoiceContext</code> (§20) — set and cleared
            in the SAME <code class="font-next-mono">finally</code> as the meter/actor/voice tags at all three
            executor entry points. It exists for exactly ONE consumer with no parameter-passing seam
            (<code class="font-next-mono">GeneratorAiTextService</code>, reached deep inside the shared
            resolver); every other consumer (the shot-list renderer, the image composers) receives the
            direction as an EXPLICIT parameter instead. <strong>Fail-soft by construction</strong> — a blank/
            unparseable/over-budget/erroring derivation returns <code class="font-next-mono">null</code>, and
            every injection point above becomes a no-op: the run renders EXACTLY as it did before this layer
            existed.
          </p>
        </div>

        <ApiTable title="Config" type-header="Type" :rows="directionConfigRows" />

        <Alert variant="warning" size="sm">
          <strong>Budget: metered like any other spend, but OUTSIDE the per-run ai-text call ceiling.</strong>
          The one derivation call is gated before spend by the SAME workspace $ cap (§15), session-tagged, and
          actor-attributed — but deliberately NOT counted against
          <code class="font-next-mono">generator.ai_text_max_calls_per_session</code>: charging it to that
          fixed budget would let one guaranteed call starve a real authored part (a 4-block recipe would then
          only resolve 3). Its own tighter timeout (<code class="font-next-mono">ai.direction_timeout</code>)
          is what keeps it from eating into the run job's fixed 300s SIGALRM window.
        </Alert>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-1 font-next-semibold text-next-fg text-next-sm">Adaptive shot cap, in lock-step with the image budget</p>
          <p class="text-next-xs text-next-muted-foreground">
            The platform ceiling (<code class="font-next-mono">generator.storyboard_max_shots</code>) was
            raised from 5 to 8, and an author may now TIGHTEN it per recipe
            (<code class="font-next-mono">content.storyboard.max_shots</code>, §19 — a whole number 1..8,
            422 otherwise). <code class="font-next-mono">GenerationSessionExecutor::effectiveShotCap()</code>
            resolves ONE value — <code class="font-next-mono">min(authored, ceiling)</code> — threaded into
            the shot-list agent's instructed bound, the parse clamp, AND the storyboard's per-shot image
            iteration, so the three can never disagree.
            <code class="font-next-mono">generator.image_generate_max_calls_per_session</code> was raised
            from 5 to 8 IN LOCK-STEP: every listed shot must be RENDERABLE, or the last frames of a long
            storyboard silently come back frameless — which reads as a bug, not a budget, in the chat.
            <code class="font-next-mono">generator.image_edit_max_calls_per_session</code> was raised 3 → 8
            for the SIBLING reason: an authored storyboard filter chain runs on EVERY shot out of the run's
            ONE cumulative counter, so a single <code class="font-next-mono">ai_edit</code> filter across a
            full 8-shot storyboard costs 8 edits. Over the budget, a chain step is now SKIPPED rather than
            failing the frame (§15) — the shot still comes back with a picture, just unfiltered.
          </p>
        </div>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-1 font-next-semibold text-next-fg text-next-sm">The "Kierunek kreatywny" chat card + the max_shots field</p>
          <p class="text-next-xs text-next-muted-foreground">
            <code class="font-next-mono">SessionDirectionCard.vue</code> — a READ-ONLY, collapsed-by-default
            card rendered as its own assistant turn in the session chat
            (<code class="font-next-mono">SessionChatView.vue</code>), hidden entirely when the direction is
            null or all-empty (the SAME visibility predicate,
            <code class="font-next-mono">hasCreativeDirection()</code> in
            <code class="font-next-mono">sessionDirection.ts</code>, gates both the card and its host turn, so
            an empty direction never leaves a dangling empty turn). It legitimately DISAPPEARS while a run is
            <code class="font-next-mono">generating</code> (a full claim nulls the column) and reappears with
            the settled session. Every value is MODEL-DERIVED content rendered as PLAIN TEXT interpolation
            ONLY — never <code class="font-next-mono">v-html</code>, never the markdown viewer.
            <code class="font-next-mono">TemplateStoryboardPart.vue</code>'s authoring form gets a
            <code class="font-next-mono">max_shots</code> <code class="font-next-mono">NumberInput</code>
            (1..8) alongside the style/filter chain; clearing it sends the key ABSENT, never
            <code class="font-next-mono">0</code>, and the live preview (§9) shows the resolved cap in the
            storyboard plan summary.
          </p>
        </div>

        <Alert variant="danger" size="sm">
          <strong>Honest limitation.</strong> Prompt anchoring (the <code class="font-next-mono">forImage()</code>
          projection above) gives consistent world/style/palette/camera across independently generated
          frames, but it does <strong>NOT</strong> give the same character FACE (or exact garment/object
          identity) across frames — each <code class="font-next-mono">ai_generate</code> call is still an
          independent text→image generation that merely STARTS from a shared written description, not from
          shared pixels. The named v2 path for closing that gap is IMAGE-TO-IMAGE CHAINING (feed frame N's
          produced bytes as frame N+1's edit base) — cheaper per call, but fundamentally SERIAL (no per-shot
          fail-soft parallelism), tends to over-preserve composition, and would need
          <code class="font-next-mono">image_edit_max_calls_per_session</code> raised FURTHER (its 8 is
          sized for ONE authored filter per shot, not for a per-frame chain on top of it). Not built now — see
          <code class="font-next-mono">docs/decisions/ADR-0038-creative-direction-layer.md</code>
          ("Alternatives considered" / "Consequences") for the full trade-off.
        </Alert>
      </div>
    </StorySection>

    <!-- 22. Planned / deferred -->
    <StorySection title="Planned / deferred (not implemented)">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <ul class="flex list-disc flex-col gap-next-2 pl-next-5 text-next-xs text-next-muted-foreground">
          <li><strong>Image-to-image chaining for exact cross-frame character consistency</strong> (§21) — prompt anchoring keeps a storyboard's world/style/palette consistent but not the SAME character face across frames; the named v2 path (feed frame N's bytes as frame N+1's edit base) is cheaper per call but serial and composition-preserving, and needs image_edit_max_calls_per_session raised. See ADR-0038 "Alternatives considered".</li>
          <li><strong>Bot autonomy beyond slot-fill</strong> (R2 sub-stage 3 follow-up, §20) — a delegated bot fills in-scope slots ONCE, at delegation time; it does not initiate its own regenerate/refine or otherwise act on the session afterward.</li>
          <li><strong>File / deep-composite slot bot-fill</strong> (§20) — a FILE-based slot or a deep composite is deliberately NEVER offered to the autonomous slot-fill (a bot must never forge a Disk reference); a required one always surfaces in <code class="font-next-mono">unfilled_required_slots</code> for the human to complete.</li>
          <li><strong>Delegation feeding Approvals or Publishing</strong> — a delegated session's content is not yet wired into the approvals pipeline or a future publish step.</li>
          <li><strong>~~The <code class="font-next-mono">generate_content</code> workflow step~~ — consuming a template/session from a workflow is not modeled.</strong> DONE (R2 sub-stage 5), no longer deferred — see the automation note in §11 above, <code class="font-next-mono">docs/backend/generator-sessions-api.md</code> → "Automation seam (R2 sub-stage 5)", and ADR-0039. Kept struck through so a reader of an older snapshot understands the change.</li>
          <li><strong>A bot delegating a workflow-driven generation</strong> (§20 follow-up) — the bot-delegation overlay (<code class="font-next-mono">SlotScopePolicy::Bot</code>) and the workflow automation seam (<code class="font-next-mono">SlotScopePolicy::Automation</code>) are sibling trust boundaries today, not composed — a <code class="font-next-mono">generate_content</code> step's session cannot be handed to a bot mid-run.</li>
          <li><strong>Redo</strong> — undo (§14) is one-directional; the just-undone version's blob is deleted, not merely hidden. No "redo the undo" in v1.</li>
          <li><strong>A per-session spend cap / kill-switch</strong> distinct from the per-run call-count ceilings (§15) — only the workspace-wide monthly $ cap is a real budget today (R2 sub-stage 4); the per-run ceilings bound fan-out, not cost. A per-session spend READOUT (the ledger is already session-tagged) is likewise not surfaced anywhere yet.</li>
          <li><strong>Usage history / trend, and a pre-run cost estimate</strong> (R2 sub-stage 4 follow-ups, §15) — the AI-usage page is CURRENT-MONTH only, and the pre-run 429 gate only refuses when a workspace is ALREADY over cap — it never forecasts what a specific about-to-run recipe would cost before running it.</li>
          <li><strong>Extending the pre-run 429 gate to Workflows/Disk</strong> (§15) — only Generator session runs get the pre-run 429 today; Workflows <code class="font-next-mono">@[ai-text]</code> and Disk AI edits keep only the pre-existing mid-run fail-soft.</li>
          <li><strong>A user-created content type</strong> — the registry is code-defined-only; a future table + type-authoring UI would union in with zero rework to the validators/renderer/editor, because everything switches on a part's KIND, never the type id.</li>
          <li><strong>A genuinely new part KIND</strong> (e.g. an audio plan) — the kind vocabulary is closed for v1; adding one is a deliberate future change to the validator/renderer/editor dispatch.</li>
          <li><strong>Repeater / multi-file per-element LOOP</strong> — a repeater (array&lt;object&gt;) slot and a multi-file answer have no per-element path or loop binding yet; per-element access is the R2 loop, needing its own element-cardinality / output-binding design rather than an incremental extension.</li>
          <li><strong>Scene-plan authoring polish</strong> — SUPERSEDED for <code class="font-next-mono">video_script</code>: <code class="font-next-mono">scene_plan</code> is no longer authorable for a new/edited video_script template (dropped by the video_script rework Phase B, §19 — replaced by <code class="font-next-mono">shot_list</code>/<code class="font-next-mono">storyboard</code>), so its drag-to-reorder / richer-metadata polish is moot for new authoring. The shape itself is unchanged and stays editable for a pre-rework template that still carries it.</li>
          <li><strong>Native structured output for <code class="font-next-mono">shot_list</code></strong> (video_script rework Phase B, §19) — <code class="font-next-mono">laravel/ai</code> v0.4.3's native JSON-schema structured output is available but deliberately not used; the shot list uses prompt-and-parse instead (see <code class="font-next-mono">docs/decisions/ADR-0035-video-script-cross-part-storyboard.md</code>, D6).</li>
          <li><strong>A structured (non-text-only) <code class="font-next-mono">parts.*</code> cross-part root</strong> (§18) — only a rendered STRING contributes today; a later part reading an earlier part's structured data (not its rendered text) would need a separate reference/type-flow design.</li>
        </ul>
      </div>
    </StorySection>

  </StoryPage>
</template>
