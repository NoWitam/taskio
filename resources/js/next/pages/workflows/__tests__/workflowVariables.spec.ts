// Unit tests for the PURE catalog→editor adapter (B7a, §4.7, §8.4 REQUIREMENT).
// The drift-critical logic is the KEY substitution (steps.<TYPE>.* → steps.<key>.*),
// the POSITION scoping (a field sees the trigger + EARLIER steps only), and the
// editor-primitive DEGRADE rule (date/enum/multi → text inside a directive). These
// are guarded WITHOUT mounting an editor.
import { describe, it, expect } from 'vitest';
import {
  editorPrimitive,
  isIdVariable,
  resolveVariable,
  resolveVariableType,
  stripVariableDirectives,
  toEditorVariables,
  toEditorVariablesTyped,
  triggerSystemVariables,
  variableIcon,
  variablesOfType,
  type StepLike,
} from '../workflowVariables';
import type { WorkflowCatalog } from '../types';

/** Build a `@[variable]("<escaped-json>")` directive the way the editor serializes it. */
function variableDirective(data: { id: string; name: string; type?: string }): string {
  const payload = JSON.stringify({ v: 1, data: { locked: false, pipeline: [], resultType: 'text', type: 'text', ...data } });
  return `@[variable]("${payload.replace(/"/g, '\\"')}")`;
}

// A catalog like WorkflowVariableCatalogService::forForm returns: trigger system
// vars + per-form field vars + the TEMPLATE step outputs (keyed by TYPE). The
// adapter drops the template step outputs and replaces them with live, KEY-
// substituted ones from the editor's step list.
const CATALOG: WorkflowCatalog = {
  variables: [
    { source: 'trigger', path: 'trigger.submission.id', name: 'Submission ID', type: 'text' },
    { source: 'trigger', path: 'trigger.submitted_at', name: 'Submitted at', type: 'date' },
    { source: 'trigger', path: 'trigger.source', name: 'Source', type: 'enum', enumOptions: ['manual', 'task'] },
    { source: 'trigger', path: 'trigger.fields.age', name: 'Age', type: 'number' },
    { source: 'trigger', path: 'trigger.fields.tags', name: 'Tags', type: 'multi', enumOptions: ['a', 'b'] },
    // template step outputs (keyed by TYPE — the adapter must DROP these).
    { source: 'steps', path: 'steps.create_task.task_id', name: 'Create task · task_id', type: 'text' },
    { source: 'steps', path: 'steps.create_task.title', name: 'Create task · title', type: 'text' },
  ],
  fields: [
    { path: 'fields.age', field_id: 'age', label: 'Age', type: 'number', operators: ['eq', 'neq', 'gt'] },
  ],
};

const STEPS: StepLike[] = [
  { type: 'create_task', key: 'make' },
  { type: 'create_form_report', key: 'report' },
  { type: 'create_task', key: 'followup' },
];

describe('editorPrimitive — the degrade rule', () => {
  it('number → number, boolean → boolean, everything else → text', () => {
    expect(editorPrimitive('number')).toBe('number');
    expect(editorPrimitive('boolean')).toBe('boolean');
    expect(editorPrimitive('text')).toBe('text');
    expect(editorPrimitive('date')).toBe('text');
    expect(editorPrimitive('enum')).toBe('text');
    expect(editorPrimitive('multi')).toBe('text');
  });
});

describe('toEditorVariables — system/field vars + position-scoped KEY-substituted outputs', () => {
  it('includes system + field vars as id=path with the editor PRIMITIVE type', () => {
    const vars = toEditorVariables(CATALOG, STEPS, 0);
    const submitted = vars.find((v) => v.id === 'trigger.submitted_at');
    // date degrades to the text primitive; id === path (identity-only).
    expect(submitted).toEqual({ id: 'trigger.submitted_at', name: 'Submitted at', type: 'text' });
    expect(vars.find((v) => v.id === 'trigger.fields.age')?.type).toBe('number');
    expect(vars.find((v) => v.id === 'trigger.source')?.type).toBe('text'); // enum → text
  });

  it('DROPS the catalog template step outputs (steps.<TYPE>.*)', () => {
    const vars = toEditorVariables(CATALOG, STEPS, 3);
    expect(vars.some((v) => v.id === 'steps.create_task.task_id')).toBe(false);
    expect(vars.some((v) => v.id === 'steps.create_task.title')).toBe(false);
  });

  it('the FIRST step (position 0) sees NO step outputs (trigger + fields only)', () => {
    const vars = toEditorVariables(CATALOG, STEPS, 0);
    expect(vars.some((v) => v.id.startsWith('steps.'))).toBe(false);
  });

  it('a later step sees EARLIER steps only, with the real KEY substituted', () => {
    // Field on step index 2 (`followup`) → outputs of `make` + `report` only.
    // SF3.2: the `_id` outputs (task_id / report_id) are NOT offered.
    const vars = toEditorVariables(CATALOG, STEPS, 2);
    const stepIds = vars.filter((v) => v.id.startsWith('steps.')).map((v) => v.id);
    expect(stepIds).toEqual(['steps.make.title', 'steps.report.report_name']);
    // NOT its own outputs, NOT later steps.
    expect(stepIds.some((id) => id.startsWith('steps.followup.'))).toBe(false);
  });

  it('a keyless earlier step contributes NO outputs yet', () => {
    const steps: StepLike[] = [
      { type: 'create_task', key: '' },
      { type: 'create_task', key: 'second' },
    ];
    const vars = toEditorVariables(CATALOG, steps, 1);
    const stepIds = vars.filter((v) => v.id.startsWith('steps.')).map((v) => v.id);
    expect(stepIds).toEqual([]); // the keyless first step yields nothing
  });

  it('works with a null catalog (schedule trigger) — step outputs only', () => {
    // SF3.2: the `_id` outputs are filtered out of the OFFERED list.
    const vars = toEditorVariables(null, STEPS, 2);
    expect(vars.map((v) => v.id)).toEqual(['steps.make.title', 'steps.report.report_name']);
  });
});

describe('isIdVariable — identifier detection (SF3.2)', () => {
  it('flags *.id and *_id paths, spares look-alikes', () => {
    expect(isIdVariable('trigger.submission.id')).toBe(true);
    expect(isIdVariable('trigger.form.id')).toBe(true);
    expect(isIdVariable('trigger.task.id')).toBe(true);
    expect(isIdVariable('steps.make.task_id')).toBe(true);
    expect(isIdVariable('steps.report.report_id')).toBe(true);
    // Non-identifiers: a date, a name, and words that merely END in "id".
    expect(isIdVariable('trigger.submitted_at')).toBe(false);
    expect(isIdVariable('trigger.form.name')).toBe(false);
    expect(isIdVariable('fields.valid')).toBe(false);
  });
});

describe('SF3.2 — identifiers are OFFERED nowhere, but still RESOLVE', () => {
  it('toEditorVariables(Typed) never offer an id variable', () => {
    const ids = toEditorVariables(CATALOG, STEPS, 3, 'form_submitted').map((v) => v.id);
    expect(ids).not.toContain('trigger.submission.id');
    expect(ids.some((id) => isIdVariable(id))).toBe(false);
    const typed = toEditorVariablesTyped(CATALOG, STEPS, 3, 'form_submitted').map((v) => v.id);
    expect(typed.some((id) => isIdVariable(id))).toBe(false);
  });

  it('variablesOfType never offers an id variable', () => {
    const text = variablesOfType(CATALOG, STEPS, 3, 'text').map((v) => v.path);
    expect(text).not.toContain('trigger.submission.id');
    expect(text.some((p) => isIdVariable(p))).toBe(false);
  });

  it('a SAVED id ref still resolves its type (resolving is NOT filtered)', () => {
    // The catalog carries trigger.submission.id; resolution recovers it.
    expect(resolveVariableType('trigger.submission.id', CATALOG, STEPS)).toBe('text');
    // A trigger SYSTEM id (null catalog) resolves from the RAW mirror by trigger type.
    expect(resolveVariableType('trigger.form.id', null, STEPS, 'form_submitted')).toBe('text');
    // A step-output id resolves too.
    expect(resolveVariableType('steps.make.task_id', CATALOG, STEPS)).toBe('text');
  });
});

describe('toEditorVariablesTyped — TRUE type + enum options (SF1)', () => {
  it('carries the REAL workflow type, not the degraded editor primitive', () => {
    const vars = toEditorVariablesTyped(CATALOG, STEPS, 0);
    // date/enum/multi survive (the primitive feed would flatten them to text).
    expect(vars.find((v) => v.id === 'trigger.submitted_at')?.type).toBe('date');
    expect(vars.find((v) => v.id === 'trigger.source')?.type).toBe('enum');
    expect(vars.find((v) => v.id === 'trigger.fields.tags')?.type).toBe('multi');
    expect(vars.find((v) => v.id === 'trigger.fields.age')?.type).toBe('number');
  });

  it('attaches enum/multi options (label = value) and omits options for non-choice types', () => {
    const vars = toEditorVariablesTyped(CATALOG, STEPS, 0);
    expect(vars.find((v) => v.id === 'trigger.source')?.options).toEqual([
      { label: 'manual', value: 'manual' },
      { label: 'task', value: 'task' },
    ]);
    expect(vars.find((v) => v.id === 'trigger.fields.tags')?.options).toEqual([
      { label: 'a', value: 'a' },
      { label: 'b', value: 'b' },
    ]);
    // A plain date/number carries no `options` key.
    expect('options' in (vars.find((v) => v.id === 'trigger.submitted_at') ?? {})).toBe(false);
    expect('options' in (vars.find((v) => v.id === 'trigger.fields.age') ?? {})).toBe(false);
  });

  it('stays identity-only (id = path) with position-scoped KEY-substituted step outputs', () => {
    // SF3.2: the `_id` outputs are filtered out; the named outputs remain.
    const vars = toEditorVariablesTyped(CATALOG, STEPS, 2);
    const stepIds = vars.filter((v) => v.id.startsWith('steps.')).map((v) => v.id);
    expect(stepIds).toEqual(['steps.make.title', 'steps.report.report_name']);
    // Drops the catalog's template step outputs (steps.<TYPE>.*), like the primitive feed.
    expect(vars.some((v) => v.id === 'steps.create_task.task_id')).toBe(false);
  });
});

describe('triggerSystemVariables — the backend mirror (SF2)', () => {
  it('schedule exposes ONLY trigger.scheduled_at (date)', () => {
    const vars = triggerSystemVariables('schedule');
    expect(vars.map((v) => v.path)).toEqual(['trigger.scheduled_at']);
    expect(vars[0].type).toBe('date');
  });

  it('form_submitted OFFERS the non-id system vars, but NOT the identifiers (SF3.2)', () => {
    const paths = triggerSystemVariables('form_submitted').map((v) => v.path);
    expect(paths).toContain('trigger.form.name');
    expect(paths).toContain('trigger.source');
    expect(paths).toContain('trigger.submitted_at');
    // The identifiers are stripped from the OFFERED list.
    expect(paths).not.toContain('trigger.submission.id');
    expect(paths).not.toContain('trigger.form.id');
    expect(paths).not.toContain('trigger.task.id');
  });

  it('returns [] for a null / unknown trigger type', () => {
    expect(triggerSystemVariables(null)).toEqual([]);
    expect(triggerSystemVariables(undefined)).toEqual([]);
  });
});

describe('toEditorVariables — trigger system vars supplement a null catalog (SF2)', () => {
  it('schedule (null catalog) offers trigger.scheduled_at (date → text) + step outputs', () => {
    const vars = toEditorVariables(null, STEPS, 2, 'schedule');
    const scheduled = vars.find((v) => v.id === 'trigger.scheduled_at');
    // date degrades to the text primitive; id === path (identity-only).
    expect(scheduled).toEqual({ id: 'trigger.scheduled_at', name: 'Scheduled at', type: 'text' });
    // The position-scoped step outputs are still present (the non-id ones, SF3.2).
    expect(vars.some((v) => v.id === 'steps.make.title')).toBe(true);
  });

  it('does NOT duplicate a system var already carried by the catalog', () => {
    // CATALOG already has trigger.submitted_at + trigger.source; the mirror must not double them.
    const vars = toEditorVariables(CATALOG, STEPS, 0, 'form_submitted');
    expect(vars.filter((v) => v.id === 'trigger.submitted_at')).toHaveLength(1);
    expect(vars.filter((v) => v.id === 'trigger.source')).toHaveLength(1);
  });

  it('offers no trigger system vars when no trigger type is passed (back-compat)', () => {
    const vars = toEditorVariables(null, STEPS, 2);
    expect(vars.some((v) => v.id.startsWith('trigger.'))).toBe(false);
  });
});

describe('variablesOfType — trigger system vars for a null catalog (SF2)', () => {
  it('offers the schedule system date var when the catalog is null', () => {
    const dates = variablesOfType(null, STEPS, 3, 'date', 'schedule');
    expect(dates.map((v) => v.path)).toContain('trigger.scheduled_at');
  });
});

describe('resolveVariableType / resolveVariable — the by-path type recovery', () => {
  it('recovers a trigger SYSTEM var type by trigger type when the catalog is null (SF2)', () => {
    expect(resolveVariableType('trigger.scheduled_at', null, STEPS, 'schedule')).toBe('date');
    // The wrong trigger type does not expose the path.
    expect(resolveVariableType('trigger.scheduled_at', null, STEPS, 'form_submitted')).toBeNull();
    // resolveVariable returns the full descriptor too.
    expect(resolveVariable('trigger.scheduled_at', null, STEPS, 'schedule')?.name).toBe('Scheduled at');
  });

  it('recovers the TRUE type from the catalog (not the degraded primitive)', () => {
    expect(resolveVariableType('trigger.submitted_at', CATALOG, STEPS)).toBe('date');
    expect(resolveVariableType('trigger.source', CATALOG, STEPS)).toBe('enum');
    expect(resolveVariableType('trigger.fields.tags', CATALOG, STEPS)).toBe('multi');
  });

  it('recovers a KEY-substituted step output type (position-agnostic)', () => {
    expect(resolveVariableType('steps.make.task_id', CATALOG, STEPS)).toBe('text');
    expect(resolveVariableType('steps.report.report_id', CATALOG, STEPS)).toBe('text');
  });

  it('returns null for an unknown / stale path', () => {
    expect(resolveVariableType('steps.ghost.task_id', CATALOG, STEPS)).toBeNull();
    expect(resolveVariableType('trigger.nope', CATALOG, STEPS)).toBeNull();
  });

  it('resolveVariable returns the full descriptor (name + enumOptions)', () => {
    expect(resolveVariable('trigger.source', CATALOG, STEPS)?.enumOptions).toEqual(['manual', 'task']);
    expect(resolveVariable('steps.make.title', CATALOG, STEPS)?.name).toBe('make.title');
  });
});

describe('variablesOfType — the add-on picker filters (§4.9)', () => {
  it('filters to a single accepted type', () => {
    const dates = variablesOfType(CATALOG, STEPS, 3, 'date');
    expect(dates.map((v) => v.path)).toEqual(['trigger.submitted_at']);
  });

  it('accepts a set of types (e.g. enum + text for priority)', () => {
    const priorityVars = variablesOfType(CATALOG, STEPS, 0, ['enum', 'text']);
    const paths = priorityVars.map((v) => v.path);
    expect(paths).toContain('trigger.source'); // enum
    expect(paths).not.toContain('trigger.submission.id'); // id text — never offered (SF3.2)
    expect(paths).not.toContain('trigger.fields.age'); // number excluded
  });

  it('respects position scoping for step outputs', () => {
    // At position 1 only `make`'s outputs are in scope (and the `_id` one is filtered).
    const textAt1 = variablesOfType(CATALOG, STEPS, 1, 'text').map((v) => v.path);
    expect(textAt1).toContain('steps.make.title');
    expect(textAt1).not.toContain('steps.make.task_id'); // id — never offered (SF3.2)
    expect(textAt1).not.toContain('steps.report.report_name');
  });
});

describe('stripVariableDirectives — the read-side echo (§3.2)', () => {
  it('returns plain text unchanged (no directives)', () => {
    expect(stripVariableDirectives('Just a title', CATALOG, STEPS)).toBe('Just a title');
    expect(stripVariableDirectives('', CATALOG, STEPS)).toBe('');
    expect(stripVariableDirectives(null, CATALOG, STEPS)).toBe('');
  });

  it('replaces a variable directive with the CATALOG name (by path), not the raw bytes', () => {
    // The stored directive name is stale ("Old"); the catalog is authoritative.
    const md = `Ticket for ${variableDirective({ id: 'trigger.submission.id', name: 'Old' })}`;
    const out = stripVariableDirectives(md, CATALOG, STEPS);
    expect(out).toBe('Ticket for Submission ID');
    expect(out).not.toContain('@[variable]');
  });

  it('resolves a KEY-substituted step-output path from the catalog+steps', () => {
    const md = variableDirective({ id: 'steps.make.title', name: 'x' });
    expect(stripVariableDirectives(md, CATALOG, STEPS)).toBe('make.title');
  });

  it('falls back to the directive embedded name when the path is unknown / catalog absent', () => {
    const md = variableDirective({ id: 'trigger.ghost', name: 'Ghost var' });
    expect(stripVariableDirectives(md, CATALOG, STEPS)).toBe('Ghost var');
    expect(stripVariableDirectives(md, null, [])).toBe('Ghost var');
  });

  it('strips multiple directives in one string', () => {
    const md = `${variableDirective({ id: 'trigger.submission.id', name: 'a' })} / ${variableDirective({ id: 'trigger.fields.age', name: 'b' })}`;
    expect(stripVariableDirectives(md, CATALOG, STEPS)).toBe('Submission ID / Age');
  });
});

describe('variableIcon — the §7.5 workflow-type → icon map', () => {
  it('maps every type incl. date/enum/multi (which the editor primitive map would lose)', () => {
    expect(variableIcon('text')).toBe('type');
    expect(variableIcon('number')).toBe('hash');
    expect(variableIcon('boolean')).toBe('check-circle');
    expect(variableIcon('date')).toBe('calendar');
    expect(variableIcon('enum')).toBe('list');
    expect(variableIcon('multi')).toBe('list-checks');
  });
});
