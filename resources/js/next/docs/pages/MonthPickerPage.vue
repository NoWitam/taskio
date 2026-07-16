<script setup lang="ts">
import { ref } from 'vue';
import MonthPicker from '../../ui/forms/MonthPicker.vue';
import FormField from '../../ui/forms/FormField.vue';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import StoryCell from '../StoryCell.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';
import { toIsoDate, today, makeDate } from '../../ui/forms/date/dateCore';

const sizes = ['sm', 'md', 'lg'] as const;

const basic = ref<string | null>(null);
const filled = ref<string | null>(toIsoDate(makeDate(today().getFullYear(), today().getMonth(), 1)));
const bounded = ref<string | null>(null);
const enUs = ref<string | null>(null);
const formMonth = ref<string | null>(null);

const minIso = toIsoDate(makeDate(today().getFullYear(), 0, 1));
const maxIso = toIsoDate(makeDate(today().getFullYear(), 11, 1));

const propRows: ApiRow[] = [
  { name: 'v-model', type: 'string | null', default: 'null', description: 'ISO day pinned to the 1st `yyyy-mm-01` (never a Date).' },
  { name: 'min / max', type: 'string | null', default: 'null', description: 'ISO day; months outside the range are disabled.' },
  { name: 'locale', type: 'string', default: "'pl'", description: 'BCP-47 locale for month names (Intl).' },
  { name: 'clearable / size', type: 'boolean | size', default: 'true / md', description: 'Clear button; control height.' },
  { name: 'disabled / readonly', type: 'boolean', default: 'false', description: 'Inert / read-only.' },
  { name: 'success / dirty / ariaInvalid', type: 'boolean', default: 'false', description: 'Standalone state styling.' },
];
const eventRows: ApiRow[] = [
  { name: 'update:modelValue', type: 'string | null', description: 'Emitted with the chosen month as yyyy-mm-01.' },
];
</script>

<template>
  <StoryPage
    title="MonthPicker"
    description="Month + year selection without a day grid — for billing months, reporting periods, etc. A year stepper over a 3×4 month grid; clicking the year header opens a paged 12-year grid to jump directly to a year. Model is an ISO day pinned to the first of the month (yyyy-mm-01), consistent with DatePicker — never a Date."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>The trigger is a <code>role="combobox"</code> button; <kbd>↓</kbd> or click opens the popover.</li>
        <li>The grid is <code>role="grid"</code> with <code>role="gridcell"</code> months; the selected month is <code>aria-selected</code>, the current month <code>aria-current="date"</code>, out-of-range months <code>aria-disabled</code>.</li>
        <li>Clicking the year header opens a 12-year grid (prev/next pages). Years out of <code>min</code>/<code>max</code> are disabled; <kbd>←</kbd>/<kbd>→</kbd>/<kbd>↑</kbd>/<kbd>↓</kbd> move, <kbd>Enter</kbd> selects, <kbd>Esc</kbd> returns to the months view.</li>
        <li><kbd>Esc</kbd> closes the popover and returns focus to the trigger.</li>
      </ul>
    </template>

    <StorySection title="Sizes">
      <div class="grid max-w-xs gap-next-4">
        <StoryCell v-for="s in sizes" :key="s" :label="s">
          <div class="w-56"><MonthPicker :size="s" v-model="basic" /></div>
        </StoryCell>
      </div>
    </StorySection>

    <StorySection title="States" description="default · filled · readonly · disabled · error.">
      <div class="grid max-w-xs gap-next-4">
        <StoryCell label="default"><div class="w-56"><MonthPicker v-model="basic" /></div></StoryCell>
        <StoryCell label="filled"><div class="w-56"><MonthPicker v-model="filled" /></div></StoryCell>
        <StoryCell label="readonly"><div class="w-56"><MonthPicker :model-value="filled" readonly /></div></StoryCell>
        <StoryCell label="disabled"><div class="w-56"><MonthPicker :model-value="filled" disabled /></div></StoryCell>
        <StoryCell label="error"><div class="w-56"><MonthPicker v-model="basic" aria-invalid /></div></StoryCell>
      </div>
    </StorySection>

    <StorySection title="Bounded + locale" :description="`Limited to ${minIso} … ${maxIso}; en-US names.`">
      <div class="grid max-w-xs gap-next-4">
        <StoryCell label="min/max"><div class="w-56"><MonthPicker v-model="bounded" :min="minIso" :max="maxIso" /></div></StoryCell>
        <StoryCell label="en-US"><div class="w-56"><MonthPicker v-model="enUs" locale="en-US" /></div></StoryCell>
      </div>
    </StorySection>

    <StorySection title="Realistic usage (FormField)">
      <FormField label="Miesiąc rozliczeniowy" required :error="formMonth == null ? 'Wybierz miesiąc.' : undefined">
        <div class="w-56"><MonthPicker v-model="formMonth" /></div>
      </FormField>
      <p class="mt-next-2 font-next-mono text-next-xs text-next-muted-foreground">model: {{ formMonth ?? 'null' }}</p>
    </StorySection>

    <StorySection title="API">
      <ApiTable title="Props" :rows="propRows" show-default />
      <ApiTable title="Events" type-header="Payload" :rows="eventRows" />
    </StorySection>
  </StoryPage>
</template>
