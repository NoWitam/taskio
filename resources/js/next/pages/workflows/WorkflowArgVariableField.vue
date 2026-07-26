<script setup lang="ts">
// WorkflowArgVariableField — ONE operation ARGUMENT as a value-or-variable control, packaged so a
// host that cannot import this page can still render it (B4).
//
// WHY IT EXISTS. The markdown editor's variable chip panel builds an operations pipeline exactly
// like a step field does, so its arguments deserve the SAME "value or variable" choice — the engine
// already resolves an arg-variable there (phase-4b is a backend capability, not a field-level one).
// But the value-or-variable UI is `ValueOrVariableField`, which lives in this page, and `ui/**` must
// never import `pages/**`. So the dependency is INVERTED: the step card hands this component to the
// editor (`VariableFeatureConfig.argVariableField`), and the chip panel fills the pipeline editor's
// `argVariable` slot with it.
//
// It is a THIN adapter and deliberately nothing else:
//   • props  = the slot props the pipeline editor exposes, already enriched by
//              `VariableReferenceEditor` with the argument's derived variable POLICY (its pool, its
//              coercion catalog — NONE for a structural arg — its terminal gate, its toggle label),
//   • body   = `ValueOrVariableField` with `PipelineArgLiteralInput` in its VALUE slot — the exact
//              pair the step field's own modal renders, so both surfaces behave identically,
//   • emit   = the RAW argument value (a bare literal, or the `{kind:'variable', …}` union), mapped
//              by the shared `argVariableAdapters`, so a literal stays byte-identical on the wire.
//
// NO `target-options` is threaded down: an arg-variable's own pipeline terminates on its policy
// types, never on a destination choice set (mirrors the backend).
import PipelineArgLiteralInput from '../../ui/editor/extensions/PipelineArgLiteralInput.vue';
import ValueOrVariableField from './ValueOrVariableField.vue';
import { argToUnion, unionToArg } from './argVariableAdapters';
import type { CatalogVariable, WorkflowFieldValue, WorkflowVariableType } from './types';
import type {
  VariableArgValue,
  VariableOperationArgumentDefinition,
  VariableOperationDefinition,
  VariableOption,
} from '../../ui/editor/extensions/types';

const props = withDefaults(
  defineProps<{
    /** The argument definition being supplied (its control kind, label, options…). */
    arg: VariableOperationArgumentDefinition;
    /** The RAW stored argument value (a literal, or a `{kind:'variable'}` union). */
    value?: VariableArgValue;
    /** This argument's ARG-VARIABLE nesting depth (the pipeline editor counts it). */
    depth?: number;
    /** The running SOURCE variable's options (for an option / map literal control). */
    sourceOptions?: VariableOption[];
    /** The destination field's choices (for a choice literal control). */
    targetOptions?: VariableOption[];
    disabled?: boolean;
    /** The pool this argument may reference (the reference editor passes the show-all feed). */
    variables?: CatalogVariable[];
    /** The coercion operations offered for the argument's own pipeline ([] for a structural arg). */
    operationsCatalog?: VariableOperationDefinition[];
    /** The terminal type(s) that pipeline must produce ([] ⇒ no gate). */
    resultTypes?: WorkflowVariableType[];
    /** The clarifying "Variable" toggle label for a structural argument. */
    variableModeLabel?: string;
  }>(),
  {
    depth: 0,
    sourceOptions: () => [],
    targetOptions: () => [],
    disabled: false,
    variables: () => [],
    operationsCatalog: () => [],
    resultTypes: () => [],
  },
);

const emit = defineEmits<{ 'update:value': [VariableArgValue] }>();

function onUnion(union: WorkflowFieldValue | null): void {
  emit('update:value', unionToArg(union, props.arg));
}

/**
 * A CHOICE structural ENTRY (Defect-3 — its `resultTypes` is exactly `['enum']`) must map INTO the
 * destination options, so it threads `targetOptions` to the field (the choice-op rule); a text/number/date
 * entry threads none. Mirrors ValueOrVariableField's own recursive slot.
 */
const threadsChoiceTargets = (): boolean =>
  props.resultTypes.length === 1 && props.resultTypes[0] === ('enum' as WorkflowVariableType);
</script>

<template>
  <ValueOrVariableField
    :model-value="argToUnion(value)"
    :variables="variables"
    :arg-variables="variables"
    :operations-catalog="operationsCatalog"
    :result-types="resultTypes"
    :target-options="threadsChoiceTargets() ? targetOptions : []"
    :depth="depth"
    :disabled="disabled"
    :picker-label="arg.label"
    :variable-mode-label="variableModeLabel"
    @update:model-value="onUnion"
  >
    <template #default="{ value: litValue, setValue: setLit, disabled: litDisabled }">
      <PipelineArgLiteralInput
        :arg="arg"
        :value="litValue"
        :source-options="sourceOptions"
        :target-options="targetOptions"
        :disabled="litDisabled"
        @update:value="setLit"
      />
    </template>
  </ValueOrVariableField>
</template>
