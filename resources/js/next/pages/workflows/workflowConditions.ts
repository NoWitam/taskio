// workflowConditions — the PURE, testable core of the B3 condition TREE.
//
// The conditions section of a form_submitted workflow is a TREE of logical groups
// (AND/OR) whose leaves are CONDITIONS. A condition is a form field run through an
// operations PIPELINE that must end on a boolean (the exact same pipeline the
// markdown editor's variable/IF-condition builders use). This module owns the
// drift-critical glue:
//   • the DRAFT tree types (local `uid`s for stable v-for keys),
//   • `emptyConditionTree()` + immutable add/remove/update node helpers,
//   • `draftToWire()` — emit-or-OMIT: an empty tree yields `undefined` so the drawer
//     drops the `conditions` key entirely,
//   • `wireToDraft()` — hydrates the tree shape AND converts a LEGACY flat list to a
//     single AND group (operator → op table below),
//   • `isTreeComplete()` — the save gate (every condition: a source + a pipeline that
//     resolves to boolean; every group: ≥1 child),
//   • `resolveOperationCatalog()` — merges the backend operation DESCRIPTORS with the
//     FE `standardOperationsCatalog()` labels (unknown id → label = id),
//   • `conditionSummary()` — the human "field → op → op" chip sentence parts.
//
// ── LEGACY conversion semantics (documented behavior change) ─────────────────────
// The flat operators split into two runtime families: PRESENCE ("equals") and
// ABSENCE ("not_equals"/"is_not"/"excludes"). In the OLD flat engine an ABSENCE
// operator PASSED when the field was missing from a submission. The TREE engine has
// no such carve-out: a missing field makes the pipeline fail-closed, so a converted
// `not_equals` now reads as FALSE when the field is absent. This is a CONSCIOUS,
// documented difference that only affects EDITING pre-existing (flat) workflows.
import type {
  VariableOperationArgumentType,
  VariableOperationDefinition,
  VariablePipelineStep,
  VariablePrimitive,
} from '../../ui/editor/extensions/types';
import { resolveType } from '../../ui/editor/extensions/operationHelpers';
import { standardOperationsCatalog } from '../../ui/editor/extensions/standardOperations';
import type {
  CatalogField,
  ConditionLogic,
  WorkflowCatalog,
  WorkflowCondition,
  WorkflowConditionOperator,
  WorkflowVariableType,
  WireCondition,
  WireConditionGroup,
  WireConditionNode,
} from './types';

// --- Limits (mirror the backend validator) ----------------------------------
/** Depth ≤ 5 (root = 1), children ≤ 10 per group, pipeline steps ≤ 10. */
export const CONDITION_LIMITS = {
  maxDepth: 5,
  maxGroupChildren: 10,
  maxPipelineSteps: 10,
} as const;

// --- Draft tree types (local uids) ------------------------------------------

/** A leaf condition draft: a source field + its pipeline (editor step shape). */
export interface DraftCondition {
  uid: string;
  kind: 'condition';
  /** `fields.<id>` path, or '' before a field is chosen. */
  source: string;
  /** The field's TRUE workflow type (the pipeline's base type). */
  sourceType: WorkflowVariableType;
  pipeline: VariablePipelineStep[];
}

/** A group draft: logic over children. */
export interface DraftConditionGroup {
  uid: string;
  kind: 'group';
  logic: ConditionLogic;
  children: DraftConditionNode[];
}

export type DraftConditionNode = DraftCondition | DraftConditionGroup;

/** The payload the condition Modal emits (a condition without its identity). */
export interface ConditionDraftPayload {
  source: string;
  sourceType: WorkflowVariableType;
  pipeline: VariablePipelineStep[];
}

// --- Local uid sequence (never sent to the server) --------------------------
let uidSeq = 0;
export function conditionUid(prefix = 'node'): string {
  uidSeq += 1;
  return `${prefix}-${uidSeq}`;
}

/** A fresh empty tree: an AND group with no children ("always runs"). */
export function emptyConditionTree(): DraftConditionGroup {
  return { uid: conditionUid('group'), kind: 'group', logic: 'and', children: [] };
}

/** Wrap a Modal payload into an identified condition draft. */
export function makeCondition(payload: ConditionDraftPayload): DraftCondition {
  return {
    uid: conditionUid('cond'),
    kind: 'condition',
    source: payload.source,
    sourceType: payload.sourceType,
    pipeline: payload.pipeline,
  };
}

// --- op → outputType lookup (lazy; for display fallback only) ----------------
let opOutput: Map<string, VariablePrimitive> | null = null;
function outputTypeOf(op: string): VariablePrimitive {
  if (!opOutput) opOutput = new Map(standardOperationsCatalog().map((o) => [o.id, o.outputType]));
  return opOutput.get(op) ?? 'boolean';
}

/** Build an editor pipeline step from a wire `{op, args}`. */
function makePipelineStep(op: string, args: Record<string, unknown>): VariablePipelineStep {
  return {
    stepId: conditionUid('step'),
    operationId: op,
    args: args as VariablePipelineStep['args'],
    outputType: outputTypeOf(op),
  };
}

// --- Immutable tree helpers -------------------------------------------------

/** Replace the node with `uid` by `fn(node)` (deep, immutable). */
function replaceNode(
  node: DraftConditionNode,
  uid: string,
  fn: (n: DraftConditionNode) => DraftConditionNode,
): DraftConditionNode {
  if (node.uid === uid) return fn(node);
  if (node.kind === 'group') {
    return { ...node, children: node.children.map((c) => replaceNode(c, uid, fn)) };
  }
  return node;
}

/** Append a condition to the group with `groupUid`. */
export function addConditionToGroup(
  root: DraftConditionGroup,
  groupUid: string,
  condition: DraftCondition,
): DraftConditionGroup {
  return replaceNode(root, groupUid, (n) =>
    n.kind === 'group' ? { ...n, children: [...n.children, condition] } : n,
  ) as DraftConditionGroup;
}

/** Append a new (empty AND) sub-group to the group with `groupUid`. */
export function addGroupToGroup(
  root: DraftConditionGroup,
  groupUid: string,
  logic: ConditionLogic = 'and',
): DraftConditionGroup {
  const group: DraftConditionGroup = { uid: conditionUid('group'), kind: 'group', logic, children: [] };
  return replaceNode(root, groupUid, (n) =>
    n.kind === 'group' ? { ...n, children: [...n.children, group] } : n,
  ) as DraftConditionGroup;
}

/** Remove the node with `uid` from wherever it sits (the root is never removed). */
export function removeNode(root: DraftConditionGroup, uid: string): DraftConditionGroup {
  const prune = (group: DraftConditionGroup): DraftConditionGroup => ({
    ...group,
    children: group.children
      .filter((c) => c.uid !== uid)
      .map((c) => (c.kind === 'group' ? prune(c) : c)),
  });
  return prune(root);
}

/** Patch the condition with `uid` (source / sourceType / pipeline). */
export function updateCondition(
  root: DraftConditionGroup,
  uid: string,
  payload: ConditionDraftPayload,
): DraftConditionGroup {
  return replaceNode(root, uid, (n) =>
    n.kind === 'condition' ? { ...n, source: payload.source, sourceType: payload.sourceType, pipeline: payload.pipeline } : n,
  ) as DraftConditionGroup;
}

/** Set a group's logic (AND ⇄ OR). */
export function setGroupLogic(
  root: DraftConditionGroup,
  groupUid: string,
  logic: ConditionLogic,
): DraftConditionGroup {
  return replaceNode(root, groupUid, (n) => (n.kind === 'group' ? { ...n, logic } : n)) as DraftConditionGroup;
}

/** Find any node by uid (depth-first). */
export function findNode(root: DraftConditionGroup, uid: string): DraftConditionNode | null {
  if (root.uid === uid) return root;
  for (const child of root.children) {
    if (child.uid === uid) return child;
    if (child.kind === 'group') {
      const found = findNode(child, uid);
      if (found) return found;
    }
  }
  return null;
}

/** The tree's maximum depth (root = 1). */
export function treeDepth(root: DraftConditionGroup): number {
  const depthOf = (group: DraftConditionGroup, depth: number): number => {
    let max = depth;
    for (const child of group.children) {
      if (child.kind === 'group') max = Math.max(max, depthOf(child, depth + 1));
    }
    return max;
  };
  return depthOf(root, 1);
}

// --- draftToWire (emit-or-OMIT) ---------------------------------------------

/**
 * Project the draft tree onto the wire. An empty tree (root with no children)
 * returns `undefined` so the caller OMITS `conditions` entirely. The root is emitted
 * WITHOUT `kind`; nested groups carry `kind:'group'`; each pipeline step becomes
 * `{op, args}`.
 */
export function draftToWire(root: DraftConditionGroup): WireConditionGroup | undefined {
  if (!root.children.length) return undefined;
  return groupToWire(root, true);
}

function groupToWire(group: DraftConditionGroup, isRoot: boolean): WireConditionGroup {
  const wire: WireConditionGroup = { logic: group.logic, children: group.children.map(nodeToWire) };
  return isRoot ? wire : { kind: 'group', ...wire };
}

function nodeToWire(node: DraftConditionNode): WireConditionNode {
  if (node.kind === 'group') return groupToWire(node, false);
  return {
    kind: 'condition',
    source: node.source,
    source_type: node.sourceType,
    pipeline: node.pipeline.map((step) => ({ op: step.operationId, args: step.args })),
  };
}

// --- wireToDraft (tree OR legacy flat list) ---------------------------------

/**
 * Hydrate a draft tree from the wire. Accepts the TREE object, the LEGACY flat
 * `WorkflowCondition[]`, or null/undefined (→ an empty tree). A legacy list becomes a
 * single AND group of converted conditions.
 */
export function wireToDraft(
  wire: WireConditionGroup | WorkflowCondition[] | null | undefined,
): DraftConditionGroup {
  if (!wire) return emptyConditionTree();
  if (Array.isArray(wire)) return legacyListToDraft(wire);
  return groupToDraft(wire);
}

function groupToDraft(group: WireConditionGroup): DraftConditionGroup {
  return {
    uid: conditionUid('group'),
    kind: 'group',
    logic: group.logic ?? 'and',
    children: (group.children ?? []).map(wireNodeToDraft),
  };
}

function isGroupWire(node: WireConditionNode): node is WireConditionGroup {
  return (node as WireConditionGroup).children !== undefined || (node as { kind?: string }).kind === 'group';
}

function wireNodeToDraft(node: WireConditionNode): DraftConditionNode {
  if (isGroupWire(node)) return groupToDraft(node);
  const condition = node as WireCondition;
  return {
    uid: conditionUid('cond'),
    kind: 'condition',
    source: condition.source,
    sourceType: condition.source_type,
    pipeline: (condition.pipeline ?? []).map((step) => makePipelineStep(step.op, step.args ?? {})),
  };
}

// --- LEGACY flat list → tree ------------------------------------------------

/** operator → op id (the value-carrying operators; is_true/is_false handled apart). */
const LEGACY_OP: Record<string, string> = {
  equals: 'text_equals',
  not_equals: 'text_not_equals',
  contains: 'text_contains',
  eq: 'num_eq',
  neq: 'num_neq',
  gt: 'num_gt',
  gte: 'num_gte',
  lt: 'num_lt',
  lte: 'num_lte',
  before: 'date_before',
  after: 'date_after',
  on: 'date_on',
  between: 'date_between',
  is: 'enum_is',
  is_not: 'enum_is_not',
  in: 'enum_in',
  includes: 'multi_includes',
  excludes: 'multi_excludes',
};

function legacyListToDraft(list: WorkflowCondition[]): DraftConditionGroup {
  return {
    uid: conditionUid('group'),
    kind: 'group',
    logic: 'and',
    children: list.map(legacyConditionToDraft),
  };
}

function legacyConditionToDraft(condition: WorkflowCondition): DraftCondition {
  return {
    uid: conditionUid('cond'),
    kind: 'condition',
    source: condition.field,
    sourceType: condition.field_type,
    pipeline: legacyPipeline(condition.operator, condition.value),
  };
}

/** Build the one-step (or empty) pipeline a flat operator maps to. */
function legacyPipeline(operator: WorkflowConditionOperator, value: unknown): VariablePipelineStep[] {
  if (operator === 'is_true') return []; // a boolean source is already a boolean
  if (operator === 'is_false') return [makePipelineStep('bool_not', {})];
  const op = LEGACY_OP[operator];
  if (!op) return [];
  return [makePipelineStep(op, legacyArgs(operator, value))];
}

function legacyArgs(operator: WorkflowConditionOperator, value: unknown): Record<string, unknown> {
  if (operator === 'between') {
    const pair = Array.isArray(value) ? value : [];
    return { from: pair[0] ?? '', to: pair[1] ?? '' };
  }
  if (operator === 'in') {
    return { values: Array.isArray(value) ? value : [] };
  }
  return { value: value ?? '' };
}

// --- isTreeComplete (the save gate) -----------------------------------------

/**
 * Whether every condition in the tree is well-formed enough to save: each has a
 * source and a pipeline that resolves to boolean; each group has ≥1 child. An EMPTY
 * tree (no children) is COMPLETE — it means "always runs".
 */
export function isTreeComplete(root: DraftConditionGroup, catalog: VariableOperationDefinition[]): boolean {
  if (root.children.length === 0) return true;
  return root.children.every((child) => nodeComplete(child, catalog));
}

function nodeComplete(node: DraftConditionNode, catalog: VariableOperationDefinition[]): boolean {
  if (node.kind === 'group') {
    if (node.children.length === 0) return false;
    return node.children.every((child) => nodeComplete(child, catalog));
  }
  if (!node.source || !node.source.trim()) return false;
  return resolveType(catalog, node.sourceType as VariablePrimitive, node.pipeline) === 'boolean';
}

// --- resolveOperationCatalog (descriptors × FE labels) ----------------------

/**
 * The operations catalog the condition UI runs on. When the backend catalog carries
 * `operations` DESCRIPTORS, each is mapped to its FULL `standardOperationsCatalog()`
 * definition by id (labels + arg labels + control types); a descriptor with NO known
 * label degrades to `label = id`. When `operations` is absent (older responses) the
 * whole standard catalog is used. Call inside a computed for locale reactivity.
 */
export function resolveOperationCatalog(
  catalog: WorkflowCatalog | null | undefined,
): VariableOperationDefinition[] {
  const standard = standardOperationsCatalog();
  const descriptors = catalog?.operations;
  if (!descriptors || descriptors.length === 0) return standard;

  const byId = new Map(standard.map((op) => [op.id, op]));
  return descriptors.map((descriptor) => {
    const known = byId.get(descriptor.id);
    if (known) return known;
    // Defensive: an unknown descriptor is still usable, just unlabeled (id as label).
    return {
      id: descriptor.id,
      label: descriptor.id,
      inputTypes: [descriptor.input] as VariablePrimitive[],
      outputType: descriptor.output as VariablePrimitive,
      args: (descriptor.args ?? []).map((arg) => ({
        id: arg.id,
        label: arg.id,
        type: arg.type as VariableOperationArgumentType,
        mapType: arg.mapType,
      })),
    };
  });
}

// --- conditionSummary (chip sentence parts) ---------------------------------

/** One step of the chip sentence: an op label + an optional compact value echo. */
export interface ConditionSummaryStep {
  label: string;
  value: string | null;
}
export interface ConditionSummary {
  fieldLabel: string;
  steps: ConditionSummaryStep[];
}

/**
 * The parts of a condition's human sentence: the field label (from the catalog, else
 * the path's last segment) + one entry per pipeline step (op label + a short value
 * echo). The component renders these with type icons + arrows and joins them for the
 * chip's aria-label.
 */
export function conditionSummary(
  condition: DraftCondition,
  fields: CatalogField[],
  catalog: VariableOperationDefinition[],
): ConditionSummary {
  const field = fields.find((f) => f.path === condition.source);
  const fieldLabel = field?.label ?? lastSegment(condition.source);
  const byId = new Map(catalog.map((op) => [op.id, op]));
  const steps = condition.pipeline.map((step) => {
    const op = byId.get(step.operationId);
    return { label: op?.label ?? step.operationId, value: stepValueText(step, op) };
  });
  return { fieldLabel, steps };
}

function lastSegment(path: string): string {
  if (!path) return '';
  const parts = path.split('.');
  return parts[parts.length - 1] || path;
}

function stepValueText(step: VariablePipelineStep, op: VariableOperationDefinition | undefined): string | null {
  const args = op?.args ?? [];
  if (!args.length) return null;
  const parts = args
    .map((arg) => {
      const raw = step.args[arg.id];
      if (Array.isArray(raw)) return raw.join(', ');
      if (raw && typeof raw === 'object') return ''; // sourceMap — omitted from the compact echo
      return raw === '' || raw == null ? '' : String(raw);
    })
    .filter((part) => part !== '');
  return parts.length ? parts.join(', ') : null;
}
