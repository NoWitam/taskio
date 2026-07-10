# Workflows module — UX/UI specification

> **REVISION 3 — schedule rebuild (B1–B5) — STATUS: IMPLEMENTED.** The schedule
> vocabulary grew from 12 to 16 families, `weekly` moved to a weekday LIST,
> `times`/`exclusions` were added to the schedule block, and a live preview
> endpoint landed (`POST /workflows/meta/schedule-preview`). `§4.5` below was
> **rewritten in place** to the as-built two-mode (simple/advanced) schedule
> builder, replacing REVISION 2's tier-based single-mode description. `§7.1` and
> `§8.4` were updated for the new i18n groups and files. See
> `docs/decisions/ADR-0010-workflows-schedule-rebuild.md` for the design
> decisions and `docs/backend/workflows-api.md`'s Schedule section for the
> current backend contract. Everything outside §4.5/§7.1/§8.4 is UNCHANGED from
> REVISION 2 and still describes the as-built module accurately.

> **REVISION 2 — Etap 5.1 re-scope — STATUS: IMPLEMENTED.** **B7 (the frontend
> rebuild this spec drives) is COMPLETE and reviewed ("production-ready"), same as
> backend B1–B5.** This document was implemented **verbatim** — it is retained as
> the as-built reference for the module, not just a pre-implementation design doc.
> It supersedes REVISION 1 (Etap 5, Batch 5). Where a REV-1 section contradicted
> the 5.1 backend, the section was **rewritten in place** (not appended-to), so
> there are no stale/contradictory passages left. Sections 5.1 did not touch
> (module shell, list, detail chrome, runs view, states, a11y) are carried forward
> with only the delta edits called out.
>
> **As-built deltas from this spec (both applied, everything else shipped
> verbatim):**
> - The whole-B7-review found the typed condition rows keyed by list INDEX, which
>   breaks Vue's reconciliation identity across an add/remove/reorder (a classic
>   `:key="index"` footgun). Fixed: `WorkflowConditionsEditor.vue` now keys each
>   condition row by a locally-generated, stable **uid** (a parallel `rowUids` list
>   alongside the wire `WorkflowCondition[]`, since the wire type itself carries no
>   id) — see `resources/js/next/pages/workflows/WorkflowConditionsEditor.vue`.
> - The whole-B7-review also found the AI schedule-assist composer left focus
>   wherever it was when the collapsed `sparkles` affordance expanded. Fixed:
>   `WorkflowScheduleAssist.vue` now moves focus into the prompt `Textarea` on
>   expand (queries the rendered control post-`nextTick`, since `Textarea` exposes
>   no `focus()` of its own) — see `resources/js/next/pages/workflows/WorkflowScheduleAssist.vue`.
>
> No other functional deviation from this spec was found during review.
> `docs/backend/workflows-api.md` and
> `docs/decisions/ADR-0009-workflows-rescope-typed-variables.md` are the
> corresponding as-built backend references.

---

## 0. REVISION 2 delta — what the 5.1 re-scope changed

The re-scope shrank the surface and made it **typed**. The five deltas B7 must
absorb (each drives a rewrite below):

| # | Was (REV 1) | Now (5.1) | Sections rewritten |
| --- | --- | --- | --- |
| D1 | **5 trigger types** (`task_created`, `task_status_changed`, `form_submitted`, `approval_finished`, `schedule`), each with a targeting UI. | **2 trigger types**: `schedule` + `form_submitted`. The three task/approval triggers and their `LabelSelect`/status/`PipelineSelect`/outcome targeting are **GONE**. | §2.4, §3.2, §4.4, §6, §7 |
| D2 | **4 step types** (`create_task`, `assign_bot`, `attach_form`, `start_approval`). | **2 steps**: `create_task` + `create_form_report`. `assign_bot`/`attach_form`/`start_approval` are **GONE** — form + pipeline attach now live **inside** `create_task`; assignment is a first-class `create_task` field. | §3.2, §4.6, §7.4 |
| D3 | A flat `{{trigger.*}}` / `{{steps.<key>.*}}` **reference catalog** with a click-to-insert popover. | A **typed variable system** fed by `GET /forms/{form}/workflow-catalog`. Text/markdown fields use the existing **`MarkdownEditor`** with its **variable extension**; non-text fields use new **`ValueOrVariableField`** / **`DateOrVariableField`** add-ons that echo the editor's chip. | §4.6, §4.7 (rewritten), §4.9 (new) |
| D4 | **4 schedule presets** (`every_n_minutes`/`hourly`/`daily`/`weekly`) with hand-coded inputs. | **16 descriptor-driven families** (12 at REV 2, +4 in the schedule rebuild — REVISION 3) from `GET /workflows/meta/schedule-families`, rendered through a **simple/advanced two-mode builder** with `times`/`exclusions` editors and a **live preview** (`POST /workflows/meta/schedule-preview`), plus an **AI natural-language assist** (`POST /workflows/schedule-assist`) whose alternative response now previews itself before apply. | §4.5 (rewritten twice — REV 2 then REVISION 3) |
| D5 | Conditions = flat `{field, operator, value}` string triples on any trigger. | **Typed per-field conditions** driven by the selected form's schema (`workflow-catalog.fields`), **form_submitted only**, disabled until a form is chosen. | §4.8 (rewritten) |

Everything else (module shell/aside, list + FilterBar + Saved Views, detail
`?section=`, runs list + timeline + origin badges, run statuses, sticky footer,
the 4 states, the `Button` primitive rule, trailing-affordance order, i18n en+pl
parity, the `workflow` glyph) is **carried forward** from REV 1.

### Contract facts this revision is built on (verified in code, not assumed)

- `StoreWorkflowRequest`: `trigger_type ∈ {form_submitted, schedule}`;
  `steps.*.type ∈ {create_task, create_form_report}`; `steps.*.key` required +
  `distinct`; conditions are `{field, field_type, operator, value}`, only valid
  for `form_submitted`, and require `trigger_config.form_id` when present.
- `WorkflowScheduleFamily::paramDescriptors()` — the 16 families (12 at REV 2, +4
  in the schedule rebuild — see ADR-0010) + exact bounds + `lt` invariants
  (reproduced in §4.5). The `weekly` family's param is now `weekdays` (a
  `weekday_list`), not a scalar `weekday`.
- `WorkflowScheduleService::nextOccurrences()` via
  `POST /workflows/meta/schedule-preview` — the ONE place occurrence dates are
  computed; the FE never re-implements cadence math (reproduced in §4.5).
- `WorkflowVariableType` operators (reproduced in §4.8) and
  `WorkflowConditionOperator` value shapes.
- `WorkflowVariableCatalogService` — the `{variables, fields}` catalog shape and
  the step-output stems `steps.create_task.{task_id,title}` /
  `steps.create_form_report.{report_id,report_name}`.
- `WorkflowScheduleAssistService` — the four-state assist envelope + 429 throttle.
- `StoreFormReportRequest` — `sources.* ∈ {task, form}` (NOT manual/task),
  `submissions_from`/`submissions_to` optional with server defaults.
- The manual-run 422 bag has exactly **`target_id`** and **`workflow`**
  (`approval_process` is gone).

Precedent files this spec mirrors (verified while writing):
- Module shell + aside: `resources/js/next/pages/bots/BotsModuleLayout.vue`.
- List scaffolding: `resources/js/next/pages/bots/BotsView.vue`.
- Read-only detail with `?section=`: `resources/js/next/pages/bots/BotDetailView.vue`.
- Cover drawer + sticky footer: `resources/js/next/pages/bots/BotEditorDrawer.vue`.
- Ordered list + ▲▼ reorder + per-index 422:
  `resources/js/next/pages/approvals/PipelineBuilderDrawer.vue`.
- Detail-nested list without FilterTabBar: `resources/js/next/pages/approvals/QueueView.vue`.
- Bucketed operational list with per-row state badges: `resources/js/next/pages/bots/BotInbox.vue`.
- The variable chip look/feel the add-ons echo: `resources/js/next/ui/editor/README.md`,
  `extensions/VariableChip.vue`, `extensions/VariablePanel.vue`.

---

## 1. Information architecture

*(Carried forward from REV 1 — unchanged by 5.1 except the trigger/step tables it
never touched. The `workflow` glyph is the confirmed module identity.)*

### 1.1 Module nav entry & icon

| Concern | Decision | Justification |
| --- | --- | --- |
| Module nav icon | **`workflow`** | The Lucide `workflow` glyph (two connected blocks — "steps linked into a pipeline") is the Workflows module identity everywhere (nav, PageHeader, aside header, empty state, card/entity fallback). Avoids the `git-branch` collision with the Approvals top-level nav. `git-branch` remains a SECTION glyph. |
| List sub-nav icon | `list-checks` | "the list of workflow definitions". |
| Overview section icon | `layout-dashboard` | "summary of this entity". |
| Runs section icon | `clock` | runs are a time-ordered history. |

### 1.2 Routes

Register under the authenticated AppLayout children, parallel to `bots`:

```
/workflows                     name: next.workflows          → WorkflowsView (list)
/workflows/:id                 name: next.workflows.detail   → WorkflowDetailView
                               ?section=overview|runs (default overview)
```

- Editor overlay = query key `?workflow=new` / `?workflow=<id>` (owned by the
  module layout, Bots' `?bot=` pattern; preserved across filter + section nav).
- Run-now overlay = query key `?run=<id>`.
- Run-detail overlay = query key `?run_detail=<runId>` (on the detail route).
- Deep-link unknown id → the detail view fetches by id and shows its own error
  state (Bots precedent).

### 1.3 Aside behavior (module layout)

`WorkflowsModuleLayout.vue` mirrors `BotsModuleLayout.vue`:

- **On the list**: module header (white-on-primary icon bubble
  `bg-next-primary text-next-primary-foreground` with `workflow`, title, select
  hint) + a single "All workflows" sub-nav item (`list-checks`).
- **After opening a workflow**: `back-to-list` (`arrow-left`) + the entity info
  block (icon bubble, name, StatusBadge) + a section sub-nav (**Overview** /
  **Runs**) driven by `?section=`. The layout `watch`es the route id and
  prefetches the detail so the aside renders identity immediately.
- Aside is `hidden … next-lg:flex`.
- The layout **hosts** the editor `Drawer` (`?workflow=`), the run-now `Modal`
  (`?run=`), and the run-detail `Drawer` (`?run_detail=`).

---

## 2. List screen — `WorkflowsView.vue`

Structure copied from `BotsView.vue`. **5.1 delta:** the trigger-type badge on a
row now has only two values.

### 2.1 PageHeader

- `PageHeader` `title = workflows.title`, `description = workflows.subtitle`,
  `icon="workflow"`.
- `#actions`: `Button leading-icon="plus"` → opens `?workflow=new`.

### 2.2 Persistent module-description Alert

Under the PageHeader, always visible: `Alert variant="info" size="sm"`
`workflows.list.moduleDescription` ("Workflows automate your process: a trigger
fires (a schedule or a form submission), optional conditions are checked, then
ordered steps run."). **5.1 delta:** copy now names the two trigger kinds.

### 2.3 FilterBar (with Saved Views — REQUIRED)

`FilterBar` with `v-model:search`, the Saved-Views `#top` slot, and controls:

| Control | Component | Server param | Notes |
| --- | --- | --- | --- |
| Search | built-in `v-model:search` | `?search=` | debounced 400ms. |
| Status | `Select` (single, clearable) | `?status=` | All / Active / Inactive. |

- Trigger-type is **NOT** a FilterBar control (the list endpoint accepts only
  `?search=` + `?status=`; a client filter would fight cursor pagination). It is
  shown as a **row badge** instead (§2.4).
- Deadline control: **N/A** (omitted).
- `SAVED_VIEWS_CONTEXT = 'workflows'`; snapshot serializes `{ search?, status? }`;
  chips = `workflows.filters.chip.search` / `.status`.

### 2.4 Row / card anatomy — `WorkflowCard.vue`

`EntityCard`, one per workflow in the grid
(`grid-cols-1 next-sm:grid-cols-2 next-xl:grid-cols-3`).

- `#leading`: icon bubble `bg-next-muted text-next-muted-foreground` showing the
  workflow's `icon` (fallback `workflow`); a `Spinner` while `opening`.
- Title = `name`; subtitle = `description` (or `workflows.card.noDescription`).
- `status` → StatusBadge via `workflowStatusMap` (`active`→success/subtle
  `check-circle`; `inactive`→neutral/subtle dot).
- **Metadata footer** (`#meta`):
  - Trigger-type badge: `variant="info" tone="subtle"` + the trigger icon (§7.3)
    + `workflows.trigger.<type>.short`. **5.1:** only `schedule` / `form_submitted`.
  - Step-count badge: `variant="neutral" tone="subtle" icon="list-checks"` →
    `workflows.card.stepCount` (`{count}`).
  - **Schedule only:** `next_due_at` badge `variant="neutral" tone="subtle"
    icon="calendar"` → `workflows.card.nextDue`, shown only when
    `trigger_type === 'schedule' && next_due_at != null`.
- `#actions` kebab `DropdownMenu` (disabled items stay visible + explanatory
  label): **Run now** (`arrow-right`, iff `can_run`), **Activate/Deactivate**
  (`check-circle`/`circle`, iff `can_change_status`), **Edit** (`pencil`, iff
  `can_be_edited`), **Delete** (`trash destructive`, iff `can_be_deleted`).
- Trailing-affordance order: conditional status badge before the permanent kebab;
  Run-now stays inside the menu (EntityCard enforces).

Card click → prefetch into the store, then navigate to `next.workflows.detail`.

### 2.5 The four states (list)

Identical to `BotsView.vue`: **Error** (`EmptyState variant="error"` + retry);
**Loading** (6 `EntityCard loading` skeletons); **Empty** (`variant="search"`
when filtered, else `variant="default" icon="workflow"` + first-run CTA →
`?workflow=new`); **Success** (grid + 3 trailing skeletons while `loadingMore` +
inline `Alert variant="danger"` load-more retry + infinite-scroll sentinel; cursor
8/page). Toasts on toggle/delete via `useToast`; delete guarded by `useConfirm`.

---

## 3. Detail view — `WorkflowDetailView.vue`

Read-only sub-view sectioned by `?section=` (default `overview`). Identity +
section nav in the aside (§1.3); content = slim right-aligned action bar + the
active section.

### 3.1 Slim action bar

- `Button variant="ghost" leading-icon="arrow-left"` → back to list.
- **Run now** (`arrow-right`, shown iff `can_run === true`) → `?run=<id>`.
- **Activate / Deactivate** (`check-circle`/`circle`, iff `can_change_status`,
  `:loading` while toggling).
- **Edit** (`pencil`, iff `can_be_edited`) → `?workflow=<id>`.

### 3.2 Overview section (`section === 'overview'`)

Read-only, in stacked `Surface bg="card" border elevation="sm" radius="lg"` panels.
No field/value dump — human-readable summaries. **5.1 rewrote the Trigger, Conditions
and Steps panels** for the two-trigger / two-step / typed world.

1. **Status panel:** StatusBadge + explanation. When `inactive`:
   `Alert variant="warning" size="sm"` `workflows.detail.inactiveExplanation`
   ("This workflow is inactive — its trigger will not fire automatically. You can
   still run it manually to test it.").
2. **Trigger panel** (`file-text`-style header): a human sentence from
   `trigger_type` + `trigger_config`, NOT raw JSON:
   - `form_submitted`: "When a submission is received for **<form name>**" (name
     resolved via the `FormSelect` seed mechanism; **"any form"** when `form_id`
     is null). Then two quiet sub-lines: **Source** — "manual + in-task" / "manual
     only" / "in-task only" (from `source.in`, or "any source" when null); and
     **Anonymous** — "any" / "only anonymous" / "only non-anonymous" (from
     `anonymous`, only when the form's `anonymous` flag is on — otherwise omit).
   - `schedule`: the cadence in words built by `describeSchedule(family, params,
     tz)` (§4.5.6 — the same descriptor-driven label the builder uses), e.g.
     "Twice daily at 09:00 and 17:00 (UTC)", "On the 15th of each month at 08:00",
     "Every 3 hours". Plus a **Next run** line (`next_due_at`) and
     `last_scheduled_run_at` when present.
3. **Conditions panel** (`git-branch` header): when `form_submitted` with
   conditions → an ordered read-only list, one row per condition: the field
   **label** (resolved from the catalog by `field` path; a mono chip fallback to
   the raw path when the catalog is unavailable) + operator word
   (`workflows.condition.operator.<op>`) + a **typed value chip** (a between shows
   "from → to"; an `in`/`includes`/`excludes` shows a chip per value; `is_true`/
   `is_false` render value-less). When empty →
   `workflows.detail.conditionsAlways` ("Runs on every submission — no extra
   conditions."). For `schedule` the panel is hidden (conditions are
   form_submitted-only). Resolving labels needs the catalog: if the panel renders
   before the catalog resolves, show the mono `field` path (never a blank).
4. **Steps panel** (`list-checks` header): an ordered read-only list. Each row:
   position badge + the **type** badge (icon + `workflows.step.<type>.label`,
   §7.4) + the step **`key`** as a mono chip + a one-line summary
   (`create_task` → its `title`; `create_form_report` → its `name`). Variable
   directives inside `title`/`name` render as inline **variable chips** using the
   catalog (name by path), NOT the raw `@[variable](…)` bytes — reuse
   `MarkdownViewer`'s variable rendering path for a faithful read-only echo. When
   the catalog is unavailable, degrade to the plain text with the directive
   stripped to its variable name.
5. **Runs summary:** a quiet line `workflows.detail.viewRuns` ("View run history
   →") switching `?section=runs`. The resource carries no runs-count field, so the
   UI invents none.

### 3.3 Detail states

- **Loading:** identity skeletons then two `Skeleton variant="rect"` panels.
- **Error:** `EmptyState variant="error"` + retry + `#secondary` back.
- **Empty:** N/A to Overview (a workflow always has a trigger + ≥1 step); Runs owns
  its own empty state (§5).

---

## 4. Editor drawer — `WorkflowEditorDrawer.vue` (create + edit)

### 4.1 Layout decision (carried forward)

**Single scrolling body with an always-visible GENERAL-INFO band on top + section
anchors — NOT a left module-nav.** A workflow is one linear pipeline (trigger →
conditions → steps) read top-to-bottom where later sections reference earlier ones;
the Approvals `PipelineBuilderDrawer` is the structural analogue.

- Hosted in `Drawer side="right" size="cover" :show-close=false` (editor owns its
  header + sticky footer).
- Keyed by id (`:key="editId ?? 'new'"`) → remount + re-seed per workflow. Seed
  local reactive state once from the prefetched detail via `clonePlain`;
  deep-link-without-prefetch → the shared `detailError` `EmptyState`.
- **Header:** slim, title only (`workflows.editor.createTitle` / `.editTitle`).
- **Sticky footer:** `Anuluj` (`Button variant="ghost"`) + `Zapisz`
  (`Button leading-icon="check" :loading`).

### 4.2 Form-row convention

Two-column labelled rows use `grid grid-cols-1 gap-next-4
next-sm:grid-cols-[12rem_1fr]` (the verified `12rem` label column from
`PipelineBuilderDrawer.vue`). Full-width controls (MarkdownEditor, repeaters, the
steps list) stack full-width.

### 4.3 General-info band (always visible, top)

- Row: **Name** (`TextInput`, required, `min-w-0 flex-1`) + **Icon** (`IconInput`,
  `next-sm:w-64`).
- **Description** (`Textarea rows=2`).

Labels `workflows.editor.nameLabel` / `.iconLabel` / `.descriptionLabel`. Status
is NOT in the form (created inactive, toggled via `PATCH /status`).

### 4.4 Trigger section — `WorkflowTriggerFields.vue` (REWRITTEN for 5.1)

A `<section>` under the info band.

- **Trigger type** = a **`SegmentedControl`** (not a Select) with exactly **two**
  options — `form_submitted` (icon `file-text`) and `schedule` (icon `calendar`),
  labels `workflows.trigger.<type>.label`. **Decision (changed from REV 1's
  Select):** with only two mutually-exclusive choices a SegmentedControl is the
  right control — both options visible at once, one tap to switch, `role=
  radiogroup` keyboard nav. Selects are for 3+ options; the Bot editor uses
  SegmentedControl for its own binary/small toggles, so this is consistent.
- **Type-change clearing:** switching type resets `trigger_config` to the new
  type's empty shape. Because the backend rejects cross-type keys, this is
  mandatory. When switching AWAY from a type that carried targeting, show an inline
  `Alert variant="warning" size="sm"` `workflows.editor.trigger.typeChangeWarning`
  ("Changing the trigger type clears the current trigger settings, conditions
  included."). **5.1 nuance:** switching from `form_submitted` to `schedule` also
  **clears conditions** (conditions are form_submitted-only) — the warning copy
  says so, and the conditions section disappears.

- **`form_submitted` panel:**

  | Field | Component | Wire key | Notes |
  | --- | --- | --- | --- |
  | Form | **`FormSelect`** (single, clearable) | `trigger_config.form_id` (uuid \| null) | **Single** picker — `null` = "any form". §4.4a below explains the consequence for conditions. |
  | Source | a **two-checkbox group** (or `PillGroupInput` multi) for `manual` / `task` | `trigger_config.source.in[]` (subset of `['manual','task']`) \| null | Labels `workflows.trigger.source.manual` ("Filled manually in Forms") / `.task` ("Filled during a task — incl. bots"). Empty selection ⇒ emit `source: null` (any). Helper note `workflows.trigger.source.hint` clarifies `task` is not bot-distinguishable. |
  | Anonymous | a **tri-state `SegmentedControl`** (`any` / `only_anonymous` / `only_non_anonymous`) | `trigger_config.anonymous` (bool \| null): `any`→null, `only_anonymous`→true, `only_non_anonymous`→false | **Shown whenever a form is selected** (the §9-gap-2 fallback is the PRIMARY behavior — neither FormSelect's option shape nor the workflow-catalog exposes the form's `anonymous` flag, so the FE cannot reliably know it; the backend accepts `anonymous` regardless and the flag only ever narrows matching). When `form_id` is null, hide the control and emit `anonymous: null`. OPTIONAL refinement, only if B7 finds the flag cheaply available (e.g. a form-detail fetch it already makes): hide the tri-state for a non-anonymous form. |

  - **§4.4a — form_id null ⇒ conditions disabled.** Field conditions read one
    form's answer map, so the backend rejects conditions when `form_id` is null.
    The UI enforces this **before** a 422: when `form_id` is null the Conditions
    section (§4.8) renders in a **disabled/empty state** with an explanatory
    `Alert variant="info" size="sm"` `workflows.condition.needsForm` ("Pick a form
    to add conditions on its fields.") and any previously-entered conditions are
    **cleared** on clearing the form (with a one-time toast
    `workflows.condition.clearedOnFormChange` when clearing actually dropped rows).
    Changing to a *different* form also clears conditions (their field paths belong
    to the old form's schema) with the same toast.

- **`schedule` panel:** the descriptor-driven builder + AI assist — see §4.5.

- **`trigger_config` wire shapes (exact keys the payload builder emits; the
  backend's cross-type `withValidator` 422s any foreign key):**

  | Type | Exact `trigger_config` keys |
  | --- | --- |
  | `form_submitted` | `form_id` (uuid \| null); `source.in[]` (or omit `source` entirely when "any"); `anonymous` (bool \| null). |
  | `schedule` | `schedule.family`, `schedule.params.<name>` (only the family's params), `schedule.tz` (optional; omit ⇒ server UTC). No `target`, no top-level keys. |

  > **Emit discipline:** emit `source` only when at least one of manual/task is
  > selected (an empty `source.in` is noise; omit ⇒ backend treats as "any").
  > Emit `anonymous` only when the tri-state is not `any`. Emit `tz` only when a
  > non-empty override is set.

### 4.5 Schedule builder (REWRITTEN in REVISION 3 — 16 families, simple/advanced modes, times/exclusions, live preview, AI assist)

Fed by `GET /workflows/meta/schedule-families` →
`{data:[{family, params:[{name, type, required, min?, max?, lt?}]}]}` (now 16
entries — `weekly`'s param is `weekdays`, type `weekday_list`, not a scalar
`weekday`). The FE owns **all** labels (backend sends none). The builder never
hard-codes a family's inputs — it renders from the descriptors, so it can never
drift from what the backend accepts. Component: `WorkflowScheduleBuilder.vue`.

The REVISION 2 tier-based single-mode picker (three quick-pick chips + one grouped
16-item `Select`, described in this section's earlier text) was superseded during
the schedule rebuild by a **two-mode** builder — see ADR-0010 §7 for why: a single
flat/grouped Select alone was judged not to scale to 16 items even with tiering,
and the growing optional surface (`times`, `exclusions`) needed a place to live
that a beginner never has to see.

#### 4.5.1 Simple mode (default) — five curated intents

A header `Button variant="ghost" size="sm"` toggle
(`workflows.schedule.advancedToggle` / `.simpleToggle`, `aria-pressed`) switches
between simple and advanced. **Simple is the default** and shows a
`SegmentedControl` of five intents (`workflows.schedule.intent.*`):

| Intent | Maps to family | Curated controls shown |
| --- | --- | --- |
| **Minutes** | `every_n_minutes` | One `NumberInput` (1–59), `workflows.schedule.simple.minutesLabel`. |
| **Hours** | `hourly_at` (N=1) or `every_n_hours` (N≥2) | One `NumberInput` for the interval (1–12) that SWITCHES the underlying family at the N=1/N≥2 boundary, plus an optional minute `NumberInput`. The user never sees the family switch — only "run every N hours". |
| **Daily** | `daily` | One `TimePicker`. |
| **Weekly** | `weekly` | Monday-first weekday chips (`aria-pressed` `Button`s, wire value stays `0=Sunday`) + one `TimePicker`. |
| **Monthly** | `monthly` or `last_day_of_month` | A `RadioGroup` ("a day of the month" vs. "the last day of the month") that switches family, a day `NumberInput` (monthly only), and one `TimePicker`. |

Simple mode always writes a SINGLE time (`times[0]`) — it has no times/exclusions
editors; those live in advanced mode only. `isSimpleRepresentable(draft)` decides
whether the CURRENT draft can be shown in simple mode's reduced controls (e.g. a
draft using `exclusions`, or a family with no simple intent, is NOT representable);
when the loaded draft isn't representable the builder forces advanced mode instead
of silently hiding configured state — the advanced-toggle `Button` is disabled
(with a `Tooltip`, `workflows.schedule.simpleUnavailable`) while a non-representable
draft is loaded, so the user cannot switch back to simple mode and lose it.

Simple and advanced modes write the SAME `ScheduleDraft` shape
(`{family, params, tz, times, exclusions}`) — simple mode is a curated VIEW over
the full model, never a separate schema (ADR-0010 §7).

#### 4.5.2 Advanced mode — four sections, descriptor-driven

Toggling to advanced reveals four sections, each rendering only the controls the
selected family's descriptors (plus the `times`/`exclusions` extensions) call for:

**Section 1 — Repeat** (`workflows.schedule.section.repeat`): a grouped family
`Select` (`workflows.schedule.moreLabel`) organized into tiers — Common
(`daily`/`weekly`/`hourly`), Intervals (`every_n_minutes`/`every_n_hours`/
`hourly_at`/`twice_daily`/`every_n_months`), Calendar (`monthly`/`twice_monthly`/
`last_day_of_month`/`nth_weekday_of_month`/`last_weekday_of_month`/
`last_working_day_of_month`/`quarterly`/`yearly`) — plus any family the backend
returns that isn't in a named tier falls into an `Other` group (defensive: the
tiers are UI curation over the descriptor list, never a filter on it). The
frequency params (`n`, `minute`) render here as `NumberInput`s.

**Section 2 — Days & dates** (`workflows.schedule.section.daysAndDates`, shown
only when the family has non-frequency params): renders one control per remaining
descriptor by `type`:

| Descriptor `type` | Control | Bounds / semantics |
| --- | --- | --- |
| `int` | **`NumberInput`** | `:min`/`:max` from the descriptor; `required` from `required`. Wire value = number. |
| `weekday` | **`Select`** of 0..6, **0 = Sunday** (Carbon convention), labels `workflows.schedule.weekday.<0..6>` | required. Wire value = number 0..6. Used by `nth_weekday_of_month`, `last_weekday_of_month`. |
| `weekday_list` | **Monday-first chips** (`aria-pressed` `Button`s toggling membership), wire value stays a `0..6` (0=Sunday) array | required, non-empty. Used ONLY by `weekly`'s `weekdays` param. |
| `ordinal` (the `nth_weekday_of_month` param, rendered specially) | **`Select`** 1st..5th (`workflows.schedule.ordinal.1-5`) | required, 1–5. |

- **`lt` invariant (client-side).** When a descriptor carries `lt: '<other>'`,
  the field must be **strictly less than** the named sibling. Enforce live:
  `twice_daily.first_hour < second_hour`, `twice_monthly.first_day < second_day`.
  Show `workflows.schedule.validation.lt` (`{field}`, `{other}`) on the offending
  field and block save — never let the 422 be the first the user hears of it.
- **Semantic helper text** (a quiet `Alert size="sm"`, never noise) — one per edge
  case the family/param combination can hit:
  - `monthly`/`quarterly`/`yearly`/`every_n_months` with `day ∈ 29..31`:
    `workflows.schedule.help.dayMayskip` + a `Button variant="link"`
    (`workflows.schedule.help.switchToLastDay`) that switches the family to
    `last_day_of_month`.
  - `yearly` with `month = 2 && day = 29`: `workflows.schedule.help.leapDay`.
  - `every_n_hours`: `workflows.schedule.help.hourModulo`.
  - `every_n_months` when `12 % n !== 0`: `workflows.schedule.help.everyNMonths`
    ("the month grid counts from January and resets at the turn of the year").
  - `nth_weekday_of_month` with `ordinal = 5`: `workflows.schedule.help.fifthWeekday`.
  - `last_working_day_of_month`: `workflows.schedule.help.lastWorkingDay`
    (public holidays are not taken into account).
  - Any non-blank `tz`: `workflows.schedule.help.dstNote` (a clock-change note —
    non-existent times shift forward, repeated times run twice).

**Section 3 — Times** (`workflows.schedule.section.times`): for a family with a
`time` descriptor, a repeatable list of 1–6 `TimePicker`s (`+` `Button`
`workflows.schedule.times.add` disabled at 6, per-row `x` `Button`
`workflows.schedule.times.remove` disabled at 1) writing `ScheduleDraft.times`
(the wire's `schedule.times`, sent instead of `params.time` when there is more
than one — see the backend's mutual-exclusion rule in `docs/backend/workflows-
api.md`). For a family with no `time` param (the interval families), the section
shows an info line instead (`workflows.schedule.times.selfPaced`) — "this cadence
sets its own rhythm, so it has no fixed time of day."

**Section 4 — Exclusions** (`workflows.schedule.section.exclusions`, ALWAYS shown
in advanced mode, on every family): a hint line
(`workflows.schedule.exclusions.hint`), then three independent skip filters,
all optional:
- **Months** — 12 chips (`workflows.schedule.exclusions.monthsLabel`), toggle
  membership.
- **Weekdays** — the same Monday-first 7 chips pattern as `weekday_list`
  (`workflows.schedule.exclusions.weekdaysLabel`).
- **Dates** — a `DatePicker` + `Button` `workflows.schedule.exclusions.addDate`
  building a removable chip list (`workflows.schedule.exclusions.datesEmpty` when
  none), each chip a monospace `Y-m-d` string with a remove `x` `Button`
  (`workflows.schedule.exclusions.removeDate`).

**Timezone** (advanced mode only): an optional `TextInput`
(`workflows.schedule.tzLabel`, placeholder `workflows.schedule.utc`, helper
`workflows.schedule.tzHint`). No timezone-picker component exists; a text field
matching the backend default is the pragmatic MVP (carried from REV 1). The
user's **active timezone** is sent to the assist endpoint as the `tz` hint
(§4.5.4) even when this field is blank.

#### 4.5.3 Live preview (both modes, always visible)

A dedicated card at the bottom of the builder (`workflows.schedule.section.preview`,
a `calendar` icon heading), present in BOTH simple and advanced mode:

1. **The natural-language sentence** — `describeSchedule(config, t)` (§4.5.6),
   always current, recomputed on every draft change (no network call).
2. **The next-occurrences list** — `POST /workflows/meta/schedule-preview`
   (`store.schedulePreview(config, 6)`), **debounced 400ms**, fired only when the
   draft passes CLIENT-SIDE validation (no wasted round-trips on an
   incomplete/invalid draft — `schedulePreviewRefresh()` cancels/clears
   in-flight state the moment the draft becomes invalid). Skeleton rows while
   loading (`workflows.schedule.preview.loading`).
3. **State handling**, in priority order:
   - `empty: true` (the `exclusions` rule out every occurrence) →
     `Alert variant="warning"` (`workflows.schedule.preview.empty`) — treated as a
     CLIENT VALIDATION ERROR: the builder's exposed `isValid` becomes `false` and
     save is blocked, because the server would reject an equally-empty schedule
     with a 422 anyway (the write path keeps its empty-schedule guard ON; only
     the preview endpoint turns it off — see `docs/backend/workflows-api.md`).
   - `approximate: true` (only `every_n_minutes`) →
     `Alert variant="info"` (`workflows.schedule.preview.approximate`) — the
     dates are indicative because the real phase is set at activation, not "now".
   - A network/parse failure on the preview call → QUIET, non-blocking:
     `workflows.schedule.preview.unavailable` as plain muted text; the sentence
     stays, the schedule is still savable (a preview outage never blocks the
     builder).
   - Otherwise → the occurrence list, each row formatted in the draft's own tz
     (`occurrenceFormatter`/`formatOccurrence`, shared with the AI-assist's
     alternative preview, §4.5.5).

#### 4.5.4 AI assist affordance — placement

A **secondary control at the TOP of the schedule panel**, above the mode toggle:
a collapsed row `workflows.schedule.assist.prompt` with a `sparkles`-icon
`Button variant="outline" size="sm"` `workflows.schedule.assist.open` ("Describe
it in words"). Opening it reveals an inline composer (a `Textarea`
`maxlength=500` + a `Button leading-icon="sparkles" :loading`
`workflows.schedule.assist.run`) and moves focus into the `Textarea` on expand
(a B7-review fix — see the REVISION 2 banner above). **It is a BUILDER AID, never
a submit path** — it *prefills* the family + params below; the user then
reviews/edits and the normal Save re-validates. Placing it above the builder (not
replacing it) makes the "assist → review → save" flow read top-to-bottom.

#### 4.5.5 AI assist — request

`POST /workflows/schedule-assist { prompt (≤500), tz? }`. Always send the user's
**active timezone** as `tz` (resolved via `Intl.DateTimeFormat().resolvedOptions()`
when the host doesn't pass one — no app-level tz source exists yet; the assist
merges it into a surviving config). Throttle: 5/min/user → 429.

#### 4.5.6 AI assist — the four response states (design each)

Envelope: `{feasible, config|null, unsupported: string[], alternative:{config,
note}|null, explanation}`. **All model text (`explanation`, `unsupported[]`,
`note`) renders as PLAIN TEXT — never HTML/markdown** (escape it; it is untrusted
model output).

| State | Condition | UI |
| --- | --- | --- |
| **(a) Feasible** | `feasible && config` | Apply `config` to the builder (`configToDraft` — the §8.4 canonical helper — maps `{family, params, tz, times?, exclusions?}` onto the family picker + param controls + tz), and show a success `Alert variant="success" size="sm"` with the plain-text `explanation`. The user can still edit before saving. |
| **(b) Infeasible + alternative** | `!feasible && alternative` | Show `Alert variant="warning" size="sm"` with `explanation`; a bulleted **unsupported** list (`workflows.schedule.assist.unsupportedTitle` + one `<li>` per plain-text string); AND (schedule-rebuild addition) a **preview of the alternative BEFORE the user applies it** — a bordered sub-card (`workflows.schedule.assist.alternativePreviewTitle`) showing the deterministic `describeSchedule` sentence for `alternative.config`, the model's plain-text `alternative.note`, and the alternative's next 4 occurrences (its own `store.schedulePreview(alternative.config, 4)` call, independently debounced/token-guarded, quietly dropping the list on a network error while keeping the sentence+note). A `Button variant="outline" size="sm" leading-icon="sparkles"` `workflows.schedule.assist.useAlternative` applies `alternative.config` to the builder and shows `alternative.note` as a plain-text caption under the builder (`workflows.schedule.assist.appliedNote`). The user therefore sees exactly what they'd get BEFORE committing to it, not only after applying. |
| **(c) Infeasible, no alternative** | `!feasible && !alternative` | `Alert variant="warning" size="sm"` with `explanation` + the unsupported list. The manual builder stays as-is (untouched). |
| **(d) Failure / throttle** | HTTP 429, or network/parse failure | `Alert variant="danger" size="sm"` with FE-owned copy `workflows.schedule.assist.throttled` for 429, else `workflows.schedule.assist.failed`. **Never surface the raw backend message.** The composer stays open so the user can retry or fall back to the builder. Keep the `Button :loading` width stable (Button loading contract). |

- After any apply, the builder's own client validation (§4.5.2 bounds + `lt` +
  the §4.5.3 empty-preview check) re-runs, so an applied config the user then
  edits into an invalid state is caught before save exactly like a hand-built one.

#### 4.5.7 Read-side helper — `describeSchedule(config, t)`

A pure FE helper (in `workflowSchedule.ts`) producing the human cadence sentence
from the descriptor vocabulary, reused by the detail Trigger panel (§3.2), the
live preview (§4.5.3), and the AI-assist's alternative preview (§4.5.6(b)). It is
descriptor-driven (weekday index → localized name, time → as-is, `n`/`day`/`month`
interpolated) so adding a family later needs one label, not new rendering code.
The schedule-rebuild widened its signature from `(family, params, tz)` to
`(config, t)` (the full `ScheduleConfig`, including `times`/`exclusions`) so it can
append an optional TIME CLAUSE (`workflows.schedule.describe.timeClause`, when
`times.length > 1`) and an optional EXCLUSION CLAUSE
(`workflows.schedule.describe.exclusionClause`, a joined plain-language list) to
the base per-family sentence — e.g. "Weekly on Mon, Wed, Fri at 08:00 and 17:00
(Europe/Warsaw) except: weekends".

### 4.6 Steps editor — `WorkflowStepListEditor.vue` + `WorkflowStepCard.vue` (REWRITTEN for 5.1)

Reuses the Approvals ordered-stage pattern: an `<ol>` of step cards, ▲▼ reorder,
X-before-chevron trailing order, min 1 step, per-index 422 mapping. Not
drag-and-drop, not a canvas.

- **Add step:** a `DropdownMenu` type-picker (`Button variant="outline" size="sm"
  leading-icon="plus"` → two items: `create_task` (icon `plus`) /
  `create_form_report` (icon `file-text`), each `workflows.step.<type>.label`).
  Choosing the type yields a correctly-shaped empty card immediately.
- **Per-step card header** (each an `<li>`, keyed by a stable local `uid`):
  position badge + type badge + trailing controls in rule order — conditional
  remove `Button size="icon-xs" leading-icon="x"` (only when > 1 step) **before**
  the permanent `chevron-up`/`chevron-down` (disabled at the ends).
- **`key` field** (`TextInput` mono, required, `workflows.step.keyLabel`, helper
  `workflows.step.keyHint` "Used to reference this step's output as
  `steps.<key>.…`"). Client-validate uniqueness (`.keyDuplicate`) + non-empty
  (`.keyRequired`). **5.1:** the step's `key` is what the FE substitutes into
  step-output variable refs (the catalog gives `steps.<TYPE>.<name>` templates;
  the FE swaps `<TYPE>` → the user's `<key>` — see §4.7).

#### 4.6.1 `create_task` card — field by field

Config keys (allow-list from `StoreWorkflowRequest::allowedStepKeys`): `title`,
`description`, `priority`, `deadline`, `labels`, `assignee_type`, `assignee_id`,
`form_id`, `approval_pipeline_id`.

| Field | Control | Variable support | Wire |
| --- | --- | --- | --- |
| **title** (required, one line) | **`MarkdownEditor`** with `:variables` from the catalog, `hideToolbar`, `minHeight` = one line — see §4.6.3 for the "one-line editor" decision. | Full variable chips (identity-only directives). | string with directives. |
| **description** | **`MarkdownEditor`** + `:variables` (full toolbar). | Full variable chips. | markdown string. |
| **priority** | **`ValueOrVariableField`** (§4.9): a `Select` over `low\|medium\|high\|urgent` OR a picked enum/text-typed variable. | value-or-variable. | `{kind:'literal', value} \| {kind:'variable', ref}` (bare enum string also accepted as literal). |
| **deadline** | **`DateOrVariableField`** (§4.9): `DatePicker` OR a date-typed variable. | date value-or-variable. | `{kind:'literal', value} \| {kind:'variable', ref}`. |
| **labels** | **`LabelSelect`** (multi, `addable=false`) | literal ids only (no variable). | `string[]` of label ids. |
| **assignee** | a **`SegmentedControl`** `user`/`bot` + a **`UserSelect`** / **`BotSelect`** (swapped by the segment) | literal id only. Pickers scope to the workspace **client-side**. | `assignee_type ∈ {user,bot}` + `assignee_id` (uuid) **both-or-neither** — when the assignee is left empty, emit **neither** key. |
| **form_id** | **`FormSelect`** (single, optional) | literal id. | uuid \| omit. |
| **approval_pipeline_id** | **`PipelineSelect`** (single, optional) | literal id. | uuid \| omit. |

- **Assignee asymmetry flag (surface, don't hide):** the backend accepts any
  well-formed uuid and no-ops a dangling assignee at run time, so the picker's
  client-side workspace scoping is a *convenience*, not a guarantee. This is fine
  (the server is authoritative on real security via other paths), but B7 must not
  assume the picker's list is exhaustive — a seeded id that is not in the current
  workspace list should still render its seed label, not vanish.
- **Both-or-neither assignee:** the card's assignee sub-control emits `{assignee_type,
  assignee_id}` only when a target is picked; clearing the picker drops **both**
  keys (the FormRequest requires them together). Surface the client rule so the user
  can't half-fill it.

Outputs (for the reference/variable system): `steps.<key>.task_id`,
`steps.<key>.title`.

#### 4.6.2 `create_form_report` card — field by field

Config keys (allow-list): `form_id`, `name`, `guidelines`, `sources`,
`submissions_from`, `submissions_to`. **Mirrors the interactive form-report form.**

| Field | Control | Variable support | Wire |
| --- | --- | --- | --- |
| **form_id** (required) | **`FormSelect`** (single) | literal id. | uuid. |
| **name** (required) | **`MarkdownEditor`** one-line + `:variables` (§4.6.3). | full variable chips. | string with directives. |
| **guidelines** (optional) | **`MarkdownEditor`** + `:variables` (full toolbar). | full variable chips. | markdown string \| omit. |
| **sources** | a **two-checkbox group** (`task` / `form`) | literal. | `sources[]` subset of **`['task','form']`** — **NOTE the report vocabulary is `task`/`form`, NOT `manual`/`task`** (verified in `StoreFormReportRequest`: `Rule::in(['task','form'])`). Labels `workflows.step.report.source.task` / `.form`. Empty ⇒ omit `sources`. |
| **submissions_from / submissions_to** | **`DateOrVariableField`** each (§4.9), **OPTIONAL** | date value-or-variable. | `{kind:…}` \| omit. |

- **Optional-with-implicit-defaults copy:** present `submissions_from` /
  `submissions_to` as optional and explain the server defaults via
  `FormField` descriptions: `workflows.step.report.fromHint` ("Defaults to the
  form's enable date.") / `.toHint` ("Defaults to today."). This mirrors
  `StoreFormReportRequest::prepareForValidation` (from = `enabled_at`, to = today).
  Do not pre-fill these controls with a computed date (the default is server-side);
  leaving them blank is the correct "use default" state.

Outputs: `steps.<key>.report_id`, `steps.<key>.report_name`.

#### 4.6.3 The "one-line editor" decision (title / report name)

`title` and report `name` are **single-line** yet must accept variable directives.
Two controls were weighed:

- (a) a plain `TextInput` + a separate variable-insert affordance (REV 1's
  reference popover), or
- (b) the **`MarkdownEditor`** constrained to a single line (`hideToolbar`,
  `minHeight` ≈ one row, no block features used).

**Decision: (b) — a constrained `MarkdownEditor`.** Justification: the variable
system's whole point is the **chip** interaction (type `{`, fuzzy-pick from the
catalog, chip renders with its type icon). A TextInput cannot render chips, so a
TextInput path would need a parallel token-insert UI *and* would show raw
`@[variable](…)` bytes inline — worse UX and a second pattern to maintain. The
MarkdownEditor already supports exactly this via `:variables`, and its `hideToolbar`
+ small `minHeight` make it read as a single-line field. The backend accepts "any
string with directives", so a one-line editor's serialized markdown is valid. B7
constrains height/toolbar; it does **not** hard-block Enter (a stray newline in a
title is harmless and the backend trims), but the field is styled to one row.

### 4.7 Variable wiring — the MarkdownEditor variable extension (REWRITTEN — replaces REV 1's reference popover)

**The `{{…}}` reference popover of REV 1 is DELETED.** 5.1 uses the existing
editor variable extension.

- **Catalog fetch.** When the trigger is `form_submitted` **and** a `form_id` is
  selected, fetch `GET /forms/{form}/workflow-catalog` →
  `{data:{variables:[{source, path, name, type, enumOptions?, nullable?}],
  fields:[…]}}`. Cache per form id in the editor store. The `variables` array feeds
  **every** variable-capable control on this workflow (all step editors + the
  add-on fields). When the trigger is `schedule`, there is no form; the only
  variables are the trigger's `scheduled_at` + the step outputs (§4.7.3) —
  assembled FE-side from the static trigger-system list + the live step list (no
  catalog HTTP call is possible without a form, and none is needed).
- **Feeding the editor.** Pass `:variables="{ variables: <catalogVariables>,
  operationsCatalog: [] }"` to each `MarkdownEditor`. **Pipeline operations are a
  non-goal this batch** (§7.9) → `operationsCatalog` is empty, so the variable
  panel shows the chosen variable with **no** transform pipeline (the chip is a
  pure reference). This is a deliberate scope cut: the editor supports pipelines,
  but 5.1 wires none.
- **Identity-only directive (the key nuance).** The editor's `variable` directive
  is **identity-only** — it stores `data.id` (= the variable's `path`) and a
  degraded editor primitive `data.type ∈ {text,number,boolean}`; it carries **no**
  workflow type. The FE **recovers the real workflow type from the catalog by
  path**. So date/enum/multi variables serialize with `data.type: 'text'` inside
  the directive and are re-typed from the catalog on read. B7 must map a catalog
  variable → a `VariableDefinition { id: path, name, type: editorPrimitive(type) }`
  when feeding `:variables`, and re-resolve the true type from the catalog wherever
  the true type matters (the add-on fields, the read-side chip rendering).

#### 4.7.1 The catalog→editor adapter (`workflowVariables.ts`, new)

A pure module mapping the catalog to the shapes each consumer needs:

- `toEditorVariables(catalogVariables, steps, positionIndex)` →
  `VariableDefinition[]` for a `MarkdownEditor`: system + field variables as-is
  (id = path, type = `editorPrimitive`), plus **position-scoped step outputs**
  (§4.7.3). Enum/multi/date collapse to their editor primitive here (real type
  stays recoverable by id).
- `resolveVariableType(catalog, path)` → the true `WorkflowVariableType` for a
  path (used by the add-on value-or-variable fields to filter which variables are
  offered, and by read-side rendering).

#### 4.7.2 Position scoping

A step's variable-capable fields may reference **the trigger + EARLIER steps
only**. The adapter takes the current step's `positionIndex` and includes step
outputs only for steps `0..positionIndex-1`. A field outside any step (there are
none in 5.1 — conditions use the typed builder, not variables) would see trigger
vars only.

#### 4.7.3 Step-output KEY substitution

The catalog lists step outputs as **`steps.<TYPE>.<name>`** templates
(`steps.create_task.task_id`, `steps.create_task.title`,
`steps.create_form_report.report_id`, `steps.create_form_report.report_name`).
When offering an earlier step's outputs, the adapter **substitutes the user's
actual step `key`** for `<TYPE>` → the inserted/stored ref path is
`steps.<key>.<name>`. A keyless earlier step contributes no outputs yet (skip it
until it has a key). This mirrors the existing `workflowEditorModel.referenceGroupsAt`
KEY-substitution logic (which B7 keeps but re-points at the catalog's real output
names, dropping the old hand-written `STEP_OUTPUT_SUFFIXES` in favor of the catalog).

### 4.8 Conditions builder — `WorkflowConditionsEditor.vue` (REWRITTEN — typed, schema-driven)

A `<section>` shown **only for `form_submitted`** (hidden for `schedule`), and
**disabled until a form is selected** (§4.4a). Each condition is a **typed**
clause `{field: 'fields.<id>', field_type, operator, value}` driven by
`workflow-catalog.fields`.

- **Disabled-until-form state.** When `form_id` is null: render the section header
  + an `Alert variant="info" size="sm"` `workflows.condition.needsForm` and a
  disabled "Add condition" button. No rows.
- **When a form is selected:** an add/remove repeater (unordered AND-set; no
  reorder). "Add condition" `Button variant="outline" size="sm" leading-icon="plus"`.
  Each row is three controls that cascade:

  1. **Field** `Select` over `catalog.fields` (option = field `label`; value =
     field `path` `fields.<id>`). Picking a field sets `field_type` from the
     descriptor (`field.type`) and resets `operator` + `value`.
  2. **Operator** `Select` scoped to the field type's operator set (from
     `field.operators`, which equals `WorkflowVariableType.operators()` — verified).
     Labels `workflows.condition.operator.<op>` for all 18 operators:

     | field_type | operators (tokens) |
     | --- | --- |
     | `text` | `equals`, `not_equals`, `contains` |
     | `number` | `eq`, `neq`, `gt`, `gte`, `lt`, `lte` |
     | `date` | `before`, `after`, `on`, `between` |
     | `enum` | `is`, `is_not`, `in` |
     | `multi` | `includes`, `excludes` |
     | `boolean` | `is_true`, `is_false` |

  3. **Value** — the control depends on `(field_type, operator)`:

     | Case | Control | Wire `value` |
     | --- | --- | --- |
     | text `equals/not_equals/contains` | `TextInput` | string |
     | number `eq/neq/gt/gte/lt/lte` | `NumberInput` | number |
     | date `before/after/on` | `DatePicker` | ISO date string |
     | date `between` | **two** `DatePicker`s (from / to) | `[from, to]` (2 dates) |
     | enum `is/is_not` | `Select` over `field.enumOptions` | string |
     | enum `in` | **multi** `Select` over `field.enumOptions` | `string[]` (non-empty) |
     | multi `includes/excludes` | `Select` over `field.enumOptions` (single option to test membership) | string |
     | boolean `is_true/is_false` | **no value control** | value omitted |

- **Missing-path hint (subtle, not noise).** A single quiet `FormField`-level or
  section-footer note `workflows.condition.missingPathHint` ("If a submission
  doesn't include this field, the condition fails — except *is not* / *not equals*
  / *excludes*, which pass."). One note per section, not per row.
- **Empty list is valid** ("always runs"). Rows with an empty field are dropped on
  submit.
- **422 mapping:** `conditions.<i>.field` / `.operator` / `.value` → the matching
  row; `trigger_config.form_id` (the "form required when conditions present" error)
  → surfaced on the FormSelect in the trigger panel + scroll it into view.

### 4.9 Non-text variable add-ons — interaction spec (NEW)

Three small field wrappers give non-text fields the **same "or a variable" power**
the editor chips give text, echoing the chip's look/feel (type icon + token-styled
pill). Wire shape everywhere:
`{kind:'literal', value} | {kind:'variable', ref:{source, path, type}}` — bare
scalars are also accepted as literals (the backend's `validateUnionOrLiteral`).

#### 4.9.1 `ValueOrVariableField` (priority; and enum-typed select values)

- **Default (literal) mode:** the field's native literal control (for `priority`, a
  `Select` over `low\|medium\|high\|urgent`).
- **A trailing toggle** — a `Button size="icon-xs" variant="ghost"` with the
  `braces` icon (the same glyph the editor variable insert uses) — flips the field
  to **variable mode**: the literal control is replaced by a **variable picker**
  (a `Select` whose options are the catalog variables **filtered to compatible
  types**; for `priority` that is `enum`/`text` variables). The picked variable
  renders as a **chip** styled like `VariableChip` (type icon + name) with an ✕ to
  return to literal mode.
- **State:** `{ kind: 'literal', value }` by default; picking a variable sets
  `{ kind: 'variable', ref: { source, path, type } }` (source/type from the
  catalog variable; `type` is the TRUE workflow type, not the editor primitive).
- **a11y:** the toggle is a real `Button` with `aria-pressed` + an aria-label
  (`workflows.field.useVariable` / `.useLiteral`); the variable `Select` carries a
  label; the chip's ✕ is a `Button size="icon-xs"`.

#### 4.9.2 `DateOrVariableField` (deadline; report windows)

Identical pattern, literal mode = a **`DatePicker`**; variable mode filters the
catalog to **`date`-typed** variables (`trigger.scheduled_at`,
`trigger.submitted_at`, any date form field). Chip identical.

#### 4.9.3 `ConditionalSelectField` verdict

**Verdict: do NOT build a separate `ConditionalSelectField`.** The accepted plan
floated "a select whose value is conditioned on variables"; with the 5.1 step set
the only realistic case is **an enum-typed value that may instead be a variable**
(priority; an enum condition value). That case is fully covered by
`ValueOrVariableField` §4.9.1 driving a `Select` in literal mode. A third
component would be redundant surface. **Rule kept simple:** value-or-variable is
one pattern (`ValueOrVariableField`) parameterized by its literal control
(`Select` for enums, `DatePicker` handled by `DateOrVariableField`). No new
component beyond the two add-ons.

### 4.10 Validation & 422 surfacing (updated keys)

Client-side pre-validation mirrors the FormRequest: name required; ≥1 step; each
step key required + unique; `create_task.title` non-empty; `create_form_report`
requires `form_id` + `name`; schedule param bounds + `lt`; conditions typed per
field. On submit, build the exact payload and map a 422 onto per-field errors,
including nested keys:

- `name`, `trigger_type` → info/trigger.
- `trigger_config.form_id`, `trigger_config.source.in`, `trigger_config.anonymous`
  → the form_submitted panel.
- `trigger_config.schedule.family`, `trigger_config.schedule.params.<name>`,
  `trigger_config.schedule.tz` → the matching schedule control (scroll into view).
- `conditions.<i>.field` / `.operator` / `.value` → the matching condition row.
- `steps.<i>.key` / `steps.<i>.config.<field>` (incl. the union sub-keys
  `steps.<i>.config.priority.ref.path` etc.) → the matching step card by index
  (the Approvals `stages.<i>.<field>` regex approach), scrolled into view.
- Any un-mappable 422 → a translated danger toast (`workflows.editor.toasts.error`).

Success → `useToast` success (`.created` / `.updated`), emit `saved`, close; the
store reconciles the list (prepend on create, replace on update).

---

## 5. Runs view — `WorkflowRunsView.vue` (`?section=runs`)

*(Carried forward from REV 1 — the runs contract was NOT re-scoped by 5.1. The one
note: `origin: 'event'` now corresponds to a form-submission-triggered run since
event triggers are gone; the FE keeps the three-origin filter and labels `event`
via `workflows.runs.origin.event`. Flagged in §8 — no invented field.)*

### 5.1 Saved-Views exemption

The Runs list does **NOT** carry a FilterTabBar / Saved Views (it is a
detail-nested, entity-scoped list — the Queue/inbox precedent).

### 5.2 Filters (segmented, not FilterBar)

Two `SegmentedControl`s drive `?state=` + `?origin=`:
- **State:** All / pending / running / waiting / completed / failed / cancelled
  (`workflows.runs.state.*`); reserved states (`waiting`, `cancelled`) render
  greyed at zero.
- **Origin:** All / event / schedule / manual (`workflows.runs.origin.*`).
Plus a manual Refresh `Button variant="outline" size="sm" leading-icon="rotate-ccw"`.

### 5.3 Run row anatomy — `WorkflowRunRow.vue`

Cursor list, 15/page. Each row: state badge (6-state, §5.5), origin badge,
trigger-type chip (`hidden next-sm:inline-flex`; only `form_submitted`/`schedule`
now), steps count (`list-checks` + `steps_count`), started (relative) + duration
(or "—"), error preview (failed, one line, `text-next-danger`), nested badge when
`depth > 0` (`git-branch` + `{depth}`). Row click → run-detail drawer.

### 5.4 Run detail — `WorkflowRunTimeline.vue` (right-side `Drawer size="lg"`, `?run_detail=`)

- **Header:** state badge + origin + started/finished + duration.
- **Trigger payload:** `DescriptionList` key→value from `trigger_payload` (mono
  keys), not raw JSON.
- **Step timeline:** shared `Timeline` (`ui/patterns/Timeline.vue`), one item per
  audit step ordered by `position`: node icon toned by `status_tone`; title = step
  **type** label + the **`key`** mono chip; status badge (`status_label` /
  `workflows.runs.stepStatus.*`); payload as key→value rows; `error` in a `danger`
  `Alert size="sm"`.
- **States:** loading → skeleton timeline items; error → inline `Alert` + retry;
  empty → `EmptyState size="sm"` `workflows.runs.detail.emptySteps`.

### 5.5 Run states — full 6-state badge map

| state | Badge variant · tone | icon | i18n key | `state_tone` |
| --- | --- | --- | --- | --- |
| `pending` | neutral · subtle | `clock` | `workflows.runs.state.pending` | neutral |
| `running` | info · subtle | `loader` | `workflows.runs.state.running` | info |
| `waiting` (reserved) | warning · subtle | `clock` | `workflows.runs.state.waiting` | warning |
| `completed` | success · subtle | `check-circle` | `workflows.runs.state.completed` | success |
| `failed` | danger · solid | `x-circle` | `workflows.runs.state.failed` | danger |
| `cancelled` (reserved) | neutral · subtle | `x` | `workflows.runs.state.cancelled` | neutral |

FE derives `variant` from `state_tone`; icon from this fixed map by `state`.

### 5.6 Runs list four states

error+retry (`Alert`); loading (row-shaped skeletons); empty (per-filter vs
first-run message, `workflows.runs.empty.*`); success (rows + infinite-scroll +
retryable append), via `useInfiniteScroll`.

---

## 6. Run-now flow — `TargetPickerModal.vue` (`?run=<id>`) (REWRITTEN for 5.1)

A `Modal` hosted by the module layout, opened from the row menu + the detail
action bar. **5.1 delta:** only two trigger types, only two 422 bag keys.

### 6.1 Per-trigger-type target picker

| Trigger type | Target | Control |
| --- | --- | --- |
| `form_submitted` | a **FormSubmission id** | a **`TextInput`** (`workflows.run.submissionIdLabel`, helper `.submissionIdHint` "Paste the id of the submission to run against."). **No submission picker exists** — a light id field is the honest MVP (carried from REV 1; flagged §8). |
| `schedule` | **none** | confirm-only; the modal body shows only the confirm copy. |

The task/approval target controls of REV 1 are **DELETED** (no task/approval
triggers exist).

### 6.2 Inactive workflow = "test run" framing

When the workflow is `inactive`: a leading `Alert variant="warning" size="sm"`
`workflows.run.testRunNote` ("This workflow is inactive. Running it now is a
one-off test — it will not activate the workflow."), and the confirm label becomes
`workflows.run.testRunConfirm` ("Test run") instead of `.confirm` ("Run now").

### 6.3 Submit + 422 surfacing (updated bag)

`POST /workflows/{id}/run { target_id? }` → **202** returns the created run. On
success: toast `workflows.run.toasts.started`, close, and (if on the Runs section)
refetch. **422 mapping — the bag has EXACTLY two keys now:**

- **`target_id`** → the id field error. Message-disambiguated:
  `workflows.run.errors.targetRequired` (missing/empty) vs
  `.targetNotFound` (foreign/unknown). *(The REV 1 `approval_process` /
  `noConcludedApproval` handling is DELETED — that key no longer exists.)*
- **`workflow`** → `workflows.run.errors.capReached` (cap reached).
- The FE mapper must **drop `approval_process`** (gone) and read both remaining
  keys; an unrecognized 422 → `workflows.run.errors.generic`.

Surface all as an inline `Alert variant="danger"` in the modal + a danger toast.
Confirm `Button leading-icon="arrow-right" :loading` (stable width, disabled while
pending).

---

## 7. i18n, icons, dark mode, a11y, non-goals

### 7.1 i18n namespace delta (en.ts + pl.ts parity)

Top-level `workflows.*`. **5.1 changes** (added / removed keys vs REV 1):

```
workflows.title / subtitle
workflows.module.*            selectHint, allWorkflows
workflows.list.*             moduleDescription        (copy names the 2 triggers)
workflows.filters.*          search, status(.all/.active/.inactive), clearAll,
                             chip.search, chip.status
workflows.card.*             open, noDescription, stepCount, nextDue
workflows.status.*           active, inactive
workflows.actions.*          run, edit, delete, activate, deactivate, menu,
                             runDisabled, *DisabledOwner
workflows.empty.* / errors.* / confirm.*             (unchanged)
workflows.detail.*           back, errorTitle, errorDescription, tabOverview,
                             tabRuns, viewRuns, inactiveExplanation, conditionsAlways
workflows.trigger.*          [CHANGED] <type>.label/.short for ONLY
                             {form_submitted, schedule};
                             source.manual/.task/.hint,
                             anonymous.any/.onlyAnonymous/.onlyNonAnonymous,
                             summary.*        [REMOVED] task_created/task_status_changed/
                             approval_finished labels + outcome.* + taskStatus.*
workflows.schedule.*         [REWRITTEN — schedule rebuild, REVISION 3]
                             utc, loading, loadError, retry, emptyTitle/.emptyDescription,
                             quickPickLabel, moreLabel, familyPlaceholder,
                             tzLabel, tzHint, and, advancedToggle, simpleToggle,
                             simpleUnavailable,
                             mode.simpleLabel,
                             intent.minutes/.hours/.daily/.weekly/.monthly,
                             simple.minutesLabel/.minutesUnit/.hoursIntervalLabel/
                             .hoursUnit/.hoursMinuteLabel/.dailyLabel/.weeklyDaysLabel/
                             .weeklyTimeLabel/.monthlyModeLabel/.monthlyOnDay/
                             .monthlyLastDay/.monthlyDayLabel/.monthlyTimeLabel,
                             section.repeat/.daysAndDates/.times/.exclusions/.preview,
                             tier.common/.intervals/.calendar/.other,
                             family.<16 families — 4 NEW: every_n_months,
                             nth_weekday_of_month, last_weekday_of_month,
                             last_working_day_of_month>,
                             param.n/.minute/.time/.first_hour/.second_hour/
                             .weekday/.weekdays/.ordinal/.day/.first_day/.second_day/.month,
                             ordinal.1-5, weekday.0-6, weekdayShort.0-6, month.1-12,
                             times.heading/.add/.remove/.empty/.selfPaced,
                             exclusions.monthsLabel/.weekdaysLabel/.datesLabel/.addDate/
                             .removeDate/.datesEmpty/.hint,
                             validation.required/.number/.min/.max/.lt/
                             .weekdayListRequired/.weekdayListDuplicate/.weekdayListRange/
                             .timesRequired/.timesMax/.timesFormat/.timesDuplicate/
                             .exclusionsMonths/.exclusionsMonthsMax/.exclusionsWeekdays/
                             .exclusionsWeekdaysMax/.exclusionsDatesDuplicate/
                             .exclusionsDatesMax/.empty,
                             help.dayMayskip/.switchToLastDay/.leapDay/.hourModulo/
                             .fifthWeekday/.everyNMonths/.lastWorkingDay/.dstNote,
                             describe.timeClause/.exclusionClause/.exclusionSeparator/
                             .<16 per-family sentence templates>/.unknown,
                             preview.heading/.loading/.unavailable/.approximate/
                             .empty/.summaryLabel,
                             assist.open/.prompt/.placeholder/.inputLabel/.run/
                             .unsupportedTitle/.alternativePreviewTitle/.useAlternative/
                             .throttled/.failed/.appliedNote
                             [REMOVED — REV-2-only shape] a scalar-only `weekday`
                             family param row (weekly now uses `weekdays`, see param.*
                             above; the scalar `weekday` key is STILL used by
                             `nth_weekday_of_month`/`last_weekday_of_month`, so the key
                             itself was not removed — only weekly's usage of it changed)
workflows.condition.*        [REWRITTEN]
                             operator.<18 tokens>, addCondition, removeCondition,
                             fieldPlaceholder, valuePlaceholder, betweenFrom/.betweenTo,
                             needsForm, missingPathHint, clearedOnFormChange
workflows.step.*             [CHANGED] <type>.label for ONLY {create_task,
                             create_form_report}; addStep, removeStep, moveUp/.moveDown,
                             keyLabel, keyHint,
                             config.* (title, description, priority, deadline, labels,
                             assignee, assigneeUser, assigneeBot, formId,
                             approvalPipelineId + placeholders/hints),
                             priority.low/.medium/.high/.urgent,
                             report.* (name, guidelines, source.task/.form,
                             fromHint, toHint),
                             validation.keyRequired/.keyDuplicate/.configRequired
                             [REMOVED] assign_bot/attach_form/start_approval labels,
                             referenceHint/insertReference/referenceGroups.*
workflows.field.*            [NEW] useVariable, useLiteral, pickVariable,
                             variableMode, literalMode
workflows.editor.*           createTitle, editTitle, cancel, save, saving,
                             detailError, sections.*, trigger.typeChangeWarning,
                             validation.*, toasts.created/.updated/.error
workflows.runs.*             refresh, state.<6>, origin.<3>, stepStatus.*, duration,
                             nestedBadge, listLabel, empty.*, detail.*, loadError
workflows.run.*              [CHANGED] title, purpose, submissionIdLabel/.Hint,
                             confirm, cancel, testRunNote, testRunConfirm,
                             toasts.started,
                             errors.targetRequired/.targetNotFound/.capReached/.generic
                             [REMOVED] taskIdLabel/.Hint, approvalRequirement,
                             errors.noConcludedApproval
```

Reuse shared keys (`common.cancel`, `common.delete`, the shared
`tasks.savedViews.*` block). Every visible string, placeholder, aria-label, and
state line goes through `t()`.

### 7.2 Module & section icons

`workflow` (module) · `list-checks` (all-workflows) · `layout-dashboard`
(Overview) · `clock` (Runs). All exist in `icons.ts`.

### 7.3 Trigger-type icons (5.1 — two only)

| trigger_type | icon |
| --- | --- |
| `form_submitted` | `file-text` |
| `schedule` | `calendar` |

### 7.4 Step-type icons (5.1 — two only)

| step type | icon |
| --- | --- |
| `create_task` | `plus` |
| `create_form_report` | `file-text` |

### 7.5 Add-on / assist icons

`braces` (the value-or-variable toggle, echoing the editor's variable glyph) ·
`sparkles` (the AI schedule assist). Both real. Chip icons: INSIDE the editor,
`getVariableIconName(type)` is reused verbatim — but it maps PRIMITIVES only
(text|number|boolean), so date/enum/multi variables would all fall to the text
glyph. For the ADD-ON chips (§4.9), B7 therefore adds a tiny
workflow-type→icon map in `workflowVariables.ts`: text→`type`, number→`hash`,
boolean→`check-circle` (mirroring the editor), date→`calendar`, enum→`list`,
multi→`list-checks`. The editor's own chips stay primitive-mapped (no editor
changes).

### 7.6 Origin icons

`event` → `file-text` (a form submission now) · `schedule` → `calendar` · `manual`
→ `user`. *(REV 1 mapped `event`→`arrow-right`; retuned to `file-text` since the
only event origin is a form submission.)*

### 7.7 Dark mode

`next-*` semantic tokens only (`bg-next-card`, `text-next-fg`,
`text-next-muted-foreground`, `border-next-border`, status `*-subtle`). No raw
hex/hsl, no per-component inversion. Module page icon bubble is
`bg-next-primary text-next-primary-foreground`. Variable chips + the add-on chips
reuse the editor chip's `--color-next-accent` / `accent-foreground` tokens so they
read identically in both themes. State/origin badges = tone token + icon + label.

### 7.8 Accessibility checklist

- Reorder arrows are real `Button`s with `aria-label`, disabled at ends, keyboard-
  reachable.
- Trigger `SegmentedControl` (2 options), schedule family `Select` (grouped),
  weekday/status/operator `Select`s, condition value controls, outcome/source
  `SegmentedControl`/checkbox groups all carry `aria-label`s; `SegmentedControl` is
  `role=radiogroup` with arrow-key nav.
- The `ValueOrVariableField` / `DateOrVariableField` toggle is an `aria-pressed`
  `Button`; the resulting variable chip's ✕ is a `Button size="icon-xs"`.
- The MarkdownEditor variable insert (`{`) suggestion popup is keyboard-navigable
  (`role=listbox`/`option`, ↑/↓/Enter/Esc) — inherited from the editor.
- The AI-assist composer, the run-now Modal, and the run-detail Drawer are focus-
  trapped, Esc/scrim-closable; ids wired for title/description; inputs labelled +
  `aria-describedby` on helpers. The assist `Button :loading` keeps stable width.
- Bucket/state filter chips (Runs) use `aria-pressed`; zero-count buckets greyed,
  not hidden.
- All buttons (incl. every close ✕) are the `Button` primitive; compact `icon-sm`
  / `icon-xs` for row/step/add-on controls.
- No status/state/tone signaled by color alone — icon + text always.

### 7.9 Explicit non-goals / deferred (this batch)

- **No variable pipeline operations** — `operationsCatalog` is fed empty; a
  variable chip is a pure reference (the editor supports pipelines; 5.1 wires
  none).
- **No if-blocks** — the editor's `ifBlocks` feature stays OFF in the workflow
  editors.
- **No `TaskSelect`** — no task target exists in 5.1 (task triggers gone); the
  create_task assignee uses `UserSelect`/`BotSelect`, not a task picker.
- **No caret-aware token insertion for plain inputs** — variable insertion is the
  editor's native `{`-trigger chip flow; the add-on fields use a picker, not
  caret insertion. (REV 1's manual `insertToken` at-cursor helper is retired with
  the reference popover.)
- **No bot-source distinction** — `source.task` covers in-task submissions
  including bots; the UI does not (and cannot) separate them.
- **No submission picker** — the run-now target is an id `TextInput` (flagged §8).
- **No visual canvas / node-graph builder**, **no raw cron input**, **no
  wait-for-approval UI**, **no real-time run streaming / websockets**, **no
  runs-this-month / cost dashboard** — all as REV 1.

---

## 8. Component inventory delta (B7 implements exactly this)

### 8.1 Existing UI to REUSE (verified names + paths)

| Component | Path | Used for |
| --- | --- | --- |
| `MarkdownEditor` | `ui/editor/MarkdownEditor.vue` | title/name (one-line) + description/guidelines, with `:variables` |
| `MarkdownViewer` | `ui/editor/MarkdownViewer.vue` | read-side variable-chip rendering in the detail Steps panel |
| `EntityCard` | `ui/patterns/EntityCard.vue` | list card + skeleton |
| `PageHeader` / `FilterBar` / `FilterTabBar` + `SaveViewModal` | `ui/patterns/*` | list header + filter row + Saved Views |
| `Select` | `ui/forms/Select.vue` | status, schedule family (grouped), weekday, condition field/operator/value, priority literal |
| `SegmentedControl` | `ui/forms/SegmentedControl.vue` | trigger type (2), assignee user/bot, anonymous tri-state, run state/origin |
| `FormSelect` | `ui/forms/FormSelect.vue` | trigger form (single), create_task.form_id, create_form_report.form_id |
| `PipelineSelect` | `ui/forms/PipelineSelect.vue` | create_task.approval_pipeline_id |
| `LabelSelect` | `ui/forms/LabelSelect.vue` | create_task.labels |
| `UserSelect` / `BotSelect` | `ui/forms/*` | create_task assignee |
| `DatePicker` / `TimePicker` / `NumberInput` | `ui/forms/*` | condition/date values, schedule time, schedule ints |
| `TextInput` / `Textarea` | `ui/forms/*` | name, key, tz, submission id, condition text |
| `Checkbox` (or `PillGroupInput`) | `ui/forms/*` | source (`manual`/`task`), report sources (`task`/`form`) |
| `IconInput` | `ui/forms/IconInput.vue` | workflow icon |
| `StatusBadge` / `Badge` | `ui/data/*`, `ui/primitives/*` | status; trigger/step/origin/count chips |
| `Button` | `ui/primitives/Button.vue` | ALL actions incl. closes; `icon-sm`/`icon-xs` |
| `Drawer` / `Modal` / `Popover` / `DropdownMenu` | `ui/overlay/*` | editor+run-detail drawers, run-now+assist modal, variable pickers, add-step menu |
| `ConfirmDialog` / `useConfirm` | `ui/overlay/*`, `app/composables/*` | delete confirm |
| `Alert` / `EmptyState` / `Skeleton` | `ui/feedback/*`, `ui/data/*` | states, warnings, assist responses |
| `Timeline` (+ `TimelineItem`) / `DescriptionList` | `ui/patterns/*`, `ui/data/*` | run step audit + trigger_payload |
| `Surface` / `Icon` / `Spinner` / `FormField` | `ui/layout/*`, `ui/primitives/*`, `ui/forms/*` | panels, glyphs, opening spinner, labelled rows |
| `getVariableIconName` / `VariableChip` styling | `ui/editor/extensions/*` | the add-on chip's per-type icon + token look (reused, not re-authored) |
| `useInfiniteScroll` / `useDebounce` / `useToast` / `useI18n` / `useFilterTabs` | `app/composables/*`, `app/i18n` | list orchestration, feedback, i18n, saved views |

### 8.2 B7 DELETE (superseded by 5.1)

| File | Why deleted |
| --- | --- |
| `pages/workflows/ReferenceInsertPopover.vue` | The `{{…}}` reference popover is replaced by the MarkdownEditor variable extension (§4.7) + the add-on pickers (§4.9). |
| `pages/workflows/workflowReferences.ts` | The static `{{trigger.*}}` catalog is replaced by the server `workflow-catalog` variables (system + field + step outputs). |
| `pages/workflows/__tests__/ReferenceInsertPopover.spec.ts` | Tests the deleted component. |

### 8.3 B7 REBUILD (exists but 5.1 changes its contract)

| File | What changes |
| --- | --- |
| `pages/workflows/WorkflowTriggerFields.vue` | 5 types → 2; SegmentedControl selector; form_submitted panel (FormSelect single + source + anonymous); schedule → the descriptor-driven tiered builder + AI assist; wire shapes per §4.4. |
| `pages/workflows/WorkflowStepCard.vue` | 4 step types → 2; create_task gains labels/assignee/form_id/approval_pipeline_id + value-or-variable priority/deadline + MarkdownEditor title/description; new create_form_report card; `task_id` mono field removed. |
| `pages/workflows/WorkflowStepListEditor.vue` | Add-step menu → 2 types; keeps ▲▼/min-1/per-index 422. |
| `pages/workflows/WorkflowConditionsEditor.vue` | Flat triples → typed, schema-driven, form-gated builder (§4.8). |
| `pages/workflows/workflowEditorModel.ts` | Trigger draft = 2 types; step config = 2 types (create_task full field set, create_form_report); `buildTriggerConfig`/`buildStepConfig` re-shaped; schedule = `{family, params, tz}`; union `{kind,…}` builders for priority/deadline/report windows; step-output stems from the catalog (drop hand-written `STEP_OUTPUT_SUFFIXES`); conditions typed `{field, field_type, operator, value}`; drop `referenceGroupsAt`/`insertToken` (variables come from the editor). |
| `pages/workflows/types.ts` | `WorkflowTriggerType = 'form_submitted'\|'schedule'`; `WorkflowStepType = 'create_task'\|'create_form_report'`; `WorkflowConditionOperator` = the 18-token union; condition = `{field, field_type, operator, value}`; `trigger_config` = `{form_id, source, anonymous}` \| `{schedule:{family, params, tz}}`; add the value-or-variable union type + the schedule descriptor + assist envelope types + the catalog types. |
| `pages/workflows/workflowMeta.ts` | 2 trigger icons/labels, 2 step icons/labels; add schedule family labels + tier grouping helpers + `describeSchedule`. |
| `pages/workflows/WorkflowCard.vue` | Trigger badge over 2 types (mechanical). |
| `pages/workflows/WorkflowDetailView.vue` | Overview trigger/conditions/steps panels per §3.2 (typed value chips, variable-chip step summaries, `describeSchedule`). |
| `pages/workflows/TargetPickerModal.vue` | 2 target cases (submission id / none); task/approval cases removed. |
| `pages/workflows/runNowErrors.ts` | Drop `approval_process` / `noConcludedApproval`; map only `target_id` (required/notFound) + `workflow` (cap) + generic. |
| `pages/workflows/WorkflowEditorDrawer.vue` | Host the rebuilt sections; catalog fetch-per-form + cache; conditions clear-on-form-change; assist wiring; 422 map per §4.10. |
| the `__tests__/*` for the rebuilt files | Re-point at the new contracts (editor model, trigger fields, target picker, run-now errors). |

### 8.4 B7 CREATE (new for 5.1; schedule files rebuilt again in REVISION 3)

| New file | Path | Responsibility |
| --- | --- | --- |
| `WorkflowScheduleBuilder.vue` | `pages/workflows/` | [REBUILT, REVISION 3] The simple/advanced two-mode, descriptor-driven family picker (16 families) + per-param controls + `times`/`exclusions` editors + `lt`/bounds validation + semantic helper text + the live preview card (debounced `POST schedule-preview`). See §4.5. |
| `WorkflowScheduleAssist.vue` | `pages/workflows/` | [REBUILT, REVISION 3] The AI natural-language composer + the four response states (apply/alternative/infeasible/throttle); state (b) now previews the alternative (sentence + note + next 4 runs) before apply. Prefills the builder, never submits. |
| `ValueOrVariableField.vue` | `pages/workflows/` | Literal control OR a catalog-variable picker (type-filtered), emitting `{kind:…}`; used for priority (and any enum value-or-variable). |
| `DateOrVariableField.vue` | `pages/workflows/` | `DatePicker` OR a date-typed variable picker, emitting `{kind:…}`; used for deadline + report windows. |
| `workflowVariables.ts` | `pages/workflows/` | Catalog→editor adapter: `toEditorVariables` (system/field/position-scoped step outputs, KEY-substituted, editor-primitive typed), `resolveVariableType`, `variablesOfType` (for the add-on filters). |
| `workflowSchedule.ts` | `pages/workflows/` | [REBUILT, REVISION 3] Pure schedule helpers: descriptor lookup (16 families), `lt`/bounds validators (incl. `weekday_list`/`times`/`exclusions`), `isSimpleRepresentable`, `intentForFamily`, `SIMPLE_INTENT_FAMILIES`, `describeSchedule(config, t)` (signature widened from `(family, params, tz)` to take the full `ScheduleConfig` + the exclusion/time clauses), `configToDraft`/`draftToConfig`, `occurrenceFormatter`/`formatOccurrence` (shared preview-row formatting). |
| store methods on `app/stores/workflows.ts` | `app/stores/` | `fetchScheduleFamilies()` (cached), `fetchWorkflowCatalog(formId)` (cached per form), `scheduleAssist(prompt, tz)`, and [NEW, REVISION 3] `schedulePreview(config, count)` — calls `POST /workflows/meta/schedule-preview`; used by both the builder's live preview and the assist's alternative preview. |

> No new UI **primitive** is required — the two add-on fields and the schedule
> components compose existing `ui/` controls (the times/exclusions editors reuse
> `TimePicker`/`DatePicker`/`Button` chips, no new primitive). All new page
> components live under `resources/js/next/pages/workflows/`.

> **Unit-spec REQUIREMENT (B6 review, still honored in the REVISION 3 rebuild):**
> the two pure modules — `workflowSchedule.ts` (descriptor/lt/bounds validators,
> `describeSchedule`, `configToDraft`/`draftToConfig`, now also the `weekday_list`/
> `times`/`exclusions` validators and `isSimpleRepresentable`) and
> `workflowVariables.ts` (KEY substitution, position scoping,
> `resolveVariableType`) — ship with dedicated Vitest specs:
> `pages/workflows/__tests__/workflowSchedule.spec.ts`,
> `pages/workflows/__tests__/workflowVariables.spec.ts`,
> `pages/workflows/__tests__/WorkflowScheduleBuilder.spec.ts`,
> `pages/workflows/__tests__/WorkflowScheduleAssist.spec.ts`, plus
> `app/stores/__tests__/workflows.spec.ts` for the store methods (incl.
> `schedulePreview`). Their correctness is what keeps the FE from drifting off
> the descriptor/catalog contract.

> **Carried UNCHANGED (provably complete inventory):** `WorkflowsModuleLayout.vue`,
> `WorkflowsView.vue`, `WorkflowRunsView.vue`, `WorkflowRunRow.vue`,
> `WorkflowRunTimeline.vue`, `runFormat.ts`, `workflowStatus.ts`, the
> `workflowRuns.ts` store, and the runs/read i18n groups — all verified
> contract-clean (no removed trigger/step references; runs surfaces render both
> surviving step types unchanged). B7f re-verifies they compile against the
> narrowed `types.ts`.

---

## 9. Backend contract gaps discovered (flagged, not invented)

None blocking. Two notes for B7 to be aware of (neither needs a backend change):

1. **Run `origin` still carries `event`.** The runs resource/type keeps
   `origin ∈ {event, schedule, manual}` from Etap 5; with event triggers gone, an
   `event`-origin run now means a form-submission-triggered run. The FE keeps the
   three-origin filter and labels `event` as-is; it does **not** rename the wire
   value (that would be a backend change). If the reviewed runs resource has since
   dropped/renamed `event`, B7 should mirror the real resource — verify against
   `WorkflowRunResource` at build time, but do not invent.
2. **Anonymous control depends on the form's `anonymous` flag**, which is not part
   of the workflow write body — it is read from the selected form (FormSelect seed
   / a form fetch). If FormSelect's seed does not currently expose `anonymous`, B7
   reads it from the form detail or the `workflow-catalog` fetch context; if
   neither exposes it, the safe default is to **show the tri-state whenever a form
   is selected** and let the backend accept `anonymous` regardless (the flag only
   gates whether the filter is meaningful, not whether it is accepted). Verify the
   FormSelect option shape at build time; do not fabricate a flag.

**No backend tweak is required by this spec.** It consumes the final reviewed
B1–B5 contract exactly and invents no endpoint or field.
