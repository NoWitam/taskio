<script setup lang="ts">
// CalendarPanel — the month grid shared by every date picker in the "next"
// family. It renders:
//   • a header: month/year label + prev/next month arrows + quick month & year
//     jump menus,
//   • a 6×7 day grid (always 6 rows so the height never changes month-to-month),
//   • a today marker, selected day, and — in `range` mode — in-range fill, the two
//     endpoints, and a live hover preview of the pending range.
//
// It is PURELY a calendar surface: it has no border/state-line and no popover; the
// pickers embed it inside a FieldPopover. All date math comes from `dateCore`.
//
// Model: a single ISO day string `yyyy-mm-dd` (or `{start,end}` in range mode).
// Values are ISO STRINGS, never Date objects (see dateCore's serialization
// contract — avoids timezone drift).
//
// Keyboard (roving tabindex on the focused cell, `role="grid"`):
//   ←/→            previous / next day
//   ↑/↓            previous / next week
//   PageUp/Down    previous / next month
//   Shift+PageUp/Down   previous / next year
//   Home / End     first / last day of the focused week
//   Enter / Space  select the focused day
//   Esc            bubbles to the popover (close)
// ARIA: `role="grid"` with `role="row"`/`role="gridcell"`, `aria-selected` on the
// chosen day, `aria-current="date"` on today, `aria-disabled` on blocked days; the
// focused day is announced via a polite live region.
import { computed, nextTick, ref, watch } from 'vue';
import Icon from '../../primitives/Icon.vue';
import { useI18n } from '../../../app/i18n';
import {
  addDays,
  addMonths,
  addYears,
  buildMonthWeeks,
  clampDate,
  compareDay,
  fromIsoDate,
  fullDateLabel,
  isDateDisabled,
  isSameDay,
  isWithinRange,
  makeDate,
  monthNames,
  monthYearLabel,
  startOfMonth,
  toIsoDate,
  today,
  weekdayNames,
  type DisabledDatePredicate,
  type WeekDay,
} from './dateCore';

const props = withDefaults(
  defineProps<{
    /** Range mode: select two endpoints with a hover preview. */
    range?: boolean;
    /** Min selectable day, ISO `yyyy-mm-dd`. */
    min?: string | null;
    /** Max selectable day, ISO `yyyy-mm-dd`. */
    max?: string | null;
    /** Extra disable predicate (receives a local Date). */
    disabledDate?: DisabledDatePredicate | null;
    /** BCP-47 locale for names. Default Polish. */
    locale?: string;
    /** 0 = Sunday … 6 = Saturday. Default Monday (1) for pl. */
    weekStartsOn?: WeekDay;
    /** Optional second month rendered to the right (range, ≥ md). Adds +1 month. */
    monthOffset?: number;
    /** Hide the prev/next + quick-jump header (used by the right month in a pair). */
    hideNav?: boolean;
    /** Externally-controlled view month (ISO day in the month). Two-way. */
    viewDate?: string | null;
  }>(),
  {
    range: false,
    min: null,
    max: null,
    disabledDate: null,
    weekStartsOn: 1,
    monthOffset: 0,
    hideNav: false,
    viewDate: null,
  },
);

const emit = defineEmits<{
  /** A day was activated (selection). Host decides what to do with it. */
  (e: 'select', isoDay: string): void;
  /** The view month changed (so a paired calendar can follow). */
  (e: 'update:viewDate', isoDay: string): void;
}>();

const { t, currentLocale } = useI18n();
// Effective BCP-47 locale for Intl month/weekday names: explicit `locale` prop
// wins, else follow the active UI language so the calendar localizes with the app.
const effectiveLocale = computed(() => props.locale ?? currentLocale.value);

// Single-date model (range mode uses start/end models instead).
const selected = defineModel<string | null>({ default: null });
const rangeStart = defineModel<string | null>('start', { default: null });
const rangeEnd = defineModel<string | null>('end', { default: null });
/** The pending hover endpoint while picking the second range bound. */
const hoverPreview = defineModel<string | null>('hover', { default: null });

const minDate = computed(() => fromIsoDate(props.min));
const maxDate = computed(() => fromIsoDate(props.max));
const startDate = computed(() => fromIsoDate(rangeStart.value));
const endDate = computed(() => fromIsoDate(rangeEnd.value));
const selectedDate = computed(() => fromIsoDate(selected.value));
const hoverDate = computed(() => fromIsoDate(hoverPreview.value));

// ── View month (which month the grid shows) ──────────────────────────────────
// Initialized from the selection / start / today, clamped into [min,max].
function initialView(): Date {
  const seed =
    fromIsoDate(props.viewDate) ??
    selectedDate.value ??
    startDate.value ??
    today();
  return startOfMonth(addMonths(seed, props.monthOffset));
}
const view = ref<Date>(initialView());

watch(
  () => props.viewDate,
  (v) => {
    const d = fromIsoDate(v);
    if (d) view.value = startOfMonth(addMonths(d, props.monthOffset));
  },
);
// Keep the view following an externally-set selection when it lands off-screen.
watch(selectedDate, (d) => {
  if (d && d.getMonth() !== view.value.getMonth()) view.value = startOfMonth(d);
});

function setView(next: Date): void {
  view.value = startOfMonth(next);
  emit('update:viewDate', toIsoDate(startOfMonth(addMonths(next, -props.monthOffset))));
}
function prevMonth(): void {
  setView(addMonths(view.value, -1));
}
function nextMonth(): void {
  setView(addMonths(view.value, 1));
}

// ── Names / grid ──────────────────────────────────────────────────────────────
const weekdays = computed(() =>
  weekdayNames(effectiveLocale.value, props.weekStartsOn, 'short'),
);
const months = computed(() => monthNames(effectiveLocale.value, 'long'));
const heading = computed(() => monthYearLabel(view.value, effectiveLocale.value));
const weeks = computed(() => buildMonthWeeks(view.value, props.weekStartsOn));

// Stable header width: reserve room for the LONGEST month name in the active
// locale so navigating months never reflows the panel (Issue 6 — the locale
// override case had variable-width month names). Computed once per locale from
// the long month-name list; we approximate width in `ch` from the longest label.
const longestMonthChars = computed(() =>
  months.value.reduce((max, name) => Math.max(max, name.length), 0),
);
// `+5` leaves slack for the 4-digit year + spacing inside the heading button.
const headingMinWidth = computed(() => `${longestMonthChars.value + 5}ch`);

// Quick-jump menus (month + a windowed year list around the view).
const showMonthJump = ref(false);
const showYearJump = ref(false);
const yearWindow = computed(() => {
  const y = view.value.getFullYear();
  return Array.from({ length: 12 }, (_, i) => y - 6 + i);
});
function jumpMonth(monthIndex: number): void {
  setView(makeDate(view.value.getFullYear(), monthIndex, 1));
  showMonthJump.value = false;
}
function jumpYear(year: number): void {
  setView(makeDate(year, view.value.getMonth(), 1));
  showYearJump.value = false;
}

// ── Per-cell state ────────────────────────────────────────────────────────────
const todayDate = today();

function dayDisabled(date: Date): boolean {
  return isDateDisabled(date, {
    min: minDate.value,
    max: maxDate.value,
    disabledDate: props.disabledDate,
  });
}
function isSelectedDay(date: Date): boolean {
  if (props.range) {
    return isSameDay(date, startDate.value) || isSameDay(date, endDate.value);
  }
  return isSameDay(date, selectedDate.value);
}
// In-range fill: between the committed start and either the committed end or, while
// picking the 2nd bound, the hovered day (live preview).
function inRange(date: Date): boolean {
  if (!props.range || !startDate.value) return false;
  const end = endDate.value ?? hoverDate.value;
  if (!end) return false;
  return isWithinRange(date, startDate.value, end) && !isSelectedDay(date);
}
function isRangeStart(date: Date): boolean {
  if (!props.range || !startDate.value) return false;
  const end = endDate.value ?? hoverDate.value;
  const lo = end && compareDay(end, startDate.value) < 0 ? end : startDate.value;
  return isSameDay(date, lo);
}
function isRangeEnd(date: Date): boolean {
  if (!props.range) return false;
  const end = endDate.value ?? hoverDate.value;
  if (!startDate.value || !end) return false;
  const hi = compareDay(end, startDate.value) < 0 ? startDate.value : end;
  return isSameDay(date, hi);
}

// ── Roving focus ──────────────────────────────────────────────────────────────
// One cell in the grid is tabbable; arrows move it. We seed it on the selected
// day (or today, or the 1st) and keep it inside the current view.
const focusIso = ref<string>(
  toIsoDate(
    clampDate(
      selectedDate.value ?? startDate.value ?? todayDate,
      minDate.value,
      maxDate.value,
    ),
  ),
);
const gridRef = ref<HTMLElement | null>(null);
const liveLabel = ref('');

function isFocusCell(date: Date): boolean {
  return toIsoDate(date) === focusIso.value;
}

function moveFocus(next: Date): void {
  // Follow the focused day across month boundaries.
  if (next.getMonth() !== view.value.getMonth() || next.getFullYear() !== view.value.getFullYear()) {
    setView(startOfMonth(next));
  }
  focusIso.value = toIsoDate(next);
  liveLabel.value = fullDateLabel(next, effectiveLocale.value);
  nextTick(() => {
    gridRef.value
      ?.querySelector<HTMLElement>(`[data-iso="${focusIso.value}"]`)
      ?.focus({ preventScroll: true });
  });
}

function focusedDate(): Date {
  return fromIsoDate(focusIso.value) ?? todayDate;
}

function onGridKeydown(event: KeyboardEvent): void {
  const cur = focusedDate();
  let next: Date | null = null;
  switch (event.key) {
    case 'ArrowLeft':
      next = addDays(cur, -1);
      break;
    case 'ArrowRight':
      next = addDays(cur, 1);
      break;
    case 'ArrowUp':
      next = addDays(cur, -7);
      break;
    case 'ArrowDown':
      next = addDays(cur, 7);
      break;
    case 'Home':
      next = addDays(cur, -((cur.getDay() - props.weekStartsOn + 7) % 7));
      break;
    case 'End':
      next = addDays(cur, 6 - ((cur.getDay() - props.weekStartsOn + 7) % 7));
      break;
    case 'PageUp':
      next = event.shiftKey ? addYears(cur, -1) : addMonths(cur, -1);
      break;
    case 'PageDown':
      next = event.shiftKey ? addYears(cur, 1) : addMonths(cur, 1);
      break;
    case 'Enter':
    case ' ':
      event.preventDefault();
      choose(cur);
      return;
    default:
      return;
  }
  if (next) {
    event.preventDefault();
    moveFocus(next);
  }
}

// ── Selection ─────────────────────────────────────────────────────────────────
function choose(date: Date): void {
  if (dayDisabled(date)) return;
  emit('select', toIsoDate(date));
}
function onHover(date: Date): void {
  if (!props.range) return;
  if (dayDisabled(date)) return;
  hoverPreview.value = toIsoDate(date);
}
function onLeaveGrid(): void {
  if (props.range) hoverPreview.value = null;
}
</script>

<template>
  <div class="flex flex-col gap-next-2 p-next-3">
    <!-- Header: month/year + nav + quick jumps -->
    <div v-if="!hideNav" class="flex items-center justify-between gap-next-2">
      <button
        type="button"
        class="flex h-7 w-7 items-center justify-center rounded-next-sm text-next-muted-foreground hover:bg-next-accent hover:text-next-accent-foreground"
        :aria-label="t('pickers.prevMonth', 'Previous month')"
        @click="prevMonth"
      >
        <Icon name="chevron-left" />
      </button>

      <div class="relative flex items-center gap-next-1">
        <button
          type="button"
          class="flex justify-center rounded-next-sm px-next-1_5 py-next-0_5 text-next-sm font-next-medium capitalize hover:bg-next-accent hover:text-next-accent-foreground"
          :style="{ minWidth: headingMinWidth }"
          :aria-expanded="showMonthJump"
          aria-haspopup="menu"
          :aria-label="t('pickers.selectMonth', 'Select month')"
          @click="showMonthJump = !showMonthJump; showYearJump = false"
        >
          {{ heading }}
        </button>

        <!-- Quick month menu -->
        <div
          v-if="showMonthJump"
          role="menu"
          class="absolute left-0 top-[calc(100%+4px)] z-10 grid w-44 grid-cols-3 gap-next-1 rounded-next-md border border-next-border bg-next-popover p-next-2 shadow-next-md"
        >
          <button
            v-for="(name, mi) in months"
            :key="mi"
            type="button"
            role="menuitem"
            class="rounded-next-sm px-next-1 py-next-1 text-next-xs capitalize hover:bg-next-accent hover:text-next-accent-foreground"
            :class="mi === view.getMonth() ? 'bg-next-primary text-next-primary-foreground' : ''"
            @click="jumpMonth(mi)"
          >
            {{ name.slice(0, 3) }}
          </button>
        </div>

        <!-- Quick year menu -->
        <button
          type="button"
          class="rounded-next-sm px-next-1 py-next-0_5 text-next-sm font-next-medium hover:bg-next-accent hover:text-next-accent-foreground"
          :aria-expanded="showYearJump"
          aria-haspopup="menu"
          :aria-label="t('pickers.selectYear', 'Select year')"
          @click="showYearJump = !showYearJump; showMonthJump = false"
        >
          {{ view.getFullYear() }}
        </button>
        <div
          v-if="showYearJump"
          role="menu"
          class="absolute right-0 top-[calc(100%+4px)] z-10 grid w-44 grid-cols-3 gap-next-1 rounded-next-md border border-next-border bg-next-popover p-next-2 shadow-next-md"
        >
          <button
            v-for="y in yearWindow"
            :key="y"
            type="button"
            role="menuitem"
            class="rounded-next-sm px-next-1 py-next-1 text-next-xs hover:bg-next-accent hover:text-next-accent-foreground"
            :class="y === view.getFullYear() ? 'bg-next-primary text-next-primary-foreground' : ''"
            @click="jumpYear(y)"
          >
            {{ y }}
          </button>
        </div>
      </div>

      <button
        type="button"
        class="flex h-7 w-7 items-center justify-center rounded-next-sm text-next-muted-foreground hover:bg-next-accent hover:text-next-accent-foreground"
        :aria-label="t('pickers.nextMonth', 'Next month')"
        @click="nextMonth"
      >
        <Icon name="chevron-right" />
      </button>
    </div>

    <!-- A read-only heading for the right month in a pair (no nav). -->
    <div
      v-else
      class="flex h-7 items-center justify-center text-next-sm font-next-medium capitalize"
    >
      {{ heading }}
    </div>

    <!-- Weekday header. Fixed-width columns (tabular) so a wide short-weekday name
         in some locales can never stretch the grid / reflow the panel (Issue 6). -->
    <div class="next-cal-grid grid gap-next-0_5" aria-hidden="true">
      <div
        v-for="(wd, i) in weekdays"
        :key="i"
        class="flex h-7 items-center justify-center overflow-hidden text-next-2xs font-next-semibold uppercase text-next-muted-foreground"
      >
        {{ wd }}
      </div>
    </div>

    <!-- Day grid -->
    <div
      ref="gridRef"
      role="grid"
      :aria-label="heading"
      class="next-cal-grid grid gap-next-0_5"
      @keydown="onGridKeydown"
      @mouseleave="onLeaveGrid"
    >
      <template v-for="(week, wi) in weeks" :key="wi">
        <button
          v-for="cell in week"
          :key="cell.iso"
          type="button"
          role="gridcell"
          :data-iso="cell.iso"
          :tabindex="isFocusCell(cell.date) ? 0 : -1"
          :disabled="dayDisabled(cell.date)"
          :aria-selected="isSelectedDay(cell.date) ? 'true' : 'false'"
          :aria-current="isSameDay(cell.date, todayDate) ? 'date' : undefined"
          :aria-disabled="dayDisabled(cell.date) ? 'true' : undefined"
          :aria-label="fullDateLabel(cell.date, effectiveLocale)"
          class="next-cal-cell relative flex h-9 w-9 items-center justify-center tabular-nums text-next-sm transition-colors duration-[var(--duration-next-instant)]"
          :class="[
            cell.inCurrentMonth ? '' : 'text-next-muted-foreground/60',
            dayDisabled(cell.date)
              ? 'cursor-not-allowed text-next-muted-foreground/40 line-through'
              : 'cursor-pointer hover:bg-next-accent hover:text-next-accent-foreground',
            isSelectedDay(cell.date)
              ? 'bg-next-primary font-next-semibold text-next-primary-foreground hover:bg-next-primary hover:text-next-primary-foreground'
              : '',
            inRange(cell.date) ? 'bg-next-primary-subtle text-next-primary-subtle-foreground' : '',
            isRangeStart(cell.date) ? 'rounded-l-next-md' : '',
            isRangeEnd(cell.date) ? 'rounded-r-next-md' : '',
            !isRangeStart(cell.date) && !isRangeEnd(cell.date) && !inRange(cell.date)
              ? 'rounded-next-md'
              : '',
          ]"
          @click="choose(cell.date)"
          @mouseenter="onHover(cell.date)"
          @focus="onHover(cell.date)"
        >
          {{ cell.day }}
          <!-- Today ring (kept distinct from selection; never color-only). -->
          <span
            v-if="isSameDay(cell.date, todayDate) && !isSelectedDay(cell.date)"
            class="pointer-events-none absolute inset-x-2 bottom-1 h-px rounded-full bg-next-primary"
            aria-hidden="true"
          />
        </button>
      </template>
    </div>

    <!-- Polite announcement of the focused/selected day. -->
    <span class="sr-only" aria-live="polite">{{ liveLabel }}</span>
  </div>
</template>

<style scoped>
/* Fixed 7-column tabular grid: each column is a fixed 2.25rem (matching the
   h-9/w-9 day cells) so the panel has a STABLE intrinsic width regardless of the
   locale's month/weekday name widths — prev/next never reflows the panel. */
.next-cal-grid {
  grid-template-columns: repeat(7, 2.25rem);
  justify-content: center;
}

.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}
</style>
