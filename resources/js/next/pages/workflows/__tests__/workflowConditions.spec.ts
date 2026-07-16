// workflowConditions.spec — the PURE condition-tree core (B3).
//
// Pins the drift-critical glue: draft⇄wire round-trip, the LEGACY flat→tree operator
// table (ALL operators), emit-or-OMIT of an empty tree, the isTreeComplete save gate,
// and the tree limits/helpers (depth, children, find/remove/update).
import { describe, it, expect, beforeAll } from 'vitest';
import { setLocale } from '../../../app/i18n';
import { standardOperationsCatalog } from '../../../ui/editor/extensions/standardOperations';
import type { VariableOperationDefinition, VariablePipelineStep } from '../../../ui/editor/extensions/types';
import type { WireCondition, WireConditionGroup, WorkflowCondition, WorkflowConditionOperator } from '../types';
import {
  CONDITION_LIMITS,
  addConditionToGroup,
  addGroupToGroup,
  draftToWire,
  emptyConditionTree,
  findNode,
  isTreeComplete,
  makeCondition,
  removeNode,
  setGroupLogic,
  treeDepth,
  updateCondition,
  wireToDraft,
  type DraftConditionGroup,
} from '../workflowConditions';

const CATALOG: VariableOperationDefinition[] = standardOperationsCatalog();

function step(op: string, args: Record<string, unknown> = {}): VariablePipelineStep {
  return { stepId: 's', operationId: op, args: args as VariablePipelineStep['args'], outputType: 'boolean' };
}

/** The pipeline a single legacy condition converts to (through the tree round-trip). */
function legacyPipeline(
  operator: WorkflowConditionOperator,
  value: unknown,
  fieldType: WorkflowCondition['field_type'] = 'text',
) {
  const draft = wireToDraft([{ field: 'fields.x', field_type: fieldType, operator, value }]);
  const wire = draftToWire(draft) as WireConditionGroup;
  return (wire.children[0] as WireCondition).pipeline;
}

describe('workflowConditions — draft⇄wire round-trip', () => {
  it('round-trips a nested AND/OR tree byte-for-byte on the wire', () => {
    let tree = emptyConditionTree();
    const c1 = makeCondition({
      source: 'fields.a',
      sourceType: 'text',
      pipeline: [step('text_trim'), step('text_equals', { value: 'Jan' })],
    });
    tree = addConditionToGroup(tree, tree.uid, c1);
    tree = addGroupToGroup(tree, tree.uid, 'or');
    const sub = tree.children[1] as DraftConditionGroup;
    const c2 = makeCondition({ source: 'fields.b', sourceType: 'number', pipeline: [step('num_gt', { value: 5 })] });
    tree = addConditionToGroup(tree, sub.uid, c2);

    const wire = draftToWire(tree);
    expect(wire).toEqual({
      logic: 'and',
      children: [
        {
          kind: 'condition',
          source: 'fields.a',
          source_type: 'text',
          pipeline: [
            { op: 'text_trim', args: {} },
            { op: 'text_equals', args: { value: 'Jan' } },
          ],
        },
        {
          kind: 'group',
          logic: 'or',
          children: [
            { kind: 'condition', source: 'fields.b', source_type: 'number', pipeline: [{ op: 'num_gt', args: { value: 5 } }] },
          ],
        },
      ],
    });

    // A second round-trip reproduces the same wire (uids differ but never serialize).
    const back = draftToWire(wireToDraft(wire!));
    expect(back).toEqual(wire);
  });
});

describe('workflowConditions — LEGACY flat → tree conversion (all operators)', () => {
  const cases: Array<[WorkflowConditionOperator, unknown, WorkflowCondition['field_type'], Array<{ op: string; args: Record<string, unknown> }>]> = [
    // text
    ['equals', 'v', 'text', [{ op: 'text_equals', args: { value: 'v' } }]],
    ['not_equals', 'v', 'text', [{ op: 'text_not_equals', args: { value: 'v' } }]],
    ['contains', 'v', 'text', [{ op: 'text_contains', args: { value: 'v' } }]],
    // number
    ['eq', 5, 'number', [{ op: 'num_eq', args: { value: 5 } }]],
    ['neq', 5, 'number', [{ op: 'num_neq', args: { value: 5 } }]],
    ['gt', 5, 'number', [{ op: 'num_gt', args: { value: 5 } }]],
    ['gte', 5, 'number', [{ op: 'num_gte', args: { value: 5 } }]],
    ['lt', 5, 'number', [{ op: 'num_lt', args: { value: 5 } }]],
    ['lte', 5, 'number', [{ op: 'num_lte', args: { value: 5 } }]],
    // date
    ['before', '2026-01-01', 'date', [{ op: 'date_before', args: { value: '2026-01-01' } }]],
    ['after', '2026-01-01', 'date', [{ op: 'date_after', args: { value: '2026-01-01' } }]],
    ['on', '2026-01-01', 'date', [{ op: 'date_on', args: { value: '2026-01-01' } }]],
    ['between', ['2026-01-01', '2026-02-01'], 'date', [{ op: 'date_between', args: { from: '2026-01-01', to: '2026-02-01' } }]],
    // enum
    ['is', 'open', 'enum', [{ op: 'enum_is', args: { value: 'open' } }]],
    ['is_not', 'open', 'enum', [{ op: 'enum_is_not', args: { value: 'open' } }]],
    ['in', ['open', 'done'], 'enum', [{ op: 'enum_in', args: { values: ['open', 'done'] } }]],
    // multi
    ['includes', 'red', 'multi', [{ op: 'multi_includes', args: { value: 'red' } }]],
    ['excludes', 'red', 'multi', [{ op: 'multi_excludes', args: { value: 'red' } }]],
    // boolean (value-less)
    ['is_true', undefined, 'boolean', []],
    ['is_false', undefined, 'boolean', [{ op: 'bool_not', args: {} }]],
  ];

  it.each(cases)('%s → the mapped op + args', (operator, value, fieldType, expected) => {
    expect(legacyPipeline(operator, value, fieldType)).toEqual(expected);
  });

  it('wraps a legacy list in a single AND group carrying source_type', () => {
    const wire = draftToWire(
      wireToDraft([
        { field: 'fields.name', field_type: 'text', operator: 'equals', value: 'Jan' },
        { field: 'fields.count', field_type: 'number', operator: 'gt', value: 3 },
      ]),
    );
    expect(wire?.logic).toBe('and');
    expect(wire?.children).toHaveLength(2);
    expect((wire!.children[0] as WireCondition).source_type).toBe('text');
    expect((wire!.children[1] as WireCondition).source_type).toBe('number');
  });
});

describe('workflowConditions — emit-or-OMIT', () => {
  it('an empty tree omits conditions (undefined)', () => {
    expect(draftToWire(emptyConditionTree())).toBeUndefined();
  });

  it('a tree with children emits the group', () => {
    let tree = emptyConditionTree();
    tree = addConditionToGroup(tree, tree.uid, makeCondition({ source: 'fields.a', sourceType: 'text', pipeline: [step('text_is_empty')] }));
    expect(draftToWire(tree)).toBeDefined();
  });

  it('wireToDraft(null / undefined / []) → an empty tree', () => {
    expect(wireToDraft(null).children).toEqual([]);
    expect(wireToDraft(undefined).children).toEqual([]);
    expect(wireToDraft([]).children).toEqual([]);
  });
});

describe('workflowConditions — isTreeComplete (save gate)', () => {
  it('an empty tree is complete ("always runs")', () => {
    expect(isTreeComplete(emptyConditionTree(), CATALOG)).toBe(true);
  });

  it('a boolean-terminating condition is complete', () => {
    let tree = emptyConditionTree();
    tree = addConditionToGroup(tree, tree.uid, makeCondition({ source: 'fields.a', sourceType: 'text', pipeline: [step('text_equals', { value: 'x' })] }));
    expect(isTreeComplete(tree, CATALOG)).toBe(true);
  });

  it('a non-boolean pipeline blocks completion', () => {
    let tree = emptyConditionTree();
    // text_trim → text (never a boolean) → incomplete.
    tree = addConditionToGroup(tree, tree.uid, makeCondition({ source: 'fields.a', sourceType: 'text', pipeline: [step('text_trim')] }));
    expect(isTreeComplete(tree, CATALOG)).toBe(false);
  });

  it('a missing source blocks completion', () => {
    let tree = emptyConditionTree();
    tree = addConditionToGroup(tree, tree.uid, makeCondition({ source: '', sourceType: 'boolean', pipeline: [] }));
    expect(isTreeComplete(tree, CATALOG)).toBe(false);
  });

  it('an empty nested group blocks completion', () => {
    let tree = emptyConditionTree();
    tree = addConditionToGroup(tree, tree.uid, makeCondition({ source: 'fields.a', sourceType: 'text', pipeline: [step('text_is_empty')] }));
    tree = addGroupToGroup(tree, tree.uid); // an empty sub-group
    expect(isTreeComplete(tree, CATALOG)).toBe(false);
  });
});

describe('workflowConditions — limits + tree helpers', () => {
  beforeAll(() => setLocale('en'));

  it('exposes the backend limits', () => {
    expect(CONDITION_LIMITS).toEqual({ maxDepth: 5, maxGroupChildren: 10, maxPipelineSteps: 10 });
  });

  it('treeDepth grows with nesting (root = 1)', () => {
    let tree = emptyConditionTree();
    expect(treeDepth(tree)).toBe(1);
    tree = addGroupToGroup(tree, tree.uid);
    expect(treeDepth(tree)).toBe(2);
    const sub = tree.children[0] as DraftConditionGroup;
    tree = addGroupToGroup(tree, sub.uid);
    expect(treeDepth(tree)).toBe(3);
  });

  it('addConditionToGroup grows a group; removeNode + updateCondition + setGroupLogic mutate by uid', () => {
    let tree = emptyConditionTree();
    const cond = makeCondition({ source: 'fields.a', sourceType: 'text', pipeline: [step('text_is_empty')] });
    tree = addConditionToGroup(tree, tree.uid, cond);
    expect(tree.children).toHaveLength(1);

    tree = setGroupLogic(tree, tree.uid, 'or');
    expect(tree.logic).toBe('or');

    tree = updateCondition(tree, cond.uid, { source: 'fields.b', sourceType: 'number', pipeline: [step('num_eq', { value: 1 })] });
    const updated = findNode(tree, cond.uid);
    expect(updated && updated.kind === 'condition' && updated.source).toBe('fields.b');

    tree = removeNode(tree, cond.uid);
    expect(tree.children).toHaveLength(0);
    expect(findNode(tree, cond.uid)).toBeNull();
  });
});
