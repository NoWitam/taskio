<script setup lang="ts">
// ChoiceRuleWhenField — the wire↔editor boundary for ONE `match_to_choice` rule's LEFT side
// (variable-typesystem rework). A rule's `when` used to be a free-text equality string; it is now a
// boolean-terminal PIPELINE over the operation's own TEXT input — the SAME "pipeline over a value →
// boolean" model the if-block condition uses, but rooted at the op's input (no variable picker).
//
// WHY A DEDICATED COMPONENT. The stored `when` is the WIRE `{op, args}[]` shape (it rides raw to the
// directive, exactly like every other pipeline), but the pipeline editor speaks the editor-shaped
// `VariablePipelineStep[]` (with `stepId` / `outputType`). Deriving editor steps INLINE every render
// would regenerate `stepId`s on each keystroke and collapse the step being edited. So this component
// owns the editor projection as LOCAL state (stable ids, generated once), re-seeding only on an
// EXTERNAL wire change (a rule reset), and emits the cleaned wire back — mirroring how
// `ValueOrVariableField` holds a local editor draft for its modal pipeline.
//
// IT DOES NOT IMPORT the pipeline editor (that would close an import cycle with
// `VariablePipelineEditor` ⇄ `PipelineArgLiteralInput`). Instead it exposes the projected steps + an
// update handler through its default scoped slot, and the parent (`VariablePipelineEditor`, via
// `PipelineArgLiteralInput`'s `#when` slot) fills it with a nested, self-recursive pipeline editor.
import { ref, watch } from 'vue';
import type {
  VariableOperationDefinition,
  VariablePipelineStep,
  VariablePrimitive,
} from './types';

type WireStep = { op: string; args: Record<string, unknown> };

const props = withDefaults(
  defineProps<{
    /** The rule's stored `when` — the WIRE `{op, args}[]` boolean-terminal pipeline. */
    modelValue: WireStep[];
    /** The operations catalog (to resolve a wire step's output type for the editor projection). */
    catalog: VariableOperationDefinition[];
  }>(),
  { modelValue: () => [], catalog: () => [] },
);

const emit = defineEmits<{ 'update:modelValue': [WireStep[]] }>();

let seq = 0;
/** Project the WIRE pipeline onto editor steps, minting a STABLE local id per step (once). */
function toEditorSteps(wire: WireStep[]): VariablePipelineStep[] {
  return (wire ?? []).map((w) => {
    seq += 1;
    return {
      stepId: `when-step-${seq}`,
      operationId: w.op,
      args: (w.args ?? {}) as VariablePipelineStep['args'],
      outputType: (props.catalog.find((op) => op.id === w.op)?.outputType ?? 'boolean') as VariablePrimitive,
    };
  });
}
/** Strip editor steps back to the WIRE `{op, args}` shape (no `stepId` / `outputType` on the wire). */
function toWireSteps(steps: VariablePipelineStep[]): WireStep[] {
  return steps.map((s) => ({ op: s.operationId, args: s.args as Record<string, unknown> }));
}

const steps = ref<VariablePipelineStep[]>(toEditorSteps(props.modelValue));
/** The wire we last emitted — so our own echo does NOT re-seed local state (which would drop edit ids). */
let lastWire = JSON.stringify(props.modelValue ?? []);

watch(
  () => props.modelValue,
  (wire) => {
    const incoming = JSON.stringify(wire ?? []);
    if (incoming !== lastWire) {
      steps.value = toEditorSteps(wire);
      lastWire = incoming;
    }
  },
);

function onSteps(next: VariablePipelineStep[]): void {
  steps.value = next;
  const wire = toWireSteps(next);
  lastWire = JSON.stringify(wire);
  emit('update:modelValue', wire);
}
</script>

<template>
  <slot :steps="steps" :on-steps="onSteps" />
</template>
