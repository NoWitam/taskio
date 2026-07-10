<script setup lang="ts">
// WorkflowScheduleBuilder — the PROGRESSIVE descriptor-driven schedule builder
// (§4.5, B4 rebuild).
//
// NEVER hard-codes a family's inputs: it fetches the families + their param
// descriptors from the store (`fetchScheduleFamilies`, cached) and renders one
// control per descriptor, so it can never drift from what the backend accepts.
//
// TWO MODES (a header toggle):
//   • SIMPLE (default) — a SegmentedControl of intents (Minutes | Hours | Daily |
//     Weekly | Monthly), each mapping to a curated family + a couple of controls.
//   • ADVANCED — sections: Repeat (grouped family Select + frequency params) ·
//     Days & dates (day/weekday(s)/ordinal params) · Times (a 1..6 HH:mm editor) ·
//     Exclusions (months / weekdays / dates chips).
// A PREVIEW section is ALWAYS visible in BOTH modes: the natural-language sentence
// (describeSchedule) + a live list of the next occurrences from POST schedule-preview
// (debounced, client-valid drafts only). `empty` (exclusions strip everything) is
// treated as a validation error; `approximate` shows an info note; a network error is
// quiet + non-blocking.
//
// v-model is the local `ScheduleDraft` ({family, params, tz, times, exclusions}); the
// host wires it into trigger_config on save via `draftToConfig` (workflowSchedule.ts).
// Exposes { isValid, validationErrors } — isValid now also reflects the empty-schedule
// preview result; a server 422 per field still takes precedence (errorFor).
import { computed, onMounted, ref, watch } from 'vue';
import FormField from '../../ui/forms/FormField.vue';
import Select, { type SelectGroup, type SelectOption } from '../../ui/forms/Select.vue';
import SegmentedControl, { type SegmentOption } from '../../ui/forms/SegmentedControl.vue';
import NumberInput from '../../ui/forms/NumberInput.vue';
import TimePicker from '../../ui/forms/TimePicker.vue';
import DatePicker from '../../ui/forms/DatePicker.vue';
import TextInput from '../../ui/forms/TextInput.vue';
import RadioGroup from '../../ui/forms/RadioGroup.vue';
import Radio from '../../ui/forms/Radio.vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Alert from '../../ui/feedback/Alert.vue';
import Skeleton from '../../ui/data/Skeleton.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import Tooltip from '../../ui/overlay/Tooltip.vue';
import { useI18n } from '../../app/i18n';
import { useWorkflowsStore } from '../../app/stores/workflows';
import { useDebounce } from '../../app/composables/useDebounce';
import {
  MAX_TIMES,
  emptyScheduleDraft,
  familyHasTime,
  intentForFamily,
  isSimpleRepresentable,
  paramDescriptorsFor,
  validateScheduleDraft,
  draftToConfig,
  describeSchedule,
  occurrenceFormatter,
  formatOccurrence,
  SIMPLE_INTENT_FAMILIES,
  type ScheduleDraft,
  type ScheduleParamValue,
  type ScheduleValidationError,
  type SimpleIntent,
} from './workflowSchedule';
import type {
  ScheduleFamilyDescriptor,
  ScheduleParamDescriptor,
  WorkflowScheduleFamily,
} from './types';

const props = withDefaults(
  defineProps<{
    /**
     * Server 422 errors keyed by wire path. The builder reads
     * `trigger_config.schedule.params.<name>` / `.times` / `.exclusions.*` onto the
     * matching control and `.family` / `.tz` onto the family / tz controls. Its own
     * live client-side validation still runs; a server error takes precedence.
     */
    errors?: Record<string, string>;
  }>(),
  { errors: () => ({}) },
);

const model = defineModel<ScheduleDraft>({ required: true });

const { t, locale } = useI18n();
const store = useWorkflowsStore();

// --- Families fetch + the 4 states ------------------------------------------
const families = ref<ScheduleFamilyDescriptor[]>([]);
const loading = ref(false);
const errored = ref(false);

async function loadFamilies(): Promise<void> {
  loading.value = true;
  errored.value = false;
  try {
    families.value = await store.fetchScheduleFamilies();
    if (!model.value.family && families.value.length) {
      model.value = emptyScheduleDraft(families.value, 'daily');
    }
    // A loaded config not representable in simple mode forces advanced.
    if (!isSimpleRepresentable(model.value)) advanced.value = true;
  } catch {
    errored.value = true;
  } finally {
    loading.value = false;
  }
}

onMounted(loadFamilies);

// --- Mode (simple / advanced) -----------------------------------------------
const advanced = ref(false);
/** Whether the CURRENT draft can be represented in simple mode. */
const simpleRepresentable = computed(() => isSimpleRepresentable(model.value));

function toggleMode(): void {
  if (advanced.value) {
    // advanced → simple only when representable (guarded in the template by disabled).
    if (!simpleRepresentable.value) return;
    advanced.value = false;
  } else {
    advanced.value = true;
  }
}

// --- Tier grouping (advanced family Select) ---------------------------------
const TIERS: Array<{ heading: string; families: WorkflowScheduleFamily[] }> = [
  { heading: 'common', families: ['daily', 'weekly', 'hourly'] },
  {
    heading: 'intervals',
    families: ['every_n_minutes', 'every_n_hours', 'hourly_at', 'twice_daily', 'every_n_months'],
  },
  {
    heading: 'calendar',
    families: [
      'monthly',
      'twice_monthly',
      'last_day_of_month',
      'nth_weekday_of_month',
      'last_weekday_of_month',
      'last_working_day_of_month',
      'quarterly',
      'yearly',
    ],
  },
];

/** Only families the backend actually returned are offered (descriptor-driven). */
const availableFamilies = computed(() => families.value.map((f) => f.family));
const availableSet = computed(() => new Set(availableFamilies.value));

/** Families the backend returned but that no tier lists → the "other" fallback group. */
const otherFamilies = computed(() => {
  const listed = new Set(TIERS.flatMap((tier) => tier.families));
  return availableFamilies.value.filter((f) => !listed.has(f));
});

const familyGroups = computed<SelectGroup[]>(() => {
  const groups = TIERS.map((tier) => ({
    label: t(`workflows.schedule.tier.${tier.heading}`),
    options: tier.families
      .filter((f) => availableSet.value.has(f))
      .map<SelectOption>((f) => ({ value: f, label: t(`workflows.schedule.family.${f}`) })),
  }));
  if (otherFamilies.value.length) {
    groups.push({
      label: t('workflows.schedule.tier.other'),
      options: otherFamilies.value.map<SelectOption>((f) => ({
        value: f,
        label: t(`workflows.schedule.family.${f}`),
      })),
    });
  }
  return groups.filter((g) => g.options.length > 0);
});

// --- Family selection --------------------------------------------------------
const selectedFamily = computed<string | null>({
  get: () => model.value.family ?? null,
  set: (family) => {
    if (!family || family === model.value.family) return;
    selectFamily(family as WorkflowScheduleFamily);
  },
});

/** Seed a fresh draft for a family, preserving tz + a single time where possible. */
function selectFamily(family: WorkflowScheduleFamily): void {
  const seeded = emptyScheduleDraft(families.value, family);
  // Preserve tz always; preserve the first time when both old + new families use one.
  const keepTime =
    familyHasTime(families.value, family) && model.value.times.length > 0 && model.value.times[0] !== '';
  model.value = {
    ...seeded,
    tz: model.value.tz,
    times: seeded.times.length ? [keepTime ? model.value.times[0] : seeded.times[0]] : seeded.times,
  };
}

// --- Descriptors + generic param access -------------------------------------
const descriptors = computed<ScheduleParamDescriptor[]>(() =>
  paramDescriptorsFor(families.value, model.value.family),
);
/** The NON-time descriptors (time is rendered by the Times editor). */
const nonTimeDescriptors = computed(() => descriptors.value.filter((d) => d.type !== 'time'));
const usesTime = computed(() => familyHasTime(families.value, model.value.family));

function paramLabel(name: string): string {
  return t(`workflows.schedule.param.${name}`);
}

function paramNumber(name: string): number | null {
  const v = model.value.params[name];
  return typeof v === 'number' ? v : v === '' || v == null ? null : Number(v);
}
function setParam(name: string, value: ScheduleParamValue | null): void {
  model.value = { ...model.value, params: { ...model.value.params, [name]: value ?? '' } };
}

function weekdayValue(name: string): string | null {
  const v = paramNumber(name);
  return v == null ? null : String(v);
}
function weekdayListValue(name: string): number[] {
  const v = model.value.params[name];
  return Array.isArray(v) ? v : [];
}

// --- Weekday / month presentation (Monday-first chips; wire 0=Sunday) --------
/** Weekday chips ordered Monday..Sunday for presentation; value stays the 0..6 wire id. */
const weekdayChips = computed(() =>
  [1, 2, 3, 4, 5, 6, 0].map((i) => ({ value: i, label: t(`workflows.schedule.weekdayShort.${i}`) })),
);
const monthChips = computed(() =>
  Array.from({ length: 12 }, (_, i) => ({ value: i + 1, label: t(`workflows.schedule.month.${i + 1}`) })),
);

/** Weekday Select options 0..6, 0 = Sunday (Carbon convention). */
const weekdaySelectOptions = computed<SelectOption[]>(() =>
  Array.from({ length: 7 }, (_, i) => ({ value: String(i), label: t(`workflows.schedule.weekday.${i}`) })),
);
/** Ordinal Select options 1..5 (first..fifth). */
const ordinalOptions = computed<SelectOption[]>(() =>
  Array.from({ length: 5 }, (_, i) => ({ value: String(i + 1), label: t(`workflows.schedule.ordinal.${i + 1}`) })),
);

/** Toggle a weekday inside a weekday_list param. */
function toggleWeekdayList(name: string, day: number): void {
  const current = weekdayListValue(name);
  const set = new Set(current);
  if (set.has(day)) set.delete(day);
  else set.add(day);
  // Keep a stable Sunday-first ordering (0..6) on the wire.
  setParam(name, [0, 1, 2, 3, 4, 5, 6].filter((d) => set.has(d)));
}

// --- Times editor ------------------------------------------------------------
const times = computed(() => model.value.times);
function setTime(index: number, value: string | null): void {
  const next = [...model.value.times];
  next[index] = value ?? '';
  model.value = { ...model.value, times: next };
}
function addTime(): void {
  if (model.value.times.length >= MAX_TIMES) return;
  model.value = { ...model.value, times: [...model.value.times, ''] };
}
function removeTime(index: number): void {
  if (model.value.times.length <= 1) return;
  model.value = { ...model.value, times: model.value.times.filter((_, i) => i !== index) };
}

// --- Exclusions --------------------------------------------------------------
function toggleExclusionMonth(month: number): void {
  const set = new Set(model.value.exclusions.months);
  if (set.has(month)) set.delete(month);
  else set.add(month);
  setExclusions({ months: Array.from(set).sort((a, b) => a - b) });
}
function toggleExclusionWeekday(day: number): void {
  const set = new Set(model.value.exclusions.weekdays);
  if (set.has(day)) set.delete(day);
  else set.add(day);
  setExclusions({ weekdays: [0, 1, 2, 3, 4, 5, 6].filter((d) => set.has(d)) });
}
const newExclusionDate = ref<string | null>(null);
function addExclusionDate(): void {
  const value = newExclusionDate.value;
  if (!value) return;
  if (model.value.exclusions.dates.includes(value)) {
    newExclusionDate.value = null;
    return;
  }
  setExclusions({ dates: [...model.value.exclusions.dates, value].sort() });
  newExclusionDate.value = null;
}
function removeExclusionDate(date: string): void {
  setExclusions({ dates: model.value.exclusions.dates.filter((d) => d !== date) });
}
function setExclusions(patch: Partial<ScheduleDraft['exclusions']>): void {
  model.value = { ...model.value, exclusions: { ...model.value.exclusions, ...patch } };
}

// --- Simple-mode intent + curated controls ----------------------------------
const SIMPLE_INTENTS: SimpleIntent[] = ['minutes', 'hours', 'daily', 'weekly', 'monthly'];
/** Only intents whose (at least one) family the backend returned are offered. */
const availableIntents = computed<SimpleIntent[]>(() =>
  SIMPLE_INTENTS.filter((intent) => SIMPLE_INTENT_FAMILIES[intent].some((f) => availableSet.value.has(f))),
);
const intentOptions = computed<SegmentOption<SimpleIntent>[]>(() =>
  availableIntents.value.map((intent) => ({ value: intent, label: t(`workflows.schedule.intent.${intent}`) })),
);
const activeIntent = computed<SimpleIntent | null>(() => intentForFamily(model.value.family));

const intentModel = computed<SimpleIntent | null>({
  get: () => activeIntent.value,
  set: (intent) => {
    if (!intent || intent === activeIntent.value) return;
    // Choose the intent's DEFAULT family (its first available).
    const family = SIMPLE_INTENT_FAMILIES[intent].find((f) => availableSet.value.has(f));
    if (family) selectFamily(family);
  },
});

// Simple "Hours" sub-mode: hourly_at (N=1) vs every_n_hours (N≥2). Backed by the N field.
const hoursN = computed<number | null>({
  get: () => (model.value.family === 'hourly_at' ? 1 : paramNumber('n')),
  set: (n) => {
    const value = n ?? 1;
    if (value <= 1) {
      if (model.value.family !== 'hourly_at') selectFamily('hourly_at');
    } else {
      if (model.value.family !== 'every_n_hours') {
        selectFamily('every_n_hours');
      }
      setParam('n', value);
    }
  },
});
const hoursMinute = computed<number | null>({
  get: () => paramNumber('minute'),
  set: (m) => setParam('minute', m),
});

// Simple "Monthly" sub-mode: monthly (day) vs last_day_of_month.
const monthlyModeOptions = computed(() => {
  const opts: Array<{ value: string; label: string }> = [];
  if (availableSet.value.has('monthly')) opts.push({ value: 'monthly', label: t('workflows.schedule.simple.monthlyOnDay') });
  if (availableSet.value.has('last_day_of_month')) {
    opts.push({ value: 'last_day_of_month', label: t('workflows.schedule.simple.monthlyLastDay') });
  }
  return opts;
});
const monthlyMode = computed<string>({
  get: () => (model.value.family === 'last_day_of_month' ? 'last_day_of_month' : 'monthly'),
  set: (m) => {
    if (m !== model.value.family) selectFamily(m as WorkflowScheduleFamily);
  },
});

// A single time (simple mode always uses times[0]).
const singleTime = computed<string | null>({
  get: () => model.value.times[0] || null,
  set: (v) => setTime(0, v),
});

// --- Live validation --------------------------------------------------------
const clientErrors = computed<ScheduleValidationError[]>(() =>
  validateScheduleDraft(families.value, model.value),
);

/** A server 422 for the family / tz controls. */
const familyError = computed(() => props.errors['trigger_config.schedule.family']);
const tzError = computed(() => props.errors['trigger_config.schedule.tz']);
const timesServerError = computed(() => props.errors['trigger_config.schedule.times']);

/** The error message for a param — a server 422 takes precedence, else client. */
function errorFor(name: string): string | undefined {
  const serverError = props.errors[`trigger_config.schedule.params.${name}`];
  if (serverError) return serverError;
  const err = clientErrors.value.find((e) => e.param === name);
  if (!err) return undefined;
  const params: Record<string, string | number> = { ...(err.messageParams ?? {}) };
  if (err.key === 'workflows.schedule.validation.lt') {
    params.field = paramLabel(String(err.messageParams?.field ?? name));
    params.other = paramLabel(String(err.messageParams?.other ?? ''));
  }
  return t(err.key, '', params);
}

/** The client error for the times editor. */
const timesError = computed(() => {
  if (timesServerError.value) return timesServerError.value;
  const err = clientErrors.value.find((e) => e.param === 'times');
  if (!err) return undefined;
  return t(err.key, '', err.messageParams);
});

/** A client error for an exclusions sub-key. */
function exclusionError(key: 'months' | 'weekdays' | 'dates'): string | undefined {
  const server = props.errors[`trigger_config.schedule.exclusions.${key}`];
  if (server) return server;
  const err = clientErrors.value.find((e) => e.param === `exclusions.${key}`);
  return err ? t(err.key, '', err.messageParams) : undefined;
}

// --- Semantic helper text ----------------------------------------------------
const showDayMayskip = computed(() => {
  if (!['monthly', 'quarterly', 'yearly', 'every_n_months'].includes(model.value.family)) return false;
  const day = paramNumber('day');
  return day != null && day >= 29 && day <= 31;
});
const showLeapDay = computed(
  () => model.value.family === 'yearly' && paramNumber('month') === 2 && paramNumber('day') === 29,
);
const showHourModulo = computed(() => model.value.family === 'every_n_hours');
const showFifthWeekday = computed(
  () => model.value.family === 'nth_weekday_of_month' && paramNumber('ordinal') === 5,
);
const showEveryNMonths = computed(() => {
  if (model.value.family !== 'every_n_months') return false;
  const n = paramNumber('n');
  return n != null && 12 % n !== 0;
});
const showLastWorkingDay = computed(() => model.value.family === 'last_working_day_of_month');
const showDstNote = computed(() => model.value.tz.trim() !== '');

function switchToLastDay(): void {
  if (availableSet.value.has('last_day_of_month')) selectFamily('last_day_of_month');
}

// --- Live preview (POST schedule-preview, debounced) ------------------------
const previewOccurrences = ref<string[]>([]);
const previewEmpty = ref(false);
const previewApproximate = ref(false);
const previewLoading = ref(false);
const previewUnavailable = ref(false);
let previewToken = 0;

async function runPreview(): Promise<void> {
  const myToken = (previewToken += 1);
  previewLoading.value = true;
  previewUnavailable.value = false;
  try {
    const config = draftToConfig(model.value, families.value);
    const res = await store.schedulePreview(config, 6);
    if (myToken !== previewToken) return;
    previewOccurrences.value = res.occurrences;
    previewEmpty.value = res.empty;
    previewApproximate.value = res.approximate;
  } catch {
    if (myToken !== previewToken) return;
    // Quiet, non-blocking: the sentence stays; the list is unavailable.
    previewUnavailable.value = true;
    previewOccurrences.value = [];
    previewEmpty.value = false;
    previewApproximate.value = false;
  } finally {
    if (myToken === previewToken) previewLoading.value = false;
  }
}

const debouncedPreview = useDebounce(runPreview, 400);

// Only preview when the client-side validation passes (no wasted 422s).
const clientValid = computed(() => clientErrors.value.length === 0);

/** (Re)schedule the debounced preview, or clear it when the draft can't be previewed. */
function schedulePreviewRefresh(): void {
  if (!clientValid.value || !families.value.length) {
    debouncedPreview.cancel();
    previewToken += 1; // drop any in-flight result
    previewLoading.value = false;
    previewOccurrences.value = [];
    previewEmpty.value = false;
    previewApproximate.value = false;
    previewUnavailable.value = false;
    return;
  }
  previewLoading.value = true;
  debouncedPreview();
}

// Re-preview whenever the draft, its validity, or the loaded families change. Runs
// immediately so a valid seeded draft previews as soon as the families resolve.
watch(
  () => [model.value, clientValid.value, families.value.length] as const,
  schedulePreviewRefresh,
  { deep: true, immediate: true },
);

/** The natural-language sentence, always current. */
const sentence = computed(() => {
  if (!families.value.length) return '';
  return describeSchedule(draftToConfig(model.value, families.value), t);
});

/** Localized occurrence rendering (weekday + date + time in the draft tz) — shared helper. */
const dateFormatter = computed(() => occurrenceFormatter(locale.value, model.value.tz));
function formatOccurrenceRow(iso: string): string {
  return formatOccurrence(iso, dateFormatter.value);
}

// --- Exposed validity (client errors + the empty-schedule preview) ----------
// A LOADING preview also blocks: previewEmpty still holds the PREVIOUS settled value
// during the debounce+RTT window, so saving mid-flight could slip an empty schedule
// past the gate (the server would 422 it, but as a raw toast instead of the in-builder
// warning). A network-failed preview does NOT block — the server stays authoritative.
const isValid = computed(() => clientValid.value && !previewEmpty.value && !previewLoading.value);
defineExpose({ isValid, validationErrors: clientErrors });
</script>

<template>
  <div class="flex flex-col gap-next-4">
    <!-- Loading: skeletons mimicking the mode toggle + the picker. -->
    <div v-if="loading" class="flex flex-col gap-next-3" role="status" :aria-label="t('workflows.schedule.loading')">
      <Skeleton variant="rect" width="10rem" height="2rem" />
      <Skeleton variant="rect" height="2.5rem" />
      <Skeleton variant="rect" height="4rem" />
    </div>

    <!-- Error: inline Alert + retry. -->
    <Alert v-else-if="errored" variant="danger" size="sm">
      {{ t('workflows.schedule.loadError') }}
      <template #actions>
        <Button variant="outline" size="sm" leading-icon="rotate-ccw" @click="loadFamilies">
          {{ t('workflows.schedule.retry') }}
        </Button>
      </template>
    </Alert>

    <!-- Empty: no families from the backend (never expected). -->
    <EmptyState
      v-else-if="familyGroups.length === 0"
      variant="default"
      icon="clock"
      size="sm"
      :title="t('workflows.schedule.emptyTitle')"
      :description="t('workflows.schedule.emptyDescription')"
    />

    <!-- Success: the builder. -->
    <template v-else>
      <!-- Header: mode toggle (aria-pressed). -->
      <div class="flex items-center justify-end">
        <Tooltip v-if="advanced && !simpleRepresentable" :label="t('workflows.schedule.simpleUnavailable')">
          <Button variant="ghost" size="sm" leading-icon="settings" disabled :aria-pressed="advanced">
            {{ t('workflows.schedule.simpleToggle') }}
          </Button>
        </Tooltip>
        <Button
          v-else
          variant="ghost"
          size="sm"
          leading-icon="settings"
          :aria-pressed="advanced"
          @click="toggleMode"
        >
          {{ advanced ? t('workflows.schedule.simpleToggle') : t('workflows.schedule.advancedToggle') }}
        </Button>
      </div>

      <!-- ============================ SIMPLE MODE ============================ -->
      <template v-if="!advanced">
        <FormField :label="t('workflows.schedule.mode.simpleLabel')" :error="familyError">
          <SegmentedControl
            v-model="intentModel"
            :options="intentOptions"
            equal-width
            :aria-label="t('workflows.schedule.mode.simpleLabel')"
          />
        </FormField>

        <!-- MINUTES → every_n_minutes -->
        <div v-if="activeIntent === 'minutes'" class="grid grid-cols-1 gap-next-4 next-sm:grid-cols-[12rem_1fr]">
          <FormField :label="t('workflows.schedule.simple.minutesLabel')" required :error="errorFor('n')">
            <NumberInput
              :model-value="paramNumber('n')"
              :min="1"
              :max="59"
              :suffix="t('workflows.schedule.simple.minutesUnit')"
              :aria-label="t('workflows.schedule.simple.minutesLabel')"
              @update:model-value="(v) => setParam('n', v)"
            />
          </FormField>
        </div>

        <!-- HOURS → hourly_at (N=1) | every_n_hours (N≥2) -->
        <template v-else-if="activeIntent === 'hours'">
          <div class="grid grid-cols-1 gap-next-4 next-sm:grid-cols-[12rem_1fr]">
            <FormField :label="t('workflows.schedule.simple.hoursIntervalLabel')" required>
              <NumberInput
                v-model="hoursN"
                :min="1"
                :max="12"
                :suffix="t('workflows.schedule.simple.hoursUnit')"
                :aria-label="t('workflows.schedule.simple.hoursIntervalLabel')"
              />
            </FormField>
          </div>
          <div class="grid grid-cols-1 gap-next-4 next-sm:grid-cols-[12rem_1fr]">
            <FormField :label="t('workflows.schedule.simple.hoursMinuteLabel')" :error="errorFor('minute')">
              <NumberInput
                v-model="hoursMinute"
                :min="0"
                :max="59"
                :aria-label="t('workflows.schedule.simple.hoursMinuteLabel')"
              />
            </FormField>
          </div>
        </template>

        <!-- DAILY → daily -->
        <div v-else-if="activeIntent === 'daily'" class="grid grid-cols-1 gap-next-4 next-sm:grid-cols-[12rem_1fr]">
          <FormField :label="t('workflows.schedule.simple.dailyLabel')" required :error="timesError">
            <TimePicker v-model="singleTime" :aria-label="t('workflows.schedule.simple.dailyLabel')" />
          </FormField>
        </div>

        <!-- WEEKLY → weekly (weekday chips + one time) -->
        <template v-else-if="activeIntent === 'weekly'">
          <FormField :label="t('workflows.schedule.simple.weeklyDaysLabel')" required :error="errorFor('weekdays')">
            <div class="flex flex-wrap gap-next-2" role="group" :aria-label="t('workflows.schedule.simple.weeklyDaysLabel')">
              <Button
                v-for="chip in weekdayChips"
                :key="chip.value"
                variant="outline"
                size="sm"
                :aria-pressed="weekdayListValue('weekdays').includes(chip.value)"
                :class="weekdayListValue('weekdays').includes(chip.value) ? 'border-next-primary bg-next-primary-subtle text-next-primary-subtle-foreground' : ''"
                @click="toggleWeekdayList('weekdays', chip.value)"
              >
                {{ chip.label }}
              </Button>
            </div>
          </FormField>
          <div class="grid grid-cols-1 gap-next-4 next-sm:grid-cols-[12rem_1fr]">
            <FormField :label="t('workflows.schedule.simple.weeklyTimeLabel')" required :error="timesError">
              <TimePicker v-model="singleTime" :aria-label="t('workflows.schedule.simple.weeklyTimeLabel')" />
            </FormField>
          </div>
        </template>

        <!-- MONTHLY → monthly (day) | last_day_of_month -->
        <template v-else-if="activeIntent === 'monthly'">
          <FormField :label="t('workflows.schedule.simple.monthlyModeLabel')">
            <RadioGroup v-model="monthlyMode" :aria-label="t('workflows.schedule.simple.monthlyModeLabel')">
              <Radio
                v-for="opt in monthlyModeOptions"
                :key="opt.value"
                :value="opt.value"
                :label="opt.label"
              />
            </RadioGroup>
          </FormField>
          <div v-if="monthlyMode === 'monthly'" class="grid grid-cols-1 gap-next-4 next-sm:grid-cols-[12rem_1fr]">
            <FormField :label="t('workflows.schedule.simple.monthlyDayLabel')" required :error="errorFor('day')">
              <NumberInput
                :model-value="paramNumber('day')"
                :min="1"
                :max="31"
                :aria-label="t('workflows.schedule.simple.monthlyDayLabel')"
                @update:model-value="(v) => setParam('day', v)"
              />
            </FormField>
          </div>
          <div class="grid grid-cols-1 gap-next-4 next-sm:grid-cols-[12rem_1fr]">
            <FormField :label="t('workflows.schedule.simple.monthlyTimeLabel')" required :error="timesError">
              <TimePicker v-model="singleTime" :aria-label="t('workflows.schedule.simple.monthlyTimeLabel')" />
            </FormField>
          </div>
          <Alert v-if="showDayMayskip" variant="info" size="sm">
            {{ t('workflows.schedule.help.dayMayskip') }}
            <template #actions>
              <Button variant="link" size="sm" @click="switchToLastDay">
                {{ t('workflows.schedule.help.switchToLastDay') }}
              </Button>
            </template>
          </Alert>
        </template>
      </template>

      <!-- ============================ ADVANCED MODE ============================ -->
      <template v-else>
        <!-- SECTION 1 — Repeat: family Select + frequency params. -->
        <section class="flex flex-col gap-next-3">
          <h4 class="text-next-sm font-next-semibold text-next-fg">{{ t('workflows.schedule.section.repeat') }}</h4>
          <FormField :label="t('workflows.schedule.moreLabel')" :error="familyError">
            <Select
              v-model="selectedFamily"
              :groups="familyGroups"
              leading-icon="clock"
              :placeholder="t('workflows.schedule.familyPlaceholder')"
              :aria-label="t('workflows.schedule.moreLabel')"
            />
          </FormField>

          <!-- Frequency params: n / minute (in the repeat section). -->
          <template v-for="descriptor in nonTimeDescriptors" :key="`freq-${descriptor.name}`">
            <div
              v-if="descriptor.name === 'n' || descriptor.name === 'minute'"
              class="grid grid-cols-1 gap-next-4 next-sm:grid-cols-[12rem_1fr]"
            >
              <FormField :label="paramLabel(descriptor.name)" :required="descriptor.required" :error="errorFor(descriptor.name)">
                <NumberInput
                  :model-value="paramNumber(descriptor.name)"
                  :min="descriptor.min"
                  :max="descriptor.max"
                  :aria-label="paramLabel(descriptor.name)"
                  @update:model-value="(v) => setParam(descriptor.name, v)"
                />
              </FormField>
            </div>
          </template>

          <Alert v-if="showHourModulo" variant="info" size="sm">{{ t('workflows.schedule.help.hourModulo') }}</Alert>
          <Alert v-if="showEveryNMonths" variant="info" size="sm">{{ t('workflows.schedule.help.everyNMonths') }}</Alert>
          <Alert v-if="showLastWorkingDay" variant="info" size="sm">{{ t('workflows.schedule.help.lastWorkingDay') }}</Alert>
        </section>

        <!-- SECTION 2 — Days & dates: day / weekday(s) / ordinal params. -->
        <section
          v-if="nonTimeDescriptors.some((d) => d.name !== 'n' && d.name !== 'minute')"
          class="flex flex-col gap-next-3"
        >
          <h4 class="text-next-sm font-next-semibold text-next-fg">{{ t('workflows.schedule.section.daysAndDates') }}</h4>
          <template v-for="descriptor in nonTimeDescriptors" :key="`day-${descriptor.name}`">
            <!-- weekday_list → Monday-first chips. -->
            <FormField
              v-if="descriptor.type === 'weekday_list'"
              :label="paramLabel(descriptor.name)"
              :required="descriptor.required"
              :error="errorFor(descriptor.name)"
            >
              <div class="flex flex-wrap gap-next-2" role="group" :aria-label="paramLabel(descriptor.name)">
                <Button
                  v-for="chip in weekdayChips"
                  :key="chip.value"
                  variant="outline"
                  size="sm"
                  :aria-pressed="weekdayListValue(descriptor.name).includes(chip.value)"
                  :class="weekdayListValue(descriptor.name).includes(chip.value) ? 'border-next-primary bg-next-primary-subtle text-next-primary-subtle-foreground' : ''"
                  @click="toggleWeekdayList(descriptor.name, chip.value)"
                >
                  {{ chip.label }}
                </Button>
              </div>
            </FormField>

            <!-- ordinal → Select first..fifth. -->
            <div
              v-else-if="descriptor.name === 'ordinal'"
              class="grid grid-cols-1 gap-next-4 next-sm:grid-cols-[12rem_1fr]"
            >
              <FormField :label="paramLabel(descriptor.name)" :required="descriptor.required" :error="errorFor(descriptor.name)">
                <Select
                  :model-value="weekdayValue(descriptor.name)"
                  :options="ordinalOptions"
                  :aria-label="paramLabel(descriptor.name)"
                  @update:model-value="(v) => setParam(descriptor.name, v == null ? null : Number(v))"
                />
              </FormField>
            </div>

            <!-- weekday (scalar) → Select 0..6. -->
            <div
              v-else-if="descriptor.type === 'weekday'"
              class="grid grid-cols-1 gap-next-4 next-sm:grid-cols-[12rem_1fr]"
            >
              <FormField :label="paramLabel(descriptor.name)" :required="descriptor.required" :error="errorFor(descriptor.name)">
                <Select
                  :model-value="weekdayValue(descriptor.name)"
                  :options="weekdaySelectOptions"
                  :aria-label="paramLabel(descriptor.name)"
                  @update:model-value="(v) => setParam(descriptor.name, v == null ? null : Number(v))"
                />
              </FormField>
            </div>

            <!-- day / first_day / second_day / month → NumberInput. -->
            <div
              v-else-if="descriptor.type === 'int' && descriptor.name !== 'n' && descriptor.name !== 'minute'"
              class="grid grid-cols-1 gap-next-4 next-sm:grid-cols-[12rem_1fr]"
            >
              <FormField :label="paramLabel(descriptor.name)" :required="descriptor.required" :error="errorFor(descriptor.name)">
                <NumberInput
                  :model-value="paramNumber(descriptor.name)"
                  :min="descriptor.min"
                  :max="descriptor.max"
                  :aria-label="paramLabel(descriptor.name)"
                  @update:model-value="(v) => setParam(descriptor.name, v)"
                />
              </FormField>
            </div>
          </template>

          <Alert v-if="showDayMayskip" variant="info" size="sm">
            {{ t('workflows.schedule.help.dayMayskip') }}
            <template #actions>
              <Button variant="link" size="sm" @click="switchToLastDay">
                {{ t('workflows.schedule.help.switchToLastDay') }}
              </Button>
            </template>
          </Alert>
          <Alert v-if="showLeapDay" variant="info" size="sm">{{ t('workflows.schedule.help.leapDay') }}</Alert>
          <Alert v-if="showFifthWeekday" variant="info" size="sm">{{ t('workflows.schedule.help.fifthWeekday') }}</Alert>
        </section>

        <!-- SECTION 3 — Times: 1..6 HH:mm editor (families with a time param). -->
        <section class="flex flex-col gap-next-3">
          <h4 class="text-next-sm font-next-semibold text-next-fg">{{ t('workflows.schedule.section.times') }}</h4>
          <p v-if="!usesTime" class="text-next-xs text-next-muted-foreground">
            <Icon name="info" class="mr-next-1 inline align-text-bottom" aria-hidden="true" />
            {{ t('workflows.schedule.times.selfPaced') }}
          </p>
          <template v-else>
            <div class="flex flex-col gap-next-2">
              <div v-for="(tm, i) in times" :key="i" class="flex items-center gap-next-2">
                <TimePicker
                  :model-value="tm || null"
                  class="max-w-[10rem]"
                  :aria-label="t('workflows.schedule.times.heading')"
                  @update:model-value="(v) => setTime(i, v)"
                />
                <Button
                  v-if="times.length > 1"
                  variant="ghost"
                  size="icon-xs"
                  leading-icon="x"
                  :aria-label="t('workflows.schedule.times.remove')"
                  @click="removeTime(i)"
                />
              </div>
            </div>
            <p v-if="timesError" class="text-next-xs text-next-danger">{{ timesError }}</p>
            <div>
              <Button
                variant="outline"
                size="sm"
                leading-icon="plus"
                :disabled="times.length >= MAX_TIMES"
                @click="addTime"
              >
                {{ t('workflows.schedule.times.add') }}
              </Button>
            </div>
          </template>
        </section>

        <!-- SECTION 4 — Exclusions: months / weekdays / dates. -->
        <section class="flex flex-col gap-next-3">
          <h4 class="text-next-sm font-next-semibold text-next-fg">{{ t('workflows.schedule.section.exclusions') }}</h4>
          <p class="text-next-xs text-next-muted-foreground">{{ t('workflows.schedule.exclusions.hint') }}</p>

          <!-- Months (12 chips). -->
          <FormField :label="t('workflows.schedule.exclusions.monthsLabel')" :error="exclusionError('months')">
            <div class="flex flex-wrap gap-next-2" role="group" :aria-label="t('workflows.schedule.exclusions.monthsLabel')">
              <Button
                v-for="chip in monthChips"
                :key="chip.value"
                variant="outline"
                size="sm"
                :aria-pressed="model.exclusions.months.includes(chip.value)"
                :class="model.exclusions.months.includes(chip.value) ? 'border-next-danger bg-next-danger-subtle text-next-danger-subtle-foreground' : ''"
                @click="toggleExclusionMonth(chip.value)"
              >
                {{ chip.label }}
              </Button>
            </div>
          </FormField>

          <!-- Weekdays (7 chips, Monday-first). -->
          <FormField :label="t('workflows.schedule.exclusions.weekdaysLabel')" :error="exclusionError('weekdays')">
            <div class="flex flex-wrap gap-next-2" role="group" :aria-label="t('workflows.schedule.exclusions.weekdaysLabel')">
              <Button
                v-for="chip in weekdayChips"
                :key="chip.value"
                variant="outline"
                size="sm"
                :aria-pressed="model.exclusions.weekdays.includes(chip.value)"
                :class="model.exclusions.weekdays.includes(chip.value) ? 'border-next-danger bg-next-danger-subtle text-next-danger-subtle-foreground' : ''"
                @click="toggleExclusionWeekday(chip.value)"
              >
                {{ chip.label }}
              </Button>
            </div>
          </FormField>

          <!-- Specific dates (DatePicker + Add → removable list). -->
          <FormField :label="t('workflows.schedule.exclusions.datesLabel')" :error="exclusionError('dates')">
            <div class="flex flex-col gap-next-2">
              <div class="flex items-center gap-next-2">
                <DatePicker
                  v-model="newExclusionDate"
                  class="max-w-[12rem]"
                  :aria-label="t('workflows.schedule.exclusions.datesLabel')"
                />
                <Button
                  variant="outline"
                  size="sm"
                  leading-icon="plus"
                  :disabled="!newExclusionDate"
                  @click="addExclusionDate"
                >
                  {{ t('workflows.schedule.exclusions.addDate') }}
                </Button>
              </div>
              <p v-if="model.exclusions.dates.length === 0" class="text-next-xs text-next-muted-foreground">
                {{ t('workflows.schedule.exclusions.datesEmpty') }}
              </p>
              <ul v-else class="flex flex-wrap gap-next-2">
                <li
                  v-for="date in model.exclusions.dates"
                  :key="date"
                  class="inline-flex items-center gap-next-1 rounded-next-md bg-next-muted px-next-2 py-next-1 text-next-xs text-next-fg"
                >
                  <span class="font-next-mono">{{ date }}</span>
                  <Button
                    variant="ghost"
                    size="icon-xs"
                    leading-icon="x"
                    :aria-label="t('workflows.schedule.exclusions.removeDate')"
                    @click="removeExclusionDate(date)"
                  />
                </li>
              </ul>
            </div>
          </FormField>
        </section>

        <!-- Timezone (optional). -->
        <div class="grid grid-cols-1 gap-next-4 next-sm:grid-cols-[12rem_1fr]">
          <FormField :label="t('workflows.schedule.tzLabel')" :description="t('workflows.schedule.tzHint')" :error="tzError">
            <TextInput
              v-model="model.tz"
              leading-icon="clock"
              :placeholder="t('workflows.schedule.utc')"
              :aria-label="t('workflows.schedule.tzLabel')"
            />
          </FormField>
        </div>

        <Alert v-if="showDstNote" variant="info" size="sm">{{ t('workflows.schedule.help.dstNote') }}</Alert>
      </template>

      <!-- ======================= PREVIEW (both modes) ======================= -->
      <section class="flex flex-col gap-next-3 rounded-next-lg border border-next-border bg-next-muted/20 p-next-4">
        <div class="flex items-center gap-next-2">
          <Icon name="calendar" class="text-next-muted-foreground" aria-hidden="true" />
          <h4 class="text-next-sm font-next-semibold text-next-fg">{{ t('workflows.schedule.section.preview') }}</h4>
        </div>

        <!-- The always-current natural-language sentence. -->
        <p v-if="sentence" class="text-next-sm text-next-fg">{{ sentence }}</p>

        <!-- empty → treated as an error (save blocked; server would reject too). -->
        <Alert v-if="previewEmpty" variant="warning" size="sm">{{ t('workflows.schedule.preview.empty') }}</Alert>

        <!-- approximate → indicative dates. -->
        <Alert v-else-if="previewApproximate && previewOccurrences.length" variant="info" size="sm">
          {{ t('workflows.schedule.preview.approximate') }}
        </Alert>

        <!-- Loading skeleton rows. -->
        <div v-if="previewLoading" class="flex flex-col gap-next-2" role="status" :aria-label="t('workflows.schedule.preview.loading')">
          <Skeleton v-for="n in 4" :key="n" variant="text" width="70%" />
        </div>

        <!-- The occurrences list. -->
        <ul v-else-if="!previewEmpty && previewOccurrences.length" class="flex flex-col gap-next-1">
          <li
            v-for="(occ, i) in previewOccurrences"
            :key="i"
            class="flex items-center gap-next-2 text-next-sm text-next-muted-foreground"
          >
            <Icon name="clock" class="shrink-0 text-next-muted-foreground" aria-hidden="true" />
            <span class="tabular-nums">{{ formatOccurrenceRow(occ) }}</span>
          </li>
        </ul>

        <!-- Network error → quiet, non-blocking note. -->
        <p v-else-if="previewUnavailable" class="text-next-xs text-next-muted-foreground">
          {{ t('workflows.schedule.preview.unavailable') }}
        </p>
      </section>
    </template>
  </div>
</template>
