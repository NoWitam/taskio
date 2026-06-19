<script setup lang="ts">
// Slider — value selector for the "next" frontend, single value or range.
//
// Each thumb is a focusable element with role="slider" and
// aria-valuemin/max/now (+ aria-label). The fill bar between the rail ends (or
// between the two thumbs in range mode) shows the selected span. Optional tick
// marks and a value label/tooltip above the active thumb.
//
// v-model is `number` in single mode and `[number, number]` in range mode
// (toggle with the `range` prop). Pointer drag updates the nearest thumb;
// keyboard: ←/→ step, Home/End jump to min/max, PageUp/PageDown larger step.
//
// An integrated, compact "type-a-number" field sits inline with the track so the
// value can be entered directly (like a budget input). It stays in sync with the
// thumb(s) and respects min/max/step. In range mode there are two boxes (low/high)
// that keep their order. Disable it with `:numberField="false"`.
import { computed, onBeforeUnmount, ref } from 'vue';
import { useFormField } from './formField';

const props = withDefaults(
  defineProps<{
    min?: number;
    max?: number;
    step?: number;
    /** Two-thumb range mode (model is [number, number]). */
    range?: boolean;
    /** Render tick marks at each step (or at provided values). */
    ticks?: boolean | number[];
    /** Larger step for PageUp/PageDown (defaults to 10× step). */
    pageStep?: number;
    /** Show a value label above the active thumb. */
    showValue?: boolean;
    /** Show the integrated inline number field(s) aligned with the track. */
    numberField?: boolean;
    /** Prefix shown inside the number field (e.g. "$"). */
    prefix?: string;
    disabled?: boolean;
    readonly?: boolean;
    ariaInvalid?: boolean;
    /** Accessible label(s) for the thumb(s). */
    ariaLabel?: string;
    ariaLabelMin?: string;
    ariaLabelMax?: string;
  }>(),
  {
    min: 0,
    max: 100,
    step: 1,
    range: false,
    ticks: false,
    showValue: false,
    numberField: false,
    disabled: false,
    readonly: false,
  },
);

// Single OR range model. Defaults chosen to be valid for either mode.
const model = defineModel<number | [number, number]>({ default: 0 });

const field = useFormField();
const disabled = computed(() => props.disabled || (field?.disabled.value ?? false));
const readonly = computed(() => props.readonly || (field?.readonly.value ?? false));
const invalid = computed(() => props.ariaInvalid ?? field?.invalid.value ?? false);
const describedBy = computed(() => field?.describedById.value);

if (field?.registerValue) {
  const dispose = field.registerValue(() => model.value);
  onBeforeUnmount(dispose);
}

const railRef = ref<HTMLElement | null>(null);

// Normalised pair [low, high]; single mode mirrors a single value into low.
const values = computed<[number, number]>(() => {
  if (props.range && Array.isArray(model.value)) {
    return [model.value[0], model.value[1]];
  }
  const v = Array.isArray(model.value) ? model.value[0] : model.value;
  return [props.min, v];
});

function clamp(v: number): number {
  return Math.min(props.max, Math.max(props.min, v));
}
function snap(v: number): number {
  const snapped = Math.round((v - props.min) / props.step) * props.step + props.min;
  return clamp(Number(snapped.toFixed(6)));
}
function pct(v: number): number {
  return ((v - props.min) / (props.max - props.min)) * 100;
}

function setValue(which: 0 | 1, raw: number): void {
  if (readonly.value) return;
  const v = snap(raw);
  if (props.range && Array.isArray(model.value)) {
    const pair: [number, number] = [model.value[0], model.value[1]];
    pair[which] = v;
    // Keep ordering: low <= high.
    if (pair[0] > pair[1]) {
      if (which === 0) pair[0] = pair[1];
      else pair[1] = pair[0];
    }
    model.value = pair;
  } else {
    model.value = v;
  }
}

// Fill bar geometry.
const fillStyle = computed(() => {
  if (props.range) {
    return { left: `${pct(values.value[0])}%`, right: `${100 - pct(values.value[1])}%` };
  }
  return { left: '0%', right: `${100 - pct(values.value[1])}%` };
});

const tickValues = computed<number[]>(() => {
  if (Array.isArray(props.ticks)) return props.ticks;
  if (!props.ticks) return [];
  const out: number[] = [];
  for (let v = props.min; v <= props.max; v += props.step) out.push(Number(v.toFixed(6)));
  return out;
});

function onKeydown(event: KeyboardEvent, which: 0 | 1): void {
  if (disabled.value) return;
  const current = which === 0 ? values.value[0] : values.value[1];
  const big = props.pageStep ?? props.step * 10;
  let next: number | undefined;
  switch (event.key) {
    case 'ArrowRight':
    case 'ArrowUp':
      next = current + props.step;
      break;
    case 'ArrowLeft':
    case 'ArrowDown':
      next = current - props.step;
      break;
    case 'PageUp':
      next = current + big;
      break;
    case 'PageDown':
      next = current - big;
      break;
    case 'Home':
      next = props.min;
      break;
    case 'End':
      next = props.max;
      break;
    default:
      return;
  }
  event.preventDefault();
  setValue(which, next);
}

// Pointer drag: move the thumb closest to the pointer.
let dragging: 0 | 1 | null = null;
function valueFromPointer(clientX: number): number {
  const rail = railRef.value;
  if (!rail) return props.min;
  const rect = rail.getBoundingClientRect();
  const ratio = Math.min(1, Math.max(0, (clientX - rect.left) / rect.width));
  return props.min + ratio * (props.max - props.min);
}
function onPointerDown(event: PointerEvent): void {
  if (disabled.value) return;
  const v = valueFromPointer(event.clientX);
  // Pick nearest thumb in range mode.
  if (props.range) {
    dragging =
      Math.abs(v - values.value[0]) <= Math.abs(v - values.value[1]) ? 0 : 1;
  } else {
    dragging = 1;
  }
  setValue(dragging, v);
  window.addEventListener('pointermove', onPointerMove);
  window.addEventListener('pointerup', onPointerUp);
}
function onPointerMove(event: PointerEvent): void {
  if (dragging === null) return;
  setValue(dragging, valueFromPointer(event.clientX));
}
function onPointerUp(): void {
  dragging = null;
  window.removeEventListener('pointermove', onPointerMove);
  window.removeEventListener('pointerup', onPointerUp);
}

const thumbs = computed(() =>
  props.range
    ? ([
        { which: 0 as const, value: values.value[0], label: props.ariaLabelMin ?? 'Minimum' },
        { which: 1 as const, value: values.value[1], label: props.ariaLabelMax ?? 'Maximum' },
      ])
    : [{ which: 1 as const, value: values.value[1], label: props.ariaLabel ?? 'Value' }],
);

// Inline number field(s): one in single mode, two (low/high) in range mode. Each
// commits through `setValue` so it snaps, clamps, and keeps low<=high in sync with
// the thumbs. We keep `aria-label` distinct from the thumb so SR users hear it as
// a separate way to enter the same value.
const numberBoxes = computed(() =>
  props.range
    ? ([
        { which: 0 as const, value: values.value[0], label: props.ariaLabelMin ?? 'Minimum value' },
        { which: 1 as const, value: values.value[1], label: props.ariaLabelMax ?? 'Maximum value' },
      ])
    : [{ which: 1 as const, value: values.value[1], label: props.ariaLabel ?? 'Value' }],
);

function commitNumber(which: 0 | 1, raw: string): void {
  if (raw === '') return;
  const n = Number(raw);
  if (Number.isNaN(n)) return;
  setValue(which, n);
}

// Roomy enough for the widest value (max with optional prefix), so the box width
// is stable and never resizes as you type.
const numberFieldWidth = computed(() => {
  const longest = Math.max(String(props.min).length, String(props.max).length);
  return `${longest + (props.prefix ? props.prefix.length : 0) + 2}ch`;
});
</script>

<template>
  <div
    class="flex w-full items-center gap-next-3 py-next-2"
    :class="disabled ? 'opacity-60' : ''"
  >
    <!-- Range low box sits before the track. -->
    <label
      v-if="numberField && range"
      class="next-slider-num"
      :class="invalid ? 'is-invalid' : ''"
      :style="{ width: numberFieldWidth }"
    >
      <span v-if="prefix" class="next-slider-num__prefix">{{ prefix }}</span>
      <input
        type="number"
        :value="numberBoxes[0].value"
        :min="min"
        :max="max"
        :step="step"
        :disabled="disabled"
        :readonly="readonly"
        :aria-label="numberBoxes[0].label"
        @change="commitNumber(0, ($event.target as HTMLInputElement).value)"
      />
    </label>

    <div
      ref="railRef"
      class="relative h-1.5 flex-1 rounded-next-full bg-next-muted"
      :class="disabled || readonly ? 'cursor-not-allowed' : 'cursor-pointer'"
      @pointerdown="onPointerDown"
    >
      <!-- Fill -->
      <div
        class="absolute top-0 bottom-0 rounded-next-full"
        :class="invalid ? 'bg-next-danger' : 'bg-next-primary'"
        :style="fillStyle"
      />

      <!-- Ticks -->
      <span
        v-for="t in tickValues"
        :key="t"
        class="absolute top-1/2 h-1.5 w-px -translate-y-1/2 bg-next-fg/30"
        :style="{ left: `${pct(t)}%` }"
        aria-hidden="true"
      />

      <!-- Thumbs -->
      <button
        v-for="thumb in thumbs"
        :key="thumb.which"
        type="button"
        role="slider"
        class="absolute top-1/2 h-4 w-4 -translate-x-1/2 -translate-y-1/2 rounded-next-full border-2 bg-next-card shadow-next-sm transition-colors duration-[var(--duration-next-fast)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-next-ring"
        :class="[
          invalid ? 'border-next-danger' : 'border-next-primary',
          disabled ? 'cursor-not-allowed' : 'cursor-grab active:cursor-grabbing',
        ]"
        :style="{ left: `${pct(thumb.value)}%` }"
        :aria-valuemin="min"
        :aria-valuemax="max"
        :aria-valuenow="thumb.value"
        :aria-label="thumb.label"
        :aria-invalid="invalid ? 'true' : undefined"
        :aria-describedby="describedBy"
        :aria-disabled="disabled ? 'true' : undefined"
        :tabindex="disabled ? -1 : 0"
        @keydown="onKeydown($event, thumb.which)"
      >
        <span
          v-if="showValue"
          class="pointer-events-none absolute bottom-[calc(100%+6px)] left-1/2 -translate-x-1/2 rounded-next-sm bg-next-fg px-next-1_5 py-next-0_5 text-next-2xs font-next-medium text-next-bg"
        >
          {{ thumb.value }}
        </span>
      </button>
    </div>

    <!-- Single value box, or range high box, sits after the track. -->
    <label
      v-if="numberField && !range"
      class="next-slider-num"
      :class="invalid ? 'is-invalid' : ''"
      :style="{ width: numberFieldWidth }"
    >
      <span v-if="prefix" class="next-slider-num__prefix">{{ prefix }}</span>
      <input
        type="number"
        :value="numberBoxes[0].value"
        :min="min"
        :max="max"
        :step="step"
        :disabled="disabled"
        :readonly="readonly"
        :aria-label="numberBoxes[0].label"
        @change="commitNumber(numberBoxes[0].which, ($event.target as HTMLInputElement).value)"
      />
    </label>
    <label
      v-else-if="numberField && range"
      class="next-slider-num"
      :class="invalid ? 'is-invalid' : ''"
      :style="{ width: numberFieldWidth }"
    >
      <span v-if="prefix" class="next-slider-num__prefix">{{ prefix }}</span>
      <input
        type="number"
        :value="numberBoxes[1].value"
        :min="min"
        :max="max"
        :step="step"
        :disabled="disabled"
        :readonly="readonly"
        :aria-label="numberBoxes[1].label"
        @change="commitNumber(1, ($event.target as HTMLInputElement).value)"
      />
    </label>
  </div>
</template>

<style scoped>
/* Compact inline number box, visually fitted to the slider track height. Uses the
   same bordered surface language as the field family; width is fixed (set inline)
   so it never resizes as you type. */
.next-slider-num {
  display: inline-flex;
  align-items: center;
  flex-shrink: 0;
  height: var(--spacing-next-8); /* 32px — matches the sm control height */
  padding-inline: var(--spacing-next-2);
  border-radius: var(--radius-next-md);
  border: 1px solid var(--color-next-input);
  background-color: var(--color-next-card);
  color: var(--color-next-fg);
  font-size: var(--text-next-sm);
  transition:
    border-color var(--duration-next-fast) var(--ease-next-standard),
    box-shadow var(--duration-next-fast) var(--ease-next-standard);
}
.next-slider-num:focus-within {
  border-color: var(--color-next-ring);
  box-shadow: inset 0 0 0 1.5px var(--color-next-ring);
}
.next-slider-num.is-invalid {
  border-color: var(--color-next-danger);
}
.next-slider-num.is-invalid:focus-within {
  border-color: var(--color-next-danger);
  box-shadow: inset 0 0 0 1.5px var(--color-next-danger);
}
.next-slider-num__prefix {
  margin-inline-end: var(--spacing-next-0_5);
  color: var(--color-next-muted-foreground);
}
.next-slider-num input {
  width: 100%;
  min-width: 0;
  border: 0;
  background: transparent;
  color: inherit;
  outline: none;
  text-align: right;
  font-variant-numeric: tabular-nums;
}
.next-slider-num input::-webkit-outer-spin-button,
.next-slider-num input::-webkit-inner-spin-button {
  -webkit-appearance: none;
  margin: 0;
}
.next-slider-num input {
  -moz-appearance: textfield;
  appearance: textfield;
}
.next-slider-num input:disabled {
  cursor: not-allowed;
}
</style>
