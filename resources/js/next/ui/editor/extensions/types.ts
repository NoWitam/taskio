// extensions/types.ts — runtime payloads + feature configs for the PART 2
// app-specific editor nodes (mention, variable, if-block, AI chip). These mirror
// the LEGACY `types/editor.ts` shapes EXACTLY so the serialized markdown
// directives are byte-compatible between the old and the "next" editor (see
// FORMAT.md), AND so the full operations-pipeline / boolean-condition / AI-panel
// BEHAVIOR can be replicated with next components.
//
// TYPE-ONLY link to the SHARED variable model (`ui/variables/types`): the editor's
// variable feature can be fed the same `VariableSourceVar[]` every other variable
// surface speaks. The import is `import type` on BOTH sides of the pair (that module
// imports `VariablePipelineStep` from here), so the cycle is erased at build time.
import type { Component } from 'vue';
import type { VariableBase, VariableLiteral, VariableSourceVar } from '../../variables/types';

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
  /**
   * Type-icon MODIFIERS (§refinement 3), mirrored from the catalog descriptor: `nullable` when the
   * source may resolve empty, `array` when it is a collection (a multi / repeater). Optional +
   * emit-or-omit; the chip reads them to mark its type icon. NOT serialized into the directive.
   */
  nullable?: boolean;
  array?: boolean;
  /**
   * The STRUCTURAL base, when the flat `type` cannot express it (B4). An `object` container — a
   * form SECTION, an object GLOBAL, the "Globals" group — degrades to the `text` wire type, so
   * without this marker a definition-only feed could not tell a container from a text leaf, and the
   * `{` browser would offer it as an INSERTABLE row (a directive resolving to a map). Emit-or-omit,
   * exactly like the two flags above; a host that feeds the shared `source()` list carries the full
   * descriptor instead and never needs it.
   */
  base?: VariableBase;
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
 * repeatable `{when,then}[]` list — `when` is a boolean-terminal CONDITION pipeline
 * over the op's own TEXT input, `then` is a target choice) and `choiceFallback` (a
 * single required target choice).
 */
/*
 * ARRAY-TRANSFORM wave 2 adds two controls, both mirrored on the backend
 * (`WorkflowOperationArgType`):
 *   • `elementPipeline` — a per-element `{op,args}[]` pipeline (byte-identical wire to a
 *     `ChoiceRule.when`), rooted at the array's ELEMENT type, terminal-gated per host op
 *     (filter=boolean, sort=number, reduce=seed type, map=any base). Fed two synthetic
 *     `scope` variables (`Element`/`Indeks`) into its browser feed.
 *   • `reduceSeed` — the `array_reduce` accumulator seed: a typed literal `{type, value}`
 *     (type ∈ text/number/boolean/date). Its chosen type roots the sibling `reducer`
 *     element pipeline's required terminal.
 *
 * ARRAY-TRANSFORM wave 3 (F4) adds one more:
 *   • `elementDefault` — `array_at`'s REQUIRED-when-non-terminal typed default `{type, value}`
 *     (reduceSeed-style self-describing literal), but with the type LOCKED to the array's ELEMENT
 *     base (no free type select — hence a distinct kind from `reduceSeed`). Substituted for a null
 *     element before the NEXT op so the chain never consumes null; a valid default flips `array_at`'s
 *     output to non-null. type ∈ text|number|boolean|date|enum (enum membership checked vs the
 *     element options).
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
  | 'choiceFallback'
  | 'elementPipeline'
  | 'reduceSeed'
  | 'elementDefault';

/**
 * The typed literal an `array_reduce` SEED carries — mirrors the backend seed wire
 * (`{type, value}`, type ∈ text/number/boolean/date). The seed's `type` roots the sibling
 * `reducer` element pipeline (its terminal must equal this base).
 */
export interface ReduceSeedValue {
  type: 'text' | 'number' | 'boolean' | 'date';
  value: VariableLiteral;
}

/**
 * One rule of a `choiceRules` arg: a CONDITION → a destination choice ENTRY.
 *
 * `when` (variable-typesystem rework): was a bare `string` (free-text equality). It is now a
 * boolean-terminal PIPELINE over the operation's OWN input (match_to_choice runs from TEXT) — the
 * SAME "pipeline over a value → boolean" model the if-block condition uses, rooted at the op's input
 * instead of a picked variable. When it evaluates TRUE the rule fires and returns `then`.
 *
 * It carries the WIRE `{op, args}` pipeline shape — byte-identical to every other pipeline
 * (`ArgVariableValue['pipeline']`), NOT the editor-shaped `VariablePipelineStep` (no `stepId` /
 * `outputType` keys ever reach the wire; the editor↔wire projection is transient, in the LHS
 * sub-editor). Its ops' args may THEMSELVES be arg-variables (recursively, depth-capped). A
 * choice-producing op is NOT allowed inside it (no destination option set in a condition context).
 *
 * Since Defect-3 the `then` is a value-or-variable ENTRY (a bare choice value OR a
 * `{kind:'variable', …}` union) — see `ArgEntryValue` — so a rule's target may itself come from a
 * variable, PER RULE. `then` is UNCHANGED by the `when` rework.
 */
export interface ChoiceRule {
  when: Array<{ op: string; args: Record<string, unknown> }>;
  then: ArgEntryValue;
}

/**
 * The variable REFERENCE an arg-variable carries (phase-4b) — mirrors the host workflow ref
 * (`{source, path, type}`), kept editor-local so this shared editor module has NO dependency on the
 * workflow page types. `type` is the referenced variable's TRUE type (a full `VariablePrimitive`).
 */
export interface ArgVariableRef {
  /**
   * The ref's ROOT source. `'scope'` (array-transform wave) is the synthetic per-element scope an
   * element pipeline exposes — a reduce reducer op references an `element.<field>` subfield as a
   * scope arg-variable (`{source:'scope', path:'element.<field>'}`); it is contextual, valid only
   * inside that pipeline.
   */
  source: 'trigger' | 'steps' | 'globals' | 'scope';
  path: string;
  type: VariablePrimitive;
}

/**
 * A value-typed operation ARGUMENT supplied by a VARIABLE instead of a constant (phase-4b). It is the
 * SAME `{kind:'variable', ref, pipeline?, default?}` union the host value-or-variable field emits —
 * mirrored here so a pipeline step's `args` may hold it without importing the workflow page types.
 * Its OWN `pipeline` is the wire `{op, args}` shape (an arg-variable's pipeline may host value-or-
 * variable args again, recursively — bounded by `MAX_ARG_VARIABLE_DEPTH`). EVERY arg control can now
 * carry it (phase-4b): value args coerce to their type, option args to enum|text, and STRUCTURAL args
 * (sourceMap/choiceRules) hold a raw whole-structure ref with no sub-pipeline — see `argVariablePolicy`.
 */
export interface ArgVariableValue {
  kind: 'variable';
  ref: ArgVariableRef;
  pipeline?: Array<{ op: string; args: Record<string, unknown> }>;
  default?: string | null;
}

/**
 * ONE entry of a STRUCTURAL container (a `sourceMap` mapping value or a `choiceRules` rule `then`) —
 * Defect-3. A structural arg is NO LONGER a whole-arg variable; instead each ENTRY is its own
 * value-or-variable: a bare LITERAL scalar (byte-identical to before — a string / number, no wrapper)
 * OR the SAME `{kind:'variable', ref, pipeline?, default?}` union. The entry's expected type is the
 * map/rule TARGET type; a choice target additionally maps into the destination field's option set.
 */
export type ArgEntryValue = string | number | ArgVariableValue;

/**
 * One argument value inside a pipeline step: a literal (as before) OR — for a value-typed / option arg
 * in a value-or-variable pipeline — an `ArgVariableValue` union. A STRUCTURAL container holds LITERAL or
 * per-ENTRY value-or-variable entries (`ArgEntryValue`): a `sourceMap` is `Record<optionValue, ArgEntryValue>`
 * and a `choiceRules` is `ChoiceRule[]` whose `then` is an `ArgEntryValue`.
 */
export type VariableArgValue =
  | string
  | number
  | boolean
  | string[]
  | Record<string, ArgEntryValue>
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

/**
 * A running TYPE DESCRIPTOR threaded through a pipeline — the FE mirror of the backend's
 * `WorkflowVariableType::descriptor()` shape (variable-typesystem "array transform" wave).
 * It carries what the flat `VariablePrimitive` alone cannot: whether the running value is an
 * ARRAY, its ELEMENT type (`elementDescriptor`), and nullability. The flat wire slot for any
 * scalar/enum ARRAY is `multi` (`base:'enum', array:true`), so the descriptor↔flat bridge in
 * `operationHelpers` collapses `array:true` → `multi` and expands `multi` → an enum element.
 *
 * By CONVENTION `base` is never `'multi'` (a multi is `base:'enum'` + `array:true`).
 */
export interface OperationTypeDescriptor {
  /**
   * The structural base. Never `'multi'`: an array-of-enum is `base:'enum'` + `array:true`.
   * Array-transform wave 3 widens it with `'object'` (a repeater ELEMENT — a form section /
   * repeater row) so an `array<object>` element descriptor stays inspectable (its `fields` drive
   * the synthetic `Element` subfield expansion). `'object'` degrades to `'text'` on the flat wire,
   * exactly like the backend, so `typeFromDescriptor` never surfaces it as a variable type.
   */
  base: VariablePrimitive | 'object';
  /** A collection (a multi; a repeater `array<object>` / `array<file>` since wave 3). */
  array: boolean;
  /** The path may resolve empty (`at` marks its element output nullable). */
  nullable?: boolean;
  /** enum/multi choices, carried so a following option op keeps the source's options. */
  options?: VariableOption[];
  /** The element type of an array (a `multi`'s element is a single `enum`). */
  elementDescriptor?: OperationTypeDescriptor;
  /**
   * The structural SUBFIELDS of an `object` container / `file` composite (array-transform wave 3):
   * the recursive `{key, label, descriptor}` list an `array<object>` / `array<file>` ELEMENT
   * carries, so the synthetic `Element` scope variable expands into `element.<field>` pickables and
   * a scope-rooted map/filter/sort union knows each subfield's type. Absent for a scalar/enum element.
   */
  fields?: OperationTypeDescriptorField[];
}

/** One structural subfield of an `object`/`file` descriptor (array-transform wave 3). */
export interface OperationTypeDescriptorField {
  key: string;
  label: string;
  descriptor: OperationTypeDescriptor;
}

/**
 * The context an array op's `resolveOutput` receives (array-transform wave 2). It carries the
 * operations catalog so an op whose output depends on its ELEMENT PIPELINE's terminal
 * (`array_map` → array of the pipeline terminal) can walk that inner pipeline. Absent for the
 * flat callers (the picker/chip, wave-1 count/at) — those resolvers never read it.
 */
export interface OperationOutputContext {
  catalog: VariableOperationDefinition[];
}

/** An operation in the catalog: valid on `inputTypes`, yields `outputType`. */
export interface VariableOperationDefinition {
  id: string;
  label: string;
  description?: string;
  inputTypes: VariablePrimitive[];
  /**
   * The STATIC (flat) output type — the degraded wire form the picker/chip reads and the
   * default type-flow uses. An ARRAY op whose real output depends on its INPUT element type
   * (e.g. `array_at` → the element type) additionally supplies `resolveOutput` below; every
   * legacy/scalar op omits it and keeps this static `outputType` verbatim (a provable no-op).
   */
  outputType: VariablePrimitive;
  args?: VariableOperationArgumentDefinition[];
  /**
   * OPTIONAL descriptor-aware output resolver (array-transform wave). When present it computes
   * the running descriptor AFTER this op from the INPUT descriptor (and the step's args). Absent
   * ⇒ a default resolver returns `outputType` as a flat descriptor, so every existing op's
   * type-flow is byte-identical. Array ops supply it: `array_count` → number, `array_at` → the
   * input's element descriptor (nullable). Mirrors the backend `WorkflowOperation::outputDescriptor`.
   */
  resolveOutput?: (
    input: OperationTypeDescriptor,
    args: Record<string, unknown>,
    ctx?: OperationOutputContext,
  ) => OperationTypeDescriptor;
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
   *
   * TYPED since B4: the panel authors it with the shared `VariableDefaultField`, so a
   * number/boolean/date default keeps its JS type and round-trips through the directive as a
   * JSON scalar (`"default":12` / `false`) rather than being stringified by a text input.
   * `null` is the ONE "unset" value (a `false` / `0` default is meaningful and IS emitted).
   */
  default?: VariableLiteral;
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

/**
 * Variable feature: predefined variables + the operations catalog.
 *
 * LIVE FEED (the fix for the frozen-snapshot bug): `variables` / `operationsCatalog` are
 * read ONCE, when the editor builds its extensions at setup. A host whose catalog arrives
 * ASYNC (the workflow step card fetches it) — or which renames a step key, inserts an
 * earlier step, or switches the trigger form — therefore never reached an already-open
 * editor. The two OPTIONAL getters below close that: when supplied they are read AT CALL
 * TIME by the extension and by every consumer (chip, if-branch, insert suggestion), so a
 * reactive source stays live for the editor's whole lifetime.
 *
 * Both are OPTIONAL and purely ADDITIVE: a host that passes only the arrays (the docs page,
 * any simple embed) keeps working exactly as before — the arrays are the fallback — and a
 * host that passes NO variable feature at all (`pages/tasks/*`) never builds this node.
 */
export interface VariableFeatureConfig {
  variables: VariableDefinition[];
  operationsCatalog: VariableOperationDefinition[];
  /** Trigger char for the insert suggestion (default `{`). */
  trigger?: string;
  /**
   * LIVE getter for the offered variables in the SHARED model's vocabulary
   * (`ui/variables` — the same `{source,path,name,type,descriptor?}` the catalog emits).
   * Read at call time; wins over `variables` whenever it returns a non-empty list.
   */
  source?: () => VariableSourceVar[];
  /** LIVE getter for the operations catalog. Read at call time; wins over `operationsCatalog`. */
  catalog?: () => VariableOperationDefinition[];
  /**
   * OPTIONAL (B4): the component that renders ONE operation ARGUMENT as a value-or-variable
   * control inside the chip panel's pipeline. INJECTED BY THE HOST — an arg-variable's UI is the
   * value-or-variable field, which lives in the consuming page (`pages/workflows`), and `ui/**`
   * must never import `pages/**`. So the page hands its field in and the panel fills the pipeline
   * editor's `argVariable` slot with it; a host that injects nothing keeps LITERAL-ONLY arguments
   * (today's behaviour, byte-identical), which is what the docs page and any plain embed get.
   *
   * It is handed every slot prop the pipeline editor exposes, ENRICHED by
   * `VariableReferenceEditor` with the argument's derived variable policy, and reports a new RAW
   * argument value back:
   *   props  `{ arg, value, depth, sourceOptions, targetOptions, disabled, variables,
   *             operationsCatalog, resultTypes, variableModeLabel }`
   *   emits  `update:value` with the raw arg value (a bare literal, or the
   *          `{kind:'variable', ref, pipeline?, default?}` union).
   * Pass it `markRaw()`-wrapped so Vue never makes the component definition reactive.
   */
  argVariableField?: Component;
}

/**
 * The `variable` extension's Tiptap STORAGE — what a NodeView (`VariableChip`,
 * `IfBranchView`) reads off `editor.storage.variable`.
 *
 * `definitions` / `catalog` are the FROZEN arrays the extension was built with (kept for
 * backward compatibility with anything reading them directly). `getDefinitions()` /
 * `getCatalog()` / `getSource()` are the LIVE readers: calling them inside a Vue computed
 * both re-reads the host's current feed AND registers the reactive dependency, so a
 * consumer re-renders when the host's catalog changes.
 */
export interface VariableStorage {
  definitions: VariableDefinition[];
  catalog: VariableOperationDefinition[];
  getDefinitions: () => VariableDefinition[];
  getCatalog: () => VariableOperationDefinition[];
  getSource: () => VariableSourceVar[];
  /**
   * The host-injected operation-ARGUMENT field (B4 — see `VariableFeatureConfig.argVariableField`).
   * Absent ⇒ the chip panel's pipeline renders literal-only arguments.
   */
  argVariableField?: Component;
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
