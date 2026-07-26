<script setup lang="ts">
// ConstantValueField — the consts-side ALIAS of the shared design-system control
// `ui/variables/TypedLiteralInput.vue`.
//
// The BODY moved into `ui/variables` in B3 (a variable reference's "default when empty"
// needs exactly the same descriptor-driven control the consts editor needs, and a `ui/`
// component may never import from `pages/`). This file stays so the consts editor keeps its
// import path AND its consts-typed prop vocabulary (`ConstantScalarBase` /
// `CatalogDescriptorOption`), which are structurally identical to the shared model's
// `VariableLiteralBase` / `VariableDescriptorOption`.
//
// v-model is still the raw literal (`string | number | boolean | null`); the PARENT
// (ConstantEditorDrawer) still composes the array repeater / object fields / nullable
// "no value" toggle around this leaf.
import TypedLiteralInput from '../../ui/variables/TypedLiteralInput.vue';
import type { CatalogDescriptorOption, ConstantScalarBase } from '../workflows/types';

withDefaults(
  defineProps<{
    /** The value's base — a scalar or enum (object/array are composed by the parent). */
    base: ConstantScalarBase | 'enum';
    /** The enum descriptor options (used only when base === 'enum'). */
    options?: CatalogDescriptorOption[];
    disabled?: boolean;
    /** Accessible label / placeholder context for the control. */
    ariaLabel?: string;
    /** True when the host renders a validation error for this field (aria-invalid skin). */
    invalid?: boolean;
  }>(),
  { options: () => [], disabled: false, invalid: false },
);

const model = defineModel<unknown>({ default: null });
</script>

<template>
  <TypedLiteralInput
    v-model="model"
    :base="base"
    :options="options"
    :disabled="disabled"
    :aria-label="ariaLabel"
    :invalid="invalid"
  />
</template>
