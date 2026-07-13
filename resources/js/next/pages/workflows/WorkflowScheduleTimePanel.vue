<script setup lang="ts">
// WorkflowScheduleTimePanel — the "Czas" tab (§4.5.5a, REV5). Renders its three
// sub-modes through `WorkflowScheduleOptionCards`: the SELECTED card expands with its
// controls woven into a natural-language SENTENCE (the `{n}`/`{minute}` slots are
// NumberInputs; the "od {from} do {to}" window is the inline WindowField). Switching a
// sub-mode reshapes ONLY the time axis. The `last_working_day` day rule LOCKS this to
// `at`: the every_* cards render disabled (with a panel-level explanation, never a bare
// gray-out) and the host auto-resets the axis + flags `switchedToAt`.
import { computed, ref } from 'vue';
import NumberInput from '../../ui/forms/NumberInput.vue';
import TimePicker from '../../ui/forms/TimePicker.vue';
import Button from '../../ui/primitives/Button.vue';
import Alert from '../../ui/feedback/Alert.vue';
import WorkflowScheduleOptionCards, { type OptionCard } from './WorkflowScheduleOptionCards.vue';
import WorkflowScheduleWindowField from './WorkflowScheduleWindowField.vue';
import { useI18n } from '../../app/i18n';
import { SCHEDULE_LIMITS, splitSentenceTemplate, type TimeAxis } from './workflowSchedule';

type TimeSubmode = 'at' | 'every_minutes' | 'every_hours';

const props = withDefaults(
  defineProps<{
    /** True while the day axis is `last_working_day` — locks this to `at`. */
    locked?: boolean;
    /** Show the one-time "switched to set times" note after an auto-reset. */
    switchedToAt?: boolean;
    /** Resolved per-control errors keyed by field (`at`/`n`/`minute`/`window`). */
    errors?: Record<string, string | undefined>;
  }>(),
  { locked: false, switchedToAt: false, errors: () => ({}) },
);

const model = defineModel<TimeAxis>({ required: true });
const { t } = useI18n();
const L = SCHEDULE_LIMITS;

const modeOptions = computed<OptionCard<TimeSubmode>[]>(() => [
  { value: 'at', title: t('workflows.schedule.time.mode.at') },
  { value: 'every_minutes', title: t('workflows.schedule.time.mode.everyMinutes'), disabled: props.locked },
  { value: 'every_hours', title: t('workflows.schedule.time.mode.everyHours'), disabled: props.locked },
]);

// The in-card head sentences, split so PL/EN word order lives in the string (§4.5.12).
const everyMinutesHead = computed(() => splitSentenceTemplate(t('workflows.schedule.time.card.everyMinutes.head')));
const everyHoursHead = computed(() => splitSentenceTemplate(t('workflows.schedule.time.card.everyHours.head')));

// Remember the last `at` times so switching away and back preserves them (§4.5.1).
const preservedAt = ref<string[]>(['09:00']);

const mode = computed<TimeSubmode>({
  get: () => model.value.mode,
  set: (m) => {
    if (m === model.value.mode) return;
    if (model.value.mode === 'at' && model.value.at.length) preservedAt.value = [...model.value.at];
    if (m === 'at') model.value = { mode: 'at', at: preservedAt.value.length ? [...preservedAt.value] : ['09:00'] };
    else if (m === 'every_minutes') model.value = { mode: 'every_minutes', n: 1 };
    else model.value = { mode: 'every_hours', n: 1, minute: 0 };
  },
});

// --- `at` — the editable 1..6 TimePicker list --------------------------------
const atTimes = computed<string[]>(() => (model.value.mode === 'at' ? model.value.at : []));
function setAt(i: number, value: string | null): void {
  if (model.value.mode !== 'at') return;
  const at = [...model.value.at];
  at[i] = value ?? '';
  model.value = { mode: 'at', at };
}
function addAt(): void {
  if (model.value.mode !== 'at' || model.value.at.length >= L.atTimesMax) return;
  model.value = { mode: 'at', at: [...model.value.at, '12:00'] };
}
function removeAt(i: number): void {
  if (model.value.mode !== 'at' || model.value.at.length <= 1) return;
  model.value = { mode: 'at', at: model.value.at.filter((_, j) => j !== i) };
}
/** A `at` picker emits null/'' when its INNER ✕ clears it (or the text is emptied): that
 *  removes the row. With a single time the picker is not clearable, so `removeAt` no-ops
 *  and the last time can't be deleted. A concrete time just updates that slot. */
function onAtInput(i: number, value: string | null): void {
  if (value == null || value === '') removeAt(i);
  else setAt(i, value);
}

// --- numeric fields (finite-or-null so a cleared field reads empty + invalid) --
function finiteOrNull(n: number): number | null {
  return Number.isFinite(n) ? n : null;
}

const everyMinutesN = computed<number | null>({
  get: () => (model.value.mode === 'every_minutes' ? finiteOrNull(model.value.n) : null),
  set: (v) => {
    if (model.value.mode === 'every_minutes') model.value = { ...model.value, n: v ?? NaN };
  },
});
const everyHoursN = computed<number | null>({
  get: () => (model.value.mode === 'every_hours' ? finiteOrNull(model.value.n) : null),
  set: (v) => {
    if (model.value.mode === 'every_hours') model.value = { ...model.value, n: v ?? NaN };
  },
});
const everyHoursMinute = computed<number | null>({
  get: () => (model.value.mode === 'every_hours' ? finiteOrNull(model.value.minute) : null),
  set: (v) => {
    if (model.value.mode === 'every_hours') model.value = { ...model.value, minute: v ?? NaN };
  },
});

// --- windows (every_minutes → HH:mm TimePicker²; every_hours → whole-hour NumberInput²) --
const minutesWindowOn = computed(() => model.value.mode === 'every_minutes' && !!model.value.window);
function toggleMinutesWindow(checked: boolean): void {
  if (model.value.mode !== 'every_minutes') return;
  model.value = { mode: 'every_minutes', n: model.value.n, window: checked ? { from: '09:00', to: '17:00' } : undefined };
}
function setMinutesWindow(key: 'from' | 'to', value: string | null): void {
  if (model.value.mode !== 'every_minutes' || !model.value.window) return;
  model.value = { ...model.value, window: { ...model.value.window, [key]: value ?? '' } };
}
const minutesWindowFrom = computed(() => (model.value.mode === 'every_minutes' ? model.value.window?.from ?? null : null));
const minutesWindowTo = computed(() => (model.value.mode === 'every_minutes' ? model.value.window?.to ?? null : null));

const hoursWindowOn = computed(() => model.value.mode === 'every_hours' && !!model.value.window);
function toggleHoursWindow(checked: boolean): void {
  if (model.value.mode !== 'every_hours') return;
  model.value = {
    mode: 'every_hours',
    n: model.value.n,
    minute: model.value.minute,
    window: checked ? { from: 8, to: 18 } : undefined,
  };
}
function setHoursWindow(key: 'from' | 'to', value: number | null): void {
  if (model.value.mode !== 'every_hours' || !model.value.window) return;
  model.value = { ...model.value, window: { ...model.value.window, [key]: value ?? NaN } };
}
const hoursWindowFrom = computed(() =>
  model.value.mode === 'every_hours' && model.value.window ? finiteOrNull(model.value.window.from) : null,
);
const hoursWindowTo = computed(() =>
  model.value.mode === 'every_hours' && model.value.window ? finiteOrNull(model.value.window.to) : null,
);
</script>

<template>
  <div class="flex flex-col gap-next-3">
    <WorkflowScheduleOptionCards
      v-model="mode"
      :options="modeOptions"
      :aria-label="t('workflows.schedule.tab.time')"
    >
      <!-- at — ONE horizontal wrapping flow (REV5): lead + every TimePicker + "Add time".
           REV5.1: the ✕ lives INSIDE each field (the picker's own `clearable`); clearing a
           time removes that row (null ⇒ removeAt). With a single time the picker is not
           clearable, so the last time can't be removed. The error breaks to its own row.
           The field is w-44 so "09:00" is not truncated once the inner ✕ + clock share the
           trailing zone. -->
      <template #body-at>
        <div class="flex flex-wrap items-center gap-next-2">
          <p class="text-next-sm text-next-fg">{{ t('workflows.schedule.time.card.at.lead') }}</p>
          <TimePicker
            v-for="(time, i) in atTimes"
            :key="i"
            :model-value="time"
            :clearable="atTimes.length > 1"
            class="w-44 shrink-0 basis-44"
            :aria-label="`${t('workflows.schedule.field.times')} ${i + 1}`"
            @update:model-value="onAtInput(i, $event)"
          />
          <Button
            variant="outline"
            size="sm"
            leading-icon="plus"
            :disabled="atTimes.length >= L.atTimesMax"
            @click="addAt"
          >
            {{ t('workflows.schedule.field.addTime') }}
          </Button>
          <p v-if="errors.at" class="w-full text-next-xs text-next-danger" role="alert">{{ errors.at }}</p>
        </div>
      </template>

      <!-- every_minutes — "co {n} minut [☐ od {from} do {to}]" — ONE wrapping flow (REV5). -->
      <template #body-every_minutes>
        <div class="flex flex-wrap items-center gap-next-1_5 text-next-sm text-next-fg">
          <template v-for="(seg, i) in everyMinutesHead" :key="'h' + i">
            <span v-if="seg.type === 'text'">{{ seg.value }}</span>
            <NumberInput
              v-else-if="seg.name === 'n'"
              v-model="everyMinutesN"
              :min="L.everyMinutesMin"
              :max="L.everyMinutesMax"
              class="w-20 shrink-0 basis-20"
              :aria-label="t('workflows.schedule.field.minutesEvery')"
            />
          </template>
          <WorkflowScheduleWindowField
            :enabled="minutesWindowOn"
            :toggle-label="t('workflows.schedule.window.toggle.time')"
            :window-template="t('workflows.schedule.time.card.everyMinutes.window')"
            :error="errors.window"
            @toggle="toggleMinutesWindow"
          >
            <template #from="{ disabled }">
              <TimePicker
                :model-value="minutesWindowFrom"
                :clearable="false"
                :disabled="disabled"
                class="w-36 shrink-0 basis-36"
                :aria-label="t('workflows.schedule.window.from')"
                @update:model-value="setMinutesWindow('from', $event)"
              />
            </template>
            <template #to="{ disabled }">
              <TimePicker
                :model-value="minutesWindowTo"
                :clearable="false"
                :disabled="disabled"
                class="w-36 shrink-0 basis-36"
                :aria-label="t('workflows.schedule.window.to')"
                @update:model-value="setMinutesWindow('to', $event)"
              />
            </template>
          </WorkflowScheduleWindowField>
          <p v-if="errors.n" class="w-full text-next-xs text-next-danger" role="alert">{{ errors.n }}</p>
        </div>
      </template>

      <!-- every_hours — "co {n} godz. o {minute} min … [☐ od {from} do {to}]" — ONE flow (REV5). -->
      <template #body-every_hours>
        <div class="flex flex-wrap items-center gap-next-1_5 text-next-sm text-next-fg">
          <template v-for="(seg, i) in everyHoursHead" :key="'h' + i">
            <span v-if="seg.type === 'text'">{{ seg.value }}</span>
            <NumberInput
              v-else-if="seg.name === 'n'"
              v-model="everyHoursN"
              :min="L.everyHoursMin"
              :max="L.everyHoursMax"
              class="w-20 shrink-0 basis-20"
              :aria-label="t('workflows.schedule.field.hoursEvery')"
            />
            <NumberInput
              v-else-if="seg.name === 'minute'"
              v-model="everyHoursMinute"
              :min="L.minuteMin"
              :max="L.minuteMax"
              class="w-20 shrink-0 basis-20"
              :aria-label="t('workflows.schedule.field.minute')"
            />
          </template>
          <WorkflowScheduleWindowField
            :enabled="hoursWindowOn"
            :toggle-label="t('workflows.schedule.window.toggle.hours')"
            :window-template="t('workflows.schedule.time.card.everyHours.window')"
            :error="errors.window"
            @toggle="toggleHoursWindow"
          >
            <template #from="{ disabled }">
              <NumberInput
                :model-value="hoursWindowFrom"
                :min="L.hourMin"
                :max="L.hourMax"
                :disabled="disabled"
                class="w-20 shrink-0 basis-20"
                :aria-label="t('workflows.schedule.window.fromHour')"
                @update:model-value="setHoursWindow('from', $event)"
              />
            </template>
            <template #to="{ disabled }">
              <NumberInput
                :model-value="hoursWindowTo"
                :min="L.hourMin"
                :max="L.hourMax"
                :disabled="disabled"
                class="w-20 shrink-0 basis-20"
                :aria-label="t('workflows.schedule.window.toHour')"
                @update:model-value="setHoursWindow('to', $event)"
              />
            </template>
          </WorkflowScheduleWindowField>
          <p v-if="errors.n || errors.minute" class="w-full text-next-xs text-next-danger" role="alert">
            {{ errors.n || errors.minute }}
          </p>
        </div>
      </template>
    </WorkflowScheduleOptionCards>

    <!-- last_working_day restriction: surface, don't hide (§4.5.5a). -->
    <Alert v-if="locked" variant="info" size="sm">{{ t('workflows.schedule.time.lockedByLastWorkingDay') }}</Alert>
    <Alert v-if="switchedToAt && mode === 'at'" variant="info" size="sm">
      {{ t('workflows.schedule.time.switchedToAt') }}
    </Alert>
  </div>
</template>
