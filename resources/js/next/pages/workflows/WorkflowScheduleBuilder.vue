<script setup lang="ts">
// WorkflowScheduleBuilder — the v2 compositional schedule builder (§4.5, REV5). It owns
// the single `ScheduleDraft` v-model and renders, top→bottom:
//   1. ONE compact HEADER SEGMENT — a `Surface bg="muted"` frame holding the live summary
//      sentence + a compact "Skocz do daty" date FIELD + the "Zaplanuj z AI" button (row 1)
//      over the compact upcoming-runs preview rail (row 2). The host owns the shared `anchor`
//      (§4.5.2–§4.5.4).
//   2. a three-tab manual builder (Czas | Dzień | Miesiąc), each tab a
//      WorkflowScheduleOptionCards radio-group whose SELECTED card expands with its
//      in-sentence inputs — NEVER tabs-in-tabs (Tabs switches the AXIS; the cards switch
//      the SUB-MODE),
//   3. a collapsed-by-default "Wyjątki" (exceptions) Accordion — REV5: skip-DATES only
//      (the weekday/month exclusion chips are gone; the axes cover that — §4.5.7),
//   4. the AI assist MODAL (opened from the summary; reviews then commits via "Zastosuj").
//
// REV5 removed the tz field from the UI (§4.5.8) — the MODEL still carries `tz` (seeded to
// the browser zone by `emptyScheduleDraft`), but nothing in the panel edits it; a
// `schedule.tz` 422 has no control and surfaces via the drawer's generic danger toast.
//
// It exposes `isValid` (client rules ∧ !preview.empty ∧ !preview.loading, §4.5.11) +
// `validationErrors` for the drawer's step/save gate, coordinates the ONE cross-axis
// coupling — `last_working_day` LOCKS the time axis to `at` — and maps a server 422 onto
// the offending tab / the exceptions disclosure (§4.5.11).
import { computed, ref, watch } from 'vue';
import Tabs, { type TabItem } from '../../ui/navigation/Tabs.vue';
import Accordion from '../../ui/disclosure/Accordion.vue';
import AccordionItem from '../../ui/disclosure/AccordionItem.vue';
import FormField from '../../ui/forms/FormField.vue';
import DatePicker from '../../ui/forms/DatePicker.vue';
import DateTimePicker from '../../ui/forms/DateTimePicker.vue';
import Button from '../../ui/primitives/Button.vue';
import Badge from '../../ui/primitives/Badge.vue';
import Surface from '../../ui/layout/Surface.vue';
import WorkflowScheduleSummary from './WorkflowScheduleSummary.vue';
import WorkflowSchedulePreviewStrip from './WorkflowSchedulePreviewStrip.vue';
import WorkflowScheduleAssistModal from './WorkflowScheduleAssistModal.vue';
import WorkflowScheduleTimePanel from './WorkflowScheduleTimePanel.vue';
import WorkflowScheduleDayPanel from './WorkflowScheduleDayPanel.vue';
import WorkflowScheduleMonthPanel from './WorkflowScheduleMonthPanel.vue';
import { useI18n } from '../../app/i18n';
import {
  draftToConfig,
  resolveBrowserZone,
  validateScheduleDraft,
  SCHEDULE_LIMITS,
  type DayAxis,
  type MonthAxis,
  type ScheduleDraft,
  type ScheduleExclusions,
  type TimeAxis,
} from './workflowSchedule';

const props = withDefaults(
  defineProps<{
    /** Server 422 errors keyed by wire path (mapped to the offending tab, §4.5.11). */
    errors?: Record<string, string>;
    /** The user's active tz, forwarded to the AI modal as a hint (§4.5.8). */
    tz?: string | null;
  }>(),
  { errors: () => ({}), tz: null },
);

const model = defineModel<ScheduleDraft>({ required: true });
const { t } = useI18n();
const L = SCHEDULE_LIMITS;

/** The viewer's active zone — feeds the conditional tz clause in the summary (§4.5.10). */
const browserTz = resolveBrowserZone();

type TabValue = 'time' | 'day' | 'month';
const activeTab = ref<TabValue>('time');
const assistOpen = ref(false);
const exclusionsOpen = ref<string | null>(null);
const stripRef = ref<InstanceType<typeof WorkflowSchedulePreviewStrip> | null>(null);

/** The shared "jump to date" anchor — owned by the HOST so the row trigger + the rail
 *  stay in sync (§4.5.3/§4.5.4). A local ISO datetime, or null (the default "now" view). */
const anchor = ref<string | null>(null);

// --- Draft mutation (always replace so update:modelValue fires) --------------
function setTime(time: TimeAxis): void {
  model.value = { ...model.value, time };
}
function setDay(day: DayAxis): void {
  model.value = { ...model.value, day };
}
function setMonth(month: MonthAxis): void {
  model.value = { ...model.value, month };
}
function setExclusions(exclusions: ScheduleExclusions): void {
  model.value = { ...model.value, exclusions };
}

const timeAxis = computed<TimeAxis>({ get: () => model.value.time, set: setTime });
const dayAxis = computed<DayAxis>({ get: () => model.value.day, set: setDay });
const monthAxis = computed<MonthAxis>({ get: () => model.value.month, set: setMonth });

// --- Validation + save gate (§4.5.11) ---------------------------------------
const clientErrors = computed(() => validateScheduleDraft(model.value));
const clientValid = computed(() => clientErrors.value.length === 0);
const config = computed(() => draftToConfig(model.value));

const isValid = computed(
  () => clientValid.value && !(stripRef.value?.empty ?? false) && !(stripRef.value?.loading ?? false),
);
defineExpose({ isValid, validationErrors: clientErrors });

/** Resolve a control's error: the client rule first, else the mapped 422 (§4.5.11). */
function errorFor(path: string): string | undefined {
  const client = clientErrors.value.find((e) => e.path === path);
  if (client) return t(client.key, undefined, client.messageParams);
  return props.errors[`trigger_config.schedule.${path}`];
}

const timeErrors = computed(() => ({
  at: errorFor('time.at'),
  n: errorFor('time.n'),
  minute: errorFor('time.minute'),
  window: errorFor('time.window'),
}));
const dayErrors = computed(() => ({
  n: errorFor('day.n'),
  window: errorFor('day.window'),
  weekdays: errorFor('day.weekdays'),
  days: errorFor('day.days'),
  ordinal: errorFor('day.special.ordinal'),
  weekday: errorFor('day.special.weekday'),
}));
const monthErrors = computed(() => ({
  n: errorFor('month.n'),
  window: errorFor('month.window'),
  months: errorFor('month.months'),
}));

// --- last_working_day coupling: LOCK the time axis to `at` (§4.5.5a) ---------
const lockedToAt = computed(
  () => model.value.day.mode === 'special' && model.value.day.special.kind === 'last_working_day',
);
const switchedToAt = ref(false);
watch(lockedToAt, (locked) => {
  if (!locked) {
    switchedToAt.value = false;
    return;
  }
  if (model.value.time.mode !== 'at') {
    setTime({ mode: 'at', at: ['09:00'] });
    switchedToAt.value = true;
  }
});

// --- Tabs --------------------------------------------------------------------
const tabItems = computed<TabItem<TabValue>[]>(() => [
  { value: 'time', label: t('workflows.schedule.tab.time'), icon: 'clock' },
  { value: 'day', label: t('workflows.schedule.tab.day'), icon: 'calendar' },
  { value: 'month', label: t('workflows.schedule.tab.month'), icon: 'hash' },
]);

// --- 422 → tab / disclosure mapping (§4.5.11) --------------------------------
// A `schedule.tz` 422 maps to NO tab (the field is gone, §4.5.8) — it is surfaced by the
// drawer's generic danger toast instead, exactly as any un-mappable 422.
watch(
  () => props.errors,
  (errs) => {
    const keys = Object.keys(errs ?? {});
    const P = 'trigger_config.schedule.';
    if (keys.some((k) => k.startsWith(`${P}time`))) activeTab.value = 'time';
    else if (keys.some((k) => k.startsWith(`${P}day`))) activeTab.value = 'day';
    else if (keys.some((k) => k.startsWith(`${P}month`))) activeTab.value = 'month';
    if (keys.some((k) => k.startsWith(`${P}exclusions`))) exclusionsOpen.value = 'exclusions';
  },
  { deep: true },
);

// --- Exceptions (§4.5.7) — skip-DATES only -----------------------------------
const newExclusionDate = ref<string | null>(null);
const exclusionDates = computed(() => [...model.value.exclusions.dates].sort());
const atDatesMax = computed(() => model.value.exclusions.dates.length >= L.exclusionsDatesMax);
const exclusionsDatesError = computed(() => errorFor('exclusions.dates'));

function addExclusionDate(): void {
  const d = newExclusionDate.value;
  const ex = model.value.exclusions;
  if (!d || ex.dates.includes(d) || ex.dates.length >= L.exclusionsDatesMax) return;
  setExclusions({ ...ex, dates: [...ex.dates, d].sort() });
  newExclusionDate.value = null;
}
function removeExclusionDate(date: string): void {
  const ex = model.value.exclusions;
  setExclusions({ ...ex, dates: ex.dates.filter((d) => d !== date) });
}
/** The disclosure badge counts the DATES only (weekday/month exclusions are no longer
 *  authored in the UI — §4.5.7 — though the model/wire still carry them). */
const exclusionsCount = computed(() => model.value.exclusions.dates.length);

// --- AI modal apply ----------------------------------------------------------
function onApply(draft: ScheduleDraft): void {
  model.value = draft;
}
</script>

<template>
  <div class="flex flex-col gap-next-4">
    <!-- 1. Compact HEADER SEGMENT: the summary row + the preview rail in ONE muted frame. -->
    <Surface bg="muted" border radius="lg" class="flex flex-col gap-next-3 p-next-3">
      <WorkflowScheduleSummary :draft="model" :active-tz="browserTz" @assist="assistOpen = true">
        <!-- "Skocz do daty" — a COMPACT, FIRST-LEVEL DateTimePicker (§4.5.3). Rendering it
             directly (NOT nested in a Popover) lets its calendar open as a first-level overlay:
             the old popover-in-popover treated a click inside the teleported calendar as
             "outside" and self-closed, so it was unusable. An active anchor tints the field
             (`dirty` → primary accent). REV5.1: the clear ✕ lives INSIDE the field (the
             DateTimePicker's own `clearable`, default on) — it shows only when an anchor is
             set and clears it back to null; no external sibling button. -->
        <template #jump>
          <div class="w-56">
            <DateTimePicker
              v-model="anchor"
              size="sm"
              :dirty="!!anchor"
              :placeholder="t('workflows.schedule.preview.jumpTo')"
              :aria-label="t('workflows.schedule.preview.jumpTo')"
            />
          </div>
        </template>
      </WorkflowScheduleSummary>

      <!-- Upcoming-runs rail (the concrete effect of the AND-composition). -->
      <WorkflowSchedulePreviewStrip
        ref="stripRef"
        :config="config"
        :tz="model.tz"
        :anchor="anchor"
        :disabled="!clientValid"
      />
    </Surface>

    <!-- 2. The three-tab manual builder. -->
    <Tabs v-model="activeTab" :items="tabItems" variant="underline" size="md" :aria-label="t('workflows.schedule.tabsAria')">
      <template #panel-time>
        <WorkflowScheduleTimePanel
          v-model="timeAxis"
          :locked="lockedToAt"
          :switched-to-at="switchedToAt"
          :errors="timeErrors"
        />
      </template>
      <template #panel-day>
        <WorkflowScheduleDayPanel v-model="dayAxis" :errors="dayErrors" />
      </template>
      <template #panel-month>
        <WorkflowScheduleMonthPanel v-model="monthAxis" :errors="monthErrors" />
      </template>
    </Tabs>

    <!-- 3. Exceptions — collapsed by default; skip-DATES only (§4.5.7). -->
    <Accordion v-model="exclusionsOpen" type="single">
      <AccordionItem value="exclusions" icon="x-circle">
        <template #header>
          <span class="flex items-center gap-next-2">
            {{ t('workflows.schedule.exclusions.title') }}
            <Badge v-if="exclusionsCount > 0" variant="neutral" tone="subtle" size="sm">{{ exclusionsCount }}</Badge>
          </span>
        </template>

        <div class="flex flex-col gap-next-4">
          <p class="text-next-xs text-next-muted-foreground">{{ t('workflows.schedule.exclusions.hint') }}</p>

          <!-- Skip specific dates — a FUSED entry group (picker + add read as one control,
               sized to the date, never full-width) with the added dates as chips on the
               SAME wrapping line (REV5.2). -->
          <FormField :label="t('workflows.schedule.exclusions.datesLabel')" :error="exclusionsDatesError">
            <div class="flex flex-wrap items-center gap-next-2">
              <!-- The picker sits in a FIXED-WIDTH wrapper (Popover-based fields drop the
                   class attr; the trigger chain needs a forced w-full) and the well is
                   shrink-0 so the wrapping row can never squeeze it into overlap. -->
              <div class="inline-flex shrink-0 items-center gap-next-1 rounded-next-lg border border-next-border bg-next-muted p-next-1">
                <div class="w-44 shrink-0 [&>div]:w-full">
                  <DatePicker
                    v-model="newExclusionDate"
                    :aria-label="t('workflows.schedule.exclusions.datesLabel')"
                  />
                </div>
                <Button
                  variant="ghost"
                  size="sm"
                  leading-icon="plus"
                  :disabled="!newExclusionDate || atDatesMax"
                  @click="addExclusionDate"
                >
                  {{ t('workflows.schedule.exclusions.addDate') }}
                </Button>
              </div>

              <p v-if="exclusionDates.length === 0" class="text-next-xs text-next-muted-foreground">
                {{ t('workflows.schedule.exclusions.datesEmpty') }}
              </p>
              <template v-else>
                <span
                  v-for="date in exclusionDates"
                  :key="date"
                  class="inline-flex items-center gap-next-1 rounded-next-md border border-next-border bg-next-card py-next-0_5 pl-next-2 pr-next-1 text-next-xs"
                >
                  <span class="font-next-mono tabular-nums text-next-fg">{{ date }}</span>
                  <Button
                    variant="ghost"
                    size="icon-xs"
                    leading-icon="x"
                    :aria-label="`${t('workflows.schedule.exclusions.removeDate')} ${date}`"
                    @click="removeExclusionDate(date)"
                  />
                </span>
              </template>

              <span v-if="atDatesMax" class="text-next-xs text-next-muted-foreground">
                {{ t('workflows.schedule.exclusions.datesMax') }}
              </span>
            </div>
          </FormField>
        </div>
      </AccordionItem>
    </Accordion>

    <!-- 4. AI assist modal (opened from the summary). -->
    <WorkflowScheduleAssistModal v-model:open="assistOpen" :tz="tz" @apply="onApply" />
  </div>
</template>
