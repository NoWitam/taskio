// Unit tests for the editor model helpers (B7c steps + B7d trigger rebuild). These
// guard the DRIFT-CRITICAL step-config wire emission (§4.6) — the exact JSON
// `buildStepConfig` sends for each of the two 5.1 step types — the type-agnostic list
// invariants (unique key suggestion, add/remove(min-1)/reorder, duplicate-key
// detection), and the typed condition sanitation (§4.8). If the backend allow-list or
// union/condition contract changes, these tests break first. The removed legacy
// trigger draft / buildTriggerConfig / buildWorkflowPayload / ConditionDraft arms are
// no longer imported (the drawer builds the trigger_config payload inline in B7d).
import { describe, expect, it } from 'vitest';
import {
  buildStepConfig,
  duplicateKeyUids,
  emptyFormTriggerDraft,
  emptyStepConfig,
  isConditionComplete,
  makeStepDraft,
  moveStep,
  removeStep,
  sanitizeConditions,
  suggestStepKey,
  type StepDraft,
} from '../workflowEditorModel';
import type { WorkflowCondition, WorkflowFieldValue } from '../types';

function step(type: StepDraft['type'], config: Record<string, unknown>): StepDraft {
  return { uid: 'x', type, key: type === 'create_task' ? 'task' : 'report', config };
}

// --- emptyStepConfig --------------------------------------------------------

describe('emptyStepConfig — the seeded draft shape (§4.6)', () => {
  it('create_task seeds the full field set (empty)', () => {
    expect(emptyStepConfig('create_task')).toEqual({
      title: '',
      description: '',
      priority: null,
      deadline: null,
      labels: [],
      attachments: null,
      assignee_type: null,
      assignee_id: null,
      form_id: null,
      approval_pipeline_id: null,
    });
  });

  it('create_form_report seeds form_id/name/guidelines/sources/windows', () => {
    expect(emptyStepConfig('create_form_report')).toEqual({
      form_id: null,
      name: '',
      guidelines: '',
      sources: null,
      submissions_from: null,
      submissions_to: null,
    });
  });
});

// --- buildStepConfig: create_task wire shape --------------------------------

describe('buildStepConfig — create_task wire (strip empties, unions through, assignee both-or-neither)', () => {
  it('a bare (only-title) card emits ONLY title', () => {
    const cfg = buildStepConfig(step('create_task', { ...emptyStepConfig('create_task'), title: '  Do it  ' }));
    expect(cfg).toEqual({ title: 'Do it' });
  });

  it('emits every filled field with the exact keys + strips empty optionals', () => {
    const priority: WorkflowFieldValue = { kind: 'literal', value: 'high' };
    const deadline: WorkflowFieldValue<string> = {
      kind: 'variable',
      ref: { source: 'trigger', path: 'trigger.submitted_at', type: 'date' },
    };
    const cfg = buildStepConfig(
      step('create_task', {
        title: 'Ship',
        description: '',
        priority,
        deadline,
        labels: ['l1', 'l2'],
        assignee_type: 'bot',
        assignee_id: 'bot-uuid',
        form_id: 'form-uuid',
        approval_pipeline_id: '',
      }),
    );
    expect(cfg).toEqual({
      title: 'Ship',
      priority,
      deadline,
      labels: ['l1', 'l2'],
      assignee_type: 'bot',
      assignee_id: 'bot-uuid',
      form_id: 'form-uuid',
    });
    // Stripped empties are absent, not null.
    expect('description' in cfg).toBe(false);
    expect('approval_pipeline_id' in cfg).toBe(false);
  });

  it('passes an attachments union through and omits an empty one', () => {
    const literal: WorkflowFieldValue = { kind: 'literal', value: '11111111-1111-4111-8111-111111111111' };
    const withFile = buildStepConfig(step('create_task', { title: 'A', attachments: literal }));
    expect(withFile.attachments).toEqual(literal);

    // An empty literal (no file chosen) is stripped, not emitted as null.
    const empty = buildStepConfig(step('create_task', { title: 'A', attachments: { kind: 'literal', value: null } }));
    expect('attachments' in empty).toBe(false);
  });

  it('drops BOTH assignee keys when only one side is set', () => {
    const onlyType = buildStepConfig(step('create_task', { title: 'A', assignee_type: 'user', assignee_id: null }));
    expect('assignee_type' in onlyType).toBe(false);
    expect('assignee_id' in onlyType).toBe(false);

    const onlyId = buildStepConfig(step('create_task', { title: 'A', assignee_type: null, assignee_id: 'u1' }));
    expect('assignee_type' in onlyId).toBe(false);
    expect('assignee_id' in onlyId).toBe(false);
  });

  it('omits a cleared value-or-variable (literal null / empty) but keeps a real one', () => {
    const clearedPriority = buildStepConfig(
      step('create_task', { title: 'A', priority: { kind: 'literal', value: null } }),
    );
    expect('priority' in clearedPriority).toBe(false);

    const realPriority = buildStepConfig(
      step('create_task', { title: 'A', priority: { kind: 'literal', value: 'low' } }),
    );
    expect(realPriority.priority).toEqual({ kind: 'literal', value: 'low' });
  });

  it('drops an empty labels array', () => {
    const cfg = buildStepConfig(step('create_task', { title: 'A', labels: [] }));
    expect('labels' in cfg).toBe(false);
  });
});

// --- buildStepConfig: value-or-variable PIPELINE (SF1) ----------------------

describe('buildStepConfig — value-or-variable pipeline (SF1: wire + round-trip)', () => {
  const priorityWithPipeline: WorkflowFieldValue = {
    kind: 'variable',
    ref: { source: 'trigger', path: 'fields.status', type: 'enum' },
    pipeline: [{ op: 'enum_to_text', args: { status_map: { open: 'Open' } } }],
  };

  it('passes a variable ref WITH its operations pipeline through untouched', () => {
    const cfg = buildStepConfig(step('create_task', { title: 'A', priority: priorityWithPipeline }));
    expect(cfg.priority).toEqual(priorityWithPipeline);
  });

  it('strips an EMPTY pipeline array so an identity ref stays lean', () => {
    const cfg = buildStepConfig(
      step('create_task', {
        title: 'A',
        priority: { kind: 'variable', ref: { source: 'trigger', path: 'fields.status', type: 'enum' }, pipeline: [] },
      }),
    );
    expect(cfg.priority).toEqual({ kind: 'variable', ref: { source: 'trigger', path: 'fields.status', type: 'enum' } });
  });

  it('round-trips a variable+pipeline union (re-building a saved config is idempotent)', () => {
    const once = buildStepConfig(step('create_task', { title: 'A', priority: priorityWithPipeline }));
    // Feed the emitted config back as a hydrated draft (as the drawer's seedStep does).
    const twice = buildStepConfig(step('create_task', { title: 'A', priority: once.priority }));
    expect(twice.priority).toEqual(priorityWithPipeline);
  });

  it('emits a date-window pipeline on a report step (submissions_from)', () => {
    const from: WorkflowFieldValue<string> = {
      kind: 'variable',
      ref: { source: 'trigger', path: 'trigger.submitted_at', type: 'date' },
      pipeline: [{ op: 'date_add_days', args: { days: 7 } }],
    };
    const cfg = buildStepConfig(step('create_form_report', { form_id: 'f1', name: 'R', submissions_from: from }));
    expect(cfg.submissions_from).toEqual(from);
  });
});

// --- buildStepConfig: create_form_report wire shape -------------------------

describe('buildStepConfig — create_form_report wire (required form_id/name, report sources, windows)', () => {
  it('emits required form_id + name, strips empty optionals', () => {
    const cfg = buildStepConfig(
      step('create_form_report', { ...emptyStepConfig('create_form_report'), form_id: 'f1', name: '  Weekly  ' }),
    );
    expect(cfg).toEqual({ form_id: 'f1', name: 'Weekly' });
    expect('guidelines' in cfg).toBe(false);
    expect('sources' in cfg).toBe(false);
    expect('submissions_from' in cfg).toBe(false);
  });

  it('emits sources subset (task/form) and the date-or-variable windows', () => {
    const from: WorkflowFieldValue<string> = { kind: 'literal', value: '2026-01-01' };
    const cfg = buildStepConfig(
      step('create_form_report', {
        form_id: 'f1',
        name: 'R',
        guidelines: 'Be brief',
        sources: ['task', 'form'],
        submissions_from: from,
        submissions_to: null,
      }),
    );
    expect(cfg).toEqual({
      form_id: 'f1',
      name: 'R',
      guidelines: 'Be brief',
      sources: ['task', 'form'],
      submissions_from: from,
    });
    expect('submissions_to' in cfg).toBe(false);
  });
});

// --- key suggestion ---------------------------------------------------------

describe('suggestStepKey — unique key suggestion', () => {
  it('uses the type base when free', () => {
    expect(suggestStepKey('create_task', [])).toBe('task');
    expect(suggestStepKey('create_form_report', [])).toBe('report');
  });

  it('appends _2, _3 … until unique', () => {
    expect(suggestStepKey('create_task', ['task'])).toBe('task_2');
    expect(suggestStepKey('create_task', ['task', 'task_2'])).toBe('task_3');
  });

  it('ignores empty existing keys', () => {
    expect(suggestStepKey('create_task', ['', '  '])).toBe('task');
  });
});

// --- list invariants --------------------------------------------------------

describe('step list invariants — add / remove / reorder', () => {
  function steps(): StepDraft[] {
    return [
      { uid: 's1', type: 'create_task', key: 'task', config: {} },
      { uid: 's2', type: 'create_form_report', key: 'report', config: {} },
      { uid: 's3', type: 'create_task', key: 'followup', config: {} },
    ];
  }

  it('makeStepDraft yields a correctly-shaped card with a unique key', () => {
    const d = makeStepDraft('create_task', ['task']);
    expect(d.type).toBe('create_task');
    expect(d.key).toBe('task_2');
    expect(Object.keys(d.config)).toContain('title');
    expect(Object.keys(d.config)).toContain('assignee_type');
    expect(d.uid).toMatch(/^step-/);
  });

  it('moveStep swaps neighbours and is a no-op at the edges', () => {
    const list = steps();
    expect(moveStep(list, 0, 1).map((s) => s.uid)).toEqual(['s2', 's1', 's3']);
    expect(moveStep(list, 2, 1)).toBe(list); // down at the bottom → same ref
    expect(moveStep(list, 0, -1)).toBe(list); // up at the top → same ref
  });

  it('removeStep drops a row but never below one', () => {
    const list = steps();
    expect(removeStep(list, 1).map((s) => s.uid)).toEqual(['s1', 's3']);
    const single: StepDraft[] = [{ uid: 'only', type: 'create_task', key: 'task', config: {} }];
    expect(removeStep(single, 0)).toBe(single); // min 1 enforced
  });

  it('duplicateKeyUids flags BOTH offending cards, ignoring empties', () => {
    const list: StepDraft[] = [
      { uid: 'a', type: 'create_task', key: 'dup', config: {} },
      { uid: 'b', type: 'create_form_report', key: 'dup', config: {} },
      { uid: 'c', type: 'create_task', key: 'unique', config: {} },
      { uid: 'd', type: 'create_task', key: '', config: {} },
    ];
    const dupes = duplicateKeyUids(list);
    expect(dupes.has('a')).toBe(true);
    expect(dupes.has('b')).toBe(true);
    expect(dupes.has('c')).toBe(false);
    expect(dupes.has('d')).toBe(false);
  });
});

// --- form trigger draft -----------------------------------------------------

describe('emptyFormTriggerDraft — the fresh form_submitted draft', () => {
  it('is any form / any source / any anonymity', () => {
    expect(emptyFormTriggerDraft()).toEqual({ form_id: null, source: [], anonymous: null });
  });
});

// --- typed condition sanitation (§4.8) --------------------------------------

describe('isConditionComplete — the row-completeness gate', () => {
  function cond(overrides: Partial<WorkflowCondition>): WorkflowCondition {
    return { field: 'fields.a', field_type: 'text', operator: 'equals', value: 'x', ...overrides };
  }

  it('rejects rows missing a field / field_type / operator', () => {
    expect(isConditionComplete(cond({ field: '' }))).toBe(false);
    expect(isConditionComplete(cond({ field: '   ' }))).toBe(false);
    expect(isConditionComplete(cond({ field_type: undefined as never }))).toBe(false);
    expect(isConditionComplete(cond({ operator: undefined as never }))).toBe(false);
  });

  it('value-less booleans are complete without a value', () => {
    expect(isConditionComplete(cond({ field_type: 'boolean', operator: 'is_true', value: undefined }))).toBe(true);
    expect(isConditionComplete(cond({ field_type: 'boolean', operator: 'is_false', value: undefined }))).toBe(true);
  });

  it('between needs a two-entry non-empty pair', () => {
    expect(isConditionComplete(cond({ field_type: 'date', operator: 'between', value: ['2026-01-01', '2026-02-01'] }))).toBe(true);
    expect(isConditionComplete(cond({ field_type: 'date', operator: 'between', value: ['2026-01-01'] }))).toBe(false);
    expect(isConditionComplete(cond({ field_type: 'date', operator: 'between', value: ['2026-01-01', ''] }))).toBe(false);
  });

  it('in needs a non-empty array', () => {
    expect(isConditionComplete(cond({ field_type: 'enum', operator: 'in', value: ['a'] }))).toBe(true);
    expect(isConditionComplete(cond({ field_type: 'enum', operator: 'in', value: [] }))).toBe(false);
  });

  it('scalar operators need a present value', () => {
    expect(isConditionComplete(cond({ value: 'done' }))).toBe(true);
    expect(isConditionComplete(cond({ value: '' }))).toBe(false);
    expect(isConditionComplete(cond({ value: null as never }))).toBe(false);
  });
});

describe('sanitizeConditions — drops incomplete rows, trims field paths', () => {
  it('keeps complete rows (trimmed) and drops the rest', () => {
    const rows: WorkflowCondition[] = [
      { field: ' fields.status ', field_type: 'text', operator: 'equals', value: 'done' },
      { field: '', field_type: 'text', operator: 'equals', value: 'noise' },
      { field: 'fields.count', field_type: 'number', operator: 'gt', value: undefined },
    ];
    const clean = sanitizeConditions(rows);
    expect(clean).toEqual([
      { field: 'fields.status', field_type: 'text', operator: 'equals', value: 'done' },
    ]);
  });

  it('an all-incomplete list collapses to [] ("always runs")', () => {
    expect(sanitizeConditions([{ field: '', field_type: 'text', operator: 'equals', value: '' }])).toEqual([]);
  });
});
