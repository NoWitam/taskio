<script setup lang="ts">
import { ref } from 'vue';
import DateRangePicker, {
  type DateRangeValue,
} from '../../ui/forms/DateRangePicker.vue';
import FormField from '../../ui/forms/FormField.vue';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import StoryCell from '../StoryCell.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';
import { addDays, toIsoDate, today } from '../../ui/forms/date/dateCore';

const sizes = ['sm', 'md', 'lg'] as const;

const basic = ref<DateRangeValue>({ start: null, end: null });
const filled = ref<DateRangeValue>({
  start: toIsoDate(addDays(today(), -6)),
  end: toIsoDate(today()),
});
const noPresets = ref<DateRangeValue>({ start: null, end: null });
const bounded = ref<DateRangeValue>({ start: null, end: null });
const formRange = ref<DateRangeValue>({ start: null, end: null });

const minIso = toIsoDate(addDays(today(), -30));
const maxIso = toIsoDate(addDays(today(), 30));

const propRows: ApiRow[] = [
  { name: 'v-model', type: '{ start, end }', default: '{null,null}', description: 'Each endpoint an ISO day `yyyy-mm-dd` (never a Date).' },
  { name: 'presets', type: 'DateRangePreset[]', default: 'Polish defaults', description: 'Shortcut list; each has id, label, range().' },
  { name: 'hidePresets', type: 'boolean', default: 'false', description: 'Hide the preset column.' },
  { name: 'min / max', type: 'string | null', default: 'null', description: 'ISO day bounds.' },
  { name: 'disabledDate', type: '(d: Date) => boolean', default: '—', description: 'Disable specific days.' },
  { name: 'format / locale / weekStartsOn', type: 'string | 0..6', default: "dd.mm.yyyy / pl / 1", description: 'Display + calendar locale.' },
  { name: 'clearable / size', type: 'boolean | size', default: 'true / md', description: 'Clear button; control height.' },
  { name: 'disabled / readonly', type: 'boolean', default: 'false', description: 'Inert / read-only.' },
];
const eventRows: ApiRow[] = [
  { name: 'update:modelValue', type: '{ start, end }', description: 'Emitted when either endpoint changes (typed, preset, or calendar).' },
];

function fmt(r: DateRangeValue): string {
  return `{ start: ${r.start ?? 'null'}, end: ${r.end ?? 'null'} }`;
}
</script>

<template>
  <StoryPage
    title="DateRangePicker"
    description="Start/end range with two typeable segments under one FieldShell label and a popover with preset shortcuts (Polish) plus a range CalendarPanel — two months side by side ≥ next-md, one stacked below otherwise, with a live hover preview. Model is { start, end } of ISO day strings — never Dates."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>Two inputs (<code>Data początkowa</code> / <code>Data końcowa</code>) share one bordered shell, grouped via <code>role="group"</code>.</li>
        <li>Calendar grid keyboard mirrors DatePicker; hovering/focusing a day previews the pending range. First click sets the start, second the end (order-tolerant).</li>
        <li>Presets are buttons that fill both endpoints. <kbd>Esc</kbd> closes + returns focus.</li>
      </ul>
    </template>

    <StorySection title="Sizes">
      <div class="grid max-w-lg gap-next-4">
        <StoryCell v-for="s in sizes" :key="s" :label="s">
          <div class="w-80"><DateRangePicker :size="s" v-model="basic" /></div>
        </StoryCell>
      </div>
    </StorySection>

    <StorySection title="States" description="default · filled (last 7 days) · readonly · disabled.">
      <div class="grid max-w-lg gap-next-4">
        <StoryCell label="default"><div class="w-80"><DateRangePicker v-model="basic" /></div></StoryCell>
        <StoryCell label="filled"><div class="w-80"><DateRangePicker v-model="filled" /></div></StoryCell>
        <StoryCell label="readonly"><div class="w-80"><DateRangePicker :model-value="filled" readonly /></div></StoryCell>
        <StoryCell label="disabled"><div class="w-80"><DateRangePicker :model-value="filled" disabled /></div></StoryCell>
      </div>
      <p class="mt-next-2 font-next-mono text-next-xs text-next-muted-foreground">filled: {{ fmt(filled) }}</p>
    </StorySection>

    <StorySection title="Without presets + bounded" :description="`Bounded ${minIso} … ${maxIso}.`">
      <div class="grid max-w-lg gap-next-4">
        <StoryCell label="hidePresets"><div class="w-80"><DateRangePicker v-model="noPresets" hide-presets /></div></StoryCell>
        <StoryCell label="min/max"><div class="w-80"><DateRangePicker v-model="bounded" :min="minIso" :max="maxIso" /></div></StoryCell>
      </div>
    </StorySection>

    <StorySection title="Realistic usage (FormField)">
      <FormField label="Okres raportu" required description="Zakres dat dla raportu." :error="formRange.start == null ? 'Wybierz zakres.' : undefined">
        <div class="w-80"><DateRangePicker v-model="formRange" /></div>
      </FormField>
      <p class="mt-next-2 font-next-mono text-next-xs text-next-muted-foreground">model: {{ fmt(formRange) }}</p>
    </StorySection>

    <StorySection title="API">
      <ApiTable title="Props" :rows="propRows" show-default />
      <ApiTable title="Events" type-header="Payload" :rows="eventRows" />
    </StorySection>
  </StoryPage>
</template>
