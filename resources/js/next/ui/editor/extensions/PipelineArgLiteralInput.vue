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
import ChoiceRuleWhenField from './ChoiceRuleWhenField.vue';
import TypedLiteralInput from '../../variables/TypedLiteralInput.vue';
import { buildDefaultArgs } from './operationHelpers';
import { useI18n, translate } from '../../../app/i18n';
import type {
  ArgEntryValue,
  ChoiceRule,
  ReduceSeedValue,
  VariableOperationArgumentDefinition,
  VariableOperationDefinition,
  VariableOption,
  VariablePrimitive,
} from './types';
import type { VariableLiteral, VariableLiteralBase } from '../../variables/types';

/** The WIRE pipeline step shape — what a `choiceRules` rule's `when` stores (same as every pipeline). */
type WireStep = { op: string; args: Record<string, unknown> };

/**
 * The literal an arg control emits. A STRUCTURAL container's ENTRIES may hold a value-or-variable union
 * (Defect-3) — `ArgEntryValue` — so the map record + the rule `then` are widened past bare scalars.
 */
type LiteralArgValue =
  | string
  | number
  | boolean
  | string[]
  | Record<string, ArgEntryValue>
  | ChoiceRule[]
  | WireStep[]
  | ReduceSeedValue;

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
    /**
     * The operations catalog — needed ONLY by the `choiceRules` control, whose per-rule `when` is a
     * boolean-terminal pipeline over TEXT (the LHS sub-editor resolves each wire step's output type
     * from here). Empty for every non-structural control (they never render a `when` pipeline).
     */
    catalog?: VariableOperationDefinition[];
    /** The ARG-VARIABLE nesting depth — forwarded to the `choiceRules` `when` sub-editor. */
    depth?: number;
    /** Disable the control (forwarded from a wrapping value-or-variable field). */
    disabled?: boolean;
  }>(),
  { sourceOptions: () => [], targetOptions: () => [], catalog: () => [], depth: 0, disabled: false },
);

/** The normalized literal to store — matches the original inline controls' emit exactly. */
const emit = defineEmits<{ 'update:value': [LiteralArgValue] }>();

const { t } = useI18n();

/** The source options mapped to Select / selection-card options. */
const sourceSelectOptions = computed(() => props.sourceOptions.map((o) => ({ value: o.value, label: o.label })));
/** The destination options mapped to Select options (choiceRules / Fallback + enum map). */
const targetSelectOptions = computed(() => props.targetOptions.map((o) => ({ value: o.value, label: o.label })));

/** A `sourceMap` arg's current record (defensive). An entry may be a scalar OR a value-or-variable union. */
function mapValue(): Record<string, ArgEntryValue> {
  const raw = props.value;
  return raw && typeof raw === 'object' && !Array.isArray(raw) ? (raw as Record<string, ArgEntryValue>) : {};
}
/**
 * Set ONE option's mapped target value inside a `sourceMap` arg. An empty LITERAL ('' / null) DELETES the
 * key (byte-identical to before); a non-empty scalar or a `{kind:'variable'}` union is stored (Defect-3).
 */
function setMapEntry(option: string, value: ArgEntryValue | null): void {
  const next = { ...mapValue() };
  if (value === '' || value == null) delete next[option];
  else next[option] = value;
  emit('update:value', next);
}

// --- Per-ENTRY value-or-variable descriptors (Defect-3) ----------------------
// When the `entry` slot is provided the host renders each structural ENTRY as its OWN value-or-variable
// field; this component supplies the SYNTHETIC leaf arg (drives the field's VALUE-mode control), the
// entry's target `resultTypes`, and the `targetOptions` a CHOICE entry maps into.

/** The map's per-entry target primitive (enum_to_choice → enum; else the flat mapType, default text). */
function mapEntryResultTypes(): VariablePrimitive[] {
  switch (props.arg.mapType) {
    case 'number':
      return ['number'];
    case 'date':
      return ['date'];
    case 'enum':
      return ['enum'];
    default:
      return ['text'];
  }
}

/** The synthetic leaf arg for ONE map option (its VALUE-mode control mirrors the inline map control). */
function mapEntryArg(option: VariableOption): VariableOperationArgumentDefinition {
  const type =
    props.arg.mapType === 'enum'
      ? 'choiceFallback'
      : props.arg.mapType === 'number'
        ? 'number'
        : props.arg.mapType === 'date'
          ? 'date'
          : 'text';
  return { id: `${props.arg.id}.${option.value}`, label: `${props.arg.label}: ${option.label}`, type };
}

/** The synthetic leaf arg for ONE rule's `then` (a destination-choice Select over `targetOptions`). */
function ruleThenArg(index: number): VariableOperationArgumentDefinition {
  return {
    id: `${props.arg.id}.${index}.then`,
    label: t('editor.pipeline.choiceThenLabel', 'Rule {index}: then', { index: index + 1 }),
    type: 'choiceFallback',
  };
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
/**
 * A rule's `when` as a WIRE pipeline (defensive — a legacy string `when` degrades to an empty
 * pipeline the author rebuilds; there is no free-text equality any more).
 */
function whenWire(rule: ChoiceRule): WireStep[] {
  return Array.isArray(rule.when) ? (rule.when as WireStep[]) : [];
}
/**
 * Seed a NEW rule's `when` with a single `text_equals` step (empty value) — so the common
 * "gdy wejście == X" case stays ONE step and reads like the old free-text equality; the author can
 * then change the op or extend the chain toward a boolean terminal.
 */
function seedWhen(): WireStep[] {
  const def = props.catalog.find((op) => op.id === 'text_equals');
  return [{ op: 'text_equals', args: def ? buildDefaultArgs(def.args) : { value: '' } }];
}
/** Append a rule row seeded with a `text_equals` `when` + an empty `then`. */
function addRule(): void {
  emit('update:value', [...rulesValue(), { when: seedWhen(), then: '' }]);
}
/** Remove the rule row at `index`. */
function removeRule(index: number): void {
  emit(
    'update:value',
    rulesValue().filter((_, i) => i !== index),
  );
}
/** Patch a rule's `then` (a value-or-variable entry — Defect-3). */
function setRule(index: number, key: 'then', value: ArgEntryValue): void {
  emit(
    'update:value',
    rulesValue().map((rule, i) => (i === index ? { ...rule, [key]: value } : rule)),
  );
}
/** Replace a rule's `when` with a new WIRE boolean-terminal pipeline (from the LHS sub-editor). */
function setRuleWhen(index: number, wire: WireStep[]): void {
  emit(
    'update:value',
    rulesValue().map((rule, i) => (i === index ? { ...rule, when: wire } : rule)),
  );
}

// --- elementPipeline (array-transform wave 2) --------------------------------
// An `array_map` / `array_filter` / `array_sort` pipeline arg (or `array_reduce`'s reducer):
// the WIRE `{op,args}[]` per-element pipeline. The wire↔editor projection reuses ChoiceRuleWhenField
// (a generic projector); the nested pipeline editor itself — rooted at the ELEMENT type with the
// synthetic scope variables + terminal gating — is supplied by the host through the `#elementPipeline`
// slot (so this component keeps NO dependency on the pipeline editor, avoiding an import cycle).

/** The arg's current value as a WIRE element pipeline (defensive). */
function elementPipelineWire(): WireStep[] {
  return Array.isArray(props.value) ? (props.value as WireStep[]) : [];
}

// --- reduceSeed (array-transform wave 2) -------------------------------------
// The `array_reduce` accumulator seed: a typed literal `{type, value}` (type ∈ text/number/boolean/
// date). REUSES the shared TypedLiteralInput for the value; a type Select roots the sibling reducer.
const SEED_BASES: VariableLiteralBase[] = ['text', 'number', 'boolean', 'date'];
const seedTypeOptions = computed(() =>
  SEED_BASES.map((base) => ({
    value: base,
    label: translate(`editor.pipeline.seedType_${base}`, base),
  })),
);

/** The seed's current `{type, value}` (defensive; defaults to a numeric 0). */
function seedValue(): ReduceSeedValue {
  const raw = props.value;
  if (raw && typeof raw === 'object' && !Array.isArray(raw) && 'type' in raw) {
    const s = raw as { type?: string; value?: unknown };
    const type = (SEED_BASES.includes(s.type as VariableLiteralBase) ? s.type : 'number') as ReduceSeedValue['type'];
    return { type, value: (s.value ?? null) as VariableLiteral };
  }
  return { type: 'number', value: 0 };
}
/** A sensible empty value when the seed type changes (keeps the emitted literal typed). */
function defaultSeedValueFor(type: ReduceSeedValue['type']): VariableLiteral {
  if (type === 'number') return 0;
  if (type === 'boolean') return false;
  return '';
}
function setSeedType(type: string): void {
  const next = (SEED_BASES.includes(type as VariableLiteralBase) ? type : 'number') as ReduceSeedValue['type'];
  emit('update:value', { type: next, value: defaultSeedValueFor(next) });
}
function setSeedValue(value: unknown): void {
  emit('update:value', { type: seedValue().type, value: value as VariableLiteral });
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
      <!-- Defect-3: when the host provides the `entry` slot, THIS option's target is its OWN value-or-
           variable (typed to the map's target type; a CHOICE map threads targetOptions). Else the inline
           literal control renders, BYTE-IDENTICAL to before (conditions / markdown / depth cap). -->
      <slot
        v-if="$slots.entry"
        name="entry"
        :entry-arg="mapEntryArg(option)"
        :value="mapValue()[option.value] ?? null"
        :set-value="(v: ArgEntryValue | null) => setMapEntry(option.value, v)"
        :result-types="mapEntryResultTypes()"
        :target-options="arg.mapType === 'enum' ? targetOptions : []"
        :source-options="sourceOptions"
        :disabled="disabled"
      />
      <template v-else>
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
      </template>
    </div>
    <p v-if="!sourceOptions.length" class="text-next-xs text-next-muted-foreground">
      {{ t('editor.pipeline.noSourceOptions', 'This variable has no options to map.') }}
    </p>
  </div>
  <!-- choiceRules — a repeatable list of `when` (boolean-terminal CONDITION pipeline over the op's own
       TEXT input) → `then` (target choice) rules. The `when` LHS is no longer a free-text equality: it
       is the SAME "pipeline over a value → boolean" builder the if-block condition uses, rooted at TEXT
       with NO variable picker (the running value IS the match_to_choice input) — provided by the parent
       through the `#when` slot so this component keeps no dependency on the pipeline editor. -->
  <div
    v-else-if="arg.type === 'choiceRules'"
    class="flex flex-col gap-next-3 rounded-next-md border border-next-border bg-next-muted p-next-2"
  >
    <div
      v-for="(rule, ruleIndex) in rulesValue()"
      :key="ruleIndex"
      class="flex flex-col gap-next-2 rounded-next-md border border-next-border bg-next-card p-next-2"
    >
      <div class="flex items-center justify-between gap-next-2">
        <span class="text-next-xs font-next-medium text-next-muted-foreground">
          {{ t('editor.pipeline.choiceWhen', 'When condition…') }}
        </span>
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
      <!-- LHS: the boolean-terminal condition pipeline over TEXT (host-provided nested editor). -->
      <div
        :aria-label="t('editor.pipeline.choiceWhenLabel', 'Rule {index}: when', { index: ruleIndex + 1 })"
        role="group"
      >
        <ChoiceRuleWhenField
          :model-value="whenWire(rule)"
          :catalog="catalog"
          @update:model-value="(wire) => setRuleWhen(ruleIndex, wire)"
        >
          <template #default="{ steps, onSteps }">
            <slot
              v-if="$slots.when"
              name="when"
              :steps="steps"
              :on-steps="onSteps"
              :depth="depth"
              :index="ruleIndex"
            />
            <p v-else class="text-next-xs text-next-muted-foreground">
              {{ t('editor.pipeline.choiceWhenUnavailable', 'The condition builder is unavailable here.') }}
            </p>
          </template>
        </ChoiceRuleWhenField>
      </div>
      <!-- RHS (`then`): the destination choice, UNCHANGED. Defect-3: its OWN value-or-variable entry
           when the host provides the `entry` slot; else the inline destination-choice Select. -->
      <div class="flex items-center gap-next-2">
        <Icon name="arrow-right" class="shrink-0 text-next-muted-foreground" aria-hidden="true" />
        <span class="text-next-xs font-next-medium text-next-muted-foreground shrink-0">
          {{ t('editor.pipeline.choiceThen', 'then') }}
        </span>
        <div class="min-w-0 flex-1">
          <slot
            v-if="$slots.entry"
            name="entry"
            :entry-arg="ruleThenArg(ruleIndex)"
            :value="rule.then ?? null"
            :set-value="(v: ArgEntryValue | null) => setRule(ruleIndex, 'then', (v as ArgEntryValue) ?? '')"
            :result-types="['enum']"
            :target-options="targetOptions"
            :source-options="sourceOptions"
            :disabled="disabled"
          />
          <Select
            v-else
            :model-value="typeof rule.then === 'string' ? rule.then : ''"
            :options="targetSelectOptions"
            :placeholder="t('editor.pipeline.selectChoice', 'Select a choice')"
            :aria-label="t('editor.pipeline.choiceThenLabel', 'Rule {index}: then', { index: ruleIndex + 1 })"
            :disabled="disabled"
            @update:model-value="(v) => setRule(ruleIndex, 'then', (v as string) ?? '')"
          />
        </div>
      </div>
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
  <!-- elementPipeline (array-transform wave 2) — a per-element `{op,args}[]` pipeline. The
       wire↔editor projection reuses ChoiceRuleWhenField; the nested pipeline editor (rooted at the
       ELEMENT type, fed the synthetic Element/Indeks scope variables, terminal-gated per host op) is
       provided by the parent through the `#elementPipeline` slot, so this component keeps no
       dependency on the pipeline editor (mirrors the `#when` pattern). -->
  <div v-else-if="arg.type === 'elementPipeline'" role="group" :aria-label="arg.label">
    <ChoiceRuleWhenField
      :model-value="elementPipelineWire()"
      :catalog="catalog"
      @update:model-value="(wire) => emit('update:value', wire)"
    >
      <template #default="{ steps, onSteps }">
        <slot
          v-if="$slots.elementPipeline"
          name="elementPipeline"
          :steps="steps"
          :on-steps="onSteps"
          :depth="depth"
        />
        <p v-else class="text-next-xs text-next-muted-foreground">
          {{ t('editor.pipeline.elementPipelineUnavailable', 'The item pipeline builder is unavailable here.') }}
        </p>
      </template>
    </ChoiceRuleWhenField>
  </div>

  <!-- reduceSeed (array-transform wave 2) — the accumulator seed: a typed literal `{type, value}`.
       A type Select roots the sibling reducer's required terminal; the value reuses TypedLiteralInput. -->
  <div v-else-if="arg.type === 'reduceSeed'" class="flex flex-wrap items-center gap-next-2">
    <div class="w-36">
      <Select
        :model-value="seedValue().type"
        :options="seedTypeOptions"
        :aria-label="t('editor.pipeline.seedTypeLabel', 'Start value type')"
        :disabled="disabled"
        @update:model-value="(v) => setSeedType(v as string)"
      />
    </div>
    <div class="min-w-0 flex-1">
      <TypedLiteralInput
        :model-value="seedValue().value"
        :base="seedValue().type"
        :disabled="disabled"
        :aria-label="arg.label"
        @update:model-value="setSeedValue"
      />
    </div>
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
