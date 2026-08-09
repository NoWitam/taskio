# ADR-0043 — Knowledge module design: bases, entries, wikilinks, and the write-side contract

**Date:** 2026-08-01 (created)
**Status:** Accepted
**Module:** `App\Modules\Knowledge` (`Models\KnowledgeBase`, `Models\KnowledgeEntry`,
`Models\KnowledgeEntryRevision`, `Models\KnowledgeLink`, `Services\KnowledgeBaseService`,
`Services\KnowledgeEntryService`, `Services\KnowledgeLinkService`, `Services\KnowledgeMetadataValidator`,
`Support\WikilinkParser`, `Support\TemplateDirectiveGuard`, `Policies\KnowledgeBasePolicy`,
`Policies\KnowledgeEntryPolicy`)
**Relates to:** ADR-0044 (the index/retrieval half of this same module — chunking, embeddings, hybrid
search), ADR-0045 (how a bound consumer reads a base, and the erasure command), ADR-0027 (Variables module
extraction — the descriptor vocabulary this ADR reuses for the metadata schema), ADR-0040 (the unrelated
"Etykiety wiedzy" / `labels` field on an `ai-text` block — see the disambiguation note under Consequences)

---

## Context

The product needed a durable, curated place for facts a workspace teaches the platform once and every AI
consumer reads back — "what do we say about returns", "what is our brand voice", "who is on the team". Before
this module the only place anything like this lived was a bot's own `knowledge` JSON column (B6, see
`docs/backend/bots-api.md`): a flat `{title, content}[]` array, scoped to one bot, with no versioning, no
structure, no way for two bots (or a future Workflows step, or a future Generator template) to share the same
facts.

The brainstorm and planning session (see the project memory `knowledge-module-brainstorm.md`) considered and
rejected a lighter-weight alternative — extending the bot's own knowledge column with tags and letting other
modules read it directly — because it would have made every future consumer depend on the Bot module's schema,
which is exactly the dependency direction the rest of the codebase's module boundaries forbid (see
`docs/architecture/refactor-operating-model.md`). The owner explicitly asked for an MVP+ scope (chunking +
visual graph in the first cut, "wbrew rekomendacji" against the planning agent's own leaner recommendation)
rather than the minimal slice a strict MVP would have shipped.

## Decisions

**D1 — a new LOW-LAYER module, on the same tier as Variables.** `App\Modules\Knowledge` may depend on
`App\Modules\Variables` and `App\Support`, and must import **nothing** from the modules that will consume it
(Bot, Generator, Workflows). This is pinned literally by `KnowledgeModuleBoundaryTest`, which scans every PHP
file under the module root — comments and docblocks included — for a forbidden import. The one-way direction
is what lets several unrelated consumers share one knowledge base without any of them becoming a dependency of
it. The provider is registered directly after `VariablesModuleServiceProvider` in `bootstrap/providers.php` so
the boot order mirrors the dependency direction.

**D2 — a knowledge BASE is a hard, twice-typed container, not a folder.** A base is not merely a grouping label
— it carries a **charter** (free prose stating what belongs in it, for whom, in what tone) and a **metadata
schema** (typed fields every entry's metadata is validated against). An entry cannot exist outside a base.
Tag-based faceting (cross-base labels a user could attach freely) was considered and explicitly deferred: it
solves a filtering problem the module does not have yet, and it would compete with the base's own metadata
schema as "the" place a field lives, without a base to anchor validation against.

**D3 — the metadata schema rides the shared Variables DESCRIPTOR vocabulary, not the Forms format.** A base's
`metadata_schema` is a list of `{key, label, descriptor}`, where `descriptor` is exactly the descriptor shape
`ConstantTypeValidator` already validates for a constant's type. `KnowledgeMetadataValidator` (the module's own
class) owns almost nothing — it delegates the type judgement wholesale and only decides the SURFACE: the
authorable bases (`text`, `number`, `boolean`, `date`, `enum`, `object`, plus `nullable`/`array`), explicitly
excluding `file` (entry metadata is a plain literal, not a reference to a Disk row — admitting `file` would
make this module own file lifecycles, and would name a module it must not name). The Forms question-schema
format was rejected: it is built for validation-and-submission workflows (sections, conditions, a submit
action), not for "a typed literal per field," which is exactly the shape `TypedLiteralInput`/`ConstantEditorDrawer`
already render in the frontend. Reusing the descriptor vocabulary means a knowledge field can never describe a
shape the rest of the product cannot read back.

**D4 — editorial status is a closed 4-value enum, and `stale_at` is an ORTHOGONAL flag, not a 5th status.**
`KnowledgeEntryStatus` is `draft | proposed | approved | archived` — a single line from "being written" to "no
longer true," with no parallel branches to reconcile. B1 stores and filters the status but gates NOTHING on it
(no approval pipeline, no retrieval filter) — the vocabulary has to exist before the consumers (B6, ADR-0045)
can decide which states they read. `stale_at` (a review-due timestamp) is kept as a SEPARATE, orthogonal column
rather than a fifth status: an `approved` entry can be stale (it needs review but is still today's best answer)
and a `draft` can be perfectly current — collapsing the two into one status would force a choice between "still
authoritative" and "needs a look," which are independent facts.

**D5 — a slug is minted once, from the first title, and never follows a rename.** `[[wikilinks]]` address an
entry by its slug. If the slug tracked the title, fixing a typo in a heading would break every inbound link the
moment it was saved. A rename is therefore a deliberate, separate act (an explicit `slug` field on the update
request, normalized through the same `WikilinkParser::normalize()` a link target goes through) — never a side
effect of editing a title. Renaming degrades every inbound edge pointing at the OLD slug to a ghost (they still
say what they meant) and adopts anything already waiting for the NEW one.

**D6 — content is DATA, not template source: a fail-closed directive guard on every write.** A knowledge entry
is retrieved and injected into prompts that several present-and-future surfaces run through the shared template
engine (`{{…}}`, `@[…]`, `[[IF…]]` fenced conditionals). If an entry could carry that engine's markers, then
whoever can write an entry could write a directive a completely different feature later EXECUTES on someone
else's behalf — reading another tier's variables, or spending on an AI call, from inside what everyone treats
as a note. `TemplateDirectiveGuard` refuses (422) rather than escapes: escaping would require every present and
future consumer to un-escape identically, and the first one that forgot would reopen the hole silently. This is
a vector of **execution**, not merely of prompt hygiene — see ADR-0045 for the parallel, independent defence
against a model simply *obeying* an entry's imperative prose (`KnowledgeFence`). The two guards are deliberately
independent: one stops an entry from being executed by the template engine, the other stops it from being
obeyed by a language model. On the read side, rendering is **MarkdownViewer only** — there is no second render
path (e.g. a raw-HTML preview) that could interpret an entry's content differently than the guard assumed at
write time.

**D7 — authorization splits `update` (everyday edits) from `manage` (governance), with no concept of roles.**
Any workspace member may create a base and edit its name/description — a base is a SHARED asset, and gating
everyday edits on the creator would let it decay the moment its author went on holiday. But the **charter**
(what the base is FOR) and the **metadata schema** (which retro-actively decides whether every existing entry's
metadata is still valid) are governance: `manage` is required, and it resolves to "the base's creator, or the
active workspace's owner" — the ADR-0015 `ownsOrIsWorkspaceOwner` vocabulary, one step wider than the plain
ownership check, because a workspace owner must be able to govern a shared base a colleague created. There is no
new "role" concept introduced anywhere in this module; entries themselves are fully collaborative (any member
may create/edit/trash/restore any entry — see `KnowledgeEntryPolicy`), with only the irreversible **purge**
restricted to creator-or-workspace-owner.

**D8 — a base's soft-delete cascade to its entries is SERVICE-side, not a database `ON DELETE CASCADE`.**
Trashing a base stamps its live entries with the base's own `deleted_at` (not a fresh timestamp — the base's own,
read back post-commit so column precision matches on restore); restoring the base restores exactly the entries
whose `deleted_at >= ` that cascade instant, leaving alone anything a user had trashed individually beforehand.
A database cascade cannot express "restore what fell with me, not what was already gone" — `ON DELETE CASCADE`
does not fire for a soft delete at all, and a trigger-based equivalent has no way to distinguish the two cases.
The FK cascades exist as the safety net beneath the **permanent** purge, where deletion really is final.

**D9 — the embedding vector's width is hardcoded in the migration, not read from config, and pinned by a test.**
See ADR-0044 for the full indexing design; the schema decision belongs here because it is a `knowledge_entries`/
`knowledge_entry_chunks` table shape choice. `vector(1536)` is written as a literal DDL string (`ALTER TABLE …
ADD COLUMN embedding vector(1536)`), matching `config('knowledge.embedding.dimensions')`, but deliberately NOT
interpolated from it — a schema whose column type changes shape with an env var is a schema nobody can reason
about. `KnowledgeTenantSchemaTest` reads the config value back and asserts the live column type agrees, so a
drift between the two is caught in CI rather than at query time; changing the embedding model's width is a real
migration (drop/recreate the column, re-index everything), never a config edit.

## Alternatives considered

- **Extend the Bot module's `knowledge` column with tags, let other modules read it directly.** Rejected — see
  Context above; it inverts the module dependency direction the rest of the codebase enforces.
- **A Forms-style question schema for metadata.** Rejected — built for a different problem (validated
  submission workflows), and would have introduced a second, incompatible notion of "typed field" next to the
  one Variables already owns.
- **Tag-based cross-base faceting in the MVP.** Deferred — no filtering need exists yet to justify it, and it
  would compete with the base's own governed metadata schema.
- **A database-level cascade for base→entry soft delete.** Rejected for the reason in D8: it cannot express
  "restore only what fell with the parent."
- **Role-based permissions (editor/viewer/admin per base).** Not introduced. The two-tier `update`/`manage`
  split plus workspace-owner fallback covers every case the MVP needed without inventing a new authorization
  primitive the rest of the app does not have.

## Consequences

- Every future consumer of the knowledge base (Bot today; Generator, Workflows, a future "web research" or
  "propose an entry" surface tomorrow — see ADR-0045 and the R2/Knowledge chapter of
  `docs/product/plan-dzialania.md`) reaches it through primitive-only seams
  (`KnowledgeBindingService::attach(string $bindableType, string $bindableId, …)`), never through a model import.
  Adding a consumer never requires a change inside this module.
- A base's charter and metadata schema are the ONE place governance decisions live; a UI must never offer a
  "quick edit" of the charter through the same control as a name change, or it will produce 403s a user cannot
  explain (see `docs/backend/knowledge-api.md` → Authorization).
- **Disambiguation, not a code change:** the workspace already had an unrelated, hidden field with the Polish
  label "Etykiety wiedzy" ("knowledge labels") on an `ai-text` Generator block — decoded off the wire and
  discarded by the runtime (it never did anything; see ADR-0040 §"the labels field is hidden"). That field
  predates this module, names nothing this module owns, and stays exactly as ADR-0040 left it — its stored data
  is preserved byte-for-byte, its editor control stays hidden. A reader should not confuse it with the real
  Knowledge module documented here; nothing in this ADR reactivates it, and nothing in this module reads it.
- The metadata schema's reuse of the Variables descriptor vocabulary means any future widening of that
  vocabulary (a new base type, a new flag) is inherited by knowledge metadata automatically — and any narrowing
  is a breaking change to both at once. This coupling is deliberate (see D3) and should be weighed before either
  module changes its descriptor surface.
