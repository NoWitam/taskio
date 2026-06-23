# ADR-0006 — Approvals module design decisions

**Date:** 2026-06-23
**Status:** Accepted
**Module:** Approvals (next frontend, resources/js/next/pages/approvals/)

---

## Context

The Approvals module was migrated into the `next` frontend across three
implementation batches:
- Batch 1: Pipelines browse + builder drawer.
- Batch 2: Queue browse + review drawer + decision flow.
- Batch 3: Comments panel inside the review drawer.

During planning and implementation, several design questions arose. This record
documents the choices made and why.

---

## Decisions

### 1. Additive `is_owner` flag on the backend resource

**Decision:** Added `is_owner: bool` to `ApprovalPipelineListItem` and
`ApprovalPipeline` as a purely additive backend change. No other backend
modifications were made for the Approvals next migration.

**Rationale:** Edit and delete are gated on ownership AND capability (`can_be_edited`,
`can_be_deleted`). The existing API already returned capability flags but not an
ownership flag. Adding it additively is safe — the old frontend ignores unknown
fields. Disabled actions remain visible with a reason label
("you are not the owner" vs "has active processes") so the user understands
the constraint.

**Alternatives rejected:** Deriving ownership from a matching creator ID client-side
was rejected because the creator field is not present on the list resource (only on
the detail resource), requiring an extra request per card.

---

### 2. FormViewer imported directly from `pages/forms`

**Decision:** `ApprovalReviewDrawer.vue` imports `FormViewer.vue` directly from
`../../pages/forms/FormViewer.vue` without extracting it to a shared location.

**Rationale:** At the time of implementation, FormViewer had exactly one consumer
(the forms module itself, for preview pages). The approval review drawer adds a
second consumer, but the "extract only when a third consumer appears" rule did not
apply yet. The import is clean and within the next module boundary. If a genuine
third consumer appears, extraction to `ui/` or a shared `app/` location should be
planned through the planning agent.

**Trigger for revisit:** A second consumer of FormViewer outside of
`pages/forms/` and `pages/approvals/` is the signal to extract.

---

### 3. ▲▼ buttons for stage reorder (not drag-and-drop)

**Decision:** Stage reorder in the pipeline builder uses up/down arrow buttons
(`moveStage(index, -1 | 1)`), not a drag-and-drop library.

**Rationale:** The stage list is small (typically 2–5 stages). Arrow buttons are
accessible by default, require no extra dependency, and are unambiguous to
keyboard-only users. Drag-and-drop adds complexity (touch support, ARIA live
regions, dnd library overhead) for marginal benefit at this scale. If user research
shows friction with large stage lists, drag-and-drop can be planned separately.

---

### 4. FilterBar + Saved Views on the Pipelines list

**Decision:** `PipelinesView` follows the Taskio mandatory rule: every `next` list
screen's FilterBar must include the Saved Views FilterTabBar in the `#top` slot.
Pipelines uses Saved Views context key `approval_pipelines`.

**Rationale:** The global rule (`filterbar-saved-views-required`) applies to all
next list screens. Pipelines is a list screen.

**Note:** Queue has no FilterBar and therefore no Saved Views. The server already
filters the queue to the current user's pending items — there are no client-side
filterable dimensions.

---

### 5. Nav icon: `git-branch` for Pipelines, `check-circle` for the module

**Decision:** The Approvals module header icon is `check-circle`. The Pipelines
sub-nav entry uses `git-branch`. Queue sub-nav uses `inbox`.

**Rationale:** `check-circle` reflects the approval / sign-off concept. `git-branch`
was chosen for pipelines because a pipeline is a branching sequential workflow.
`inbox` reflects the "items waiting for me" nature of the queue. These are
deliberate semantic choices — not icon-picking.

---

### 6. No soft-delete/restore UI

**Decision:** The Approvals module has no soft-delete or restore UI for pipelines.

**Rationale:** The backend does not implement soft deletes for approval pipelines.
The 422 "pipeline has active processes" error prevents deletion of in-use pipelines,
which provides the safety constraint. There is nothing to restore.

---

### 7. `meta.total` first-page-only capture

**Decision:** The queue's `total` (count of the current user's pending items) is
captured from `meta.total` on the first page only and never overwritten on subsequent
cursor pages.

**Rationale:** The backend only includes `total` when no `cursor` parameter is
present (first page). Cursor pages do not re-send it. The store must protect against
overwriting a valid total with `undefined` when appending subsequent pages.

---

### 8. `already_decided` 422 resync

**Decision:** When `makeDecision()` receives a 422, the store interprets it as
"already decided" (the item was decided by another session or a race condition),
refetches the queue and count in parallel (`Promise.allSettled`), and throws a
structured `DecisionError` of kind `already_decided`. The UI surfaces a translated
warning toast and closes the drawer.

**Rationale:** A 422 from the decide endpoint has only one semantic meaning in this
context. Resync is best-effort (`allSettled` — individual failures are swallowed);
the structured error is thrown regardless so the UI always closes the stale drawer.

---

## Related files

- `resources/js/next/pages/approvals/` — module components and types
- `resources/js/next/app/stores/approvalPipelines.ts`
- `resources/js/next/app/stores/approvalQueue.ts`
- `resources/js/next/app/router/index.ts` — route definitions
- `resources/js/next/docs/pages/ApprovalsPage.vue` — in-app module docs
