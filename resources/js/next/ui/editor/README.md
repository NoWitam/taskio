# `next` Markdown Editor (Tier 7)

The flagship rich-text control for the isolated `next` frontend. It edits a
**markdown string** through Tiptap while presenting the same visual/field
language as the rest of the `next` form controls.

> **PART 1** ships the CORE: base markdown editing, the FieldShell mirror, the
> toolbar, link + table UX, states, the (de)serializer, the viewer, and the
> extension architecture. **PART 2 (implemented)** adds the app-specific nodes —
> **mentions, variables, if-blocks, AI chips** — each enabled via a typed prop,
> OFF by default, plugging in through the extension points below.

## Files

| File | Role |
| --- | --- |
| `MarkdownEditor.vue` | Main component. `v-model` is a markdown string; integrates with `FormField`; owns the FieldShell-mirror surface + states. |
| `EditorToolbar.vue` | Grouped command bar (history, block type, marks, lists, blockquote/code, link, table, hr, clear). Icon-only buttons with `aria-pressed`, tooltips + shortcuts. |
| `MarkdownViewer.vue` | Read-only renderer (`marked` + DOMPurify) for previews and display. |
| `markdown.ts` | Pure, unit-testable `markdownToDoc()` / `docToMarkdown()`. The single source of truth for the wire format. |
| `extensions/index.ts` | `createCoreExtensions()` + `mergeExtensions()` + `buildPart2Extensions()` — the Tiptap schema assembly + the PART 2 feature assembly. |
| `extensions/placeholder.ts` | Tiny local Placeholder extension (avoids a new npm dependency). |
| `extensions/types.ts` | PART 2 runtime payloads (mirror the legacy `types/editor.ts` shapes for portability). |
| `extensions/mention.ts` + `MentionChip.vue` + `MentionSuggest.vue` + `suggestionStore.ts` | `@`-mention node + caret-anchored suggestion popup (no tippy). The **store** (caret rect / query / key forwarding) is shared with the variable trigger; the popup is per-variant (see `VariableSuggest.vue`). |
| `extensions/variable.ts` + `VariableChip.vue` + `VariablePanel.vue` | Template-variable node. Inserted via a `{` **trigger**; the chip opens a **Modal** whose body is the SHARED `ui/variables/VariableReferenceEditor` (source header → nullable-gated TYPED "default when empty" → operations pipeline incl. arg-variables → "Returns: <type>" → change source). The panel keeps only the markdown-only display **name + lock**. |
| `extensions/VariableSuggest.vue` + `variableFeed.ts` | The `{`-insert popup: the SHARED `ui/variables/VariableBrowser` (an inline ARIA tree with type glyphs, `?`/`[]` markers and expandable containers) anchored to the caret. `variableFeed.ts` turns whichever feed the host gave the editor — the live `source()` list or the flat `VariableDefinition[]` — into that one tree. An object container is **expand-only**, so it can never be inserted. |
| `extensions/VariablePipelineEditor.vue` + `operationHelpers.ts` | The **shared** operations-pipeline editor (add-operation dropdown filtered by the current running type → steps → per-arg inputs → computed `resultType`) + its pure type-flow helpers. Used by BOTH the VariablePanel and the IF condition editor (DRY). |
| `extensions/PipelineArgLiteralInput.vue` | The literal control for EVERY pipeline-operation argument kind — value (text/number/boolean/date) AND option/map/rules (select/sourceOption/sourceOptions/sourceMap/choiceRules/choiceFallback) — extracted verbatim from `VariablePipelineEditor`'s previous inline controls (Workflows variable-typesystem Phase 4; widened to the option/map/rules controls in a later "Phase 4b" batch) — rendered both as the editor's own literal fallback and inside a host's `argVariable` slot's value mode, so the two always look/behave identically. |
| `extensions/VariableTypeIcon.vue` | Shared type-icon glyph + `nullable` ("?") / `array` ("[]") modifier markers (title + sr-only text), and an optional sr-only `typeLabel` prop. Used by `VariableChip` here and by the Workflows module's value-or-variable token, picker-tree rows, and operations-modal header, so a variable's type reads with the same glyph everywhere. |
| `extensions/aiText.ts` + `AiTextChip.vue` + `AiTextPanel.vue` | AI-text node + **Modal** (an Author `BotSelect` — replaces the retired persona Select, R2 ADR-0040 — + a nested `MarkdownEditor` for the prompt; the legacy persona tone, when present, shows read-only; the labels field is retired). |
| `extensions/ifBlock.ts` + `IfBlockView.vue` + `IfBranchView.vue` + `IfConditionPanel.vue` | Conditional `if-block` **container** node + `ifBranch` child nodes with **inline-editable bodies** (real editor regions via `contentDOM`), boolean-condition editing (Modal), and a depth cap. |
| `__tests__/markdown.spec.ts` | Round-trip + fidelity tests for the base serializer. |
| `__tests__/directives.spec.ts` | PART 2 round-trip + degradation tests (directive bytes + structure). |

## The FORMAT contract (base dialect)

The legacy `resources/js/components/editors/MarkdownEditor/FORMAT.md` only pins
the **custom directive layer** (mentions/variables/ai/if-blocks); it never fixes
the base markdown grammar. PART 1 commits the base grammar explicitly, chosen to
round-trip losslessly through Tiptap and to stay portable with the legacy
`marked`-based renderer:

| Feature | Canonical syntax |
| --- | --- |
| Headings | `#`, `##`, `###` (capped at H3) |
| Bold / Italic | `**b**` / `*i*` |
| Strikethrough | `~~s~~` (GFM) |
| Underline | `<u>text</u>` (no CommonMark syntax; HTML element, same as legacy) |
| Inline code | `` `code` `` |
| Link | `[text](href)` — `http(s)`/`mailto` only; rendered with `rel="noopener"` |
| Bullet / Ordered list | `- item` / `1. item` (`start` preserved when ≠ 1) |
| Task list | `- [ ]` / `- [x]` **parsed**, but **degraded** to a bullet item with the literal `[ ]` kept (no task node in core — legacy parity + no new dep) |
| Blockquote | `> quote` |
| Fenced code | ```` ```lang ```` (language preserved) |
| Horizontal rule | `---` |
| Table | GFM pipe tables with `:---` / `:---:` / `---:` alignment; first row = header |
| Hard break | trailing two spaces → `<br>` |

### Round-trip guarantees

`docToMarkdown(markdownToDoc(md))` is **idempotent after one normalization pass**:
it normalizes to the canonical forms above (e.g. `_x_` → `*x*`, collapses extra
blank lines) but never loses structure. It is **not** byte-for-byte for cosmetic
input variants. See `__tests__/markdown.spec.ts`.

### Paste behavior

- **Paste plain markdown** into the editor: Tiptap inserts it as literal text
  (ProseMirror has no markdown clipboard parser); the markdown **input rules**
  (`# `, `- `, `> `, etc.) only fire as you type, so a pasted `## Heading` stays
  literal until edited. Treat pasted markdown as text, not as parsed structure.
- **Paste rich text / HTML** (from a web page, Google Docs, etc.): ProseMirror's
  clipboard parser maps it onto the schema, so bold/italic/links/lists/tables
  survive and anything outside the schema is dropped. `linkOnPaste` autolinks a
  pasted URL over a selection.
- To ingest markdown programmatically, set `v-model` (it runs through
  `markdownToDoc`) rather than pasting.

## Component APIs

### `MarkdownEditor`

Props: `placeholder`, `disabled`, `readonly`, `hideToolbar`, `loading`,
`counter`, `maxlength`, `minHeight`, `maxHeight`, `ariaInvalid`, `success`,
`dirty`, `extensions?: AnyExtension[]`, `id`, `describedById`, `ariaLabel`, plus
the **PART 2 feature configs** (all OFF by default):

- `mentions?: { fetch: (q) => Promise<MentionItem[]>; trigger? }` — async source;
  triggered by typing `@`.
- `variables?: { variables: VariableDefinition[]; operationsCatalog:
  VariableOperationDefinition[]; trigger? }` — predefined list + the operations
  catalog. Inserted via a **trigger** (default `{`); the chip opens a Modal with
  the full pipeline editor.
- `ifBlocks?: boolean | { maxElseIf?; maxDepth? }` — conditional containers
  (`maxDepth` defaults to **3**). Inserted via the toolbar git-branch button
  (disabled at the depth cap).
- `aiText?: boolean | { personas?; labelsEnabled?; labelsCatalog? }` — inserted
  via the toolbar sparkles button. The panel's picker edits the block's **author** (a Bot, via
  `BotSelect` — contributes a VOICE, never knowledge or tools; R2, ADR-0040), not `personas`
  anymore: that prop is now only the LABEL SOURCE for a legacy tone a block was saved with
  (rendered read-only, clearable). `labelsEnabled`/`labelsCatalog` are retired — the labels UI
  is gone, though any stored `labels` data is carried through untouched on save.

There is **no variable toolbar button** — variables are trigger-only.
Model: `v-model` (markdown string). Exposes `{ editor }` via `defineExpose`.

### `MarkdownViewer`

Props: `source` (markdown string), `openLinksInNewTab`, `ariaLabel`.

## PART 2 — app-specific nodes (implemented)

Four app nodes layer on top of the core, each behind a **typed prop** on
`<MarkdownEditor>` (all OFF by default, so the core stays lean):

```vue
<MarkdownEditor
  v-model="md"
  :mentions="{ fetch: (q) => api.searchUsers(q) }"
  :variables="{ variables, operationsCatalog }"
  :if-blocks="{ maxDepth: 3 }"
  :ai-text="{ personas, labelsEnabled: true, labelsCatalog }"
/>
```

### Variable pipeline (shared editor)

A variable carries a `pipeline` of operations transforming the source value. The
shared `VariablePipelineEditor` (used in the VariablePanel **and** the IF
condition editor) computes the running type at each step from the catalog: the
add-operation menu only offers ops valid for the **current** type, and the
chip/result icon reflects the final `resultType` (`resolveType`). An **IF/ELSE-IF
condition is only valid when its pipeline resolves to `boolean`** — the condition
Modal shows a status icon and **blocks saving** an invalid condition.

**Per-reference "Default when empty" (Workflows variable-typesystem Phase 1b,
`docs/decisions/ADR-0022-workflows-variable-typesystem-phase1.md`).**
`VariablePanel.vue` now renders one extra optional text input once a variable is
picked, saved onto `VariableNodeAttrs.default` and serialized as the directive's
`data.default` — omitted from the payload when left blank, so an un-defaulted
variable stays byte-identical to before this addition. Consuming hosts (the
Workflows resolver) substitute it for a null/empty lookup before the pipeline
runs; this editor only carries the byte, it does not interpret it.
`VariableOperationArgumentDefinition` also gained an optional `hint` string,
rendered as persistent helper text under an arg control by
`VariablePipelineEditor` — first used by the `date_format` op (Workflows-only, a
host op) to show its safe-token legend under the pattern field.

**Argument variables — a RECURSIVE `argVariable` slot, offered for EVERY control (Workflows
variable-typesystem Phase 4, `docs/decisions/ADR-0025-workflows-variable-typesystem-phase4-arg-variables.md`
+ its Phase 4b addendum).** `VariablePipelineEditor` accepts a `depth` prop (default `0`) and, for
ANY argument control — value (`text`/`number`/`boolean`/`date`) OR option/structural
(`select`/`sourceOption`/`sourceOptions`/`sourceMap`/`choiceRules`/`choiceFallback`) — offers a
scoped `#argVariable` slot INSTEAD of its own literal control whenever the HOST provides that slot
AND `depth < MAX_ARG_VARIABLE_DEPTH` (3). The per-control gate (whether the ref must be a strict
single type, `enum|text`, `multi`, or — for a structural control — is unfiltered) is
`argVariablePolicy()` in `operationHelpers.ts` (renamed from the narrower `argVariableValueType()`,
which excluded every option/map/rules/select control — Phase 4a's original scope). This component
only decides WHETHER to offer the slot — it stays free of any dependency on a host's own
variable/field types, exactly like the rest of this shared editor. The slot receives `{ arg, value,
depth: depth + 1, setValue, disabled, sourceOptions, targetOptions }`; when it is not provided (a
condition builder, an if-block, a markdown pipeline all render `VariablePipelineEditor` without it —
argument variables are OFF there) or the depth cap is reached, the argument falls back to
`PipelineArgLiteralInput.vue` — the SAME literal controls for every kind, so behavior/serialization
for a literal argument is unchanged either way. The Workflows module's `ValueOrVariableField.vue` is
the one host that fills this slot today, recursively, with itself; its recursive picker offers the
FULL show-all variable pool for every arg (not a type-prefiltered one — the terminal gate plus the
mismatch skin enforce appropriateness instead), and a STRUCTURAL arg's recursive field gets no
operations catalog at all (no sub-pipeline — the ref supplies the whole map/rule-list). That same
host's picker — for a top-level field and for a recursive arg-variable alike — is the SHARED
`ui/variables/VariableBrowserPopover.vue` + `VariableBrowser.vue` (B2, reworked in B3), driven by
`buildVariableTree()` in `ui/variables/variableTree.ts`. It presents the offered variables as ONE
INLINE TREE: expanding a container reveals its children directly BENEATH it, indented, with a guide
rail per ancestor level and a rotating chevron on container rows; a search box switches to a flat
result list. It IS an ARIA `tree` — the body is one focusable element carrying `role="tree"` +
`aria-activedescendant` (virtual focus, so the search input drives the same cursor) and each row is
a `treeitem` with `aria-level`/`aria-expanded` (+ `aria-posinset`/`aria-setsize`, the DOM being
flattened); only the search RESULTS are a `listbox` of `option`s. Keyboard: ↑/↓ over the visible
rows, → expand or step in, ← collapse or step out, Home/End, Enter/Space pick-or-toggle, Esc, and
type-ahead. An object-shaped entry (a file composite, an object global, a form section, the
workflows "Globals" group) expands to its child fields; an object container is expand-ONLY (a whole
object resolves to a map, so it is never itself a reference) while a file composite is both
expandable and selectable; a repeater stays one non-expandable list entry. Note a form SECTION only
reaches the browser if the host's feed carries it — the workflows value-field feed carries it (and
the globals group) while the flat markdown `{` feed still flattens both upstream in
`expandVariables()`. The browser reads each variable's type
through this module's own `VariableTypeIcon.vue` (see the Files table above), so a variable's type
glyph — plus its `nullable`/`array` markers — looks identical in the browser, the chip, and the
picked token.

### Variable value types (extended vocabulary)

`VariablePrimitive` covers six value types. The original trio — `text`, `number`,
`boolean` — is what the legacy FORMAT ever serialized. Three EXTENDED types exist
for hosts whose sources are richer (e.g. workflow conditions over form fields):

| Type | Icon | Wire value | Typical source |
| --- | --- | --- | --- |
| `date` | calendar | ISO `YYYY-MM-DD` string | date form fields |
| `enum` | list | ONE of the source's option values | select fields |
| `multi` | list-checks | `string[]` of option values | multi-select fields |

**The STANDARD catalog** (`extensions/standardOperations.ts`) ships 66 canonical,
i18n-labelled operations across all six types — transforms within a type,
conversions between types (`*_to_text` / `*_to_number` / `enum_to_date`…), and
boolean-terminating comparisons for EVERY type (so any variable can become a
condition). Hosts should import `standardOperationsCatalog()` (inside a computed —
labels are locale-reactive) instead of hand-rolling op lists; the ids are the
stable wire vocabulary the backend condition engine mirrors. Runtime semantics
(fail-closed conversions, 1-based substring, 0=Sunday weekday, "now"-relative date
checks) are documented at the top of that module.

An enum/multi `VariableDefinition` carries its `options: {label, value}[]`.
Operation args gained matching control kinds: `date` (DatePicker) plus the
SOURCE-DRIVEN `sourceOption` (pick one of the source variable's options) and
`sourceOptions` (pick many → the arg value is a `string[]`) — their choices come
from the PICKED VARIABLE, not the operation definition, so comparison values for
selects always match the field's real options. Pass the picked definition's
`options` to `VariablePipelineEditor` via its `sourceOptions` prop (VariablePanel
and IfConditionPanel already do).

**FORMAT note:** the extended types change NO directive bytes by themselves —
hosts that keep serializing degraded primitives (the workflow markdown fields do)
stay byte-compatible; only hosts that opt in to richer definitions surface them.

### If-block branches (inline editing + depth cap)

The `ifBlock` is a CONTAINER whose content is `ifBranch+` nodes. Each branch body
is a **real editor region** (`contentDOM`), so branch text has all editor
features inline (marks, mentions, variables, even nested if-blocks) — not a nested
markdown textarea. Branch headers show the kind (IF / ELSE IF / ELSE), a condition
summary (variable + op count + boolean-valid icon), and controls (edit condition,
remove non-IF branches); the container footer adds ELSE-IF (≤ `maxElseIf`) / ELSE
(max one). **Nesting is capped at `maxDepth` (default 3)**: the toolbar insert
disables and a `filterTransaction` guard rejects pastes/drops that would exceed it
(depth = ancestor `ifBlock` count).

Under the hood the component calls `buildPart2Extensions({ mentions, variables,
ifBlocks, aiText })` and merges the result **after** the core via
`mergeExtensions`. You can still pass raw `:extensions` for anything bespoke.

### 1. The node registry (`markdown.ts`)

The two pure functions consult a registry **first**, then fall back to graceful
degradation (block → inline text, inline → nested text; unknown nodes never
throw). Three hooks:

```ts
registerMarkdownNode(type, { toMarkdown(node, ctx) → string | null });   // serialize
registerInlineDirective({ keyword, toNode(payload) → JSONNode | null }); // parse inline
registerIfBlockParser({ toNode(meta, bodyLines, parseBody) → JSONNode }); // parse fence
```

Each node module self-registers on import (idempotent). Parsing masks every
`@[keyword]("…")` directive to an inert alphanumeric placeholder **before**
`marked` lexes (so a directive that looks like a `[ref](link)` can't be
mis-tokenized), then restores the real node afterwards; the if-block fence is
spliced out with depth tracking so nested fences re-parse recursively.

### 2. Directive serialization (legacy `FORMAT.md` parity — byte-exact)

All payloads are wrapped `{"v":1,"data":{…}}` with `"`→`\"` escaping, matching
the legacy editor so content is **portable both ways**.

| Node | Markdown form |
| --- | --- |
| **Mention** | `@[mention]("{\"v\":1,\"data\":{\"id\":\"u_1\",\"name\":\"Alice\",\"avatar\":\"/a.png\"}}")` |
| **Variable** | `@[variable]("{\"v\":1,\"data\":{\"id\":\"total\",\"name\":\"Order total\",\"type\":\"number\",\"locked\":false,\"pipeline\":[…],\"resultType\":\"number\"}}")` |
| **AI text** | `@[ai-text]("{\"v\":1,\"data\":{\"id\":\"ai_1\",\"personaId\":null,\"prompt\":\"…md…\",\"labels\":[…]}}")` (no author) — with an author (R2, ADR-0040): `@[ai-text]("{\"v\":1,\"data\":{\"id\":\"ai_1\",\"personaId\":null,\"authorId\":\"bot_1\",\"authorName\":\"Support Bot\",\"prompt\":\"…md…\",\"labels\":[…]}}")` |
| **If-block** | fenced container (below) |

**`authorId` / `authorName` (R2, ADR-0040) — EMIT-OR-OMIT, no alias.** The two AUTHOR keys on an `ai-text`
node's `data` are written to the wire ONLY when the block actually has an author: with none, `encodeAiTextDirective()`
omits both keys entirely, so an author-less block (every block saved before this feature, and every block
whose author was never set) serializes to the EXACT SAME bytes as before — the `directives.spec.ts` round-trip
tests pin this. `authorName` additionally never travels without a real `authorId` alongside it (clearing the
author drops its display snapshot too). `authorId` has **no alias**: the backend's `VariableResolver` decodes
this key and only this key — unlike `personaId`, which the inline-directive parser also accepts under the
legacy bare key `persona`, `authorId` written under any other name would silently degrade the block to the
default neutral tone rather than resolve. `authorName` is DISPLAY-ONLY on both ends — a snapshot the editor
shows on the trigger before/without a live lookup, never an authority the backend trusts for anything.

```text
```if-block {"id":"if_1","v":1}
[[IF {"id":"b1","condition":{"variableId":"var_bool","pipeline":[],"resultType":"boolean"}}]]
…branch body markdown (may contain directives / nested if-blocks / lists)…
[[ELSE {"id":"b2"}]]
…branch body markdown…
```
```

Branch bodies are recursively (de)serialized through `markdown.ts`, so nested
directives, nested if-blocks, and lists round-trip.

### 3. NodeViews / panels

Vue NodeViews + their edit panels live alongside their extension. The variable,
AI and IF-condition panels are hosted in the next **`Modal`** (focus-trapped,
overlay-stacked); the mention/variable **suggestion** popup uses the shared
`useAnchoredPosition` + `Teleport` + a synthetic caret anchor — **never tippy**
(forbidden new dep). They never touch `MarkdownEditor.vue`.

> **Nested directives:** an `@[ai-text]` prompt may itself contain an
> `@[variable]` (or an if-block) directive. Serialization stays the legacy
> single-level `"`→`\"` byte-format; the parser's payload extractor is made robust
> to the resulting multi-level escaping by selecting the outermost `")` whose
> unescaped payload is valid JSON — bytes unchanged, only parsing is smarter.

### Suggestion popup architecture (no tippy)

The legacy editor anchored its `@`-mention popup with tippy
(`getReferenceClientRect` → caret coords). Here a bespoke ProseMirror plugin
watches the text before the caret for an `@query`, publishes the caret rect +
query + (async) items into a reactive `suggestionStore`, and a single
always-mounted `MentionSuggest.vue` reads the store and positions itself against
a **synthetic anchor** (`{ getBoundingClientRect: () => caretRect }`) via
`useAnchoredPosition` — the exact same flip/clamp the rest of `next` uses, with
zero new deps. Loading shows **option-shaped Skeleton rows** (skeleton rule),
empty shows a muted row; ↑/↓/Enter/Esc are forwarded from the plugin's
`handleKeyDown`; the listbox is `role="listbox"`/`option` + `aria-activedescendant`.

**SF3.1 — the popup FOLLOWS the caret on scroll, and CLOSES when the caret
scrolls out of view.** A `window` `scroll` (capture phase, so it fires for any
scrollable ancestor) / `resize` listener asks the ProseMirror plugin to
re-measure the caret while the popup is open; the plugin updates
`suggestionStore.rect`, which the popup re-reads through its STABLE synthetic
anchor (kept as one object so a rect change always reads fresh coordinates,
rather than re-creating the anchor per frame) and repositions against. When the
caret's editor line scrolls fully out of the viewport, the plugin's re-measure
finds no rect and the store closes the popup instead of leaving it pinned to a
stale, now-meaningless position.

**The `{` variant renders the shared VariableBrowser, with VIRTUAL focus.** The
mention popup is a flat listbox; the variable popup (`VariableSuggest.vue`) puts
the same caret anchoring around `ui/variables/VariableBrowser` — type glyphs with
their `?` / `[]` markers, containers you can expand inline, and a search mode fed
by the query typed after `{`. The ProseMirror contract is unchanged and is the
thing to protect when touching it: **DOM focus never leaves the editor**, so the
plugin FORWARDS keys into the browser through `suggestionStore.onKey` —
`↑ ↓ Enter Esc` always, and `← →` **only while the query is empty** (they are the
tree's expand/collapse keys there; once a query exists they belong to the caret).
Nothing else is forwarded, so a printable key can never be swallowed by the
tree's type-ahead, and the panel prevents `mousedown` so a click cannot blur the
editor. Because `buildVariableTree` makes a non-array object **never selectable**,
a form section / object global / the "Globals" group can only be OPENED here —
inserting one (which the old flat list allowed) would have written a directive
that resolves to a map inside the text.

## v-model loop prevention

- `onUpdate` serializes the doc and only assigns `model.value` when the markdown
  actually changed.
- `watch(model)` re-parses **only** when the incoming value differs from both the
  live serialization and the last value we emitted, and uses
  `setContent(…, false)` (no `emitUpdate`) guarded by a `syncingFromModel` flag.
- Net: type → emit → parent echoes the same string → no re-parse; external set →
  parse once → no spurious emit.
