<script setup lang="ts">
// WorkflowScheduleDayPanel — the "Dzień" tab (§4.5.5b, REV5). The day axis's `special{}`
// union is presented FLAT as 7 sub-mode cards through `WorkflowScheduleOptionCards`; the
// SELECTED card expands with its inputs (chips / the ordinal+weekday Selects woven into
// "w {ordinal} {weekday} miesiąca"). The last card UNIFIES `nth_weekday` (ordinal 1..5)
// and `last_weekday` under ONE ordinal Select whose options are {first…fifth, last} —
// "fifth" and "last" are two explicit options so a 5th-occurrence is never mislabelled
// "last". The `last_working_day` note + its time restriction live in that card's SELECTED
// body (unselected cards show title only).
import { computed } from 'vue';
import Select, { type SelectOption } from '../../ui/forms/Select.vue';
import NumberInput from '../../ui/forms/NumberInput.vue';
import Button from '../../ui/primitives/Button.vue';
import Alert from '../../ui/feedback/Alert.vue';
import WorkflowScheduleOptionCards, { type OptionCard } from './WorkflowScheduleOptionCards.vue';
import WorkflowScheduleWindowField from './WorkflowScheduleWindowField.vue';
import { useI18n } from '../../app/i18n';
import { SCHEDULE_LIMITS, splitSentenceTemplate, type DayAxis } from './workflowSchedule';

type DaySubmode =
  | 'every_day'
  | 'every_n_days'
  | 'weekdays'
  | 'month_days'
  | 'last_day'
  | 'last_working_day'
  | 'weekday_in_month';

withDefaults(defineProps<{ errors?: Record<string, string | undefined> }>(), { errors: () => ({}) });

const model = defineModel<DayAxis>({ required: true });
const { t } = useI18n();
const L = SCHEDULE_LIMITS;

/** Monday-first display order (the wire array stays 0 = Sunday). */
const WEEKDAY_ORDER = [1, 2, 3, 4, 5, 6, 0];
const MONTH_DAYS = Array.from({ length: 31 }, (_, i) => i + 1);

function daySubmodeOf(day: DayAxis): DaySubmode {
  switch (day.mode) {
    case 'every_day':
      return 'every_day';
    case 'every_n_days':
      return 'every_n_days';
    case 'weekdays':
      return 'weekdays';
    case 'month_days':
      return 'month_days';
    case 'special':
      if (day.special.kind === 'last_day') return 'last_day';
      if (day.special.kind === 'last_working_day') return 'last_working_day';
      return 'weekday_in_month';
  }
}

const modeOptions = computed<OptionCard<DaySubmode>[]>(() => [
  { value: 'every_day', title: t('workflows.schedule.day.mode.everyDay') },
  { value: 'every_n_days', title: t('workflows.schedule.day.mode.everyNDays') },
  { value: 'weekdays', title: t('workflows.schedule.day.mode.weekdays') },
  { value: 'month_days', title: t('workflows.schedule.day.mode.monthDays') },
  { value: 'last_day', title: t('workflows.schedule.day.mode.lastDay') },
  { value: 'last_working_day', title: t('workflows.schedule.day.mode.lastWorkingDay') },
  { value: 'weekday_in_month', title: t('workflows.schedule.day.mode.weekdayInMonth') },
]);

// In-card head sentences, split so PL/EN word order lives in the string (§4.5.12).
const everyNDaysHead = computed(() => splitSentenceTemplate(t('workflows.schedule.day.card.everyNDays.head')));
const weekdayInMonthHead = computed(() => splitSentenceTemplate(t('workflows.schedule.day.card.weekdayInMonth.head')));

const submode = computed<DaySubmode>({
  get: () => daySubmodeOf(model.value),
  set: (m) => {
    if (m === daySubmodeOf(model.value)) return;
    switch (m) {
      case 'every_day':
        model.value = { mode: 'every_day' };
        break;
      case 'every_n_days':
        model.value = { mode: 'every_n_days', n: 1 };
        break;
      case 'weekdays':
        model.value = { mode: 'weekdays', weekdays: [] };
        break;
      case 'month_days':
        model.value = { mode: 'month_days', days: [] };
        break;
      case 'last_day':
        model.value = { mode: 'special', special: { kind: 'last_day' } };
        break;
      case 'last_working_day':
        model.value = { mode: 'special', special: { kind: 'last_working_day' } };
        break;
      case 'weekday_in_month':
        model.value = { mode: 'special', special: { kind: 'nth_weekday', ordinal: 1, weekday: 1 } };
        break;
    }
  },
});

// --- every_n_days: N + optional day-of-month window --------------------------
function finiteOrNull(n: number): number | null {
  return Number.isFinite(n) ? n : null;
}
const everyNDaysN = computed<number | null>({
  get: () => (model.value.mode === 'every_n_days' ? finiteOrNull(model.value.n) : null),
  set: (v) => {
    if (model.value.mode === 'every_n_days') model.value = { ...model.value, n: v ?? NaN };
  },
});
const daysWindowOn = computed(() => model.value.mode === 'every_n_days' && !!model.value.window);
function toggleDaysWindow(checked: boolean): void {
  if (model.value.mode !== 'every_n_days') return;
  model.value = { mode: 'every_n_days', n: model.value.n, window: checked ? { from: 1, to: 28 } : undefined };
}
function setDaysWindow(key: 'from' | 'to', value: number | null): void {
  if (model.value.mode !== 'every_n_days' || !model.value.window) return;
  model.value = { ...model.value, window: { ...model.value.window, [key]: value ?? NaN } };
}
const daysWindowFrom = computed(() =>
  model.value.mode === 'every_n_days' && model.value.window ? finiteOrNull(model.value.window.from) : null,
);
const daysWindowTo = computed(() =>
  model.value.mode === 'every_n_days' && model.value.window ? finiteOrNull(model.value.window.to) : null,
);

// --- weekdays chips (Monday-first display; wire 0 = Sunday) ------------------
function weekdaySelected(w: number): boolean {
  return model.value.mode === 'weekdays' && model.value.weekdays.includes(w);
}
function toggleWeekday(w: number): void {
  const set = new Set(model.value.mode === 'weekdays' ? model.value.weekdays : []);
  if (set.has(w)) set.delete(w);
  else set.add(w);
  model.value = { mode: 'weekdays', weekdays: [...set].sort((a, b) => a - b) };
}
function setWeekdays(list: number[]): void {
  model.value = { mode: 'weekdays', weekdays: list };
}

// --- month_days chips (1..31) ------------------------------------------------
function monthDaySelected(d: number): boolean {
  return model.value.mode === 'month_days' && model.value.days.includes(d);
}
function toggleMonthDay(d: number): void {
  const set = new Set(model.value.mode === 'month_days' ? model.value.days : []);
  if (set.has(d)) set.delete(d);
  else set.add(d);
  model.value = { mode: 'month_days', days: [...set].sort((a, b) => a - b) };
}

// --- weekday_in_month: ordinal {first..fifth, last} + weekday ----------------
const ordinalOptions = computed<SelectOption[]>(() => [
  ...([1, 2, 3, 4, 5] as const).map((n) => ({ value: String(n), label: t(`workflows.schedule.day.ordinal.${n}`) })),
  { value: 'last', label: t('workflows.schedule.day.ordinal.last') },
]);
const weekdayOptions = computed<SelectOption[]>(() =>
  WEEKDAY_ORDER.map((w) => ({ value: String(w), label: t(`workflows.schedule.weekday.long.${w}`) })),
);

/** The special's current weekday (nth_weekday | last_weekday), default Monday. */
const specialWeekday = computed(() => {
  if (model.value.mode === 'special' && (model.value.special.kind === 'nth_weekday' || model.value.special.kind === 'last_weekday')) {
    return model.value.special.weekday;
  }
  return 1;
});
/** The special's current ordinal (nth_weekday only), default 1. */
const specialOrdinal = computed(() =>
  model.value.mode === 'special' && model.value.special.kind === 'nth_weekday' ? model.value.special.ordinal : 1,
);

const ordinalModel = computed<string | null>({
  get: () => {
    if (model.value.mode === 'special' && model.value.special.kind === 'last_weekday') return 'last';
    if (model.value.mode === 'special' && model.value.special.kind === 'nth_weekday') return String(model.value.special.ordinal);
    return '1';
  },
  set: (v) => {
    if (!v) return;
    const weekday = specialWeekday.value;
    model.value =
      v === 'last'
        ? { mode: 'special', special: { kind: 'last_weekday', weekday } }
        : { mode: 'special', special: { kind: 'nth_weekday', ordinal: Number(v), weekday } };
  },
});
const weekdayModel = computed<string | null>({
  get: () => String(specialWeekday.value),
  set: (v) => {
    if (v == null) return;
    const weekday = Number(v);
    model.value =
      model.value.mode === 'special' && model.value.special.kind === 'last_weekday'
        ? { mode: 'special', special: { kind: 'last_weekday', weekday } }
        : { mode: 'special', special: { kind: 'nth_weekday', ordinal: specialOrdinal.value, weekday } };
  },
});
const showFifthNote = computed(() => ordinalModel.value === '5');

const chipBase =
  'inline-flex items-center justify-center rounded-next-md border px-next-2 py-next-1 text-next-sm ' +
  'outline-none transition-colors focus-visible:ring-2 focus-visible:ring-next-ring cursor-pointer';
function chipClass(active: boolean): string {
  return active
    ? 'border-next-primary bg-next-primary-subtle text-next-fg font-next-medium'
    : 'border-next-border bg-next-card text-next-fg hover:border-next-primary/50';
}
</script>

<template>
  <div class="flex flex-col gap-next-3">
    <WorkflowScheduleOptionCards
      v-model="submode"
      :options="modeOptions"
      :aria-label="t('workflows.schedule.tab.day')"
    >
      <!-- every_n_days — "co {n} dni [☐ od {from} do {to} dnia miesiąca]" — ONE flow (REV5). -->
      <template #body-every_n_days>
        <div class="flex flex-wrap items-center gap-next-1_5 text-next-sm text-next-fg">
          <template v-for="(seg, i) in everyNDaysHead" :key="'h' + i">
            <span v-if="seg.type === 'text'">{{ seg.value }}</span>
            <NumberInput
              v-else-if="seg.name === 'n'"
              v-model="everyNDaysN"
              :min="L.everyNDaysMin"
              :max="L.everyNDaysMax"
              class="w-20 shrink-0 basis-20"
              :aria-label="t('workflows.schedule.field.daysEvery')"
            />
          </template>
          <WorkflowScheduleWindowField
            :enabled="daysWindowOn"
            :toggle-label="t('workflows.schedule.window.toggle.days')"
            :window-template="t('workflows.schedule.day.card.everyNDays.window')"
            :error="errors.window"
            @toggle="toggleDaysWindow"
          >
            <template #from="{ disabled }">
              <NumberInput
                :model-value="daysWindowFrom"
                :min="L.monthDayMin"
                :max="L.monthDayMax"
                :disabled="disabled"
                class="w-20 shrink-0 basis-20"
                :aria-label="t('workflows.schedule.window.fromDay')"
                @update:model-value="setDaysWindow('from', $event)"
              />
            </template>
            <template #to="{ disabled }">
              <NumberInput
                :model-value="daysWindowTo"
                :min="L.monthDayMin"
                :max="L.monthDayMax"
                :disabled="disabled"
                class="w-20 shrink-0 basis-20"
                :aria-label="t('workflows.schedule.window.toDay')"
                @update:model-value="setDaysWindow('to', $event)"
              />
            </template>
          </WorkflowScheduleWindowField>
          <p v-if="errors.n" class="w-full text-next-xs text-next-danger" role="alert">{{ errors.n }}</p>
        </div>
      </template>

      <!-- weekdays — ONE wrapping flow (REV5): the lead sits INSIDE the chip group, the
           presets trail as a cluster, the error breaks to its own row. -->
      <template #body-weekdays>
        <div class="flex flex-wrap items-center gap-next-2">
          <div class="flex flex-wrap items-center gap-next-1_5" role="group" :aria-label="t('workflows.schedule.day.mode.weekdays')">
            <p class="text-next-sm text-next-fg">{{ t('workflows.schedule.day.card.weekdays.lead') }}</p>
            <button
              v-for="w in WEEKDAY_ORDER"
              :key="w"
              type="button"
              :class="[chipBase, chipClass(weekdaySelected(w))]"
              :aria-pressed="weekdaySelected(w)"
              @click="toggleWeekday(w)"
            >
              {{ t(`workflows.schedule.weekday.short.${w}`) }}
            </button>
          </div>
          <div class="flex items-center gap-next-1">
            <Button variant="ghost" size="sm" @click="setWeekdays([1, 2, 3, 4, 5])">
              {{ t('workflows.schedule.day.preset.workdays') }}
            </Button>
            <Button variant="ghost" size="sm" @click="setWeekdays([0, 6])">
              {{ t('workflows.schedule.day.preset.weekend') }}
            </Button>
          </div>
          <p v-if="errors.weekdays" class="w-full text-next-xs text-next-danger" role="alert">{{ errors.weekdays }}</p>
        </div>
      </template>

      <!-- month_days — ONE wrapping flow (REV5): the lead sits INSIDE the 1..31 chip grid. -->
      <template #body-month_days>
        <div class="flex flex-wrap items-center gap-next-2">
          <div class="flex flex-wrap items-center gap-next-1_5" role="group" :aria-label="t('workflows.schedule.day.mode.monthDays')">
            <p class="text-next-sm text-next-fg">{{ t('workflows.schedule.day.card.monthDays.lead') }}</p>
            <button
              v-for="d in MONTH_DAYS"
              :key="d"
              type="button"
              class="h-8 w-8 rounded-next-md border text-next-sm tabular-nums outline-none transition-colors focus-visible:ring-2 focus-visible:ring-next-ring cursor-pointer"
              :class="chipClass(monthDaySelected(d))"
              :aria-pressed="monthDaySelected(d)"
              @click="toggleMonthDay(d)"
            >
              {{ d }}
            </button>
          </div>
          <p v-if="errors.days" class="w-full text-next-xs text-next-danger" role="alert">{{ errors.days }}</p>
        </div>
      </template>

      <!-- last_working_day — note-only body (public holidays note + time restriction). -->
      <template #body-last_working_day>
        <div class="flex flex-col gap-next-2">
          <Alert variant="info" size="sm">{{ t('workflows.schedule.day.lastWorkingDayNote') }}</Alert>
          <p class="text-next-xs text-next-muted-foreground">{{ t('workflows.schedule.time.lockedByLastWorkingDay') }}</p>
        </div>
      </template>

      <!-- weekday_in_month — "w {ordinal} {weekday} miesiąca" — ONE flow (REV5); the note +
           error break to their own rows (w-full). -->
      <template #body-weekday_in_month>
        <div class="flex flex-wrap items-center gap-next-1_5 text-next-sm text-next-fg">
          <template v-for="(seg, i) in weekdayInMonthHead" :key="'h' + i">
            <span v-if="seg.type === 'text'">{{ seg.value }}</span>
            <Select
              v-else-if="seg.name === 'ordinal'"
              v-model="ordinalModel"
              :options="ordinalOptions"
              class="w-36 shrink-0 basis-36"
              :aria-label="t('workflows.schedule.day.ordinalLabel')"
            />
            <Select
              v-else-if="seg.name === 'weekday'"
              v-model="weekdayModel"
              :options="weekdayOptions"
              class="w-40 shrink-0 basis-40"
              :aria-label="t('workflows.schedule.day.weekdayLabel')"
            />
          </template>
          <Alert v-if="showFifthNote" variant="info" size="sm" class="w-full">{{ t('workflows.schedule.day.fifthWeekdayNote') }}</Alert>
          <p v-if="errors.ordinal || errors.weekday" class="w-full text-next-xs text-next-danger" role="alert">
            {{ errors.ordinal || errors.weekday }}
          </p>
        </div>
      </template>
    </WorkflowScheduleOptionCards>
  </div>
</template>
