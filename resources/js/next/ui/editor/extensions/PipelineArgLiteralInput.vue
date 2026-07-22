<script setup lang="ts">
// PipelineArgLiteralInput — the LITERAL control for a value-typed operation argument
// (text / number / boolean / date). Extracted VERBATIM from VariablePipelineEditor's
// inline per-arg controls so the SAME control is used in two places without drift:
//   • VariablePipelineEditor renders it directly for a value-typed arg when the
//     value-or-variable arg feature is OFF (conditions / markdown builders) or at/over
//     the depth cap — i.e. today's literal behavior, byte-identical;
//   • the value-or-variable arg editor (ValueOrVariableField) renders it inside its
//     VALUE-mode slot, so an arg's literal input looks/behaves identically whether or
//     not the variable toggle wraps it.
//
// The model coercions + emitted normalizations MATCH the originals exactly (number →
// `v ?? 0`, date → `v ?? ''`), so a literal arg serializes byte-identically to before.
import TextInput from '../../forms/TextInput.vue';
import NumberInput from '../../forms/NumberInput.vue';
import Switch from '../../forms/Switch.vue';
import DatePicker from '../../forms/DatePicker.vue';
import type { VariableOperationArgumentDefinition } from './types';

defineProps<{
  /** The arg descriptor (drives the control + its label/placeholder). */
  arg: VariableOperationArgumentDefinition;
  /** The arg's current RAW value (a literal). */
  value: unknown;
  /** Disable the control (forwarded from a wrapping value-or-variable field). */
  disabled?: boolean;
}>();

/** The normalized literal to store — matches the original inline control's emit exactly. */
const emit = defineEmits<{ 'update:value': [string | number | boolean] }>();
</script>

<template>
  <TextInput
    v-if="arg.type === 'text'"
    :model-value="String(value ?? '')"
    :placeholder="arg.placeholder"
    :disabled="disabled"
    @update:model-value="(v: string) => emit('update:value', v)"
  />
  <NumberInput
    v-else-if="arg.type === 'number'"
    :model-value="value === '' || value == null ? null : Number(value)"
    :placeholder="arg.placeholder"
    :disabled="disabled"
    @update:model-value="(v: number | null) => emit('update:value', v ?? 0)"
  />
  <Switch
    v-else-if="arg.type === 'boolean'"
    :model-value="Boolean(value)"
    :aria-label="arg.label"
    :disabled="disabled"
    @update:model-value="(v: boolean) => emit('update:value', v)"
  />
  <!-- date — a DatePicker in a fixed-width wrapper (Popover-based fields drop the class
       attr; the trigger chain needs the forced w-full). -->
  <div v-else-if="arg.type === 'date'" class="w-44 [&>div]:w-full">
    <DatePicker
      :model-value="typeof value === 'string' && value !== '' ? String(value) : null"
      :aria-label="arg.label"
      :disabled="disabled"
      @update:model-value="(v: string | null) => emit('update:value', v ?? '')"
    />
  </div>
</template>
