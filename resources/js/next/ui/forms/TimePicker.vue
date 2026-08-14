<script setup lang="ts">
// TimePicker — wall-clock time entry for the "next" frontend.
//
// A typeable text field (`HH:mm`, live-parsed) rendered through FieldShell, with a
// popover holding steppered hour/minute (+ optional seconds) columns. `hour12`
// switches to 12h with an AM/PM toggle; `minuteStep` constrains the minute stepper.
//
// MODEL CONTRACT: v-model is an ISO time string `HH:mm` (or `HH:mm:ss` when
// `seconds`), 24h, no zone — NEVER a Date. See `date/dateCore.ts`.
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import Icon from '../primitives/Icon.vue';
import FieldShell from './FieldShell.vue';
import FieldPopover from './FieldPopover.vue';
import { useFormField, nextId } from './formField';
import { FIELD_PADDING_X, type ControlSize } from './fieldShell';
import { useI18n } from '../../app/i18n';
import {
  formatTime,
  fromIsoTime,
  from12Hour,
  pad2,
  parseTimeInput,
  snapMinuteToStep,
  to12Hour,
  toIsoTime,
  type TimeParts,
} from './date/dateCore';

const props = withDefaults(
  defineProps<{
    size?: ControlSize;
    placeholder?: string;
    disabled?: boolean;
    readonly?: boolean;
    /** Include a seconds column + serialize `HH:mm:ss`. */
    seconds?: boolean;
    /** 12-hour clock with an AM/PM toggle (display only; model stays 24h). */
    hour12?: boolean;
    /** Minute stepper increment (e.g. 5, 15). Default 1. */
    minuteStep?: number;
    /** Show a clear (✕) button when there's a value. */
    clearable?: boolean;
    /** BCP-47 locale (reserved for future name use). Default `pl`. */
    locale?: string;
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
    seconds: false,
    hour12: false,
    minuteStep: 1,
    clearable: true,
    locale: 'pl',
    success: false,
    dirty: false,
  },
);

// ISO time string `HH:mm` / `HH:mm:ss` (or null). Never a Date.
const model = defineModel<string | null>({ default: null });

const { t } = useI18n();

const field = useFormField();
const generatedId = nextId('next-time');
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

// ── Typed text ↔ model ────────────────────────────────────────────────────────
const text = ref('');
const parseError = ref(false);

const placeholderText = computed(
  () =>
    props.placeholder ??
    (props.seconds
      ? t('pickers.timePlaceholderSeconds', 'hh:mm:ss')
      : t('pickers.timePlaceholder', 'hh:mm')),
);

function partsFromModel(): TimeParts | null {
  return fromIsoTime(model.value);
}
function syncTextFromModel(): void {
  const p = partsFromModel();
  text.value = p ? formatTime(p, { hour12: props.hour12, withSeconds: props.seconds }) : '';
  parseError.value = false;
}
watch(model, syncTextFromModel, { immediate: true });

function commit(parts: TimeParts | null): void {
  model.value = parts ? toIsoTime(parts, props.seconds) : null;
}

function onInput(event: Event): void {
  text.value = (event.target as HTMLInputElement).value;
  if (!text.value.trim()) {
    parseError.value = false;
    if (model.value !== null) model.value = null;
    return;
  }
  const parsed = parseTimeInput(text.value, props.seconds);
  if (parsed) {
    parseError.value = false;
    commit(parsed);
  } else {
    parseError.value = text.value.replace(/\D/g, '').length >= (props.seconds ? 4 : 3);
  }
}
function onBlur(): void {
  syncTextFromModel();
}

// ── Stepper columns ───────────────────────────────────────────────────────────
// Local working parts so the columns reflect edits immediately; committed to the
// model on each change.
const parts = computed<TimeParts>(
  () => partsFromModel() ?? { hours: 0, minutes: 0, seconds: 0 },
);
const period = computed(() => to12Hour(parts.value.hours).period);
const displayHour = computed(() =>
  props.hour12 ? to12Hour(parts.value.hours).hour : parts.value.hours,
);

function setHour24(h: number): void {
  const hours = ((h % 24) + 24) % 24;
  commit({ ...parts.value, hours });
}
function stepHour(dir: 1 | -1): void {
  setHour24(parts.value.hours + dir);
}
function stepMinute(dir: 1 | -1): void {
  const step = Math.max(1, props.minuteStep);
  const minutes = ((parts.value.minutes + dir * step) % 60 + 60) % 60;
  commit({ ...parts.value, minutes });
}
function stepSecond(dir: 1 | -1): void {
  const seconds = ((parts.value.seconds + dir) % 60 + 60) % 60;
  commit({ ...parts.value, seconds });
}
function togglePeriod(): void {
  const next = period.value === 'AM' ? 'PM' : 'AM';
  const { hour } = to12Hour(parts.value.hours);
  commit({ ...parts.value, hours: from12Hour(hour, next) });
}
function snapMinutes(): void {
  if (props.minuteStep > 1 && partsFromModel()) {
    commit({ ...parts.value, minutes: snapMinuteToStep(parts.value.minutes, props.minuteStep) });
  }
}

function clear(): void {
  model.value = null;
  text.value = '';
  parseError.value = false;
}

const popoverRef = ref<InstanceType<typeof FieldPopover> | null>(null);
function openPanel(): void {
  if (disabled.value || readonly.value) return;
  snapMinutes();
  popoverRef.value?.openPanel();
}

const invalid = computed(() => fieldInvalid.value || parseError.value);
const success = computed(() => fieldSuccess.value && !parseError.value);
const showClear = computed(
  () => props.clearable && !disabled.value && !readonly.value && !!model.value,
);

const stepBtn =
  'flex h-6 w-8 items-center justify-center rounded-next-sm text-next-muted-foreground ' +
  'hover:bg-next-accent hover:text-next-accent-foreground text-[0.7rem]';
</script>

<template>
  <FieldPopover
    ref="popoverRef"
    :disabled="disabled || readonly"
    :panel-id="panelId"
    :aria-label="t('pickers.timeLabel', 'Time selection')"
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
          <Icon name="clock" class="text-next-muted-foreground" />
        </template>

        <input
          :id="resolvedId"
          :value="text"
          type="text"
          inputmode="numeric"
          :name="name"
          :placeholder="placeholderText"
          :disabled="disabled"
          :readonly="readonly"
          autocomplete="off"
          role="combobox"
          aria-haspopup="dialog"
          :aria-expanded="open"
          :aria-controls="panelId"
          :aria-invalid="invalid ? 'true' : undefined"
          :aria-describedby="resolvedDescribedBy"
          :aria-required="required ? 'true' : undefined"
          :aria-label="ariaLabel"
          class="h-full w-full min-w-0 flex-1 truncate border-0 bg-transparent pl-next-2 text-current outline-none placeholder:text-next-muted-foreground disabled:cursor-not-allowed"
          :class="FIELD_PADDING_X[size]"
          @input="onInput"
          @blur="onBlur"
          @keydown.down.prevent="openPanel"
          @click="openPanel"
        />

        <template #trailing>
          <span class="flex items-center gap-next-1">
            <!-- Reserve the clear box when clearable; toggle visibility only so the
                 input width is stable as the value comes/goes. -->
            <span v-if="clearable" class="flex h-5 w-5 items-center justify-center">
              <button
                type="button"
                class="flex items-center rounded-next-sm p-next-0_5 text-next-muted-foreground hover:text-next-fg"
                :class="showClear ? '' : 'invisible'"
                :aria-hidden="showClear ? undefined : 'true'"
                tabindex="-1"
                :aria-label="t('pickers.clearTime', 'Clear time')"
                @click.stop="clear"
              >
                <Icon name="x" />
              </button>
            </span>
            <button
              type="button"
              class="flex items-center rounded-next-sm p-next-0_5 text-next-muted-foreground hover:text-next-fg disabled:cursor-not-allowed"
              :disabled="disabled || readonly"
              :aria-label="t('pickers.openTime', 'Open time selection')"
              tabindex="-1"
              @click.stop="openPanel"
            >
              <Icon name="clock" />
            </button>
          </span>
        </template>
      </FieldShell>
    </template>

    <!-- Steppered columns -->
    <div class="flex items-stretch gap-next-2 p-next-3">
      <!-- Hours -->
      <div class="flex flex-col items-center gap-next-1">
        <button type="button" :class="stepBtn" :aria-label="t('pickers.hourUp', 'Hour up')" @click="stepHour(1)">
          <Icon name="chevron-up" />
        </button>
        <div
          class="flex h-9 w-12 items-center justify-center rounded-next-md border border-next-input bg-next-card font-next-mono text-next-base tabular-nums"
          role="spinbutton"
          :aria-valuenow="parts.hours"
          :aria-label="t('pickers.hour', 'Hour')"
          tabindex="0"
          @keydown.up.prevent="stepHour(1)"
          @keydown.down.prevent="stepHour(-1)"
        >
          {{ pad2(displayHour) }}
        </div>
        <button type="button" :class="stepBtn" :aria-label="t('pickers.hourDown', 'Hour down')" @click="stepHour(-1)">
          <Icon name="chevron-down" />
        </button>
      </div>

      <span class="flex items-center text-next-lg font-next-semibold text-next-muted-foreground">:</span>

      <!-- Minutes -->
      <div class="flex flex-col items-center gap-next-1">
        <button type="button" :class="stepBtn" :aria-label="t('pickers.minuteUp', 'Minute up')" @click="stepMinute(1)">
          <Icon name="chevron-up" />
        </button>
        <div
          class="flex h-9 w-12 items-center justify-center rounded-next-md border border-next-input bg-next-card font-next-mono text-next-base tabular-nums"
          role="spinbutton"
          :aria-valuenow="parts.minutes"
          :aria-label="t('pickers.minute', 'Minute')"
          tabindex="0"
          @keydown.up.prevent="stepMinute(1)"
          @keydown.down.prevent="stepMinute(-1)"
        >
          {{ pad2(parts.minutes) }}
        </div>
        <button type="button" :class="stepBtn" :aria-label="t('pickers.minuteDown', 'Minute down')" @click="stepMinute(-1)">
          <Icon name="chevron-down" />
        </button>
      </div>

      <!-- Seconds (optional) -->
      <template v-if="seconds">
        <span class="flex items-center text-next-lg font-next-semibold text-next-muted-foreground">:</span>
        <div class="flex flex-col items-center gap-next-1">
          <button type="button" :class="stepBtn" :aria-label="t('pickers.secondUp', 'Second up')" @click="stepSecond(1)">
            <Icon name="chevron-up" />
          </button>
          <div
            class="flex h-9 w-12 items-center justify-center rounded-next-md border border-next-input bg-next-card font-next-mono text-next-base tabular-nums"
            role="spinbutton"
            :aria-valuenow="parts.seconds"
            :aria-label="t('pickers.second', 'Second')"
            tabindex="0"
            @keydown.up.prevent="stepSecond(1)"
            @keydown.down.prevent="stepSecond(-1)"
          >
            {{ pad2(parts.seconds) }}
          </div>
          <button type="button" :class="stepBtn" :aria-label="t('pickers.secondDown', 'Second down')" @click="stepSecond(-1)">
            <Icon name="chevron-down" />
          </button>
        </div>
      </template>

      <!-- AM/PM toggle (12h) -->
      <div v-if="hour12" class="flex flex-col items-center justify-center gap-next-1 pl-next-1">
        <button
          type="button"
          class="w-10 rounded-next-sm px-next-1 py-next-1 text-next-xs font-next-medium"
          :class="period === 'AM' ? 'bg-next-primary text-next-primary-foreground' : 'text-next-muted-foreground hover:bg-next-accent'"
          :aria-pressed="period === 'AM'"
          @click="period !== 'AM' && togglePeriod()"
        >
          AM
        </button>
        <button
          type="button"
          class="w-10 rounded-next-sm px-next-1 py-next-1 text-next-xs font-next-medium"
          :class="period === 'PM' ? 'bg-next-primary text-next-primary-foreground' : 'text-next-muted-foreground hover:bg-next-accent'"
          :aria-pressed="period === 'PM'"
          @click="period !== 'PM' && togglePeriod()"
        >
          PM
        </button>
      </div>
    </div>
  </FieldPopover>
</template>
