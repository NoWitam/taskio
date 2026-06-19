<script setup lang="ts">
// DateRangePicker — start/end date range for the "next" frontend.
//
// Two typeable text segments (start + end, `dd.mm.yyyy`) share ONE FieldShell
// under one label (a FormField multi-input demo, managed internally). The popover
// shows preset shortcuts plus a CalendarPanel in `range` mode: two months side by
// side ≥ next-md, one month stacked below on narrow screens, with a live hover
// preview of the pending range.
//
// MODEL CONTRACT: v-model is `{ start: string|null, end: string|null }` where each
// endpoint is an ISO day string `yyyy-mm-dd` — NEVER a Date. See `date/dateCore.ts`.
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
  addDays,
  addMonths,
  compareDay,
  endOfMonth,
  fromIsoDate,
  formatDate,
  maskDateInput,
  parseDateInput,
  startOfMonth,
  toIsoDate,
  today,
  type DisabledDatePredicate,
  type WeekDay,
} from './date/dateCore';

export interface DateRangeValue {
  start: string | null;
  end: string | null;
}
export interface DateRangePreset {
  id: string;
  label: string;
  /** Returns the [start,end] ISO days for this preset. */
  range: () => { start: string; end: string };
}

const props = withDefaults(
  defineProps<{
    size?: ControlSize;
    disabled?: boolean;
    readonly?: boolean;
    min?: string | null;
    max?: string | null;
    disabledDate?: DisabledDatePredicate | null;
    clearable?: boolean;
    format?: string;
    locale?: string;
    weekStartsOn?: WeekDay;
    /** Override the preset shortcut list (default: common Polish presets). */
    presets?: DateRangePreset[];
    /** Hide preset shortcuts entirely. */
    hidePresets?: boolean;
    ariaInvalid?: boolean;
    success?: boolean;
    dirty?: boolean;
    id?: string;
    describedById?: string;
    ariaLabel?: string;
  }>(),
  {
    size: 'md',
    disabled: false,
    readonly: false,
    min: null,
    max: null,
    disabledDate: null,
    clearable: true,
    format: 'dd.mm.yyyy',
    weekStartsOn: 1,
    hidePresets: false,
    success: false,
    dirty: false,
  },
);

const model = defineModel<DateRangeValue>({
  default: () => ({ start: null, end: null }),
});

const { t, currentLocale } = useI18n();
// Effective BCP-47 locale for the calendar names: explicit prop wins, else follow
// the active UI language.
const effectiveLocale = computed(() => props.locale ?? currentLocale.value);

const field = useFormField();
const generatedId = nextId('next-range');
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

// ── Typed segments ↔ model ────────────────────────────────────────────────────
const startText = ref('');
const endText = ref('');
const parseError = ref(false);

function syncTextFromModel(): void {
  const s = fromIsoDate(model.value.start);
  const e = fromIsoDate(model.value.end);
  startText.value = s ? formatDate(s, props.format) : '';
  endText.value = e ? formatDate(e, props.format) : '';
  parseError.value = false;
}
watch(model, syncTextFromModel, { immediate: true, deep: true });

function setEndpoint(which: 'start' | 'end', iso: string | null): void {
  let next: DateRangeValue = { ...model.value, [which]: iso };
  // Keep start ≤ end; swap if the user inverts them.
  const s = fromIsoDate(next.start);
  const e = fromIsoDate(next.end);
  if (s && e && compareDay(s, e) > 0) {
    next = { start: next.end, end: next.start };
  }
  model.value = next;
}

function onSegmentInput(which: 'start' | 'end', event: Event): void {
  const masked = maskDateInput((event.target as HTMLInputElement).value, props.format);
  if (which === 'start') startText.value = masked;
  else endText.value = masked;
  if (!masked) {
    setEndpoint(which, null);
    parseError.value = false;
    return;
  }
  const parsed = parseDateInput(masked, props.format);
  if (parsed) {
    parseError.value = false;
    setEndpoint(which, toIsoDate(parsed));
  } else {
    parseError.value =
      masked.replace(/\D/g, '').length >= (props.format.includes('yyyy') ? 8 : 6);
  }
}
function onBlur(): void {
  syncTextFromModel();
}

// ── Calendar range picking (two-tap with hover preview) ───────────────────────
// While picking: first tap sets `start` and clears `end`; second tap sets `end`.
const picking = ref(false);
const calStart = ref<string | null>(null);
const calEnd = ref<string | null>(null);
const hover = ref<string | null>(null);
// Shared left-month view so the paired right calendar (monthOffset 1) follows
// prev/next navigation in lockstep.
const viewMonth = ref<string>(toIsoDate(startOfMonth(today())));

function syncCalFromModel(): void {
  calStart.value = model.value.start;
  calEnd.value = model.value.end;
  picking.value = false;
  const seed = fromIsoDate(model.value.start) ?? today();
  viewMonth.value = toIsoDate(startOfMonth(seed));
}
watch(model, syncCalFromModel, { immediate: true, deep: true });

function onDaySelect(iso: string): void {
  if (!picking.value || !calStart.value || calEnd.value) {
    // Start a fresh range.
    calStart.value = iso;
    calEnd.value = null;
    picking.value = true;
  } else {
    // Complete the range (order-tolerant).
    const a = fromIsoDate(calStart.value)!;
    const b = fromIsoDate(iso)!;
    if (compareDay(a, b) <= 0) {
      calStart.value = toIsoDate(a);
      calEnd.value = toIsoDate(b);
    } else {
      calStart.value = toIsoDate(b);
      calEnd.value = toIsoDate(a);
    }
    picking.value = false;
    model.value = { start: calStart.value, end: calEnd.value };
  }
  hover.value = null;
}

// ── Presets (Polish labels) ───────────────────────────────────────────────────
function isoToday(): string {
  return toIsoDate(today());
}
// Default preset shortcuts — labels resolve through the i18n catalog so they
// follow the active UI language (overridable via the `presets` prop).
const defaultPresets = computed<DateRangePreset[]>(() => [
  {
    id: 'today',
    label: t('pickers.presets.today', 'Today'),
    range: () => ({ start: isoToday(), end: isoToday() }),
  },
  {
    id: 'yesterday',
    label: t('pickers.presets.yesterday', 'Yesterday'),
    range: () => {
      const y = toIsoDate(addDays(today(), -1));
      return { start: y, end: y };
    },
  },
  {
    id: 'last7',
    label: t('pickers.presets.last7', 'Last 7 days'),
    range: () => ({ start: toIsoDate(addDays(today(), -6)), end: isoToday() }),
  },
  {
    id: 'last30',
    label: t('pickers.presets.last30', 'Last 30 days'),
    range: () => ({ start: toIsoDate(addDays(today(), -29)), end: isoToday() }),
  },
  {
    id: 'thisMonth',
    label: t('pickers.presets.thisMonth', 'This month'),
    range: () => ({ start: toIsoDate(startOfMonth(today())), end: toIsoDate(endOfMonth(today())) }),
  },
  {
    id: 'lastMonth',
    label: t('pickers.presets.lastMonth', 'Last month'),
    range: () => {
      const prev = addMonths(today(), -1);
      return { start: toIsoDate(startOfMonth(prev)), end: toIsoDate(endOfMonth(prev)) };
    },
  },
]);
const presetList = computed(() => props.presets ?? defaultPresets.value);

function applyPreset(preset: DateRangePreset): void {
  const { start, end } = preset.range();
  model.value = { start, end };
}

const popoverRef = ref<InstanceType<typeof FieldPopover> | null>(null);
function openPanel(): void {
  if (disabled.value || readonly.value) return;
  syncCalFromModel();
  popoverRef.value?.openPanel();
}
function clear(): void {
  model.value = { start: null, end: null };
  startText.value = '';
  endText.value = '';
  picking.value = false;
}
function applyAndClose(): void {
  if (calStart.value) {
    model.value = { start: calStart.value, end: calEnd.value ?? calStart.value };
  }
  popoverRef.value?.closePanel(true);
}

const invalid = computed(() => fieldInvalid.value || parseError.value);
const success = computed(() => fieldSuccess.value && !parseError.value);
const hasValue = computed(() => !!model.value.start || !!model.value.end);
const showClear = computed(
  () => props.clearable && !disabled.value && !readonly.value && hasValue.value,
);

const segmentClass =
  'h-full min-w-0 flex-1 truncate border-0 bg-transparent text-current outline-none ' +
  'placeholder:text-next-muted-foreground disabled:cursor-not-allowed';
</script>

<template>
  <FieldPopover
    ref="popoverRef"
    :disabled="disabled || readonly"
    :panel-id="panelId"
    :aria-label="t('pickers.rangePickerLabel', 'Date range selection')"
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

        <!-- Two text segments under one shell, divided by an arrow. -->
        <div
          class="flex h-full min-w-0 flex-1 items-center gap-next-2 pl-next-2"
          :class="FIELD_PADDING_X[size]"
          role="group"
          :aria-label="ariaLabel ?? t('pickers.rangeLabel', 'Date range')"
        >
          <input
            :id="resolvedId"
            :value="startText"
            type="text"
            inputmode="numeric"
            :placeholder="format"
            :disabled="disabled"
            :readonly="readonly"
            autocomplete="off"
            :aria-label="t('pickers.startDate', 'Start date')"
            :aria-invalid="invalid ? 'true' : undefined"
            :aria-describedby="resolvedDescribedBy"
            :aria-required="required ? 'true' : undefined"
            :class="segmentClass"
            @input="onSegmentInput('start', $event)"
            @blur="onBlur"
            @click="openPanel"
            @keydown.down.prevent="openPanel"
          />
          <Icon name="arrow-right" class="shrink-0 text-next-muted-foreground" />
          <input
            :value="endText"
            type="text"
            inputmode="numeric"
            :placeholder="format"
            :disabled="disabled"
            :readonly="readonly"
            autocomplete="off"
            :aria-label="t('pickers.endDate', 'End date')"
            :aria-invalid="invalid ? 'true' : undefined"
            :class="segmentClass"
            @input="onSegmentInput('end', $event)"
            @blur="onBlur"
            @click="openPanel"
            @keydown.down.prevent="openPanel"
          />
        </div>

        <template #trailing>
          <span class="flex items-center gap-next-1">
            <span v-if="clearable" class="flex h-5 w-5 items-center justify-center">
              <button
                type="button"
                class="flex items-center rounded-next-sm p-next-0_5 text-next-muted-foreground hover:text-next-fg"
                :class="showClear ? '' : 'invisible'"
                :aria-hidden="showClear ? undefined : 'true'"
                tabindex="-1"
                :aria-label="t('pickers.clearRange', 'Clear range')"
                @click.stop="clear"
              >
                <Icon name="x" />
              </button>
            </span>
            <button
              type="button"
              class="flex items-center rounded-next-sm p-next-0_5 text-next-muted-foreground hover:text-next-fg disabled:cursor-not-allowed"
              :disabled="disabled || readonly"
              :aria-label="t('pickers.openCalendar', 'Open calendar')"
              tabindex="-1"
              @click.stop="openPanel"
            >
              <Icon name="calendar" />
            </button>
          </span>
        </template>
      </FieldShell>
    </template>

    <div class="flex flex-col next-md:flex-row">
      <!-- Presets -->
      <div
        v-if="!hidePresets"
        class="flex flex-row flex-wrap gap-next-1 border-b border-next-border p-next-2 next-md:w-40 next-md:flex-col next-md:border-b-0 next-md:border-r"
      >
        <button
          v-for="preset in presetList"
          :key="preset.id"
          type="button"
          class="rounded-next-sm px-next-2 py-next-1_5 text-left text-next-sm hover:bg-next-accent hover:text-next-accent-foreground"
          @click="applyPreset(preset)"
        >
          {{ preset.label }}
        </button>
      </div>

      <div class="flex flex-col">
        <!-- Calendars: two side-by-side ≥ md, one stacked below. -->
        <div class="flex flex-col next-md:flex-row">
          <CalendarPanel
            range
            v-model:view-date="viewMonth"
            :start="calStart"
            :end="calEnd"
            v-model:hover="hover"
            :min="min"
            :max="max"
            :disabled-date="disabledDate"
            :locale="effectiveLocale"
            :week-starts-on="weekStartsOn"
            @select="onDaySelect"
          />
          <CalendarPanel
            class="hidden next-md:block next-md:border-l next-md:border-next-border"
            range
            hide-nav
            :month-offset="1"
            :view-date="viewMonth"
            :start="calStart"
            :end="calEnd"
            v-model:hover="hover"
            :min="min"
            :max="max"
            :disabled-date="disabledDate"
            :locale="effectiveLocale"
            :week-starts-on="weekStartsOn"
            @select="onDaySelect"
          />
        </div>

        <div class="flex justify-end gap-next-2 border-t border-next-border p-next-2">
          <Button variant="ghost" size="sm" @click="clear">{{ t('pickers.clear', 'Clear') }}</Button>
          <Button variant="primary" size="sm" @click="applyAndClose">{{ t('pickers.apply', 'Apply') }}</Button>
        </div>
      </div>
    </div>
  </FieldPopover>
</template>
