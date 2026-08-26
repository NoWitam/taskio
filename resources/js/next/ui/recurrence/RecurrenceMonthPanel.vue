<script setup lang="ts">
// RecurrenceMonthPanel — the "Month" axis. Sub-modes via `RecurrenceOptionCards`: Every month
// (neutral, no body) / Every N months ("every {n} months" + an optional month-range window as
// two month Selects) / In selected months (a lead + month chip grid). The `everyNNote` warns
// when the interval doesn't divide the year evenly (the grid counts from January and resets
// at year end) — which is also precisely why the Calendar profile omits that mode: its
// endpoint refuses `every_n_months` rather than store a cadence that means something other
// than it says.
import { computed } from 'vue';
import Select, { type SelectOption } from '../forms/Select.vue';
import NumberInput from '../forms/NumberInput.vue';
import Alert from '../feedback/Alert.vue';
import RecurrenceOptionCards, { type OptionCard } from './RecurrenceOptionCards.vue';
import RecurrenceWindowField from './RecurrenceWindowField.vue';
import { useI18n } from '../../app/i18n';
import {
  RECURRENCE_LIMITS,
  seedMonth,
  splitSentenceTemplate,
  type MonthAxis,
  type MonthSubmode,
  type RecurrenceProfile,
} from './recurrenceAxes';

const props = withDefaults(
  defineProps<{
    profile: RecurrenceProfile;
    errors?: Record<string, string | undefined>;
    /** The start day a newly-picked sub-mode seeds from (Calendar); null ⇒ neutral seeds. */
    anchorDay?: string | null;
  }>(),
  { errors: () => ({}), anchorDay: null },
);

const model = defineModel<MonthAxis>({ required: true });
const { t } = useI18n();
const L = RECURRENCE_LIMITS;

const MONTHS = Array.from({ length: 12 }, (_, i) => i + 1);

const TITLE_KEY: Record<MonthSubmode, string> = {
  every_month: 'recurrenceEditor.month.mode.everyMonth',
  every_n_months: 'recurrenceEditor.month.mode.everyNMonths',
  months: 'recurrenceEditor.month.mode.months',
};

const modeOptions = computed<OptionCard<MonthSubmode>[]>(() =>
  props.profile.monthModes.map((value) => ({ value, title: t(TITLE_KEY[value]) })),
);

const everyNMonthsHead = computed(() => splitSentenceTemplate(t('recurrenceEditor.month.card.everyNMonths.head')));

const mode = computed<MonthSubmode>({
  get: () => model.value.mode,
  set: (m) => {
    if (m === model.value.mode) return;
    model.value = seedMonth(m, props.anchorDay);
  },
});

function finiteOrNull(n: number): number | null {
  return Number.isFinite(n) ? n : null;
}
const everyNMonthsN = computed<number | null>({
  get: () => (model.value.mode === 'every_n_months' ? finiteOrNull(model.value.n) : null),
  set: (v) => {
    if (model.value.mode === 'every_n_months') model.value = { ...model.value, n: v ?? NaN };
  },
});
/** The month grid counts from January and resets at year end — warn on an uneven divisor. */
const showEveryNNote = computed(
  () => model.value.mode === 'every_n_months' && Number.isFinite(model.value.n) && 12 % model.value.n !== 0,
);

// --- month-range window (two month Selects) ---------------------------------
const monthOptions = computed<SelectOption[]>(() =>
  MONTHS.map((m) => ({ value: String(m), label: t(`recurrenceEditor.month.long.${m}`) })),
);
const monthsWindowOn = computed(() => model.value.mode === 'every_n_months' && !!model.value.window);
function toggleMonthsWindow(checked: boolean): void {
  if (model.value.mode !== 'every_n_months') return;
  model.value = { mode: 'every_n_months', n: model.value.n, window: checked ? { from: 1, to: 12 } : undefined };
}
function setMonthsWindow(key: 'from' | 'to', value: string | null): void {
  if (model.value.mode !== 'every_n_months' || !model.value.window || value == null) return;
  model.value = { ...model.value, window: { ...model.value.window, [key]: Number(value) } };
}
const monthsWindowFrom = computed(() =>
  model.value.mode === 'every_n_months' && model.value.window ? String(model.value.window.from) : null,
);
const monthsWindowTo = computed(() =>
  model.value.mode === 'every_n_months' && model.value.window ? String(model.value.window.to) : null,
);

// --- selected-months chips ---------------------------------------------------
function monthSelected(m: number): boolean {
  return model.value.mode === 'months' && model.value.months.includes(m);
}
function toggleMonth(m: number): void {
  const set = new Set(model.value.mode === 'months' ? model.value.months : []);
  if (set.has(m)) set.delete(m);
  else set.add(m);
  model.value = { mode: 'months', months: [...set].sort((a, b) => a - b) };
}
const chipBase =
  'inline-flex items-center justify-center rounded-next-md border px-next-3 py-next-1 text-next-sm ' +
  'outline-none transition-colors focus-visible:ring-2 focus-visible:ring-next-ring cursor-pointer';
function chipClass(active: boolean): string {
  return active
    ? 'border-next-primary bg-next-primary-subtle text-next-fg font-next-medium'
    : 'border-next-border bg-next-card text-next-fg hover:border-next-primary/50';
}
</script>

<template>
  <div class="flex flex-col gap-next-3">
    <RecurrenceOptionCards
      v-model="mode"
      :options="modeOptions"
      :aria-label="t('recurrenceEditor.tab.month')"
    >
      <!-- every_n_months — "co {n} miesięcy [☐ od {from} do {to}]" — ONE flow; the divisor
           note + error break to their own rows (w-full). -->
      <template #body-every_n_months>
        <div class="flex flex-wrap items-center gap-next-1_5 text-next-sm text-next-fg">
          <template v-for="(seg, i) in everyNMonthsHead" :key="'h' + i">
            <span v-if="seg.type === 'text'">{{ seg.value }}</span>
            <NumberInput
              v-else-if="seg.name === 'n'"
              v-model="everyNMonthsN"
              :min="L.everyNMonthsMin"
              :max="L.everyNMonthsMax"
              class="w-20 shrink-0 basis-20"
              :aria-label="t('recurrenceEditor.field.monthsEvery')"
            />
          </template>
          <RecurrenceWindowField
            :enabled="monthsWindowOn"
            :toggle-label="t('recurrenceEditor.window.toggle.months')"
            :window-template="t('recurrenceEditor.month.card.everyNMonths.window')"
            :error="errors.window"
            @toggle="toggleMonthsWindow"
          >
            <template #from="{ disabled }">
              <Select
                :model-value="monthsWindowFrom"
                :options="monthOptions"
                :disabled="disabled"
                class="w-36 shrink-0 basis-36"
                :aria-label="t('recurrenceEditor.window.fromMonth')"
                @update:model-value="setMonthsWindow('from', $event)"
              />
            </template>
            <template #to="{ disabled }">
              <Select
                :model-value="monthsWindowTo"
                :options="monthOptions"
                :disabled="disabled"
                class="w-36 shrink-0 basis-36"
                :aria-label="t('recurrenceEditor.window.toMonth')"
                @update:model-value="setMonthsWindow('to', $event)"
              />
            </template>
          </RecurrenceWindowField>
          <Alert v-if="showEveryNNote" variant="info" size="sm" class="w-full">{{ t('recurrenceEditor.month.everyNNote') }}</Alert>
          <p v-if="errors.n" class="w-full text-next-xs text-next-danger" role="alert">{{ errors.n }}</p>
        </div>
      </template>

      <!-- months — ONE wrapping flow: the lead sits INSIDE the month chip grid. -->
      <template #body-months>
        <div class="flex flex-wrap items-center gap-next-2">
          <div class="flex flex-wrap items-center gap-next-1_5" role="group" :aria-label="t('recurrenceEditor.month.mode.months')">
            <p class="text-next-sm text-next-fg">{{ t('recurrenceEditor.month.card.months.lead') }}</p>
            <button
              v-for="m in MONTHS"
              :key="m"
              type="button"
              :class="[chipBase, chipClass(monthSelected(m))]"
              :aria-pressed="monthSelected(m)"
              @click="toggleMonth(m)"
            >
              {{ t(`recurrenceEditor.month.short.${m}`) }}
            </button>
          </div>
          <p v-if="errors.months" class="w-full text-next-xs text-next-danger" role="alert">{{ errors.months }}</p>
        </div>
      </template>
    </RecurrenceOptionCards>
  </div>
</template>
