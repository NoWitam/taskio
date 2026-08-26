<script setup lang="ts">
// RecurrenceTimePanel — the "Time" axis. Renders its sub-modes through
// `RecurrenceOptionCards`: the SELECTED card expands with its controls woven into a
// natural-language SENTENCE (the `{n}`/`{minute}` slots are NumberInputs; the "from {from} to
// {to}" window is the inline WindowField). Switching a sub-mode reshapes ONLY the time axis.
// The `last_working_day` day rule LOCKS this to `at`: the every_* cards render disabled (with
// a panel-level explanation, never a bare gray-out) and the host auto-resets the axis + flags
// `switchedToAt`.
//
// NO PROFILE THAT OMITS THE TIME AXIS EVER MOUNTS THIS. The Calendar has no time axis at all
// — `recurrence.time` is `prohibited` and the server stamps the event's own hour — so its
// profile lists no `time` tab and this file is simply never reached from there.
import { computed, ref } from 'vue';
import NumberInput from '../forms/NumberInput.vue';
import TimePicker from '../forms/TimePicker.vue';
import Button from '../primitives/Button.vue';
import Alert from '../feedback/Alert.vue';
import RecurrenceOptionCards, { type OptionCard } from './RecurrenceOptionCards.vue';
import RecurrenceWindowField from './RecurrenceWindowField.vue';
import { useI18n } from '../../app/i18n';
import {
  RECURRENCE_LIMITS,
  splitSentenceTemplate,
  type RecurrenceProfile,
  type TimeAxis,
  type TimeSubmode,
} from './recurrenceAxes';

const props = withDefaults(
  defineProps<{
    profile: RecurrenceProfile;
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
const L = RECURRENCE_LIMITS;

const TITLE_KEY: Record<TimeSubmode, string> = {
  at: 'recurrenceEditor.time.mode.at',
  every_minutes: 'recurrenceEditor.time.mode.everyMinutes',
  every_hours: 'recurrenceEditor.time.mode.everyHours',
};

const modeOptions = computed<OptionCard<TimeSubmode>[]>(() =>
  props.profile.timeModes.map((value) => ({
    value,
    title: t(TITLE_KEY[value]),
    // `at` is never locked — it is what the lock locks TO.
    disabled: value === 'at' ? undefined : props.locked,
  })),
);

// The in-card head sentences, split so PL/EN word order lives in the string.
const everyMinutesHead = computed(() => splitSentenceTemplate(t('recurrenceEditor.time.card.everyMinutes.head')));
const everyHoursHead = computed(() => splitSentenceTemplate(t('recurrenceEditor.time.card.everyHours.head')));

// Remember the last `at` times so switching away and back preserves them.
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

// --- `at` — a DRAFT picker + "add" (one fused group) + removable time chips ---
// The picker is not the list — it is a draft entry field fused with the add button; the times
// render as compact chips on the SAME wrapping line.
const atTimes = computed<string[]>(() => (model.value.mode === 'at' ? model.value.at : []));
const newAtTime = ref<string | null>(null);
const atFull = computed(() => atTimes.value.length >= L.atTimesMax);
/** Add is possible for a filled, non-duplicate draft while under the cap. */
const canAddAt = computed(
  () => !!newAtTime.value && !atFull.value && !atTimes.value.includes(newAtTime.value),
);
function addAt(): void {
  if (model.value.mode !== 'at' || !canAddAt.value || !newAtTime.value) return;
  model.value = { mode: 'at', at: [...model.value.at, newAtTime.value] };
  newAtTime.value = null;
}
/** The LAST time is not removable (the axis requires ≥1) — its chip hides the ✕. */
function removeAt(time: string): void {
  if (model.value.mode !== 'at' || model.value.at.length <= 1) return;
  model.value = { mode: 'at', at: model.value.at.filter((t) => t !== time) };
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
    <RecurrenceOptionCards
      v-model="mode"
      :options="modeOptions"
      :aria-label="t('recurrenceEditor.tab.time')"
    >
      <!-- at — ONE horizontal wrapping flow: lead + a FUSED draft-picker+"Add time" group (a
           muted well so the pair reads as one control) + the added times as compact removable
           chips on the SAME line. The last chip hides its ✕ (the axis requires at least one
           time). The error breaks to its own row. -->
      <template #body-at>
        <div class="flex flex-wrap items-center gap-next-2">
          <p class="text-next-sm text-next-fg">{{ t('recurrenceEditor.time.card.at.lead') }}</p>

          <!-- The fused entry group: draft picker + add. The picker sits in a FIXED-WIDTH
               wrapper: Popover-based fields drop the class attr (multi-root inheritAttrs:
               false) and their inline-flex trigger sizes to the input's intrinsic width,
               so the wrapper + a forced w-full on the trigger chain is what actually
               constrains the field. shrink-0 on the well keeps the group from being
               squeezed by the wrapping row (which made siblings overlap). -->
          <div class="inline-flex shrink-0 items-center gap-next-1 rounded-next-lg border border-next-border bg-next-muted p-next-1">
            <div class="w-40 shrink-0 [&>div]:w-full">
              <TimePicker
                v-model="newAtTime"
                :clearable="false"
                :disabled="atFull"
                :aria-label="t('recurrenceEditor.field.times')"
                @keydown.enter.prevent="addAt"
              />
            </div>
            <Button variant="ghost" size="sm" leading-icon="plus" :disabled="!canAddAt" @click="addAt">
              {{ t('recurrenceEditor.field.addTime') }}
            </Button>
          </div>

          <!-- The added times, inline on the same wrapping line. -->
          <span
            v-for="time in atTimes"
            :key="time"
            class="inline-flex items-center gap-next-1 rounded-next-md border border-next-border bg-next-card py-next-0_5 pl-next-2 text-next-sm"
            :class="atTimes.length > 1 ? 'pr-next-1' : 'pr-next-2'"
          >
            <span class="font-next-mono tabular-nums text-next-fg">{{ time }}</span>
            <Button
              v-if="atTimes.length > 1"
              variant="ghost"
              size="icon-xs"
              leading-icon="x"
              :aria-label="`${t('recurrenceEditor.field.removeTime')} ${time}`"
              @click="removeAt(time)"
            />
          </span>

          <span v-if="atFull" class="text-next-xs text-next-muted-foreground">
            {{ t('recurrenceEditor.validation.timesMax', undefined, { max: L.atTimesMax }) }}
          </span>
          <p v-if="errors.at" class="w-full text-next-xs text-next-danger" role="alert">{{ errors.at }}</p>
        </div>
      </template>

      <!-- every_minutes — "co {n} minut [☐ od {from} do {to}]" — ONE wrapping flow. -->
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
              :aria-label="t('recurrenceEditor.field.minutesEvery')"
            />
          </template>
          <RecurrenceWindowField
            :enabled="minutesWindowOn"
            :toggle-label="t('recurrenceEditor.window.toggle.time')"
            :window-template="t('recurrenceEditor.time.card.everyMinutes.window')"
            :error="errors.window"
            @toggle="toggleMinutesWindow"
          >
            <template #from="{ disabled }">
              <div class="w-40 shrink-0 [&>div]:w-full">
                <TimePicker
                  :model-value="minutesWindowFrom"
                  :clearable="false"
                  :disabled="disabled"
                  :aria-label="t('recurrenceEditor.window.from')"
                  @update:model-value="setMinutesWindow('from', $event)"
                />
              </div>
            </template>
            <template #to="{ disabled }">
              <div class="w-40 shrink-0 [&>div]:w-full">
                <TimePicker
                  :model-value="minutesWindowTo"
                  :clearable="false"
                  :disabled="disabled"
                  :aria-label="t('recurrenceEditor.window.to')"
                  @update:model-value="setMinutesWindow('to', $event)"
                />
              </div>
            </template>
          </RecurrenceWindowField>
          <p v-if="errors.n" class="w-full text-next-xs text-next-danger" role="alert">{{ errors.n }}</p>
        </div>
      </template>

      <!-- every_hours — "co {n} godz. o {minute} min … [☐ od {from} do {to}]" — ONE flow. -->
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
              :aria-label="t('recurrenceEditor.field.hoursEvery')"
            />
            <NumberInput
              v-else-if="seg.name === 'minute'"
              v-model="everyHoursMinute"
              :min="L.minuteMin"
              :max="L.minuteMax"
              class="w-20 shrink-0 basis-20"
              :aria-label="t('recurrenceEditor.field.minute')"
            />
          </template>
          <RecurrenceWindowField
            :enabled="hoursWindowOn"
            :toggle-label="t('recurrenceEditor.window.toggle.hours')"
            :window-template="t('recurrenceEditor.time.card.everyHours.window')"
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
                :aria-label="t('recurrenceEditor.window.fromHour')"
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
                :aria-label="t('recurrenceEditor.window.toHour')"
                @update:model-value="setHoursWindow('to', $event)"
              />
            </template>
          </RecurrenceWindowField>
          <p v-if="errors.n || errors.minute" class="w-full text-next-xs text-next-danger" role="alert">
            {{ errors.n || errors.minute }}
          </p>
        </div>
      </template>
    </RecurrenceOptionCards>

    <!-- last_working_day restriction: surface, don't hide. -->
    <Alert v-if="locked" variant="info" size="sm">{{ t('recurrenceEditor.time.lockedByLastWorkingDay') }}</Alert>
    <Alert v-if="switchedToAt && mode === 'at'" variant="info" size="sm">
      {{ t('recurrenceEditor.time.switchedToAt') }}
    </Alert>
  </div>
</template>
