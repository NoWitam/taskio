# ADR-0032 — R2 sub-stage 1 rework: the Generator content-recipe model

**Date:** 2026-07-27 (created)
**Status:** Accepted
**Module:** `App\Modules\Generator` (reshaped in place, unmerged/uncommitted branch
`feat/r2-generator-templatki`)
**Relates to:** ADR-0031 (the "prompt + parameters" model this ADR SUPERSEDES — read it first for what
changed), ADR-0030 (the shared Variables engine — `VariableResolver`/`VariableCatalog`/`AiTextGenerator` —
this rework keeps consuming unchanged), ADR-0027 (the one-way-module-boundary precedent this module's own
boundary test mirrors), ADR-0028 / ADR-0029 (Consts / Functions — the CRUD/authoring/dual-schema pattern
`Template` still mirrors)

---

## Context

R2 sub-stage 1 ("Templatki") originally shipped the model ADR-0031 recorded: a `Template` was an
identity + a `TemplateType` enum + a single markdown `prompt_body` + free-form `parameters`
(`length`/`format`/`channel`, unconsumed). Before that sub-stage was accepted as done, the owner rejected
the model itself, not its implementation quality: a "prompt with parameters" does not match the product's
actual mental model — **a Template is a reusable RECIPE for a finished post** (a content factory): define
the shape once, mass-produce concrete posts by filling a few typed slots, with AI doing the delegated
parts. Three concrete gaps drove the rejection:

1. **No place for a media plan.** `post_with_image` only reserved a type id — there was no `image` field
   at all, no way to declare where an image comes from or how it is transformed, so "post with image" was
   a name with nothing behind it.
2. **"Whole post by AI" was not a natural case.** A single `prompt_body` treats delegating the entire post
   to AI as a special, unrepresented case, when it should just be one big `@[ai-text]` block occupying the
   whole body — the SAME primitive as a partial AI fragment, not a different concept.
3. **A flat `prompt_body` cannot express `video_script`'s real shape.** A script is not one blob of text —
   it is (eventually) an ordered list of scenes, each with its own narration and its own optional visual.

This ADR records the REPLACEMENT model, reshaped in place (no data — the branch was unmerged) before the
sub-stage's first commit. It is still sub-stage 1: **no generation Session runs, no real AI call executes,
no Disk integration exists.** A Template remains a DEFINITION only, authored and faithfully previewed —
only the SHAPE of that definition changed. Every architectural boundary ADR-0030/ADR-0031 established
(Generator → Variables only, never Workflows; the shared resolver/catalog/AI-text contract) is UNCHANGED
and still authoritative.

## The content-recipe concept

A **Template** is now a recipe made of three ingredients:

1. **Slots** — declared typed inputs. UNCHANGED from ADR-0031 (Decision 4): `{name, description?,
   descriptor}`, the same descriptor shape a `Constant`/`CustomFunction` arg uses, validated by the same
   `ConstantTypeValidator::validateSlotDescriptorShape()`, catalogued by the same `TemplateVariableCatalog`.
   Nothing about slots — their identifier rules, their object/file subfield descent, the catalog/reference
   index/type-map trio — changed in this rework.
2. **Content authored AS THE POST**, not as a prompt: a per-part `content` map. A text part is static text
   + slot values + first-class inline `@[ai-text]` AI blocks — "the whole post by AI" is simply one big
   `@[ai-text]` block, not a separate concept.
3. **A media plan per part that needs one** — for an image: a `base` (where the image starts) + an ordered
   **filter chain** (how it is transformed), mirroring the shape of a Variables pipeline.

## Decisions

**D1 — ContentType storage is a CODE-DEFINED REGISTRY, not a table.** A content type (`post` /
`post_with_image` / `video_script`) is a `Support\ContentTypeDefinition` VO — `{id, label, parts[]}` — held
as plain data in `Services\ContentTypeRegistry::all()`. There is no `content_types` table or seeder in this
rework, exactly as there is none for consts or custom functions (both code-defined vocabularies elsewhere
in Variables). `GET /api/generator/content-types` (`ContentTypeController` → `ContentTypeResource`) is the
one read surface, gated on `TemplatePolicy::viewAny` (the same read gate the template list uses). A future
user-created content type is just another `ContentTypeDefinition` instance whose parts recombine the SAME
closed kind set (D8) — it would UNION into `all()` (e.g. from a future table) with **zero** rework to the
editor, the validators, or the renderer, because every one of them switches on a part's `kind`, never on
the type's `id`. Deferred deliberately: no type-authoring UI, no persistence for a custom type, in this
rework.

**D2 — the `templates` migrations were reshaped IN PLACE, not additively.** Both the central
(`database/migrations/2026_07_28_000000_create_templates_table.php`) and tenant
(`database/migrations/tenant/0001_01_01_000049_create_templates_table.php`) migrations were edited to drop
`type` / `prompt_body` / `parameters` and add `content_type` (string) + `content` (json), rather than
shipping a second migration on top. Safe because the branch was unmerged with zero rows in either schema —
there is no data-migration concern. Dev workflow: `php artisan migrate:fresh`; tests use `RefreshDatabase`
(schema is read fresh from the edited migration every run).

**D3 — a Template's authored content is ONE `content` JSON map, keyed by part key.** `Models\Template`
carries `content` (json → array cast) instead of a single `prompt_body` string. Each entry's shape is
decided entirely by its part's `PartKind` (D8), so the map is exactly as data-driven as the type itself —
`Template::partContent(string $key)` is the one typed getter every consumer (validator, render service, FE
editor) reads through.

**D4 — `video_script` RESERVES a scene/media plan NOW, not just text (override of the original default
scope).** `video_script`'s parts are `script` (the scenario text, required) + `scene_plan` (an ORDERED list
of scenes, optional, may be empty — "reserved now, lean v1 authoring"). Each scene is
`{narration: {markdown}, image_plan?}` — a text_body-like narration plus an OPTIONAL nested image plan,
reusing the SAME image-plan shape a `post_with_image`'s `image` part uses (D5). Modeled fully in the
registry, the write validator (`TemplateContentValidator::validateScenePlan()` +
`ImagePlanValidator` reused per-scene), and the faithful render (`TemplateRenderService::scenePlanSummary()`
— a per-scene resolved-narration + resolved-image-plan summary). The FE (`TemplateScenePlanPart.vue`) is
correspondingly lean: add/remove/reorder scenes, an opt-in per-scene image toggle — polish (drag-reorder
UX, richer scene metadata) is explicitly left for later, not blocking this rework.

**D5 — an image plan's `base` may be `disk_file` (a fixed Disk file) OR `from_slot` (a declared FILE-typed
slot, filled per session).** Both are first-class, mutually exclusive base kinds
(`ImagePlanValidator::BASE_KINDS`). `from_slot` must name a slot whose descriptor `base` is `file`
(`ImagePlanValidator::validateFromSlot()` — checked against the SAME declared-slot list a body directive
validates against). `disk_file` carries an id (D-boundary note below explains why it is opaque here). See
D6 for the third base kind.

**D6 — `ai_generate` is MODELED but SHOWN DISABLED; execution is sub-stage 6 (confirmed, unchanged from
the original plan).** A base of kind `ai_generate` — `{kind:'ai_generate', prompt: <markdown>}` — is
ACCEPTED by the write validator (its prompt is directive-validated exactly like a body) and rendered as a
resolved-prompt PLAN entry in the preview (`TemplateRenderService::baseSummary()` labels it
`"AI image (coming soon)"`). The FE's base picker (`TemplateImagePlanPart.vue`) disables the option outright
(`IMAGE_BASE_KINDS.map(... disabled: kind === 'ai_generate')`) and the client completeness gate
(`imagePlan.ts::isBaseComplete()`) always returns `false` for it — so a plan can never be SAVED with an
`ai_generate` base as its effectively-chosen one from the UI today, even though the API accepts one. Never
required to author or preview any other plan; a future session (sub-stage 2) would need to error clearly if
it is asked to execute one before the text→image client ships (sub-stage 6) — there is no such executor yet
to error from in this rework.

**D7 — ONE shared "metered AI call" seam is DEFINED NOW, no-op by default; the real meter is sub-stage
2's job.** `Variables\Contracts\MeteredAiCall` (`meter(string $channel, callable $call): mixed`) lives next
to `AiTextGenerator` in the Variables module — the lower layer both Workflows and Generator depend on — so
no upper module owns it. It is bound in `VariablesModuleServiceProvider` to
`Support\PassthroughMeteredAiCall`, which just invokes `$call` and returns its result: **no metering, no
budget, no counting ships in this rework.** Nothing calls `->meter()` yet — no caller is wired through it —
because sub-stage 1 still runs zero real AI (the preview's `AiTextGenerator` is the inert
`NoOpAiTextGenerator`, and no image executor exists at all). The seam exists so sub-stage 2 (the first real
AI spender — Sessions) can bind a real meter in the SAME slot and thread both the real `AiTextGenerator` and
the eventual `ImageAiService::edit` call through it, with zero change to either caller's own code.

**D8 — the part-KIND set is CLOSED for v1: `text_body`, `image_plan`, `script`, `scene_plan`
(`Enums\PartKind`).** This is the load-bearing rule the whole rework is built on: **every** behavioral
switch — the write validator's per-part dispatch (`TemplateContentValidator::validatePart()`), the render
service's per-part dispatch (`TemplateRenderService::renderPart()`), and the FE editor's per-part section
(`TemplateEditorDrawer.vue`'s `v-if` chain on `part.kind`) — switches on `kind`, **never** on the
content-type `id`. A content type only DECLARES which parts it has and whether each is required; it
carries no behavior of its own (`ContentTypeDefinition`/`ContentTypePart` are plain data). A future
user-created type can therefore only RECOMBINE these four kinds — "a `post_with_video_script` that has a
body AND a scene plan" costs the validators/renderer/editor zero new code, because each part they see is
still one of the four known kinds. A genuinely new kind (e.g. an audio plan) is a deliberate, separate
future change, not something a content-type author can invent from the UI.

### Boundary note (unchanged decision, restated for this model)

The image-plan's `disk_file` base introduces a natural `Generator → Disk` dependency for existence/ownership
validation. This rework keeps Phase 1 authoring **Disk-DECOUPLED**, per the accepted plan: `disk_file`'s
`file` id is stored as an OPAQUE string (`ImagePlanValidator::requireNonEmptyString()` — non-empty, nothing
more) and is never looked up against the Disk module at write time. Existence/ownership validation is
deferred to session/execution time (sub-stage 2), so `App\Modules\Generator` still imports NOTHING from
`App\Modules\Workflows` (`GeneratorModuleBoundaryTest`, unchanged) and, per this decision, nothing from
`App\Modules\Disk` either. The FE's `TemplateImagePlanPart.vue` DOES reach into Disk today
(`DiskFilePickerModal.vue`, so a user can pick a real file by name) — that is a FE-only convenience; the
backend contract never round-trips through Disk in this rework.

## What did NOT change

- The slot model, its identifier rules, its `ConstantTypeValidator`-backed descriptor validation, its
  object/file subfield descent, and the `TemplateVariableCatalog` trio (`forSlots`/`referenceIndex`/
  `typeMap`) — ADR-0031 Decisions 4 and 6 (plus their later "structural slots" amendment) stand unchanged.
- The one-way module boundary (`Generator → Variables`, never `Workflows`) and its standing test.
- The faithful-preview principle: the renderer still calls the SAME shared `VariableResolver::resolveString()`
  a real workflow run uses, still with a Generator-owned `NoOpAiTextGenerator` bound contextually only for
  `TemplateRenderService` (ADR-0031 Decision 8) — only now it is called once PER PART instead of once for a
  single body.
- CRUD shape: `TemplateController`/`TemplateService`/`TemplatePolicy` are unchanged in behavior (creator-only
  mutation, member read, cursor-paginated `index`, hard delete, dual central/tenant persistence) — only
  `TemplateService::attributes()` and `TemplateResource`'s field list moved from
  `type/prompt_body/parameters` to `content_type/content`.
- `POST /generator/catalog` — unchanged wire contract; still slots-only, still draft-friendly.

## Consequences

- **Positive.** The model now matches the product concept it names: a Template's shape (slots + content
  type + per-part content) directly mirrors "declare typed inputs, author the finished post, plan any
  media" — there is no gap between what the schema stores and what a user thinks they are building.
- **Positive.** D8's closed-kind-switching rule means the FIRST real payoff of D1 (code-defined types) is
  already visible in this rework: `video_script` and `post_with_image` share the identical `image_plan`
  kind's validator/renderer/editor code (a scene's optional image plan IS the same component
  `TemplateImagePlanPart.vue` as the top-level `image` part) — nothing was forked per content type.
- **Positive.** Because `ai_generate` is modeled (not merely planned-on-paper) but inert everywhere it
  matters (write-accepted, preview-labeled, FE-disabled, never client-completable), sub-stage 6 only needs
  to ADD an executor and flip the FE's `disabled` flag — no schema or validator change.
- **Trade-off (accepted): `image_plan`'s `disk_file` base is write-accepted with NO existence check.** A
  template can reference a Disk file id that does not exist, belongs to another workspace, or was later
  deleted — this is deliberate (Decision boundary note) to keep Phase 1 Disk-decoupled; the cost is a
  template that will fail cleanly (not silently) only once sub-stage 2 tries to execute it.
- **Trade-off (accepted): D7's seam is unreachable dead code from any caller's perspective today.** Neither
  `NoOpAiTextGenerator` nor any image path calls `MeteredAiCall::meter()` yet — it is bound and testable in
  isolation, but nothing exercises it end-to-end until sub-stage 2 wires a real caller through it. Named
  explicitly rather than left silent, matching this module's own precedent (ADR-0031's Consequences did the
  same for `RESERVED_SLOT_NAMES`).
- **Rejected: keeping `prompt_body` as an alias / dual-writing both shapes.** Rejected — the branch carried
  zero committed history and zero data, so there was nothing to keep compatible; a dual shape would only
  have doubled the validator/renderer surface this rework exists to simplify.
- **Rejected: modeling `scene_plan` as a flat list of alternating text/image blocks instead of typed
  scenes.** Rejected — a scene is a meaningful authoring unit (narration + its own optional visual), and a
  flat block list would need its own ad-hoc pairing convention the typed `{narration, image_plan?}` shape
  avoids entirely.

See `docs/backend/generator-api.md` for the full endpoint contract this ADR's model implements (request/
response shapes, the validation error tables, worked examples verified against
`tests/Feature/TemplateContentValidationTest.php` / `TemplatePreviewTest.php` / `ContentTypeApiTest.php`),
and `docs/decisions/ADR-0031-generator-module-templates.md` for the historical "prompt + parameters" record
this ADR supersedes.
