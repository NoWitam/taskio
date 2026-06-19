<script setup lang="ts">
import { ref } from 'vue';
import DatePicker from '../../ui/forms/DatePicker.vue';
import FormField from '../../ui/forms/FormField.vue';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import StoryCell from '../StoryCell.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';
import { addDays, toIsoDate, today } from '../../ui/forms/date/dateCore';

const sizes = ['sm', 'md', 'lg'] as const;

const basic = ref<string | null>(null);
const filled = ref<string | null>(toIsoDate(today()));
const bounded = ref<string | null>(null);
const noWeekends = ref<string | null>(null);
const enUs = ref<string | null>(null);
const formDate = ref<string | null>(null);

const minIso = toIsoDate(addDays(today(), -3));
const maxIso = toIsoDate(addDays(today(), 30));

function isWeekend(d: Date): boolean {
  return d.getDay() === 0 || d.getDay() === 6;
}

const propRows: ApiRow[] = [
  { name: 'v-model', type: 'string | null', default: 'null', description: 'ISO day `yyyy-mm-dd` (NEVER a Date).' },
  { name: 'min / max', type: 'string | null', default: 'null', description: 'ISO day bounds; days outside are disabled.' },
  { name: 'disabledDate', type: '(d: Date) => boolean', default: '—', description: 'Predicate to disable specific days.' },
  { name: 'format', type: 'string', default: "'dd.mm.yyyy'", description: 'Typed + displayed format. Token order drives parsing.' },
  { name: 'locale', type: 'string', default: "'pl'", description: 'BCP-47 locale for month/weekday names (Intl).' },
  { name: 'weekStartsOn', type: '0..6', default: '1', description: 'First weekday (1 = Monday for pl).' },
  { name: 'clearable', type: 'boolean', default: 'true', description: 'Show a clear (✕) button when filled.' },
  { name: 'size', type: "'sm'|'md'|'lg'", default: "'md'", description: 'Control height + text scale.' },
  { name: 'disabled / readonly', type: 'boolean', default: 'false', description: 'Inert / read-only.' },
  { name: 'success / dirty / ariaInvalid', type: 'boolean', default: 'false', description: 'Standalone state styling (FormField sets these).' },
];
const eventRows: ApiRow[] = [
  { name: 'update:modelValue', type: 'string | null', description: 'Emitted when the ISO day changes (typed parse or calendar pick).' },
];
</script>

<template>
  <StoryPage
    title="DatePicker"
    description="Single date entry: a typeable dd.mm.yyyy field on FieldShell with a calendar popover. Polish locale defaults (Monday start, pl names), all overridable. Model is an ISO day string yyyy-mm-dd — never a Date — to avoid timezone drift."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>The input is a <code>role="combobox"</code> with <code>aria-haspopup="dialog"</code> / <code>aria-expanded</code>; <kbd>↓</kbd> or click opens the calendar.</li>
        <li>The grid is <code>role="grid"</code>; arrows move day, <kbd>PageUp/Down</kbd> month, <kbd>Shift+PageUp/Down</kbd> year, <kbd>Home/End</kbd> week edges, <kbd>Enter/Space</kbd> select, <kbd>Esc</kbd> closes + returns focus.</li>
        <li>Today carries <code>aria-current="date"</code>; the selected day <code>aria-selected</code>; disabled days <code>aria-disabled</code>. The focused day is announced via a polite live region.</li>
      </ul>
    </template>

    <StorySection title="Sizes">
      <div class="grid max-w-sm gap-next-4">
        <StoryCell v-for="s in sizes" :key="s" :label="s">
          <div class="w-64"><DatePicker :size="s" v-model="basic" /></div>
        </StoryCell>
      </div>
    </StorySection>

    <StorySection title="States" description="default · filled · readonly · disabled · error · success · dirty.">
      <div class="grid max-w-sm gap-next-4">
        <StoryCell label="default (type dd.mm.yyyy)"><div class="w-64"><DatePicker v-model="basic" /></div></StoryCell>
        <StoryCell label="filled (today)"><div class="w-64"><DatePicker v-model="filled" /></div></StoryCell>
        <StoryCell label="readonly"><div class="w-64"><DatePicker :model-value="filled" readonly /></div></StoryCell>
        <StoryCell label="disabled"><div class="w-64"><DatePicker :model-value="filled" disabled /></div></StoryCell>
        <StoryCell label="error"><div class="w-64"><DatePicker v-model="basic" aria-invalid /></div></StoryCell>
        <StoryCell label="success"><div class="w-64"><DatePicker v-model="filled" success /></div></StoryCell>
        <StoryCell label="dirty"><div class="w-64"><DatePicker v-model="filled" dirty /></div></StoryCell>
      </div>
    </StorySection>

    <StorySection title="min / max + disabledDate" :description="`Min ${minIso}, max ${maxIso}; weekends disabled via predicate.`">
      <div class="grid max-w-sm gap-next-4">
        <StoryCell label="bounded"><div class="w-64"><DatePicker v-model="bounded" :min="minIso" :max="maxIso" /></div></StoryCell>
        <StoryCell label="no weekends"><div class="w-64"><DatePicker v-model="noWeekends" :disabled-date="isWeekend" /></div></StoryCell>
      </div>
    </StorySection>

    <StorySection title="Locale override" description="en-US: Sunday week start, mm/dd/yyyy, English names.">
      <div class="w-64"><DatePicker v-model="enUs" locale="en-US" :week-starts-on="0" format="mm/dd/yyyy" /></div>
    </StorySection>

    <StorySection title="Realistic usage (FormField)">
      <FormField label="Data startu" required description="Wybierz dzień rozpoczęcia projektu." :error="formDate == null ? 'Wymagana data.' : undefined">
        <div class="w-64"><DatePicker v-model="formDate" /></div>
      </FormField>
      <p class="mt-next-2 font-next-mono text-next-xs text-next-muted-foreground">model: {{ formDate ?? 'null' }}</p>
    </StorySection>

    <StorySection title="API">
      <ApiTable title="Props" :rows="propRows" show-default />
      <ApiTable title="Events" type-header="Payload" :rows="eventRows" />
    </StorySection>
  </StoryPage>
</template>
