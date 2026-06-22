<script setup lang="ts">
// DateRangeFilter — a SINGLE date-range filter control for the "next" frontend.
//
// One trigger (a FieldShell with a calendar icon, a summary text, and a chevron)
// that opens ONE popover holding everything the legacy `DateRangeSelect` did:
//   • an optional "hide items without a date" toggle,
//   • quick PRESETS (today / this week / … — ids the backend understands as
//     `date_preset`),
//   • FROM and TO fields that each open an inline calendar on click — exactly like
//     the old two-select approach: click "od" → calendar for from, click "do" →
//     calendar for to. The calendar is shared and swaps between the two fields.
//     Clicking the same field button again hides the calendar.
//   • Clear / Apply actions.
//
// MODEL CONTRACT: v-model is
//   { preset, from, to, hide_without_deadline }
// where `from`/`to` are ISO day strings `yyyy-mm-dd` (never Date) and `preset` is
// '' | one of the preset ids.
import { computed, ref, watch } from 'vue';
import Icon from '../primitives/Icon.vue';
import Button from '../primitives/Button.vue';
import FieldShell from './FieldShell.vue';
import FieldPopover from './FieldPopover.vue';
import Switch from './Switch.vue';
import CalendarPanel from './date/CalendarPanel.vue';
import SegmentedControl, { type SegmentOption } from './SegmentedControl.vue';
import { useFormField, nextId } from './formField';
import { type ControlSize, useControlSize } from './fieldShell';
import { useI18n } from '../../app/i18n';
import {
  formatDate,
  fromIsoDate,
  startOfMonth,
  toIsoDate,
  today,
  type WeekDay,
} from './date/dateCore';

/** A preset whose `id` is what the backend stores (e.g. `date_preset`). */
export interface DateRangeFilterPreset {
  id: string;
  label: string;
}

export interface DateRangeFilterValue {
  /** Preset id, or '' when an explicit range / nothing is chosen. */
  preset: string;
  /** Explicit range start, ISO `yyyy-mm-dd` or null. */
  from: string | null;
  /** Explicit range end, ISO `yyyy-mm-dd` or null. */
  to: string | null;
  /** The optional "hide items without a date" toggle state. */
  hide_without_deadline: boolean;
}

const props = withDefaults(
  defineProps<{
    size?: ControlSize;
    disabled?: boolean;
    readonly?: boolean;
    clearable?: boolean;
    /** Preset shortcuts shown above the calendar. */
    presets?: DateRangeFilterPreset[];
    /** Show the "hide without a date" toggle inside the popover. */
    showEmptyToggle?: boolean;
    /** Label + hint for that toggle (consumer-supplied so it's domain-specific). */
    emptyToggleLabel?: string;
    emptyToggleHint?: string;
    /** Trigger placeholder when nothing is selected. */
    placeholder?: string;
    format?: string;
    locale?: string;
    weekStartsOn?: WeekDay;
    ariaInvalid?: boolean;
    success?: boolean;
    dirty?: boolean;
    id?: string;
    describedById?: string;
    ariaLabel?: string;
  }>(),
  {
    disabled: false,
    readonly: false,
    clearable: true,
    presets: () => [],
    showEmptyToggle: false,
    format: 'dd.mm.yyyy',
    weekStartsOn: 1,
    success: false,
    dirty: false,
  },
);

const model = defineModel<DateRangeFilterValue>({
  default: () => ({ preset: '', from: null, to: null, hide_without_deadline: false }),
});

const { t, currentLocale } = useI18n();
const effectiveLocale = computed(() => props.locale ?? currentLocale.value);

const field = useFormField();
const controlSize = useControlSize(() => props.size);
const generatedId = nextId('next-date-range-filter');
const resolvedId = computed(() => props.id ?? field?.id.value ?? generatedId);
const panelId = computed(() => `${resolvedId.value}-panel`);
const resolvedDescribedBy = computed(
  () => props.describedById ?? field?.describedById.value,
);
const invalid = computed(() => props.ariaInvalid ?? field?.invalid.value ?? false);
const success = computed(() => props.success || (field?.valid.value ?? false));
const dirty = computed(() => props.dirty || (field?.dirty.value ?? false));
const disabled = computed(() => props.disabled || (field?.disabled.value ?? false));
const readonly = computed(() => props.readonly || (field?.readonly.value ?? false));

const placeholderText = computed(
  () => props.placeholder ?? t('dateRangeFilter.placeholder', 'Any date'),
);
// SHORT endpoint labels ("od"/"do" / "From"/"To").
const fromLabel = computed(() => t('dateRangeFilter.from', 'From'));
const toLabel = computed(() => t('dateRangeFilter.to', 'To'));

// ── Trigger summary text ──────────────────────────────────────────────────────
function fmt(iso: string | null): string {
  const d = fromIsoDate(iso);
  return d ? formatDate(d, props.format) : '';
}
const presetLabel = computed(
  () => props.presets.find((p) => p.id === model.value.preset)?.label ?? '',
);
const displayText = computed(() => {
  const { from, to } = model.value;
  if (from && to) return `${fmt(from)} – ${fmt(to)}`;
  if (from) return `${fromLabel.value}: ${fmt(from)}`;
  if (to) return `${toLabel.value}: ${fmt(to)}`;
  return presetLabel.value || '';
});
const hasSelection = computed(
  () => !!model.value.from || !!model.value.to || !!model.value.preset,
);

// ── Calendar open/close state ─────────────────────────────────────────────────
// null = calendar hidden; 'from'|'to' = calendar open for that endpoint.
const activeCalendarField = ref<'from' | 'to' | null>(null);
const viewMonth = ref<string>(toIsoDate(startOfMonth(fromIsoDate(model.value.from) ?? today())));

function openCalendar(which: 'from' | 'to'): void {
  if (activeCalendarField.value === which) {
    // Toggle: clicking the same button closes the calendar.
    activeCalendarField.value = null;
    return;
  }
  activeCalendarField.value = which;
  const current = which === 'from' ? model.value.from : model.value.to;
  viewMonth.value = toIsoDate(startOfMonth(fromIsoDate(current) ?? today()));
}

// Setting either endpoint clears any preset (explicit range wins).
function setEndpoint(which: 'from' | 'to', iso: string | null): void {
  model.value = { ...model.value, preset: '', [which]: iso };
}

// Clear a single endpoint; keep that field's calendar open so user can repick.
function clearEndpoint(which: 'from' | 'to'): void {
  setEndpoint(which, null);
  activeCalendarField.value = which;
  viewMonth.value = toIsoDate(startOfMonth(today()));
}

function onCalendarSelect(iso: string): void {
  if (!activeCalendarField.value) return;
  setEndpoint(activeCalendarField.value, iso);
  if (activeCalendarField.value === 'from') {
    // Advance to 'to' automatically after picking 'from'.
    activeCalendarField.value = 'to';
    viewMonth.value = toIsoDate(startOfMonth(fromIsoDate(iso) ?? today()));
  } else {
    // Close after picking 'to'.
    activeCalendarField.value = null;
  }
}

// Calendar reflects the active endpoint (min/max constrain the range).
const calendarSelected = computed<string | null>(() =>
  activeCalendarField.value === 'from' ? model.value.from : model.value.to,
);
const calendarMin = computed<string | null>(() =>
  activeCalendarField.value === 'to' ? model.value.from : null,
);
const calendarMax = computed<string | null>(() =>
  activeCalendarField.value === 'from' ? model.value.to : null,
);

// ── Presets + empty toggle ────────────────────────────────────────────────────
// Null when explicit dates are chosen or nothing is selected; that maps to the
// SegmentedControl's allowNone "no thumb" state.
const activePreset = computed<string | null>(() =>
  model.value.preset && !model.value.from && !model.value.to ? model.value.preset : null,
);
const presetOptions = computed<SegmentOption[]>(() =>
  props.presets.map((p) => ({ value: p.id, label: p.label })),
);
function applyPreset(id: string | null): void {
  if (!id) return;
  model.value = { ...model.value, preset: id, from: null, to: null };
  activeCalendarField.value = null;
}
function setHideEmpty(value: boolean): void {
  model.value = { ...model.value, hide_without_deadline: value };
}

// ── Trigger / popover wiring ──────────────────────────────────────────────────
const popoverRef = ref<InstanceType<typeof FieldPopover> | null>(null);

// When the popover closes, also close any open inner calendar.
watch(
  () => popoverRef.value,
  () => {
    if (!popoverRef.value) activeCalendarField.value = null;
  },
);

function clear(): void {
  model.value = { preset: '', from: null, to: null, hide_without_deadline: false };
  activeCalendarField.value = null;
}
function applyAndClose(): void {
  activeCalendarField.value = null;
  popoverRef.value?.closePanel(true);
}

const showClear = computed(
  () => props.clearable && !disabled.value && !readonly.value && hasSelection.value,
);

// Shared style for the clickable date field buttons.
const fieldBtnClass =
  'flex h-9 w-full items-center gap-next-1_5 rounded-next-sm border bg-next-card px-next-2 text-next-sm transition-colors duration-[var(--duration-next-fast)]';
const fieldBtnActive = 'border-next-ring ring-2 ring-next-ring/30';
const fieldBtnIdle =
  'border-next-input hover:border-next-ring/50 focus-visible:border-next-ring focus-visible:ring-2 focus-visible:ring-next-ring/30';
</script>

<template>
  <FieldPopover
    ref="popoverRef"
    :disabled="disabled || readonly"
    :panel-id="panelId"
    :aria-label="ariaLabel ?? t('dateRangeFilter.label', 'Date filter')"
  >
    <template #trigger="{ open, toggle }">
      <FieldShell
        :size="controlSize"
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
          class="flex h-full w-full min-w-0 flex-1 items-center pl-next-2 pr-next-3 text-left outline-none disabled:cursor-not-allowed"
          :disabled="disabled"
          :aria-haspopup="'dialog'"
          :aria-expanded="open"
          :aria-controls="panelId"
          :aria-invalid="invalid ? 'true' : undefined"
          :aria-describedby="resolvedDescribedBy"
          :aria-label="ariaLabel"
          @click="toggle"
        >
          <span v-if="displayText" class="truncate">{{ displayText }}</span>
          <span v-else class="truncate text-next-muted-foreground">{{ placeholderText }}</span>
        </button>

        <template #trailing>
          <span class="flex items-center gap-next-1">
            <span class="flex h-5 w-5 items-center justify-center">
              <button
                type="button"
                class="flex items-center rounded-next-sm p-next-0_5 text-next-muted-foreground hover:text-next-fg"
                :class="showClear ? '' : 'invisible'"
                :aria-hidden="showClear ? undefined : 'true'"
                :tabindex="showClear ? undefined : -1"
                :aria-label="t('pickers.clear', 'Clear')"
                @click.stop="clear"
              >
                <Icon name="x" />
              </button>
            </span>
            <Icon
              name="chevron-down"
              class="shrink-0 text-next-muted-foreground transition-transform duration-[var(--duration-next-fast)]"
              :class="open ? 'rotate-180' : ''"
              aria-hidden="true"
            />
          </span>
        </template>
      </FieldShell>
    </template>

    <!-- Panel: fixed 19rem so the 7-column calendar (7×2.25rem = 252px) fits
         comfortably with padding and the two-column od/do row. -->
    <div class="flex w-[19rem] flex-col gap-next-2 p-next-2">
      <!-- "Hide without a date" toggle (optional). -->
      <div v-if="showEmptyToggle" class="flex flex-col gap-next-0_5 px-next-1">
        <Switch
          :model-value="model.hide_without_deadline"
          size="sm"
          :label="emptyToggleLabel"
          @update:model-value="setHideEmpty"
        />
        <span v-if="emptyToggleHint" class="text-next-xs text-next-muted-foreground">
          {{ emptyToggleHint }}
        </span>
      </div>

      <div v-if="showEmptyToggle && presets.length" class="border-t border-next-border" />

      <!-- Preset shortcuts as a SegmentedControl. -->
      <SegmentedControl
        v-if="presets.length"
        :model-value="activePreset"
        :options="presetOptions"
        size="sm"
        :columns="2"
        allow-none
        :aria-label="t('dateRangeFilter.presetsLabel', 'Date preset')"
        @update:model-value="applyPreset"
      />

      <div v-if="presets.length" class="border-t border-next-border" />

      <!-- FROM / TO clickable date field buttons.
           Clicking a button opens the shared calendar below for that endpoint;
           clicking the same button again hides it (toggle). After picking "from",
           the calendar automatically switches to "to"; after picking "to" it closes. -->
      <div class="grid grid-cols-2 gap-next-2">
        <!-- From -->
        <div class="flex flex-col gap-next-0_5">
          <span class="text-next-xs font-next-medium text-next-muted-foreground">
            {{ fromLabel }}
          </span>
          <button
            type="button"
            :class="[fieldBtnClass, activeCalendarField === 'from' ? fieldBtnActive : fieldBtnIdle]"
            :aria-label="fromLabel"
            :aria-expanded="activeCalendarField === 'from'"
            @click="openCalendar('from')"
          >
            <span v-if="model.from" class="flex-1 truncate text-left">{{ fmt(model.from) }}</span>
            <span v-else class="flex-1 text-left text-next-muted-foreground">{{ format }}</span>
            <button
              v-if="model.from"
              type="button"
              class="flex shrink-0 items-center rounded-next-sm p-next-0_5 text-next-muted-foreground hover:text-next-fg"
              :aria-label="t('pickers.clear', 'Clear') + ': ' + fromLabel"
              @click.stop="clearEndpoint('from')"
            >
              <Icon name="x" />
            </button>
          </button>
        </div>

        <!-- To -->
        <div class="flex flex-col gap-next-0_5">
          <span class="text-next-xs font-next-medium text-next-muted-foreground">
            {{ toLabel }}
          </span>
          <button
            type="button"
            :class="[fieldBtnClass, activeCalendarField === 'to' ? fieldBtnActive : fieldBtnIdle]"
            :aria-label="toLabel"
            :aria-expanded="activeCalendarField === 'to'"
            @click="openCalendar('to')"
          >
            <span v-if="model.to" class="flex-1 truncate text-left">{{ fmt(model.to) }}</span>
            <span v-else class="flex-1 text-left text-next-muted-foreground">{{ format }}</span>
            <button
              v-if="model.to"
              type="button"
              class="flex shrink-0 items-center rounded-next-sm p-next-0_5 text-next-muted-foreground hover:text-next-fg"
              :aria-label="t('pickers.clear', 'Clear') + ': ' + toLabel"
              @click.stop="clearEndpoint('to')"
            >
              <Icon name="x" />
            </button>
          </button>
        </div>
      </div>

      <!-- Shared inline calendar: appears when either field button is active. -->
      <CalendarPanel
        v-if="activeCalendarField !== null"
        :model-value="calendarSelected"
        v-model:view-date="viewMonth"
        :min="calendarMin"
        :max="calendarMax"
        :locale="effectiveLocale"
        :week-starts-on="weekStartsOn"
        @select="onCalendarSelect"
      />

      <div class="flex justify-end gap-next-2 border-t border-next-border pt-next-2">
        <Button variant="ghost" size="sm" @click="clear">{{ t('pickers.clear', 'Clear') }}</Button>
        <Button variant="primary" size="sm" @click="applyAndClose">
          {{ t('pickers.apply', 'Apply') }}
        </Button>
      </div>
    </div>
  </FieldPopover>
</template>
