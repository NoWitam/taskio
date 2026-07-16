<script setup lang="ts">
import { ref } from 'vue';
import NumberInput from '../../ui/forms/NumberInput.vue';
import FormField from '../../ui/forms/FormField.vue';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import StoryCell from '../StoryCell.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const sizes = ['sm', 'md', 'lg'] as const;

const qty = ref<number | null>(1);
const bounded = ref<number | null>(5);
const price = ref<number | null>(19.99);
const weight = ref<number | null>(70);
const outOfRange = ref<number | null>(150);
const formSeats = ref<number | null>(3);

const propRows: ApiRow[] = [
  { name: 'v-model', type: 'number | null', default: 'null', description: 'Numeric value (null = empty).' },
  { name: 'min / max', type: 'number', default: '—', description: 'Bounds; steppers disable at the edge, out-of-range flags aria-invalid.' },
  { name: 'step', type: 'number', default: '1', description: 'Stepper + ↑/↓ increment.' },
  { name: 'size', type: "'sm' | 'md' | 'lg'", default: "'md'", description: 'Control height + text scale.' },
  { name: 'steppers', type: 'boolean', default: 'true', description: 'Show the compact stacked-chevron stepper. Set false for a bare field.' },
  { name: 'prefix / suffix', type: 'string', default: '—', description: 'Unit/affix rendered as leading/trailing adornments.' },
  { name: 'clampOnBlur', type: 'boolean', default: 'false', description: 'Clamp into [min,max] when the field loses focus.' },
  { name: 'disabled / readonly', type: 'boolean', default: 'false', description: 'Disabled or read-only state (also hides the steppers).' },
  { name: 'success / dirty', type: 'boolean', default: 'false', description: 'Force the success / dirty line standalone (FormField sets these).' },
  { name: 'ariaInvalid / id / describedById', type: 'string | boolean', default: '—', description: 'Standalone wiring; provided inside a FormField.' },
];
</script>

<template>
  <StoryPage
    title="NumberInput"
    description="Numeric entry rendered through FieldShell. Stepping is a subtle, compact pair of stacked chevrons in the trailing area (optional via :steppers=false) — no large +/- buttons. Optional unit/affix as adornments, with out-of-range handling and spinbutton semantics."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>The field is a <code>role="spinbutton"</code> with <code>aria-valuenow/min/max</code>; <kbd>↑</kbd>/<kbd>↓</kbd> step (native number input).</li>
        <li>The compact chevron steppers have <code>aria-label</code>s (“Increment”/“Decrement”), are <code>tabindex="-1"</code> (the field is the tab stop), and disable at the min/max edge.</li>
        <li>Out-of-range values keep their value but set <code>aria-invalid</code> so the FormField can show a message.</li>
      </ul>
    </template>

    <StorySection title="Stepper" description="Subtle stacked-chevron stepper (default) vs steppers hidden — a clean text field.">
      <div class="grid max-w-sm gap-next-4">
        <StoryCell label="default (subtle stepper)"><div class="w-44"><NumberInput v-model="qty" :min="0" :max="10" /></div></StoryCell>
        <StoryCell label=":steppers=false"><div class="w-44"><NumberInput v-model="qty" :steppers="false" /></div></StoryCell>
      </div>
    </StorySection>

    <StorySection title="Sizes">
      <div class="grid max-w-sm gap-next-4">
        <StoryCell v-for="s in sizes" :key="s" :label="s">
          <div class="w-40"><NumberInput :size="s" v-model="qty" /></div>
        </StoryCell>
      </div>
    </StorySection>

    <StorySection title="States" description="default · bounded (try the edges) · readonly · disabled.">
      <div class="grid max-w-sm gap-next-4">
        <StoryCell label="default"><div class="w-40"><NumberInput v-model="qty" /></div></StoryCell>
        <StoryCell label="min 0 / max 10"><div class="w-40"><NumberInput v-model="bounded" :min="0" :max="10" /></div></StoryCell>
        <StoryCell label="readonly"><div class="w-40"><NumberInput :model-value="42" readonly /></div></StoryCell>
        <StoryCell label="disabled"><div class="w-40"><NumberInput :model-value="42" disabled /></div></StoryCell>
      </div>
    </StorySection>

    <StorySection title="Unit / affix" description="Prefix or suffix text inside the control.">
      <div class="grid max-w-sm gap-next-4">
        <StoryCell label="prefix $"><div class="w-44"><NumberInput v-model="price" :step="0.01" prefix="$" /></div></StoryCell>
        <StoryCell label="suffix kg"><div class="w-44"><NumberInput v-model="weight" suffix="kg" /></div></StoryCell>
      </div>
    </StorySection>

    <StorySection title="Out-of-range" description="A value above max keeps its value but flags invalid (try lowering it).">
      <FormField :error="outOfRange != null && outOfRange > 100 ? 'Value must be 100 or less.' : undefined">
        <div class="w-44"><NumberInput v-model="outOfRange" :min="0" :max="100" /></div>
      </FormField>
    </StorySection>

    <StorySection title="Realistic usage (FormField)">
      <FormField label="Seats" required description="How many team members can access this workspace." :error="formSeats == null ? 'Enter a number of seats.' : undefined">
        <div class="w-44"><NumberInput v-model="formSeats" :min="1" :max="50" clamp-on-blur /></div>
      </FormField>
    </StorySection>

    <StorySection title="API">
      <ApiTable title="Props" :rows="propRows" show-default />
    </StorySection>
  </StoryPage>
</template>
