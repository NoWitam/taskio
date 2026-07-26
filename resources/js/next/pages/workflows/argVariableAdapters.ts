// argVariableAdapters — the RAW pipeline-argument ⇄ `WorkflowFieldValue` union mapping (phase-4b).
//
// An operation argument is stored EITHER as a bare literal (as it always was) OR as the same
// `{kind:'variable', ref, pipeline?, default?}` union a value-or-variable field emits. The
// value-or-variable field edits the UNION, the pipeline stores the RAW value — these two pure
// functions are that translation, and the reason a LITERAL argument still serializes
// BYTE-IDENTICALLY (no `{kind}` wrapper ever reaches the wire).
//
// Extracted from `ValueOrVariableField.vue` (B4) because a SECOND host now needs the exact same
// mapping: `WorkflowArgVariableField.vue`, the control this page injects into the markdown chip
// panel's pipeline. One copy, so an arg can never round-trip differently depending on which
// surface edited it.
import type { WorkflowFieldValue } from './types';
import type {
  ArgVariableValue,
  ChoiceRule,
  VariableArgValue,
  VariableOperationArgumentDefinition,
} from '../../ui/editor/extensions/types';

/** Whether a raw arg value is a variable union rather than a literal. */
export function isArgVariable(value: unknown): value is ArgVariableValue {
  return (
    !!value &&
    typeof value === 'object' &&
    !Array.isArray(value) &&
    (value as { kind?: string }).kind === 'variable'
  );
}

/**
 * The empty LITERAL for an argument toggled back to VALUE mode. Matches `buildDefaultArgs` so the
 * argument stays valid for its control (a number arg is `0`, a rules arg an empty list, …).
 */
export function emptyArgLiteral(arg: VariableOperationArgumentDefinition): VariableArgValue {
  switch (arg.type) {
    case 'number':
      return 0;
    case 'boolean':
      return false;
    case 'sourceOptions':
      return [] as string[];
    case 'choiceRules':
      return [] as ChoiceRule[];
    case 'sourceMap':
      return {} as Record<string, string | number>;
    default: // text | date | select | sourceOption | choiceFallback
      return '';
  }
}

/** Project a raw arg value onto the field union the value-or-variable field edits. */
export function argToUnion(raw: VariableArgValue | undefined): WorkflowFieldValue | null {
  if (isArgVariable(raw)) return raw as unknown as WorkflowFieldValue;
  return { kind: 'literal', value: raw ?? null };
}

/**
 * Project that union back onto raw arg storage. A variable arm passes straight through
 * (`{kind:'variable', …}`); a literal is UNWRAPPED to its bare value (empty ⇒ the arg's typed
 * empty), so a literal arg is byte-identical to today.
 */
export function unionToArg(
  union: WorkflowFieldValue | null,
  arg: VariableOperationArgumentDefinition,
): VariableArgValue {
  if (union?.kind === 'variable') return union as unknown as ArgVariableValue;
  const value = union?.kind === 'literal' ? union.value : null;
  return (value ?? emptyArgLiteral(arg)) as VariableArgValue;
}
