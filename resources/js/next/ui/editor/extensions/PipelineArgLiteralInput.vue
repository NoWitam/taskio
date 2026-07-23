<script setup lang="ts">
// PipelineArgLiteralInput — the LITERAL (non-variable) control for ANY pipeline operation argument.
// Phase-4a extracted the value-typed controls (text / number / boolean / date) here VERBATIM; phase-4b
// widened it to ALSO own the option / map / rules controls (select / sourceOption / sourceOptions /
// sourceMap / choiceRules / choiceFallback), so the SAME literal editor renders in the two places
// without drift:
//   • VariablePipelineEditor renders it directly for an arg when the value-or-variable arg feature is
//     OFF (conditions / markdown builders) or at/over the depth cap — i.e. today's literal behavior,
//     byte-identical for every control (this is the ONLY literal editor now; the editor no longer
//     inlines the option/map/rules controls);
//   • the value-or-variable arg editor (ValueOrVariableField) renders it inside its VALUE-mode slot,
//     so an arg's literal input looks/behaves identically whether or not the variable toggle wraps it.
//
// The model coercions + emitted normalizations MATCH the originals exactly (number → `v ?? 0`, date →
// `v ?? ''`, sourceOption/select/choiceFallback → `v ?? ''`, sourceOptions → `v ?? []`, a sourceMap
// entry cleared to '' is DELETED), so a literal arg serializes byte-identically to before. The
// option/map/rules controls draw their choices from `sourceOptions` (the SOURCE variable's options)
// and `targetOptions` (the DESTINATION field's options), passed by the host.
import { computed } from 'vue';
import Select from '../../forms/Select.vue';
import TextInput from '../../forms/TextInput.vue';
import NumberInput from '../../forms/NumberInput.vue';
import Switch from '../../forms/Switch.vue';
import DatePicker from '../../forms/DatePicker.vue';
import SegmentedControl from '../../forms/SegmentedControl.vue';
import Icon from '../../primitives/Icon.vue';
import Button from '../../primitives/Button.vue';
import { useI18n } from '../../../app/i18n';
import type { ChoiceRule, VariableOperationArgumentDefinition, VariableOption } from './types';

/** The literal an arg control emits — every arg VALUE shape EXCEPT the variable union. */
type LiteralArgValue = string | number | boolean | string[] | Record<string, string | number> | ChoiceRule[];

const props = withDefaults(
  defineProps<{
    /** The arg descriptor (drives the control + its label/placeholder). */
    arg: VariableOperationArgumentDefinition;
    /** The arg's current RAW value (a literal). */
    value: unknown;
    /** The SOURCE variable's options — feed sourceOption / sourceOptions / sourceMap keys. */
    sourceOptions?: VariableOption[];
    /** The DESTINATION field's options — feed the choice-producing controls (enum map / rules / fallback). */
    targetOptions?: VariableOption[];
    /** Disable the control (forwarded from a wrapping value-or-variable field). */
    disabled?: boolean;
  }>(),
  { sourceOptions: () => [], targetOptions: () => [], disabled: false },
);

/** The normalized literal to store — matches the original inline controls' emit exactly. */
const emit = defineEmits<{ 'update:value': [LiteralArgValue] }>();

const { t } = useI18n();

/** The source options mapped to Select / selection-card options. */
const sourceSelectOptions = computed(() => props.sourceOptions.map((o) => ({ value: o.value, label: o.label })));
/** The destination options mapped to Select options (choiceRules / Fallback + enum map). */
const targetSelectOptions = computed(() => props.targetOptions.map((o) => ({ value: o.value, label: o.label })));

/** A `sourceMap` arg's current record (defensive). */
function mapValue(): Record<string, string | number> {
  const raw = props.value;
  return raw && typeof raw === 'object' && !Array.isArray(raw) ? (raw as Record<string, string | number>) : {};
}
/** Set ONE option's mapped target value inside a `sourceMap` arg (clearing to '' deletes the key). */
function setMapEntry(option: string, value: string | number | null): void {
  const next = { ...mapValue() };
  if (value === '' || value == null) delete next[option];
  else next[option] = value;
  emit('update:value', next);
}

/** A `sourceOptions` arg's current value as a string[] (defensive). */
function optionsValue(): string[] {
  const raw = props.value;
  return Array.isArray(raw) && raw.every((v) => typeof v === 'string') ? (raw as string[]) : [];
}

/** A `choiceRules` arg's current value as a ChoiceRule[] (defensive). */
function rulesValue(): ChoiceRule[] {
  const raw = props.value;
  if (!Array.isArray(raw)) return [];
  return raw.filter((r): r is ChoiceRule => !!r && typeof r === 'object' && 'when' in r && 'then' in r);
}
/** Append an empty rule row. */
function addRule(): void {
  emit('update:value', [...rulesValue(), { when: '', then: '' }]);
}
/** Remove the rule row at `index`. */
function removeRule(index: number): void {
  emit(
    'update:value',
    rulesValue().filter((_, i) => i !== index),
  );
}
/** Patch ONE field of the rule row at `index`. */
function setRule(index: number, key: keyof ChoiceRule, value: string): void {
  emit(
    'update:value',
    rulesValue().map((rule, i) => (i === index ? { ...rule, [key]: value } : rule)),
  );
}
</script>

<template>
  <!-- VALUE controls (text / number / boolean / date) — the phase-4a set. -->
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

  <!-- select — one of the arg's OWN fixed options. -->
  <Select
    v-else-if="arg.type === 'select'"
    :model-value="String(value ?? '')"
    :options="(arg.options || []).map((o) => ({ value: o.value, label: o.label }))"
    :placeholder="arg.placeholder"
    :aria-label="arg.label"
    :disabled="disabled"
    @update:model-value="(v) => emit('update:value', (v as string) ?? '')"
  />
  <!-- sourceOption — ONE value picked from the SOURCE variable's options. -->
  <Select
    v-else-if="arg.type === 'sourceOption'"
    :model-value="String(value ?? '')"
    :options="sourceSelectOptions"
    :placeholder="arg.placeholder"
    :aria-label="arg.label"
    :disabled="disabled"
    @update:model-value="(v) => emit('update:value', (v as string) ?? '')"
  />
  <!-- sourceOptions — MANY values from the source options (selection cards). -->
  <SegmentedControl
    v-else-if="arg.type === 'sourceOptions'"
    :model-value="optionsValue()"
    :options="sourceSelectOptions"
    multiple
    select-all
    :columns="1"
    size="sm"
    :disabled="disabled"
    :aria-label="arg.label"
    @update:model-value="(v) => emit('update:value', (v as string[]) ?? [])"
  />
  <!-- sourceMap — ONE typed target value PER source option (option → value). -->
  <div
    v-else-if="arg.type === 'sourceMap'"
    class="flex flex-col gap-next-2 rounded-next-md border border-next-border bg-next-muted p-next-2"
  >
    <div
      v-for="option in sourceOptions"
      :key="option.value"
      class="grid grid-cols-[minmax(0,1fr)_auto_minmax(0,1.4fr)] items-center gap-next-2"
    >
      <span class="truncate text-next-sm text-next-fg" :title="option.label">{{ option.label }}</span>
      <Icon name="arrow-right" class="shrink-0 text-next-muted-foreground" aria-hidden="true" />
      <!-- enum — the mapped TARGET is one of the DESTINATION field's choices. -->
      <Select
        v-if="arg.mapType === 'enum'"
        :model-value="String(mapValue()[option.value] ?? '')"
        :options="targetSelectOptions"
        :placeholder="t('editor.pipeline.selectChoice', 'Select a choice')"
        :aria-label="`${arg.label}: ${option.label}`"
        :disabled="disabled"
        @update:model-value="(v) => setMapEntry(option.value, (v as string) ?? '')"
      />
      <NumberInput
        v-else-if="arg.mapType === 'number'"
        :model-value="mapValue()[option.value] == null || mapValue()[option.value] === '' ? null : Number(mapValue()[option.value])"
        :aria-label="`${arg.label}: ${option.label}`"
        :disabled="disabled"
        @update:model-value="(v: number | null) => setMapEntry(option.value, v)"
      />
      <div v-else-if="arg.mapType === 'date'" class="[&>div]:w-full">
        <DatePicker
          :model-value="typeof mapValue()[option.value] === 'string' && mapValue()[option.value] !== '' ? String(mapValue()[option.value]) : null"
          :aria-label="`${arg.label}: ${option.label}`"
          :disabled="disabled"
          @update:model-value="(v: string | null) => setMapEntry(option.value, v)"
        />
      </div>
      <TextInput
        v-else
        :model-value="String(mapValue()[option.value] ?? '')"
        :aria-label="`${arg.label}: ${option.label}`"
        :disabled="disabled"
        @update:model-value="(v: string) => setMapEntry(option.value, v)"
      />
    </div>
    <p v-if="!sourceOptions.length" class="text-next-xs text-next-muted-foreground">
      {{ t('editor.pipeline.noSourceOptions', 'This variable has no options to map.') }}
    </p>
  </div>
  <!-- choiceRules — a repeatable list of when (text) → then (target choice) rows. -->
  <div
    v-else-if="arg.type === 'choiceRules'"
    class="flex flex-col gap-next-2 rounded-next-md border border-next-border bg-next-muted p-next-2"
  >
    <div
      v-for="(rule, ruleIndex) in rulesValue()"
      :key="ruleIndex"
      class="grid grid-cols-[minmax(0,1fr)_auto_minmax(0,1.4fr)_auto] items-center gap-next-2"
    >
      <TextInput
        :model-value="rule.when"
        :placeholder="t('editor.pipeline.choiceWhen', 'When text is…')"
        :aria-label="t('editor.pipeline.choiceWhenLabel', 'Rule {index}: when', { index: ruleIndex + 1 })"
        :disabled="disabled"
        @update:model-value="(v: string) => setRule(ruleIndex, 'when', v)"
      />
      <Icon name="arrow-right" class="shrink-0 text-next-muted-foreground" aria-hidden="true" />
      <Select
        :model-value="rule.then"
        :options="targetSelectOptions"
        :placeholder="t('editor.pipeline.selectChoice', 'Select a choice')"
        :aria-label="t('editor.pipeline.choiceThenLabel', 'Rule {index}: then', { index: ruleIndex + 1 })"
        :disabled="disabled"
        @update:model-value="(v) => setRule(ruleIndex, 'then', (v as string) ?? '')"
      />
      <Button
        size="icon-xs"
        variant="ghost"
        type="button"
        :disabled="disabled"
        :aria-label="t('editor.pipeline.removeRule', 'Remove rule')"
        @click="removeRule(ruleIndex)"
      >
        <Icon name="x" />
      </Button>
    </div>
    <div>
      <Button size="sm" variant="secondary" type="button" leading-icon="plus" :disabled="disabled" @click="addRule">
        {{ t('editor.pipeline.addRule', 'Add rule') }}
      </Button>
    </div>
    <p class="text-next-xs text-next-muted-foreground">
      {{ t('editor.pipeline.choiceRulesHint', 'Unmatched text uses the fallback choice.') }}
    </p>
  </div>
  <!-- choiceFallback — a single required DESTINATION choice. -->
  <Select
    v-else-if="arg.type === 'choiceFallback'"
    :model-value="String(value ?? '')"
    :options="targetSelectOptions"
    :placeholder="t('editor.pipeline.selectChoice', 'Select a choice')"
    :aria-label="arg.label"
    :disabled="disabled"
    @update:model-value="(v) => emit('update:value', (v as string) ?? '')"
  />
</template>
