// functions — the PURE, testable core of the custom-function editor.
//
// A custom function is a variable TRANSFORM: one INPUT type, typed named ARGS
// ({name, description?, type}), one RETURN type, and a BODY pipeline over {input + args}
// terminating in the return type. This module owns the drift-critical glue the editor +
// row share and the tests exercise directly:
//   • the reserved scope names + the safe-identifier rule (mirrors the backend
//     FunctionDefinitionValidator — the server stays authoritative),
//   • the ARG-draft shape + factory (local ids for stable v-for keys),
//   • `validateArgDrafts()` — the client arg check (required / invalid / reserved / duplicate),
//   • `functionScopeVars()` — the {input, <argName>…} SCOPE-VAR feed the body pipeline editor
//     runs on (as `source:'scope'`, mirroring the element-scope feed — NEVER global variables),
//   • the `fn:<uuid>` op-id helpers (the wire id; the editable NAME is the only user-facing id).
import type { VariableSourceVar } from '../../ui/variables/types';
import type { CustomFunctionArg, WorkflowVariableType } from '../workflows/types';

/**
 * The scope roots a function body already owns — an arg may not shadow them (mirrors
 * FunctionDefinitionValidator::RESERVED_ARG_NAMES). `input` is the body's implicit first frame;
 * `element`/`index` are the array-element scope a nested map/filter/sort/reduce injects.
 */
export const RESERVED_ARG_NAMES = ['input', 'element', 'index'] as const;

/** A safe arg identifier (mirrors the backend SAFE_NAME): a scope path segment read like a variable path. */
export const SAFE_ARG_NAME = /^[a-zA-Z_][a-zA-Z0-9_]{0,62}$/;

export function isSafeArgName(name: string): boolean {
  return SAFE_ARG_NAME.test(name);
}

/** The reserved `fn:` op-id prefix — the wire op id is `fn:<uuid>` (never shown to the user). */
export const FUNCTION_OP_PREFIX = 'fn:';

/** The wire op id for a function uuid. */
export function functionOpId(id: string): string {
  return `${FUNCTION_OP_PREFIX}${id}`;
}

/**
 * The VariableType vocabulary offered in the input / arg / return type Selects. The full closed
 * set — the backend accepts any `VariableType` for a function's signature.
 */
export const FUNCTION_VARIABLE_TYPES: WorkflowVariableType[] = [
  'text',
  'number',
  'boolean',
  'date',
  'enum',
  'multi',
  'file',
];

/** One editor ARG row: a local `id` for stable v-for keys + the authored {name, description, type}. */
export interface FunctionArgDraft {
  id: string;
  name: string;
  description: string;
  type: WorkflowVariableType;
}

let argSeq = 0;

/** A fresh empty arg row (default type text). */
export function emptyArgDraft(type: WorkflowVariableType = 'text'): FunctionArgDraft {
  argSeq += 1;
  return { id: `arg-${argSeq}`, name: '', description: '', type };
}

/** Project a saved arg onto an editor row (seeding an edit). */
export function argToDraft(arg: CustomFunctionArg): FunctionArgDraft {
  argSeq += 1;
  return { id: `arg-${argSeq}`, name: arg.name, description: arg.description ?? '', type: arg.type };
}

/** Project an editor row back onto the wire arg ({name, description?, type}), trimming + omitting an empty description. */
export function draftToArg(draft: FunctionArgDraft): CustomFunctionArg {
  const arg: CustomFunctionArg = { name: draft.name.trim(), type: draft.type };
  const description = draft.description.trim();
  if (description !== '') arg.description = description;
  return arg;
}

/** The client arg-validation error codes (i18n key suffixes under `variables.functions.form.errors.*`). */
export type ArgErrorCode = 'nameRequired' | 'nameInvalid' | 'nameReserved' | 'nameDuplicate';

/**
 * Validate the arg rows client-side (mirrors the backend): each needs a NON-EMPTY, SAFE-identifier,
 * NON-RESERVED, DISTINCT name. Returns a map of row index → first error code (empty ⇒ all valid).
 * The server stays authoritative; this only stops an obviously-invalid save early with a clear message.
 */
export function validateArgDrafts(args: FunctionArgDraft[]): Record<number, ArgErrorCode> {
  const errors: Record<number, ArgErrorCode> = {};
  const seen = new Set<string>();
  args.forEach((arg, index) => {
    const name = arg.name.trim();
    if (name === '') {
      errors[index] = 'nameRequired';
      return;
    }
    if (!isSafeArgName(name)) {
      errors[index] = 'nameInvalid';
      return;
    }
    if ((RESERVED_ARG_NAMES as readonly string[]).includes(name)) {
      errors[index] = 'nameReserved';
      return;
    }
    if (seen.has(name)) {
      errors[index] = 'nameDuplicate';
      return;
    }
    seen.add(name);
  });
  return errors;
}

/**
 * The SCOPE-VAR feed the body pipeline editor exposes to its op-argument browser: the function's
 * `input` (path `input`) + each named arg (path = its own name), ALL as `source:'scope'`. This is
 * the function's frame — NEVER global variables (the body sees ONLY its scope). A nested element
 * pipeline (map/filter/sort/reduce) MERGES its own `element`/`index` on top of these (the shared
 * editor's existing scope propagation), so a deep body op sees the whole frame stack. Only args
 * with a non-empty name contribute (a half-typed row is skipped).
 */
export function functionScopeVars(
  inputType: WorkflowVariableType,
  args: FunctionArgDraft[],
  inputName: string,
): VariableSourceVar[] {
  const vars: VariableSourceVar[] = [{ source: 'scope', path: 'input', name: inputName, type: inputType }];
  for (const arg of args) {
    const name = arg.name.trim();
    if (name !== '') {
      vars.push({ source: 'scope', path: name, name, type: arg.type });
    }
  }
  return vars;
}
