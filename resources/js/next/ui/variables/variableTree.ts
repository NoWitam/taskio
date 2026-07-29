// variableTree — the ONE place a flat catalog variable list becomes the expandable,
// selectable TREE every variable surface renders. PURE: no Vue, no i18n, no DOM, no HTTP —
// every function here is a total function of its arguments, so it is exhaustively testable
// without mounting anything.
//
// ── THE SINGLE RULE ────────────────────────────────────────────────────────────
// A node is EXPANDABLE   ⟺ it HAS CHILDREN. Children come from `descriptor.fields`
//                          (object globals, form sections, file composites) OR from flat
//                          dotted-path nesting (a flat `a.b` entry nests under `a`).
// A node is SELECTABLE   ⟺ it is NOT a non-array OBJECT container. Concretely:
//   • object + !array  (form SECTION, object GLOBAL) → EXPANDABLE, NEVER SELECTABLE, on
//                        every surface, in every slot, with no exception. Clicking it only
//                        opens its children (a whole object resolves to a map at run time).
//   • object + array   (REPEATER)                    → NOT expandable (per-element access
//                        is deferred), SELECTABLE as a whole list value. The `array` flag
//                        is what a row turns into its "(list)" affordance.
//   • file             → EXPANDABLE (its {id,name,type,size,url} subfields) AND SELECTABLE
//                        (the whole-file ref is a long-standing valid reference).
//   • every scalar (text/number/boolean/date/enum/multi/time) → SELECTABLE.
// On top of that, `policy.acceptedPaths` (when given) further restricts selection: a node
// whose EMITTED path is not listed becomes expand-only. It can only ever take selection
// AWAY — it can never make an object container selectable.
//
// ── NO DEAD ROWS ───────────────────────────────────────────────────────────────
// A node that is NEITHER selectable NOR expandable can do nothing at all, so it is PRUNED
// (bottom-up, so a container that loses its last living child goes with it). That covers a
// childless object container AND a leaf a slot's `acceptedPaths` excluded — e.g. a repeater
// on the conditions surface, which accepts only its `fields.<id>` leaves.
//
// ── SYSTEM-IDENTIFIER STRIP (SF3.2) ────────────────────────────────────────────
// A SYSTEM identity path — `trigger.submission.id`, `trigger.form.id`, `trigger.task.id`,
// a step's `task_id` / `report_id` — is a machine key the ENGINE generates, not something a
// human drops into a title, so it is never OFFERED (it still RESOLVES — the read side is a
// different code path).
//
// The rule is scoped to those SYSTEM paths, and that scoping is what makes it correct BY
// CONSTRUCTION on every surface (B3): a path in a USER-AUTHORED namespace is NEVER stripped,
// however it is spelled. A user who names a form field `numer_id` / `order_id` — or (B4) a
// workspace GLOBAL `globals.order_id` — gets an ordinary variable: it must be insertable into
// a title, referenceable from a step field AND conditionable. The old blanket suffix test hid
// it everywhere, which is why the conditions surface needed a per-slot opt-out; with the
// namespace rule in place that escape hatch is gone (see `VariableSlotPolicy`).
// The second built-in exception is a FILE's subfields: `<file>.id` IS a valid, long-standing
// pick, so children of a `file` parent bypass the strip wherever the file lives.
//
// PRESENTATION IS THE CALLER'S JOB. This module emits RAW labels and structural flags; the
// localized "(list)" suffix, the "Globals ›" qualifier and the `workflows.variable.
// fileSubfield.<key>` sub-label lookups belong to the rendering layer, which has i18n.

import type { IconName } from '../primitives/icons';
import type {
  VariableBase,
  VariableDescriptor,
  VariableDescriptorField,
  VariableDescriptorOption,
  VariableNode,
  VariableNodeMatch,
  VariableSlotPolicy,
  VariableSource,
  VariableSourceVar,
  VariableType,
} from './types';

// --- Structural predicates --------------------------------------------------

/** A non-array OBJECT container (a form SECTION or an object GLOBAL) — expand-only. */
function isObjectContainer(base: VariableBase, array: boolean): boolean {
  return base === 'object' && !array;
}

/** A REPEATER (`array<object>`) — one selectable list value, never expanded. */
function isRepeater(base: VariableBase, array: boolean): boolean {
  return base === 'object' && array;
}

/**
 * The USER-AUTHORED namespaces — the paths a HUMAN named, where a machine-sounding key is just
 * a name someone chose:
 *   • FORM FIELDS   `trigger.fields.<id>` in a workflow catalog and — on the CONDITIONS surface,
 *                   whose contract drops the `trigger.` stem — `fields.<id>`. Both spellings, and
 *                   everything nested under them (a section's leaves, a repeater, a file
 *                   composite), are named by a user, never by the engine.
 *   • GLOBALS       `globals.<key>` (and its object fields, `globals.<key>.<field>`) — the
 *                   workspace's user-authored literal constants. B4: a workspace global someone
 *                   named `order_id` was still being hidden everywhere by the suffix test, which
 *                   is the same silent capability loss the form-field exemption already fixed.
 *
 * The `globals` ROOT itself is not in the namespace test below — it never ends with an id suffix,
 * so it can never trip the rule.
 */
function isUserAuthoredPath(path: string): boolean {
  return (
    path.startsWith('trigger.fields.') ||
    path.startsWith('fields.') ||
    path.startsWith('globals.') ||
    // TEMPLATE SLOTS (R2 Generator): `slots.<name>` is a user-named typed input, exactly like a
    // form field or a global — a slot someone named `order_id` / `id` must stay offered, never
    // hidden by the system-identifier suffix test.
    path.startsWith('slots.')
  );
}

/**
 * Whether a path is a SYSTEM IDENTITY path: it ends with `.id` / `_id` (the dot / underscore
 * boundary avoids false positives like `fields.valid` / `fields.paid`) AND it does NOT live in a
 * user-authored namespace (form fields, workspace globals).
 *
 * This is the ONE place the SF3.2 strip rule lives; `pages/workflows`' `isIdVariable` delegates
 * here so the tree, the markdown `{`-insert feed and the value-field feed can never disagree.
 * A SYSTEM id (`trigger.submission.id`, `trigger.form.id`, a step's `task_id` / `report_id`) stays
 * hidden from every offered list.
 */
export function isSystemIdentifierPath(path: string): boolean {
  if (typeof path !== 'string') return false;
  if (isUserAuthoredPath(path)) return false;
  return path.endsWith('.id') || path.endsWith('_id');
}

/**
 * The FE mirror of the backend degrade rule: a descriptor BASE → the flat wire type its
 * variable carries. `time` and `object` degrade to `text`; an `enum` base with `array:true`
 * is a `multi`. The default arm means a NEW/unknown base can never fall through (fail-soft).
 */
function baseToType(base: VariableBase, array: boolean): VariableType {
  switch (base) {
    case 'number':
      return 'number';
    case 'boolean':
      return 'boolean';
    case 'date':
      return 'date';
    case 'enum':
      return array ? 'multi' : 'enum';
    case 'file':
      return 'file';
    default:
      return 'text';
  }
}

/**
 * The inverse fallback: the structural base implied by a flat type, for a descriptor-LESS
 * variable (an older catalog entry, a locally synthesised step output). Keeps `node.base`
 * meaningful — and therefore the icon + the container rules correct — everywhere.
 */
function typeToBase(type: VariableType): VariableBase {
  return type === 'multi' ? 'enum' : (type as VariableBase);
}

/**
 * The node's choices: the structured `descriptor.options` (`{key,label}` — the REAL human
 * labels) when present, else the flat `enumOptions` promoted to `{key,label}` with the value
 * as its own label. Absent/empty → undefined, so a non-choice node carries no `options` key.
 */
function optionsOf(
  descriptor: VariableDescriptor | undefined,
  enumOptions: string[] | undefined,
): VariableDescriptorOption[] | undefined {
  if (descriptor?.options?.length) return descriptor.options;
  if (enumOptions?.length) return enumOptions.map((value) => ({ key: value, label: value }));
  return undefined;
}

// --- The internal, mutable draft -------------------------------------------
//
// The tree is assembled in two passes (flat nesting, then descriptor expansion) and then
// pruned, so nodes need mutable children while building. `Draft` is that scratch shape; it
// is frozen into the immutable `VariableNode` at the end. `selectable` is resolved on the
// draft (not only at freeze time) because the prune pass needs it.

interface Draft {
  source: VariableSource;
  path: string;
  label: string;
  type: VariableType;
  base: VariableBase;
  nullable: boolean;
  array: boolean;
  options?: VariableDescriptorOption[];
  /** The descriptor's own children, still to be expanded (undefined once consumed). */
  fields?: VariableDescriptorField[];
  children: Draft[];
  /** Resolved by `markSelectable` (THE rule + the slot policy) before pruning/freezing. */
  selectable: boolean;
}

/** A draft for a real catalog variable, at its EMITTED path. */
function draftFromVariable(variable: VariableSourceVar, path: string): Draft {
  const descriptor = variable.descriptor;
  const base = descriptor?.base ?? typeToBase(variable.type);
  const array = descriptor?.array ?? variable.type === 'multi';
  return {
    // The variable's OWN root source — never re-derived from the path (a slot may rewrite it).
    source: variable.source,
    path,
    // A nameless entry (malformed input) reads as its path rather than as a blank row.
    label: typeof variable.name === 'string' && variable.name !== '' ? variable.name : path,
    // Trust the variable's own flat type; fall back to the descriptor for a type-less entry.
    type: variable.type ?? baseToType(base, array),
    base,
    nullable: Boolean(descriptor?.nullable || variable.nullable),
    array,
    options: optionsOf(descriptor, variable.enumOptions),
    fields: descriptor?.fields,
    children: [],
    selectable: false, // resolved by `markSelectable` once the tree is assembled
  };
}

/** A draft synthesised from a container descriptor's `{key,label,descriptor}` child. */
function draftFromField(parent: Draft, field: VariableDescriptorField): Draft {
  const descriptor = field.descriptor ?? ({ base: 'text', nullable: false, array: false } as VariableDescriptor);
  const base = descriptor.base ?? 'text';
  const array = Boolean(descriptor.array);
  return {
    // A descriptor child lives under the SAME root source as the container it came from.
    source: parent.source,
    path: `${parent.path}.${field.key}`,
    label: typeof field.label === 'string' && field.label !== '' ? field.label : field.key,
    type: baseToType(base, array),
    base,
    nullable: Boolean(descriptor.nullable),
    array,
    options: optionsOf(descriptor, undefined),
    fields: descriptor.fields,
    children: [],
    selectable: false,
  };
}

// --- Pass 1: flat dotted-path nesting ---------------------------------------

/** The draft whose path is the LONGEST strict dotted prefix of `path`, or null (a root). */
function longestPrefixParent(path: string, byPath: Map<string, Draft>): Draft | null {
  let best: Draft | null = null;
  for (const [candidate, draft] of byPath) {
    if (candidate !== path && path.startsWith(`${candidate}.`)) {
      if (!best || candidate.length > best.path.length) best = draft;
    }
  }
  return best;
}

/**
 * The descriptor of the entry that is `path`'s longest strict dotted prefix in the INPUT —
 * used to spot a flat `<file>.<key>` subfield (which must survive the identifier strip) in a
 * list that was expanded before it reached us.
 */
function inputParentBase(path: string, byPath: Map<string, VariableSourceVar>): VariableBase | null {
  let best: { path: string; base: VariableBase } | null = null;
  for (const [candidate, variable] of byPath) {
    if (candidate !== path && path.startsWith(`${candidate}.`)) {
      if (!best || candidate.length > best.path.length) {
        best = { path: candidate, base: variable.descriptor?.base ?? typeToBase(variable.type) };
      }
    }
  }
  return best?.base ?? null;
}

// --- Pass 2: descriptor expansion -------------------------------------------

/**
 * Expand a draft's descriptor `fields` into child drafts, RECURSIVELY. Only a container
 * expands (a non-array object or a file); a repeater never does (per-element access is
 * deferred). A field whose composed path is ALREADY a child (the flat entry rode in the
 * input, e.g. a form section's leaves) is SKIPPED, so every leaf appears exactly ONCE and
 * the real catalog entry — with its authoritative name, type and options — wins.
 *
 * The SYSTEM-identifier strip applies to a descriptor child exactly as it does to a flat
 * variable — so a user-authored `fields.contact.customer_id` (and, since B4, an object global's
 * own `globals.thing.id` field) rides through, while a step's `steps.make.task_id` does not.
 */
function expandDescriptor(draft: Draft): void {
  const fields = draft.fields;
  draft.fields = undefined; // consumed — never expand the same descriptor twice
  const expandable = isObjectContainer(draft.base, draft.array) || draft.base === 'file';
  if (expandable && fields?.length) {
    const taken = new Set(draft.children.map((child) => child.path));
    for (const field of fields) {
      if (!field || typeof field.key !== 'string') continue; // fail-soft on a malformed field
      const path = `${draft.path}.${field.key}`;
      if (taken.has(path)) continue;
      // A FILE's subfields bypass the strip (`<file>.id` is a valid pick); every other
      // container's children obey it exactly like a flat variable would.
      if (draft.base !== 'file' && isSystemIdentifierPath(path)) continue;
      taken.add(path);
      draft.children.push(draftFromField(draft, field));
    }
  }
  for (const child of draft.children) expandDescriptor(child);
}

// --- Pass 3: resolve selectability, then prune DEAD nodes --------------------

/**
 * Resolve each draft's `selectable` — THE rule (never a non-array object container) narrowed
 * by the slot's `acceptedPaths`. Done BEFORE pruning because "is this row dead?" is exactly
 * "not selectable and not expandable".
 */
function markSelectable(drafts: Draft[], policy: VariableSlotPolicy | undefined): void {
  const accepted = policy?.acceptedPaths;
  for (const draft of drafts) {
    draft.selectable =
      !isObjectContainer(draft.base, draft.array) && (!accepted || accepted.has(draft.path));
    markSelectable(draft.children, policy);
  }
}

/**
 * Drop every node that ends up NEITHER selectable NOR expandable: it can do nothing — it
 * cannot be picked and it cannot be opened — so it could only render as a dead row. Two
 * cases reach this: an object container that ended up with no children (a section whose
 * leaves were all filtered out), and a LEAF that the slot's `acceptedPaths` excluded (a
 * repeater / a wrong-typed variable on a surface that only accepts some paths).
 *
 * Bottom-up, so pruning a container's last living child prunes the container too.
 */
function pruneDeadNodes(drafts: Draft[]): Draft[] {
  const kept: Draft[] = [];
  for (const draft of drafts) {
    draft.children = pruneDeadNodes(draft.children);
    if (!draft.selectable && draft.children.length === 0) continue;
    kept.push(draft);
  }
  return kept;
}

// --- Freeze: Draft → VariableNode -------------------------------------------

function toNode(draft: Draft): VariableNode {
  const node: VariableNode = {
    source: draft.source,
    path: draft.path,
    label: draft.label,
    type: draft.type,
    base: draft.base,
    nullable: draft.nullable,
    array: draft.array,
    selectable: draft.selectable,
  };
  if (draft.options) node.options = draft.options;
  if (draft.children.length) node.children = draft.children.map(toNode);
  return node;
}

// --- buildVariableTree ------------------------------------------------------

/**
 * Build the variable TREE for one slot from a flat, already-scoped `VariableSourceVar[]`
 * (whatever the caller decided to offer: the catalog's non-step variables plus the
 * position-scoped step outputs, the workspace globals, …). Descriptor-less lists pass
 * straight through as flat selectable leaves, so this is a no-op for a non-structural
 * catalog.
 *
 * Order is DETERMINISTIC and input-driven: roots keep the input order; a container's
 * children are its flat entries in input order, then its descriptor-only fields in
 * descriptor order.
 */
export function buildVariableTree(
  variables: VariableSourceVar[] | null | undefined,
  policy?: VariableSlotPolicy,
): VariableNode[] {
  const transform = policy?.pathTransform;
  const source = Array.isArray(variables) ? variables : [];

  // 0. Normalise: map each variable to its EMITTED path, dropping malformed entries and
  //    duplicates (first wins — two source paths can collapse onto one emitted path).
  const emitted: Array<{ variable: VariableSourceVar; path: string }> = [];
  const byInputPath = new Map<string, VariableSourceVar>();
  const seen = new Set<string>();
  for (const variable of source) {
    if (!variable || typeof variable.path !== 'string' || variable.path === '') continue;
    const path = transform ? transform(variable.path) : variable.path;
    if (typeof path !== 'string' || path === '' || seen.has(path)) continue;
    seen.add(path);
    byInputPath.set(path, variable);
    emitted.push({ variable, path });
  }

  // 1. SYSTEM-identifier strip (SF3.2) — except a flat `<file>.<key>` subfield, which is a
  //    valid pick and only ever reaches us in an input list that was expanded upstream. A
  //    user-authored form field (`trigger.fields.numer_id` / `fields.order_id`) is never a
  //    system identity path, so it rides through on EVERY surface with no per-slot opt-out.
  const kept = emitted.filter(
    ({ path }) => !isSystemIdentifierPath(path) || inputParentBase(path, byInputPath) === 'file',
  );

  // 2. Flat dotted-path nesting: attach each entry under its LONGEST strict dotted prefix.
  //    A REPEATER never receives children (per-element access is deferred) — an entry that
  //    would land inside one is re-rooted rather than hidden.
  const drafts = kept.map(({ variable, path }) => draftFromVariable(variable, path));
  const byPath = new Map<string, Draft>();
  for (const draft of drafts) byPath.set(draft.path, draft);
  const roots: Draft[] = [];
  for (const draft of drafts) {
    const parent = longestPrefixParent(draft.path, byPath);
    if (parent && !isRepeater(parent.base, parent.array)) parent.children.push(draft);
    else roots.push(draft);
  }

  // 3. Descriptor expansion (recursive, dedup-aware), 4. selectability, 5. prune dead rows.
  for (const draft of roots) expandDescriptor(draft);
  markSelectable(roots, policy);
  return pruneDeadNodes(roots).map(toNode);
}

// --- Traversal helpers ------------------------------------------------------

/** Every node in the tree, PRE-ORDER (containers included) — for lookups and iteration. */
export function flattenNodes(nodes: VariableNode[] | null | undefined): VariableNode[] {
  const out: VariableNode[] = [];
  const walk = (list: VariableNode[]): void => {
    for (const node of list) {
      out.push(node);
      if (node.children?.length) walk(node.children);
    }
  };
  walk(Array.isArray(nodes) ? nodes : []);
  return out;
}

/** The node at an EMITTED `path`, at any depth, or null when the path is unknown/stale. */
export function findNodeByPath(
  nodes: VariableNode[] | null | undefined,
  path: string | null | undefined,
): VariableNode | null {
  if (!path) return null;
  const walk = (list: VariableNode[]): VariableNode | null => {
    for (const node of list) {
      if (node.path === path) return node;
      const hit = node.children?.length ? walk(node.children) : null;
      if (hit) return hit;
    }
    return null;
  };
  return walk(Array.isArray(nodes) ? nodes : []);
}

/**
 * The SELECTABLE nodes matching `query`, at any depth, each with the breadcrumb of its
 * ancestor labels (outermost first) so a flat result row stays readable.
 *
 * Matching is case-insensitive and substring-based over the node's LABEL and its PATH — the
 * path carries the ancestor keys, so searching a container's key ("contact") still surfaces
 * its leaves ("Email" at `fields.contact.email`) without a separate ancestor rule. A blank
 * query matches everything selectable (the caller decides whether to search or render the
 * tree). A non-selectable object container is NEVER returned — it cannot be picked, so
 * offering it as a search result would be a dead end.
 */
export function searchNodes(
  nodes: VariableNode[] | null | undefined,
  query: string | null | undefined,
): VariableNodeMatch[] {
  const needle = (query ?? '').trim().toLowerCase();
  const out: VariableNodeMatch[] = [];
  const walk = (list: VariableNode[], trail: string[]): void => {
    for (const node of list) {
      const label = node.label ?? '';
      const hit =
        needle === '' ||
        label.toLowerCase().includes(needle) ||
        node.path.toLowerCase().includes(needle);
      if (hit && node.selectable) out.push({ node, breadcrumb: [...trail] });
      if (node.children?.length) walk(node.children, [...trail, label]);
    }
  };
  walk(Array.isArray(nodes) ? nodes : [], []);
  return out;
}

// --- Icons ------------------------------------------------------------------

/** The per-flat-type glyph. A container overrides this with the braces glyph (see below). */
const TYPE_ICONS: Record<VariableType, IconName> = {
  text: 'type',
  number: 'hash',
  boolean: 'check-circle',
  date: 'calendar',
  enum: 'list',
  multi: 'list-checks',
  file: 'file-text',
};

/**
 * The glyph for one node: an OBJECT base (section / object global / repeater) reads as a
 * container (braces) even though its flat type degraded to `text`; everything else uses its
 * flat type's glyph. An unknown type falls back to the text glyph (fail-soft).
 */
export function nodeIcon(node: Pick<VariableNode, 'type' | 'base'>): IconName {
  if (node?.base === 'object') return 'braces';
  return TYPE_ICONS[node?.type] ?? 'type';
}
