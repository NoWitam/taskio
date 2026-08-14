<script setup lang="ts">
// NumberInput — numeric entry for the "next" frontend.
//
// Renders THROUGH FieldShell (shared border + state line). The previous large
// full-height +/- buttons are gone: stepping is now a SUBTLE, compact pair of
// stacked chevrons in the trailing area (tiny up/down), and is OPTIONAL via
// `:steppers="false"`. The default look is a clean text field, not a control
// dominated by buttons.
//
// A spinbutton: the inner <input type="number"> exposes aria-valuenow/min/max; the
// chevrons and ↑/↓ step by `step`. prefix/suffix render as adornments. Out-of-range
// typing keeps its value but flags aria-invalid (+ FieldShell error line) and can
// clamp on blur. v-model is `number | null` (null = empty). Consumes a FormField
// or works standalone.
import { computed, onBeforeUnmount } from 'vue';
import Icon from '../primitives/Icon.vue';
import FieldShell from './FieldShell.vue';
import { useFormField } from './formField';
import { FIELD_PADDING_X, type ControlSize } from './fieldShell';

const props = withDefaults(
  defineProps<{
    min?: number;
    max?: number;
    step?: number;
    size?: ControlSize;
    placeholder?: string;
    disabled?: boolean;
    readonly?: boolean;
    /** Show the compact stacked-chevron stepper (set false for a bare field). */
    steppers?: boolean;
    /** Text shown before the value (e.g. "$"). */
    prefix?: string;
    /** Text shown after the value (e.g. "kg"). */
    suffix?: string;
    /** Clamp into [min,max] when the field loses focus. */
    clampOnBlur?: boolean;
    ariaInvalid?: boolean;
    /** Force success styling standalone (FormField sets this for you). */
    success?: boolean;
    /** Force the subtle "dirty" accent standalone (FormField tracks this). */
    dirty?: boolean;
    id?: string;
    describedById?: string;
    name?: string;
    ariaLabel?: string;
  }>(),
  {
    // Absence must stay `undefined` (no Boolean cast to `false`) so it defers
    // to the surrounding FormField — see `formField.ts`.
    ariaInvalid: undefined,
    step: 1,
    size: 'md',
    disabled: false,
    readonly: false,
    steppers: true,
    clampOnBlur: false,
    success: false,
    dirty: false,
  },
);

const model = defineModel<number | null>({ default: null });

const field = useFormField();
const resolvedId = computed(() => props.id ?? field?.id.value);
const resolvedDescribedBy = computed(
  () => props.describedById ?? field?.describedById.value,
);
const fieldInvalid = computed(() => props.ariaInvalid ?? field?.invalid.value ?? false);
const fieldSuccess = computed(() => props.success || (field?.valid.value ?? false));
const dirty = computed(() => props.dirty || (field?.dirty.value ?? false));
const disabled = computed(() => props.disabled || (field?.disabled.value ?? false));
const readonly = computed(() => props.readonly || (field?.readonly.value ?? false));
const required = computed(() => field?.required.value ?? false);

if (field?.registerValue) {
  const dispose = field.registerValue(() => model.value);
  onBeforeUnmount(dispose);
}

const outOfRange = computed(() => {
  if (model.value == null) return false;
  if (props.min != null && model.value < props.min) return true;
  if (props.max != null && model.value > props.max) return true;
  return false;
});
const invalid = computed(() => fieldInvalid.value || outOfRange.value);
// Don't show a success line while the value is out of range.
const success = computed(() => fieldSuccess.value && !outOfRange.value);

const atMin = computed(
  () => props.min != null && model.value != null && model.value <= props.min,
);
const atMax = computed(
  () => props.max != null && model.value != null && model.value >= props.max,
);

function clamp(v: number): number {
  let out = v;
  if (props.min != null) out = Math.max(props.min, out);
  if (props.max != null) out = Math.min(props.max, out);
  return out;
}

function stepBy(dir: 1 | -1): void {
  if (disabled.value || readonly.value) return;
  const base =
    model.value ??
    (dir === 1 ? (props.min ?? 0) - props.step : (props.max ?? 0) + props.step);
  model.value = clamp(base + dir * props.step);
}

function onInput(event: Event): void {
  const raw = (event.target as HTMLInputElement).value;
  model.value = raw === '' ? null : Number(raw);
}

function onBlur(): void {
  if (props.clampOnBlur && model.value != null) {
    model.value = clamp(model.value);
  }
}

const showSteppers = computed(
  () => props.steppers && !disabled.value && !readonly.value,
);

// Drop the input's right padding when steppers/suffix occupy the trailing area.
const inputPadding = computed(() => {
  const l = props.prefix ? 'pl-next-1' : FIELD_PADDING_X[props.size];
  const r = props.suffix || showSteppers.value ? 'pr-next-1' : FIELD_PADDING_X[props.size];
  return [l, r];
});

const chevronBtn =
  'flex h-3.5 w-4 items-center justify-center rounded-next-xs text-[0.6rem] ' +
  'text-next-muted-foreground hover:text-next-fg hover:bg-next-accent ' +
  'disabled:cursor-not-allowed disabled:opacity-30 disabled:hover:bg-transparent ' +
  'transition-colors duration-[var(--duration-next-fast)]';
</script>

<template>
  <FieldShell
    :size="size"
    :disabled="disabled"
    :readonly="readonly"
    :error="invalid"
    :success="success"
    :dirty="dirty"
  >
    <template v-if="prefix" #leading>
      <span class="text-next-muted-foreground">{{ prefix }}</span>
    </template>

    <input
      :id="resolvedId"
      type="number"
      inputmode="decimal"
      :value="model ?? ''"
      :min="min"
      :max="max"
      :step="step"
      :name="name"
      :placeholder="placeholder"
      :disabled="disabled"
      :readonly="readonly"
      role="spinbutton"
      :aria-valuenow="model ?? undefined"
      :aria-valuemin="min"
      :aria-valuemax="max"
      :aria-invalid="invalid ? 'true' : undefined"
      :aria-describedby="resolvedDescribedBy"
      :aria-required="required ? 'true' : undefined"
      :aria-label="ariaLabel"
      class="next-number-input h-full w-full min-w-0 flex-1 truncate border-0 bg-transparent text-current outline-none placeholder:text-next-muted-foreground disabled:cursor-not-allowed"
      :class="inputPadding"
      @input="onInput"
      @blur="onBlur"
    />

    <template v-if="suffix || showSteppers" #trailing>
      <span class="flex items-center gap-next-1_5">
        <span v-if="suffix" class="text-next-muted-foreground">{{ suffix }}</span>

        <!-- Compact stacked-chevron stepper. tabindex=-1: the field is the tab
             stop and exposes ↑/↓; these are a pointer convenience. -->
        <span v-if="showSteppers" class="flex flex-col">
          <button
            type="button"
            :class="chevronBtn"
            :disabled="atMax"
            aria-label="Increment"
            tabindex="-1"
            @click="stepBy(1)"
          >
            <Icon name="chevron-up" />
          </button>
          <button
            type="button"
            :class="chevronBtn"
            :disabled="atMin"
            aria-label="Decrement"
            tabindex="-1"
            @click="stepBy(-1)"
          >
            <Icon name="chevron-down" />
          </button>
        </span>
      </span>
    </template>
  </FieldShell>
</template>

<style scoped>
/* Hide the native number spinners; our chevron stepper replaces them. */
.next-number-input::-webkit-outer-spin-button,
.next-number-input::-webkit-inner-spin-button {
  -webkit-appearance: none;
  margin: 0;
}
.next-number-input {
  -moz-appearance: textfield;
  appearance: textfield;
}
</style>
