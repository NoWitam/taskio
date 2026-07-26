// The SHARED variable MODEL — the single vocabulary every variable-capable surface
// (markdown-editor chip, value-or-variable field, condition source, pipeline argument)
// speaks. Model only: no Vue, no i18n, no DOM, no HTTP.
//
// WHY THIS LIVES IN `ui/` AND DECLARES ITS OWN SHAPES
// The variable model is a DESIGN-SYSTEM concern (the picker/tree/chip family renders it),
// so it must never import from `pages/**`. `VariableSourceVar` below is therefore declared
// LOCALLY but is STRUCTURALLY IDENTICAL to the workflows page's `CatalogVariable`
// (`pages/workflows/types.ts`) — same keys, same unions, same optionality — so a page can
// pass its catalog arrays straight in with ZERO mapping, and TypeScript's structural typing
// accepts them in both directions. If the backend contract ever widens `CatalogVariable`,
// mirror it here; do NOT import across the boundary.

import type { VariablePipelineStep } from '../editor/extensions/types';

// --- The wire vocabulary (mirrors the backend catalog 1:1) ------------------

/**
 * A variable's ROOT source: the trigger payload, an earlier step's outputs, the
 * workspace's user-authored `globals.<key>` literal constants, or a SCOPED synthetic
 * variable (`scope`) that exists ONLY inside an element pipeline (array-transform wave 2):
 * `Element` (path `element`) and `Indeks` (path `index`). A `scope` ref is contextual —
 * the element-pipeline editor injects it into that pipeline's browser feed alone; it is
 * NEVER a global variable and the backend resolver recognises it only under the per-element
 * overlay (fail-closed elsewhere).
 */
export type VariableSource = 'trigger' | 'steps' | 'globals' | 'scope';

/**
 * The FLAT wire type a variable carries (mirrors `WorkflowVariableType`). The two
 * DESCRIPTOR-ONLY bases (`time`, `object`) never appear here — they degrade to `text` on
 * the flat wire — so this union stays closed at seven members.
 */
export type VariableType = 'text' | 'number' | 'boolean' | 'date' | 'enum' | 'multi' | 'file';

/**
 * The REAL structural base a descriptor advertises. A superset of `VariableType`: it adds
 * `time` (degrades to `text`) and `object` (a STRUCTURAL container — a form SECTION or an
 * object GLOBAL when `array:false`, a REPEATER when `array:true`), and drops `multi`
 * (which is `base:'enum'` + `array:true`, not a base of its own).
 */
export type VariableBase =
  | 'text'
  | 'number'
  | 'boolean'
  | 'date'
  | 'enum'
  | 'time'
  | 'file'
  | 'object';

/**
 * One choice of an enum/multi variable: the stored/emitted wire `key` plus its human
 * `label`. The UI shows the label and emits the key — never the other way round.
 */
export interface VariableDescriptorOption {
  key: string;
  label: string;
}

/**
 * One structural SUBFIELD of a container descriptor: the wire segment `key` appended to the
 * parent path (`<parent>.<key>`), the human `label`, and a full nested `descriptor` (so a
 * section-inside-an-object edge stays inspectable to any depth).
 */
export interface VariableDescriptorField {
  key: string;
  label: string;
  descriptor: VariableDescriptor;
}

/**
 * The structured TYPE descriptor a catalog variable carries alongside its flat `type`:
 *   - `base`     the REAL base (see `VariableBase`),
 *   - `nullable` the path is only sometimes present,
 *   - `array`    a multi (array of `enum`) or a repeater (array of `object`),
 *   - `options`  enum/multi choices, with their REAL human labels,
 *   - `fields`   the children of a `file` composite ({id,name,type,size,url}) or an
 *                `object` container (a section's / repeater-element's / global's children).
 */
export interface VariableDescriptor {
  base: VariableBase;
  nullable: boolean;
  array: boolean;
  options?: VariableDescriptorOption[];
  fields?: VariableDescriptorField[];
}

/**
 * ONE offered variable, exactly as the backend catalog emits it. Structurally identical to
 * the workflows page's `CatalogVariable` (see the module header) — a page passes its
 * `catalog.variables` / step-output arrays in unchanged.
 *
 * `descriptor` is OPTIONAL: older/simpler entries (and locally synthesised step outputs)
 * carry only the flat `type` + `enumOptions`, and the tree builder degrades gracefully.
 */
export interface VariableSourceVar {
  source: VariableSource;
  path: string;
  name: string;
  type: VariableType;
  descriptor?: VariableDescriptor;
  enumOptions?: string[];
  nullable?: boolean;
}

// --- The rendered model ----------------------------------------------------

/**
 * ONE node of the variable TREE — everything a row needs to render and everything a pick
 * needs to emit, with no descriptor archaeology at the call site.
 *
 * `path` is the EMITTED path (after `VariableSlotPolicy.pathTransform`), i.e. exactly the
 * string a saved reference stores. `type` is the flat wire type; `base` is the structural
 * base (a container reads as `object` even though its flat type degraded to `text`).
 * `nullable` / `array` are the type MARKERS a row renders (`?` / `[]`) — `array` is also
 * what makes a repeater read as a list.
 *
 * `selectable` answers "does clicking this emit a reference?"; `children` (present ⇒
 * EXPANDABLE) answers "does clicking this open a level?". The two are independent: a file
 * composite is both, a section is expandable only, a scalar is selectable only. A node that
 * is NEITHER can only render as a dead row, so the builder prunes it.
 *
 * `source` is the ROOT source a pick must emit alongside the path. It is carried from the
 * variable's OWN `source` (a descriptor child inherits its parent's) and is deliberately NOT
 * re-derived from the path root: a slot may rewrite paths (the conditions surface emits
 * `fields.<id>`, losing the `trigger.` root) while the emitted source must stay correct.
 */
export interface VariableNode {
  source: VariableSource;
  path: string;
  label: string;
  type: VariableType;
  base: VariableBase;
  nullable: boolean;
  array: boolean;
  options?: VariableDescriptorOption[];
  selectable: boolean;
  children?: VariableNode[];
}

/**
 * What ONE slot accepts, on top of the universal selectability rule.
 *
 * NOTE there is deliberately NO `acceptsObject` flag: a non-array `object` container is
 * NEVER selectable on ANY surface, so no slot can opt into it.
 *
 *   - `acceptedPaths` when provided, RESTRICTS selection to those emitted paths; a node it
 *                     excludes becomes expand-only (the condition builder uses this — only
 *                     the catalog's condition FIELDS are valid `source` paths, while their
 *                     sections still have to be walkable). A node left with NEITHER
 *                     selectability NOR children is then PRUNED as a dead row, so an
 *                     excluded LEAF disappears while an excluded CONTAINER stays walkable.
 *   - `pathTransform` maps a source path to the path this slot EMITS (the condition
 *                     builder speaks `fields.<id>`, not `trigger.fields.<id>`). Applied
 *                     ONCE per source variable; children compose from the already-mapped
 *                     parent path, so a prefix strip never double-applies.
 *
 * There is deliberately NO `stripIdentifiers` flag (B3): the SF3.2 strip is now scoped to
 * SYSTEM identity paths by construction (`isSystemIdentifierPath`), so a user-authored form
 * field named `order_id` / `numer_id` is offered on EVERY surface and no slot has anything
 * to opt out of.
 */
export interface VariableSlotPolicy {
  acceptedPaths?: Set<string>;
  pathTransform?: (path: string) => string;
}

/**
 * ONE typed LITERAL value a variable surface can author: the per-reference "default when
 * empty", an object global's leaf value, an enum choice key. `null` always means UNSET
 * (never "false" / "empty string") — which is what lets a boolean default stay tri-state.
 */
export type VariableLiteral = string | number | boolean | null;

/**
 * The descriptor bases a `TypedLiteralInput` can render a control for: the four scalars plus
 * `enum` (a Select over the descriptor options). `time` / `file` / `object` are NOT authorable
 * as a single literal — a surface must not offer one.
 */
export type VariableLiteralBase = 'text' | 'number' | 'boolean' | 'date' | 'enum';

/**
 * A variable REFERENCE being edited — the normalised in-memory draft shared by every
 * surface (the chip's node attrs, a value-or-variable field's `{kind:'variable'}` arm, a
 * condition leaf, a pipeline argument). Always fully populated: `pipeline` is `[]` for a
 * plain identity ref and `default` is `null` when unset. Projecting to the wire is the
 * caller's job (an empty pipeline / null default are OMITTED so a ref serialises
 * byte-identically to today).
 *
 * This is the vocabulary `VariableReferenceEditor` emits, and it is deliberately WIRE-FREE:
 * a host maps it onto whatever union/attrs it persists.
 */
export interface VariableRefDraft {
  source: VariableSource;
  path: string;
  type: VariableType;
  pipeline: VariablePipelineStep[];
  default: VariableLiteral;
}

/**
 * One `searchNodes` hit: the matched (always SELECTABLE) node plus the labels of its
 * ancestors, outermost first — the breadcrumb a flat result row renders so a leaf named
 * "Name" is readable as "Attachment › Name".
 */
export interface VariableNodeMatch {
  node: VariableNode;
  breadcrumb: string[];
}
