<script setup lang="ts">
// WorkflowGlobalValueField — the value control for a SINGLE (non-array) scalar/enum
// literal, adapting to the descriptor `base` (Phase 3). It reuses the existing form
// primitives, one per base:
//   text   → TextInput          number  → NumberInput (number|null)
//   boolean→ Switch (boolean)    date    → DatePicker (ISO yyyy-mm-dd|null)
//   enum   → Select of the descriptor options ({key,label} → value=key, show label)
//
// v-model is the raw literal (`string | number | boolean | null`). The PARENT
// (WorkflowGlobalEditorDrawer) composes the array repeater / object fields / nullable
// "no value" toggle around this leaf, so this component only ever renders one control.
import { computed } from 'vue';
import TextInput from '../../ui/forms/TextInput.vue';
import NumberInput from '../../ui/forms/NumberInput.vue';
import Switch from '../../ui/forms/Switch.vue';
import DatePicker from '../../ui/forms/DatePicker.vue';
import Select, { type SelectOption } from '../../ui/forms/Select.vue';
import { useI18n } from '../../app/i18n';
import type { CatalogDescriptorOption, WorkflowGlobalScalarBase, WorkflowGlobalBase } from './types';

const props = withDefaults(
  defineProps<{
    /** The value's base — a scalar or enum (object/array are composed by the parent). */
    base: WorkflowGlobalScalarBase | 'enum';
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

const { t } = useI18n();

/** The Select options for an enum value — label from the descriptor, value = the wire key. */
const enumOptions = computed<SelectOption[]>(() =>
  props.options.map((option) => ({ value: option.key, label: option.label || option.key })),
);

/** Two-way string binding for TextInput (coerces to a string; null → ''). */
const textValue = computed<string>({
  get: () => (typeof model.value === 'string' ? model.value : ''),
  set: (value) => (model.value = value),
});

/** Two-way number|null binding for NumberInput. */
const numberValue = computed<number | null>({
  get: () => (typeof model.value === 'number' ? model.value : null),
  set: (value) => (model.value = value),
});

/** Two-way boolean binding for the Switch. */
const booleanValue = computed<boolean>({
  get: () => model.value === true,
  set: (value) => (model.value = value),
});

/** Two-way ISO date|null binding for DatePicker (empty string ⇒ null on the wire). */
const dateValue = computed<string | null>({
  get: () => (typeof model.value === 'string' && model.value !== '' ? model.value : null),
  set: (value) => (model.value = value ?? ''),
});

/** Two-way string|null binding for the enum Select. */
const enumValue = computed<string | null>({
  get: () => (typeof model.value === 'string' && model.value !== '' ? model.value : null),
  set: (value) => (model.value = value ?? ''),
});
</script>

<template>
  <TextInput
    v-if="base === 'text'"
    v-model="textValue"
    :disabled="disabled"
    :aria-invalid="invalid"
    :aria-label="ariaLabel"
    :placeholder="t('workflows.globals.value.textPlaceholder')"
  />

  <NumberInput
    v-else-if="base === 'number'"
    v-model="numberValue"
    :disabled="disabled"
    :aria-invalid="invalid"
    :aria-label="ariaLabel"
    :placeholder="t('workflows.globals.value.numberPlaceholder')"
  />

  <div v-else-if="base === 'boolean'" class="flex h-10 items-center">
    <Switch
      v-model="booleanValue"
      :disabled="disabled"
      :aria-label="ariaLabel"
      :label="booleanValue ? t('common.yes') : t('common.no')"
    />
  </div>

  <DatePicker
    v-else-if="base === 'date'"
    v-model="dateValue"
    :disabled="disabled"
    :aria-invalid="invalid"
    :aria-label="ariaLabel"
    :placeholder="t('workflows.globals.value.datePlaceholder')"
  />

  <Select
    v-else-if="base === 'enum'"
    v-model="enumValue"
    :options="enumOptions"
    :disabled="disabled"
    :aria-invalid="invalid"
    :aria-label="ariaLabel"
    :placeholder="t('workflows.globals.value.enumPlaceholder')"
    :empty-text="t('workflows.globals.value.enumEmpty')"
  />
</template>
