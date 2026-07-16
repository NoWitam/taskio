<script setup lang="ts">
import { ref } from 'vue';
import DateTimePicker from '../../ui/forms/DateTimePicker.vue';
import FormField from '../../ui/forms/FormField.vue';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import StoryCell from '../StoryCell.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';
import { addDays, toIsoDate, today } from '../../ui/forms/date/dateCore';

const sizes = ['sm', 'md', 'lg'] as const;

const basic = ref<string | null>(null);
const filled = ref<string | null>(`${toIsoDate(today())}T14:30`);
const ampm = ref<string | null>(`${toIsoDate(today())}T09:00`);
const bounded = ref<string | null>(null);
const formValue = ref<string | null>(null);

const minIso = toIsoDate(today());
const maxIso = toIsoDate(addDays(today(), 14));

const propRows: ApiRow[] = [
  { name: 'v-model', type: 'string | null', default: 'null', description: 'LOCAL ISO datetime `yyyy-mm-ddTHH:mm[:ss]` (no Z/offset). Never a Date.' },
  { name: 'min / max', type: 'string | null', default: 'null', description: 'ISO day bounds for the calendar.' },
  { name: 'disabledDate', type: '(d: Date) => boolean', default: '—', description: 'Disable specific days.' },
  { name: 'seconds / hour12 / minuteStep', type: 'boolean | number', default: 'false / false / 1', description: 'Time-column options (as TimePicker).' },
  { name: 'format', type: 'string', default: "'dd.mm.yyyy'", description: 'Date portion display format.' },
  { name: 'locale / weekStartsOn', type: 'string | 0..6', default: "'pl' / 1", description: 'Calendar names + first weekday.' },
  { name: 'clearable / size', type: 'boolean | size', default: 'true / md', description: 'Clear button; control height.' },
  { name: 'disabled / readonly', type: 'boolean', default: 'false', description: 'Inert / read-only.' },
];
const eventRows: ApiRow[] = [
  { name: 'update:modelValue', type: 'string | null', description: 'Emitted on Apply with the composed local ISO datetime.' },
];
</script>

<template>
  <StoryPage
    title="DateTimePicker"
    description="Combined date + time in one popover: a calendar plus a compact time row, committed with Apply. Single LOCAL ISO datetime model (yyyy-mm-ddTHH:mm, no zone) so the backend owns the timezone — never a Date, never UTC."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>The trigger is a <code>role="combobox"</code> button with <code>aria-haspopup="dialog"</code>; <kbd>↓</kbd> or click opens.</li>
        <li>Calendar grid keyboard mirrors DatePicker; the time fields are <code>role="spinbutton"</code> stepped with <kbd>↑</kbd>/<kbd>↓</kbd>.</li>
        <li>Picking a day keeps the popover open; <strong>Zastosuj</strong> (Apply) commits date+time and closes. <kbd>Esc</kbd> / Anuluj discards.</li>
      </ul>
    </template>

    <StorySection title="Sizes">
      <div class="grid max-w-md gap-next-4">
        <StoryCell v-for="s in sizes" :key="s" :label="s">
          <div class="w-72"><DateTimePicker :size="s" v-model="basic" /></div>
        </StoryCell>
      </div>
    </StorySection>

    <StorySection title="States" description="default · filled · readonly · disabled.">
      <div class="grid max-w-md gap-next-4">
        <StoryCell label="default"><div class="w-72"><DateTimePicker v-model="basic" /></div></StoryCell>
        <StoryCell label="filled"><div class="w-72"><DateTimePicker v-model="filled" /></div></StoryCell>
        <StoryCell label="readonly"><div class="w-72"><DateTimePicker :model-value="filled" readonly /></div></StoryCell>
        <StoryCell label="disabled"><div class="w-72"><DateTimePicker :model-value="filled" disabled /></div></StoryCell>
      </div>
    </StorySection>

    <StorySection title="hour12 + bounded" :description="`12h AM/PM; bounded ${minIso} … ${maxIso}.`">
      <div class="grid max-w-md gap-next-4">
        <StoryCell label="hour12"><div class="w-72"><DateTimePicker v-model="ampm" hour12 /></div></StoryCell>
        <StoryCell label="min/max"><div class="w-72"><DateTimePicker v-model="bounded" :min="minIso" :max="maxIso" :minute-step="15" /></div></StoryCell>
      </div>
    </StorySection>

    <StorySection title="Realistic usage (FormField)">
      <FormField label="Termin publikacji" required description="Wybierz datę i godzinę publikacji." :error="formValue == null ? 'Wymagany termin.' : undefined">
        <div class="w-72"><DateTimePicker v-model="formValue" :minute-step="5" /></div>
      </FormField>
      <p class="mt-next-2 font-next-mono text-next-xs text-next-muted-foreground">model: {{ formValue ?? 'null' }}</p>
    </StorySection>

    <StorySection title="API">
      <ApiTable title="Props" :rows="propRows" show-default />
      <ApiTable title="Events" type-header="Payload" :rows="eventRows" />
    </StorySection>
  </StoryPage>
</template>
