<script setup lang="ts">
// MonthPicker — month + year selection (no day grid) for the "next" frontend.
//
// A read-only trigger (localized "month year") rendered through FieldShell, with a
// popover holding a year stepper + a 3×4 month grid. Useful for reporting periods,
// billing months, etc.
//
// MODEL CONTRACT: v-model is an ISO day string pinned to the first of the month,
// `yyyy-mm-01` (or null) — NEVER a Date. Consistent with DatePicker so the same
// ISO-day plumbing serves both. See `date/dateCore.ts`.
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import Icon from '../primitives/Icon.vue';
import FieldShell from './FieldShell.vue';
import FieldPopover from './FieldPopover.vue';
import { useFormField, nextId } from './formField';
import { FIELD_PADDING_X, type ControlSize } from './fieldShell';
import { useI18n } from '../../app/i18n';
import {
  fromIsoDate,
  isSameMonth,
  makeDate,
  monthNames,
  monthYearLabel,
  toIsoDate,
  today,
  type WeekDay,
} from './date/dateCore';

const props = withDefaults(
  defineProps<{
    size?: ControlSize;
    placeholder?: string;
    disabled?: boolean;
    readonly?: boolean;
    /** Min month (ISO day; any day in the month). */
    min?: string | null;
    /** Max month (ISO day; any day in the month). */
    max?: string | null;
    clearable?: boolean;
    locale?: string;
    /** Unused for the grid, accepted for API symmetry with the family. */
    weekStartsOn?: WeekDay;
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
    clearable: true,
    weekStartsOn: 1,
    success: false,
    dirty: false,
  },
);

// ISO day string pinned to the 1st (`yyyy-mm-01`) or null. Never a Date.
const model = defineModel<string | null>({ default: null });

const { t, currentLocale } = useI18n();
// Effective BCP-47 locale for Intl month/year names: explicit `locale` prop wins,
// otherwise follow the active UI language so names switch with the app.
const effectiveLocale = computed(() => props.locale ?? currentLocale.value);

const field = useFormField();
const generatedId = nextId('next-month');
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

const selectedDate = computed(() => fromIsoDate(model.value));
const minDate = computed(() => fromIsoDate(props.min));
const maxDate = computed(() => fromIsoDate(props.max));

const text = computed(() =>
  selectedDate.value ? monthYearLabel(selectedDate.value, effectiveLocale.value) : '',
);

const months = computed(() => monthNames(effectiveLocale.value, 'short'));

// View year (the year shown in the grid).
const viewYear = ref<number>((selectedDate.value ?? today()).getFullYear());
watch(selectedDate, (d) => {
  if (d) viewYear.value = d.getFullYear();
});

// ── Year-list view ─────────────────────────────────────────────────────────────
// Clicking the year header opens a 12-year paged grid to jump directly to a year.
const panelView = ref<'months' | 'years'>('months');

function pageStartFor(year: number): number {
  // Align pages on multiples of 12 (… 2016–2027, 2028–2039 …).
  return Math.floor(year / 12) * 12;
}
// First year of the current 12-year page (aligned so the view year is on-page).
const yearPageStart = ref<number>(pageStartFor(viewYear.value));
const yearPage = computed<number[]>(() =>
  Array.from({ length: 12 }, (_, i) => yearPageStart.value + i),
);
const activeYear = ref<number>(viewYear.value);
const yearGridRef = ref<HTMLElement | null>(null);

function openYearView(): void {
  yearPageStart.value = pageStartFor(viewYear.value);
  activeYear.value = viewYear.value;
  panelView.value = 'years';
  nextTick(() => focusActiveYear());
}
function focusActiveYear(): void {
  yearGridRef.value
    ?.querySelector<HTMLElement>(`[data-year="${activeYear.value}"]`)
    ?.focus({ preventScroll: true });
}
function backToMonths(): void {
  panelView.value = 'months';
}
function prevYearPage(): void {
  yearPageStart.value -= 12;
}
function nextYearPage(): void {
  yearPageStart.value += 12;
}
function yearDisabled(year: number): boolean {
  if (minDate.value && year < minDate.value.getFullYear()) return true;
  if (maxDate.value && year > maxDate.value.getFullYear()) return true;
  return false;
}
function chooseYear(year: number): void {
  if (yearDisabled(year)) return;
  viewYear.value = year;
  activeYear.value = year;
  panelView.value = 'months';
}
function onYearGridKeydown(e: KeyboardEvent): void {
  let next: number | null = null;
  switch (e.key) {
    case 'ArrowRight':
      next = activeYear.value + 1;
      break;
    case 'ArrowLeft':
      next = activeYear.value - 1;
      break;
    case 'ArrowDown':
      next = activeYear.value + 3;
      break;
    case 'ArrowUp':
      next = activeYear.value - 3;
      break;
    case 'Enter':
    case ' ':
      e.preventDefault();
      chooseYear(activeYear.value);
      return;
    case 'Escape':
      e.preventDefault();
      e.stopPropagation();
      backToMonths();
      return;
    default:
      return;
  }
  if (next != null) {
    e.preventDefault();
    activeYear.value = next;
    // Page-follow when the active year leaves the current page.
    if (next < yearPageStart.value) yearPageStart.value -= 12;
    else if (next >= yearPageStart.value + 12) yearPageStart.value += 12;
    nextTick(() => focusActiveYear());
  }
}

function monthDisabled(monthIndex: number): boolean {
  const first = makeDate(viewYear.value, monthIndex, 1);
  const last = makeDate(viewYear.value, monthIndex + 1, 0);
  if (minDate.value && last.getTime() < makeDate(minDate.value.getFullYear(), minDate.value.getMonth(), 1).getTime()) return true;
  if (maxDate.value && first.getTime() > makeDate(maxDate.value.getFullYear(), maxDate.value.getMonth() + 1, 0).getTime()) return true;
  return false;
}
function isSelectedMonth(monthIndex: number): boolean {
  return isSameMonth(selectedDate.value, makeDate(viewYear.value, monthIndex, 1));
}
function isCurrentMonth(monthIndex: number): boolean {
  return isSameMonth(today(), makeDate(viewYear.value, monthIndex, 1));
}

const popoverRef = ref<InstanceType<typeof FieldPopover> | null>(null);
function choose(monthIndex: number): void {
  if (monthDisabled(monthIndex)) return;
  model.value = toIsoDate(makeDate(viewYear.value, monthIndex, 1));
  popoverRef.value?.closePanel(true);
}
function clear(): void {
  model.value = null;
}
function openPanel(): void {
  if (disabled.value || readonly.value) return;
  panelView.value = 'months';
  popoverRef.value?.openPanel();
}

const invalid = computed(() => fieldInvalid.value);
const success = computed(() => fieldSuccess.value);
const showClear = computed(
  () => props.clearable && !disabled.value && !readonly.value && !!model.value,
);
</script>

<template>
  <FieldPopover
    ref="popoverRef"
    :disabled="disabled || readonly"
    :panel-id="panelId"
    :aria-label="t('pickers.monthLabel', 'Month selection')"
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
          class="flex h-full w-full min-w-0 flex-1 items-center pl-next-2 text-left capitalize outline-none disabled:cursor-not-allowed"
          :class="FIELD_PADDING_X[size]"
          @click="openPanel"
          @keydown.down.prevent="openPanel"
        >
          <span v-if="text" class="truncate">{{ text }}</span>
          <span v-else class="truncate normal-case text-next-muted-foreground">
            {{ placeholder ?? t('pickers.chooseMonth', 'Choose a month') }}
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
              :aria-label="t('pickers.clearMonth', 'Clear month')"
              @click.stop="clear"
            >
              <Icon name="x" />
            </button>
          </span>
        </template>
      </FieldShell>
    </template>

    <div class="flex w-64 flex-col gap-next-2 p-next-3">
      <!-- MONTHS VIEW -->
      <template v-if="panelView === 'months'">
        <!-- Year stepper. Clicking the year label opens the year-list view. -->
        <div class="flex items-center justify-between">
          <button
            type="button"
            class="flex h-7 w-7 items-center justify-center rounded-next-sm text-next-muted-foreground hover:bg-next-accent hover:text-next-accent-foreground"
            :aria-label="t('pickers.prevYear', 'Previous year')"
            @click="viewYear -= 1"
          >
            <Icon name="chevron-left" />
          </button>
          <button
            type="button"
            class="rounded-next-sm px-next-2 py-next-0_5 text-next-sm font-next-semibold hover:bg-next-accent hover:text-next-accent-foreground"
            aria-haspopup="grid"
            :aria-label="t('pickers.selectYear', 'Select year')"
            @click="openYearView"
          >
            {{ viewYear }}
          </button>
          <button
            type="button"
            class="flex h-7 w-7 items-center justify-center rounded-next-sm text-next-muted-foreground hover:bg-next-accent hover:text-next-accent-foreground"
            :aria-label="t('pickers.nextYear', 'Next year')"
            @click="viewYear += 1"
          >
            <Icon name="chevron-right" />
          </button>
        </div>

        <!-- Month grid -->
        <div class="grid grid-cols-3 gap-next-1" role="grid" :aria-label="t('pickers.monthsOfYear', 'Months of {year}', { year: viewYear })">
          <button
            v-for="(name, mi) in months"
            :key="mi"
            type="button"
            role="gridcell"
            :disabled="monthDisabled(mi)"
            :aria-selected="isSelectedMonth(mi) ? 'true' : 'false'"
            :aria-current="isCurrentMonth(mi) ? 'date' : undefined"
            class="rounded-next-md py-next-2 text-next-sm capitalize transition-colors duration-[var(--duration-next-instant)]"
            :class="[
              monthDisabled(mi)
                ? 'cursor-not-allowed text-next-muted-foreground/40 line-through'
                : 'cursor-pointer hover:bg-next-accent hover:text-next-accent-foreground',
              isSelectedMonth(mi) ? 'bg-next-primary font-next-semibold text-next-primary-foreground hover:bg-next-primary hover:text-next-primary-foreground' : '',
              isCurrentMonth(mi) && !isSelectedMonth(mi) ? 'ring-1 ring-next-primary' : '',
            ]"
            @click="choose(mi)"
          >
            {{ name }}
          </button>
        </div>
      </template>

      <!-- YEARS VIEW (12-year page) -->
      <template v-else>
        <div class="flex items-center justify-between">
          <button
            type="button"
            class="flex h-7 w-7 items-center justify-center rounded-next-sm text-next-muted-foreground hover:bg-next-accent hover:text-next-accent-foreground"
            :aria-label="t('pickers.prevYears', 'Previous years')"
            @click="prevYearPage"
          >
            <Icon name="chevron-left" />
          </button>
          <button
            type="button"
            class="rounded-next-sm px-next-2 py-next-0_5 text-next-sm font-next-semibold hover:bg-next-accent hover:text-next-accent-foreground"
            :aria-label="t('pickers.backToMonths', 'Back to months')"
            @click="backToMonths"
          >
            {{ yearPage[0] }}–{{ yearPage[yearPage.length - 1] }}
          </button>
          <button
            type="button"
            class="flex h-7 w-7 items-center justify-center rounded-next-sm text-next-muted-foreground hover:bg-next-accent hover:text-next-accent-foreground"
            :aria-label="t('pickers.nextYears', 'Next years')"
            @click="nextYearPage"
          >
            <Icon name="chevron-right" />
          </button>
        </div>

        <div
          ref="yearGridRef"
          class="grid grid-cols-3 gap-next-1"
          role="grid"
          :aria-label="t('pickers.yearSelection', 'Year selection')"
          @keydown="onYearGridKeydown"
        >
          <button
            v-for="y in yearPage"
            :key="y"
            type="button"
            role="gridcell"
            :data-year="y"
            :disabled="yearDisabled(y)"
            :aria-selected="y === viewYear ? 'true' : 'false'"
            :aria-current="y === today().getFullYear() ? 'date' : undefined"
            :tabindex="y === activeYear ? 0 : -1"
            class="rounded-next-md py-next-2 text-next-sm transition-colors duration-[var(--duration-next-instant)]"
            :class="[
              yearDisabled(y)
                ? 'cursor-not-allowed text-next-muted-foreground/40 line-through'
                : 'cursor-pointer hover:bg-next-accent hover:text-next-accent-foreground',
              y === viewYear ? 'bg-next-primary font-next-semibold text-next-primary-foreground hover:bg-next-primary hover:text-next-primary-foreground' : '',
              y === today().getFullYear() && y !== viewYear ? 'ring-1 ring-next-primary' : '',
            ]"
            @click="chooseYear(y)"
            @focus="activeYear = y"
          >
            {{ y }}
          </button>
        </div>
      </template>
    </div>
  </FieldPopover>
</template>
