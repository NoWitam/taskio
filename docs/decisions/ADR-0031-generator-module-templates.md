# ADR-0031 — R2 PR-1b: the Generator module + Templates

**Date:** 2026-07-26 (created)
**Status:** Accepted (see supersession note below)
**Module:** `App\Modules\Generator` (new)
**Relates to:** ADR-0030 (the shared-engine down-move this module consumes — read that ADR first),
ADR-0027 (the one-way-module-boundary + dependency-inversion precedent this module's own boundary test
mirrors), ADR-0028 / ADR-0029 (Consts / Functions — the CRUD/authoring pattern Templates mirrors:
workspace-scoped, creator-only mutation, member read, dual central/tenant persistence), ADR-0021 (the
composable catalog-roots recipe — `slots` is the new root this module adds)

> **Superseded by ADR-0032 (Generator content-recipe rework).** This record describes Templates as
> originally shipped in this sub-stage: a `type` enum + a single markdown `prompt_body` + free-form
> `parameters` — the "prompt with parameters" model. That model was REJECTED before the sub-stage was
> considered done: it does not match "a template is a reusable RECIPE for a finished post," offers no
> place for a media (image/scene) plan, and treats "the whole post is AI" as a special case instead of
> the natural one-big-`@[ai-text]`-block case. **ADR-0032** replaces it, in place, with the CONTENT-RECIPE
> model — `content_type` (a code-defined `ContentTypeRegistry` id) + a per-part `content` map (`text_body`
> / `image_plan` / `script` / `scene_plan`) — before this sub-stage's first commit. The historical text
> below is kept AS WRITTEN for the record; see **ADR-0032** and `docs/backend/generator-api.md` for the
> contract that actually shipped.

---

## Context

Per the product roadmap (`docs/product/plan-dzialania.md`, R2 "Generator treści + Templatki"), sub-stage 1
("Templatki") is the first user-facing slice of the Generator: a reusable, workspace-scoped PROMPT with
declared typed placeholders a user (and, later, a workflow) fills in to produce content. Everything past
the definition itself — running a generation SESSION, delegating to a bot persona, saving a result to
Disk, spending real AI budget, a `generate_content` workflow step, image generation — is explicitly out of
scope for this sub-stage (see Decision 9) so the slice stays reviewable.

The architecturally significant decision is the module's DEPENDENCY DIRECTION: `App\Modules\Generator`
depends on `App\Modules\Variables` (ADR-0030's down-move exists specifically so it can), and depends on
NOTHING from `App\Modules\Workflows` — Generator and Workflows are SIBLINGS sharing only the lower
Variables layer, never depending on each other. This mirrors the one-way boundary ADR-0027 established for
Workflows → Variables, enforced the same way: a standing test scanning every file under the module for a
forbidden import string.

## Decisions

1. **One `App\Modules\Generator` module, registered `GeneratorModuleServiceProvider` in
   `bootstrap/providers.php` immediately AFTER `VariablesModuleServiceProvider`** (load order mirrors the
   dependency direction, the same convention ADR-0027 established for Workflows):

   ```php
   // bootstrap/providers.php
   App\Modules\Variables\VariablesModuleServiceProvider::class,
   App\Modules\Generator\GeneratorModuleServiceProvider::class,
   App\Modules\Workflows\WorkflowsModuleServiceProvider::class,
   ```

   The boundary is enforced by `tests/Feature/GeneratorModuleBoundaryTest.php`, structurally identical to
   `VariablesModuleBoundaryTest`: `test_generator_module_imports_nothing_from_workflows` scans every
   `.php` file under `app/modules/Generator` for the literal string `App\Modules\Workflows`; a second
   test, `test_generator_services_depend_on_variables_not_workflows`, positively asserts
   `TemplateVariableCatalog` / `TemplateRenderService` DO name `App\Modules\Variables` (so the boundary
   test cannot pass vacuously for a module that simply imports nothing at all — it must be importing the
   RIGHT thing).

2. **`Template` — a workspace-scoped model mirroring the Constant/CustomFunction persistence pattern
   exactly** (`app/modules/Generator/Models/Template.php`, table `templates`): `TenantAware` +
   `HasCreator` + `HasUuids`. Identity is the uuid; `name` is a display label only (not unique) — a
   Template is ALWAYS user-created (no engine authors one), so `HasCreator`'s system-record fallback never
   applies, same as a Constant or a CustomFunction. Columns: `name`, `description?`, `type` (a
   `TemplateType` id), `prompt_body` (markdown, nullable — an empty draft is a valid row), `slots` (json —
   the declared typed placeholders), `parameters` (json — free-form generation hints), `creator_id` /
   `creator_type` (polymorphic). Dual schema, following the Constant/CustomFunction convention verbatim: a
   CENTRAL migration (`workspace_id` nullable+indexed, shared-mode isolation via `WorkspaceScope`) and a
   TENANT migration (`database/migrations/tenant/0001_01_01_000049_create_templates_table.php`, omitting
   `workspace_id` — one tenant database is one workspace). Proven both ways:
   `tests/Feature/TemplateOwnDatabaseTest.php` pins that a Template created while an own-database
   workspace is active routes to the dedicated tenant connection and never lands centrally.

3. **`TemplateType` — a small, closed-but-additive enum: `post`, `post_with_image`, `video_script`.**
   String-backed (the value IS both the DB value and the wire id). `post_with_image` reserves the SHAPE
   for an image slot/parameters only — it does not model image GENERATION itself, which is explicitly
   deferred (Decision 9 / sub-stage 6).

4. **Slots are DECLARED, typed placeholders — NOT catalog-derived.** Each slot is
   `{name, description?, descriptor}`, the identical shape `CustomFunction.args` already uses (ADR-0029)
   and the identical `descriptor` shape a `Constant` declares (ADR-0028) — reusing
   `ConstantTypeValidator::validateDescriptorShape()` as the SINGLE descriptor authority, so a slot's type
   can never accept a shape the resolver/catalog does not understand. `TemplateSlotValidator` (the
   Generator counterpart to `FunctionDefinitionValidator` / `ConstantTypeValidator`) additionally
   enforces: a slot `name` is a safe identifier (`/^[a-zA-Z_][a-zA-Z0-9_]{0,62}$/`), UNIQUE within the
   template, and not one of the RESERVED scope/root names — `input` / `element` / `index` (the
   function-scope names) plus `trigger` / `steps` / `globals` / `slots` (the context roots) — inlined as a
   literal in `TemplateSlotValidator::RESERVED_SLOT_NAMES` (see ADR-0030 Consequences for why this is an
   inlined copy, not an import).

5. **`prompt_body` is markdown carrying the SAME `@[variable]` directive serialization a workflow text
   field uses**, referencing `slots.<name>` (the template's own declared placeholders), `globals.<key>`
   (workspace consts), pipelines, and custom functions (`fn:<uuid>`) — nothing new was invented for the
   wire format. `TemplateSlotValidator` write-validates every directive it can DECODE (a malformed one
   renders inert at runtime — never a write error, matching the resolver's own tolerance): an unknown
   `slots.<name>` reference is REJECTED (422 under `prompt_body`); a KNOWN top-level reference's
   (non-empty) pipeline is type-flowed through the SAME shared `PipelineValidator` a workflow pipeline
   uses. A directive whose root is not a context root at all (e.g. `foo.bar`) is not a reference to the
   resolver — left literal, accepted at write, and rendered inert at preview. Deliberately deferred:
   SUBFIELD references into an object/file slot (`slots.company.city`) are fail-soft/unvalidated in this
   sub-stage — the workflow catalog's recursive descriptor descent (ADR-0023) is NOT forked into
   Generator; see Decision 9.

6. **`TemplateVariableCatalog` — the Generator counterpart to `WorkflowVariableCatalogService` — layers
   the template's declared `slots.<name>` variables OVER the shared, form-independent surface
   `VariableCatalog` (ADR-0030) owns.** Each `slots.<name>` entry mirrors a global's own catalog shape
   exactly (`source` EQUALS the root, the flat wire `type` recovered from the descriptor via the shared
   `flatType()` rule, the authoritative `descriptor`, enum option keys when applicable) — built straight
   from the DECLARED descriptor (unlike a workflow form field, whose type is DERIVED from a JSON schema).
   It serves three consumers off the SAME source, so they can never disagree: the editor catalog
   (`forSlots()`), the write-side reference index (`referenceIndex()`), and the runtime type map
   (`typeMap()`).

7. **Three write endpoints (mirroring the Consts/Functions CRUD shape) plus two draft-friendly,
   server-authoritative helper endpoints:**
   - `GET/POST /api/generator/templates`, `GET/PUT/DELETE /api/generator/templates/{template}` —
     `TemplateController` (thin) → `TemplateService` (cursor-paginated `index`, `search(['name'])`,
     single-row `create`/`update`/`delete`, no transaction needed — there is no multi-step orchestration
     to make atomic) → `TemplateResource` (identity + `slots`/`parameters` shaped to their stable wire
     keys + `creator` + server-authoritative `is_owner`/`can_be_edited`/`can_be_deleted`).
     `TemplatePolicy`: `viewAny`/`view`/`create` = any authenticated user (workspace membership enforced
     upstream by `ResolveWorkspace`/`WorkspaceScope`, same split as Consts/Functions); `update`/`delete` =
     creator only, via the shared `ChecksRecordOwnership::ownsOrManagesSystemRecord` trait (whose
     system-record fallback never actually applies here, since a Template is always human-created). No
     soft-delete (like Constant/CustomFunction, unlike Workflow) — `DELETE` is a hard row delete.
   - `POST /api/generator/catalog` — `{slots:[{name, descriptor}]}` → `{data:{variables, operations,
     types}}`. DRAFT-FRIENDLY (POST, not GET, because an in-progress template's unsaved slots ride the
     body) and server-authoritative (the SAME catalog the write-validator itself checks against). Fail-soft
     on a malformed slot (skipped, not 422'd) — an in-progress template still gets a usable catalog.
   - `POST /api/generator/preview` — `{prompt_body, slots, slot_values}` → `{data:{rendered: string}}`.
     The FAITHFUL preview (Decision 8).

8. **The preview is FAITHFUL — it renders through the REAL shared `VariableResolver`, not a frontend
   re-implementation — with a CONTEXTUAL no-op `AiTextGenerator` bound ONLY for this path.**
   `TemplateRenderService::render()` builds the exact context shape the resolver expects (`{slots: <sample
   values>, globals: <workspace consts>}`, the workspace's custom functions threaded via `FunctionScope`, a
   runtime type map built from the slot + global descriptors) and calls `VariableResolver::resolveString()`
   — the SAME method, the SAME executor, the SAME directive/if-block/pipeline/NUL-mask machinery a real
   workflow run uses. `@[ai-text]` renders INERT (resolves to `''`) because `GeneratorModuleServiceProvider`
   binds a contextual override:

   ```php
   // app/modules/Generator/GeneratorModuleServiceProvider.php
   $this->app->when(TemplateRenderService::class)
       ->needs(VariableResolver::class)
       ->give(fn ($app) => new VariableResolver(
           $app->make(OperationExecutor::class),
           new NoOpAiTextGenerator,
           $app->make(OperationResolver::class),
       ));
   ```

   Bound CONTEXTUALLY (only when resolving a dependency of `TemplateRenderService`), not app-wide — so
   Workflows keeps its real, budgeted `WorkflowAiTextService` everywhere else. `NoOpAiTextGenerator::
   generate()` always returns `''`, the SAME fail-closed contract a real generator's blank-prompt /
   exhausted-budget / provider-failure path already returns — the resolver cannot tell the difference
   between "no AI configured for this preview" and "the AI call failed," which is exactly the point (no
   special-casing anywhere in the resolver). Every path in `TemplateRenderService::render()` is fail-soft
   to `''`, including a caught `RuntimeException` from a hard `assert_present` — a preview is advisory,
   never a run, so it degrades to an empty render instead of a 500.

9. **Explicitly deferred this sub-stage, per the accepted plan:**
   - **AI-cost metering foundation** — pulled FORWARD to sub-stage 2 (the Generator SESSION feature is the
     first REAL AI spender; sub-stage 1 runs no real AI at all, so there is nothing to meter yet).
   - **`generate_content` workflow step** — deferred to sub-stage 5, unblocked (cycle-free) specifically
     BECAUSE of ADR-0030's down-move.
   - **Image generation** — deferred to sub-stage 6; `post_with_image` (Decision 3) only reserves the
     type's shape.
   - **Object/file SUBFIELD references** inside a prompt body (Decision 5) — fail-soft/unvalidated, the
     same "R2 loop" deferral the workflow catalog already carries for per-element/subfield execution
     (ADR-0023).
   - **Generation Sessions, Disk-save, bot-authored generation** — no code this sub-stage; the module
     exists, per its own docblock, to own "Templates now (and generation Sessions later)."

## Consequences

- **Positive.** Sub-stage 1 ships a complete, reviewable vertical slice (model → validator → catalog →
  faithful preview → CRUD → FE) that reuses 100% of the Variables engine with zero forked logic, proving
  out ADR-0030's down-move immediately rather than leaving it speculative.
- **Positive.** The faithful-preview design (Decision 8) means "what you see in the preview is what a real
  render would produce" is true BY CONSTRUCTION (same resolver, same executor) rather than by discipline —
  there is no second interpolation implementation for a frontend/backend preview mismatch to creep into.
- **Positive.** Because Generator never depends on Workflows, sub-stage 5's `generate_content` step
  (Workflows → Generator) will not create a cycle — this is the payoff ADR-0030 was built for.
- **Trade-off (accepted): a Template's slots offer NO subfield authoring/validation for an object/file
  base in this sub-stage** (Decision 9) — a slot CAN be declared `object`/`file`, but only its top-level
  path is referenceable in the prompt body; a subfield reference neither validates nor resolves.
  Consistent with — not a new gap beyond — the workflow catalog's own pre-existing per-element/subfield
  deferral.
- **Trade-off (accepted): `TemplateSlotValidator::RESERVED_SLOT_NAMES` is an inlined literal**, not
  cross-asserted equal to `VariableResolver::ROOTS` / `PipelineValidator::DEFAULT_REFERENCE_SOURCES` by a
  standing test (see ADR-0030 Consequences) — a future root addition to Variables would need a manual,
  undetected update here. Small blast radius (a missed reserved name only lets a slot SHADOW a root, which
  the resolver's own whitelist gate still resolves safely — Decision 4's "inert" behavior in ADR-0030) but
  named here explicitly as a gap rather than left silent.
- **Trade-off (accepted): the two draft-friendly endpoints (`catalog`, `preview`) authorize on
  `TemplatePolicy::viewAny`** (any workspace member) rather than requiring an existing, saved Template —
  necessary because both operate on UNSAVED drafts, but it means any member can probe the catalog/preview
  surface without creating anything, the same posture the workflow catalog's own form-less path already
  accepts (ADR-0021).
- **Rejected: deriving a Template's variables from something OTHER than declared slots** (e.g. parsing
  them out of the prompt body automatically). Rejected per the accepted plan — DECLARED slots give a
  stable identity (a slot can be re-described without breaking anything, and the descriptor is explicit)
  the same way a CustomFunction's args are declared, not inferred.
- **Rejected: validating/resolving object-slot and file-slot SUBFIELD references in this sub-stage** by
  forking the workflow catalog's recursive descent. Rejected as unnecessary scope growth for sub-stage 1;
  the same deferred "R2 loop" the type-system ADRs already named covers this.

See `docs/backend/generator-api.md` for the full endpoint contract (query/body parameters, validation
error tables, worked request/response examples) and `docs/decisions/ADR-0030-variable-engine-down-move.md`
for the shared-engine relocation this module is built on.

---

## Amendment (same chapter — the "structural slots" polish)

Decision 5, Decision 9, and the Trade-off/Rejected notes above all describe object/file slot SUBFIELD
references (`slots.company.city`) as deliberately fail-soft/unvalidated/deferred for this sub-stage. That
scope note is SUPERSEDED, within the same sub-stage (not a later one): a subfield reference is now
write-validated (an unknown subfield of a declared object/file slot is a symmetric 422, exactly like an
unknown slot itself) and resolved at runtime — sharing ONE descent implementation with the workflow
catalog, promoted into `App\Modules\Variables\Services\VariableCatalog` (see
**ADR-0030-variable-engine-down-move.md**'s own amendment for the mechanics this module now calls). The FE
slot builder (`TemplateSlotsPanel.vue`) was widened alongside it: `object` AND `file` are now both
authorable bases in the UI (not merely API-accepted-but-unauthored, which is how Decision 4 originally
left `object`; `file` is newly authorable outright), with matching structured sample-value inputs in the
preview panel.

The historical Decision/Trade-off/Rejected text above is left AS WRITTEN — it accurately records what
sub-stage 1 shipped as of this ADR's own date — rather than rewritten to match the later state. See
`docs/backend/generator-api.md` → "Object and file slots" / "Subfield references" / "FE authoring parity"
for the current, implemented contract, including the ONE residual gap this polish did NOT close:
array-of-object / array-of-file ("repeater-shaped") slots still get no subfield descent and no FE
authoring — the same per-element "R2 loop" deferral Decision 9 already named, now narrowed to just that
one shape instead of every structural slot.
