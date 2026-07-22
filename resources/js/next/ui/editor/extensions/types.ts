// extensions/types.ts — runtime payloads + feature configs for the PART 2
// app-specific editor nodes (mention, variable, if-block, AI chip). These mirror
// the LEGACY `types/editor.ts` shapes EXACTLY so the serialized markdown
// directives are byte-compatible between the old and the "next" editor (see
// FORMAT.md), AND so the full operations-pipeline / boolean-condition / AI-panel
// BEHAVIOR can be replicated with next components.

/** The directive payload schema version. Legacy FORMAT.md pins this at 1. */
export const DATA_VERSION = 1 as const;

/**
 * The editor's variable value types. The ORIGINAL trio (text/number/boolean) is
 * what the legacy FORMAT.md ever serialized; `date` / `enum` / `multi` / `file`
 * extend the vocabulary for hosts that carry richer sources (e.g. workflow-condition
 * editing over form fields: selects → enum, multi-selects → multi, date inputs →
 * date, file inputs → file).
 * Wire values: date = an ISO `YYYY-MM-DD` string; enum = one of the source
 * variable's option VALUES; multi = an array of option values; file = a list of
 * file snapshots ({id, name, mime_type, size}).
 *
 * NOTE: this widens what an op may DECLARE as its input, not what a directive
 * SERIALIZES — a directive's `data.type` still degrades everything outside the
 * original trio to 'text' (identity-only; the real type is recovered from the
 * catalog by path).
 */
export type VariablePrimitive = 'text' | 'number' | 'boolean' | 'date' | 'enum' | 'multi' | 'file';

/** One selectable option of an enum/multi SOURCE variable. */
export interface VariableOption {
  label: string;
  value: string;
}

// --- Mention ----------------------------------------------------------------
// FORMAT.md: `@[mention]("{…}")` with at least `id`, `name`, `avatar`.
export interface MentionNodeAttrs {
  id: string;
  name: string;
  avatar?: string | null;
}

/** Item shape returned by the async `fetchMentions(query)` source. */
export interface MentionItem {
  id: string;
  /** Display label (legacy serializes this as `name`). */
  label: string;
  avatar?: string | null;
}

// --- Variable ---------------------------------------------------------------
// FORMAT.md: `@[variable]("{id,name,type,locked,pipeline,resultType,v}")`.

/** A predefined source variable the user can insert / build a pipeline on. */
export interface VariableDefinition {
  id: string;
  name: string;
  type: VariablePrimitive;
  /** The selectable options of an `enum`/`multi` source (feed `sourceOption(s)` args). */
  options?: VariableOption[];
}

/**
 * Operation argument control types. Beyond the value primitives and the static
 * `select` (options fixed on the operation definition), the SOURCE-driven kinds
 * draw their choices from the SOURCE variable's `options`, not from the operation:
 * `sourceOption` (pick ONE option value), `sourceOptions` (pick MANY; the arg value
 * is a `string[]`), and `sourceMap` (one TARGET value PER option — the arg value is a
 * `Record<optionValue, targetValue>`, with the target's kind declared by the arg's
 * `mapType`; `mapType:'enum'` maps each option to a DESTINATION choice value).
 *
 * The TARGET-driven kinds (choice-producing ops) draw their choices from the
 * DESTINATION field's option set, injected as `targetOptions`: `choiceRules` (a
 * repeatable `{when,then}[]` list — `when` is free text, `then` is a target choice)
 * and `choiceFallback` (a single required target choice).
 */
export type VariableOperationArgumentType =
  | 'text'
  | 'number'
  | 'boolean'
  | 'date'
  | 'select'
  | 'sourceOption'
  | 'sourceOptions'
  | 'sourceMap'
  | 'choiceRules'
  | 'choiceFallback';

/** One rule of a `choiceRules` arg: a text match → a destination choice value. */
export interface ChoiceRule {
  when: string;
  then: string;
}

/**
 * The variable REFERENCE an arg-variable carries (phase-4b) — mirrors the host workflow ref
 * (`{source, path, type}`), kept editor-local so this shared editor module has NO dependency on the
 * workflow page types. `type` is the referenced variable's TRUE type (a full `VariablePrimitive`).
 */
export interface ArgVariableRef {
  source: 'trigger' | 'steps' | 'globals';
  path: string;
  type: VariablePrimitive;
}

/**
 * A value-typed operation ARGUMENT supplied by a VARIABLE instead of a constant (phase-4b). It is the
 * SAME `{kind:'variable', ref, pipeline?, default?}` union the host value-or-variable field emits —
 * mirrored here so a pipeline step's `args` may hold it without importing the workflow page types.
 * Its OWN `pipeline` is the wire `{op, args}` shape (an arg-variable's pipeline may host value-or-
 * variable args again, recursively — bounded by `MAX_ARG_VARIABLE_DEPTH`). Only value controls
 * (text/number/boolean/date) ever carry it; option/map/rules/select args stay literal-only.
 */
export interface ArgVariableValue {
  kind: 'variable';
  ref: ArgVariableRef;
  pipeline?: Array<{ op: string; args: Record<string, unknown> }>;
  default?: string | null;
}

/**
 * One argument value inside a pipeline step: a literal (as before) OR — for a value-typed arg in a
 * value-or-variable pipeline — an `ArgVariableValue` union.
 */
export type VariableArgValue =
  | string
  | number
  | boolean
  | string[]
  | Record<string, string | number>
  | ChoiceRule[]
  | ArgVariableValue;

export interface VariableOperationArgumentDefinition {
  id: string;
  label: string;
  type: VariableOperationArgumentType;
  placeholder?: string;
  /** Optional persistent helper text under the control (e.g. the date_format safe tokens). */
  hint?: string;
  options?: Array<{ label: string; value: string }>;
  defaultValue?: string | number | boolean;
  /** `sourceMap` only: the kind of each mapped TARGET value (`enum` = a target choice). */
  mapType?: 'text' | 'number' | 'date' | 'enum';
}

/** An operation in the catalog: valid on `inputTypes`, yields `outputType`. */
export interface VariableOperationDefinition {
  id: string;
  label: string;
  description?: string;
  inputTypes: VariablePrimitive[];
  outputType: VariablePrimitive;
  args?: VariableOperationArgumentDefinition[];
}

export interface VariablePipelineStep {
  stepId: string;
  operationId: string;
  /**
   * Arg values by arg id; `string[]` carries a `sourceOptions` multi-pick, a
   * `Record<optionValue, targetValue>` carries a `sourceMap` per-option mapping, and
   * a `ChoiceRule[]` carries a `choiceRules` when→then list. A value-typed arg may ALSO
   * hold an `ArgVariableValue` variable union (phase-4b).
   */
  args: Record<string, VariableArgValue>;
  outputType: VariablePrimitive;
}

export interface VariableNodeAttrs {
  id: string;
  name: string;
  type: VariablePrimitive;
  locked: boolean;
  pipeline: VariablePipelineStep[];
  resultType: VariablePrimitive;
  /**
   * OPTIONAL literal DEFAULT (phase-1b): the value the backend substitutes when the
   * referenced value resolves null/'' at run time. Serialized as `data.default` — and
   * only when non-empty, so a ref without a default stays byte-identical to today.
   */
  default?: string | null;
}

// --- AI text ----------------------------------------------------------------
// FORMAT.md: `@[ai-text]("{id,personaId|persona,prompt,labels,v}")`. The legacy
// node attr is `personaId`; the FORMAT sample shows `persona` — we read both on
// parse and write `personaId` (matching the legacy node), staying portable.
export interface AiLabelOption {
  id: string;
  name: string;
  color?: string;
}

export interface AiPersona {
  id: string;
  label: string;
}

export interface AiTextNodeAttrs {
  id: string;
  personaId: string | null;
  /** Markdown (may itself contain inline directives + if-blocks). */
  prompt: string;
  labels: string[];
}

// --- If-block ---------------------------------------------------------------
// FORMAT.md fenced container with `[[IF …]] / [[ELSE_IF …]] / [[ELSE]] / [[/IF]]`.
export type IfBranchKind = 'if' | 'else-if' | 'else';

export interface IfConditionState {
  variableId: string;
  pipeline: VariablePipelineStep[];
  resultType: 'boolean';
}

/** Attrs carried on each `ifBranch` node (its body is editable node content). */
export interface IfBranchNodeAttrs {
  id: string;
  kind: IfBranchKind;
  condition: IfConditionState | null;
}

export interface IfBlockNodeAttrs {
  id: string;
}

// --- Feature configs --------------------------------------------------------

/** Variable feature: predefined variables + the operations catalog. */
export interface VariableFeatureConfig {
  variables: VariableDefinition[];
  operationsCatalog: VariableOperationDefinition[];
  /** Trigger char for the insert suggestion (default `{`). */
  trigger?: string;
}

export interface IfBlockFeatureConfig {
  maxElseIf?: number;
  /** Max nesting depth (an ifBlock counts as one level). Default 3. */
  maxDepth?: number;
}

export interface AiTextFeatureConfig {
  personas?: AiPersona[];
  labelsEnabled?: boolean;
  labelsCatalog?: AiLabelOption[];
}

/** Small id helper (legacy parity: `prefix_xxxxxx`). */
export function generateId(prefix: string): string {
  return `${prefix}_${Math.random().toString(36).slice(2, 8)}`;
}
