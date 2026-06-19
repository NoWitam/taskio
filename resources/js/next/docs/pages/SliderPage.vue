<script setup lang="ts">
import { ref } from 'vue';
import Slider from '../../ui/forms/Slider.vue';
import FormField from '../../ui/forms/FormField.vue';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const single = ref(40);
const withValue = ref(65);
const range = ref<[number, number]>([20, 70]);
const ticked = ref(60);
const stepped = ref(2);
const formBudget = ref(500);
const numBudget = ref(750);
const numRange = ref<[number, number]>([200, 1400]);

const propRows: ApiRow[] = [
  { name: 'v-model', type: 'number | [number, number]', default: '0', description: 'Single value, or a [low, high] pair in range mode.' },
  { name: 'min / max', type: 'number', default: '0 / 100', description: 'Bounds.' },
  { name: 'step', type: 'number', default: '1', description: 'Increment for arrow keys + snapping.' },
  { name: 'pageStep', type: 'number', default: 'step × 10', description: 'PageUp/PageDown increment.' },
  { name: 'range', type: 'boolean', default: 'false', description: 'Two-thumb range mode.' },
  { name: 'ticks', type: 'boolean | number[]', default: 'false', description: 'Tick marks at each step, or at the given values.' },
  { name: 'showValue', type: 'boolean', default: 'false', description: 'Value label above the active thumb.' },
  { name: 'numberField', type: 'boolean', default: 'false', description: 'Integrated inline number box(es) aligned with the track.' },
  { name: 'prefix', type: 'string', default: '—', description: 'Prefix inside the number box (e.g. "$").' },
  { name: 'disabled', type: 'boolean', default: 'false', description: 'Disables the control (also inherited from FormField).' },
  { name: 'readonly', type: 'boolean', default: 'false', description: 'Read-only; thumbs + number box don’t change the value.' },
  { name: 'ariaLabel / ariaLabelMin / ariaLabelMax', type: 'string', default: '—', description: 'Accessible names per thumb.' },
];
</script>

<template>
  <StoryPage
    title="Slider"
    description="Value selector — single value or a two-thumb range, with optional tick marks, a value label, and an integrated inline number box for typing the value directly. Each thumb is a role=slider with full keyboard support; the rail is also draggable with a pointer."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>Each thumb is <code>role="slider"</code> with <code>aria-valuemin/max/now</code> and an <code>aria-label</code>.</li>
        <li>Keyboard: <kbd>←</kbd>/<kbd>→</kbd> (and <kbd>↑</kbd>/<kbd>↓</kbd>) step, <kbd>PageUp</kbd>/<kbd>PageDown</kbd> larger step, <kbd>Home</kbd>/<kbd>End</kbd> jump to min/max.</li>
        <li>Range thumbs keep order (low ≤ high). Disabled thumbs leave the tab order; error swaps the fill + thumb to danger.</li>
        <li>The inline number box has its own <code>aria-label</code> and commits on <kbd>change</kbd>; it snaps to <code>step</code> and clamps to <code>[min,max]</code>, staying in sync with the thumb.</li>
      </ul>
    </template>

    <StorySection title="Single value">
      <div class="max-w-md"><Slider v-model="single" aria-label="Volume" /></div>
    </StorySection>

    <StorySection title="With value label" description="showValue floats the current value above the thumb.">
      <div class="max-w-md pt-next-6"><Slider v-model="withValue" show-value aria-label="Opacity" /></div>
    </StorySection>

    <StorySection title="Range (two thumbs)" description="Model is a [low, high] pair; thumbs can’t cross.">
      <div class="max-w-md pt-next-6"><Slider v-model="range" range show-value aria-label-min="Min price" aria-label-max="Max price" /></div>
      <p class="mt-next-3 text-next-sm text-next-muted-foreground">Selected: {{ range[0] }} – {{ range[1] }}</p>
    </StorySection>

    <StorySection title="Ticks & custom step" description="Tick marks + a larger step.">
      <div class="flex flex-col gap-next-8">
        <div class="max-w-md"><Slider v-model="ticked" :step="20" ticks show-value aria-label="With ticks" /></div>
        <div class="max-w-md"><Slider v-model="stepped" :min="0" :max="10" :step="1" :ticks="[0, 5, 10]" show-value aria-label="0 to 10" /></div>
      </div>
    </StorySection>

    <StorySection title="Inline number field" description="An integrated, fixed-width number box aligned with the track lets the value be typed directly — kept in sync with the thumb, snapped + clamped to min/max/step.">
      <div class="flex flex-col gap-next-6">
        <div class="max-w-md">
          <Slider v-model="numBudget" :min="0" :max="2000" :step="50" number-field prefix="$" aria-label="Budget" />
          <p class="mt-next-2 text-next-sm text-next-muted-foreground">Single value: {{ numBudget }}</p>
        </div>
        <div class="max-w-md">
          <Slider v-model="numRange" :min="0" :max="2000" :step="50" range number-field prefix="$" aria-label-min="Min budget" aria-label-max="Max budget" />
          <p class="mt-next-2 text-next-sm text-next-muted-foreground">Range: {{ numRange[0] }} – {{ numRange[1] }}</p>
        </div>
      </div>
    </StorySection>

    <StorySection title="Disabled & error">
      <div class="flex flex-col gap-next-6">
        <div class="max-w-md"><Slider :model-value="30" disabled aria-label="Disabled" /></div>
        <div class="max-w-md"><Slider :model-value="80" aria-invalid aria-label="Invalid" /></div>
      </div>
    </StorySection>

    <StorySection title="Realistic usage (FormField)">
      <FormField label="Monthly budget" description="Drag the thumb or type a value.">
        <Slider v-model="formBudget" :min="0" :max="2000" :step="50" number-field prefix="$" aria-label="Monthly budget" />
      </FormField>
    </StorySection>

    <StorySection title="API">
      <ApiTable title="Props" :rows="propRows" show-default />
    </StorySection>
  </StoryPage>
</template>
