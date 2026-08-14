<script setup lang="ts">
import { ref } from 'vue';
import TimePicker from '../../ui/forms/TimePicker.vue';
import FormField from '../../ui/forms/FormField.vue';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import StoryCell from '../StoryCell.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const sizes = ['sm', 'md', 'lg'] as const;

const basic = ref<string | null>('09:30');
const empty = ref<string | null>(null);
const withSecs = ref<string | null>('14:05:20');
const ampm = ref<string | null>('13:45');
const quarter = ref<string | null>('10:15');
const formTime = ref<string | null>(null);

const propRows: ApiRow[] = [
  { name: 'v-model', type: 'string | null', default: 'null', description: 'ISO time `HH:mm` / `HH:mm:ss` (24h, no zone). Never a Date.' },
  { name: 'seconds', type: 'boolean', default: 'false', description: 'Add a seconds column + serialize HH:mm:ss.' },
  { name: 'hour12', type: 'boolean', default: 'false', description: '12-hour display + AM/PM toggle (model stays 24h).' },
  { name: 'minuteStep', type: 'number', default: '1', description: 'Minute stepper increment (e.g. 5, 15); snaps on open.' },
  { name: 'clearable', type: 'boolean', default: 'true', description: 'Show a clear (✕) button when filled.' },
  { name: 'size', type: "'sm'|'md'|'lg'", default: "'md'", description: 'Control height + text scale.' },
  { name: 'disabled / readonly', type: 'boolean', default: 'false', description: 'Inert / read-only.' },
  { name: 'success / dirty', type: 'boolean', default: 'false', description: 'Standalone state styling.' },
  { name: 'ariaInvalid', type: 'boolean', default: '—', description: 'Force the error state standalone; omit it and the surrounding FormField decides.' },
];
const eventRows: ApiRow[] = [
  { name: 'update:modelValue', type: 'string | null', description: 'Emitted when the ISO time changes (typed or steppers).' },
];
</script>

<template>
  <StoryPage
    title="TimePicker"
    description="Wall-clock time entry: a typeable HH:mm field on FieldShell with steppered hour/minute (and optional seconds) columns. 24h by default (Polish), with an hour12 AM/PM mode. Model is an ISO time string — never a Date."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>The input is a <code>role="combobox"</code>; <kbd>↓</kbd> or click opens the spinner popover.</li>
        <li>Each column is a <code>role="spinbutton"</code> with <code>aria-valuenow</code>; <kbd>↑</kbd>/<kbd>↓</kbd> step it. The AM/PM buttons are <code>aria-pressed</code> toggles.</li>
        <li>Typing tolerates <code>9</code>, <code>9:5</code>, <code>0930</code>, <code>09:30</code>; out-of-range like <code>25:00</code> flags invalid.</li>
        <li><kbd>Esc</kbd> closes the popover and returns focus to the field.</li>
      </ul>
    </template>

    <StorySection title="Sizes">
      <div class="grid max-w-xs gap-next-4">
        <StoryCell v-for="s in sizes" :key="s" :label="s">
          <div class="w-44"><TimePicker :size="s" v-model="basic" /></div>
        </StoryCell>
      </div>
    </StorySection>

    <StorySection title="States" description="filled · empty · readonly · disabled · error.">
      <div class="grid max-w-xs gap-next-4">
        <StoryCell label="filled"><div class="w-44"><TimePicker v-model="basic" /></div></StoryCell>
        <StoryCell label="empty"><div class="w-44"><TimePicker v-model="empty" /></div></StoryCell>
        <StoryCell label="readonly"><div class="w-44"><TimePicker :model-value="basic" readonly /></div></StoryCell>
        <StoryCell label="disabled"><div class="w-44"><TimePicker :model-value="basic" disabled /></div></StoryCell>
        <StoryCell label="error"><div class="w-44"><TimePicker v-model="empty" aria-invalid /></div></StoryCell>
      </div>
    </StorySection>

    <StorySection title="Seconds, 12h, minute step">
      <div class="grid max-w-xs gap-next-4">
        <StoryCell label="seconds (HH:mm:ss)"><div class="w-52"><TimePicker v-model="withSecs" seconds /></div></StoryCell>
        <StoryCell label="hour12 (AM/PM)"><div class="w-52"><TimePicker v-model="ampm" hour12 /></div></StoryCell>
        <StoryCell label="minuteStep 15"><div class="w-44"><TimePicker v-model="quarter" :minute-step="15" /></div></StoryCell>
      </div>
    </StorySection>

    <StorySection title="Realistic usage (FormField)">
      <FormField label="Godzina spotkania" required :error="formTime == null ? 'Podaj godzinę.' : undefined">
        <div class="w-44"><TimePicker v-model="formTime" :minute-step="5" /></div>
      </FormField>
      <p class="mt-next-2 font-next-mono text-next-xs text-next-muted-foreground">model: {{ formTime ?? 'null' }}</p>
    </StorySection>

    <StorySection title="API">
      <ApiTable title="Props" :rows="propRows" show-default />
      <ApiTable title="Events" type-header="Payload" :rows="eventRows" />
    </StorySection>
  </StoryPage>
</template>
