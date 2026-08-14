<script setup lang="ts">
// DateTimePicker — combined date + time entry for the "next" frontend.
//
// One popover holds a CalendarPanel and a compact time row; the model is a single
// local ISO datetime string. The date is chosen in the calendar, the time via
// steppered hour/minute (+ optional seconds) fields; an "Apply" button commits and
// closes (selecting a day auto-keeps the popover open so the user can set time).
//
// MODEL CONTRACT: v-model is a LOCAL ISO datetime `yyyy-mm-ddTHH:mm` (or `…:ss`),
// no `Z`/offset — NEVER a Date. The backend owns the zone. See `date/dateCore.ts`.
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import Icon from '../primitives/Icon.vue';
import Button from '../primitives/Button.vue';
import FieldShell from './FieldShell.vue';
import FieldPopover from './FieldPopover.vue';
import CalendarPanel from './date/CalendarPanel.vue';
import { useFormField, nextId } from './formField';
import { FIELD_PADDING_X, type ControlSize } from './fieldShell';
import { useI18n } from '../../app/i18n';
import {
  fromIsoDate,
  fromIsoDateTime,
  formatDate,
  from12Hour,
  pad2,
  parseDateInput,
  to12Hour,
  toIsoDate,
  toIsoDateTime,
  type DisabledDatePredicate,
  type TimeParts,
  type WeekDay,
} from './date/dateCore';

const props = withDefaults(
  defineProps<{
    size?: ControlSize;
    placeholder?: string;
    disabled?: boolean;
    readonly?: boolean;
    min?: string | null;
    max?: string | null;
    disabledDate?: DisabledDatePredicate | null;
    seconds?: boolean;
    hour12?: boolean;
    minuteStep?: number;
    clearable?: boolean;
    /** Date display/parse format. Default `dd.mm.yyyy`. */
    format?: string;
    locale?: string;
    weekStartsOn?: WeekDay;
    ariaInvalid?: boolean;
    success?: boolean;
    dirty?: boolean;
    id?: string;
    describedById?: string;
    name?: string;
    ariaLabel?: string;
  }>(),
  {
    // Absence must stay `undefined` (no Boolean cast to `false`) so it defers
    // to the surrounding FormField — see `formField.ts`.
    ariaInvalid: undefined,
    size: 'md',
    disabled: false,
    readonly: false,
    min: null,
    max: null,
    disabledDate: null,
    seconds: false,
    hour12: false,
    minuteStep: 1,
    clearable: true,
    format: 'dd.mm.yyyy',
    weekStartsOn: 1,
    success: false,
    dirty: false,
  },
);

// LOCAL ISO datetime string `yyyy-mm-ddTHH:mm[:ss]` (or null). Never a Date.
const model = defineModel<string | null>({ default: null });

const { t, currentLocale } = useI18n();
// Effective BCP-47 locale for the embedded calendar: explicit prop wins, else
// follow the active UI language.
const effectiveLocale = computed(() => props.locale ?? currentLocale.value);

const field = useFormField();
const generatedId = nextId('next-datetime');
const resolvedId = computed(() => props.id ?? field?.id.value ?? generatedId);
const panelId = computed(() => `${resolvedId.value}-panel`);
const resolvedDescribedBy = computed(
  () => props.describedById ?? field?.describedById.value,
);
const fieldInvalid = computed(() => props.ariaInvalid ?? field?.invalid.value ?? false);
const fieldSuccess = computed(() => props.success || (field?.valid.value ?? false));
const dirty = computed(() => props.dirty || (field?.dirty.value ?? false));
const disabled = computed(() => props.disabled || (field?.disabled.value ?? false));
const readonly = computed(() => props.readonly || (field?.readonly.value ?? false));
const required = computed(() => field?.required.value ?? false);

if (field?.registerValue) {
  const dispose = field.registerValue(() => model.value);
  onBeforeUnmount(dispose);
}

// ── Working draft (committed on Apply / day-select keeps popover open) ─────────
const draftDate = ref<string | null>(null); // ISO day
const draftTime = ref<TimeParts>({ hours: 0, minutes: 0, seconds: 0 });

function loadDraftFromModel(): void {
  const { date, time } = fromIsoDateTime(model.value);
  draftDate.value = date ? toIsoDate(date) : null;
  draftTime.value = time ?? { hours: 0, minutes: 0, seconds: 0 };
}
watch(model, loadDraftFromModel, { immediate: true });

// ── Trigger text (read-only summary of the committed value) ───────────────────
const timeFmt = computed(() => ({ hour12: props.hour12, withSeconds: props.seconds }));
const text = computed(() => {
  const { date, time } = fromIsoDateTime(model.value);
  if (!date) return '';
  const datePart = formatDate(date, props.format);
  const timePart = time
    ? props.hour12
      ? `${to12Hour(time.hours).hour}:${pad2(time.minutes)}${props.seconds ? ':' + pad2(time.seconds) : ''} ${to12Hour(time.hours).period}`
      : `${pad2(time.hours)}:${pad2(time.minutes)}${props.seconds ? ':' + pad2(time.seconds) : ''}`
    : '';
  return timePart ? `${datePart} ${timePart}` : datePart;
});

const period = computed(() => to12Hour(draftTime.value.hours).period);
const displayHour = computed(() =>
  props.hour12 ? to12Hour(draftTime.value.hours).hour : draftTime.value.hours,
);

function onDaySelect(iso: string): void {
  draftDate.value = iso;
}
function stepHour(dir: 1 | -1): void {
  draftTime.value = { ...draftTime.value, hours: ((draftTime.value.hours + dir) % 24 + 24) % 24 };
}
function stepMinute(dir: 1 | -1): void {
  const step = Math.max(1, props.minuteStep);
  draftTime.value = { ...draftTime.value, minutes: ((draftTime.value.minutes + dir * step) % 60 + 60) % 60 };
}
function stepSecond(dir: 1 | -1): void {
  draftTime.value = { ...draftTime.value, seconds: ((draftTime.value.seconds + dir) % 60 + 60) % 60 };
}
function togglePeriod(): void {
  const next = period.value === 'AM' ? 'PM' : 'AM';
  const { hour } = to12Hour(draftTime.value.hours);
  draftTime.value = { ...draftTime.value, hours: from12Hour(hour, next) };
}

const popoverRef = ref<InstanceType<typeof FieldPopover> | null>(null);
function apply(): void {
  const d = fromIsoDate(draftDate.value);
  if (!d) {
    popoverRef.value?.closePanel(true);
    return;
  }
  model.value = toIsoDateTime(d, draftTime.value, props.seconds);
  popoverRef.value?.closePanel(true);
}
function clear(): void {
  model.value = null;
  draftDate.value = null;
  draftTime.value = { hours: 0, minutes: 0, seconds: 0 };
}
function openPanel(): void {
  if (disabled.value || readonly.value) return;
  loadDraftFromModel();
  popoverRef.value?.openPanel();
}

const invalid = computed(() => fieldInvalid.value);
const success = computed(() => fieldSuccess.value);
const showClear = computed(
  () => props.clearable && !disabled.value && !readonly.value && !!model.value,
);

const stepBtn =
  'flex h-6 w-7 items-center justify-center rounded-next-sm text-next-muted-foreground ' +
  'hover:bg-next-accent hover:text-next-accent-foreground text-[0.7rem]';
</script>

<template>
  <FieldPopover
    ref="popoverRef"
    :disabled="disabled || readonly"
    :panel-id="panelId"
    :aria-label="t('pickers.dateTimeLabel', 'Date and time selection')"
  >
    <template #trigger="{ open }">
      <FieldShell
        :size="size"
        :disabled="disabled"
        :readonly="readonly"
        :error="invalid"
        :success="success"
        :dirty="dirty"
        :focused="open || undefined"
      >
        <template #leading>
          <Icon name="calendar" class="text-next-muted-foreground" />
        </template>

        <button
          :id="resolvedId"
          type="button"
          role="combobox"
          aria-haspopup="dialog"
          :aria-expanded="open"
          :aria-controls="panelId"
          :disabled="disabled"
          :aria-invalid="invalid ? 'true' : undefined"
          :aria-describedby="resolvedDescribedBy"
          :aria-required="required ? 'true' : undefined"
          :aria-label="ariaLabel"
          class="flex h-full w-full min-w-0 flex-1 items-center pl-next-2 text-left outline-none disabled:cursor-not-allowed"
          :class="FIELD_PADDING_X[size]"
          @click="openPanel"
          @keydown.down.prevent="openPanel"
        >
          <span v-if="text" class="truncate">{{ text }}</span>
          <span v-else class="truncate text-next-muted-foreground">
            {{ placeholder ?? `${format} ${t('pickers.timePlaceholder', 'hh:mm')}` }}
          </span>
        </button>

        <template v-if="clearable && !disabled && !readonly" #trailing>
          <span class="flex h-5 w-5 items-center justify-center">
            <button
              type="button"
              class="flex items-center rounded-next-sm p-next-0_5 text-next-muted-foreground hover:text-next-fg"
              :class="showClear ? '' : 'invisible'"
              :aria-hidden="showClear ? undefined : 'true'"
              tabindex="-1"
              :aria-label="t('pickers.clearDate', 'Clear date')"
              @click.stop="clear"
            >
              <Icon name="x" />
            </button>
          </span>
        </template>
      </FieldShell>
    </template>

    <div class="flex flex-col">
      <CalendarPanel
        :model-value="draftDate"
        :min="min"
        :max="max"
        :disabled-date="disabledDate"
        :locale="effectiveLocale"
        :week-starts-on="weekStartsOn"
        @select="onDaySelect"
      />

      <!-- Time row -->
      <div class="flex items-center justify-center gap-next-2 border-t border-next-border px-next-3 py-next-2">
        <Icon name="clock" class="text-next-muted-foreground" />
        <div class="flex items-center gap-next-1">
          <div class="flex flex-col items-center">
            <button type="button" :class="stepBtn" :aria-label="t('pickers.hourUp', 'Hour up')" @click="stepHour(1)"><Icon name="chevron-up" /></button>
            <div class="flex h-8 w-10 items-center justify-center rounded-next-sm border border-next-input bg-next-card font-next-mono tabular-nums" role="spinbutton" :aria-valuenow="draftTime.hours" :aria-label="t('pickers.hour', 'Hour')" tabindex="0" @keydown.up.prevent="stepHour(1)" @keydown.down.prevent="stepHour(-1)">{{ pad2(displayHour) }}</div>
            <button type="button" :class="stepBtn" :aria-label="t('pickers.hourDown', 'Hour down')" @click="stepHour(-1)"><Icon name="chevron-down" /></button>
          </div>
          <span class="font-next-semibold text-next-muted-foreground">:</span>
          <div class="flex flex-col items-center">
            <button type="button" :class="stepBtn" :aria-label="t('pickers.minuteUp', 'Minute up')" @click="stepMinute(1)"><Icon name="chevron-up" /></button>
            <div class="flex h-8 w-10 items-center justify-center rounded-next-sm border border-next-input bg-next-card font-next-mono tabular-nums" role="spinbutton" :aria-valuenow="draftTime.minutes" :aria-label="t('pickers.minute', 'Minute')" tabindex="0" @keydown.up.prevent="stepMinute(1)" @keydown.down.prevent="stepMinute(-1)">{{ pad2(draftTime.minutes) }}</div>
            <button type="button" :class="stepBtn" :aria-label="t('pickers.minuteDown', 'Minute down')" @click="stepMinute(-1)"><Icon name="chevron-down" /></button>
          </div>
          <template v-if="seconds">
            <span class="font-next-semibold text-next-muted-foreground">:</span>
            <div class="flex flex-col items-center">
              <button type="button" :class="stepBtn" :aria-label="t('pickers.secondUp', 'Second up')" @click="stepSecond(1)"><Icon name="chevron-up" /></button>
              <div class="flex h-8 w-10 items-center justify-center rounded-next-sm border border-next-input bg-next-card font-next-mono tabular-nums" role="spinbutton" :aria-valuenow="draftTime.seconds" :aria-label="t('pickers.second', 'Second')" tabindex="0" @keydown.up.prevent="stepSecond(1)" @keydown.down.prevent="stepSecond(-1)">{{ pad2(draftTime.seconds) }}</div>
              <button type="button" :class="stepBtn" :aria-label="t('pickers.secondDown', 'Second down')" @click="stepSecond(-1)"><Icon name="chevron-down" /></button>
            </div>
          </template>
          <div v-if="hour12" class="ml-next-1 flex flex-col gap-next-0_5">
            <button type="button" class="rounded-next-sm px-next-1_5 py-next-0_5 text-next-2xs font-next-medium" :class="period === 'AM' ? 'bg-next-primary text-next-primary-foreground' : 'text-next-muted-foreground hover:bg-next-accent'" :aria-pressed="period === 'AM'" @click="period !== 'AM' && togglePeriod()">AM</button>
            <button type="button" class="rounded-next-sm px-next-1_5 py-next-0_5 text-next-2xs font-next-medium" :class="period === 'PM' ? 'bg-next-primary text-next-primary-foreground' : 'text-next-muted-foreground hover:bg-next-accent'" :aria-pressed="period === 'PM'" @click="period !== 'PM' && togglePeriod()">PM</button>
          </div>
        </div>
      </div>

      <!-- Apply -->
      <div class="flex justify-end gap-next-2 border-t border-next-border p-next-2">
        <Button variant="ghost" size="sm" @click="popoverRef?.closePanel(true)">{{ t('common.cancel', 'Cancel') }}</Button>
        <Button variant="primary" size="sm" :disabled="!draftDate" @click="apply">{{ t('common.apply', 'Apply') }}</Button>
      </div>
    </div>
  </FieldPopover>
</template>
