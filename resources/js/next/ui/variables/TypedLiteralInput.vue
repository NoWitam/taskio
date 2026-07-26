<script setup lang="ts">
// TypedLiteralInput — the ONE descriptor-driven control for a SINGLE (non-array) typed
// literal. Given a descriptor `base` it renders exactly one existing form primitive:
//   text   → TextInput          number  → NumberInput (number|null)
//   boolean→ Switch (boolean)    date    → DatePicker (ISO yyyy-mm-dd|null)
//   enum   → Select of the descriptor options ({key,label} → value=key, show label)
//
// It moved here from the consts editor's value field (B3) UNCHANGED: the consts editor
// authored a typed literal, and so does a variable reference's "default when empty" — one
// control, one set of coercions, one place to fix a typed-input bug. The Variables area
// keeps `pages/variables/ConstantValueField.vue` as a thin alias over this so the consts
// editor's import path and prop vocabulary are untouched.
//
// v-model is the raw literal (`string | number | boolean | null`). COMPOSITION is the
// caller's job: an array repeater, an object's fields, a nullable "no value" toggle or a
// tri-state "no default" wrapper are all built AROUND this leaf, never inside it.
import { computed } from 'vue';
import TextInput from '../forms/TextInput.vue';
import NumberInput from '../forms/NumberInput.vue';
import Switch from '../forms/Switch.vue';
import DatePicker from '../forms/DatePicker.vue';
import Select, { type SelectOption } from '../forms/Select.vue';
import { useI18n } from '../../app/i18n';
import type { VariableDescriptorOption, VariableLiteralBase } from './types';

const props = withDefaults(
  defineProps<{
    /** The value's base — a scalar or enum (object/array are composed by the caller). */
    base: VariableLiteralBase;
    /** The enum descriptor options (used only when base === 'enum'). */
    options?: VariableDescriptorOption[];
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
  <!-- The placeholder strings live under `variables.consts.value.*`: they were authored for
       this exact control and are reused verbatim so no user-visible text changed when the
       control moved into the design system (same precedent as VariableBrowser's
       `workflows.variable.fileSubfield.*` sub-labels). -->
  <TextInput
    v-if="base === 'text'"
    v-model="textValue"
    :disabled="disabled"
    :aria-invalid="invalid"
    :aria-label="ariaLabel"
    :placeholder="t('variables.consts.value.textPlaceholder')"
  />

  <NumberInput
    v-else-if="base === 'number'"
    v-model="numberValue"
    :disabled="disabled"
    :aria-invalid="invalid"
    :aria-label="ariaLabel"
    :placeholder="t('variables.consts.value.numberPlaceholder')"
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
    :placeholder="t('variables.consts.value.datePlaceholder')"
  />

  <Select
    v-else-if="base === 'enum'"
    v-model="enumValue"
    :options="enumOptions"
    :disabled="disabled"
    :aria-invalid="invalid"
    :aria-label="ariaLabel"
    :placeholder="t('variables.consts.value.enumPlaceholder')"
    :empty-text="t('variables.consts.value.enumEmpty')"
  />
</template>
