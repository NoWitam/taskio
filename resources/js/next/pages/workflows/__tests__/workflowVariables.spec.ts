// Unit tests for the PURE catalog→editor adapter (B7a, §4.7, §8.4 REQUIREMENT).
// The drift-critical logic is the KEY substitution (steps.<TYPE>.* → steps.<key>.*),
// the POSITION scoping (a field sees the trigger + EARLIER steps only), and the
// editor-primitive DEGRADE rule (date/enum/multi → text inside a directive). These
// are guarded WITHOUT mounting an editor.
import { describe, it, expect } from 'vitest';
import {
  editorPrimitive,
  isIdVariable,
  positionScopedStepOutputs,
  resolveVariable,
  resolveVariableDescriptor,
  resolveVariableType,
  stripVariableDirectives,
  toEditorVariables,
  toEditorVariablesTyped,
  triggerSystemVariables,
  variableIcon,
  variableOptionList,
  variablesOfType,
  type StepLike,
} from '../workflowVariables';
import type { CatalogVariable, WorkflowCatalog, WorkflowVariableType } from '../types';

/** Build a `@[variable]("<escaped-json>")` directive the way the editor serializes it. */
function variableDirective(data: { id: string; name: string; type?: string }): string {
  const payload = JSON.stringify({ v: 1, data: { locked: false, pipeline: [], resultType: 'text', type: 'text', ...data } });
  return `@[variable]("${payload.replace(/"/g, '\\"')}")`;
}

// The live catalog is now FORM-INDEPENDENT (WorkflowVariableCatalogService::forContext):
// trigger-system vars (source 'trigger', by trigger type) + per-form field vars (when a
// form_id is layered in) + the TEMPLATE step outputs (keyed by TYPE, for EVERY step type).
// The adapter drops the template step outputs and replaces them with live, KEY-substituted
// ones from the editor's step list; trigger-system vars now come straight from the catalog
// (the static FE mirrors were deleted). CATALOG mirrors a form_submitted catalog WITH a form.
const CATALOG: WorkflowCatalog = {
  variables: [
    // trigger-system vars (form_submitted) — the FULL set the backend emits, incl. the ids.
    { source: 'trigger', path: 'trigger.submission.id', name: 'Submission ID', type: 'text' },
    { source: 'trigger', path: 'trigger.form.id', name: 'Form ID', type: 'text' },
    { source: 'trigger', path: 'trigger.form.name', name: 'Form name', type: 'text' },
    { source: 'trigger', path: 'trigger.source', name: 'Source', type: 'enum', enumOptions: ['manual', 'task'] },
    { source: 'trigger', path: 'trigger.submitted_at', name: 'Submitted at', type: 'date' },
    { source: 'trigger', path: 'trigger.task.id', name: 'Task ID', type: 'text', nullable: true },
    // per-form field vars.
    { source: 'trigger', path: 'trigger.fields.age', name: 'Age', type: 'number' },
    { source: 'trigger', path: 'trigger.fields.tags', name: 'Tags', type: 'multi', enumOptions: ['a', 'b'] },
    // template step outputs (keyed by TYPE — the adapter must DROP these). A form-independent
    // catalog carries EVERY step type's templates regardless of the workflow's steps.
    { source: 'steps', path: 'steps.create_task.task_id', name: 'Create task · task_id', type: 'text' },
    { source: 'steps', path: 'steps.create_task.title', name: 'Create task · title', type: 'text' },
    { source: 'steps', path: 'steps.create_form_report.report_id', name: 'Create report · report_id', type: 'text' },
    { source: 'steps', path: 'steps.create_form_report.report_name', name: 'Create report · report_name', type: 'text' },
  ],
  fields: [
    { path: 'fields.age', field_id: 'age', label: 'Age', type: 'number', operators: ['eq', 'neq', 'gt'] },
  ],
};

// A form-LESS SCHEDULE catalog (WorkflowVariableCatalogService::forContext(SCHEDULE, null)):
// the schedule trigger-system var + EVERY step type's output templates, no form fields. This
// is what a schedule workflow's editor now fetches (it previously had NO catalog).
const SCHEDULE_CATALOG: WorkflowCatalog = {
  variables: [
    { source: 'trigger', path: 'trigger.scheduled_at', name: 'Scheduled at', type: 'date' },
    { source: 'steps', path: 'steps.create_task.task_id', name: 'Create task · task_id', type: 'text' },
    { source: 'steps', path: 'steps.create_task.title', name: 'Create task · title', type: 'text' },
    { source: 'steps', path: 'steps.create_form_report.report_id', name: 'Create report · report_id', type: 'text' },
    { source: 'steps', path: 'steps.create_form_report.report_name', name: 'Create report · report_name', type: 'text' },
  ],
  fields: [],
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

  it('works with a form-less SCHEDULE catalog — trigger var + step outputs (no fields)', () => {
    // SF3.2: the `_id` outputs are filtered out of the OFFERED list.
    const vars = toEditorVariables(SCHEDULE_CATALOG, STEPS, 2);
    expect(vars.map((v) => v.id)).toEqual([
      'trigger.scheduled_at',
      'steps.make.title',
      'steps.report.report_name',
    ]);
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
    // A trigger SYSTEM id resolves from the catalog (it carries the id vars for resolution).
    expect(resolveVariableType('trigger.form.id', CATALOG, STEPS)).toBe('text');
    // A step-output id resolves too (from the catalog's step templates).
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

describe('triggerSystemVariables — the OFFERED system vars, from the live catalog (SF2)', () => {
  it('schedule catalog exposes ONLY trigger.scheduled_at (date)', () => {
    const vars = triggerSystemVariables(SCHEDULE_CATALOG);
    expect(vars.map((v) => v.path)).toEqual(['trigger.scheduled_at']);
    expect(vars[0].type).toBe('date');
  });

  it('form_submitted catalog OFFERS the non-id system vars, but NOT the identifiers (SF3.2) or fields', () => {
    const paths = triggerSystemVariables(CATALOG).map((v) => v.path);
    expect(paths).toContain('trigger.form.name');
    expect(paths).toContain('trigger.source');
    expect(paths).toContain('trigger.submitted_at');
    // The identifiers are stripped from the OFFERED list.
    expect(paths).not.toContain('trigger.submission.id');
    expect(paths).not.toContain('trigger.form.id');
    expect(paths).not.toContain('trigger.task.id');
    // Form FIELD vars are not "system" vars.
    expect(paths).not.toContain('trigger.fields.age');
  });

  it('returns [] for a null / empty catalog', () => {
    expect(triggerSystemVariables(null)).toEqual([]);
    expect(triggerSystemVariables(undefined)).toEqual([]);
  });
});

describe('toEditorVariables — the live catalog carries the trigger system vars (SF2)', () => {
  it('schedule catalog offers trigger.scheduled_at (date → text) + step outputs', () => {
    const vars = toEditorVariables(SCHEDULE_CATALOG, STEPS, 2);
    const scheduled = vars.find((v) => v.id === 'trigger.scheduled_at');
    // date degrades to the text primitive; id === path (identity-only).
    expect(scheduled).toEqual({ id: 'trigger.scheduled_at', name: 'Scheduled at', type: 'text' });
    // The position-scoped step outputs are still present (the non-id ones, SF3.2).
    expect(vars.some((v) => v.id === 'steps.make.title')).toBe(true);
  });

  it('never duplicates a system var (the catalog is the single source)', () => {
    const vars = toEditorVariables(CATALOG, STEPS, 0);
    expect(vars.filter((v) => v.id === 'trigger.submitted_at')).toHaveLength(1);
    expect(vars.filter((v) => v.id === 'trigger.source')).toHaveLength(1);
  });

  it('offers nothing (no trigger vars, no step outputs) for a null catalog', () => {
    const vars = toEditorVariables(null, STEPS, 2);
    expect(vars.some((v) => v.id.startsWith('trigger.'))).toBe(false);
    // No catalog → no step-output templates → no step outputs either.
    expect(vars.some((v) => v.id.startsWith('steps.'))).toBe(false);
  });
});

describe('variablesOfType — trigger system vars from the schedule catalog (SF2)', () => {
  it('offers the schedule system date var from a form-less schedule catalog', () => {
    const dates = variablesOfType(SCHEDULE_CATALOG, STEPS, 3, 'date');
    expect(dates.map((v) => v.path)).toContain('trigger.scheduled_at');
  });
});

describe('resolveVariableType / resolveVariable — the by-path type recovery', () => {
  it('recovers a trigger SYSTEM var type from the catalog (SF2)', () => {
    expect(resolveVariableType('trigger.scheduled_at', SCHEDULE_CATALOG, STEPS)).toBe('date');
    // A path the catalog does not carry is unknown (CATALOG is form_submitted — no scheduled_at).
    expect(resolveVariableType('trigger.scheduled_at', CATALOG, STEPS)).toBeNull();
    // resolveVariable returns the full descriptor too.
    expect(resolveVariable('trigger.scheduled_at', SCHEDULE_CATALOG, STEPS)?.name).toBe('Scheduled at');
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

describe('descriptor-based enum labels (phase-1a)', () => {
  // A catalog whose enum/multi vars ALSO carry the structured descriptor with REAL human
  // labels (DISTINCT from the wire keys). Older fixtures omit the descriptor → the code
  // falls back to `enumOptions` (label = value).
  const DESCRIPTOR_CATALOG: WorkflowCatalog = {
    variables: [
      {
        source: 'trigger',
        path: 'trigger.fields.status',
        name: 'Status',
        type: 'enum',
        enumOptions: ['open', 'done'],
        descriptor: {
          base: 'enum',
          nullable: false,
          array: false,
          options: [
            { key: 'open', label: 'Open ticket' },
            { key: 'done', label: 'Resolved' },
          ],
        },
      },
      {
        source: 'trigger',
        path: 'trigger.fields.tags',
        name: 'Tags',
        type: 'multi',
        enumOptions: ['a', 'b'],
        descriptor: {
          base: 'enum',
          nullable: false,
          array: true,
          options: [
            { key: 'a', label: 'Alpha' },
            { key: 'b', label: 'Beta' },
          ],
        },
      },
      // No descriptor → falls back to enumOptions (label = value).
      { source: 'trigger', path: 'trigger.source', name: 'Source', type: 'enum', enumOptions: ['manual', 'task'] },
    ],
    fields: [],
  };

  it('variableOptionList prefers descriptor {key,label} — human label, key stays the wire value', () => {
    expect(variableOptionList(DESCRIPTOR_CATALOG.variables[0])).toEqual([
      { label: 'Open ticket', value: 'open' },
      { label: 'Resolved', value: 'done' },
    ]);
  });

  it('variableOptionList falls back to enumOptions (label = value) when no descriptor', () => {
    expect(variableOptionList(DESCRIPTOR_CATALOG.variables[2])).toEqual([
      { label: 'manual', value: 'manual' },
      { label: 'task', value: 'task' },
    ]);
  });

  it('variableOptionList returns undefined for a non-choice variable', () => {
    expect(
      variableOptionList({ source: 'trigger', path: 'trigger.fields.age', name: 'Age', type: 'number' }),
    ).toBeUndefined();
  });

  it('toEditorVariablesTyped surfaces the HUMAN labels (≠ their values) from the descriptor', () => {
    const vars = toEditorVariablesTyped(DESCRIPTOR_CATALOG, [], 0);
    expect(vars.find((v) => v.id === 'trigger.fields.status')?.options).toEqual([
      { label: 'Open ticket', value: 'open' },
      { label: 'Resolved', value: 'done' },
    ]);
    expect(vars.find((v) => v.id === 'trigger.fields.tags')?.options).toEqual([
      { label: 'Alpha', value: 'a' },
      { label: 'Beta', value: 'b' },
    ]);
    // The descriptor-less var still carries label = value (fallback preserved).
    expect(vars.find((v) => v.id === 'trigger.source')?.options).toEqual([
      { label: 'manual', value: 'manual' },
      { label: 'task', value: 'task' },
    ]);
  });

  it('resolveVariableDescriptor recovers the descriptor by path (null when absent / unknown)', () => {
    expect(resolveVariableDescriptor('trigger.fields.status', DESCRIPTOR_CATALOG, [])).toMatchObject({
      base: 'enum',
      array: false,
      options: [
        { key: 'open', label: 'Open ticket' },
        { key: 'done', label: 'Resolved' },
      ],
    });
    // A var carrying no descriptor → null.
    expect(resolveVariableDescriptor('trigger.source', DESCRIPTOR_CATALOG, [])).toBeNull();
    // Unknown path → null.
    expect(resolveVariableDescriptor('trigger.ghost', DESCRIPTOR_CATALOG, [])).toBeNull();
  });

  it('a `time` descriptor base rides on a flat text type (descriptor-only; primitive intact)', () => {
    const timeCatalog: WorkflowCatalog = {
      variables: [
        {
          source: 'trigger',
          path: 'trigger.fields.slot',
          name: 'Slot',
          type: 'text', // the flat wire type degrades time → text
          descriptor: { base: 'time', nullable: false, array: false },
        },
      ],
      fields: [],
    };
    expect(resolveVariableDescriptor('trigger.fields.slot', timeCatalog, [])?.base).toBe('time');
    // resolveVariableType is UNCHANGED — still the flat text type.
    expect(resolveVariableType('trigger.fields.slot', timeCatalog, [])).toBe('text');
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

describe('PARITY — the live catalog covers the deleted static mirrors', () => {
  // FROZEN snapshots of the two mirrors DELETED from workflowVariables.ts (STEP_OUTPUTS +
  // TRIGGER_SYSTEM_VARIABLES). This block is the "verify parity" gate: it proves the backend
  // FORM-INDEPENDENT catalog (SCHEDULE_CATALOG / CATALOG fixtures) reproduces EXACTLY what
  // those hardcoded arrays provided — both the OFFERED set and the resolve-side ids. If the
  // backend ever changes a trigger-system var or a step output, the fixtures drift from these
  // frozen values and this fails, flagging the change.
  const FROZEN_STEP_OUTPUTS: Record<string, Array<{ name: string; type: WorkflowVariableType }>> = {
    create_task: [
      { name: 'task_id', type: 'text' },
      { name: 'title', type: 'text' },
    ],
    create_form_report: [
      { name: 'report_id', type: 'text' },
      { name: 'report_name', type: 'text' },
    ],
  };
  const FROZEN_TRIGGER_SYSTEM: Record<'schedule' | 'form_submitted', CatalogVariable[]> = {
    schedule: [
      { source: 'trigger', path: 'trigger.scheduled_at', name: 'Scheduled at', type: 'date' },
    ],
    form_submitted: [
      { source: 'trigger', path: 'trigger.submission.id', name: 'Submission ID', type: 'text' },
      { source: 'trigger', path: 'trigger.form.id', name: 'Form ID', type: 'text' },
      { source: 'trigger', path: 'trigger.form.name', name: 'Form name', type: 'text' },
      { source: 'trigger', path: 'trigger.source', name: 'Source', type: 'enum', enumOptions: ['manual', 'task'] },
      { source: 'trigger', path: 'trigger.submitted_at', name: 'Submitted at', type: 'date' },
      { source: 'trigger', path: 'trigger.task.id', name: 'Task ID', type: 'text', nullable: true },
    ],
  };

  /** Reproduce the OLD mirror's key-substituted step outputs for a steps list. */
  function mirrorStepOutputs(steps: StepLike[]): CatalogVariable[] {
    const out: CatalogVariable[] = [];
    for (const step of steps) {
      const key = step.key.trim();
      if (!key) continue;
      for (const o of FROZEN_STEP_OUTPUTS[step.type] ?? []) {
        out.push({ source: 'steps', path: `steps.${key}.${o.name}`, name: `${key}.${o.name}`, type: o.type });
      }
    }
    return out;
  }

  it('step outputs: catalog derivation === the deleted STEP_OUTPUTS mirror (key-substituted)', () => {
    // Full position (all steps) — the raw derivation, before the OFFERED id-filter. Both the
    // schedule and the form catalog carry every step type's templates, so both reproduce it.
    expect(positionScopedStepOutputs(SCHEDULE_CATALOG, STEPS, STEPS.length)).toEqual(mirrorStepOutputs(STEPS));
    expect(positionScopedStepOutputs(CATALOG, STEPS, STEPS.length)).toEqual(mirrorStepOutputs(STEPS));
  });

  it('trigger-system vars: the schedule catalog OFFERS exactly the (non-id) schedule mirror', () => {
    const offered = FROZEN_TRIGGER_SYSTEM.schedule.filter((v) => !isIdVariable(v.path));
    expect(triggerSystemVariables(SCHEDULE_CATALOG)).toEqual(offered);
  });

  it('trigger-system vars: the form_submitted catalog OFFERS exactly the (non-id) form mirror', () => {
    const offered = FROZEN_TRIGGER_SYSTEM.form_submitted.filter((v) => !isIdVariable(v.path));
    expect(triggerSystemVariables(CATALOG)).toEqual(offered);
  });

  it('the ids the mirror carried are OFFERED nowhere but still RESOLVE (SF3.2 resolve-side)', () => {
    for (const v of FROZEN_TRIGGER_SYSTEM.form_submitted) {
      expect(resolveVariableType(v.path, CATALOG, STEPS)).toBe(v.type);
    }
    expect(resolveVariableType('trigger.scheduled_at', SCHEDULE_CATALOG, STEPS)).toBe('date');
    // Step-output ids (task_id / report_id) resolve too.
    expect(resolveVariableType('steps.make.task_id', CATALOG, STEPS)).toBe('text');
    expect(resolveVariableType('steps.report.report_id', CATALOG, STEPS)).toBe('text');
  });
});
