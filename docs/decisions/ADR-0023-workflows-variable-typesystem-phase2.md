# ADR-0023 — Workflows variable type-system: structural types — object/array<object> containers + the file composite (Phase 2)

**Date:** 2026-07-22 (created)
**Status:** Accepted
**Updated:** 2026-07-23 — Addendum below: non-array `object` descriptor fields join the reference
index/type map, recursively (partially supersedes the "Rejected: enumerating a repeater element's
subfields" note — for the non-array case only; the REPEATER/array boundary Decision 5 established is
unchanged)
**Module:** `App\Modules\Workflows` (one cross-module addition: `App\Modules\Disk\Models\File`)
**Relates to:** ADR-0021 (Phase 0: the composable, form-independent catalog this phase's container
walk builds on), ADR-0022 (Phase 1: the `descriptor` spine and the `TIME` descriptor-only tripwire
this phase's `object` case mirrors verbatim), ADR-0018 (file-container-is-fileable — the Disk
`File` model this phase adds `serveUrl()` to), ADR-0016 (disk-resources — pins the FILE variable's
snapshot shape `{id,name,mime_type,size}` this phase extends with `url`), ADR-0009 (typed variable
system: one identity, two serializations; the whitelist/no-expression-language invariant this
phase's file-subfield resolution stays entirely inside)

---

## Context

Phase 0 (ADR-0021, `3e75f11`) made the catalog composable and form-independent. Phase 1
(ADR-0022, `43c5cbd`) added the structured `descriptor` spine, a descriptor-only `TIME` type,
per-reference defaults, and 5 presence/date-format operations. Phase 2 — batches **2a** (object
containers), **2b** (the file composite), **2b.1** (file subfields in the write-validation
reference index), and **2c** (the matching frontend expansion) — closes two more gaps, both
prerequisites for R2 (Generator/Templates), which will need to author content against a form's
FULL structure and against individual file facets, not just its flat scalar leaves:

1. **A form's structural elements were invisible past their scalar leaves.** A SECTION's children
   already resolved as flat `section.field` variables (via the shared
   `InteractsWithFormSchema::extractFieldPaths` recursion), but the section itself had no
   identity — an author (or a future R2 loop-authoring UI) had no way to see "this form has a
   `details` group" as a thing. A REPEATER was worse: excluded from the catalog outright (ADR-0009
   §7) because its answer is an array-of-objects no flat path can resolve to a scalar — an honest
   omission, but one that made a repeater completely invisible, not just unaddressable.
2. **A FILE variable was opaque.** An author could reference "the whole file" (its text
   representation stringifies to the name, its structural coercion yields the id(s)), but never one
   of its own facets — and, notably, the snapshot did not even carry a servable `url` at all before
   this phase, which R2's "insert this image" / "link to this attachment" authoring needs.

**Both gaps are closed as REPRESENTATION ONLY.** Making the whole form structure — and a file's own
facets — visible and (for the file) individually referenceable is this phase's entire scope.
Actually LOOPING a repeater or a multi-file answer (iterating per element, giving each element its
own binding inside a template/generator run) is explicitly **OUT OF SCOPE, deferred to
R2-Generator**, which needs its own design for element cardinality, output binding, and loop
context — not a byproduct of a catalog-visibility batch. Every change below is additive and
behavior-preserving: an existing stored workflow, an existing directive, and an existing
`{kind:'variable'}` union all keep parsing and running byte-for-byte unchanged. No migration was
needed and none was run.

**This is NOT the "Phase 2" ADR-0022 pointed to.** ADR-0022 named three specific items as
"deferred to Phase 2": `TIME` gaining real runtime semantics (resolver/evaluator/executor arms + a
flat wire representation), `WorkflowConditionTreeValidator::walkPipeline`'s exact-type gate
learning to accept the presence-op family the way the runtime executor already does, and two
defensive hardening items (`WorkflowOperationExecutor::normalizeInput()`'s missing `default` arm,
write-time validation of `date_format`'s pattern against its safe-token whitelist). **None of
those three files were touched by this batch** (`WorkflowConditionTreeValidator.php` and
`WorkflowOperationExecutor.php` are unmodified) — the actual next slice of work turned out to be
the structural-types gap above instead. All three ADR-0022 items remain outstanding, now deferred
to a later, not-yet-numbered phase; see the "Planned / deferred" update in
`docs/backend/workflows-api.md` this ADR appends.

## Decisions

1. **`WorkflowVariableType::OBJECT` is appended — descriptor-only, exactly mirroring the `TIME`
   tripwire (ADR-0022 Decision 2).** `operatorCases()` returns `[]` for `OBJECT` (never a condition
   source — a structural grouping is not a comparable scalar), and
   `WorkflowVariableCatalogService::flatType()` degrades it to `TEXT` on the flat wire, alongside
   `TIME`. The resolver/evaluator/executor's exhaustive matches (still the original 7 cases, no
   `default` arm) and the frontend's closed `WorkflowVariableType` union therefore never receive
   `object` — the same "loud tripwire, not a silent `UnhandledMatchError`" posture ADR-0022
   established. `WorkflowVariableType::descriptor()` gained two new parameters to support it:
   `array $fields = []` (an object's ordered `{key,label,descriptor}` child list) and
   `?bool $array = null` (an explicit override — an object caller states `array:false` for a
   section, `array:true` for a repeater — rather than the type deriving it, since `array:true`
   used to mean "this is a `MULTI`" and an object container needs a second, independent reason to
   set it).

2. **A NEW catalog-local structural walk emits one container variable per TOP-LEVEL form
   SECTION (an `object`) and REPEATER (an `array<object>`), strictly additive over the existing
   flat-leaf pass — the shared `InteractsWithFormSchema` trait is deliberately left untouched.**
   `WorkflowVariableCatalogService::containerVariables()` walks the form's JSON schema's top-level
   `properties`; for each fragment shaped like a section (`type:object` + `properties`) or a
   repeater (`type:array` whose `items` are an object), it builds an `OBJECT` descriptor via
   `containerDescriptorFor()`, recursing into children through `buildContainerFields()` (a nested
   container — e.g. a section inside a repeater — recurses again; a scalar/enum/multi child reuses
   `mapSchemaToVariableType()`). **A container's child LABELS (and its own label) come from the
   element `config.children`/`config.label`/`config.options` tree** via a new
   `collectContainerLabels()` walk — a sibling of the existing `collectOptionLabels()`, not a
   replacement: `collectOptionLabels` powers the flat-leaf pass and deliberately excludes
   repeaters, while `collectContainerLabels` recurses INTO repeaters too (their element fields need
   labels for the container's `fields` list) and is consulted only by this new walk. The
   pre-existing flat-leaf pass (`extractFieldPaths`, reused unchanged) still emits exactly what it
   always did — a section's scalar children as `section.field` variables, a repeater emitting none
   — so `formFieldVariables()` simply `array_merge()`s the container list onto the unchanged leaf
   list. **`InteractsWithFormSchema::extractFieldPaths` itself was deliberately NOT modified**:
   Forms' own reports/analytics depend on its exact existing contract (leaf-only, repeaters
   flagged-and-skipped), so coupling a Workflows-only visibility need onto a Forms-owned shared
   trait was rejected in favor of a second, catalog-local walk (see "Rejected" below). A container
   variable carries no `field_id` and is explicitly filtered out of `conditionFields()` (its
   `descriptor.base === 'object'` is the exclusion test) — it is referenceable and inspectable, but
   never offered as a condition source. **Only the TOP-LEVEL section/repeater gets its own catalog
   entry** — a NESTED container (a section inside a repeater, say) is visible only inside its
   parent's recursive `descriptor.fields` tree; it has no flat leaf and no reference-index path of
   its own, so nothing beneath a repeater is referenceable today, consistent with the repeater's
   pre-existing no-flat-leaf rule.

3. **A FILE variable is modeled as its OWN composite (`base:'file'` + `fields`), not folded into
   the new `object` base — realizing "a file is like an object" as a shape, not an identity, so
   every existing file semantic survives with zero shim.** `WorkflowVariableType::descriptor()`'s
   `FILE` branch attaches the fixed `{id,name,type,size,url}` subfield list — self-supplied by the
   type itself (`fileFields()`, built from one source, `fileSubfieldTypes()`) whenever the caller
   passes none, which every current caller does — but **the flat wire `type` is left completely
   alone**: `flatType()` only degrades `TIME` and `OBJECT`; `FILE` falls through its `default` arm
   unchanged. This is the crux of the decision: a file variable keeps `operatorCases() =
   [FILLED, EMPTY]` (unchanged — condition filtering still works), keeps stringifying to its name
   inside text, keeps coercing to id(s) in a structured slot (what `create_task`'s copy-on-attach
   reads), and — because `conditionFields()`'s new object-exclusion filter tests
   `descriptor.base === 'object'`, not `'file'` — a FILE variable is **not** swept out of the
   condition-field list the way an `object` container is (pinned by
   `test_file_variable_stays_a_condition_source_with_filled_empty_operators`). Modeling `file` as a
   plain `object` instead was considered and rejected — see "Rejected" below.

4. **The file snapshot gains a `url` key — the file's own access-controlled serve route, built by
   a new `File::serveUrl()`, never a raw storage path.** `App\Modules\Disk\Models\File::serveUrl()`
   returns `route('disk.show', ['file' => $this->id])` — the identical shape `FileResource`
   already builds inline for its own `path` key (that resource was not touched by this phase; it
   still constructs the route itself rather than calling the new method, so the two are
   independent call sites producing the same URL, not yet a shared one).
   `WorkflowTriggerPayloadFactory::fileSnapshots()` now sets `'url' => $file->serveUrl()` on every
   snapshot entry, alongside the pre-existing `id`/`name`/`mime_type`/`size`. `disk.show` is gated
   end-to-end — `auth:sanctum` + `RequireWorkspace` + a tenant-scoped `{file}` binding that 404s a
   foreign or trashed id — so embedding it is safe to persist and log; it is emphatically **not** a
   forever-public link. The snapshot captures the **original submission file**: a later
   `create_task` step copies the file onto the task via `FileService`'s id-based copy, and that
   copy gets its own id/url — the trigger snapshot is never rewritten to point at the copy. `type`
   remains the human alias for the snapshot's `mime_type` (unchanged from before this phase).

5. **A file's subfields are individually referenceable, including as PIPELINE-bearing references —
   single-sourced through the same `fileSubfieldTypes()` map — while a REPEATER's element
   subfields deliberately remain NOT referenceable.**
   `WorkflowVariableCatalogService::referenceIndex()` (the write-validator's lookup table) and
   `runtimeTypeMap()` (the runtime base-type recovery table) both gained a small wrapper
   (`addReferenceEntry()` / `addTypeMapEntry()`) that, whenever an entry's type is `FILE`, ALSO
   inserts its five `<path>.<subfield>` entries. This means `StoreWorkflowRequest`'s value-pipeline
   validator now recognizes e.g. `fields.upload.name` as a KNOWN `text` variable and
   `fields.upload.size` as a KNOWN `number` variable — a pipeline targeting one type-flows from the
   real subfield type (a number op on `.name` now fails with the ordinary type-mismatch error under
   `.pipeline.0.op`, not "unknown path"; see
   `tests/Feature/WorkflowStepValuePipelineValidationTest.php`). At RUNTIME,
   `WorkflowVariableResolver` gained one shared `readContext()` wrapper around every existing
   `Arr::get($context, $path)` call site (both flat-token branches, both directive forms, the
   `{kind:'variable'}` union, and if-block condition evaluation) — so a file subfield resolves
   identically everywhere a normal path already did, with no per-call-site special-casing. On an
   `Arr::get` miss, `readFileSubfield()` treats the path's LAST segment as a candidate subfield
   name, collapses the parent answer (`collapseFileSnapshot()`) to a single snapshot — a genuine
   single-file snapshot passes through, a multi-element list takes its FIRST element, fail-soft —
   and reads the mapped key (`type` → the snapshot's `mime_type`, everything else identity). This
   NEVER throws and is consulted only as a fallback: a genuinely nested value that happens to have
   its own `name`/`type` key (e.g. a section child literally named that) still resolves directly,
   never intercepted (`test_a_genuine_nested_field_named_like_a_subfield_still_resolves_directly`).
   **A REPEATER element's subfield (e.g. `items.item_name`) is deliberately NOT enumerated by
   either table** — it exists only inside the container's `descriptor.fields`, never as a flat or
   reference-index path — so a pipeline-bearing reference to one still 422s as an unknown variable
   (`test_rejects_a_pipeline_bearing_repeater_element_ref`). This is the concrete boundary between
   "a file's own facets are addressable today" and "per-element access needs the R2 loop": a file
   answer is always exactly one composite value (even when its snapshot list has extra entries), so
   collapsing it to a single element is a safe, total operation; a repeater's elements are an
   open-ended list with no defined "which one" today, so no path is offered for them at all rather
   than silently picking one.

6. **The frontend layers ONE additive expansion, `expandVariables()`, under every variable-offering
   feed, rather than special-casing structural descriptors at each call site.**
   `resources/js/next/pages/workflows/workflowVariables.ts`'s `toEditorVariables()`,
   `toEditorVariablesTyped()`, and `variablesOfType()` all now route their flat variable list
   through `expandVariables()` before their own per-feed shaping (this also now owns the SF3.2
   id-strip rule for top-level variables, previously inlined at each call site). Per descriptor
   base: a **FILE** composite yields its unchanged whole-file entry PLUS one pickable per subfield
   (path `<file>.<key>`, a qualified display name — the file's own name plus a localized subfield
   label — and the subfield's real scalar type); subfields deliberately BYPASS the id-strip rule (a
   file's own `.id` IS meant to be pickable, unlike a generic system id). A **REPEATER**
   (`object`+`array:true`) yields exactly ONE entry, relabelled with a "(list)" suffix so a user
   can see the collection exists, with no per-element children. A **SECTION**
   (`object`+`array:false`) yields NOTHING — its leaves are already present as separate top-level
   variables, so re-surfacing the whole object (which would resolve to a nested map at runtime)
   would only duplicate and confuse. Everything else passes through unchanged. A new
   `descriptorBaseToType()` helper mirrors the backend's degrade rule with its own closed-union
   safety net: `enum` maps to `multi` when `array:true` else `enum`; `number`/`boolean`/`date`/
   `file` map to themselves; every other base (`time`, `object`, and any future base) falls through
   a `default` arm to `text` — so a new descriptor base can never crash the FE's own closed type
   switch, the same defensive posture the backend's `descriptorBase()` uses. The TypeScript
   contract grew accordingly: `CatalogVariableDescriptor.base` widened to include `'object'`, a new
   recursive `CatalogDescriptorField { key, label, descriptor }` interface backs the new optional
   `descriptor.fields`, and `CatalogType.id` widened to a new `CatalogTypeId = WorkflowVariableType
   | 'time' | 'object'` (the catalog's `types[]` list now enumerates every backend case including
   the two descriptor-only ones — a variable's actual `type` field, and the closed
   `WorkflowVariableType` union itself, are UNCHANGED, since neither `time` nor `object` ever
   reaches a variable's flat `type` on the wire). New i18n keys back the qualified subfield name
   (`workflows.variable.qualifier`, `"{parent} › {sub}"`), the collection suffix
   (`workflows.variable.collection`, `"{name} (list)"` / `"{name} (lista)"`), and the five subfield
   labels (`workflows.variable.fileSubfield.{id,name,type,size,url}`) in both `en.ts`/`pl.ts` — the
   backend's own subfield `label` is just the raw key (`'name'`, `'size'`, …; unlike a section's
   labels, which ARE real config-sourced human text), so the human-facing capitalization
   (`"Name"`/`"Nazwa"`) is entirely a frontend localization concern, consistent with how every
   other label-less catalog vocabulary (`operations`, `ai_personas`, `types`) is already localized
   client-side.

## Consequences

- **Positive.** The catalog now shows the WHOLE form structure (every section and repeater has an
  entry), giving R2's future loop/template authoring a real foundation to build on without this
  batch having to commit to loop semantics itself. A file becomes addressable at the facet level
  for the first time — a template can reference "the attachment's name" or link its `url` — with
  zero behavior change to every existing workflow that already references a file as a whole.
  Everything is additive: a workflow stored before this phase, a catalog response cached by an
  older frontend build, and every pre-existing test all keep passing unchanged; no migration was
  needed or run.
- **Trade-off (accepted): a container variable is representation-only.** An author can now SEE
  that a repeater exists and inspect its element shape via `descriptor.fields`, but still cannot
  loop over it, address one element, or bind a template slot to "element N's `item_name`" — that
  needs its own loop-context design (element cardinality, per-element output binding), deliberately
  left for R2-Generator rather than grown incrementally here.
- **Trade-off (accepted): `descriptor.array` is `false` on every file variable shipped today, even
  though the resolver's subfield-collapse already defensively handles a multi-element snapshot
  list (taking the first, fail-soft).** No form-builder surface exists today to author a field that
  accepts more than one file, so this is forward-defensive plumbing for a shape the type already
  declares support for (`array:<multi-file?>` in the descriptor docblock), not evidence of a
  shipped multi-file capability.
- **Trade-off (accepted): a nested container has no path of its own.** A section nested inside a
  repeater (or vice versa) is visible only inside its parent's recursive `fields` tree — it is not
  independently referenceable, not even as a whole object — until a real per-element loop context
  exists to give one a meaningful path.
- **Clarification, not a new trade-off: this ADR is not the "Phase 2" ADR-0022 named.** See the
  Context section above — `TIME` runtime semantics, the presence-op `walkPipeline` asymmetry, and
  the two defensive hardening items ADR-0022 deferred are all still outstanding, untouched by this
  batch, and now deferred to a later, unnumbered phase.
- **Rejected: modeling `file` as a plain `object` base with `fields`.** Would degrade the flat wire
  `type` to `text` (losing the `filled`/`empty` condition operators outright, since `conditionFields`
  would then also filter it out as a container) and would replace the structural coercion's
  id-list output with a generic nested-map read — breaking `create_task`'s id-based copy-on-attach
  unless a special case were added back in. Giving `file` its own composite base (Decision 3)
  achieves the same "shape with named subfields" outcome with no shim anywhere.
- **Rejected: extending `InteractsWithFormSchema::extractFieldPaths` to also emit container
  entries.** That trait is shared with Forms' own reports/analytics, which depend on its current
  leaf-only, repeater-skipping contract; changing its output shape to serve a Workflows-only
  visibility need would couple a Forms-owned contract to a Workflows concern for no benefit either
  module needs. A second, catalog-local walk (`containerVariables()`) keeps the coupling at zero.
- **Rejected: enumerating a repeater element's subfields in the reference index today.** Would let
  a value-or-variable pipeline reference `items.item_name` as though it resolved to one scalar,
  when at runtime it is actually N values with no defined "which element" semantics yet — a
  correctness trap, not a convenience, until R2's loop model defines what "the" element means.

See `docs/backend/workflows-api.md` ("Structural descriptor: object containers & the file
composite" under "The typed variable system") for the full wire contracts this phase shipped,
`docs/backend/disk-api.md` and `docs/decisions/ADR-0016-disk-resources.md` for the `serveUrl()` /
snapshot cross-reference, and `resources/js/next/docs/pages/WorkflowsPage.vue` (Section 6) for the
in-app docs mirror.

---

## Addendum (2026-07-23) — non-array OBJECT descriptor fields are now referenceable, recursively

Decision 5 above enumerated a FILE composite's fixed subfields into the reference index / runtime
type map via `fileSubfieldTypeMap()`, and explicitly left a REPEATER element's subfields
unenumerated ("Rejected: enumerating a repeater element's subfields in the reference index today").
A later batch generalizes that mechanism to a SECOND descriptor shape — a **non-array** `object`
container's own declared `fields` — while leaving the REPEATER (array) case exactly as Decision 5
and the Rejected note describe.

`WorkflowVariableCatalogService::descriptorSubfieldTypeMap()` (renamed from the phase-2b-only
file-subfield wrapper) is now `fileSubfieldTypeMap() + objectSubfieldTypeMap()` — the new
`objectSubfieldTypeMap()` walks a descriptor's `fields` RECURSIVELY into `<path>.<key>` entries
whenever `isObjectContainer($descriptor)` (`base === 'object' && array !== true`), mirroring the
editor's own picker-tree rule of the same name in `workflowVariables.ts` so what the tree offers is
exactly what the write-side index accepts. `addReferenceEntry()`/`addTypeMapEntry()` both call it
unconditionally now (not just for a FILE-typed entry), so this reaches EVERY non-array object
descriptor — a form SECTION's own container entry (redundant with its pre-existing flat leaves, see
below), and, more consequentially, a Phase-3 GLOBAL's interior, which has no separate flat-leaf pass
at all. Recursion STOPS the instant it reaches an `array:true` object descriptor (a REPEATER) —
`isObjectContainer()` returns `false` for it, so `objectSubfieldTypeMap()` returns `[]` immediately —
so a repeater's element subfields remain UNREFERENCEABLE at every nesting depth, exactly as Decision
5 established; this addendum widens the non-array case only, at any depth of non-array nesting (an
object nested inside another non-array object is indexed too, recursively).

**Section-leaf dedupe.** A form section's own flat leaves are emitted by the pre-existing
`formFieldVariables()` leaf pass BEFORE its container entry, so `addReferenceEntry()`'s existing
`??=` guard means the section's own richer flat entry (carrying its `enumOptions`) always wins over
the descriptor-derived duplicate — no behavior change for any existing section reference. A
descriptor-derived subfield entry (whether from a FILE or now an OBJECT) carries NO `enumOptions` —
an accepted, unchanged limitation (Decision 5's file subfields already had it).

This closes the write-side gap Decision 5 left open for a SELF-CONTAINED object with no separate
flat-leaf pass — see `docs/decisions/ADR-0024-workflows-variable-typesystem-phase3-globals.md`'s own
addendum for the GLOBAL-specific consequence (an object global's interior is now write-validatable,
matching what its picker tree already exposed). The "Rejected: enumerating a repeater element's
subfields" note above is otherwise UNCHANGED — a repeater element's subfield is still, deliberately,
not a correctness-safe reference until R2's loop model defines what "the" element means. See
`docs/backend/workflows-api.md` ("Structural descriptor: object containers & the file composite")
for the updated wire description.
