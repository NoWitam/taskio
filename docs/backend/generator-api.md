# Backend API: Generator module

Module: `app/modules/Generator/` — R2 sub-stage 1 ("Templatki"), **content-recipe rework**. This document
covers the **Template (definition)** endpoints only — creating/editing a reusable recipe and previewing it.
For **executing** a recipe (a generation Session: real AI spend, the server image chain, save-to-Disk, the
per-part refine loop), see `docs/backend/generator-sessions-api.md` (R2 sub-stage 2, shipped).

The Generator module imports **NOTHING** from `app/modules/Workflows/` (a one-way boundary pinned by
`tests/Feature/GeneratorModuleBoundaryTest.php`, mirroring the boundary
**ADR-0027-variables-module-extraction.md** already established for `Workflows → Variables`), and depends on
`app/modules/Variables/` (the variable TYPE SYSTEM, the pipeline OPERATION ENGINE, and the shared
interpolation `VariableResolver` — see **ADR-0030-variable-engine-down-move.md**). **The TEMPLATE endpoints
documented below stay Disk-decoupled** — authoring never looks up a `disk_file` id — but the module AS A
WHOLE is no longer Disk-free: R2 sub-stage 2c added a deliberate `Generator → Disk` EXECUTION edge (a
session's image chain reads/writes Disk files) — see "Boundary" below and
**ADR-0034-generation-sessions.md**. See **ADR-0032-generator-content-recipe.md** for the full design record
this document's wire contract implements (and **ADR-0031-generator-module-templates.md** for the superseded
"prompt + parameters" model this rework replaced before its first commit).

> **`video_script` rework (2026-07-28).** `video_script` originally composed `[script, scene_plan]` (a
> free-form scenario + optional scene list) — rejected by the owner as useless for producing a real
> short-form video (no coherent hook/shots/timing structure, and no way for one part to reference another's
> generated output). It now composes `[shot_list, storyboard]`: a STRUCTURED short-video shot list (hook /
> timed shots / cta, one metered AI call) plus an executor-iterated storyboard (one AI image per shot). This
> rework also added a GENERAL engine primitive — a part may reference an EARLIER part's rendered output as
> `parts.<key>` — usable by any content type, not only `video_script`. `script`/`scene_plan` are KEPT in the
> closed `PartKind` vocabulary and still fully render/refine/undo for an EXISTING session's snapshot
> (snapshot-authoritative back-compat), but are no longer offered for new authoring. See
> **ADR-0035-video-script-cross-part-storyboard.md** for the full design record; "Content parts" and
> "shot_list" / "storyboard" below cover the new authored shapes, and "Cross-part references" below covers
> the `parts.<key>` write-validation. Session-side (the structured JSON contract, the storyboard's per-shot
> execution/addressing) is documented in `docs/backend/generator-sessions-api.md`.

Auth: all endpoints require `auth:sanctum` + `X-Workspace-Id` header (resolved by `ResolveWorkspace`
middleware).
Tenant scope: `TenantAware` trait on `Template` — every query is automatically scoped to the active
workspace (shared-mode via `WorkspaceScope`, own-mode via the tenant connection).

> **Sub-stage 1 ("Templatki") — this revision, content-recipe model.** The Generator module's first concept
> is a **Template**, a reusable, workspace-scoped RECIPE for a finished post. A Template BY ITSELF is
> authored, stored, and faithfully PREVIEWED per part; nothing in the endpoints below EXECUTES a real AI
> call or resolves an image — that is what a generation **Session** (R2 sub-stage 2, now shipped — see
> `docs/backend/generator-sessions-api.md`) does. **Update (R2 sub-stage 5, shipped):** a workflow step,
> `generate_content`, now consumes a template this way from inside a workflow run — see
> `docs/backend/workflows-api.md` → "Steps: `generate_content`" and "Planned / deferred" below for what
> still remains. This sub-stage (1)
> shipped alongside a precursor refactor (ADR-0030) that relocated the
> shared interpolation engine (`WorkflowVariableResolver` → `App\Modules\Variables\Services\
> VariableResolver`) and the form-independent half of the variable catalog
> (`App\Modules\Variables\Services\VariableCatalog`) down into the Variables module — a PURE,
> behavior-preserving refactor with **no wire change to anything documented in
> `docs/backend/workflows-api.md`**. Templates consume that same relocated engine directly; nothing about a
> Template's resolution is a second implementation.

---

## Concepts

A **Template** is a reusable, user-created RECIPE for a finished post (a "content factory"): define its
shape once, mass-produce concrete posts by filling a few typed **slots**, with AI doing the delegated
parts. A Template does nothing by itself — "running" one is a generation **Session**
(`docs/backend/generator-sessions-api.md`, R2 sub-stage 2, shipped), which snapshots the recipe and
executes it for real. What THIS document covers is authoring a Template and rendering a FAITHFUL, per-part
preview of it — never a real run.

```
Template (definition)
  ├─ content_type       (post | post_with_image | video_script — a ContentTypeRegistry id)
  ├─ slots[]             ({name, description?, descriptor} — DECLARED typed inputs)
  └─ content             (a per-part map, keyed by the content type's part keys; each part's own shape
                           is decided by its KIND — see "Content types & parts" below)
```

A **slot** is a DECLARED typed placeholder, not something derived from parsing the content — the same
"declare, then reference" shape a `CustomFunction`'s `args` already use (see `docs/backend/workflows-api.md`
→ "Custom functions"). A slot's `descriptor` is the identical `{base, nullable?, array?, options?, fields?}`
shape a `Constant` declares (see `docs/backend/workflows-api.md` → "The `globals` root"), validated by
`ConstantTypeValidator::validateSlotDescriptorShape()` — the SAME descriptor authority a const's own type
uses (`validateDescriptorShape()`), widened with ONE extra authorable base a const does not get: `file`, a
slot-only REUSABLE composite (see "Object and file slots" below). So a slot's type can never accept a shape
the resolver/catalog does not understand, while a const's own bases stay untouched (`file` still rejected
there). **The slot model is unchanged by this rework** — see ADR-0032's "What did NOT change".

A **content type** decides which PARTS a template's `content` map is made of (a `post` has a `body`; a
`post_with_image` has a `body` and an `image`; a `video_script` has a `shot_list` and a `storyboard` — see
the rework note above). A part is authored AS its finished form — a text/script part is markdown carrying
the SAME `@[variable]("<json>")` directive serialization a workflow step's text field already uses (no new
wire format), plus first-class inline `@[ai-text]` AI blocks ("the whole thing by AI" is simply one big
`@[ai-text]` block, not a separate concept); a media (image) part is a DECLARED plan — a base + an ordered
transform chain — not text at all; a `shot_list`/`storyboard` part (video_script rework Phase B) is
authored as an input to a LATER structured/executor-driven step rather than as its own finished form — see
"`shot_list` & `storyboard`" below.

---

## Content types & parts

### `ContentTypeRegistry` — a code-defined registry, not a table

The three v1 content types are DATA held in `Services\ContentTypeRegistry::all()` (no `content_types`
table/seeder in this sub-stage — none exists for consts or custom functions either, the same precedent).
Every part carries a `kind` drawn from a CLOSED, four-member vocabulary (`Enums\PartKind`); **all**
editing/validation/rendering behavior switches on a part's `kind`, **never** on the content type's `id`
(D8, ADR-0032) — a future user-created content type would only RECOMBINE these kinds, costing the
validators/renderer/editor zero rework.

| Content type `id`  | Label             | Parts (`key` → `kind`, required?)                                      |
|----------------------|--------------------|----------------------------------------------------------------------------|
| `post`                 | Post                 | `body` → `text_body` (required)                                        |
| `post_with_image`         | Post with image        | `body` → `text_body` (required); `image` → `image_plan` (optional) |
| `video_script`               | Video script              | `shot_list` → `shot_list` (required); `storyboard` → `storyboard` (optional) |

`video_script` PREVIOUSLY composed `script` → `script` (required) + `scene_plan` → `scene_plan` (optional);
this shape is no longer authorable but an EXISTING session's snapshot still renders/refines it exactly as
before — see "shot_list & storyboard — the video_script rework" below.

| `PartKind`   | What it authors                                                                            |
|---------------|-------------------------------------------------------------------------------------------------|
| `text_body`    | The post BODY: static text + slot values + inline `@[ai-text]` blocks — markdown carrying the SAME `@[variable]` directives a workflow text field uses. |
| `image_plan`         | A media plan for ONE image: a `base` (where the image starts) + an ordered FILTER CHAIN (how it is transformed) — mirrors the shape of a Variables pipeline. See "Image plan" below. |
| `shot_list`  | The STRUCTURED short-video shot list (`video_script` rework Phase B): an authored creative `{brief}` feeds ONE metered AI call that returns a coherent hook / ordered timed shots / cta as JSON. See "shot_list & storyboard" below. |
| `storyboard` | The EXECUTOR-ITERATED image plan of a `shot_list` (Phase B): an optional authored `{style, filters}`, one AI-generated image PER shot at run time. See "shot_list & storyboard" below. |
| `script` *(legacy)*        | A video SCENARIO — a `text_body`-like markdown body (validates/renders through the identical body path — `PartKind::isBody()`). No longer offered by `ContentTypeRegistry::all()` for new authoring (dropped from `video_script` by the Phase B rework); kept only so an EXISTING session snapshot still renders it (`ContentTypeRegistry::legacyParts()`). |
| `scene_plan` *(legacy)*             | An ORDERED list of scenes, each a narration (`text_body`-like) + an OPTIONAL nested `image_plan` (D4). Same legacy status as `script` — see "Scene plan" below. |

### GET /api/generator/content-types

The code-defined content-type catalog the template editor builds its data-driven sections from.
Authorization: `TemplatePolicy::viewAny` (any workspace member — the same read gate the template list
uses). Backed by `ContentTypeController` → `ContentTypeResource` (a thin wrap of
`ContentTypeDefinition::toArray()`, so the wire can never drift from what the validators/renderer read).

**Response** `200 OK` — verified against `tests/Feature/ContentTypeApiTest.php`:

```json
{
  "data": [
    { "id": "post", "label": "Post", "parts": [
      { "key": "body", "kind": "text_body", "label": "Post body", "required": true, "config": {} }
    ] },
    { "id": "post_with_image", "label": "Post with image", "parts": [
      { "key": "body", "kind": "text_body", "label": "Post body", "required": true, "config": {} },
      { "key": "image", "kind": "image_plan", "label": "Image", "required": false, "config": {} }
    ] },
    { "id": "video_script", "label": "Video script", "parts": [
      { "key": "shot_list", "kind": "shot_list", "label": "Shot list", "required": true, "config": {} },
      { "key": "storyboard", "kind": "storyboard", "label": "Storyboard", "required": false, "config": {} }
    ] }
  ]
}
```

`script`/`scene_plan` no longer appear in this response (they are absent from `ContentTypeRegistry::all()`
— see the `video_script` rework note above); a session created before the rework still carries them in its
OWN `recipe_snapshot` and renders them regardless of what this endpoint returns today.

`config` is reserved per-kind authoring metadata — empty for every v1 system part; a future kind may
populate it. **Errors**: `401` unauthenticated.

---

## Endpoints

### GET /api/generator/templates

List the workspace's templates. Cursor-paginated, 20 per page, ordered by `name` (ascending — mirrors
the Consts/Functions list, not "newest first"). Authorization: `TemplatePolicy::viewAny` — any
authenticated user; workspace membership itself is enforced upstream by `ResolveWorkspace`/`WorkspaceScope`.

**Query**

| Param    | Required | Notes                                              |
|----------|----------|--------------------------------------------------------|
| `search` | no       | case-insensitive match on `name` (`Searchable` trait) |
| `cursor` | no       | cursor from `meta.next_cursor` for the next page   |

**Response** `200 OK`

```json
{ "data": [ TemplateResource ], "meta": { "next_cursor": "string | null" } }
```

Each row eager-loads `creator` (`with('creator')` in `TemplateService::index()`), so the list can render
the creator badge without an N+1.

---

### POST /api/generator/templates

Create a template. Authorization: `TemplatePolicy::create` (any authenticated user).

**Body**

| Field           | Required | Constraints                                                                 |
|------------------|----------|------------------------------------------------------------------------------|
| `name`            | yes      | string, max 255 — a label only, NOT unique (identity is the row's uuid).    |
| `description`       | no       | string, max 2000                                                          |
| `content_type`         | yes      | one `ContentTypeRegistry` id (`post` \| `post_with_image` \| `video_script`) — checked against `ContentTypeRegistry::ids()` |
| `slots`                   | yes (key always sent) | array of `{ name, description?, descriptor }` — may be empty. Deep-validated (see "Slots" below). |
| `content`                    | yes (key always sent) | object keyed by the content type's PART keys — deep-validated PER PART BY KIND (see below). |

**Validation summary** (`StoreTemplateRequest` for the coarse shape, delegating the deep checks to
`TemplateContentValidator` in `withValidator()` — the SINGLE authority for a template's slots + per-part
content, shared by Store/Update). `TemplateContentValidator` validates the (content-type-shared) `slots`
list ONCE via `TemplateSlotValidator::validateSlots()`, then iterates the selected type's declared PARTS
and checks each part's `content.<key>` BY KIND:

| Code | Field                             | Meaning                                                                 |
|------|--------------------------------------|----------------------------------------------------------------------------|
| 422  | `name`                                 | Required, max 255.                                                      |
| 422  | `content_type`                            | Not one of `ContentTypeRegistry::ids()`.                             |
| 422  | `slots.<i>.*`                                | Same slot rules as before this rework — see "Slots" below.        |
| 422  | `content.<key>`                                 | An UNKNOWN part key — not declared by the selected content type — was sent (`TemplateContentValidator::rejectUnknownParts()`). |
| 422  | `content.<key>`                                    | A REQUIRED part's content is missing/null. An OPTIONAL part may be entirely absent. |
| 422  | `content.<key>.markdown`                              | (`text_body`; `script` — currently unreachable, see "Legacy `script`/`scene_plan`" below) Not a string; OR a directive references an unknown `slots.<name>` / unknown subfield / a forward-or-self `parts.<key>`; OR a directive's pipeline does not type-check — the SAME rules `prompt_body` used before this rework, now PART-AGNOSTIC (`TemplateSlotValidator::validateBody($validator, …, $promptKey, $earlierPartKeys)` takes the exact key to report under, plus the cross-part earlier-only scope — see "Cross-part references" below). |
| 422  | `content.<key>.base` / `.base.kind` / `.base.file` / `.base.slot` / `.base.prompt` | (`image_plan`) A malformed/missing base, an unknown base `kind`, a `disk_file` with no non-empty `file` id, a `from_slot` not naming a declared FILE-typed slot, or an `ai_generate`/`ai_edit` prompt with an unknown slot OR forward/self `parts.<key>` reference. See "Image plan" below. |
| 422  | `content.<key>.filters` / `.filters.<i>.*`     | (`image_plan`, and `storyboard`'s own optional `filters` — reuses the identical authority) The filter chain is not an ordered list; an unknown filter `kind`; an unknown pixel `op`; out-of-range/malformed pixel `params`; an `ai_edit` prompt with an unknown slot / forward-or-self `parts.<key>` reference. See "Image plan" below. |
| 422  | `content.<key>.brief.markdown`          | (`shot_list`) The creative brief fails the SAME body-directive check as `text_body`'s `markdown` (including the cross-part earlier-only gate). See "`shot_list` & `storyboard`" below. |
| 422  | `content.<key>.style.markdown`          | (`storyboard`, when `style` is present) The optional style prompt fails the SAME body-directive check. See "`shot_list` & `storyboard`" below. |
| 422  | `content.<key>.max_shots`          | (`storyboard`, when `max_shots` is present) Not a whole number, or outside `1..generator.storyboard_max_shots` (the platform ceiling, default 8). See "`shot_list` & `storyboard`" below. |
| 422  | `content.<key>.scenes` / `.scenes.<i>.*`          | (`scene_plan` — currently unreachable, see "Legacy `script`/`scene_plan`" below) `scenes` is not an ordered list; a scene is not an object; a scene's `narration.markdown` fails the same body-directive check; a scene's optional `image_plan` fails the same image-plan checks. See "Scene plan" below. |
| 401  | —                                                         | Unauthenticated.                                                |

**Response** `201 Created` — `TemplateResource` with `creator` loaded. Example — verified against
`tests/Feature/TemplateCrudTest.php::test_can_create_a_template()` (the `@[variable](...)` shorthand below
stands for the real byte-format, `@[variable]("<json>")` — see "Two serializations, resolved by
`VariableResolver`" in `docs/backend/workflows-api.md`):

Request:

```json
{
  "name": "Launch post",
  "description": "A product launch post",
  "content_type": "post",
  "slots": [
    { "name": "topic", "description": "The subject", "descriptor": { "base": "text", "nullable": false, "array": false } }
  ],
  "content": {
    "body": { "markdown": "Write about @[variable](\"...slots.topic...\")." }
  }
}
```

Response:

```json
{
  "data": {
    "id": "…",
    "name": "Launch post",
    "description": "A product launch post",
    "content_type": "post",
    "slots": [
      { "name": "topic", "description": "The subject", "descriptor": { "base": "text", "nullable": false, "array": false } }
    ],
    "content": {
      "body": { "markdown": "Write about @[variable](\"...slots.topic...\")." }
    },
    "creator": { "type": "user", "id": "…", "name": "…" },
    "is_owner": true,
    "can_be_edited": true,
    "can_be_deleted": true,
    "created_at": "…",
    "updated_at": "…"
  }
}
```

`slots` on the wire is always shaped to exactly `{name, description, descriptor}` per entry (a malformed
stored slot is skipped, never leaked raw); `content` is emitted exactly as authored (validated on write),
keyed by the content type's part keys — **there is no `type`/`prompt_body`/`parameters` on the wire
anymore** (verified by the same test's `assertJsonMissingPath`).

---

### GET /api/generator/templates/{template}

Fetch one template. Authorization: `TemplatePolicy::view` (any workspace member) — a foreign-workspace
`{template}` is filtered out by `WorkspaceScope` before route-model binding ever sees it, so it **404s**,
never 403.

**Response** `200 OK` — `TemplateResource` with `creator` loaded. **Errors**: `404` not found (including a
foreign-workspace id).

---

### PUT /api/generator/templates/{template}

Update a template. Same body/validation rules as `POST` (`UpdateTemplateRequest extends
StoreTemplateRequest`, identity + content_type + slots + per-part content ALL re-validated in full — a
template has no partial-update shortcut). Authorization: creator only (`TemplatePolicy::update` →
`ChecksRecordOwnership::ownsOrManagesSystemRecord` — in practice always the creator, since a template's
creator is always a human user; the trait's workspace-owner fallback for a creator-less SYSTEM record never
applies to a template).

**Response** `200 OK` — `TemplateResource`. **Errors**: `403` not creator, `404` not found, `422`
validation (same table as `POST`).

---

### DELETE /api/generator/templates/{template}

Permanently delete a template. **No soft-delete, no restore** — like `Constant`/`CustomFunction`, unlike
`Workflow`, `Template` does not use `SoftDeletes` (no `deleted_at` column); `TemplateService::delete()` is
a hard `Model::delete()`. There is no delete-while-referenced guard (unlike a custom function): a
generation session SNAPSHOTS a template's recipe into its own `recipe_snapshot` at creation and renders
only from that copy, so deleting a referenced template never breaks an existing session (a workflow step
referencing a template is a later sub-stage). Authorization: creator only.

**Response** `200 OK` — `{ "message": "Template deleted successfully" }`. **Errors**: `403` not creator,
`404` not found.

---

### POST /api/generator/catalog

The DRAFT-FRIENDLY, server-authoritative TEMPLATE catalog: takes a set of DECLARED slots and returns the
`slots.<name>` typed variables MERGED with the shared authoring surface (workspace globals, the operation
catalog including custom functions as `fn:<uuid>` entries, and the variable-type list). **Unchanged by
this rework** — still slots-only, still POST because the slots are an in-progress template's DRAFT and
ride the request body. Authorization: `TemplatePolicy::viewAny` (any workspace member). Backed by
`TemplateCatalogController` → `TemplateCatalogRequest` (coarse shape only) →
`TemplateVariableCatalog::forSlots()`.

**Body**

| Field   | Required | Notes                                                                 |
|----------|----------|--------------------------------------------------------------------------|
| `slots`   | yes (key always sent) | array of `{ name?, descriptor? }`. Only the COARSE shape is validated here — a malformed slot (missing name/descriptor) is SKIPPED by the catalog builder (fail-soft), never `422`'d, so an in-progress draft still gets a usable catalog for its well-formed slots. |
| `content_type` | no | string. Cross-part scoping (video_script rework Phase A): paired with `part_key`, offers the parts declared BEFORE `part_key` as `parts.<earlierKey>` catalog variables. Omitted/blank → no `parts.*` entries (unchanged behavior for callers that don't pass it). |
| `part_key` | no | string. The content-type part currently being authored — see `content_type` above. Both must be present together to have any effect (`ContentTypeRegistry::partKeysBefore()`); either alone is a no-op. |

**Response** `200 OK` — `{ data: { variables, operations, types } }` (the identical envelope shape
`GET /api/workflows/catalog` returns). Example (one `text` slot with a description, one `enum` slot, a
workspace global `brand`, and a workspace custom function):

```json
{
  "data": {
    "variables": [
      { "source": "slots", "path": "slots.topic", "name": "The subject", "type": "text", "descriptor": { "base": "text", "nullable": false, "array": false } },
      { "source": "slots", "path": "slots.tone", "name": "tone", "type": "enum", "descriptor": { "base": "enum", "options": [ { "key": "formal", "label": "Formal" }, { "key": "casual", "label": "Casual" } ] }, "enumOptions": ["formal", "casual"] },
      { "source": "globals", "path": "globals.brand", "name": "Brand", "type": "text", "descriptor": { "base": "text", "nullable": false, "array": false } }
    ],
    "operations": [ { "id": "fn:b1b2c3d4-...", "input": "text", "output": "text", "args": [], "label": "Uppercase", "description": null }, ... ],
    "types": [ { "id": "text", "primitive": "text", "operators": ["equals", "..."] }, ... ]
  }
}
```

(`operations` also carries the built-in ops, elided above; `types` carries all `VariableType` cases — both
truncated with `...` here for brevity, matching `docs/backend/workflows-api.md`'s own catalog example.)

A `slots.<name>` entry's `source` EQUALS the root (`slots`) — mirroring `trigger`/`steps`/`globals` — and
its `name` (the display label) prefers the slot's own `description`, falling back to its identifier `name`
when no description was given. An `object`/`file` slot's entry carries its full `descriptor.fields` — see
"The catalog wire contract for structural slots" below. Workspace globals/functions are scoped to the
ACTIVE workspace exactly like every other Variables-module read. **Errors**: `401` unauthenticated.

---

### POST /api/generator/preview

The FAITHFUL, DRAFT-FRIENDLY, **PER-PART** template preview: renders an (unsaved) content recipe against
sample slot values plus the workspace globals through the SAME shared `VariableResolver` a real generation
would use — the real executor, directives, if-blocks, pipelines, custom functions, and NUL-mask injection
guards. **This is the endpoint this rework changed the most:** it previews EVERY declared part of the
selected content type, by KIND, in one call — not a single `prompt_body` string. `@[ai-text]` is
INERT-but-LABELED in this sub-stage (see "The faithful, per-part preview" below). **POST** because the
whole draft — content type, content, slots, and sample values — rides the request. Authorization:
`TemplatePolicy::viewAny` (any workspace member). Backed by `TemplatePreviewController` →
`TemplatePreviewRequest` (coarse shape only) → `TemplateRenderService::render()`.

**Body**

| Field           | Required | Notes                                                                 |
|------------------|----------|--------------------------------------------------------------------------|
| `content_type`      | yes (key always sent) | nullable string — the draft's recipe SHAPE. An unknown/absent type renders NO parts (fail-soft, never `422`). |
| `content`               | yes (key always sent) | object keyed by part — the draft per-part content, ANY shape (render is fail-soft per part). |
| `slots`                    | yes (key always sent) | array of `{ name?, descriptor? }` — the declared slots (used to build the runtime type map so a pipeline executes against each reference's real type). |
| `slot_values`                  | no       | object `{ <slot name>: <sample value> }` — any shape a slot may hold: a scalar/list/null for a plain slot, a NESTED `{field: value}` object for an `object` slot, or a file SNAPSHOT `{id,name,type,size,url}` for a `file` slot (see "Structured `slot_values`" below). A slot with no sample value resolves empty rather than throwing. |

**Response** `200 OK` — `{ "data": { "parts": { "<partKey>": {"rendered": "string"} | {"plan": {...}} } } }`.
Which shape a part carries depends on its `kind`:

| `PartKind`   | Preview shape                          | Meaning                                                                 |
|---------------|--------------------------------------------|-------------------------------------------------------------------------|
| `text_body` / `script` | `{ "rendered": "<string>" }`     | The markdown body resolved through the shared resolver — the faithful finished text. |
| `image_plan`             | `{ "plan": { "base": {...}\|null, "filters": [...] } }` | A PLAN SUMMARY — the base + the ordered filter chain, with every prompt string directive-RESOLVED. **No image is executed IN THIS PREVIEW** (a generation Session executes the plan server-side — sub-stage 2c). |
| `scene_plan`                 | `{ "plan": { "scenes": [{ "narration": "<string>", "image": {...}\|null }] } }` | A per-scene summary — each scene's resolved narration + its resolved image-plan summary (or `null` when the scene has no image). |
| `shot_list` *(video_script rework)*  | `{ "brief": "<string>" }` | The resolved creative BRIEF only — **no structured AI call runs in a preview** (that happens once per real session run); the hook/shots/cta are produced only by a generation Session. |
| `storyboard` *(video_script rework)* | `{ "plan": { "style": "<string>", "filters": [...], "max_shots": int\|null } }` | A PLAN SUMMARY of the resolved style + the authored filter chain + the authored shot cap (`null` when unauthored — the run then uses the platform ceiling) — there is no `base` to summarize (a session auto-generates one per shot) and **no image is executed nor any shot enumerated** here (the storyboard's shots come from the sibling `shot_list`'s real run output, which a preview never produces). |

**`parts.<key>` (video_script rework Phase A) resolves EMPTY in a preview.** `TemplateRenderService::render()`
builds ONE execution context shared by every part — it does NOT accumulate a `parts` map across parts the
way a real session run does (`docs/backend/generator-sessions-api.md`). A `parts.<key>` reference in a
preview therefore behaves like any other unpopulated whitelisted root: it resolves to `''` (or an empty
plan), never the actual earlier-part text a real run would produce. This is a deliberate scope-limit, not a
bug — see `docs/decisions/ADR-0035-video-script-cross-part-storyboard.md` (D5).

**Examples — text_body (verified against `tests/Feature/TemplatePreviewTest.php`).** Every example below
previews a `post`, reading its `body` part's `rendered` result:

| Scenario                                   | `content.body.markdown`                                                                 | `rendered`          |
|----------------------------------------------|----------------------------------------------------------------------------------------|------------------------|
| A piped slot                                    | `"Topic: @[variable](slots.topic \|> text_uppercase)!"`, `slot_values: {topic: "hello"}` | `"Topic: HELLO!"`      |
| An identity slot                                   | `"About @[variable](slots.topic)."`, `slot_values: {topic: "Taskio"}`        | `"About Taskio."`      |
| `@[ai-text]` (inert-but-labeled)                      | `"Intro: @[ai-text](\"Write a punchy hook\") End."`                          | `"Intro: [AI: Write a punchy hook] End."` |
| An unknown root (not a reference)                        | `"@[variable](nope.foo)"`                                                     | `""`                    |
| A workspace global                                          | `"By @[variable](globals.brand)."` (with a `brand` const = `"Taskio"`)          | `"By Taskio."`          |
| A declared slot with NO sample value (fail-soft)                | `"Topic: @[variable](slots.topic)."`, `slot_values: {}`                            | `"Topic: ."`            |
| An object slot's SUBFIELD                                          | `"Product: @[variable](slots.product.name)."`, `slot_values: {product: {name: "Widget", price: 9}}` | `"Product: Widget."`   |
| A file slot's SUBFIELD                                                | `"See @[variable](slots.image.url)."`, `slot_values: {image: {id:"f1", name:"photo.png", type:"image/png", size:2048, url:"https://cdn/x.png"}}` | `"See https://cdn/x.png."` |

`@[ai-text]` renders to a LABELED `[AI: <resolved prompt>]` placeholder, never empty and never the literal
`@[ai-text](...)` bytes — see "The faithful, per-part preview" below for why.

**Example — `image_plan` (verified against
`TemplatePreviewTest::test_an_image_plan_part_renders_a_plan_summary_with_resolved_prompts()`).** Preview a
`post_with_image` with a `photo` file slot and a `topic` text slot:

```json
{
  "content_type": "post_with_image",
  "content": {
    "body": { "markdown": "Hi" },
    "image": {
      "base": { "kind": "from_slot", "slot": "photo" },
      "filters": [
        { "kind": "pixel", "op": "grayscale" },
        { "kind": "ai_edit", "prompt": "In the style of @[variable](\"...slots.topic...\")." }
      ]
    }
  },
  "slots": [
    { "name": "topic", "descriptor": { "base": "text" } },
    { "name": "photo", "descriptor": { "base": "file", "fields": [ /* … the fixed 5 */ ] } }
  ],
  "slot_values": { "topic": "noir" }
}
```

returns (the `image` part — NO `rendered` key, only `plan`):

```json
{
  "data": { "parts": { "image": {
    "plan": {
      "base": { "kind": "from_slot", "label": "From slot: photo", "slot": "photo" },
      "filters": [
        { "kind": "pixel", "op": "grayscale", "label": "grayscale", "params": null },
        { "kind": "ai_edit", "label": "AI edit", "prompt": "In the style of noir." }
      ]
    }
  } } }
}
```

The `ai_edit` prompt is directive-resolved against the SAMPLE `slot_values` — a faithful preview of what a
real run would send the image-edit provider, even though no provider call happens here. A `disk_file` base
summarizes as `{kind:"disk_file", label:"Disk file", file:<the opaque id>}`; an `ai_generate` base
summarizes as `{kind:"ai_generate", label:"AI image", prompt:<resolved>}` (RUNNABLE by a Session since R2
sub-stage 6 — see `docs/backend/generator-sessions-api.md`; the preview still only summarizes, never
generates).

**Example — `scene_plan`'s render shape** (the exact JSON `TemplateRenderService::scenePlanSummary()`
produces, unchanged since it shipped — see "Legacy `script` / `scene_plan`" below for why it is no longer
REACHABLE via this endpoint today): a `topic` slot valued `"the sea"` and two scenes, the second carrying
an `ai_generate` image:

```json
{
  "data": { "parts": { "scene_plan": {
    "plan": { "scenes": [
      { "narration": "Open on the sea.", "image": null },
      { "narration": "Wide shot.", "image": {
        "base": { "kind": "ai_generate", "label": "AI image", "prompt": "A shot of the sea." },
        "filters": []
      } }
    ] }
  } } }
}
```

**No CURRENT content type declares a `scene_plan`-kind part** (`post` / `post_with_image` / `video_script`
all omit it — see "Legacy `script` / `scene_plan`" below), so `POST /generator/preview` can never actually
render this today: `TemplateRenderService::render()` iterates only `$definition->parts` from the LIVE
registry, and no definition includes `scene_plan`. The shape above documents the render CODE PATH (kind-
driven, D8 — reachable again the moment any content type recomposes to include a `scene_plan`-kind part),
not a currently-exercisable request. The one place `scene_plan` still renders TODAY is a pre-rework
generation SESSION replaying its own `recipe_snapshot` — see `docs/backend/generator-sessions-api.md`.

**Structured `slot_values` (verified against `tests/Feature/TemplatePreviewTest.php`).** An object slot's
sample value is a plain NESTED object keyed by its declared fields; a file slot's is a SNAPSHOT keyed by
its fixed composite. `slots.<name>.<sub>` resolves into either through the SAME whitelisted-path machinery
described in "Subfield references" below — no new engine, no special-casing in `TemplateRenderService`
itself (it forwards `slot_values` into the resolver's `slots` context root verbatim). A file slot's
snapshot may be given EITHER the wire-shaped composite (`{id,name,type,size,url}`) or a REAL file-answer
snapshot carrying `mime_type` instead of `type` (what Disk/Forms actually persist) — both resolve
identically through the resolver's pre-existing file-subfield collapse.

Every path is FAIL-SOFT to `""`/an empty plan — including a caught hard `assert_present` failure, which
anywhere else in this engine re-raises as a run step-failure, but here degrades to an empty render instead:
a preview is advisory, never a run. **Errors**: `401` unauthenticated. Never `422` — the whole point of a
draft-friendly endpoint is that a malformed/incomplete draft still returns a best-effort per-part render
rather than being rejected. `slots`/`slot_values`/`content` carry NO write validation here either
(`TemplatePreviewRequest` checks only the coarse shape) — a preview may reference an undeclared slot or an
unindexed subfield freely; both simply resolve empty.

**Hardening pin.** A resolved SLOT VALUE that literally contains reference/directive bytes (e.g.
`slot_values.note = '{{globals.secret}} and @[variable]("x")'`) is inserted VERBATIM, never re-scanned as a
second-order reference — the shared resolver's NUL-mask, inherited by the per-part render, is what makes
`slot_values` a safe user-controlled injection surface (`TemplatePreviewTest::
test_a_slot_value_containing_reference_bytes_renders_verbatim_no_second_order_injection()`).

---

## Capability flags

`TemplateResource` exposes the same server-authoritative capability-flag convention as
Workflows/Consts/Functions — the frontend must never invent authorization, only read these:

| Flag              | Source                                                                         |
|--------------------|------------------------------------------------------------------------------------|
| `is_owner`           | `isOwnedBy(auth user)` — a Template is always human-created, so this is always a plain creator match (the `HasCreator` system-record fallback never applies). |
| `can_be_edited`         | `TemplatePolicy::update` — creator only.                                    |
| `can_be_deleted`           | `TemplatePolicy::delete` — creator only (same rule as `can_be_edited`).    |

`TemplateResource` also carries `creator` — the polymorphic discriminated union (`user | null` in
practice for this model) — eager-loaded on every list/detail response. See
`docs/backend/creator-attribution.md` for the general shape.

---

## Slots — the template's declared typed variables

**Unchanged by this rework** — every rule and example in this section held before the content-recipe
model shipped and holds after it; only the WHERE a body's directives live changed (from `prompt_body` to
`content.<part>.markdown`, see "Content parts" below).

Unlike a workflow's `trigger.fields.*` (DERIVED from a form's JSON schema) or `globals.*` (a workspace
const's own stored descriptor), a template's `slots.<name>` variables are built straight from the
DECLARED slot list on the template/draft itself — `TemplateVariableCatalog::slotVariables()`. Each slot
produces exactly one catalog variable, in the identical shape a global produces
(`VariableCatalog`'s `globalVariable()` — see ADR-0030):

```json
{ "source": "slots", "path": "slots.<name>", "name": "<description or name>", "type": "<flat wire type>", "descriptor": { "...": "..." }, "enumOptions": ["...", "only when descriptor.base === 'enum'"] }
```

The SAME source feeds three consumers so they can never disagree:

- **the editor catalog** — `TemplateVariableCatalog::forSlots()`, what `POST /generator/catalog` returns;
- **the write-side reference index** — `TemplateVariableCatalog::referenceIndex()`, what
  `TemplateSlotValidator` checks a directive's pipeline against;
- **the runtime type map** — `TemplateVariableCatalog::typeMap()`, what `TemplateRenderService` hands the
  resolver so a directive/if-block pipeline executes against each reference's REAL (flat) type, never the
  degraded editor primitive riding the directive's own wire bytes.

### Object and file slots (structural, additive)

A slot's `descriptor` may declare a STRUCTURAL base exactly like an object const: `object` carries a nested
`fields` list; `file` is a fixed composite. Both are AUTHORABLE today — via the API directly, and via the FE
slot builder (see "FE authoring parity" below) — validated by
`ConstantTypeValidator::validateSlotDescriptorShape()` (see "Concepts" above).

**An `object` slot** declares its own leaves, exactly like an object const (verified against
`TemplateSlotValidationTest::objectSlot()`):

```json
{ "name": "product", "descriptor": {
    "base": "object", "nullable": false, "array": false,
    "fields": [
      { "key": "name", "label": "Name", "descriptor": { "base": "text", "nullable": false, "array": false } },
      { "key": "price", "label": "Price", "descriptor": { "base": "number", "nullable": false, "array": false } }
    ] } }
```

Once declared, its leaves are directly referenceable in a part's markdown:

```
Name: @[variable]("...slots.product.name...") Price: @[variable]("...slots.product.price...")
```

**A `file` slot** declares the FIXED composite `{id,name,type,size,url}` (`id`/`name`/`type`/`url` text,
`size` number — verified against `TemplateSlotValidationTest::fileDescriptor()`):

```json
{ "name": "image", "description": "Hero image", "descriptor": {
    "base": "file", "nullable": false, "array": false,
    "fields": [
      { "key": "id",   "label": "id",   "descriptor": { "base": "text",   "nullable": false, "array": false } },
      { "key": "name", "label": "name", "descriptor": { "base": "text",   "nullable": false, "array": false } },
      { "key": "type", "label": "type", "descriptor": { "base": "text",   "nullable": false, "array": false } },
      { "key": "size", "label": "size", "descriptor": { "base": "number", "nullable": false, "array": false } },
      { "key": "url",  "label": "url",  "descriptor": { "base": "text",   "nullable": false, "array": false } }
    ] } }
```

A `file` slot doubles as the ONLY thing an `image_plan`'s `from_slot` base may name — see "Image plan"
below.

The descriptor's `fields` list is validated for well-formedness only — it does NOT pin a `file` slot's
keys to exactly `id`/`name`/`type`/`size`/`url` at write time. In practice they always ARE that set (the FE
slot builder emits it programmatically for a `file` base — `templateSlots.ts`'s `fileDescriptorFields()`),
and the ACTUALLY-resolvable/referenceable subfield set is always the fixed five from
`VariableType::fileSubfieldTypes()`.

An `array:true` object/file slot ("a repeater-shaped slot") is itself a valid descriptor — the `array` flag
is independent of `base` — but its interior gets no subfield descent (the same per-element "R2 loop"
deferral a workflow repeater already carries). The FE slot builder does not offer this combination.

### Subfield references — `slots.<name>.<sub>` and `globals.<key>.<sub>`

A part's markdown may reference a SUBFIELD of a declared object/file slot, or of an object global, via the
same dotted-path convention a workflow uses over its own structural descriptors. The descent that makes a
subfield path KNOWN is `VariableCatalog::descriptorSubfieldTypeMap()` (`fileSubfieldTypeMap()` + the
recursive `objectSubfieldTypeMap()`), shared with the workflow catalog:

```
slots.product.name    → text    (an object slot's declared field)
slots.image.url        → text    (a file slot's fixed composite)
globals.company.city   → text    (an object GLOBAL's declared field)
```

**Write-time.** A SLOT subfield is symmetrically hard-validated with its parent —
`TemplateSlotValidator::validateBody()`/`validateDirective()` rejects BOTH an unknown `slots.<name>` AND an
unknown subfield of a KNOWN object/file slot, and a KNOWN subfield's own (non-empty) pipeline type-flows
from ITS base. **An OBJECT-GLOBAL subfield is deliberately NOT symmetric with a slot subfield** — an
unknown `globals.<key>.<sub>` is never hard-rejected at write (the `slots` surface is FULLY DECLARED by the
template itself, so an unknown slot/slot-subfield is always a genuine authoring mistake; a global's shape
lives on the `Constant` row, outside the template's own declaration).

**At runtime**, resolution goes through the SAME whitelisted-path machinery a workflow already uses —
FAIL-SOFT (an unresolvable subfield renders `''`) and INJECTION-SAFE (a subfield read rides the identical
NUL-mask a top-level resolved value does).

### The catalog wire contract for structural slots

An object/file SLOT — and an object GLOBAL — is emitted in `POST /generator/catalog`'s `variables[]` as
**ONE** entry carrying its full `descriptor.fields`; there is **NO** separate flat `slots.<name>.<field>`
entry on the wire:

```json
{ "source": "slots", "path": "slots.product", "name": "product", "type": "text",
  "descriptor": { "base": "object", "nullable": false, "array": false,
    "fields": [ { "key": "name", "label": "Name", "descriptor": { "base": "text", "nullable": false, "array": false } } ] } }
```

**Frontend expansion.** The client-side variable tree expands that ONE entry into pickable subfield nodes —
it never talks to the server again for this. `expandVariables()` in
`resources/js/next/pages/workflows/workflowVariables.ts` (imported verbatim by Generator's
`resources/js/next/pages/generator/templateCatalog.ts`, no forked copy) special-cases a `source:'slots'`
object exactly like an object GLOBAL: it is pushed AS ITSELF, `descriptor.fields` RETAINED, rather than
through `sectionContainerVariable()` (the treatment a form SECTION gets). This is PRESENTATION ONLY: the
emitted ref (`slots.product.name`, typed `text`) is byte-identical to what the SAME path would resolve to
on the wire either way.

### FE authoring parity

`TemplateSlotsPanel.vue`'s type Select offers all 7 backend-authorable bases
(`templateSlots.ts`'s `SLOT_BASES = ['text','number','boolean','date','enum','object','file']`): an
`object` slot gets a nested fields sub-editor (reusing the consts object type-builder verbatim); a `file`
slot shows an informational note only (its composite is backend-fixed). FE and backend are in PARITY for
every slot base except `time` (never authorable anywhere) and array-of-object / array-of-file (a
repeater-shaped slot), whose `array` toggle the builder disables for a structural base.

---

## Content parts — the per-part authored recipe (this rework's core change)

`Template::content` is a JSON object keyed by the SELECTED content type's declared part keys — never a
single string. Each key's value shape is decided entirely by that part's `PartKind` (D8, ADR-0032). The
write path is `TemplateContentValidator` (orchestrates per-part validation by kind, reusing
`TemplateSlotValidator::validateBody()` — now PART-AGNOSTIC via a `$promptKey` parameter — for every
`text_body`/`script`/scene-narration/image-prompt/shot_list-brief/storyboard-style body); the read path
(preview) is `TemplateRenderService::renderPart()`.

### Cross-part references — `parts.<key>` (video_script rework Phase A)

Any body-shaped content (a `text_body`/`script` markdown, a scene's narration, an image-plan prompt, a
`shot_list`'s brief, a `storyboard`'s style) may reference an EARLIER part's rendered output as
`parts.<key>` — a GENERAL engine primitive, not specific to `video_script`. It is validated and resolved
exactly like a `globals.<key>` reference (see `docs/backend/workflows-api.md` → "The `globals` root"): a
plain whitelisted dotted lookup over a stored, post-render STRING. See
`docs/decisions/ADR-0035-video-script-cross-part-storyboard.md` for the full design record and
`docs/backend/generator-sessions-api.md` → "Cross-part context" for how it resolves at RUN time (this
document covers only the WRITE-time gate — a Template's authoring never runs a real render).

**Write-time: earlier-only, acyclic, `422` on violation.** `TemplateContentValidator::validate()` iterates
the content type's parts IN DECLARED ORDER, accumulating a cumulative set of EARLIER part keys, and hands
that set to `TemplateSlotValidator::validateBody()` for each part's body — so a `parts.<key>` reference to a
part declared BEFORE the current one type-checks (as a `text` variable), while a FORWARD, SELF, or UNKNOWN
part reference is rejected under the SAME field the body's other directive errors land on:

| Code | Field | Meaning |
|---|---|---|
| 422 | `content.<key>.markdown` (or `.brief.markdown` / `.style.markdown` / a scene narration / an image prompt) | The body references `parts.<forwardOrSelfOrUnknownKey>` — "The prompt references an unknown or later part: parts.\<key\>." |

**The gate covers EVERY serialization the resolver actually resolves**, not just a top-level
`@[variable]` directive — `TemplateSlotValidator::validateCrossPartReferences()` walks the body through the
SHARED `VariableResolver::collectReferenceIds()` scanner, so a `parts.<key>` reference nested inside an
`@[ai-text]` prompt, inside an if-block CONDITION or BODY, or written as a transitional flat `{{parts.<key>}}`
token is caught exactly like a top-level directive. This was hardened across two review rounds specifically
because a flat-regex approach is BLIND to those nested forms — see ADR-0035 (D3) for the history. Verified
by `tests/Feature/CrossPartContextTest.php`'s directive / ai-text-nested / if-block-nested / flat-token
cases (both the accept and the reject side).

**The catalog only OFFERS earlier parts.** `POST /generator/catalog {slots, content_type, part_key}` (above)
returns `parts.<earlierKey>` variables scoped to `part_key`'s position — so the editor's picker/tree never
even shows a forward/self reference as a choice, though the write gate above is the actual enforcement.

### `text_body` / `script` — `{ "markdown": "<string>" }`

The post body / video scenario, authored AS the finished text: static text + `slots.<name>` / `globals.<key>`
/ `parts.<earlierKey>` references + pipelines + custom functions (`fn:<uuid>`) + fenced `if-block`
conditionals + first-class inline `@[ai-text]` AI blocks — byte-identical directive serialization to a
workflow text field, so nothing new was invented for the wire format. "The whole post by AI" is not a
separate mode — it is simply ONE big `@[ai-text]` block occupying the whole `markdown` string. A `script`
part validates and renders through the IDENTICAL path (`PartKind::isBody()`); it is a separate kind purely
so the editor labels/arranges it as a scenario rather than a post body. **`script` is currently
LEGACY** — see "Legacy `script` / `scene_plan`" below: no content type declares it today, so this shape is
reachable only through an existing pre-rework session's snapshot, not through this write/preview endpoint.

### `image_plan` — `{ "base": {...}|null, "filters": [...], "character"?: 'auto'|'never' }`

A DECLARED media plan for one image — the config of a `post_with_image`'s `image` part, and of a scene's
optional image (see "Scene plan" below). Mirrors the shape of a Variables pipeline: a `base` (where the
image STARTS) + an ordered `filters` CHAIN (how it is transformed). **This is authoring only — EXECUTION
(resolving the base, running the chain) is a generation Session** (`docs/backend/generator-sessions-api.md`,
R2 sub-stage 2c, shipped); the write path here only validates the SHAPE (fail-closed), and the preview only
produces a resolved-prompt PLAN SUMMARY (no image is ever produced by this API).

**`base`** — exactly one of:

| `base.kind`   | Shape                              | Meaning                                                                 |
|-----------------|---------------------------------------|-------------------------------------------------------------------------|
| `disk_file`       | `{ kind: 'disk_file', file: <id> }`      | A fixed Disk file. **BOUNDARY:** the id is an OPAQUE string here — TEMPLATE AUTHORING never looks it up, so writing/previewing a recipe stays Disk-decoupled. Its existence/ownership is validated only when a generation Session executes the plan (`docs/backend/generator-sessions-api.md` → "The image chain") — the module's one deliberate `Generator → Disk` edge, added in R2 sub-stage 2c. |
| `from_slot`           | `{ kind: 'from_slot', slot: <name> }`       | A DECLARED FILE-typed slot (descriptor `base:'file'`), filled per session. The name MUST match a declared file slot exactly. |
| `ai_generate`             | `{ kind: 'ai_generate', prompt: <markdown> }`   | A text→image prompt, directive-validated exactly like a body. **D6 — authored here; EXECUTED by a generation Session (R2 sub-stage 6, SHIPPED).** Accepted at write and rendered (resolved, label `"AI image"`) at preview; a Session resolves the prompt and generates the base image for real through the metered `ai_image_generate` seam (see `docs/backend/generator-sessions-api.md` → "The image chain"). The FE base picker offers it as a selectable, savable base. **At a real run** (never in this write/preview API), an `ai_generate` base — a plain `image_plan`'s, a scene's, or every storyboard shot's — is PREFIXED with the run's derived creative-direction ART-DIRECTION ANCHOR (visual style + the recurring subject + continuity notes) ahead of the authored prompt, when the run has one (the creative-direction layer, see `docs/backend/generator-sessions-api.md` → "Creative direction layer"); with no direction the composed prompt is byte-identical to the authored one. |

**`filters`** — an ordered list, each entry one of:

| `filter.kind`  | Shape                              | Meaning                                                                 |
|------------------|---------------------------------------|-------------------------------------------------------------------------|
| `pixel`            | `{ kind: 'pixel', op: <op>, params?: {...} }` | A deterministic pixel/geometry op — see the table below.        |
| `ai_edit`              | `{ kind: 'ai_edit', prompt: <markdown>, mask?: <id\|object> }` | A provider image-edit prompt (directive-validated like a body) + an OPTIONAL mask reference. Reuses `Disk\Services\ImageAiService::edit(image, prompt, mask?)`, called at EXECUTION by a generation Session (sub-stage 2c, now implemented); this PREVIEW endpoint never calls it. |

**`character`** (optional; the character visual-identity phase, `docs/decisions/
ADR-0042-character-visual-identity.md`) — whether a DELEGATED session's frozen creator may appear in THIS
image. Authoring-only, like the rest of this shape: nothing here decides whether the session actually HAS a
character, only whether this particular image is allowed to show one.

| `character` value | Meaning |
|---|---|
| absent, or `'auto'` (the default) | Draw the creator when the session has one. This is the default because handing a whole session to a persona implies its face appears — it does not need opting into. |
| `'never'` | This image never shows the creator, whoever the session belongs to — the product shot, the logo, the chart: cases where a person would be both wrong and billed. |

There is deliberately no `'always'` — `'auto'` already means "yes, when the session has one," and forcing a
character onto a session with none would be a promise the write layer cannot keep. An unrecognized value is
REFUSED at write (`422` under `content.<key>.character`), never silently defaulted
(`ImagePlanValidator::CHARACTER_MODES`). This field has NO bearing on a `storyboard` shot — a storyboard's
per-shot character decision is made by the MODEL at run time (`features_character`, one per shot list entry),
never authored; see `docs/backend/generator-sessions-api.md` → "Frozen character visual identity" for how
both mechanisms are consumed.

**Pixel ops** (`ImagePlanValidator::PIXEL_OPS` — mirrors the `imageOps.ts` op set the Disk image editor
also uses; this rework REUSES the pure functions, not the Disk editor itself — the filter chain is a NEW
ordered pipeline, the Disk editor is single-filter):

| `op`             | `params`                                    | Range / notes                                    |
|--------------------|--------------------------------------------------|---------------------------------------------------|
| `grayscale`, `sepia`, `invert`, `warm`, `cool` | none (ignored if sent) | Deterministic color casts.               |
| `brightness`, `contrast`, `saturation` | `{ amount: <number> }`       | `amount` in **-100..100**.                     |
| `crop`               | `{ rect: {x, y, w, h} }`                       | Each of `x`/`y`/`w`/`h` a number in **0..1** (canvas fractions). |
| `rotate`                | `{ quarterTurns: <int> }`                         | **1..3** (90° / 180° / 270°).                |
| `flip`                     | `{ axis: 'horizontal'\|'vertical' }`                  | —                                            |

**Example — a full, valid `post_with_image` recipe** (verified against
`TemplateContentValidationTest::test_image_plan_full_chain_with_a_valid_slot_ref_is_accepted()` and
`TemplateFactory::postWithImage()`):

```json
{
  "name": "Launch with hero",
  "content_type": "post_with_image",
  "slots": [
    { "name": "topic", "description": "The subject", "descriptor": { "base": "text", "nullable": false, "array": false } },
    { "name": "photo", "description": "Hero image", "descriptor": { "base": "file", "nullable": false, "array": false, "fields": [ "…the fixed 5…" ] } }
  ],
  "content": {
    "body": { "markdown": "Write about @[variable](\"...slots.topic...\")." },
    "image": {
      "base": { "kind": "from_slot", "slot": "photo" },
      "filters": [
        { "kind": "pixel", "op": "grayscale" },
        { "kind": "pixel", "op": "brightness", "params": { "amount": 20 } },
        { "kind": "ai_edit", "prompt": "In the style of @[variable](\"...slots.topic...\")." }
      ]
    }
  }
}
```

### `scene_plan` — `{ "scenes": [{ "narration": {"markdown": "…"}, "image_plan"?: {...} }] }` *(legacy — see below)*

An ORDERED list of scenes (D4). Each scene is a NARRATION (a `text_body`-like markdown body) + an OPTIONAL
nested `image_plan` (the identical shape a top-level `image_plan` part uses); an empty `scenes: []` was
always a valid, accepted value. **This shape and its write validation are UNCHANGED since it shipped**, but
it is currently **LEGACY** — see "Legacy `script` / `scene_plan`" below: no content type declares a
`scene_plan`-kind part today (the video_script rework Phase B dropped it from `video_script`'s composition),
so `POST`/`PUT /generator/templates` rejects `content.scene_plan` as an unknown part for ANY content type,
and `POST /generator/preview` never renders it either. The JSON below still documents the exact shape the
KIND renders (kind-driven, D8 — reachable again the instant any content type recomposes to include it), and
it is what an EXISTING pre-rework session's snapshot still executes at the session layer (`docs/backend/
generator-sessions-api.md`).

**Example — a well-formed scene plan** (the shape `TemplateContentValidator::validateScenePlan()` still
accepts for any content type that declares a `scene_plan`-kind part):

```json
{
  "script": { "markdown": "About @[variable](\"...slots.topic...\")." },
  "scene_plan": {
    "scenes": [
      { "narration": { "markdown": "Open on @[variable](\"...slots.topic...\")." } },
      {
        "narration": { "markdown": "Wide shot." },
        "image_plan": {
          "base": { "kind": "ai_generate", "prompt": "A wide shot of @[variable](\"...slots.topic...\")." },
          "filters": [ { "kind": "pixel", "op": "sepia" } ]
        }
      }
    ]
  }
}
```

---

## `shot_list` & `storyboard` — the video_script rework (Phase B)

`video_script` composes `[shot_list, storyboard]` (see the rework note at the top of this document).
Full session-side execution — the structured JSON contract + parse fallback, the storyboard's per-shot
iteration + `storyboard.<i>` addressing, the adaptive shot-count/story narrative contract, and the
once-per-run creative-direction layer that every part of a run (including a storyboard shot's `ai_generate`
base) is made to — is documented in `docs/backend/generator-sessions-api.md` and
`docs/decisions/ADR-0038-creative-direction-layer.md`; this section covers only the AUTHORED shapes + write
validation these two TEMPLATE endpoints see.

### `shot_list` — `{ "brief": { "markdown": "<string>" } }`

The `video_script`'s creative BRIEF — NOT the finished script. It is authored exactly like a `text_body`
(static text + slot values + `parts.<earlierKey>` references + pipelines + if-blocks +
`@[ai-text]`), but its role is different: at a real session run it feeds ONE structured AI call that
returns a coherent hook / ordered timed shots / cta (see `docs/backend/generator-sessions-api.md` → "shot_list
— the structured JSON contract"). Validated by `TemplateContentValidator::validateShotList()`, which reuses
`TemplateSlotValidator::validateBody()` under `content.shot_list.brief.markdown` — the identical body
authority every other body-shaped field uses; a non-object part or a non-string `brief.markdown` is a `422`
under `content.shot_list` / `content.shot_list.brief.markdown` respectively.

```json
{
  "shot_list": { "brief": { "markdown": "A 20-second hook about @[variable](\"...slots.topic...\") for a desk-organization product." } }
}
```

### `storyboard` — `{ "style"?: { "markdown": "<string>" }, "filters"?: [...], "max_shots"?: int }`

An OPTIONAL declared look for the `shot_list`'s images — there is **no `base`** field (unlike `image_plan`):
a session ALWAYS auto-generates each shot's base image from the shot's own on-screen `visual` (see
`docs/backend/generator-sessions-api.md`). `style` is an OPTIONAL resolvable body prepended to that
auto-generated prompt (e.g. "in a bright, minimal, pastel style"); `filters` is the SAME ordered
pixel/`ai_edit` chain shape `image_plan` uses (§ "Image plan" above), applied to EVERY shot's image after it
is generated. `max_shots` (added alongside the creative-direction layer) is an OPTIONAL whole number that
TIGHTENS the platform ceiling (`generator.storyboard_max_shots`, default 8) for THIS recipe — a session's
EFFECTIVE shot cap is `min(authored max_shots, ceiling)`, and that one value bounds BOTH the shot list the
model writes and the storyboard's per-shot image fan-out (see `docs/backend/generator-sessions-api.md` →
"Storyboard shot ceiling"). All three fields are optional — a bare `storyboard: {}` (or omitting the part
entirely, since it is OPTIONAL) is valid, and an absent `max_shots` simply means the ceiling applies.
Validated by `TemplateContentValidator::validateStoryboard()`: `style.markdown` (when present) reuses the
same body authority under `content.storyboard.style.markdown`; `filters` (when present) reuses
`ImagePlanValidator::validateFilters()` under `content.storyboard.filters` — the identical filter-kind /
pixel-op / `ai_edit`-prompt checks "Image plan" describes above; `max_shots` (when present) is validated by
`validateMaxShots()` — MUST be a whole number between 1 and the platform ceiling, `422` otherwise (an author
may only ever TIGHTEN the ceiling, never raise it — a stale over-ceiling value could never take effect at
run time anyway, so it is rejected here instead of silently truncated later).

```json
{
  "storyboard": {
    "style": { "markdown": "Bright, minimal, pastel colors." },
    "filters": [ { "kind": "pixel", "op": "saturation", "params": { "amount": 15 } } ],
    "max_shots": 4
  }
}
```

### Legacy `script` / `scene_plan` — unchanged shapes, no longer authorable

`script` (`{markdown}`) and `scene_plan` (`{scenes:[…]}`) keep the EXACT shapes documented above ("`text_body`
/ `script`" and "`scene_plan`" sections) — nothing about their validation/render changed. What changed is
availability: `ContentTypeRegistry::all()` no longer lists them under `video_script`, so
`POST`/`PUT /generator/templates` rejects them as an unknown part (`content.script` / `content.scene_plan` →
"Unknown content part") for a NEW or edited template. An existing session's `recipe_snapshot` — captured
before this rework — still carries them and still renders/refines identically
(`ContentTypeRegistry::partsForSnapshot()`, snapshot-authoritative); see
`docs/backend/generator-sessions-api.md`.

---

## The faithful, per-part preview

`TemplateRenderService::render()` is the server-side counterpart of a workflow step runner's config
resolution — for the selected content type it renders EVERY declared part BY KIND, so an author sees the
finished post exactly as a real generation would shape it (minus the real AI/image execution, which lands
in a later sub-stage):

```php
$context = [
    'slots' => $slotValues,                     // the caller's sample values, {<name>: <value>}
    'globals' => $this->catalog->globalValues(), // the workspace consts, {<key>: <value>}
];
// custom functions ride the context under FunctionScope, exactly like a workflow run
$execContext = FunctionScope::forFunctions($functions)->writeInto($context);

foreach ($definition->parts as $part) {
    $parts[$part->key] = $this->renderPart($part, $content[$part->key] ?? null, $execContext, $typeMap);
}
```

- **`text_body` / `script`** → `resolveString()` — the SAME method, executor, directive grammar, if-block
  grammar, and NUL-mask injection guard a real workflow run uses → `{rendered: "<string>"}`.
- **`image_plan`** → a PLAN SUMMARY: the base + the ordered filter chain, with every prompt string
  directive-resolved through the SAME resolver → `{plan: {base, filters}}`. **No image is executed.**
- **`scene_plan`** → a per-scene summary: each scene's resolved narration + its (optional) resolved image
  plan → `{plan: {scenes: [...]}}`.
- **`shot_list`** *(video_script rework)* → the resolved creative BRIEF only, through the SAME resolver →
  `{brief: "<string>"}`. **No structured AI call runs**; the hook/shots/cta only exist after a real
  generation Session run.
- **`storyboard`** *(video_script rework)* → a PLAN SUMMARY of the resolved style + the authored filter
  chain → `{plan: {style, filters}}`. **No base, no image, no shot enumeration** — a storyboard has no
  authored base (a session auto-generates one per shot) and its shots come from the sibling `shot_list`'s
  real run output, which a preview never produces.

Because it is the identical resolver, executor, directive grammar, if-block grammar, and NUL-mask
injection guard a real workflow run uses, a preview cannot drift from what a real render would produce —
there is no second, frontend-side interpolation implementation to keep in sync.

**`@[ai-text]` is INERT-but-LABELED in this sub-stage.** `GeneratorModuleServiceProvider` binds the
resolver `TemplateRenderService` receives with a `NoOpAiTextGenerator` — a CONTEXTUAL binding
(`$this->app->when(TemplateRenderService::class)->needs(VariableResolver::class)->give(...)`), not an
app-wide override, so nothing about Workflows' own ai-text behavior changes. Unlike the ORIGINAL
sub-stage-1 behavior (which returned `''`, fully consuming the block), `NoOpAiTextGenerator::generate()`
now emits a LABELED `[AI: <resolved prompt>]` PLACEHOLDER — never empty for a non-blank prompt — so an
author previewing a recipe SEES exactly where an `@[ai-text]` block lands and against WHICH resolved
prompt. A genuinely blank prompt still returns `''`. No real AI call runs; this is purely a preview
affordance.

**The RESOLVED voice is a SESSION-run concept; the AUTHOR CHOICE is authored in the template (R2, ADR-0040).**
An `@[ai-text]` block's `authorId` (which bot should write it — the picker that replaced the legacy persona
Select, see "AI-text personas" above) IS part of the template's authored `content`, exactly like `personaId`
always was. What is NOT authored at the template level is the actual opaque VOICE STRING that id resolves
to — that only exists once a real Session RUNS it: `GenerationSessionService::create()` resolves every
authored `authorId` into `recipe_snapshot.author_voices`, frozen at session creation (see "Per-block AI-text
authors" in `docs/backend/generator-sessions-api.md`). Independently, when a WHOLE session is delegated to a
bot (R2 sub-stage 3, "Boty w generatorze"), every `@[ai-text]` call/`shot_list` call with no MORE SPECIFIC
block-level author renders in the delegated bot's snapshotted, opaque voice directive instead of the resolved
`AiPersona` — see "Bot-author delegation overlay" and "Per-block AI-text authors" in
`docs/backend/generator-sessions-api.md`, `docs/decisions/ADR-0036-bot-delegation-generation-sessions.md`,
and `docs/decisions/ADR-0040-per-block-ai-text-author.md`. The `storyboard` IMAGE prompt is unaffected by
either — it stays the authored `style` only.

**DECLARE vs EXECUTE — the boundary the TEMPLATE endpoints sit behind.** Authoring a Template — its content
type, its slots, its per-part content including an image plan's base + filter chain — is a pure DECLARATION
that is write-validated fail-closed (so a session can never start from an un-runnable recipe) and preview
that is fail-soft (so a draft always renders something, best-effort). **Nothing in the TEMPLATE API EXECUTES
anything**: no real AI-text call runs (`@[ai-text]` is inert-but-labeled), no image is fetched/generated/
edited (an `image_plan` HERE only ever produces a resolved-text PLAN SUMMARY), and no Disk file is looked up.
EXECUTION — actually running a template against a real, per-session set of slot values, resolving an image
plan's base (a Disk file lookup — the deliberate, boundary-test-allowed Generator → Disk edge), running its
filter chain for real, and saving the result — is a **generation Session** (R2 sub-stage 2; SHIPPED — full
endpoint/wire contract in `docs/backend/generator-sessions-api.md`, design record in
`docs/decisions/ADR-0034-generation-sessions.md`). `ai_generate`'s prompt is authored/previewed here and
RUNNABLE by a Session since R2 sub-stage 6 — a run resolves it and generates the base image for real through
the metered `ai_image_generate` seam.

---

## Tenancy

`Template` uses the SAME dual-schema pattern as `Constant`/`CustomFunction`:

- **Shared mode** — the central `templates` table carries a nullable, indexed `workspace_id`; every query
  is isolated by `WorkspaceScope`.
- **Own mode** — `database/migrations/tenant/0001_01_01_000049_create_templates_table.php` creates the
  identical schema MINUS `workspace_id` (the whole tenant database is one workspace); `TenantAware` routes
  the model to the tenant connection. `creator_id` references the CENTRAL `users` table with no FK (no
  cross-database constraint) — same as every other tenant-aware, creator-stamped model.

Both migrations carry `name, description, content_type, slots (json), content (json), creator_id,
creator_type, timestamps` — reshaped IN PLACE from the original `type`/`prompt_body`/`parameters` columns
(D2, ADR-0032; the branch carried zero committed rows, so no data migration was needed).

Proven by `tests/Feature/TemplateOwnDatabaseTest.php`: a template created while an own-database workspace
is active lands in the tenant connection's `templates` table and never in the central one.

---

## Boundary

`App\Modules\Generator` imports NOTHING from `App\Modules\Workflows` (unchanged) — the pinned peer boundary.
R2 sub-stage 2c ADDS a deliberate, documented Generator → Disk EXECUTION edge (Fork 3): a generation Session
resolves an `image_plan` base from a Disk `File`, runs `ai_edit` through Disk's `ImageAiService`, and saves a
produced image through Disk's `FileService`. So Disk is now an ALLOWED dependency of the image services;
Workflows remains forbidden. A `disk_file` base id is still stored OPAQUE at WRITE time (never validated
against Disk when authoring — see "Image plan" above); it is looked up, and its existence/ownership enforced
(workspace-scoped, so a cross-tenant read is impossible), only at EXECUTION.

Pinned by `tests/Feature/GeneratorModuleBoundaryTest.php`:
`test_generator_module_imports_nothing_from_workflows` scans every `.php` file under `app/modules/Generator`
for the literal string `App\Modules\Workflows`; `test_generator_services_depend_on_variables_not_workflows`
positively asserts `TemplateVariableCatalog` / `TemplateRenderService` DO name `App\Modules\Variables`; and
`test_generator_image_services_may_depend_on_disk_but_never_workflows` positively asserts the image services
(`ImageBaseResolver`, `ImageChainExecutor`) DO name `App\Modules\Disk` (the allowed edge) and still name no
Workflows class. The FE's image-plan builder also reaches into Disk (`DiskFilePickerModal.vue`, so a user can
pick a file by name).

---

## Related files

- `app/modules/Generator/` — module root
- `app/modules/Generator/GeneratorModuleServiceProvider.php` — routes + `TemplatePolicy` gate + the
  contextual `NoOpAiTextGenerator` binding for `TemplateRenderService`; registered AFTER
  `VariablesModuleServiceProvider`, BEFORE `WorkflowsModuleServiceProvider`, in `bootstrap/providers.php`
- `app/modules/Generator/Models/Template.php` — the model (`TenantAware`/`HasCreator`/`HasUuids`;
  `definition()`/`partContent()` helpers)
- `app/modules/Generator/Enums/PartKind.php` — the CLOSED kind vocabulary (`text_body`, `image_plan`,
  `shot_list`, `storyboard`, plus the LEGACY `script`/`scene_plan`, D8)
- `app/modules/Generator/Support/ContentTypeDefinition.php`, `Support/ContentTypePart.php` — the
  content-type/part VOs (D1)
- `app/modules/Generator/Services/ContentTypeRegistry.php` — the code-defined registry of the 3 system
  content types; `partKeysBefore()` (cross-part earlier-only scope), `legacyParts()`/`partsForSnapshot()`
  (snapshot-authoritative back-compat for the dropped `script`/`scene_plan` video_script composition —
  video_script rework Phase B, ADR-0035)
- `app/modules/Generator/DTOs/TemplateDTO.php`
- `app/modules/Generator/Services/TemplateService.php` — CRUD + `search(['name'])` + `cursorPaginate`
- `app/modules/Generator/Services/TemplateSlotValidator.php` — slots + the PART-AGNOSTIC body-directive
  authority (`validateBody($validator, $markdown, $slots, $declaredNames, $promptKey, $earlierPartKeys)`);
  `validateCrossPartReferences()` — the earlier-only `parts.*` write gate, shared-scanner-driven (Phase A,
  ADR-0035)
- `app/modules/Generator/Services/TemplateContentValidator.php` — the per-part write authority, dispatches
  by `PartKind` to `TemplateSlotValidator::validateBody()` / `ImagePlanValidator` / its own scene-plan check
  / `validateShotList()` / `validateStoryboard()` (incl. `validateMaxShots()`, the optional
  `content.storyboard.max_shots` ceiling-tightening check, added with the creative-direction layer) — and
  threads the cumulative `$earlierPartKeys` set part by part (Phase A)
- `app/modules/Generator/Services/ImagePlanValidator.php` — an image plan's base kind + filter chain +
  pixel-op params + prompt directives
- `app/modules/Generator/Services/TemplateVariableCatalog.php` — `slots.<name>` variables layered over the
  shared `VariableCatalog`; feeds `forSlots()`/`referenceIndex()`/`typeMap()`; `partVariables()` — the
  `parts.<earlierKey>` catalog entries (Phase A, ADR-0035)
- `app/modules/Generator/Services/TemplateRenderService.php` — the faithful, PER-PART preview renderer
  (does NOT accumulate a `parts` context across parts — see "Content parts" → cross-part references above)
- `app/modules/Generator/Support/NoOpAiTextGenerator.php` — the inert-but-labeled `AiTextGenerator` the
  preview binds (`[AI: <resolved prompt>]`)
- `app/modules/Generator/Http/Controllers/ContentTypeController.php`, `TemplateController.php`,
  `TemplateCatalogController.php`, `TemplatePreviewController.php`
- `app/modules/Generator/Http/Requests/StoreTemplateRequest.php`, `UpdateTemplateRequest.php`,
  `TemplateCatalogRequest.php`, `TemplatePreviewRequest.php`
- `app/modules/Generator/Http/Resources/ContentTypeResource.php`, `TemplateResource.php`
- `app/modules/Generator/Policies/TemplatePolicy.php`
- `app/modules/Generator/routes/api.php` — `GET generator/content-types`; `POST generator/catalog`/
  `generator/preview` declared BEFORE the `generator/templates` resource
- `database/factories/TemplateFactory.php` — `directive()`, `fileDescriptor()`, `postWithImage()` state
  helpers. **Stale, unused:** `videoScript()` still builds the PRE-REWORK `script`/`scene_plan` content — no
  current test calls it (the video_script tests build their own `shot_list`/`storyboard` payloads inline —
  see `tests/Feature/TemplateContentValidationTest.php`'s private `videoScript()` helper); flagged as an
  open documentation/cleanup gap below rather than silently fixed by this pass.
- `database/migrations/2026_07_28_000000_create_templates_table.php`,
  `database/migrations/tenant/0001_01_01_000049_create_templates_table.php` — reshaped in place (D2)
- `app/modules/Variables/Services/VariableResolver.php` — the shared interpolation engine Templates
  render through (relocated from Workflows — ADR-0030)
- `app/modules/Variables/Services/VariableCatalog.php` — the shared, form-independent catalog composition
  Templates layer their `slots.<name>` variables over (extracted from `WorkflowVariableCatalogService` —
  ADR-0030)
- `app/modules/Variables/Contracts/AiTextGenerator.php` — the ai-text seam; `WorkflowAiTextService`
  implements it for a real workflow run, `Generator\Services\GeneratorAiTextService` for a real generation
  Session run (both budgeted decorators over the shared `AiTextGenerationService`), `NoOpAiTextGenerator`
  for a TEMPLATE preview only (ADR-0030, ADR-0033)
- `app/modules/Variables/Contracts/MeteredAiCall.php` — the D7 shared "metered AI call" seam (ADR-0032):
  bound to the REAL ledger meter, `Support/LedgerMeteredAiCall` (gate-before-spend on a workspace's
  calendar-month token cap + usage recording), in `VariablesModuleServiceProvider` since R2 sub-stage 2a —
  see `docs/decisions/ADR-0033-ai-cost-meter.md`. `Support/PassthroughMeteredAiCall.php` (the original
  no-op) still exists for tests that want no metering.
- `app/modules/Variables/Services/ConstantTypeValidator.php` — `validateSlotDescriptorShape()` (widens
  `validateDescriptorShape()`'s authorable bases with `file`, a slot-only composite), used by
  `TemplateSlotValidator` for a slot's descriptor
- `app/modules/Variables/Services/PipelineValidator.php` — reused verbatim by `TemplateSlotValidator` for
  a directive's pipeline type-flow
- `app/modules/Disk/Services/ImageAiService.php` — the `edit(image, prompt, mask?)` a generation Session's
  image chain calls for every `ai_edit` step, since R2 sub-stage 2c
  (`docs/backend/generator-sessions-api.md` → "The image chain"); the TEMPLATE endpoints on this page never
  call it
- `docs/backend/generator-sessions-api.md` — the Session (execution) endpoints: `generate`, the per-part
  `regenerate`/`refine`/`undo` refine loop, the produced-image serve + save-to-Disk, archive/unarchive, the
  cost-meter integration, and the lifecycle reaper
- `tests/Feature/GeneratorModuleBoundaryTest.php` — the one-way `Generator → Variables` (never Workflows)
  dependency pin
- `tests/Feature/ContentTypeApiTest.php` — `GET /generator/content-types`: the 3 system definitions +
  their exact parts/kinds + the workspace-member read gate
- `tests/Feature/TemplateCrudTest.php` — happy paths, resource wire shape (content_type + content, no
  legacy fields), workspace-scoped authorization, creator-only mutation
- `tests/Feature/TemplateOwnDatabaseTest.php` — own-database (tenant) persistence
- `tests/Feature/TemplateSlotValidationTest.php` — slot descriptor/identity write validation (unchanged by
  this rework)
- `tests/Feature/TemplateContentValidationTest.php` — the per-part content write validation: unknown/
  missing parts, image-plan base/chain/pixel-params/prompt-directive checks, scene-plan checks
- `tests/Feature/TemplateCatalogTest.php` — `POST /generator/catalog`: slot variables + globals + functions
  + types, workspace scoping, draft-friendly fail-soft-on-malformed-slot
- `tests/Feature/TemplatePreviewTest.php` — `POST /generator/preview`: per-part rendering (text/image-plan/
  scene-plan), ai-text inert-but-labeled, unknown-root non-reference, workspace globals, missing-sample
  fail-soft, the second-order-injection hardening pin
- `tests/Feature/CrossPartContextTest.php` — the video_script rework Phase A: earlier-only/acyclic write
  validation (directive/ai-text-nested/if-block-nested/flat-token cases, both accept + reject), the catalog's
  earlier-only offering, a real run's cross-part resolution, and the staleness marking on refine/regenerate
- `resources/js/next/pages/generator/__tests__/TemplateShotListPart.spec.ts`,
  `TemplateStoryboardPart.spec.ts` — the shot_list/storyboard authoring UI specs (video_script rework
  Phase B)
- `tests/Unit/Variables/VariableResolverCharacterizationTest.php` — the ADR-0030 down-move's
  byte-identical-resolution pin (see that ADR)
- `resources/js/next/pages/generator/GeneratorModuleLayout.vue` — the module nav shell
- `resources/js/next/pages/generator/TemplatesView.vue` — the list (FilterBar + Saved Views + search +
  infinite scroll)
- `resources/js/next/pages/generator/TemplateEditorDrawer.vue` — the create/edit drawer: STEP 1
  `ContentTypePicker.vue` (create only), identity, `TemplateSlotsPanel.vue`, then a data-driven section
  PER PART rendered by kind
- `resources/js/next/pages/generator/TemplateBodyPart.vue` — the `text_body`/`script` editor (the shared
  `MarkdownEditor` + the promoted first-class `@[ai-text]` insert affordances)
- `resources/js/next/pages/generator/TemplateImagePlanPart.vue` — the `image_plan` authoring UI (base
  picker + ordered filter-chain builder)
- `resources/js/next/pages/generator/TemplateScenePlanPart.vue` — the LEGACY `scene_plan` authoring UI
  (reuses `TemplateBodyPart`/`TemplateImagePlanPart` per scene) — no longer reachable for a new `video_script`
  template (`ContentTypePicker.vue` only ever offers the current registry shape), kept only for editing a
  pre-rework template that still carries `script`/`scene_plan` content
- `resources/js/next/pages/generator/TemplateShotListPart.vue` — the `shot_list` authoring UI: the creative
  `{brief}` body editor (reuses `TemplateBodyPart.vue`'s markdown editor) (video_script rework Phase B)
- `resources/js/next/pages/generator/TemplateStoryboardPart.vue` — the `storyboard` authoring UI: an
  optional `{style}` body editor + the shared filter-chain builder, no base picker (there is no authored
  base) (video_script rework Phase B)
- `resources/js/next/pages/generator/TemplateFilterChain.vue` — the ordered pixel/`ai_edit` filter-chain
  builder, extracted so `TemplateImagePlanPart.vue` and `TemplateStoryboardPart.vue` share ONE
  implementation rather than forking it
- `resources/js/next/pages/generator/TemplatePreview.vue`, `TemplatePartPreview.vue`,
  `ImagePlanPreviewCard.vue` — the live, debounced, PER-PART preview pane calling
  `POST /generator/preview`
- `resources/js/next/pages/generator/imagePlan.ts` — the pure image-plan model/factories/client
  completeness gate (mirrors `ImagePlanValidator`; the server stays authoritative) + an optional,
  off-by-default client dry-run of the deterministic pixel ops (reuses `disk/imageOps.ts`, never forks it)
- `resources/js/next/pages/generator/templateContent.ts` — the pure per-part content model: seeding,
  activating/deactivating optional parts, the write-content builder, the completeness gate
- `resources/js/next/pages/generator/templateMeta.ts` — content-type + part-kind → icon/label helpers
- `resources/js/next/pages/generator/types.ts` — the wire contract, mirrored 1:1 (content types, parts,
  per-part content shapes, the per-part preview result)
- `resources/js/next/pages/generator/templateSlots.ts` — the slots builder core (unchanged by this rework)
- `resources/js/next/pages/generator/templateCatalog.ts` — catalog → shared-editor adapter; imports
  `resources/js/next/pages/workflows/workflowVariables.ts` VERBATIM (no forked copy)
- `resources/js/next/app/stores/templates.ts` — the CRUD + `fetchContentTypes` + `fetchCatalog` +
  `preview` store
- `docs/decisions/ADR-0032-generator-content-recipe.md` — the design record this document's wire contract
  implements (this rework)
- `docs/decisions/ADR-0035-video-script-cross-part-storyboard.md` — the video_script rework design record:
  cross-part `parts.*` context (Phase A, a general engine primitive) + structured `shot_list`/`storyboard`
  (Phase B)
- `docs/decisions/ADR-0038-creative-direction-layer.md` — the content-quality rework design record: the
  adaptive shot-count/degrading-story narrative contract (B1) + the once-per-run creative direction every
  generation in a session is made to (B2), including the `content.storyboard.max_shots` authoring knob this
  page documents
- `docs/decisions/ADR-0031-generator-module-templates.md` — the SUPERSEDED "prompt + parameters" record
- `docs/decisions/ADR-0030-variable-engine-down-move.md` — the shared-engine relocation this module
  consumes
- `docs/decisions/ADR-0027-variables-module-extraction.md`, `ADR-0028-consts-rename.md`,
  `ADR-0029-custom-functions.md` — the boundary/CRUD/type-system precedents this module mirrors
- `docs/product/plan-dzialania.md` — R2 roadmap (sub-stage sequencing)

## Planned / deferred (not implemented)

- ~~Editable/undoable session history~~ — **BUILT (R2 sub-stage 2, all of 2a–2d), no longer deferred.** A
  generation Session EXECUTES a template against a real, per-session set of slot values — the `generate`
  action + live `@[ai-text]` (2b) and the image chain (2c, `disk_file`/`from_slot` base → real filter chain
  → `ImageAiService::edit()` for an `ai_edit` → stored result). The per-part refine loop: per-part
  **regenerate** (a fresh snapshot variation) and instructed **refine** (a revision of the current output —
  text via an AI text revision, image via an AI edit) ride the same async claim/job, each bumps a per-part
  `version` and pushes the prior onto a bounded per-part **history** stack, and a synchronous **undo**
  restores the previous version (produced images are stored versioned). Full Session
  generate/refine/serve/save-to-disk/archive endpoint docs live in `docs/backend/generator-sessions-api.md`;
  the endpoints documented ABOVE on this page are the Template (definition) endpoints only.
- **`disk_file` existence/ownership validation at WRITE time** (by design): a template may reference a Disk
  file id that does not exist or belongs to another workspace; the write path accepts any non-empty string
  (deliberate write-time Disk-decoupling, see "Boundary" above). The error is surfaced only at EXECUTION — a
  generation Session's base resolve is workspace-scoped and fail-soft (2c), so a missing/foreign id fails
  that image part rather than the write.
- ~~`ai_generate` execution~~ — **BUILT (R2 sub-stage 6).** The text→image base is now RUNNABLE by a
  generation Session: a run resolves its prompt and generates the base image for real through the metered
  `ai_image_generate` seam (`Disk\Services\ImageGenerateService`, provider config `ai.image_generate_provider`,
  per-run budget `generator.image_generate_max_calls_per_session`) — see
  `docs/backend/generator-sessions-api.md` → "The image chain". The base kind stays write-accepted +
  preview-resolved (label `"AI image"`); the FE picker now offers it as a selectable, savable base.
- ~~AI-cost metering~~ — **BUILT (R2 sub-stage 2a).** The D7 `MeteredAiCall` seam is bound to a real
  ledger meter (`LedgerMeteredAiCall`): gate-before-spend on a workspace's calendar-month token cap +
  usage recording, consumed by both a real Session run and (rewired, behavior-preserved) the pre-existing
  Workflows/Disk spenders. See `docs/decisions/ADR-0033-ai-cost-meter.md` and
  `docs/backend/generator-sessions-api.md` → "Cost meter integration". A richer per-workspace usage/limit
  UI is still open — see that document's own "Planned / deferred".
- ~~Disk-save integration~~ — **BUILT (R2 sub-stage 2c).** A generation Session's produced image can be
  promoted onto the user's Disk (`POST /generator/sessions/{id}/parts/{partKey}/save-to-disk`) through
  `Disk\Services\FileService::storeDiskContent()` — see `docs/backend/generator-sessions-api.md`.
- ~~Bot-authored generation~~ ("Boty w generatorze") — **BUILT (R2 sub-stage 3).** A human may delegate an
  editable session to a workspace bot: it autonomously fills in-scope slots and becomes the content's
  AUTHOR (every subsequently rendered text part reads in its voice), while the human keeps full ownership —
  see `docs/backend/generator-sessions-api.md` → "Bot-author delegation overlay" and
  `docs/decisions/ADR-0036-bot-delegation-generation-sessions.md`.
- ~~`generate_content` workflow step~~ (sub-stage 5) — **BUILT.** The step that lets a `Workflow` consume a
  Template, unblocked (cycle-free) specifically because of the ADR-0030 down-move (Generator depends on
  Variables only, so `Workflows → Generator` creates no cycle) — see `docs/backend/workflows-api.md` →
  "Steps: `generate_content`" / "Suspend/resume engine", `docs/backend/generator-sessions-api.md` →
  "Automation seam (R2 sub-stage 5)", and
  `docs/decisions/ADR-0039-workflow-suspend-resume-and-generate-content.md`. It runs against the SNAPSHOTTED
  template exactly like an interactive session — see the "delete-while-referenced" bullet below, which this
  answers rather than reopens.
- **A user-created content type** (planned, no ETA): `ContentTypeRegistry` is code-defined-only (D1); a
  future table + type-authoring UI would UNION its rows into the registry with zero rework to the
  validators/renderer/editor, because every one of them switches on a part's `kind`, never the type's `id`
  (D8).
- **A genuinely new `PartKind`** (e.g. an audio plan): the kind vocabulary is CLOSED for v1 (D8) — adding
  one is a deliberate, separate future change touching the validator/renderer/editor dispatch, not
  something a content-type author can request from the UI.
- **Array-of-object / array-of-file ("repeater-shaped") slots**: an `object`/`file` descriptor with
  `array:true` is a structurally valid slot, but its interior gets no subfield descent — the same
  per-element "R2 loop" deferral a workflow repeater already carries. The FE slot builder does not offer
  this combination.
- **No delete-while-referenced guard on `DELETE /generator/templates/{template}`** — deliberately still
  absent even though a generation Session now references a template (`template_id`, provenance-only): a
  Session SNAPSHOTS the recipe at creation and renders only from that copy (see
  `docs/backend/generator-sessions-api.md`), so deleting a referenced template never breaks an existing
  session and there is nothing to guard against. **Confirmed still true post sub-stage 5:**
  `generate_content` resolves `template_id` through the LIVE, tenant-scoped `Template` model only ONCE, at
  the moment the step's `run()` creates the session (hard-failing the step if the template is gone by
  then) — the session it creates snapshots the recipe exactly like an interactive one, so a template
  deleted AFTER a `generate_content` session exists still never breaks it. This guard remains
  unnecessary.
- **Scene-plan polish** (D4, no ETA) — **superseded for `video_script`**: `scene_plan` is no longer
  authorable for a new/edited `video_script` template (dropped by the video_script rework Phase B in favor
  of `shot_list`/`storyboard`, see `docs/decisions/ADR-0035-video-script-cross-part-storyboard.md`), so its
  drag-to-reorder / richer-metadata polish is moot for new authoring. `scene_plan` itself is UNCHANGED and
  still fully editable for a pre-rework template that still carries it (`TemplateScenePlanPart.vue`), and
  the shape remains available to any FUTURE content type that wants to reuse the kind (D8 — the kind
  vocabulary is closed, not deleted).
- **Native structured output for `shot_list`** (video_script rework Phase B) — `laravel/ai` v0.4.3's
  `HasStructuredOutput` is available but deliberately not used (`ShotListAgent` uses prompt-and-parse
  instead); see `docs/decisions/ADR-0035-video-script-cross-part-storyboard.md` (D6) for why, and its
  "Alternatives considered" for what would be needed to adopt it later.
- **A structured (non-text-only) `parts.*` cross-part root** — today only a rendered STRING contributes to
  `parts.<key>` (D1); a later part reading an earlier part's STRUCTURED data (e.g. an `image_plan`'s
  metadata) would need a separate reference/type-flow design. The one concrete structured need identified so
  far — `storyboard` reading its sibling `shot_list`'s shots — is handled by a SEPARATE, kind-specific
  intra-composition mechanism, not this generic root (ADR-0035, D7).
