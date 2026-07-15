# Workflows module — UX/UI specification

> **REVISION 6 — Runs UI: global cross-workflow feed, source relabel, schedule "reason", form/
> submission cards, run-now Pick/Create (B1–B8) — STATUS: IMPLEMENTED, date 2026-07-15.** This
> revision rewrites `§5` (Runs view) and `§6` (Run-now flow) in place to the as-built module; it
> does not touch `§4` (the editor) or anything above `§5`. Five deltas:
> - **A new TOP-LEVEL "All runs" list** (`WorkflowRunsListView.vue`, route `next.workflows.runs`,
>   `GET /workflows/runs`) sits ALONGSIDE the existing "All workflows" list in the module nav
>   (`§1.1`–`§1.3`). Unlike the per-workflow Runs section, this list is NOT detail-nested — it
>   carries the mandatory `FilterBar` + `#top` Saved Views `FilterTabBar` (context
>   `workflow-runs`), same as every other top-level next list.
> - **Every runs filter (both the global list and the per-workflow section) is now a
>   MULTI-SELECT `Select`, not a `SegmentedControl`**, plus a shared `DateRangeFilter`
>   (`date_from`/`date_to`/`date_preset`). The global list adds a fourth filter, a searchable
>   single-`Select` scoping to one workflow (`workflow_id`, global-only). The per-workflow
>   section's SOURCE filter hides the "schedule" option when the open workflow's `trigger_type` is
>   `form_submitted` (a form-triggered workflow can never produce a schedule-origin run).
> - **The origin/trigger-type pair collapses into ONE "source" badge** on both the run row and the
>   run-detail header — REV1/REV2's separate origin badge + trigger-type chip is GONE. The origin
>   RELABEL itself (`event` → "Wysłanie formularza"/"Form submission") is an i18n-only change; the
>   `event` wire value is untouched (§9's note #1 below still applies to the VALUE, not the label).
> - **The run detail drawer gained TWO trigger-specific bodies**, replacing the old flat
>   `trigger_payload` key→value dump for these two cases: a schedule run shows a semantic "Powód"/
>   "Reason" sentence (`describeOccurrence`, computed client-side from the run's
>   `schedule_descriptor` + `trigger_payload.scheduled_at`); a `form_submitted` run shows a Form
>   card (opens the form in a new tab) + a Submission card (opens `SubmissionPreviewDrawer` in its
>   new `diff` mode — snapshot vs. current, project-wide `modified` token for changed fields). Any
>   OTHER trigger payload shape still falls back to the flat key→value rows.
> - **Run-now's `form_submitted` target is no longer a raw id `TextInput`** (§6.1's REV1/REV2 gap,
>   flagged in the old §9). `TargetPickerModal` now shows a read-only selected-submission summary
>   plus **Pick** (`SubmissionPickerDrawer`) and **Create** (`FormFillView` in a drawer) actions.
>   The wire contract (`{target_id}`) and the 422 bag (`target_id` / `workflow`, §6.3) are
>   UNCHANGED — only how the id is obtained changed.
>
> See `docs/backend/workflows-api.md`'s Runs endpoints section and
> `docs/decisions/ADR-0016-workflows-global-runs-and-schedule-reason.md` for the backend/design
> record this revision implements against.

> **REVISION 5 — step 2a `schedule` builder UX compaction (fresh user feedback on the
> shipped REV4) — STATUS: PLANNED (this spec drives it), date 2026-07-13.** REV5 is a
> SURGICAL delta on REV4's schedule builder — the compositional descriptor v2
> (time × day × month, AND-semantics), the summary sentence GRAMMAR, the AI modal
> (`§4.5.9`), the preview endpoint, and the four load states are all UNCHANGED. Four
> things change, all inside `§4.5`:
> - **A — one compact header segment.** REV4's separate summary band, the visible
>   "Najbliższe uruchomienia" heading, the labelled "Skocz do daty" field and the
>   "Zaplanuj z AI" button collapse into a SINGLE framed segment with a far smaller
>   vertical budget: one sentence+actions row over a rail of COMPACT two-line tiles.
>   The "Najbliższe uruchomienia" heading becomes an aria-label (not visible text);
>   "Skocz do daty" sits in the same row as a compact always-visible `DateTimePicker`
>   field (REV5.1 correction — the original icon+`Popover` was inscrutable and broke
>   the nested calendar; see `§4.5.3`) (`§4.5.2`–`§4.5.4`).
> - **B — options carry their own settings.** The per-tab "`SegmentedControl` of
>   cards + controls stacked BELOW" is replaced by a radio-group of selection cards
>   where the SELECTED card EXPANDS to show its inputs woven into a natural-language
>   sentence ("co [n] minut ☐ od [od] do [do]"). New LOCAL component
>   `WorkflowScheduleOptionCards.vue`; the `SegmentedControl` PRIMITIVE is left
>   UNTOUCHED (other consumers are pinned to it). Unselected cards show their title
>   only (`§4.5.5`–`§4.5.6`, `§4.5.15`).
> - **C — no timezone field.** The tz text field leaves the UI; the MODEL still
>   carries `tz` (a NEW schedule seeds the browser's active zone, an EDITED one keeps
>   its saved zone — the wire round-trip is unchanged). The summary's "({tz})" clause
>   now shows ONLY when the schedule's tz differs from the viewer's active zone
>   (`§4.5.8`, `§4.5.10`).
> - **D — exceptions are dates only.** The "oprócz dni tygodnia / miesięcy" chip
>   filters leave the UI (the same effect is reachable by choosing days/months on the
>   Dzień/Miesiąc axes); the "Wyjątki" disclosure now holds ONLY skip-dates. The
>   backend still ACCEPTS weekday/month exclusions (the FE simply stops AUTHORING
>   them), and `describeSchedule` still RENDERS them when a config carries them
>   (`§4.5.7`, `§4.5.10`, `§4.5.12`).
>
> Sections REV5 rewrites in place, each carrying a REV5 supersede marker: `§4.5.2`,
> `§4.5.3`, `§4.5.4`, `§4.5.5` (+ 5a/5b/5c), `§4.5.6`, `§4.5.7`, `§4.5.8`, the tz
> clause of `§4.5.10`, the key inventory `§4.5.12`, and the component inventory
> `§4.5.15`. `§4.5.9` (AI modal) is UNCHANGED except that its compact in-modal preview
> inherits REV5's compact tiles. Everything else in this spec (REV4 and earlier) still
> describes the module. A follow-up ADR records the compaction decision (flagged for
> the docs phase; not written by this UX pass).

> **REVISION 4 — step 2a `schedule` rebuild (compositional descriptor v2) — STATUS:
> PLANNED (this spec drives it), date 2026-07-12.** The `schedule` trigger leaves
> the 16-family model behind for a **compositional descriptor**: an occurrence
> fires when a **time** rule, a **day** rule, and a **month** rule ALL match
> (AND-semantics), minus `exclusions`, in a `tz`. `§4.5` below was **rewritten in
> place** to: a top **summary sentence + "Zaplanuj z AI"** (`WorkflowScheduleSummary`),
> a horizontally-scrolled **preview strip** with a "jump to date" anchor
> (`WorkflowSchedulePreviewStrip`), a **three-tab manual builder** (Czas | Dzień |
> Miesiąc — each tab a `SegmentedControl` of sub-modes, never tabs-in-tabs), a
> collapsible **exceptions** disclosure, and an **AI assist MODAL**
> (`WorkflowScheduleAssistModal`) that **no longer auto-applies** — every result is
> reviewed and committed via **Zastosuj**. The **clock grid** (co-X counted from the
> top of the hour / start of the day) makes every preview EXACT, so REV3's
> `approximate` note is **retired**. The REV3 simple/advanced two-mode text is
> **SUPERSEDED**; its `workflows.schedule.*` sketch in `§7.1` and its read-side use
> in `§3.2` are reconciled by the authoritative REV4 grammar (`§4.5.10`) + key
> inventory (`§4.5.12`) — where those pre-REV4 sections and REV4 differ, REV4 wins,
> and `§3.2`'s `describeSchedule` reference now resolves to `§4.5.10`. Everything
> outside `§4.5` is UNCHANGED from REVISION 3. This revision DEPENDS on the backend
> descriptor-v2 contract (owned by the same plan's backend phase); where this spec
> names a wire/endpoint shape it is the FE's REQUIREMENT on that phase, flagged
> `[backend-dep]` — it invents no field.

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

*(Carried forward from REV 1, updated by REVISION 6 for the new top-level "All runs" list — see
`§1.1`/`§1.2`/`§1.3`. The `workflow` glyph is the confirmed module identity.)*

### 1.1 Module nav entry & icon

| Concern | Decision | Justification |
| --- | --- | --- |
| Module nav icon | **`workflow`** | The Lucide `workflow` glyph (two connected blocks — "steps linked into a pipeline") is the Workflows module identity everywhere (nav, PageHeader, aside header, empty state, card/entity fallback). Avoids the `git-branch` collision with the Approvals top-level nav. `git-branch` remains a SECTION glyph. |
| List sub-nav icon | `list-checks` | "the list of workflow definitions". |
| **All-runs sub-nav icon (REVISION 6)** | `clock` | the same glyph as the per-workflow Runs section — a time-ordered history, now also reachable as its own top-level list. |
| Overview section icon | `layout-dashboard` | "summary of this entity". |
| Runs section icon | `clock` | runs are a time-ordered history. |

### 1.2 Routes

Register under the authenticated AppLayout children, parallel to `bots`:

```
/workflows                     name: next.workflows          → WorkflowsView (list)
/workflows/runs                name: next.workflows.runs     → WorkflowRunsListView (REVISION 6,
                                                                 the GLOBAL cross-workflow feed;
                                                                 declared BEFORE the dynamic `:id`
                                                                 record so the static segment wins)
/workflows/:id                 name: next.workflows.detail   → WorkflowDetailView
                               ?section=overview|runs (default overview)
```

- Editor overlay = query key `?workflow=new` / `?workflow=<id>` (owned by the
  module layout, Bots' `?bot=` pattern; preserved across filter + section nav).
- Run-now overlay = query key `?run=<id>`.
- Run-detail overlay = query key `?run_detail=<runId>` (on the detail route). The GLOBAL runs list
  (REVISION 6) hosts its OWN run-detail drawer locally (no `?run_detail=` on `/workflows/runs` —
  the selected run is kept in local component state, not the URL, since it is resolved from the
  clicked row rather than a deep-linkable id).
- Deep-link unknown id → the detail view fetches by id and shows its own error
  state (Bots precedent).

### 1.3 Aside behavior (module layout)

`WorkflowsModuleLayout.vue` mirrors `BotsModuleLayout.vue`:

- **On the list**: module header (white-on-primary icon bubble
  `bg-next-primary text-next-primary-foreground` with `workflow`, title, select
  hint) + **two** sub-nav items (REVISION 6): "All workflows" (`list-checks`,
  `next.workflows`) and "All runs" (`clock`, `next.workflows.runs`) — a distinct nav-item key
  (`allRuns`) keeps it from colliding with the per-workflow Runs SECTION tab (`runs`), which
  shares the same active-item matcher.
- **After opening a workflow**: `back-to-list` (`arrow-left`) + the entity info
  block (icon bubble, name, StatusBadge) + a section sub-nav (**Overview** /
  **Runs**) driven by `?section=`. The layout `watch`es the route id and
  prefetches the detail so the aside renders identity immediately.
- Aside is `hidden … next-lg:flex`.
- The layout **hosts** the editor `Drawer` (`?workflow=`), the run-now `Modal`
  (`?run=`), and the run-detail `Drawer` (`?run_detail=`, per-workflow section only — the global
  runs list's detail drawer is self-hosted, `§1.2`).

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

### 4.5 Schedule builder (REWRITTEN in REVISION 4 — compositional descriptor v2: time × day × month, tabbed builder, summary + preview strip, AI modal)

> **Supersede marker.** This section REPLACES the REVISION 3 simple/advanced
> two-mode builder (16 families, one grouped family `Select`, an inline assist
> composer, a bottom preview card). None of REV3's §4.5.1–§4.5.7 structure survives:
> there is no simple/advanced toggle, no family `Select`, no `intent.*`/`family.*`/
> `tier.*` keys, and no `approximate` state. The REV3 text below was rewritten in
> place. `ADR-0010 §7`'s two-mode decision is superseded by the compositional model
> here (a follow-up ADR records this — flagged for the docs phase; not written by
> this UX pass).

> **REV5 supersede marker (scoped).** REV5 does NOT re-open the descriptor model, the
> AND-semantics, the tab structure, the AI modal, or the summary GRAMMAR. It compacts
> the header (`§4.5.2`–`§4.5.4`), weaves each option's inputs into the option itself
> (`§4.5.5`–`§4.5.6`), removes the tz field (`§4.5.8`, keeping the model), and reduces
> exceptions to dates (`§4.5.7`). Subsections it rewrites carry their own REV5 marker;
> subsections without a marker are REV4-as-built.

The `schedule` trigger is now a **composition of three independent axes** — WHEN in
the day (`time`), WHICH days (`day`), WHICH months (`month`) — plus a set of
`exclusions` and a `tz`. An occurrence fires **only when all three axes match at
once** (AND-semantics), then any `exclusions` drop it. The user builds each axis in
its own tab; the builder never asks them to pick from a flat list of pre-composed
"families". The FE owns **all** labels (§4.5.12).

Host container: `WorkflowScheduleBuilder.vue` (rebuilt), rendered by
`WorkflowTriggerFields.vue`'s `schedule` branch (§4.4). `v-model` is the local
`ScheduleDraft` (§4.5.1); the drawer wires it into `trigger_config.schedule` on save
via `draftToConfig` and gates the step/save on the builder's exposed `isValid`.

#### 4.5.1 The compositional descriptor v2 (the data model)

The FE draft shape the whole section manipulates (the exact wire shape is finalized
by the backend phase — `[backend-dep]`; `configToDraft`/`draftToConfig` in
`workflowSchedule.ts` adapt between wire and this draft):

```ts
type TimeAxis =
  | { mode: 'at'; at: string[] }                    // 1..6 'HH:mm', sorted, distinct
  | { mode: 'every_minutes'; n: number;             // n 1..59 (default 1)
      window?: { from: string; to: string } }       // 'HH:mm' each, from < to; default OFF
  | { mode: 'every_hours'; n: number;               // n 1..23 (default 1)
      minute: number;                                // 0..59 (default 0)
      window?: { from: number; to: number } };       // whole hours 0..23, from < to; default OFF

type DayAxis =
  | { mode: 'every_day' }
  | { mode: 'every_n_days'; n: number;              // n 1..31 (default 1)
      window?: { from: number; to: number } }        // day-of-month 1..31, from < to; default OFF
  | { mode: 'weekdays'; weekdays: number[] }        // non-empty subset of 0..6, 0 = Sunday
  | { mode: 'month_days'; days: number[] }          // non-empty subset of 1..31
  | { mode: 'special'; special:
      | { kind: 'last_day' }
      | { kind: 'last_working_day' }                 // RESTRICTION: requires TimeAxis.mode === 'at'
      | { kind: 'nth_weekday'; ordinal: number; weekday: number }  // ordinal 1..5, weekday 0..6
      | { kind: 'last_weekday'; weekday: number } };

type MonthAxis =
  | { mode: 'every_month' }
  | { mode: 'every_n_months'; n: number;            // n 1..12 (default 1)
      window?: { from: number; to: number } }        // month 1..12, from < to; default OFF
  | { mode: 'months'; months: number[] };           // non-empty subset of 1..12

interface ScheduleExclusions {
  months: number[];   // 1..12
  weekdays: number[]; // 0..6
  dates: string[];    // 'YYYY-MM-DD', max 50, distinct
}

interface ScheduleDraft {
  time: TimeAxis;
  day: DayAxis;
  month: MonthAxis;
  exclusions: ScheduleExclusions;
  tz: string;         // '' ⇒ omit ⇒ server UTC
}
```

- **Neutral draft (the seed on a fresh schedule):**
  `{ time:{mode:'at', at:['09:00']}, day:{mode:'every_day'}, month:{mode:'every_month'},
  exclusions:{months:[],weekdays:[],dates:[]}, tz:<REV5: browser zone> }` → renders as
  **"Codziennie o 09:00"** (§4.5.10). `every_day` and `every_month` are the **neutral**
  modes and are omitted from the sentence. **REV5:** `tz` now seeds the resolved browser
  IANA zone (fallback `''`) instead of `''`, and the sentence shows no "(…)" clause when
  that zone equals the viewer's (§4.5.8/§4.5.10). `exclusions.months`/`.weekdays` stay in
  the model (backend-supported) though the FE no longer authors them (§4.5.7).
- **Defaults are deliberate:** the interval **N defaults to 1**; every **window is OFF
  by default** (omitted from the wire); `every_hours.minute` **defaults to 0**.
- **Clock grid (why the preview is always exact).** `every_minutes` is phased from the
  **top of the hour** (n=15 ⇒ :00, :15, :30, :45), `every_hours` from the **start of
  the day** (n=2 ⇒ 00:00, 02:00, … plus the `minute` offset). Because the phase is
  fixed to the calendar, not to "now", the preview is deterministic — **REV3's
  `approximate` state is retired** and no "w przybliżeniu / indicative" note is ever
  shown.
- **Switching a tab's sub-mode reshapes ONLY that axis**, preserving the other two and
  the exclusions/tz (e.g. flipping the Day tab from `every_day` to `weekdays` keeps the
  chosen times + months). `at` times are preserved across time sub-mode switches where
  possible.

#### 4.5.2 Panel structure (top → bottom) + component decomposition

> **REV5 supersede marker.** REV4's five stacked blocks (separate summary band,
> separate preview `<section>` with its own heading, separate tz field) become FOUR,
> and the top TWO fuse into one **header segment**. Blocks below are the REV5 order.

The `schedule` panel renders these blocks in order. Each is a small component so the
host stays thin; all share the single `ScheduleDraft` `v-model`.

| # | Block | Component | Purpose |
| --- | --- | --- | --- |
| 1 | **Header segment** (summary + preview, ONE frame) | `WorkflowScheduleSummary` (top row) + `WorkflowSchedulePreviewStrip` (rail row), composed inside a single `Surface bg="muted" border radius="lg"` owned by the host | ROW 1: the live `describeSchedule` sentence + a compact "Skocz do daty" `DateTimePicker` field (REV5.1) + the "Zaplanuj z AI" button. ROW 2: a rail of COMPACT two-line run tiles (`§4.5.3`–`§4.5.4`). |
| 2 | **Manual builder** | `WorkflowScheduleBuilder` (tabs) | three tabs Czas / Dzień / Miesiąc, each a `WorkflowScheduleOptionCards` radio-group whose SELECTED card expands with its in-sentence inputs (`§4.5.5`). |
| 3 | **Exceptions** | inside the builder (`Accordion`) | a collapsed disclosure holding ONLY skip-**dates** (`§4.5.7`). |
| 4 | **AI modal** | `WorkflowScheduleAssistModal` | opened from block 1; reviews then commits via **Zastosuj** (`§4.5.9`). |

- **The tz field is GONE from the panel** (REV4 block 5). The MODEL still carries `tz`
  (`§4.5.8`); nothing in the panel edits it.
- **Vertical rhythm:** the host is still `flex flex-col gap-next-4`, but block 1 now
  reads as ONE compact unit — "here's what you built AND what it will do" in a single
  framed segment above the controls, in a much smaller vertical budget than REV4's two
  separate boxes. `describeSchedule` + the preview recompute on every draft change.
- **The four load states** apply to the whole panel exactly as REV4: the descriptor
  vocabulary is static in the FE, so there is no families fetch to fail — the **preview
  strip** owns its own loading/empty/error/success (`§4.5.4`, now rendered INSIDE the
  segment frame), and the `[backend-dep]` preview degrades quietly and never blocks
  editing except the `empty` gate (`§4.5.11`).

#### 4.5.3 `WorkflowScheduleSummary` — the sentence + the header row (REV5: no own band)

> **REV5 supersede marker.** REV4's summary was its OWN `Surface bg="muted" p-next-4`
> band, separate from the preview `<section>`. REV5 folds it into the shared header
> segment (`§4.5.2` block 1): the HOST owns the single `Surface bg="muted" border
> radius="lg" p-next-3` frame; the summary is just its **top row**, and that row now
> also carries the "Skocz do daty" trigger + the "Zaplanuj z AI" button. This is the
> "one visual segment, smaller footprint" the user asked for.

The header row is `flex flex-wrap items-center gap-next-2` and wraps on mobile:

- **Leading:** an inline `Icon name="calendar"` (the only calendar glyph in the curated
  set), `text-next-muted-foreground`, `aria-hidden` — NO large `h-9 w-9` bubble (REV4 had
  one; the bubble is dropped to reclaim width and height so the row stays one line on
  desktop).
- **Body:** the live `describeSchedule(draft, t)` sentence (`§4.5.10`) as one string,
  `text-next-sm font-next-medium text-next-fg`, `min-w-0 flex-1` so it wraps, never
  truncates. It is ALWAYS renderable (the neutral draft yields "Codziennie o 09:00"),
  so there is no empty/placeholder state here.
- **Trailing action cluster** (right-aligned on the same row, wraps under the sentence
  on mobile), two controls in order:
  1. the **"Skocz do daty"** anchor control — **REV5.1 correction (user feedback on the
     built REV5):** NOT an icon+`Popover`. A bare icon was inscrutable, and nesting the
     `DateTimePicker` (whose calendar is a teleported `FieldPopover`) inside
     `ui/overlay/Popover` broke it — any click in the calendar landed "outside" the
     panel and closed it. Instead: a **compact, always-visible `DateTimePicker` field**
     rendered directly in the row (`w-56` wrap, `size="sm"`, placeholder + `aria-label`
     `workflows.schedule.preview.jumpTo`, `dirty` tint when an anchor is set) plus an
     field's own built-in `clearable` ✕ INSIDE the shell (REV5.2 — no external clear
     button; the user's rule: clear/remove affordances live INSIDE the input). The
     calendar is then a FIRST-level overlay and works. Never nest a FieldPopover-based
     control inside `ui/overlay/Popover`.
  2. the **"Zaplanuj z AI"** button — `Button variant="outline" size="sm"
     leading-icon="sparkles"` `workflows.schedule.summary.assist` that opens
     `WorkflowScheduleAssistModal` (`§4.5.9`); icon-only (`size="icon-sm"`, aria-label)
     on the narrowest widths. Still a **builder affordance**, not a drawer/footer
     action (the sticky-footer rule governs Save/Cancel — `§4.1` — and the modal's own
     footer — `§4.5.9`).

- **Anchor ownership.** The `anchor` state lives with the strip/host so the trigger
  (row 1) and the rail (`§4.5.4`) stay in sync; whether the jump trigger is a summary
  slot or a host-rendered sibling in the same row is an implementation choice — the
  visual result is one row. `WorkflowScheduleSummary` still owns NO AI logic (it emits
  `assist`; the host wires the modal).

The summary is the single source of the human sentence; there is no second copy of it
in a preview card (REV3 had one — removed).

#### 4.5.4 `WorkflowSchedulePreviewStrip` — upcoming runs as a compact scrollable rail

> **REV5 supersede marker.** REV4's strip was its OWN `<section>` with a visible
> `Icon + <h4>` "Najbliższe uruchomienia" heading, a labelled `DateTimePicker`
> beside it, and TALL three-line tiles (`w-[8.5rem]`, `~5.5rem`). REV5: (1) the
> strip renders INSIDE the header segment frame with NO visible heading — the
> "Najbliższe uruchomienia" text becomes the rail region's `aria-label`; (2) the
> "Skocz do daty" control MOVES up into the segment row as a compact always-visible
> `DateTimePicker` field (REV5.1 correction — see `§4.5.3`)
> (`§4.5.3`) — this subsection only documents the anchor's EFFECT on the rail; (3)
> tiles are COMPACT two-line (`§4.5.4` below). Everything else — paging, edge fades,
> the anchor's "previous" tile, the 4 states, the preview contract — is REV4-as-built.

A horizontal rail of **run tiles** as the second row of the header segment, so the
user sees the concrete effect of the AND-composition. `[backend-dep]` — it consumes
the preview endpoint (see the contract note at the end of this subsection). The rail's
scroll region carries `aria-label = workflows.schedule.preview.title` (the ex-heading
text) so it is still named for assistive tech.

**Tile (a compact data card, per `reference-links.md` "data cards").** A `Surface
bg="card" border radius="md"` (tiles pop against the muted segment frame), `w-[7rem]
shrink-0`, `p-next-2`, **two** lines:
1. **weekday + date on one line:** weekday `text-next-2xs uppercase tracking-wide
   text-next-muted-foreground` (e.g. "pon") immediately followed by the date
   `text-next-sm font-next-semibold text-next-fg tabular-nums` (e.g. "12 lip");
2. **time:** `text-next-sm tabular-nums text-next-fg` (e.g. "09:00").
This halves REV4's tile height while keeping every field. Both lines render in the
**schedule's own `tz`** via the shared `occurrencePartsFormatter(locale, renderZone)`
(`renderZone` = the schedule `tz`, or the active browser tz when blank — `§4.5.8`).
Each tile has an `aria-label` = `workflows.schedule.preview.tileAria` (`{weekday}`,
`{date}`, `{time}`) so a screen reader announces the whole instant.

**Rail behavior.**
- Container: `flex gap-next-2 overflow-x-auto scrollbar-none scroll-smooth`, with
  **edge-fade** gradients on both sides (the technique `Tabs.vue` uses: `from-next-bg
  to-transparent` overlays shown only when scrollable that way) so the rail visibly
  continues off-screen.
- **Lazy paging:** a zero-width sentinel at the right end; when it scrolls into the
  viewport, request the next page and **append** tiles (a horizontal analogue of
  `useInfiniteScroll`), plus a keyboard "Wczytaj kolejne" fallback button. A trailing
  `Spinner` tile shows while a page is in flight.
- **"Skocz do daty" (jump-to-date) — trigger MOVED to the segment row (`§4.5.3`).**
  The anchor (a `DateTimePicker` inside the row's `Popover`) still re-seeds the rail:
  setting an **anchor A** makes **tile[0] = the last occurrence ≤ A**, rendered as a
  **"previous" tile** (visually distinct — `bg-next-muted border-dashed
  text-next-muted-foreground` + a leading `rotate-ccw` glyph before the weekday; the
  "poprzednie" word lives ONLY in the tile's `aria-label`, no stacked
  caption line — dropping the REV4 caption chip keeps the compact two-line height),
  followed by the occurrences **after A**. Clearing the anchor returns to the default
  view (upcoming from now, **no** previous tile). The anchor is interpreted in the
  schedule `tz`.

**States (the 4 UI states, scoped to the strip):**

| State | Trigger | UI |
| --- | --- | --- |
| **Loading** | first fetch / anchor change | 5–6 **skeleton tiles** (`Skeleton variant="rect"` at the COMPACT two-line tile size — `w-[7rem]` × ~`3.25rem`), `role="status"` + `workflows.schedule.preview.loading`. |
| **Empty** | preview `empty: true` (exclusions/impossible AND rule out every run — e.g. `month_days:[31] ∧ months:[2]`) | an inline `Alert variant="warning" size="sm"` `workflows.schedule.preview.empty` REPLACING the rail; this **blocks save** (feeds `isValid=false`, §4.5.11) because the write path 422s an empty schedule. |
| **Error** | network/parse failure | QUIET, non-blocking: `workflows.schedule.preview.unavailable` as muted text where the rail would be; the summary sentence stays and the schedule is still savable (a preview outage never blocks the builder). |
| **Success** | occurrences returned | the tile rail (+ previous tile when anchored) + lazy paging. |

**Preview contract this strip consumes — IMPLEMENTED, flat (no `previous`/`cursor`).**
`POST /workflows/meta/schedule-preview` accepts the **v2 `{ time, day?, month?,
exclusions?, tz? }` block** plus an optional **`count`** (1–12, default 6) and an
optional **`anchor`** (an ISO-8601 datetime; without an offset it is read as a
wall-clock time in the schedule's own tz). The response stays FLAT —
`{ occurrences: string[], count: number, empty: boolean, approximate: boolean }` — with
**no separate `previous` or `cursor` field**: when `anchor` is set, `occurrences[0]` IS
the prev-or-at occurrence (the strip's `isPreviousOccurrence()` helper marks it as the
"previous" tile by comparing it to the anchor), and the rest ascend after it. PAGING is
a plain re-call with `anchor` set to the last occurrence already shown — there is no
separate cursor concept. `approximate` is always `false` in v2 (kept only for
response-shape stability). A pre-implementation sketch of this contract once proposed
the richer `previous`/`cursor` shape; ADR-0012 §4 explicitly rejects it in favor of the
flatter one actually shipped, for simplicity of the wire contract. One call —
`store.schedulePreview(config, { count?, anchor? })`.

#### 4.5.5 Manual builder — three tabs (Czas | Dzień | Miesiąc) with self-configuring option cards

> **REV5 supersede marker.** REV4 rendered each tab as a `SegmentedControl` of
> sub-mode cards WITH the active sub-mode's controls stacked in separate `FormField`
> rows BELOW the cards. The user's feedback: "if you pick an option in the tabs, the
> inputs for its settings should be placed visually INSIDE that option, phrased in
> natural language." REV5 therefore replaces the `SegmentedControl`+below-controls
> per tab with a new LOCAL component **`WorkflowScheduleOptionCards.vue`** — a vertical
> radio-group of selection cards where the SELECTED card EXPANDS to reveal its own
> inputs woven into a sentence. The `SegmentedControl` PRIMITIVE is UNTOUCHED (its
> other consumers stay pinned to their specs — `§4.4`, `§4.6`, `§5.2`).

The builder body is still a **`Tabs` (`variant="underline"`, `size="md"`,
`ariaLabel=workflows.schedule.tabsAria`)** with exactly three tabs, one per axis:
`workflows.schedule.tab.time` / `.day` / `.month` (icons `clock` / `calendar-days` /
`calendar`). Each tab **panel** now holds ONE `WorkflowScheduleOptionCards` group.
**Never tabs-in-tabs** — the tab switches the AXIS; the cards switch the sub-mode;
sub-mode controls live INSIDE the selected card. Each tab label keeps at most a
dot/none `Badge` (the full sentence lives in the summary `§4.5.3`).

**The option-card pattern (`WorkflowScheduleOptionCards.vue`).** A `role=radiogroup`
(`aria-label` = the axis) of stacked cards, each in the selection-card spirit
(border + tint + radio indicator when chosen, never color alone). REV5 splits each
card into a **radio header** and an **optional expanding body**:

- **Card header = the radio.** A `button role="radio"` with `aria-checked`, carrying a
  radio-dot indicator + the option **title** (`workflows.schedule.<axis>.mode.*`). For
  an UNSELECTED card the header shows the **title ONLY** — no description, no inputs —
  so the tab reads as a compact vertical list where only the active option is "open"
  (decision B(4): unselected = title only, for compactness; it also complements the
  header compaction of decision A).
- **Card body = a SEPARATE region OUTSIDE the button.** The selected card's body is a
  sibling of the header inside the same bordered card container (visual continuity via
  the shared border + tint), NOT a child of the `role="radio"` button — because a
  `radio` MUST NOT contain interactive controls. The body is `role="group"`
  (`aria-label` = the option title) and holds that sub-mode's inputs woven into a
  natural-language sentence (`§4.5.5a`–`§4.5.5c`; the "od–do" window is `§4.5.6`).
  Modes with no inputs (`every_day`, `every_month`, `last_day`, `last_working_day`)
  render an empty or note-only body.

- **Keyboard / focus model (decision B(1)).**
  - The card HEADERS form the radio group: roving tabindex (only the selected header is
    in the tab order), arrow keys (↑/↓ **and** ←/→) move + select between headers,
    Space/Enter selects, `Home`/`End` jump — the standard radio pattern.
  - **Tab from the selected header moves focus INTO that card's body** (its first
    input), then through the body inputs in order, then out to the next control after
    the group. Because only the selected card has a body, there is exactly ONE body in
    the tab path — no ambiguity.
  - Disabled cards (the time cards under `last_working_day`, `§4.5.5a`) keep
    `aria-disabled` + a visible explanation (`reference-links.md` "disabled buttons:
    don't just gray out, explain"), never a bare gray-out.

**Why option cards, not a `Select`.** The set per axis is 3–7 options that each imply a
*different set of settings*; showing all options at once with the chosen one expanded
in-place makes the current choice obvious AND puts its inputs where the eye already is,
with arrow-key `radiogroup` nav — a `Select` would hide the alternatives and detach the
settings from the choice. Cards stack **one per row** (full width) so the in-sentence
body has room to read as a sentence; on `next-sm+` the SHORT no-input cards may pack
two-up, but any card with a body spans full width.

**Panels become declarative (decision B(5)).** `WorkflowScheduleTimePanel` /
`…DayPanel` / `…MonthPanel` KEEP their axis-specific mutation logic (mode-switch
reshaping, window toggling, chip grids, the ordinal/weekday selects, the
`last_working_day` coupling) but now render their sub-modes THROUGH
`WorkflowScheduleOptionCards`: each panel passes the option list (`{value, title,
disabled?, disabledNote?}`) for the headers and fills a per-option **body slot**
(`#body-<value>`) with that mode's in-sentence controls. The new component owns the
card chrome + radio a11y + focus model; the panels own the body content — the smallest
safe split (one new presentational/interaction component; no logic rewrite).

##### 4.5.5a Tab: **Czas** (the `time` axis)

`WorkflowScheduleOptionCards` (`workflows.schedule.time.mode.*`), 3 cards. Card TITLE is
the header; the **selected card body** weaves the controls into a sentence (numeric
`{slot}`s are `NumberInput`s, `{from}`/`{to}` are the `§4.5.6` window pair). The `field.*`
/ `window.*` keys survive as the inputs' `aria-label`s; the VISIBLE text is the slotted
template (`§4.5.12`).

| Card (sub-mode) | Wire | Selected-card body (in-sentence) |
| --- | --- | --- |
| **O określonych godzinach** (`at`) | `{mode:'at', at[]}` | Body lead `time.card.at.lead` ("Uruchom o wskazanych godzinach:") then a **1–6 `TimePicker` list flowing LEFT→RIGHT on one wrapping line** (`w-44 shrink-0 basis-44` each — wide enough for "09:00" WITH the built-in ✕; REV5.1/5.2): `Button variant="outline" size="sm" leading-icon="plus"` `field.addTime` appends inline (disabled at 6); removal = each picker's **built-in `clearable` ✕ INSIDE the shell** (`:clearable="length > 1"`; clearing a time removes its row, hidden at 1 — no external x button; `field.removeTime` is retired from the view). Displayed sorted; duplicates flagged (`§4.5.11`). Editable in place. |
| **Co X minut** (`every_minutes`) | `{mode:'every_minutes', n, window?}` | Slotted sentence `time.card.everyMinutes.head` **"co {n} minut"** — `{n}` = `NumberInput` (`min 1 max 59`, default 1, aria `field.minutesEvery`) — followed by the inline **od–do window** (`§4.5.6`), `time.card.everyMinutes.window` **"od {from} do {to}"** with two **`TimePicker`s** (`HH:mm`, minute-precise, e.g. 09:30–17:45), gated by the inline window checkbox (default OFF). |
| **Co X godzin** (`every_hours`) | `{mode:'every_hours', n, minute, window?}` | Slotted sentence `time.card.everyHours.head` **"co {n} godz. o {minute} min po pełnej godzinie"** — `{n}` = `NumberInput` (`min 1 max 23`, default 1, aria `field.hoursEvery`), `{minute}` = `NumberInput` (`min 0 max 59`, default 0, aria `field.minute`) — followed by the inline **od–do window** (`§4.5.6`), `time.card.everyHours.window` **"od {from} do {to}"** with two whole-hour **`NumberInput`s** (`0..23`, aria `window.fromHour`/`.toHour`; rendered `HH:00`). |

- **`last_working_day` RESTRICTION (surface, don't hide — `reference-links.md`
  "disabled buttons: don't just gray out, explain").** When the Day tab's sub-mode is
  `special/last_working_day`, the **Co X minut** and **Co X godzin** cards render
  `disabled` AND a helper `Alert variant="info" size="sm"`
  `workflows.schedule.time.lockedByLastWorkingDay` ("Reguła „ostatni dzień roboczy"
  działa tylko z określonymi godzinami.") appears under the cards. If the time axis was
  `every_minutes`/`every_hours` at the moment `last_working_day` is chosen, the time
  axis **auto-resets to `at`** (seeding `['09:00']` when empty) and a one-time inline
  note `workflows.schedule.time.switchedToAt` explains the switch. This is the ONE
  cross-axis coupling; everything else is independent.

##### 4.5.5b Tab: **Dzień** (the `day` axis)

`WorkflowScheduleOptionCards` (`workflows.schedule.day.mode.*`), **7 flat cards** — the
model's `special{}` union is presented flat (one mode-selector per tab, no nested
selector), and the FE maps cards 5–7 onto `day.mode='special'`. Selected-card bodies:

| Card (sub-mode) | Wire | Selected-card body (in-sentence) |
| --- | --- | --- |
| **Codziennie** (`every_day`) | `{mode:'every_day'}` | none (neutral) — empty body. |
| **Co X dni** (`every_n_days`) | `{mode:'every_n_days', n, window?}` | Slotted sentence `day.card.everyNDays.head` **"co {n} dni"** — `{n}` = `NumberInput` (`min 1 max 31`, default 1, aria `.daysEvery`) — followed by the inline **od–do window** (`§4.5.6`), `day.card.everyNDays.window` **"od {from} do {to} dnia miesiąca"** with two **`NumberInput`s** (day-of-month `1..31`, aria `.window.fromDay`/`.toDay`). |
| **W dni tygodnia** (`weekdays`) | `{mode:'weekdays', weekdays[]}` | Body lead `day.card.weekdays.lead` then **Monday-first weekday chips** (`aria-pressed` `Button`s; wire array stays `0=Sunday`, labels `weekday.short.<0..6>`) + two shortcut `Button variant="ghost" size="sm"`: `day.preset.workdays` ("Dni robocze" → [1–5]) / `.weekend` ("Weekend" → [0,6]). Non-empty required. |
| **W dni miesiąca** (`month_days`) | `{mode:'month_days', days[]}` | Body lead `day.card.monthDays.lead` then a **wrapping chip grid of days 1..31** (`aria-pressed` `Button`s, `size="icon-sm"` tabular), calendar-like. Non-empty required. |
| **Ostatni dzień miesiąca** (`special/last_day`) | `{mode:'special', special:{kind:'last_day'}}` | none — empty body. |
| **Ostatni dzień roboczy** (`special/last_working_day`) | `{mode:'special', special:{kind:'last_working_day'}}` | note-only body: a helper `Alert size="sm"` `day.lastWorkingDayNote` (public holidays NOT counted) + the time-restriction reminder (`§4.5.5a`). Because unselected cards show title only (B(4)), this restriction copy lives in the SELECTED body, not on the resting card. |
| **Określony dzień tygodnia** (`special/nth_weekday` \| `last_weekday`) | see below | Slotted sentence `day.card.weekdayInMonth.head` **"w {ordinal} {weekday} miesiąca"** — `{ordinal}` = the ordinal `Select`, `{weekday}` = the weekday `Select` (§ note) — + the 5th-week note when ordinal = 5. |

- **The ordinal Select — the "ordinal 5 = piąty, NOT ostatni" trap, resolved by
  design.** The **Określony dzień tygodnia** card unifies the model's `nth_weekday`
  (ordinal 1..5) and `last_weekday` under ONE control pair so the two are never
  confused. Ordinal `Select` (`workflows.schedule.day.ordinal.*`) options:
  **pierwszy (1) / drugi (2) / trzeci (3) / czwarty (4) / piąty (5) / ostatni (last)**.
  Weekday `Select` (`workflows.schedule.weekday.long.<0..6>`, 0=Sunday).
  - ordinal **1..5** → `special.kind='nth_weekday'` `{ordinal, weekday}` — "piąty" is a
    genuine 5th-occurrence rule that **can skip months** (helper `Alert size="sm"`
    `workflows.schedule.day.fifthWeekdayNote` when ordinal=5).
  - ordinal **"ostatni"** → `special.kind='last_weekday'` `{weekday}` — a GUARANTEED
    monthly fire. "piąty" and "ostatni" are two explicit, separate options; there is no
    place where a "5th" is mislabelled "last".

##### 4.5.5c Tab: **Miesiąc** (the `month` axis)

`WorkflowScheduleOptionCards` (`workflows.schedule.month.mode.*`), 3 cards.
Selected-card bodies:

| Card (sub-mode) | Wire | Selected-card body (in-sentence) |
| --- | --- | --- |
| **Co miesiąc** (`every_month`) | `{mode:'every_month'}` | none (neutral) — empty body. |
| **Co X miesięcy** (`every_n_months`) | `{mode:'every_n_months', n, window?}` | Slotted sentence `month.card.everyNMonths.head` **"co {n} miesięcy"** — `{n}` = `NumberInput` (`min 1 max 12`, default 1, aria `.monthsEvery`) — + a helper `Alert size="sm"` `month.everyNNote` when `12 % n !== 0` (the grid counts from January, resets at year end) + the inline **od–do window** (`§4.5.6`), `month.card.everyNMonths.window` **"od {from} do {to}"** with two **month `Select`s** (`month.long.<1..12>`, aria `.window.fromMonth`/`.toMonth`). |
| **W wybrane miesiące** (`months`) | `{mode:'months', months[]}` | Body lead `month.card.months.lead` then a **month chip grid** (`month.short.<1..12>` — sty…gru), 6×2 / wraps; `aria-pressed` `Button`s. Non-empty required. |

#### 4.5.6 The "od–do" window pattern — now IN-SENTENCE (jointed fields, reused across four axes)

> **REV5 supersede marker.** REV4 rendered the window as a `Checkbox` ABOVE a
> separately-stacked jointed "od [ ] do [ ]" `FormField` block, `pl-next-6` under the
> `n` field. REV5 weaves it into the option-card sentence: the checkbox and the
> "od [from] do [to]" fragment sit INLINE, continuing the same wrapping line as "co
> {n} minut" — this is the user's "gdzie Y i Z domyślnie wyłączone" pattern (a
> checkbox that turns on a sentence FRAGMENT carrying the inputs). `WorkflowSchedule
> WindowField.vue` is REPURPOSED to render inline (it keeps its name + reuse).

`every_minutes`, `every_hours`, `every_n_days`, and `every_n_months` each expose an
OPTIONAL bound window. It is the SAME pattern everywhere so it is learned once:

- **REV5.2 correction (user feedback on the built REV5.1):** the gate is a **bare
  `Switch`** (`role="switch"`, NO visible text — `workflows.schedule.window.toggle.<axis>`
  survives ONLY as its `aria-label`), and the "od {from} do {to}" fragment is rendered
  **ALWAYS** — the switch toggles the two inputs' **`disabled`** state instead of
  revealing/hiding the fragment. This is the user's original "od Y do Z, gdzie Y i Z
  jest domyślnie WYŁĄCZONE" read literally: the fields are visible but disabled until
  switched on. Default OFF still means the window is omitted from the wire. **Why
  `Switch`, not `Checkbox`:** the bare (label-less) `Checkbox` primitive cannot carry an
  accessible name without modifying the primitive (its root is a `<label>`; a fallthrough
  `aria-label` never names the input), while `Switch` puts `aria-label` directly on the
  `role="switch"` button and renders zero visible text without a `label`. Disabled
  literals ("od"/"do") dim via `opacity-60`.
- The **jointed field fragment** stays INLINE (`reference-links.md` "jointed fields:
  simplify two-column forms") — the slotted template `<axis>.card.<mode>.window` reading
  **"od {from} do {to}"** (…`dnia miesiąca` for the day axis) with the two inputs woven
  at `{from}`/`{to}`, on the SAME `flex flex-wrap items-center gap-next-2` line as the
  head, NOT a `pl-next-6` block below. `WorkflowScheduleWindowField` exposes a
  `{ disabled }` slot prop the panels bind onto the pair. The pair is per `§4.5.5`
  (two `TimePicker`s `w-36 shrink-0 basis-36` — REV5.2 widened from `w-28`, which
  truncated "09:00" to "0…" / two hour `NumberInput`s / two day `NumberInput`s /
  two month `Select`s); `window.from`/`.to`/`.fromHour`/… survive as the inputs'
  `aria-label`s.
- **Live `from < to` invariant** (strict): on violation show
  `workflows.schedule.validation.windowOrder` under the sentence and block save
  (`§4.5.11`) — the user never learns of it first from a 422.

The only remaining invariant is the window's `from < to`, plus the two contextual notes
(`fifthWeekdayNote`, `everyNNote`) inside their cards. **REV5 removes the `dstNote`**
from the builder (the tz field is gone — `§4.5.8`).

#### 4.5.7 Exclusions — a collapsible "Wyjątki" disclosure (REV5: skip-DATES only)

> **REV5 supersede marker.** REV4's disclosure held THREE filters — skip-dates PLUS
> "oprócz dni tygodnia" / "oprócz miesięcy" chip grids. The user's feedback: choosing
> weekdays/months to EXCLUDE is redundant, because the same effect is achieved by
> setting the **Dzień** / **Miesiąc** axes appropriately (`§4.5.5b`/`§4.5.5c`). REV5
> therefore removes the weekday and month exclusion chips from the UI; the disclosure
> holds ONLY skip-dates. **The backend still ACCEPTS `exclusions.weekdays[]` /
> `exclusions.months[]`** (the wire shape is unchanged) — the FE simply stops AUTHORING
> them; an edited legacy/AI-applied config that carries them still round-trips and is
> still described by the sentence (`§4.5.10`). See the read-side note below.

Because the POSITIVE selection lives in the tabs (`§4.5.5`), the remaining exclusion is
framed as a **subtraction** ("oprócz konkretnych dni") tucked into a
**collapsed-by-default disclosure** so a simple schedule never sees it. Keep
`Accordion type="single"` with one `AccordionItem value="exclusions"` (reuse the
existing primitive — collapse-by-default still earns its keep even with one control):

- **Header:** `workflows.schedule.exclusions.title` ("Wyjątki") + an
  `Icon name="calendar-x"` + a **count `Badge variant="neutral" tone="subtle"`** = the
  DATES count only (hidden at 0). A short helper line `workflows.schedule.exclusions.hint`
  under the header when open.
- **Body (one filter):** **Pomiń konkretne dni**
  (`workflows.schedule.exclusions.datesLabel`): a `DatePicker` (`yyyy-mm-dd` model) +
  `Button variant="outline" size="sm" leading-icon="plus"` `.addDate` → a removable
  **chip list** (each chip a `font-next-mono` `Y-m-d` + `Button size="icon-xs"
  leading-icon="x"` `.removeDate`), sorted + de-duplicated, **max 50** (add disabled at
  50, hint `.datesMax`); `.datesEmpty` muted line when none. Same chip pattern as the
  create_task exclusion-date idiom — consistency, not a new widget.
- **Weekday/month exclusions reachable via the axes:** if a user wants "never on
  weekends" or "not in July", they set the **Dzień**/**Miesiąc** axis positively (e.g.
  weekdays [1–5], or months excluding 7). The disclosure no longer duplicates that as a
  subtraction. (Backend support is retained for API/legacy callers — `§4.5.15` store
  note.)
- **No cap gymnastics in the UI:** a genuinely unfireable COMBINATION (e.g. `month_days
  [31] ∧ months [2]`) is caught by the **preview `empty` gate** (`§4.5.4` / `§4.5.11`),
  not by per-control errors — the strip's warning is the single, honest signal.

#### 4.5.8 Timezone (REV5: field REMOVED from the UI; the MODEL keeps `tz`)

> **REV5 supersede marker.** REV4 exposed `tz` as a `TextInput` (label/hint/utc
> placeholder + a `dstNote`). The user's feedback: choosing a timezone is unnecessary.
> REV5 removes the field and its `dstNote` from the builder. The `tz` DATA is retained
> end-to-end — the model, the draft, `configToDraft`/`draftToConfig`, the preview
> `renderZone`, and the AI hint are unchanged.

- **No tz control anywhere in the schedule panel.** The `tz.label`/`.hint`/`.utc`/
  `.dstNote` keys are no longer rendered by the builder (marked superseded in
  `§4.5.12`).
- **Where the tz value now comes from (the model behavior to implement):**
  - **New schedule** → the neutral draft SEEDS `tz` to the browser's active IANA zone
    (`Intl.DateTimeFormat().resolvedOptions().timeZone`), so "Codziennie o 09:00"
    created in Warsaw actually runs at 09:00 Warsaw — matching the preview, which
    already renders in the browser zone. This is the "nowe = strefa przeglądarki"
    behavior; it is the ONLY change to `emptyScheduleDraft` (a small, contained touch in
    `workflowSchedule.ts`, flagged in `§4.5.15`). If `Intl` is unavailable the seed
    falls back to `''` (⇒ server UTC), exactly as before.
  - **Editing** → the saved `tz` round-trips untouched via `configToDraft`/
    `draftToConfig` (no change). A schedule saved in a foreign zone keeps that zone.
  - `draftToConfig` still omits `tz` when blank; a seeded new schedule simply carries a
    non-blank zone, so it wires an explicit `tz`.
- **Rendering** (unchanged): the preview strip and the summary render occurrences in the
  schedule `tz` (or the active browser tz when blank — `§4.5.4`); the AI modal is sent
  the **active timezone** as its `tz` hint (`§4.5.9`).
- **The "({tz})" sentence clause** is now CONDITIONAL — see the tz-clause rule in
  `§4.5.10` (decision C): it shows ONLY when the schedule's `tz` differs from the
  viewer's active zone, so a user's own new schedule in their own zone reads clean and
  only a foreign/legacy zone surfaces the label.

#### 4.5.9 `WorkflowScheduleAssistModal` — AI natural language (no auto-apply)

Opened by the summary's "Zaplanuj z AI" (§4.5.3). A **`Modal size="lg"`** (focus-
trapped, Esc/scrim-closable — the primitive handles it), hosted by the schedule panel.
It is a BUILDER AID that **prefills** the draft; the drawer's Save still re-validates.
**The REV3 auto-apply is GONE** — even a feasible result is shown as a *proposal* the
user commits with **Zastosuj**. This is `reference-links.md` "modal anatomy": one clear
title, one purpose, a primary action, a safe cancel, managed focus.

- **`#title`:** `workflows.schedule.assist.title` ("Opisz harmonogram słowami").
  **`#description`:** `workflows.schedule.assist.subtitle` (one line on what to type).
- **Body — composer:** a `Textarea` (`rows=3`, `maxlength=500`, `aria-label`
  `.inputLabel`, placeholder `.placeholder`) + a `Button variant="primary" size="sm"
  leading-icon="sparkles" :loading` `.run` ("Zaproponuj") that fires the request. On
  open, focus moves into the `Textarea`. Re-running replaces the current proposal.
- **Body — proposal card** (appears after a feasible/alternative response): a bordered
  `Surface`, showing the deterministic **`describeSchedule` sentence** for the proposed
  config (§4.5.10) as the headline "what you'll get", a **compact preview strip** (the
  §4.5.4 tile look — **REV5: inherits the compact two-line tiles** — next 4–5 runs, its
  own token-guarded `store.schedulePreview(config,
  5)` call, quietly dropping the list on error while keeping the sentence), and — for an
  ALTERNATIVE — the plain-text `alternative.note` + an "alternatywna propozycja" caption
  (`.alternativeTag`) so the user knows it is a fallback, not their literal ask.
- **Sticky `#footer`:** `Anuluj` (`Button variant="ghost"`, closes, discards) +
  **`Zastosuj`** (`Button variant="primary"`, DISABLED until a proposal exists). Zastosuj
  → `emit('apply', configToDraft(proposedConfig))`, close, and a success `useToast`
  `.appliedToast`. The whole draft (all three axes + exclusions + tz) is replaced; the
  summary, preview strip, and tabs re-render from it, and client validation (§4.5.11)
  re-runs so an applied config the user then edits is gated exactly like a hand-built one.

**Request:** `POST /workflows/schedule-assist { prompt (≤500), tz }` — always send the
**active timezone** (§4.5.8). Throttle 5/min/user → 429. **Envelope:** `{feasible,
config|null, unsupported: string[], alternative:{config, note}|null, explanation}`.
**All model text (`explanation`, `unsupported[]`, `note`) renders as PLAIN TEXT via
interpolation — never `v-html`** (untrusted output).

**States (design each):**

| State | Condition | Body | Footer `Zastosuj` |
| --- | --- | --- | --- |
| **Composing** | idle, before/after edits | just the composer (+ any prior proposal until re-run). | disabled unless a proposal is shown. |
| **Loading** | request in flight | composer disabled; `.run` `:loading` (stable width — `reference-links.md` "button loading state"); a `Skeleton` proposal card. | disabled. |
| **Proposal — feasible** | `feasible && config` | success `Alert size="sm"` (plain `explanation`) + proposal card for `config`. | **enabled** → applies `config`. |
| **Proposal — alternative** | `!feasible && alternative` | warning `Alert size="sm"` (`explanation`) + an `.unsupportedTitle` bulleted plain-text list + proposal card for `alternative.config` (with its `note` + `.alternativeTag`). | **enabled** → applies `alternative.config`. |
| **Infeasible, no alternative** | `!feasible && !alternative` | warning `Alert size="sm"` (`explanation`) + the unsupported list. No proposal card. | **disabled** (nothing to apply); user edits + re-runs or Anuluj. |
| **Failure / throttle** | 429 / network / parse | danger `Alert size="sm"`: `.throttled` (429) else `.failed` — FE-owned copy, **never the raw backend message**. Composer stays open to retry. | disabled. |

#### 4.5.10 `describeSchedule(draft, t, activeTz?)` — the sentence grammar (PL + EN)

> **REV5 supersede marker (tz clause only).** The GRAMMAR of the summary sentence is
> UNCHANGED by REV5 — the same `describe.*` templates, casing/plural machinery, and
> clause composition. TWO read-side rules change: (1) the tz clause is now CONDITIONAL
> on `tz !== activeTz` (decision C); the helper gains an OPTIONAL third param
> `activeTz` (defaulting to the resolved browser zone) — additive, so the existing
> callers (`§4.5.3`, `§3.2`, `§4.5.9`) keep working. (2) The exclusion clause STILL
> renders weekday/month exclusions when a config carries them (they are no longer
> AUTHORED in the UI — `§4.5.7` — but a legacy/AI/edited config may still carry them,
> and the sentence must stay honest); the grammar drops nothing.

A pure helper in `workflowSchedule.ts`, reused by the summary (`§4.5.3`), the detail
Trigger panel (`§3.2`), and the AI modal (`§4.5.9`). It produces **one string**.
Composition:

```
sentence = capitalize(TIME)                       // TIME always present; heads the sentence
         + (day.mode !== 'every_day'   ? ", " + DAY   : "")
         + (month.mode !== 'every_month' ? ", " + MONTH : "")
         + (hasExclusions ? describe.exclusionClause : "")   //  " — z wyjątkami: {list}"
         + (tz && tz !== activeTz ? describe.tzClause : "")   //  " ({tz})" — REV5: only when foreign
```

- **REV5 tz-clause rule (decision C).** `activeTz = Intl.DateTimeFormat().resolvedOptions().timeZone`
  (the viewer's zone; `''`/undefined ⇒ never suppress). The clause is emitted **only**
  when `draft.tz` is non-empty AND `draft.tz !== activeTz`. Consequences: a new schedule
  seeded to the browser zone (`§4.5.8`) reads with NO "(…)" tail; an edited schedule
  whose saved zone matches yours is likewise silent; only a schedule in a foreign/legacy
  zone surfaces "(Europe/Warsaw)". Justification: with the tz field gone, the clause's
  only remaining job is to WARN that a schedule runs in a zone other than the one you're
  reading it in — showing it for your own zone would be redundant noise.

- **Neutral collapse:** `every_day` and `every_month` produce NO clause. The friendly
  head form **"Codziennie o {t}"** (EN "Daily at {t}") is used **only** when
  `time.mode==='at' && time.at.length===1 && day.mode==='every_day'` — otherwise `at`
  heads as **"O {times}"** ("At {times}") and the day/month clauses append as usual.

**TIME head clause** (capitalized):

| Sub-mode | PL | EN | Example |
| --- | --- | --- | --- |
| `at` (1 time, `every_day`) | `describe.daily` "Codziennie o {t}" | "Daily at {t}" | **Codziennie o 09:00** |
| `at` (general) | `describe.at` "O {times}" | "At {times}" | **O 09:00 i 17:00** |
| `every_minutes` | `describe.everyMinutes` "Co {n} minut" | "Every {n} minutes" | Co 15 minut |
| `every_minutes` + window | `describe.everyMinutesWindow` "Co {n} minut między {from} a {to}" | "Every {n} minutes between {from} and {to}" | **Co 15 minut między 09:30 a 17:45** |
| `every_hours` | `describe.everyHours` "Co {n} godzin" (+ `describe.everyHoursMinute` "(o :{mm})" when `minute≠0`) | "Every {n} hours (at :{mm})" | Co 2 godziny (o :15) |
| `every_hours` + window | `describe.everyHoursWindow` "… między {from}:00 a {to}:00" | "… between {from}:00 and {to}:00" | **Co 2 godziny (o :15) między 08:00 a 18:00** |

**DAY clause** (lowercase, appended; omitted when `every_day`):

| Sub-mode | PL | EN | Example |
| --- | --- | --- | --- |
| `every_n_days` | `describe.everyNDays` "co {n} dni" | "every {n} days" | co 2 dni |
| `every_n_days` + window | `describe.everyNDaysWindow` "co {n} dni od {from}. do {to}. dnia miesiąca" | "every {n} days from the {from} to the {to} of the month" | **co 2 dni od 5. do 20. dnia miesiąca** |
| `weekdays` (general) | `describe.weekdays` "w {days}" (days = `weekdayPlural` joined) | "on {days}" | w poniedziałki i piątki |
| `weekdays` = [1–5] | `describe.workdays` "w dni robocze" | "on workdays" | **w dni robocze** |
| `weekdays` = [0,6] | `describe.weekend` "w weekendy" | "on weekends" | w weekendy |
| `month_days` | `describe.monthDays` "{days} dnia miesiąca" (days = ordinal-dot list) | "on the {days} of the month" | **1. i 15. dnia miesiąca** |
| `special/last_day` | `describe.lastDay` "ostatniego dnia miesiąca" | "on the last day of the month" | **ostatniego dnia miesiąca** |
| `special/last_working_day` | `describe.lastWorkingDay` "ostatniego dnia roboczego miesiąca" | "on the last working day of the month" | **ostatniego dnia roboczego miesiąca** |
| `special/nth_weekday` | `describe.nthWeekday` "w {ordinal}. {weekdayAcc} miesiąca" | "on the {ordinal-suffixed} {weekday} of the month" | **w 2. wtorek miesiąca** |
| `special/last_weekday` | `describe.lastWeekday` "w {lastWeekdayClause} miesiąca" | "on the last {weekday} of the month" | **w ostatni piątek miesiąca** |

**MONTH clause** (lowercase, appended; omitted when `every_month`):

| Sub-mode | PL | EN | Example |
| --- | --- | --- | --- |
| `every_n_months` | `describe.everyNMonths` "co {n} {miesiące\|miesięcy}" | "every {n} months" | co 2 miesiące |
| `every_n_months` + window | `describe.everyNMonthsWindow` "co {n} … od {monthGen from} do {monthGen to}" | "every {n} months from {month} to {month}" | **co 2 miesiące od marca do września** |
| `months` | `describe.months` "w {monthsLoc}" (locative list) | "in {months}" | **w styczniu i czerwcu** |

**EXCLUSION clause + tz + joins:**

- `describe.exclusionClause` = " — z wyjątkami: {list}" / " — except: {list}"; parts
  joined by `describe.exclusionSep` ("; "). Parts: weekdays →
  `describe.exclusionWeekdays` (plural list, or "weekendy"/"weekends" for [0,6]);
  months → `describe.exclusionMonths` (nominative list, e.g. "lipiec i sierpień");
  dates → `describe.exclusionDates` (plural count "{n} wybranych dni" / "{n} selected
  dates", or the single date when n=1).
- `describe.tzClause` = " ({tz})" — REV5: appended only when `tz !== activeTz` (above).
- List joins: `describe.listSep` (", ") + `describe.listLast` ("{init} i {last}" /
  "{init} and {last}").

**Full combined examples (AND across axes):**
- `at[08:00,17:00] ∧ weekdays[1,3,5] ∧ every_month, tz Europe/Warsaw` →
  **PL** "O 08:00 i 17:00, w poniedziałki, środy i piątki (Europe/Warsaw)" ·
  **EN** "At 08:00 and 17:00, on Mondays, Wednesdays and Fridays (Europe/Warsaw)".
- `at[09:00] ∧ every_day ∧ every_month, excl.months=[7,8]` →
  **PL** "Codziennie o 09:00 — z wyjątkami: lipiec i sierpień" ·
  **EN** "Daily at 09:00 — except: July and August".

**Polish casing/plural — a REQUIREMENT, not a nicety** (EN uses one form throughout).
Natural PL needs the month/weekday name in the right case per clause, so the inventory
(§4.5.12) ships FOUR month arrays (short/long-nominative/locative/genitive) and FIVE
weekday arrays (short/long-nominative/plural-acc/sing-acc/last-clause). Units go through
the i18n plural machinery (minuta·y·ø, godzina·y·ø, dni, miesiąc·e·y). EN ordinals use
`{n}ᵗʰ` suffixing (2nd, 15th).

#### 4.5.11 Client validation & the preview-driven save gate

The builder exposes `isValid` + `validationErrors` (consumed by
`WorkflowTriggerFields.scheduleValid()` → the drawer step/save gate). `isValid` =
**all axis rules pass AND `preview.empty !== true` AND `preview.loading !== true`**
(a loading preview blocks mid-flight so an empty schedule can't slip past before its
warning settles — as REV3). A network-failed preview does NOT block (server stays
authoritative). Per-axis rules (each shows its i18n error on the offending control):

- **time.at:** 1–6 entries, each a valid `HH:mm`, distinct, non-empty
  (`validation.timeRequired`/`.timeFormat`/`.timeDuplicate`/`.timesMax`).
- **time.every_minutes:** `n∈[1,59]`; window (if set) valid `HH:mm` + `from<to`.
- **time.every_hours:** `n∈[1,23]`, `minute∈[0,59]`; window (if set) `0..23` + `from<to`.
- **day.every_n_days:** `n∈[1,31]`; window (if set) `1..31` + `from<to`.
- **day.weekdays / month_days / month.months:** non-empty (`validation.pickAtLeastOne`).
- **day.special.nth_weekday:** `ordinal∈[1,5]`, weekday set; **last_weekday:** weekday set.
- **day.special.last_working_day:** requires `time.mode==='at'` (enforced by §4.5.5a's
  auto-reset + disabled cards, so this can't be reached; the guard stays as a belt-and-
  braces `validation.lastWorkingDayNeedsAt`).
- **month.every_n_months:** `n∈[1,12]`; window (if set) `1..12` + `from<to`.
- **windows everywhere:** `from<to` (`validation.windowOrder`).
- **exclusions.dates:** ≤50, valid, distinct.

**422 surfacing** (wire keys under `trigger_config.schedule.*` are `[backend-dep]`):
map `…schedule.time.*` → **Czas** tab, `…day.*` → **Dzień**, `…month.*` → **Miesiąc**,
`…exclusions.*` → **Wyjątki** disclosure (auto-open it) — SWITCH to the offending tab and
scroll the control into view. **REV5:** with the tz field removed (`§4.5.8`), a
`…schedule.tz` 422 has no control to attach to → surface it as the translated danger
toast (`workflows.editor.toasts.error`), same as any un-mappable 422. This mirrors
§4.10's overall mapping.

#### 4.5.12 i18n inventory (`workflows.schedule.*` — PL + EN, FE transcribes in Phase 5)

Authoritative REV4 key set; **supersedes** the `workflows.schedule.*` sketch in §7.1
(the REV3 `intent.*`/`family.*`/`tier.*`/`simple.*`/`mode.*`/`advancedToggle`/
`approximate` keys are **removed**). Every visible string, placeholder, aria-label and
state line goes through `t()`.

> **REV5 delta (read with the REV5 markers on the rows below).** REV5 ADDS the in-card
> slotted-sentence keys (new block "In-card slotted sentences (REV5)" after Month
> modes), REWORDS the `window.toggle.*` labels to read as sentence continuations,
> REPURPOSES `preview.title` (now the rail's `aria-label`, not a visible heading) and
> `preview.jumpTo` (now the compact anchor field's placeholder + `aria-label`, REV5.1), and marks
> **SUPERSEDED** the tz-field keys (`tz.label`/`.hint`/`.utc`/`.dstNote`) and the
> weekday/month exclusion labels (`exclusions.weekdaysLabel`/`.monthsLabel`) — those
> controls are gone (`§4.5.7`, `§4.5.8`). **Segmentation mechanism:** the `next` i18n
> is string-only (`t()` interpolates `{param}`, no component slots), so each in-card
> sentence is a normal translated string with `{slot}` tokens; the FE renders it by
> SPLITTING on the `/(\{[a-z]+\})/` token regex into an ordered `text | {slot} | text`
> list, emitting a `<span>` per literal and the mapped control per slot. Because the
> WORD ORDER lives in the locale STRING (not in component markup), PL and EN reorder
> slots freely — the "tablice segmentów per tryb" requirement, satisfied without arrays.
> Slot ids per mode: `{n}`, `{minute}`, `{from}`, `{to}`, `{ordinal}`, `{weekday}`.

| Key (under `workflows.schedule.`) | PL | EN |
| --- | --- | --- |
| `tabsAria` | Osie harmonogramu | Schedule axes |
| `tab.time` / `.day` / `.month` | Czas / Dzień / Miesiąc | Time / Day / Month |
| `summary.assist` | Zaplanuj z AI | Plan with AI |
| **Time modes** | | |
| `time.mode.at` | O określonych godzinach | At set times |
| `time.mode.everyMinutes` | Co kilka minut | Every few minutes |
| `time.mode.everyHours` | Co kilka godzin | Every few hours |
| `time.lockedByLastWorkingDay` | Reguła „ostatni dzień roboczy" działa tylko z określonymi godzinami. | The "last working day" rule only works with set times. |
| `time.switchedToAt` | Przełączono na określone godziny — wymaga ich reguła „ostatni dzień roboczy". | Switched to set times — the "last working day" rule needs them. |
| **Day modes** | | |
| `day.mode.everyDay` | Codziennie | Every day |
| `day.mode.everyNDays` | Co kilka dni | Every few days |
| `day.mode.weekdays` | W dni tygodnia | On weekdays |
| `day.mode.monthDays` | W dni miesiąca | On days of the month |
| `day.mode.lastDay` | Ostatni dzień miesiąca | Last day of the month |
| `day.mode.lastWorkingDay` | Ostatni dzień roboczy | Last working day |
| `day.mode.weekdayInMonth` | Określony dzień tygodnia | A specific weekday |
| `day.preset.workdays` / `.weekend` | Dni robocze / Weekend | Workdays / Weekend |
| `day.ordinal.1`–`.5` | pierwszy / drugi / trzeci / czwarty / piąty | first / second / third / fourth / fifth |
| `day.ordinal.last` | ostatni | last |
| `day.ordinalLabel` / `.weekdayLabel` | Który / Dzień tygodnia | Which / Weekday |
| `day.fifthWeekdayNote` | „Piąty" występuje nie w każdym miesiącu — wtedy uruchomienie zostaje pominięte. | A "fifth" doesn't occur every month — those months are skipped. |
| `day.lastWorkingDayNote` | Dni ustawowo wolne nie są uwzględniane. | Public holidays are not taken into account. |
| **Month modes** | | |
| `month.mode.everyMonth` | Co miesiąc | Every month |
| `month.mode.everyNMonths` | Co kilka miesięcy | Every few months |
| `month.mode.months` | W wybrane miesiące | In selected months |
| `month.everyNNote` | Miesiące liczone są od stycznia i resetują się z końcem roku. | Months are counted from January and reset at year end. |
| **In-card slotted sentences (REV5 — NEW; `{…}` = input slot)** | | |
| `time.card.at.lead` | Uruchom o wskazanych godzinach: | Run at the listed times: |
| `time.card.everyMinutes.head` | co {n} minut | every {n} minutes |
| `time.card.everyMinutes.window` | od {from} do {to} | from {from} to {to} |
| `time.card.everyHours.head` | co {n} godz. o {minute} min po pełnej godzinie | every {n} h, at {minute} min past the hour |
| `time.card.everyHours.window` | od {from} do {to} | from {from} to {to} |
| `day.card.everyNDays.head` | co {n} dni | every {n} days |
| `day.card.everyNDays.window` | od {from} do {to} dnia miesiąca | from day {from} to day {to} |
| `day.card.weekdays.lead` | W wybrane dni tygodnia: | On selected weekdays: |
| `day.card.monthDays.lead` | W wybrane dni miesiąca: | On selected days of the month: |
| `day.card.weekdayInMonth.head` | w {ordinal} {weekday} miesiąca | on the {ordinal} {weekday} of the month |
| `month.card.everyNMonths.head` | co {n} miesięcy | every {n} months |
| `month.card.everyNMonths.window` | od {from} do {to} | from {from} to {to} |
| `month.card.months.lead` | W wybrane miesiące: | In selected months: |
| **Fields / units** | | |
| `field.minutesEvery` / `.hoursEvery` / `.daysEvery` / `.monthsEvery` | Co ile minut / godzin / dni / miesięcy | Every N minutes / hours / days / months |
| `field.minute` / `.minuteHint` | Minuta / liczona od pełnej godziny | Minute / counted from the top of the hour |
| `field.times` / `.addTime` / `.removeTime` *(REV5.2: `.removeTime` retired from the view — removal is the picker's built-in ✕; key kept for PL/EN parity)* | Godziny / Dodaj godzinę / Usuń godzinę | Times / Add time / Remove time |
| `unit.min` / `.h` | min / godz. | min / h |
| **Window** | | |
| `window.toggle.time` *(REV5 reword)* | w wybranych godzinach | within set hours |
| `window.toggle.hours` *(REV5 reword)* | w wybranych godzinach | within set hours |
| `window.toggle.days` *(REV5 reword)* | w wybranych dniach miesiąca | within set month days |
| `window.toggle.months` *(REV5 reword)* | w wybranych miesiącach | within set months |
| `window.from` / `.to` | od / do | from / to |
| `window.fromHour` / `.toHour` | Od godziny / Do godziny | From hour / To hour |
| `window.fromDay` / `.toDay` | Od dnia / Do dnia | From day / To day |
| `window.fromMonth` / `.toMonth` | Od miesiąca / Do miesiąca | From month / To month |
| **Weekday / month names** | | |
| `weekday.short.0`–`.6` | nd, pn, wt, śr, cz, pt, sb | Sun, Mon, Tue, Wed, Thu, Fri, Sat |
| `weekday.long.0`–`.6` | niedziela … sobota | Sunday … Saturday |
| `month.short.1`–`.12` | sty … gru | Jan … Dec |
| `month.long.1`–`.12` | styczeń … grudzień | January … December |
| **Preview strip** | | |
| `preview.title` *(REV5: now the rail's `aria-label`, not a visible `<h4>`)* | Najbliższe uruchomienia | Upcoming runs |
| `preview.loading` | Wczytywanie… | Loading… |
| `preview.empty` | Ten harmonogram nigdy się nie uruchomi — wyjątki lub wybór dni wykluczają każdy termin. | This schedule will never run — exceptions or day choices rule out every time. |
| `preview.unavailable` | Nie można teraz wczytać podglądu. | The preview can't be loaded right now. |
| `preview.jumpTo` *(REV5.1: the compact anchor field's placeholder + `aria-label`, in the segment row)* | Skocz do daty | Jump to date |
| `preview.previousTile` | poprzednie | previous |
| `preview.tileAria` | {weekday}, {date}, {time} | {weekday}, {date}, {time} |
| `preview.loadMore` | Wczytaj kolejne | Load more |
| **AI modal** | | |
| `assist.title` | Opisz harmonogram słowami | Describe the schedule in words |
| `assist.subtitle` | Np. „w każdy ostatni piątek miesiąca o 15:00". | e.g. "every last Friday of the month at 15:00". |
| `assist.placeholder` | Wpisz, jak często ma się uruchamiać… | Type how often it should run… |
| `assist.inputLabel` | Opis harmonogramu | Schedule description |
| `assist.run` | Zaproponuj | Suggest |
| `assist.apply` | Zastosuj | Apply |
| `assist.previewLabel` | Podgląd | Preview |
| `assist.alternativeTag` | propozycja alternatywna | suggested alternative |
| `assist.unsupportedTitle` | Czego nie udało się odwzorować: | What couldn't be mapped: |
| `assist.throttled` | Za dużo prób. Odczekaj chwilę i spróbuj ponownie. | Too many attempts. Wait a moment and try again. |
| `assist.failed` | Nie udało się przygotować propozycji. Spróbuj ponownie lub ustaw ręcznie. | Couldn't prepare a suggestion. Try again or set it manually. |
| `assist.appliedToast` | Zastosowano harmonogram z propozycji AI. | Applied the AI-suggested schedule. |
| **Exclusions** | | |
| `exclusions.title` | Wyjątki | Exceptions |
| `exclusions.hint` | Pomiń wybrane terminy bez zmiany reguły powyżej. | Skip selected times without changing the rule above. |
| `exclusions.datesLabel` | Pomiń konkretne dni | Skip specific dates |
| `exclusions.addDate` / `.removeDate` | Dodaj datę / Usuń datę | Add date / Remove date |
| `exclusions.datesEmpty` | Brak pominiętych dni. | No skipped dates. |
| `exclusions.datesMax` | Maksymalnie 50 dni. | Up to 50 dates. |
| ~~`exclusions.weekdaysLabel`~~ *(SUPERSEDED REV5 — chips removed, `§4.5.7`)* | ~~Oprócz dni tygodnia~~ | ~~Except weekdays~~ |
| ~~`exclusions.monthsLabel`~~ *(SUPERSEDED REV5 — chips removed, `§4.5.7`)* | ~~Oprócz miesięcy~~ | ~~Except months~~ |
| **Timezone (SUPERSEDED REV5 — field removed from the builder, `§4.5.8`; the `tz` DATA stays in the model/wire, these STRINGS are no longer rendered)** | | |
| ~~`tz.label` / `.hint` / `.utc`~~ | ~~Strefa czasowa / Puste = UTC / UTC~~ | ~~Timezone / Empty = UTC / UTC~~ |
| ~~`tz.dstNote`~~ | ~~Uwaga na zmianę czasu: nieistniejące godziny przesuwają się, powtórzone mogą uruchomić się dwukrotnie.~~ | ~~Mind clock changes: non-existent times shift forward, repeated times can run twice.~~ |
| **Validation** | | |
| `validation.timeRequired` / `.timeFormat` / `.timeDuplicate` / `.timesMax` | Podaj godzinę / Nieprawidłowa godzina / Godzina się powtarza / Maksymalnie 6 godzin | Enter a time / Invalid time / Duplicate time / Up to 6 times |
| `validation.pickAtLeastOne` | Wybierz co najmniej jeden | Pick at least one |
| `validation.windowOrder` | „Od" musi być wcześniejsze niż „do" | "From" must be earlier than "to" |
| `validation.number` / `.min` / `.max` | Podaj liczbę / Min. {min} / Maks. {max} | Enter a number / Min {min} / Max {max} |
| `validation.lastWorkingDayNeedsAt` | Ta reguła wymaga określonych godzin. | This rule requires set times. |
| **describe.* (grammar templates + cased names)** | | |
| `describe.daily` | Codziennie o {t} | Daily at {t} |
| `describe.at` | O {times} | At {times} |
| `describe.everyMinutes` / `.everyMinutesWindow` | Co {n} minut / …między {from} a {to} | Every {n} minutes / …between {from} and {to} |
| `describe.everyHours` / `.everyHoursMinute` / `.everyHoursWindow` | Co {n} godzin / (o :{mm}) / …między {from}:00 a {to}:00 | Every {n} hours / (at :{mm}) / …between {from}:00 and {to}:00 |
| `describe.everyNDays` / `.everyNDaysWindow` | co {n} dni / …od {from}. do {to}. dnia miesiąca | every {n} days / …from the {from} to the {to} of the month |
| `describe.weekdays` / `.workdays` / `.weekend` | w {days} / w dni robocze / w weekendy | on {days} / on workdays / on weekends |
| `describe.monthDays` | {days} dnia miesiąca | on the {days} of the month |
| `describe.lastDay` / `.lastWorkingDay` | ostatniego dnia miesiąca / ostatniego dnia roboczego miesiąca | on the last day / on the last working day of the month |
| `describe.nthWeekday` / `.lastWeekday` | w {ordinal}. {weekdayAcc} miesiąca / w {lastWeekdayClause} miesiąca | on the {ord} {weekday} / on the last {weekday} of the month |
| `describe.everyNMonths` / `.everyNMonthsWindow` | co {n} {miesiące\|miesięcy} / …od {from} do {to} | every {n} months / …from {from} to {to} |
| `describe.months` | w {months} | in {months} |
| `describe.exclusionClause` / `.exclusionSep` | — z wyjątkami: {list} / "; " | — except: {list} / "; " |
| `describe.exclusionWeekdays` / `.exclusionMonths` / `.exclusionDates` | {days} / {months} / {n, plural: 1{1 wybrany dzień} few{# wybrane dni} other{# wybranych dni}} | {days} / {months} / {n} selected date(s) |
| `describe.tzClause` | " ({tz})" | " ({tz})" |
| `describe.listSep` / `.listLast` | ", " / {init} i {last} | ", " / {init} and {last} |
| `describe.monthIn.1`–`.12` (locative) | styczniu … grudniu | (EN reuses `month.long`) |
| `describe.monthGen.1`–`.12` (genitive) | stycznia … grudnia | (EN reuses `month.long`) |
| `describe.weekdayPlural.0`–`.6` (acc. pl.) | niedziele, poniedziałki, wtorki, środy, czwartki, piątki, soboty | (EN reuses `weekday.long` + plural) |
| `describe.weekdayAcc.0`–`.6` (acc. sg.) | niedzielę, poniedziałek, wtorek, środę, czwartek, piątek, sobotę | (EN reuses `weekday.long`) |
| `describe.lastWeekdayClause.0`–`.6` | ostatnią niedzielę, ostatni poniedziałek, ostatni wtorek, ostatnią środę, ostatni czwartek, ostatni piątek, ostatnią sobotę | the last {weekday} |

> **Zero cron/RRULE jargon** anywhere in the copy — no "cron", "BYDAY", "modulo",
> "expression". **REV5:** with the tz field removed (`§4.5.8`), a user now types NO
> technical string at all — the raw IANA tz name is never surfaced for input, only ever
> shown (in plain language) in the conditional "({tz})" clause for a foreign zone.

#### 4.5.13 Accessibility

- **Tabs** (`Tabs` primitive): `role=tablist`/`tab`/`tabpanel`, roving tabindex, ←/→
  move + Home/End, `aria-selected`, `aria-controls` — inherited; pass `ariaLabel`.
- **Option cards (`WorkflowScheduleOptionCards`, REV5):** the CARD HEADERS form one
  `role=radiogroup` (`aria-label` = the axis) — each header a `role=radio` +
  `aria-checked`, roving tabindex, arrow-key select (↑/↓ and ←/→) + Home/End; the
  selected card's INPUT BODY is a `role=group` region OUTSIDE the radio button (a radio
  must not wrap interactive controls), reached by **Tab from the selected header**. Only
  the selected card has a body, so the tab path is unambiguous. Disabled headers (the
  time cards under `last_working_day`) keep an explanation visible, never a bare gray-out
  (`reference-links.md` "disabled buttons").
- **Chip grids** (weekdays, month-days, months — REV5: exclusion weekday/month grids
  removed, `§4.5.7`): each chip a real `Button` with `aria-pressed`, grouped in a
  `role=group` with an `aria-label`; selection is border+tint+aria, never color alone.
- **Preview tiles:** each an element with an `aria-label` (`preview.tileAria`) so the
  full instant is announced; the "previous" tile adds `preview.previousTile` to its
  label; the rail is keyboard-scrollable and the lazy sentinel doesn't trap focus.
- **AI modal:** `Modal` focus-trap + Esc/scrim close + title/description ids
  (inherited); focus lands in the `Textarea` on open; `.run` `:loading` keeps a stable
  width; `Zastosuj` disabled-state is announced. Model text is inert plain text.
- **Every control labelled** via `FormField`/`aria-label`; helper/error text wired
  through `aria-describedby`; `focus-visible` rings come from the primitives. No
  state/tone by color alone — icon + text throughout.

#### 4.5.14 Responsive / mobile (REV5)

> **REV5 supersede marker.** Updated for the compact segment, the option cards, and
> the in-sentence window.

- **Header segment (`§4.5.2`):** the top row wraps on mobile — the sentence takes the
  full width, then the action cluster (the jump-to-date field + "Zaplanuj z AI", the latter
  icon-only) wraps to a right-aligned second line; the compact tile rail scrolls
  horizontally below. Still ONE framed segment.
- **Tabs** never wrap — they scroll horizontally with edge fades (primitive default).
- **Option cards:** stack **one per row** (full width) at every width so the in-sentence
  body reads as a sentence; on `next-sm+` the SHORT no-input cards may pack two-up, but a
  card with a body always spans full width.
- **In-sentence window (`§4.5.6`):** the "od {from} do {to}" fragment wraps within the
  card sentence line (`flex-wrap`) on the narrowest widths while staying one group.
- **Chip grids wrap** (`flex-wrap`) inside their card body; the month-days grid keeps
  small calendar-like tap targets and wraps naturally; weekday/month chips wrap.
- **Preview strip:** native horizontal scroll (touch-friendly); compact tiles are
  fixed-width `shrink-0`; the "jump to date" trigger is in the segment row (an icon +
  `Popover`), NOT a stacked field above the rail (REV4 behavior removed).
- **AI modal** is `size="lg"` capped at `85dvh` with an internally-scrolling body and a
  pinned footer (primitive) — the composer + proposal never push `Zastosuj` off-screen.

#### 4.5.15 Component & helper inventory (REV5 delta on the shipped REV4 files)

> **REV5 supersede marker.** The REV4 files below already EXIST and shipped. This is a
> DELTA table: the "Kind" column is what REV5 does to each. ONE new component
> (`WorkflowScheduleOptionCards.vue`); the rest are targeted MODIFYs. No file is deleted.

| File | REV5 kind | What REV5 changes |
| --- | --- | --- |
| `WorkflowScheduleOptionCards.vue` | **CREATE** | The radio-group of selection cards (`§4.5.5`): a `role=radiogroup` of card headers (`role=radio`, arrow-key select, roving tabindex) each with an OPTIONAL expanding body region OUTSIDE the button; `v-model` the sub-mode; a per-option `#body-<value>` scoped slot the panels fill; unselected cards show title only; disabled cards keep a visible explanation; Tab from the selected header enters that card's body. Owns the card chrome + radio a11y + the focus model, NOT axis logic. May host the `{slot}`-split renderer for in-sentence templates (or that lives in `workflowSchedule.ts`). |
| `WorkflowScheduleBuilder.vue` | MODIFY | Own the single `Surface bg="muted" border radius="lg"` **header segment** wrapping the summary row + preview rail (`§4.5.2`); own the shared `anchor` for the jump-to-date; **remove the tz `FormField`** and the **exclusions weekday/month chip groups** (`§4.5.7`/`§4.5.8`); the Exceptions `Accordion` body = dates only. Still exposes `isValid`/`validationErrors`. |
| `WorkflowScheduleSummary.vue` | MODIFY | Drop its own `Surface bg="muted"` band + the `h-9` bubble; render as the segment's TOP ROW (`§4.5.3`): inline icon + sentence + the compact "Skocz do daty" `DateTimePicker` field (REV5.1) + the "Zaplanuj z AI" button (icon-only on mobile). |
| `WorkflowSchedulePreviewStrip.vue` | MODIFY | COMPACT two-line tiles (`w-[7rem]`, weekday+date on line 1, time on line 2; previous tile = dashed + leading glyph, "poprzednie" in `aria-label` only); drop the visible `<h4>` heading → rail region `aria-label = preview.title`; the jump-to-date trigger moves to the segment row (accept the `anchor` as a prop/`v-model` from the host); render inside the segment frame (`§4.5.4`). Paging/edge-fades/states unchanged. |
| `WorkflowScheduleTimePanel.vue` | MODIFY | Render its 3 sub-modes via `WorkflowScheduleOptionCards` (not `SegmentedControl`); move the `at` list / `every_*` controls into per-option `#body` slots woven into slotted sentences (`§4.5.5a`); keep the `last_working_day` lock + auto-reset; window inline (`§4.5.6`). |
| `WorkflowScheduleDayPanel.vue` | MODIFY | Same via option-cards, 7 cards; ordinal + weekday `Select`s woven into "w {ordinal} {weekday} miesiąca"; `last_working_day` note moves into the SELECTED body (`§4.5.5b`). |
| `WorkflowScheduleMonthPanel.vue` | MODIFY | Same via option-cards, 3 cards; `every_n_months` head + inline month-range window (`§4.5.5c`). |
| `WorkflowScheduleWindowField.vue` | MODIFY | Render INLINE (`§4.5.6`, REV5.2): a bare `Switch` (aria-label = `window.toggle.*`, no visible text) + the ALWAYS-visible "od {from} do {to}" fragment on the SAME wrapping line as the head sentence; the switch flips the pair's `disabled` (exposed as a `{ disabled }` slot prop), it never hides the fragment — not a `pl-next-6` stacked block. |
| `WorkflowScheduleAssistModal.vue` | MODIFY (light) | UNCHANGED except its compact in-modal preview inherits the REV5 compact two-line tiles (`§4.5.9`). |
| `workflowSchedule.ts` | MODIFY | `emptyScheduleDraft.tz` SEEDS the resolved browser zone (fallback `''`) — the "nowe = strefa przeglądarki" behavior (`§4.5.8`); `describeSchedule` gains optional `activeTz` + the conditional tz clause (`§4.5.10`). KEEP the weekday/month exclusion validators + their clause rendering (the model/wire still supports them). `configToDraft`/`draftToConfig` UNCHANGED. May host the `{slot}`-split template helper. Update its Vitest spec for the seed + tz-clause. |
| `app/stores/workflows.ts` | UNCHANGED | `schedulePreview`/`scheduleAssist` unchanged; the config it forwards still MAY carry `exclusions.weekdays/months` (backend support retained; the FE just stops authoring them). |

> The two add-on fields and other §8 inventory are UNCHANGED. Still no new UI
> **primitive** — the schedule components compose `Tabs`, `TimePicker`, `DatePicker`,
> `DateTimePicker`, `NumberInput`, `Select`, `Checkbox`, `Accordion`, `Surface`,
> `Modal`, `Popover` (REV5, for jump-to-date), `Button`, `Alert`, `Skeleton`, `Badge`,
> `Icon`. `WorkflowScheduleOptionCards.vue` is a LOCAL workflows component, NOT a
> promoted primitive; `SegmentedControl` is no longer used by the schedule panels but is
> UNTOUCHED for its other consumers. This inventory supersedes the schedule rows of
> §8.3/§8.4; the non-schedule rows there stand.

### 4.6 Steps editor — `WorkflowStepListEditor.vue` + `WorkflowStepCard.vue` (REWRITTEN for 5.1; AS-BUILT updated for SB1/SB2/SF1/SF2)

> **This section now describes the SHIPPED, uncommitted-at-time-of-writing SF1/SF2
> behavior, not the original B7 plan.** Three things changed from the plan below:
> the add-step control is a CARD GRID, not a `DropdownMenu`; every card is
> COLLAPSIBLE with a one-line summary (new, not in any earlier revision); and the
> step's markdown/value-or-variable fields carry real runtime power (operations
> pipelines, if-blocks, AI text — §4.7, §4.9) instead of pure references. See
> ADR-0013 for the backend decisions this FE work wires up to.

Reuses the Approvals ordered-stage pattern: an `<ol>` of step cards, ▲▼ reorder,
X-before-chevron trailing order, min 1 step, per-index 422 mapping. Not
drag-and-drop, not a canvas.

- **Add step (AS-BUILT — supersedes the `DropdownMenu` plan):** one SELECTION CARD
  per step type, in a `next-sm:grid-cols-2` grid — consistent with the step-1
  trigger cards' look, not a dropdown menu. Each card shows the type icon + label +
  a one-line description (`workflows.step.<type>.description`); clicking it appends
  a correctly-shaped empty `StepDraft` with an auto-suggested unique key, OPENS only
  that new card, and collapses every other card (so a long stack never stays fully
  expanded). The whole grid disables (with a `Tooltip` explaining
  `workflows.step.maxSteps`) at the client `MAX_STEPS` ceiling (mirrors the
  backend's `max:50`).
- **Collapsible cards (NEW — not in any earlier revision).** The LIST editor (not
  each card) owns which cards are expanded (`expandedUids`, a `Set<uid>`). A
  freshly-created workflow's single step starts OPEN; opening the editor on an
  EXISTING (already-configured) workflow starts fully COLLAPSED — so editing a
  10-step workflow does not open 10 full field sets at once. A collapsed card is a
  single ROW: a chevron (rotates open) + the position badge + the type `Badge` + a
  truncated one-line SUMMARY + (when applicable) an error `Badge`; clicking
  anywhere on the row toggles it (`aria-expanded` / `aria-controls` wired to the
  body). The summary is the step's `title` (or report `name`) with its variable
  directives stripped down to their variable NAMES — resolved against the catalog
  / step outputs / trigger system variables, so a chip reads as `Priority` rather
  than raw `@[variable](…)` bytes — falling back to a generic per-type label
  (`workflows.step.summary.createTaskFallback` / `.createFormReportFallback`) when
  still blank. A card carrying ANY error (a `steps.<i>.*` 422 or a duplicate key)
  auto-EXPANDS (the editor watches the error map), so a failed save always lands
  the user on the field to fix even inside a long collapsed stack.
- **Per-step card header** (each an `<li>`, keyed by a stable local `uid`): the
  collapse toggle spans the row; the trailing controls stay in the SAME rule order
  as before — conditional remove `Button size="icon-xs" leading-icon="x"` (only
  when > 1 step) **before** the permanent `chevron-up`/`chevron-down` reorder
  (disabled at the ends) — visible whether the card is open or closed.
- **`key` field** (`TextInput` mono, required, `workflows.step.keyLabel`, helper
  `workflows.step.keyHint` "Used to reference this step's output as
  `steps.<key>.…`"). Client-validate uniqueness (`.keyDuplicate`) + non-empty
  (`.keyRequired`); **AS-BUILT (SF2):** every keystroke is additionally SANITIZED
  to `[A-Za-z0-9_]` (`sanitizeStepKey`) rather than merely validated after the
  fact, so an invalid character can never even land in the field. The step's `key`
  is what the FE substitutes into step-output variable refs (the catalog gives
  `steps.<TYPE>.<name>` templates; the FE swaps `<TYPE>` → the user's `<key>` — see
  §4.7).

#### 4.6.1 `create_task` card — field by field (AS-BUILT: typed variable feed + pipelines)

Config keys (allow-list from `StoreWorkflowRequest::allowedStepKeys`): `title`,
`description`, `priority`, `deadline`, `labels`, `assignee_type`, `assignee_id`,
`form_id`, `approval_pipeline_id`.

| Field | Control | Variable support (AS-BUILT) | Wire |
| --- | --- | --- | --- |
| **title** (required, one line) | **`MarkdownEditor`**, full toolbar (SF3.6 — see §4.6.3). | Variable chips fed the TRUE type + enum options (`toEditorVariablesTyped`) + the merged 68-op catalog (`resolveOperationCatalog`) — a chip's pipeline Modal offers the real operations for its type. **AS-BUILT (SF3.6): if-blocks + `@[ai-text]` are now available here too** (see the reversed decision in §4.6.3). | string with directives (a directive's `pipeline`, if non-empty, executes at run time — ADR-0013 §2). |
| **description** | **`MarkdownEditor`**, full toolbar. | SAME typed variable feed + operations catalog, PLUS `:if-blocks="{maxDepth:3}"` and `:ai-text="{personas, labelsEnabled:false}"` (§4.7, §ADR-0013 §§3–4) — a conditional branch and/or an AI-generated paragraph can be inserted from the toolbar. | markdown string (if-blocks / `@[ai-text]` resolve at run time; unvalidated at write time — no server-side markdown parser). |
| **priority** | **`ValueOrVariableField`** (§4.9, AS-BUILT SF3 redesign): a `Select` over `low\|medium\|high\|urgent` in Value mode, OR — in Variable mode — ANY referenceable variable (no type pre-filter, SF3.2), coerced via an operations pipeline opened from the picked variable's chip. | value-or-variable + pipeline, gated to a **CHOICE** terminal — the pipeline must END in `enum_to_choice` / `match_to_choice` mapping into `TaskPriority::ids()` (ADR-0014); a bare identity ref or a non-choice terminal (e.g. `enum_to_text`) is rejected. | `{kind:'literal', value} \| {kind:'variable', ref, pipeline?}` (bare enum string also accepted as literal). |
| **deadline** | **`DateOrVariableField`** (§4.9, AS-BUILT SF3 redesign): `DatePicker` in Value mode, OR — in Variable mode — ANY referenceable variable (no type pre-filter, SF3.2), coerced to a date via an operations pipeline (e.g. `enum_to_date`, or nothing extra when already date-typed). | date value-or-variable + pipeline, gated to a `date` terminal (no `targetOptions` — a date field is not a choice field). | `{kind:'literal', value} \| {kind:'variable', ref, pipeline?}`. |
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

#### 4.6.2 `create_form_report` card — field by field (AS-BUILT: same pipeline/if-block/AI wiring)

Config keys (allow-list): `form_id`, `name`, `guidelines`, `sources`,
`submissions_from`, `submissions_to`. **Mirrors the interactive form-report form.**

| Field | Control | Variable support (AS-BUILT) | Wire |
| --- | --- | --- | --- |
| **form_id** (required) | **`FormSelect`** (single) | literal id. | uuid. |
| **name** (required) | **`MarkdownEditor`**, full toolbar (SF3.6 — see §4.6.3). | Same as `create_task.title`: chips + pipeline, PLUS if-blocks + AI-text as of SF3.6. | string with directives. |
| **guidelines** (optional) | **`MarkdownEditor`**, full toolbar. | Same as `create_task.description`: chips + pipeline + if-blocks + AI-text. | markdown string \| omit. |
| **sources** | a **two-checkbox group** (`task` / `form`) | literal. | `sources[]` subset of **`['task','form']`** — **NOTE the report vocabulary is `task`/`form`, NOT `manual`/`task`** (verified in `StoreFormReportRequest`: `Rule::in(['task','form'])`). Labels `workflows.step.report.source.task` / `.form`. Empty ⇒ omit `sources`. |
| **submissions_from / submissions_to** | **`DateOrVariableField`** each (§4.9), **OPTIONAL** | date value-or-variable + pipeline (any referenceable variable, no type pre-filter — SF3.2), gated to a `date` terminal. | `{kind:…, pipeline?}` \| omit. |

- **Optional-with-implicit-defaults copy:** present `submissions_from` /
  `submissions_to` as optional and explain the server defaults via
  `FormField` descriptions: `workflows.step.report.fromHint` ("Defaults to the
  form's enable date.") / `.toHint` ("Defaults to today."). This mirrors
  `StoreFormReportRequest::prepareForValidation` (from = `enabled_at`, to = today).
  Do not pre-fill these controls with a computed date (the default is server-side);
  leaving them blank is the correct "use default" state.

Outputs: `steps.<key>.report_id`, `steps.<key>.report_name`.

#### 4.6.3 The "one-line editor" decision (title / report name) — SF3.6 REVERSES the if-block/AI-text exclusion

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

**AS-BUILT (SF1) — the original reasoning, no longer current:** operations pipelines were offered
here from the start (a chip's Modal works regardless of toolbar visibility), but if-blocks and
`@[ai-text]` were initially kept OFF `title`/`name` — both features insert through the TOOLBAR,
and B7's original `hideToolbar` styling hid it to keep these fields reading as one line; an
if-block is also a BLOCK container that would visually break inside a field styled that way.

**AS-BUILT (SF3.6) — REVERSED: `title`/`name` are now ordinary multi-line `MarkdownEditor`s WITH
the toolbar, carrying the FULL power `description`/`guidelines` already had.** The user asked for
the same conditional-branch / AI-written-value capability on `title`/`name` that
`description`/`guidelines` already had, and there is no structural reason to withhold it (the
backend's markdown-field contract never distinguished single-line from multi-line fields — see
`docs/backend/workflows-api.md`'s "Where each is enabled" note, which is itself corrected by this
same SF3.6 pass). `WorkflowStepCard.vue` renders `title`/`name` with `min-height="4rem"` and the
same `:if-blocks="IF_BLOCK_CONFIG"` / `:ai-text="aiTextConfig"` props `description`/`guidelines`
use — there is no longer a toolbar-less, single-line variant of these fields. The engine already
trims a resolved `title`/`name` to 255 chars at run time (the DB column width — a reviewer fix,
see `docs/backend/workflows-api.md`'s Steps section), so a longer composed value from an if-block
branch or an AI-generated sentence never breaks a run.

### 4.7 Variable wiring — the MarkdownEditor variable extension (REWRITTEN — replaces REV 1's reference popover; AS-BUILT updated for SB1/SF1/SF2)

> **The "pipeline operations are a non-goal this batch" line from the original B7
> plan is WRONG as of SF1 — corrected below.** Operations pipelines, if-blocks, and
> AI text are now wired and executing at run time (§4.6, ADR-0013). This section
> also corrects the ORIGINAL plan's claim that the trigger's system variables
> (`trigger.scheduled_at`) were already offered for a null catalog — they were
> PLANNED to be, but the code did not actually do it until SF2 (see 4.7.4 below).

**The `{{…}}` reference popover of REV 1 is DELETED.** 5.1 uses the existing
editor variable extension.

- **Catalog fetch.** When the trigger is `form_submitted` **and** a `form_id` is
  selected, fetch `GET /forms/{form}/workflow-catalog` →
  `{data:{variables:[…], fields:[…], operations:[…], ai_personas:[…]}}`. Cache per
  form id in the editor store. The `variables` array feeds **every**
  variable-capable control on this workflow (all step editors + the add-on
  fields); `operations` (SB1; 66→68 ops with the ADR-0014 choice-coercion batch) is
  the operation catalog and `ai_personas` (SB2) is the closed AI-text tone set (both
  label-less — the FE attaches labels). When the
  trigger is `schedule`, there is no form; the only variables are the trigger's
  `scheduled_at` + the step outputs (§4.7.3/§4.7.4).
- **Feeding the editor — AS-BUILT (SF1): pipelines ARE wired.** Each `MarkdownEditor`
  is fed `:variables="{ variables: toEditorVariablesTyped(...), operationsCatalog:
  resolveOperationCatalog(catalog) }"` — `operationsCatalog` is the merged
  backend-descriptor × FE-label catalog (`resolveOperationCatalog`,
  `workflowConditions.ts`; unknown/missing descriptors degrade to the full FE
  standard catalog), never empty. A variable chip's Modal therefore opens the FULL
  operations-pipeline editor, filtered to the picked variable's type — this
  REVERSES the original plan's "pipeline operations are a non-goal this batch."
- **Two variable feeds, not one (SF1).** `toEditorVariables(...)` (unchanged,
  degrade-to-primitive) still feeds anything that only needs identity + the
  editor's 3-primitive vocabulary (read-side/summary code). NEW:
  `toEditorVariablesTyped(...)` feeds the step markdown editors — the SAME
  identity-only definitions (`id = path`) but carrying the variable's TRUE
  `WorkflowVariableType` (not the degraded primitive) plus its enum `options`, so
  a chip's pipeline modal offers the RIGHT type-specific operations (date/enum/
  multi, not just text/number/boolean) and enum comparison args are populated from
  the real option list.
- **Identity-only directive PAYLOAD (the key nuance, UNCHANGED by SB1).** The
  editor's `variable` directive is still **identity-only in its base shape** —
  `data.id` (= the variable's `path`) and a degraded editor primitive
  `data.type ∈ {text,number,boolean}`; it carries **no** workflow-type field. The
  FE **recovers the real workflow type from the catalog by path** exactly as
  before. What changed is that the directive MAY ADDITIONALLY carry a non-empty
  `data.pipeline` (an array of `{stepId, operationId, args, outputType}` steps,
  the editor's `VariablePipelineEditor` format) — when present, the BACKEND
  transforms the resolved value through it at run time (ADR-0013 §2); the
  directive's identity/type-degrade contract is otherwise untouched.

#### 4.7.1 The catalog→editor adapter (`workflowVariables.ts`)

A pure module mapping the catalog to the shapes each consumer needs:

- `toEditorVariables(catalog, steps, position, triggerType?)` →
  `VariableDefinition[]` for a plain (untyped) `MarkdownEditor` feed: system +
  field variables as-is (id = path, type = `editorPrimitive`), plus
  **position-scoped step outputs** (§4.7.3). Enum/multi/date collapse to their
  editor primitive here (real type stays recoverable by id).
- **`toEditorVariablesTyped(catalog, steps, position, triggerType?)` (SF1, NEW)** —
  the step markdown editors' actual feed: the SAME identity-only definitions PLUS
  the TRUE `WorkflowVariableType` and enum `options` (not degraded) — see above.
- `resolveVariableType(path, catalog, steps, triggerType?)` / `resolveVariable(...)`
  → the true `WorkflowVariableType` / full `CatalogVariable` for a path (used by
  the add-on value-or-variable fields, and by read-side rendering). **SF2:** both
  now also fall back to the static trigger-system mirror (§4.7.4) when the path
  isn't in the catalog, so a null-catalog schedule workflow still resolves
  `trigger.scheduled_at`'s type/name correctly.
- `variablesOfType(catalog, steps, position, types, triggerType?)` → a type-filtered
  variable list, still exported but **no longer what an add-on field's picker
  actually receives** (superseded by `allValueVariables` below — SF3.2/"show-all",
  §4.9).
- **`allValueVariables(catalog, steps, position, triggerType?)` (SF3.2, NEW)** — the
  actual add-on picker feed as of the SF3 redesign: `variablesOfType(...)` called
  with EVERY value-or-variable-referenceable type, i.e. no per-field type filter at
  all. Every `ValueOrVariableField`/`DateOrVariableField` on the card receives this
  SAME full list regardless of its own accepted terminal — the user picks any
  variable and coerces it with an operations pipeline (§4.9.1). Both this and
  `variablesOfType` strip identifier variables (`isIdVariable` — a path ending in
  `.id`/`_id`) from the OFFERED list; a saved reference to one still resolves/renders
  correctly, only NEW picking is restricted.
- `variableIcon(type)` → the per-`WorkflowVariableType` icon for an add-on chip
  (§7.5) — covers date/enum/multi too, unlike the editor's own primitive-only map.
- **`stripVariableDirectives(text, catalog, steps, triggerType?)` (SF2, NEW)** —
  replaces every directive in a string with its variable NAME (catalog-resolved by
  the directive's `id`, falling back to the directive's own embedded name); powers
  the collapsed step card's one-line SUMMARY (§4.6) so a chip never leaks raw
  `@[variable](…)` bytes into a read-only echo.

#### 4.7.2 Position scoping

A step's variable-capable fields may reference **the trigger + EARLIER steps
only**. The adapter takes the current step's `positionIndex` and includes step
outputs only for steps `0..positionIndex-1`. A field outside any step (there are
none in 5.1 — conditions use the typed builder, not variables) would see trigger
vars only.

#### 4.7.3 Step-output KEY substitution (the static `STEP_OUTPUTS` mirror — NOT re-pointed at the catalog)

The catalog lists step outputs as **`steps.<TYPE>.<name>`** templates
(`steps.create_task.task_id`, `steps.create_task.title`,
`steps.create_form_report.report_id`, `steps.create_form_report.report_name`).
When offering an earlier step's outputs, the adapter **substitutes the user's
actual step `key`** for `<TYPE>` → the inserted/stored ref path is
`steps.<key>.<name>`. A keyless earlier step contributes no outputs yet (skip it
until it has a key).

**Spec-vs-built gap (checked as part of this doc pass, still open).** The
original plan described this as re-pointing at "the catalog's real output names,
dropping the old hand-written `STEP_OUTPUT_SUFFIXES`." **This did NOT happen.**
`workflowVariables.ts` still hand-maintains its own `STEP_OUTPUTS` constant (a
`{create_task: [...], create_form_report: [...]}` map mirroring the backend's
step-output descriptors) and `toEditorVariables`/`toEditorVariablesTyped`
EXPLICITLY FILTER OUT the catalog's own `source:'steps'` entries
(`nonStepVariables()`) rather than reading them — the live, key-substituted
per-position outputs come entirely from the local static mirror, never from
`catalog.variables`. The code's own comment marks this as a deliberate two-step
plan ("B7a keeps this as a static mirror… B7b re-points it at the catalog's real
step-output names") — **B7b was never done**; SF1/SF2 did not revisit it either.
This is a real drift risk (a backend step-output rename would silently desync
from this FE mirror with no compile-time or catalog-driven signal) — flagged here
as an OPEN gap, not fixed by this doc pass.

#### 4.7.4 Trigger SYSTEM variables for a null catalog (SF2 — NOW ACTUALLY WIRED)

A `schedule` trigger (or a `form_submitted` trigger with no form selected) has no
catalog to fetch, so the trigger's SYSTEM variables (`trigger.scheduled_at` for
`schedule`; `trigger.submission.id`/`.form.id`/`.form.name`/`.source`/
`.submitted_at`/`.task.id` for `form_submitted`) would otherwise be invisible to
every step editor even though the RUNTIME resolves them regardless. **This is
what the original spec claimed already happened ("the only variables are the
trigger's `scheduled_at` + the step outputs") — it did not; SF2 is what actually
built it.** `workflowVariables.ts` now carries `TRIGGER_SYSTEM_VARIABLES` (a
`Record<WorkflowTriggerType, CatalogVariable[]>` — a hand-maintained mirror of
`WorkflowVariableCatalogService::triggerSystemVariables()`, same drift caveat as
§4.7.3) and `triggerSystemVariables(triggerType)` reads it. Every variable-feed
function (`toEditorVariables[Typed]`, `variablesOfType`, `resolveVariableType`,
`resolveVariable`) now takes an OPTIONAL `triggerType` and MERGES these into the
non-step variables (deduped by path — a present catalog entry always wins), so a
schedule workflow's step editors correctly offer `trigger.scheduled_at` as a
date-typed variable with the same name the catalog would have produced had a form
existed. Every step editor/card passes `triggerType` down from the drawer.

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

### 4.9 Non-text variable add-ons — interaction spec (NEW; AS-BUILT toggle + pipeline for SF1; REDESIGNED for SF3.3-5)

> **This section describes the SF3.3-5 REDESIGN of the fields, which SUPERSEDES the SF1
> "SegmentedControl + inline pipeline editor" shape described in earlier snapshots of this
> document.** The wire contract (the `{kind, ref, pipeline?}` union) and the underlying
> "no separate `ConditionalSelectField`" verdict (§4.9.3) are UNCHANGED — only the field's own
> internal layout and the pipeline's editing surface moved from an inline `SegmentedControl` +
> below-the-chip editor to a compact in-field toggle + a Modal, and the picker DROPPED its
> type pre-filter (SF3.2/"show-all"). See ADR-0013/ADR-0014 for the backend decisions (the
> shared operations engine; the choice-producing terminal rule) this redesign wires up to.

Two field wrappers give non-text fields the **same "or a variable" power** the editor
chips give text. Wire shape everywhere (unchanged since SF1):
`{kind:'literal', value} | {kind:'variable', ref:{source, path, type}, pipeline?:
{op, args}[]}` — bare scalars are also accepted as literals (the backend's
`validateUnionOrLiteral`).

#### 4.9.1 `ValueOrVariableField` (priority; and enum-typed select values)

**AS-BUILT (SF3.3-5) — ONE input-look box, not a SegmentedControl + a separate editor
panel.** The field reads as a SINGLE bordered box (matching every other form control's
look): a COMPACT leading toggle — two small icon `Button size="icon-xs"`s,
`pencil` (Value) / `braces` (Variable), each `aria-pressed` — sits INSIDE the box's left
edge, divided by a hairline from the body; the body holds either the literal control
(Value mode) or the variable picker/chip (Variable mode), flattened so the whole thing
shares ONE border/focus ring rather than nesting a field-inside-a-field.

- **Value mode (default):** the field's native literal control fills the body (for
  `priority`, a `Select` over `low\|medium\|high\|urgent`).
- **Variable mode, no pick yet:** a `Select` picker fills the body. **AS-BUILT
  (SF3.2/"show-all") — the picker offers EVERY referenceable variable, not a
  type-filtered subset.** The SF1 plan's `variablesOfType(...)` type pre-filter is
  GONE: `allValueVariables(...)` feeds the SAME full, position-scoped +
  trigger-system-merged variable list to every value-or-variable field regardless of
  the field's own accepted type — the user picks ANY variable and COERCES it to the
  field's terminal with an operations pipeline (a text field becomes a choice via
  `enum_to_choice`/`match_to_choice`, a number becomes a date via a suitable op, etc.).
  Identifier variables (`*.id` / `*_id` — `trigger.submission.id`, `steps.<key>.task_id`,
  …) stay STRIPPED from every offered list (`isIdVariable`, unchanged since SF3.2) — a
  machine key is never useful to drop into a priority or a deadline.
- **Variable mode, picked:** the picker is replaced by a **CHIP token rendered AS the
  field's value** — inline inside the box (not below it), styled like the editor's own
  `VariableChip` (type icon + name), with a trailing ✕ (`Button size="icon-xs"`,
  X-before-permanent-control order) to remove it and return to Value mode. **Clicking
  the chip's body (not the ✕) opens the OPERATIONS MODAL** (below) rather than expanding
  an inline pipeline editor underneath the field — operations no longer live inline.
- **The operations Modal** (mirrors `WorkflowConditionModal`'s shape, §4.8): a read-only
  SOURCE header (the picked variable's icon + name + its TRUE type as a `Badge`), the
  shared `VariablePipelineEditor` (seeded from the variable's true type, its enum/multi
  option list as `sourceOptions`, and — for a CHOICE field — the destination's own option
  list as `targetOptions`), a live **"Returns: `<type>`"** status strip (success tone
  when the in-progress pipeline satisfies the field's terminal contract, warning tone +
  the expected type(s) otherwise — the SAME `pipelineSatisfies(...)` predicate the field-
  level gate below uses, so the Modal and the field can never disagree), and a
  Cancel/Save footer. **Save is DISABLED (gated) until the pipeline's terminal
  satisfies the field** — a client-side preview of the exact terminal-type/choice gate
  the backend 422s on (`docs/backend/workflows-api.md`'s write-validation + "Choice
  fields" sections). Cancel discards the Modal's edits (a local clone); only Save
  commits the pipeline onto the field's model.
- **Choice fields (priority) — the Modal's choice-targeting affordance.** When the host
  passes a non-empty `targetOptions` (priority → `TaskPriority::ids()`), the Modal's
  pipeline editor offers the two choice-producing ops — `enum_to_choice`
  ("Zamień na wybór", enum source) and `match_to_choice` ("Dopasuj do wyboru", text
  source) — each editing UI presenting the FIELD's specific options (`urgent`/`high`/
  `medium`/`low`) as the mapping targets/rule `then`/`fallback` values, not a generic
  free-text target. A pipeline that already returns the right TYPE but does not END on a
  choice op shows a distinct hint (`workflows.field.needsChoice`, "zmapuj ją na jedną z
  wartości tego pola") rather than the generic "expected …" mismatch text, since
  "expected Choice" would otherwise read confusingly next to a value that already IS an
  enum.
- **Field-level TYPE ERROR (outside the Modal) — engages even while the step card is
  COLLAPSED.** The gate is derived from the SAVED `{kind, ref, pipeline?}` model, not
  from the Modal's live edits — so a workflow loaded for editing with an already-invalid
  saved pipeline (e.g. an old `enum_to_text`-terminated priority, now rejected by
  ADR-0014) is flagged immediately, before the user ever opens the field. When the saved
  pipeline does not satisfy the field: the box takes an `is-error` danger skin (a danger
  border + inset ring, mirroring the focus-within ring so the field itself reads
  "action required"), the chip gains a danger **"Wymaga uwagi"** pill (replacing the
  neutral "N ops" count marker) with `aria-invalid="true"` on the chip's clickable body,
  and one inline helper line renders under the box
  (`workflows.field.typeError`, "Ta zmienna nie pasuje jeszcze do pola — dodaj operacje,
  aby zwracała {expected}."). The step card/drawer's Save is BLOCKED while any card
  carries this error (bubbled via a `type-error` event up through
  `WorkflowStepListEditor` to the drawer — the SAME boolean gate a `steps.<i>.*` 422
  already used). **Because this reads the SAVED model on an ALWAYS-mounted card** (only
  the card's BODY sits behind `v-if="expanded"`, the card itself never unmounts), a
  multi-step workflow that hydrates fully COLLAPSED still reports its per-field errors
  immediately (`immediate: true` watchers) — and `WorkflowStepListEditor` auto-EXPANDS
  any card carrying one, exactly like an existing `steps.<i>.*` 422 already does (§4.6).
- **Suppressing a duplicate message (`externalErrorPresent`):** when the host's
  `FormField` ALREADY renders a server 422 for this exact field, the field passes
  `:external-error-present="true"` and the inline client-side type-error TEXT is
  suppressed — the danger skin / pill / `aria-invalid` still render (so the visual
  "action required" cue never disappears), only the redundant second line of prose is
  dropped, so exactly ONE error message shows per field.
- **State:** `{ kind: 'literal', value }` by default; picking a variable sets
  `{ kind: 'variable', ref: { source, path, type } }` (source/type from the catalog
  variable; `type` is the TRUE workflow type, not the editor primitive); Saving the
  Modal adds `pipeline: [{op, args}, …]` to the same object (omitted — a plain identity
  ref — when the Modal's pipeline is empty AND the field is not a choice field; a
  choice field's Modal Save is gated so an empty pipeline can never be committed).
  Switching the toggle back to "Wartość" restores a literal value AND drops any
  pipeline/ref entirely.
- **Props (component contract):** `variables` (the show-all feed), `operationsCatalog`
  (empty ⇒ no Modal offered — a removable-pill-only fallback for any future host that
  does not wire operations), `resultTypes` (the accepted terminal type(s) — `['enum']`
  for `priority`, `['date']` for a date field), **`targetOptions`** (NEW — the
  destination option set for a choice field; empty/omitted ⇒ not a choice field),
  **`externalErrorPresent`** (NEW — suppresses the inline type-error TEXT only, per the
  bullet above), `pickerLabel`/`pickerPlaceholder`, `disabled`.
- **a11y:** the mode toggle is `role=group` with two `aria-pressed` `Button`s (not a
  `SegmentedControl` radiogroup, since it needs no arrow-key roving-tabindex behavior
  for just two icon buttons); the chip's clickable body carries `aria-invalid` +
  an `aria-label` naming both the variable AND the "edit operations" action; the chip's
  ✕ is a `Button size="icon-xs"`; the Modal inherits standard focus-trap/Esc/scrim
  behavior (`ui/overlay/Modal.vue`) and the pipeline editor's own a11y (§4.8/editor
  README).

#### 4.9.2 `DateOrVariableField` (deadline; report windows)

Same AS-BUILT SF3.3-5 pattern as §4.9.1 (the compact in-field toggle, chip-opens-Modal,
field-level error affordance, `externalErrorPresent`), literal mode = a **`DatePicker`**;
variable mode offers the SAME show-all variable feed (§4.9.1's SF3.2 note — no `date`
type pre-filter), coerced to a `date` terminal via the Modal's pipeline. `targetOptions`
is never passed here (a date field is never a choice field) — the Modal's type-gate
strip is the plain "Returns: `<type>`" success/warning contract, no choice-specific hint.

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
  `steps.<i>.config.priority.ref.path` / `.pipeline.<m>.op` /
  `.pipeline.<m>.args.mapping.<key>` / `.pipeline.<m>.args.rules.<j>.then` /
  `.pipeline.<m>.args.fallback` etc.) → the matching step card by index
  (the Approvals `stages.<i>.<field>` regex approach), scrolled into view.
  `WorkflowStepCard.vue`'s `fieldError(field)` helper falls back from the exact
  `config.<field>` key to any `config.<field>.`-prefixed nested key, so a deep
  pipeline-arg 422 still surfaces on the right `FormField` (`:external-error-present`
  is then `true` for that field — §4.9.1).
- **A CLIENT-SIDE type-satisfaction gate mirrors the server's choice/terminal 422
  BEFORE submit** (§4.9.1): each value-or-variable field's SAVED pipeline is checked
  against its `resultTypes`/`targetOptions` contract via the same
  `pipelineSatisfies(...)` helper the field and its operations Modal both use; any
  card failing this — including a `priority` pipeline that does not end in a choice
  op — blocks Save and auto-expands, without waiting for a round-trip 422.
- Any un-mappable 422 → a translated danger toast (`workflows.editor.toasts.error`).

Success → `useToast` success (`.created` / `.updated`), emit `saved`, close; the
store reconciles the list (prepend on create, replace on update).

---

## 5. Runs view — the global list, the per-workflow section, and the shared detail drawer (REVISION 6, IMPLEMENTED)

*(REVISION 6 rewrites this section in place. Two entry points share the same run-row and
run-detail components: the TOP-LEVEL global list `WorkflowRunsListView.vue`
(`next.workflows.runs`, `§5.0`) and the detail-nested per-workflow section
`WorkflowRunsView.vue` (`?section=runs`, `§5.1`–`§5.2`). Both render `WorkflowRunRow.vue`
(`§5.3`) and open the same `WorkflowRunTimeline.vue` drawer (`§5.4`).)*

### 5.0 Global list — `WorkflowRunsListView.vue` (`next.workflows.runs`, top-level)

A TOP-LEVEL module list (a child of `WorkflowsModuleLayout`, `§1`), so — UNLIKE the detail-nested
per-workflow section (`§5.1`) — it carries the MANDATORY `FilterBar` + the `#top` Saved Views
`FilterTabBar` (context `workflow-runs`), mirroring `WorkflowsView.vue`/`FormSubmissionsView.vue`.

- **Filters (`FilterBar` default slot, no text search — runs have no search param):** STATE
  `Select multiple`, SOURCE `Select multiple`, TRIGGER `Select multiple` (all three:
  `workflows.runs.filters.*Label`/`.all*`), a searchable single **WORKFLOW** `Select`
  (`workflow_id`, global-feed-only, seeded from the workflows store, `workflows.runs.filters.
  workflowLabel`/`.anyWorkflow`), and the shared `DateRangeFilter` (`date_from`/`date_to`/
  `date_preset`, presets `today`/`this_week`/`last_week`/`this_month`). All five drive
  `GET /workflows/runs` through the `workflowRuns` store's `'global'` scope, kept separate from the
  per-workflow feed's cached items.
- **Active-filter chips**: state/origin/trigger render as multi-value chip GROUPS (one chip per
  selected value, `workflows.runs.filters.chip.*`, never "N selected"); the workflow filter and the
  date range render as single removable chips.
- **Rows**: `WorkflowRunRow` with `show-workflow` (the leading workflow-identity line, `§5.3`).
- **Detail**: clicking a row opens a LOCAL `WorkflowRunTimeline` drawer (`Drawer size="lg"
  :show-close="false"`), resolved via the clicked row's OWN `run.workflow.id` — no URL query key
  (`§1.2`).
- **Four states**: error+retry (`EmptyState variant="error"`); initial loading (6 row-shaped
  skeletons — state chip pair + two text lines, never a spinner); empty (`hasActiveFilters` ?
  filtered-search copy : first-run copy, `workflows.runs.global.empty.*`); success (rows +
  `useInfiniteScroll` + a retryable append `Alert`, `workflows.runs.global.error.*`).
- On unmount the store's global slice is reset (`store.resetAll()`) so a later visit never bleeds
  stale rows from a previous filter set.

### 5.1 Per-workflow section — Saved-Views exemption

The detail-nested Runs SECTION (`?section=runs`, inside a workflow's own detail) still does
**NOT** carry a FilterTabBar / Saved Views — the `§5.1` REV1 exemption is UNCHANGED (a
detail-nested, entity-scoped list, the Queue/inbox precedent). Saved Views live only on the NEW
global list (`§5.0`), which is a proper top-level list.

### 5.2 Filters — multi-select `Select`s + a date range (REVISION 6: no longer `SegmentedControl`)

Both the global list (`§5.0`) and the per-workflow section share the SAME filter vocabulary; the
per-workflow section renders it as two labelled `Select :multiple size="sm"` fields + a
`DateRangeFilter size="sm"` (no `FilterBar` wrapper, per the `§5.1` exemption) rather than the
`FilterBar` row the global list uses:

- **State** `Select multiple` — every `WorkflowRunState` value (`workflows.runs.state.*`); no
  explicit "All" option, an empty selection means unfiltered.
- **Source** (origin) `Select multiple` — `workflows.runs.origin.*`. **Per-workflow only:** when
  the open workflow's `trigger_type` is `form_submitted`, the "schedule" option is DROPPED from the
  list (a form-triggered workflow can never produce a schedule-origin run) —
  `WorkflowRunsSection.vue` threads the cached detail's `trigger_type` down for this.
- **Trigger** `Select multiple` — global list ONLY (a per-workflow section's trigger type is fixed
  by definition, so filtering by it there would be a no-op).
- **Date range** — the shared `DateRangeFilter` (`date_from`/`date_to`/`date_preset`).

Plus a manual Refresh `Button variant="outline" size="sm" leading-icon="rotate-ccw"`
(per-workflow section only — the global list relies on `useInfiniteScroll` + filter changes, no
separate refresh button). The per-workflow section's filters are URL-synced (`?state[]=`/
`?origin[]=`/`date_*`), preserving the `?run_detail=` overlay key across a filter change.

### 5.3 Run row anatomy — `WorkflowRunRow.vue`

Cursor list, 15/page, shared by BOTH entry points. Each row (a `Surface` card, the whole row a
button):

- **`showWorkflow` prop** (`boolean`, default `false`) — when `true` (the global list only), renders
  a leading identity line: the parent workflow's icon + name (`run.workflow.icon`/`.name`, from the
  resource's `workflow` block, `whenLoaded`). Degrades to nothing when the row carries no
  `workflow` (the per-workflow feed omits that block server-side, `§9`).
- **State badge** — the exhaustive 6-state map (`§5.5`), icon + label, NEVER color-only; `failed`
  renders `tone="solid"` for emphasis.
- **ONE source badge** (REVISION 6 — collapses the REV1/REV2 separate origin badge + trigger-type
  chip into a single badge; the trigger type was redundant information once origin already implies
  it in practice) — `originIcon(run.origin)` + `originLabel(run.origin, t)` (the RELABELLED origin,
  `§7.1`).
- **Nested-run badge** when `depth > 0` (`git-branch` + `workflows.runs.nestedBadge`).
- **Meta line**: steps count (`list-checks` + `workflows.runs.stepsCount`), started time
  (`workflows.runs.detail`-style relative/absolute formatting) + duration (`workflows.runs.
  duration` or "—" while unfinished).
- **Error preview** (failed runs only) — one truncated line, `text-next-danger`.

Row click emits `open(run)`; each host resolves that into its own drawer-opening mechanism
(URL query key for the per-workflow section, local ref for the global list, `§1.2`).

### 5.4 Run detail — `WorkflowRunTimeline.vue` (right-side `Drawer size="lg"`, no own chrome)

- **Header:** state badge + the ONE source badge (mirrors `§5.3`) + a nested-run badge when
  applicable + started/finished + duration. Own close button (`Button variant="outline"
  size="icon-sm"`) since the host `Drawer` renders with `:show-close="false"`.
- **Trigger context — THREE cases, most-specific first:**
  1. **Schedule run with a resolvable "reason"** (REVISION 6, NEW): a single labelled row — "Powód"/
     "Reason" — rendering `describeOccurrence(scheduledAtIso, run.schedule_descriptor, t)`, a
     CLIENT-COMPUTED sentence ("pierwszy czwartek o 14:00 w lipcu" / "the first Thursday at 14:00 in
     July") that names the matched occurrence semantically instead of showing a bare timestamp. The
     descriptor rides on the run's OWN `schedule_descriptor` field (backend-supplied data,
     `docs/backend/workflows-api.md`); the sentence GRAMMAR itself is entirely frontend
     (`workflowSchedule.ts`'s `describeOccurrence`, reusing the schedule builder's own
     `describeSchedule` clause-building — see ADR-0016). Falls back to a plain formatted timestamp
     (never blank) when the descriptor is missing or the occurrence cannot be named semantically
     (Tier-A fallback — compound/exclusion/unnamed descriptors).
  2. **`form_submitted` run** (REVISION 6, NEW): a "Wysłanie formularza"/"Form submission" heading
     over TWO cards:
     - **Form card** (`EntityCard`, whole card opens the form in a NEW TAB) — title = form name
       (or "Untitled form"), an `external-link` status icon, and — when the form is anonymous — an
       "Anonymous form" meta line (`eye-off`).
     - **Submission card** (`EntityCard`) — title "Submission", meta = submitted-at date + source
       (manual/task), a status badge (approved/pending derived from whether `submitted_at` is
       present). Clicking it opens `SubmissionPreviewDrawer` in **`diff`** mode (`§5.4a`), NOT a
       navigation — the run stays open behind it.
  3. **Anything else** — the REV1 fallback: a flat `trigger_payload` key→value `dl` (mono keys),
     not raw JSON.
- **Step timeline:** shared `Timeline` (`ui/patterns/Timeline.vue`), one item per
  audit step ordered by `position`: node icon toned by `status_tone`; title = step
  **type** label + the **`key`** mono chip; status badge (`status_label` /
  `workflows.runs.stepStatus.*`); payload as key→value rows; `error` in a `danger`
  `Alert size="sm"`.
- **States:** loading → skeleton timeline items; error → inline `Alert` + retry;
  empty → `EmptyState size="sm"` `workflows.runs.detail.emptySteps`.

#### 5.4a `SubmissionPreviewDrawer` — `diff` mode (REVISION 6, NEW; extracted from Forms)

`SubmissionPreviewDrawer` (`pages/forms/SubmissionPreviewDrawer.vue`) was EXTRACTED from
`FormSubmissionsView`'s inline detail drawer so both the Forms submissions screen AND this run
detail can reuse it, via a new `mode: 'view' | 'diff'` prop (default `'view'`):

- **`view`** (Forms submissions, unchanged behavior) — renders the submission read-only through
  `FormViewer`, with an edit affordance when `can_be_edited`.
- **`diff`** (this drawer) — the run captured a SNAPSHOT of the form's answers
  (`trigger_payload.fields`, prop `snapshotFields`). The drawer shows that snapshot AND fetches the
  CURRENT submission (`GET /api/form-submissions/{id}`, prop `submissionId`) to diff against it:
  - a header status: `Spinner` "Comparing…" while the fetch is in flight, then a
    `Badge variant="modified"` "Changed" (any field differs) or a `Badge variant="success"`
    "Unchanged" — computed via an order-insensitive canonical-value comparison (handles arrays/
    objects, not just scalars);
  - each field row that differs is highlighted `bg-next-modified-subtle`, carries a
    `Badge variant="modified" icon="pencil"` "Changed" marker, and shows the CURRENT value inline
    below the snapshot value (`workflows.runs` reuses `forms.submissions.preview.*` keys, not a
    Workflows-local i18n group — the drawer lives in `pages/forms/`);
  - a compare-failed `Alert variant="warning"` if the current-submission fetch errors (the
    snapshot still renders);
  - the footer offers "Open original submission" (`external-link`, opens the submission's Forms
    detail route in a new tab) instead of the `view` mode's submitter/date/status line.

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

Per-workflow section: error+retry (`Alert`); loading (row-shaped skeletons); empty (per-filter vs
first-run message, `workflows.runs.empty.*`); success (rows + infinite-scroll +
retryable append), via `useInfiniteScroll`. The global list's own four states are documented in
`§5.0` (`workflows.runs.global.*` copy, distinct from the per-workflow keys).

---

## 6. Run-now flow — `TargetPickerModal.vue` (`?run=<id>`) (REVISION 6: Pick / Create replace the raw id field)

A `Modal` hosted by the module layout, opened from the row menu + the detail
action bar. **5.1 delta:** only two trigger types, only two 422 bag keys.
**REVISION 6 delta:** the `form_submitted` target is acquired through Pick/Create instead of a
typed-in id (`§6.1`); the wire contract and the 422 bag are otherwise UNCHANGED (`§6.3`).

### 6.1 Per-trigger-type target picker

| Trigger type | Target | Control |
| --- | --- | --- |
| `form_submitted` | a **FormSubmission id**, resolved via Pick or Create | A read-only **selected-submission summary** (`EntityCard selected`, `CreatorBadge` glyph, submitted-at + source meta, a clear-selection ✕) when a submission is chosen, or an `EmptyState` ("No submission selected") otherwise — plus two actions: **Pick** (`workflows.run.pick`, opens `SubmissionPickerDrawer`, `§6.1a`) and **Create** (`workflows.run.create`, opens `FormFillView` in a drawer, `§6.1b`). Both resolve to the SAME hidden `target_id` the confirm button submits; Run stays disabled (`canConfirm`) until one is set. **REVISION 6 REPLACES** the REV1/REV2 raw-uuid `TextInput` (`workflows.run.submissionIdLabel`/`.submissionIdHint` — now unused, retained only for i18n-parity history) — the "no picker exists" gap flagged in the old `§9` is CLOSED. |
| `schedule` | **none** | confirm-only; the modal body shows only the confirm copy (`workflows.run.scheduleConfirm`). |

The task/approval target controls of REV 1 are **DELETED** (no task/approval
triggers exist).

#### 6.1a Pick — `SubmissionPickerDrawer.vue` (REVISION 6, NEW)

A right `Drawer size="xl"` listing a form's APPROVED submissions for selection — a lean list over
the shared `useFormsStore` (`submissionsFor`/`fetchSubmissions`), not the coupled
`FormSubmissionsView` page:

- **Scope**: prop `formId` — the trigger's bound form (`trigger_config.form_id`) or `null` ("any
  form"). When `null`, the drawer shows a `FormSelect` step FIRST (`workflows.run.picker.
  chooseForm`/`.chooseFormHint`); once a form is chosen it lists that form's submissions (a
  "change form" `arrow-left` link returns to the chooser).
- **Filters**: search + a source `Select multiple` (manual/task) + the shared `DateRangeFilter`, via
  a `FilterBar` INSIDE the drawer (this is a drawer-scoped list, not a top-level route, so it does
  not carry Saved Views). **No approved/pending filter** — the submissions endpoint returns only
  approved submissions already, so the whole list is implicitly approved.
- **Rows**: `SubmissionCard` (`pages/forms/SubmissionCard.vue`) in its `selectable` prop mode
  (`§6.1a` note below) — cursor-paginated via `useInfiniteScroll`, standard loading/empty/error/
  success states.
- **Pick**: clicking a card emits `select(id, submission)` and CLOSES the drawer, setting the
  modal's selected-submission summary (`setSelected`).

**`SubmissionCard.selectable` prop** (`boolean`, default `false`, additive/non-breaking): when
`true`, the WHOLE card becomes a pick affordance (`EntityCard`'s stretched-click action, a real
keyboard-activatable control) that emits `select(submission)` instead of navigating, and the
kebab/action menu (delete/restore/force-delete) is SUPPRESSED. `false` (the Forms submissions
screen's usage) is today's unchanged behavior.

#### 6.1b Create — `FormFillView` in a drawer (REVISION 6, NEW)

A right `Drawer size="xl"` (`workflows.run.createDrawer.title`) mounting the EXISTING
`FormFillView` (the same component the Forms module's own fill flow uses) so Create produces a
REAL `FormSubmission`, not a synthetic one. When the trigger's form is `null` ("any form"), a
`FormSelect` step precedes the form (`workflows.run.createDrawer.chooseForm`/`.chooseFormHint`).
On `@submitted`, the new submission becomes the modal's selection (`setSelected`) and the drawer
closes.

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
workflows.schedule.*         [REWRITTEN — v2 compositional descriptor, REVISION 4]
                             the REV3 family/tier/simple-mode key sketch that used to be
                             transcribed here is RETIRED along with the family model
                             itself (ADR-0012). The authoritative REV4 key inventory
                             (tabsAria, tab.*, time.*, day.*, month.*, field.*, unit.*,
                             window.*, weekday.*/month.* names, preview.*, assist.*,
                             validation.*, describe.* — PL + EN side by side) lives in
                             §4.5.12; read it there, not here.
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
workflows.runs.*             refresh, state.<6>, origin.<3> [origin.event RELABELLED
                             "Wysłanie formularza"/"Form submission", REVISION 6 —
                             i18n only, the wire VALUE is still `event`],
                             stepStatus.*, duration, nestedBadge, listLabel,
                             openRun, stepsCount, empty.*, detail.* [REVISION 6 adds
                             .reason.* (heading/label/daily/weekday/monthDay/lastDay/
                             lastWorkingDay/lastWeekday/nthWeekday — the schedule
                             "reason" sentence templates, PL+EN) and .formTrigger.*
                             (heading/openForm/untitledForm/anonymousForm/
                             submissionTitle/sourceManual/sourceTask)], loadError,
                             filters.* [REVISION 6, both entry points: stateLabel/
                             originLabel/triggerLabel/workflowLabel/allStates/
                             allOrigins/allTriggers/anyWorkflow/dateLabel/dateAny/
                             workflowSearchPlaceholder/chip.*],
                             global.* [REVISION 6, NEW — the top-level list only:
                             title, subtitle, error.title/.description,
                             empty.title/.description/.searchTitle/.searchDescription]
workflows.run.*              [CHANGED] title, purpose, confirm, cancel, testRunNote,
                             testRunConfirm, scheduleConfirm, toasts.started,
                             errors.targetRequired/.targetNotFound/.capReached/.generic
                             [REVISION 6 NEW — Pick/Create replace the id field]
                             pick, create, noSubmission/.noSubmissionHint,
                             clearSelection, picker.title/.chooseForm/
                             .chooseFormHint/.changeForm, createDrawer.title/
                             .chooseForm/.chooseFormHint
                             [REVISION 6 RETIRED, kept for i18n-parity history only —
                             no longer rendered] submissionIdLabel/.Hint/.Placeholder
                             [REMOVED] taskIdLabel/.Hint, approvalRequirement,
                             errors.noConcludedApproval
workflows.module.allRuns     [REVISION 6, NEW] the "All runs" module-nav item label
forms.submissions.preview.*  [REVISION 6, NEW keys on the FORMS i18n namespace —
                             `SubmissionPreviewDrawer`'s diff mode lives in
                             `pages/forms/`, not `pages/workflows/`]
                             diffTitle, snapshotNote, comparing, compareError,
                             status.changed/.unchanged, changedMarker, nowLabel,
                             submitter, sourceManual/.sourceTask, emptyFields,
                             openOriginal
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

`pencil` / `braces` (the value-or-variable field's compact two-icon mode toggle — Value /
Variable, SF3.3-5, §4.9.1) · `sparkles` (the AI schedule assist). All real. Chip icons: INSIDE the editor,
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
- The `ValueOrVariableField` / `DateOrVariableField` toggle (SF3.3-5) is a `role=group`
  pair of `aria-pressed` `Button size="icon-xs"`s (Value/Variable); the resulting
  variable chip's clickable body carries `aria-invalid` when its saved pipeline does
  not satisfy the field, and its ✕ is a `Button size="icon-xs"`; the operations Modal
  it opens is focus-trapped/Esc-closable like every other Modal.
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

- ~~No variable pipeline operations~~ — **DONE (SB1/SF1), no longer a non-goal.**
  `operationsCatalog` is now the merged 68-op catalog (66 at SB1/SF1 time, +2 with the
  SF3/ADR-0014 choice-coercion ops) on every step markdown editor + value-or-variable
  add-on; a variable chip's pipeline executes at run time (§4.6, §4.7, §4.9,
  ADR-0013, ADR-0014). This bullet is kept, struck through, so a reader of an older
  snapshot of this doc understands the change rather than finding a silent
  contradiction.
- ~~No if-blocks~~ — **DONE (SB1/SF1) for `description`/`guidelines`; SF3.6 EXTENDS
  it to `title`/`name` too** — every text field now offers if-blocks + `@[ai-text]`
  (§4.6.3). Not a non-goal at all anymore, for any text field.
- ~~Value-or-variable pickers type-filtered to the field's own type~~ — **REPLACED
  by SF3.2/"show-all" (§4.9.1).** Every add-on field now offers EVERY referenceable
  variable (identifiers still stripped) and relies on the operations pipeline to
  coerce it — including, for `priority`, a mapping into the CHOICE option set
  (`enum_to_choice`/`match_to_choice`, ADR-0014). Kept struck through for the same
  reason as the two bullets above.
- **No `TaskSelect`** — no task target exists in 5.1 (task triggers gone); the
  create_task assignee uses `UserSelect`/`BotSelect`, not a task picker.
- **No caret-aware token insertion for plain inputs** — variable insertion is the
  editor's native `{`-trigger chip flow; the add-on fields use a picker, not
  caret insertion. (REV 1's manual `insertToken` at-cursor helper is retired with
  the reference popover.)
- **No bot-source distinction** — `source.task` covers in-task submissions
  including bots; the UI does not (and cannot) separate them.
- ~~No submission picker~~ — **DONE (REVISION 6, §6.1a/§6.1b).** The run-now target
  is now acquired via Pick (`SubmissionPickerDrawer`) or Create (`FormFillView` in a drawer); the
  raw-uuid `TextInput` is retired. Kept struck through for the same reason as the two bullets
  above it (a resolved gap, not a still-open one).
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
| `TextInput` / `Textarea` | `ui/forms/*` | name, key, tz, condition text |
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
| `pages/workflows/TargetPickerModal.vue` | 2 target cases (submission / none); task/approval cases removed. **REVISION 6:** the `form_submitted` case is Pick/Create (`§6.1a`/`§6.1b`), not a raw id `TextInput`. |
| `pages/workflows/runNowErrors.ts` | Drop `approval_process` / `noConcludedApproval`; map only `target_id` (required/notFound) + `workflow` (cap) + generic. |
| `pages/workflows/WorkflowEditorDrawer.vue` | Host the rebuilt sections; catalog fetch-per-form + cache; conditions clear-on-form-change; assist wiring; 422 map per §4.10. |
| the `__tests__/*` for the rebuilt files | Re-point at the new contracts (editor model, trigger fields, target picker, run-now errors). |

### 8.4 B7 CREATE (new for 5.1; schedule files rebuilt again in REVISION 3, then again in REVISION 4)

> **The REV3 schedule rows below are superseded by §4.5.15** (`WorkflowScheduleBuilder.vue`,
> `WorkflowScheduleAssist.vue`, and `workflowSchedule.ts`'s REV3 descriptions, plus the
> REV3 store-methods row's `fetchScheduleFamilies()`/`schedulePreview(config, count)`
> shape) — removed here; see §4.5.15 for the current REV4 component inventory and
> §4.5.9/§4.5.4 for the current AI-modal/preview contracts. The non-schedule rows below
> (the two add-on fields, `workflowVariables.ts`) were true AT THE TIME this table was
> written; **SB1/SF1/SF2/SF3 (§4.6, §4.7, §4.9, ADR-0013, ADR-0014) since updated
> both** — the table below reflects the AS-BUILT current shape, not the original
> snapshot.

| New file | Path | Responsibility |
| --- | --- | --- |
| `ValueOrVariableField.vue` | `pages/workflows/` | Compact `pencil`/`braces` in-field toggle (SF3.3-5, replacing SF1's `SegmentedControl`) over EITHER a literal control OR a show-all catalog-variable picker (SF3.2, no type pre-filter, `*_id`/`*.id` stripped) whose pick renders as an in-field chip; the chip opens an OPERATIONS MODAL (mirrors `WorkflowConditionModal`) hosting the shared `VariablePipelineEditor` + a live "Returns: …" gate, Save disabled until the pipeline satisfies `resultTypes`/`targetOptions`; emits `{kind:…, pipeline?}`. Props: `variables`, `operationsCatalog`, `resultTypes`, **`targetOptions`** (a choice field's option set, e.g. `TaskPriority::ids()` for priority — ADR-0014), **`externalErrorPresent`** (suppresses the inline type-error TEXT when the host already shows a server error), `pickerLabel`/`pickerPlaceholder`, `disabled`. Emits `update:typeError` so the host can gate Save even while the card is collapsed. Used for priority (choice-gated) and any other enum value-or-variable. |
| `DateOrVariableField.vue` | `pages/workflows/` | Same SF3.3-5 pattern (in-field toggle, chip-opens-Modal, `externalErrorPresent`), literal mode = `DatePicker`, variable mode = the SAME show-all feed (no `date` pre-filter) coerced via the Modal's pipeline; `targetOptions` never passed (a date field is never a choice field); emits `{kind:…, pipeline?}`; used for deadline + report windows. |
| `workflowVariables.ts` | `pages/workflows/` | Catalog→editor adapter: `toEditorVariables` (editor-primitive typed) + **`toEditorVariablesTyped`** (SF1, TRUE type + enum options, feeds the step markdown editors' pipeline modal), both KEY-substituted + position-scoped; `resolveVariableType`/`resolveVariable`, `variablesOfType` (per-type filter, still used internally) + **`allValueVariables`** (SF3.2/"show-all" — every add-on field's actual feed, no type filter), **`isIdVariable`** (SF3.2 — the `*.id`/`*_id` stripping rule applied to every OFFERED list), **`triggerSystemVariables`** (SF2, the null-catalog mirror — §4.7.4), **`stripVariableDirectives`** (SF2, the collapsed-card summary echo — §4.6), `variableIcon`. The step-output stems (`STEP_OUTPUTS`) remain a hand-written static mirror, NOT re-pointed at the live catalog — see §4.7.3's open-gap note. |
| store method `fetchWorkflowCatalog(formId)` on `app/stores/workflows.ts` | `app/stores/` | Cached per form; unrelated to the schedule rebuild. The schedule-specific store methods (`scheduleAssist`, `schedulePreview`) are documented in §4.5.15, not here. |

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

> **Carried UNCHANGED by B7 (5.1), later touched by REVISION 6 (see §8.5):**
> `WorkflowsModuleLayout.vue`, `WorkflowsView.vue`, `WorkflowRunsView.vue`,
> `WorkflowRunRow.vue`, `WorkflowRunTimeline.vue`, `runFormat.ts`, `workflowStatus.ts`, the
> `workflowRuns.ts` store, and the runs/read i18n groups were all verified contract-clean by B7
> (no removed trigger/step references; runs surfaces rendered both surviving step types
> unchanged) — this note is retained for that historical record, but every file it lists except
> `runFormat.ts`/`workflowStatus.ts` was substantively REWRITTEN by REVISION 6 (§8.5); it no
> longer describes their current state.

### 8.5 REVISION 6 file set — Runs UI (global feed, source relabel, schedule reason, form/submission cards, run-now Pick/Create)

| File | Path | Kind | What changed / was added |
| --- | --- | --- | --- |
| `WorkflowsModuleLayout.vue` | `pages/workflows/` | MODIFY | `moduleItems` gains the "All runs" nav entry (`§1.1`/`§1.3`); `isItemActive` gains the `allRuns` branch. |
| `WorkflowRunsListView.vue` | `pages/workflows/` | **NEW** | The TOP-LEVEL global runs list (`§5.0`) — `FilterBar` + `#top` Saved Views `FilterTabBar` (context `workflow-runs`), the 4 filters incl. the workflow `Select`, `WorkflowRunRow show-workflow`, a self-hosted run-detail `Drawer` (no URL query key), `useInfiniteScroll`, the module's standard save/edit/delete-view modals. |
| `WorkflowRunsView.vue` | `pages/workflows/` | MODIFY | State/origin filters become `Select multiple` + a `DateRangeFilter` replacing the old `SegmentedControl` pair (`§5.2`); the origin options drop `schedule` when `triggerType === 'form_submitted'` (a new prop). |
| `WorkflowRunsSection.vue` | `pages/workflows/` | **NEW** (thin route adapter) | Derives `workflowId` from `route.params` and threads the cached detail's `trigger_type` down to `WorkflowRunsView` for the schedule-origin-hide rule — kept as a separate file so `WorkflowRunsView` stays routing-agnostic. |
| `WorkflowRunRow.vue` | `pages/workflows/` | MODIFY | New `showWorkflow` prop (`§5.3`) rendering the leading workflow-identity line; the separate origin badge + trigger-type chip collapse into ONE source badge. |
| `WorkflowRunTimeline.vue` | `pages/workflows/` | MODIFY | New schedule-"reason" section (`§5.4`, `describeOccurrence` from `workflowSchedule.ts`) and form/submission-card section (mounts `SubmissionPreviewDrawer` in `diff` mode) ahead of the REV1 flat-payload fallback; header collapses to ONE source badge, mirroring the row. |
| `SubmissionPreviewDrawer.vue` | `pages/forms/` | MOVED + MODIFY | Extracted out of `FormSubmissionsView`'s inline drawer; gains `mode: 'view' \| 'diff'` (`§5.4a`). `view` is the original unchanged behavior; `diff` is new. |
| `SubmissionCard.vue` | `pages/forms/` | MODIFY | New `selectable` prop (`§6.1a`) — additive, default `false`, today's behavior unchanged. |
| `SubmissionPickerDrawer.vue` | `pages/workflows/` | **NEW** | The run-now Pick flow (`§6.1a`) — a lean submissions list scoped by the trigger's bound form (or a `FormSelect` chooser step when unbound). |
| `TargetPickerModal.vue` | `pages/workflows/` | MODIFY | `form_submitted` case rebuilt around the selected-submission summary + Pick/Create (`§6.1`); `{target_id}` wire contract and the 422 bag are unchanged. |
| `workflowMeta.ts` | `pages/workflows/` | MODIFY | `originLabel`/`originIcon` unchanged in signature; the underlying i18n VALUE for `origin.event` is relabelled (`§7.1`). |
| `workflowSchedule.ts` | `pages/workflows/` | MODIFY | New `describeOccurrence(scheduledAtIso, descriptor, t)` — names a matched occurrence semantically by reusing the builder's own clause-building, with a Tier-A timestamp fallback (`§5.4`). |
| `app/stores/workflowRuns.ts` | `app/stores/` | MODIFY | Gains a `'global'` fetch scope (`GET /workflows/runs`) alongside the existing per-workflow scope, keeping their cached item lists separate; `resetAll()` clears whichever scope is active. |
| `app/router/index.ts` | `app/router/` | MODIFY | Registers `next.workflows.runs` (`§1.2`), declared before the dynamic `:id` child. |

Backend counterpart: `docs/backend/workflows-api.md` (Runs endpoints section) +
`docs/decisions/ADR-0016-workflows-global-runs-and-schedule-reason.md`.

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
