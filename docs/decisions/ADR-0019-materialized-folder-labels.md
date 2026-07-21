# ADR-0019 — Folder label governance: enforced labels are MATERIALIZED onto files

**Date:** 2026-07-19 (created)
**Status:** Accepted
**Module:** `App\Modules\Disk` (+ the shared `App\Modules\Labels` pivot)
**Relates to:** ADR-0018 (a file's container is its `fileable`)

---

## Context

The disk (F2/F3) lets a folder **govern** labels for the files it contains, in two modes:

- **enforced** — the label applies to every file in the folder's subtree and cannot be removed there;
- **recommended** — the label is pre-applied (but removable) to new files created in the folder.

The open question was how enforced labels reach a file. Two shapes were considered:

1. **Derived at read time** — store only the folder→label declarations; compute a file's enforced
   labels on the fly (walk its ancestors) wherever labels are read.
2. **Materialized** — write real `labelables` rows on each file, marked `enforced`, and recompute
   them when governance or placement changes.

Derivation keeps a single source of truth and never drifts, but it makes the **label filter** the
hard case: `filterByLabels` is a `whereHas('labels', …)` over the `labelables` pivot, and the disk
list, the tasks board and every other label query rely on that pivot holding the truth. A derived
model would force every one of those reads to additionally union an ancestor-walk — invasive, and
easy to get subtly wrong (and inconsistent between surfaces).

## Decision

**Enforced labels are MATERIALIZED onto each file as real `labelables` rows, marked `enforced = true`.**

- A dedicated `folder_label` pivot (`{ folder_id, label_id, mode }`) holds a folder's governance —
  **not** the shared `labelables` pivot, so the file/task pivot never grows a folder-only column.
- `labelables` gains a boolean `enforced` (default `false`). Tasks — the other consumer of the
  shared pivot — never write it, so they are unaffected.
- `FolderLabelEnforcer` is the recompute engine. A file's **target** enforced set is the union of the
  enforced labels of its folder and **every ancestor** on the materialized path. `syncFile` performs
  a **full recompute** of that target (insert new, upgrade a colliding manual row, delete rows no
  longer enforced), preserving manual rows; `syncSubtree` runs it over every file under a folder.
- Recompute is wired into the mutations that can change a file's target: a folder's governance edit
  and a subtree **move** (`FolderService::update` / `move` → `syncSubtree`), and file **store /
  move / restore / copy** (`FileService` → `syncFile`).
- **Recommended** labels are seeded once, at file **store**, from the *immediate* folder only
  (manual, removable). A new **subfolder** copies its parent's recommended labels as its own
  (an adjustable starting point).
- Enforced labels also apply to **descendant folders**, but by **DERIVATION, not materialization**:
  a folder is never label-filtered (the argument above only bites for files), so its inherited-
  enforced set is computed at read time (`FolderService::inheritedEnforcedLabels`, the union of any
  ancestor's enforced governance) and returned as `locked` labels on the show/edit payload. This
  needs no schema, no propagation, and correctly covers subfolders that already existed when the
  label was enforced. Enforced is therefore NOT copied onto a new subfolder's own governance — only
  recommended is — so un-enforcing at an ancestor cleanly removes it everywhere and a descendant can
  never opt out.
- `FileService::syncLabels` operates on **manual rows only** — it never adds, removes or restyles an
  `enforced` row, so a metadata edit can neither strip an enforced label nor forge one.
- `FileResource.labels[].locked = enforced` drives the UI lock (a lock glyph instead of the remove
  control; the id stays in the model on save).

## Consequences

**Positive**

- The label **filter, resource and changelog are unchanged** — an enforced label is just a real
  label the user cannot detach. No read path special-cases governance.
- A **full recompute** (not incremental) makes the tricky cases correct by construction:
  un-enforcing at one level while an ancestor still enforces keeps the label; a move re-parents the
  whole set.

**Negative / trade-offs**

- **Write amplification.** Enforcing on (or moving) a folder near the root recomputes every file
  beneath it. Fine at R1 volumes; a per-folder memo is the obvious optimization if a workspace's
  file count grows (noted in `FolderLabelEnforcer`).
- **Upgrade is lossy.** A manual label that becomes enforced is upgraded in place; if it is later
  un-enforced it is removed, not demoted back to manual. Accepted — recovering the "was once manual"
  intent is not worth a second bit.
- Materialized rows can drift if a future writer bypasses the enforcer. Mitigated by routing all
  disk file/folder mutations through the two services, and pinned by `FolderLabelEnforcerTest`.

## Alternatives rejected

- **Derive at read time** — rejected: it pushes an ancestor-walk into every label read (filter
  included) and risks per-surface inconsistency.
- **Put `mode` on `labelables`** (reuse the shared pivot for folder governance) — rejected: it
  pollutes the file/task pivot with a folder-only concept and blurs "a file's labels" with "a
  folder's declarations".
