# ADR-0034 — R2 sub-stage 2 (2b–2d): Generation Sessions — async run, versioned refine-as-revision, the server image chain, and the lifecycle reaper

**Date:** 2026-07-29 (created)
**Status:** Accepted
**Module:** `App\Modules\Generator` (the new `GenerationSession` model + its services/jobs/console command),
`App\Modules\Disk` (the deliberate Generator → Disk edge — a read for an image base, a write for
save-to-disk)
**Relates to:** ADR-0032 (the Template/recipe model a session snapshots and executes), ADR-0033 (the AI
cost meter + shared ai-text generator this engine spends through), ADR-0030 (the shared `VariableResolver`
this engine resolves every part with — no second interpolation implementation), ADR-0018 (`fileable` — the
Disk container model `save-to-disk` writes into, via `FileService::storeDiskContent()`)

---

## Context

ADR-0032 shipped a Template as a pure DECLARATION — its content-recipe rework explicitly deferred
"executing" it to "a generation Session." That deferral covered four coupled problems, which is why this
sub-stage was cut into four PRs (2a–2d) rather than shipped as one:

1. **How does a recipe actually RUN**, given it can carry several parts, each potentially several inline
   `@[ai-text]` blocks and/or an image plan with several filter steps — synchronously (in the request) or
   asynchronously (off it)? What happens when one part fails but the recipe has three others?
2. **Where does an image plan's `base` resolve to real bytes**, given ADR-0032 deliberately kept Generator
   DISK-DECOUPLED at write time (a `disk_file` id is stored opaque, never looked up)? Something has to cross
   that boundary eventually, or `post_with_image` never produces an actual image.
3. **What does "editable/undoable session history" (explicitly named in ADR-0032's "Planned / deferred") actually
   mean** for a chat-shaped surface — is "undo" a database rollback, a new record, or something else? Is
   "regenerate" the same operation as "let me revise the current draft in a different direction"?
4. **What stops a session from living forever**, given it holds provider-billed content and (from 2c on)
   real image blobs on disk?

This ADR records the answers, covering all four PRs together since they compound into one coherent engine
rather than four independent features.

## Decisions

**D1 — a session is a SNAPSHOT-AUTHORITATIVE execution, never a live re-read of its template.**
`GenerationSession::recipe_snapshot` captures `{content_type, slots, content}` at creation
(`CreateGenerationSessionDTO::fromRequest()`); every subsequent render — the initial `generate`, a
`regenerate`, a `refine` — reads ONLY that snapshot plus the session's own `slot_values`, never
`Template::find($template_id)`. `template_id` is provenance-only, nullable, with NO foreign key. This means
a template can be freely edited or deleted after a session exists without corrupting or breaking that
session (pinned by `GenerationSessionGenerateTest::test_run_reads_the_snapshot_not_the_live_template`) — the
alternative (re-reading the live template on each run) would make "what does this session actually produce"
a moving target across a template's edit history, and would need a delete-while-referenced guard on
`Template` that ADR-0032 explicitly chose not to build.

**D2 — the run is ASYNC, claimed atomically, and the SAME claim/job/tenancy machinery backs BOTH a
whole-session generate and a per-part refine-loop op — no second engine.** A guarded
`UPDATE … WHERE status IN (draft,ready,failed)` (`GenerationSessionRunManager::claimAndDispatch()`) is the
ONLY way a session transitions into `generating`; the affected-row count is the race-free "did I win"
signal, so a concurrent double-click or a second tab never double-runs (and never double-BILLS) anything —
it gets a `409`. `GenerationRunMode` (`full | regenerate | refine`) parameterizes ONE job
(`RunGenerationSessionJob`) and one manager rather than forking a second job type when the 2d refine loop
was added — the claim, the tenancy re-activation, the `WithoutOverlapping` lock, and the meter-session
tagging are identical regardless of mode; only the UNIT OF WORK `run()` dispatches to differs (the whole
executor vs. one part via the refiner). This mirrors the Disk/Bot/Workflow run-claim pattern already
established elsewhere in the codebase rather than inventing a new one.

**D3 — every part is FAIL-SOFT independently; only an infra fault fails the whole run.** A broken directive,
an unresolvable image base, an exhausted per-run AI budget — each degrades to
`{status:'failed', error:<localized>}` for THAT part alone, caught inside
`GenerationSessionExecutor::executeTextPart()`/`executeImagePart()`/`executeScenePart()`; every other part
still executes, and the session still lands `ready`. Only a `Throwable` escaping the whole `execute()` call
(e.g. a database error) fails the session itself. This follows the same fail-soft doctrine the Template
PREVIEW already established (ADR-0032) — a multi-part recipe should never be all-or-nothing when only one
ingredient is broken — extended here to a REAL run with REAL billed spend, where the stakes of an
all-or-nothing failure are higher (a broken image plan should not waste the already-spent text tokens by
discarding the whole result).

**D4 — the image chain crosses into Disk deliberately, ONLY at execution, closing the boundary ADR-0032
opened.** `Services\ImageBaseResolver` reads a `disk_file`/`from_slot` base through the tenant-scoped
`Disk\Models\File` model (a foreign-workspace id is simply not found — never a cross-tenant read); the
`ai_edit` filter step calls `Disk\Services\ImageAiService::edit()`; `save-to-disk` writes through
`Disk\Services\FileService::storeDiskContent()`. This is `App\Modules\Generator`'s FIRST dependency on
`App\Modules\Disk` (ADR-0032 explicitly kept it Disk-decoupled at write time) — `Workflows` remains
forbidden. `GeneratorModuleBoundaryTest::test_generator_image_services_may_depend_on_disk_but_never_workflows`
positively asserts the image services name `App\Modules\Disk` and still name no Workflows class, alongside
the pre-existing `Generator ↛ Workflows` scan. The pixel-op MATH itself is NOT borrowed from Disk's editor —
`Services\ImagePixelProcessor` re-expresses the identical `imageOps.ts` formulas as `Imagick` operations
(D5) — only the FILE READ/WRITE crosses the boundary, keeping the deterministic transform logic single-
sourced at the FRONTEND fidelity authority rather than forking it a second time server-side against Disk's
own (single-filter) editor implementation.

**D5 — server-side pixel fidelity is verified against the CLIENT's math, not against Imagick's own
built-ins.** `ImagePixelProcessor` deliberately does NOT use Imagick's default grayscale (Rec.709) or ad-hoc
brightness/contrast helpers; every op is hand-expressed as an exact `Imagick` colorMatrix/evaluate call
reproducing `resources/js/next/pages/disk/imageOps.ts`'s Rec.601-luma, `Math.round`-equivalent-rounding
formulas, then `clampImage()`d (the build is HDRI and does not auto-clamp). This is the property that lets a
headless/bot-driven session (future work — see "Planned" below) produce pixel-for-pixel what the interactive
Disk editor would have, rather than a visually-similar-but-numerically-different result.

**D6 — "editable/undoable history" (ADR-0032's deferral) is: REGENERATE is a fresh variation, REFINE is an
instructed REVISION of the current output, and UNDO is a synchronous version-stack pop — three distinct
operations, not one.** `GenerationSessionExecutor::renderPartFromSnapshot()` (regenerate) re-renders from the
UNCHANGED snapshot + slot values — literally re-rolling the dice on the same inputs (a text part gets a new
AI completion of the SAME prompt; an image part re-runs the SAME chain). `renderRefinedPart()` (refine)
instead takes the CURRENT output and a free-text `instruction` and asks the model to REVISE it — a text
revision prompt frames the current text + instruction explicitly as DATA
(`GenerationSessionExecutor::revisionPrompt()`); an image revision is a direct `ai_edit` of the CURRENT
bytes, no base/filter chain replayed. Both bump a per-part `version` and push the PRIOR result onto that
part's bounded `history` stack (`GenerationSessionRefiner::pushHistory()`, capped at
`generator.history_max_versions`, oldest dropped + its blob GC'd on overflow). UNDO
(`GenerationSessionRefiner::undo()`) is the odd one out: it runs SYNCHRONOUSLY, no AI call, no async claim —
pop the stack, make it current, delete the just-undone version's blob. It is one-directional (no "redo") and
its concurrency safety is a `lockForUpdate()` transaction re-checking `status===ready` + a non-empty stack
UNDER the lock, so a racing double-undo or an undo racing a completing refine cannot double-apply.

**D7 — a failed part-op is a content NO-OP, but the OUTCOME is still surfaced via
`last_op_status`/`last_op_error`.** `GenerationSessionRefiner::applyPartOp()` treats a `null`/non-`ok` op
result as "leave the current good result and history untouched" — a failed regenerate/refine must never
clobber a working result or push a bogus entry onto the undo stack. This was hardened DURING 2d after
observing that a silent no-op is indistinguishable, from the FE's point of view, from "nothing
happened" — the session settles back to `ready` at the SAME version with no signal a refine even failed. Two
transient columns (`last_op_status: 'ok'|'failed'|null`, `last_op_error`) are stamped in the SAME write that
flips the session back to `ready` and cleared at the NEXT claim, so the chat can toast a genuine failure
instead of silently accepting an unchanged result.

**D8 — the lifecycle reaper enforces THREE windows (stale → trash → purge), and archive is a BLANKET
FREEZE exempting a session from all three, not merely the trash step.** This mirrors the product plan's
explicit spec ("sesje żyją tydzień → kosz → miesiąc → trwałe usunięcie, archiwizacja wyłącza czyszczenie" —
`docs/product/plan-dzialania.md`). `Models\GenerationSession`'s three scopes (`scopeStaleGenerating`,
`scopeTrashable`, `scopePurgable`) each `whereNull('archived_at')`, so `archived_at` being set opts a session
OUT of stale-recovery too, not only trash/purge — an archived session mid-`generating` past the stale
window is never auto-failed either. The blob GC on purge derives the storage prefix from the ROW's OWN
`workspace_id` column (not the ambient tenant context, which the shared-DB sweep pass runs with CLEARED) —
mirroring the existing `DraftService::reapStale` pattern rather than inventing a new one.

**D9 — produced images are STORED VERSIONED from day one (2c), not retrofitted for undo in 2d.**
`GeneratedImageStore::storeVersion()` always allocates a version strictly above every version currently on
disk for that part (never overwrites); `deleteVersion()` removes exactly one. This meant the 2d refine loop
needed ZERO changes to the storage layer — regenerate/refine simply call `storeVersion()` again (a new
number), and undo's blob-discard is `deleteVersion()` on the just-undone number. Had 2c stored images
un-versioned (overwrite-in-place), 2d would have needed a storage migration to retrofit history; versioning
from the start avoided that.

**D10 — session run monitoring is WEBSOCKET-PUSH, never polling.** (Amended after the initial poll-based
plan.) When a run reaches a terminal state (`ready`/`failed`) the manager broadcasts
`GenerationSessionUpdated` on the private per-workspace channel `generator.workspace.{workspaceId}`
(`.generation-session.updated`, payload `{id, status, last_op_status?}` — never the produced content),
authorized by central workspace membership in `routes/channels.php` — the SAME posture as the Disk AI edit
channel (`DiskAiEditUpdated`). The FE's `useSessionSettle` composable subscribes once per open chat, filters
by session id, and on the terminal push re-fetches `GET /generator/sessions/{id}` for the authoritative
`results` + `last_op_status`. There is NO poll loop: a single post-subscribe fetch covers the event-before-
listener race; a generous safety timeout (past the 300s job timeout) and a Reverb-absent single re-fetch are
the only fallbacks (each a one-shot check, never a loop). Reuses the Reverb/Echo transport the Disk AI edit
already introduced, so no new infrastructure. Trade-off: a run reaper that fails a stale session with the
tenant context CLEARED (the shared-DB sweep pass) cannot push to the right channel — that rare case relies on
the FE's safety-timeout "refresh" hint.

## Consequences

- **Positive.** The claim/job/mode design (D2) meant the 2d refine loop added ZERO new async infrastructure
  — `GenerationRunMode` and a `partKey`/`instruction` on the SAME job payload were the only additions to the
  engine 2b already shipped.
- **Positive.** Fail-soft-per-part (D3) plus snapshot-authoritative execution (D1) together mean a session's
  `results` map is always a faithful, reproducible record of "what this exact recipe + these exact inputs
  produced," independent of anything that happens to the source template or to unrelated parts afterward.
- **Positive.** Versioning images from 2c (D9) meant 2d's undo needed no storage-layer migration — only new
  bookkeeping (the `history` column + the refiner) on top of an already-correct primitive.
- **Positive.** The Disk boundary crossing (D4) is narrow and test-pinned: only the two image services name
  `Disk`, and only at read (base resolve) / write (save-to-disk) time — authoring (ADR-0032) is untouched.
- **Trade-off (accepted).** No redo (D6) — an undone version's blob is deleted, not archived a second time.
  Considered and rejected as unnecessary complexity for a v1 undo affordance; the bounded history stack
  itself already gives several steps of "go back," which was judged sufficient.
- **Positive.** Websocket-push monitoring (D10) settles the chat the instant a run finishes (no poll-interval
  latency), reusing the Reverb/Echo transport the Disk AI edit already introduced — no new infrastructure.
- **Trade-off (accepted).** The stale-reaper window (`session_stale_after`, 1800s) must be manually kept
  ABOVE the run job's own retry/lock budget (`tries=1`, `timeout=300s`, `WithoutOverlapping` up to 600s) —
  documented as an explicit config-note invariant rather than derived/asserted in code, so raising the job
  timeout without also raising the stale window is a real, if documented, foot-gun.
- **Rejected: re-reading the live template on every run** (D1's alternative) — would make a session's output
  a moving target and would force a delete-while-referenced guard onto `Template` that nothing else in the
  system needs yet.
- **Rejected: a single "edit history" concept covering regenerate/refine/undo as one operation** — collapsing
  "fresh variation" and "instructed revision" into one endpoint would force the caller to always supply (or
  always omit) an instruction, muddying the UI affordance between "reroll" and "steer" that the product
  chat surface deliberately keeps distinct (D6).
- **Rejected: an in-place-overwrite image store with a later versioning retrofit** — would have meant
  shipping 2c's image chain twice (once un-versioned, once migrated) instead of once, correctly, from the
  start (D9).

See `docs/backend/generator-sessions-api.md` for the full endpoint/wire contract this ADR's engine backs,
`docs/decisions/ADR-0033-ai-cost-meter.md` for the cost-metering seam this engine's every AI call routes
through, and `docs/decisions/ADR-0032-generator-content-recipe.md` for the Template/recipe shape a session
snapshots and executes.
