<script setup lang="ts">
// MonthGrid — the 6×7 month surface: roles, roving focus, the keyboard map, the announcer.
//
// THE GRID MATHS IS NOT WRITTEN HERE. `buildMonthWeeks(viewDate, 1)` in
// `ui/forms/date/dateCore.ts` already returns exactly this shape — six rows of seven
// `{date, iso, day, inCurrentMonth}` — tested, dependency-free, and shared with every date
// picker in the app. A second implementation of "which square is the 1st of the month in"
// is a second place for it to be wrong.
//
// WHAT IS *NOT* REUSED IS `CalendarPanel.vue`, and that is deliberate (spec D1). It is a
// date PICKER: `h-9 w-9` cells with nothing inside them, a selection model, a range hover
// preview — and a `today()` computed in the BROWSER's zone. This grid needs cells several
// rows tall with content in them and a "today" in the WORKSPACE's zone. Adding both worlds
// to the picker would give every date field in the app a second mode and one shared
// regression. What IS copied, deliberately and exactly, is its ACCESSIBILITY: the same
// roles, the same roving tabindex, the same key map, the same polite live region — because
// that is the part users already know from every other date control here.
//
// THE KEY MAP CROSSES MONTHS. Arrowing off the end of the window does not stop dead: the
// grid asks its parent for the next month and remembers which day to land on, so paging
// with the keyboard feels like one continuous strip of days rather than six-week islands.
import { computed, nextTick, ref, watch } from 'vue';
import DayCell from './DayCell.vue';
import { useI18n } from '../../app/i18n';
import { collapseDense } from './occurrenceGroups';
import {
  addDays,
  fromIsoDate,
  fullDateLabel,
  toIsoDate,
  weekdayNames,
  type CalendarCell,
} from '../../ui/forms/date/dateCore';
import type { CalendarOccurrence, IsoDay } from './types';

const props = defineProps<{
  /** Six rows of seven, straight from `buildMonthWeeks`. */
  weeks: CalendarCell[][];
  /** Day → that day's occurrences, in the server's order. */
  buckets: Map<IsoDay, CalendarOccurrence[]>;
  timezone: string;
  locale: string;
  /** `workspaceToday(meta.timezone)` — never the browser's today. */
  today: IsoDay;
  /** The month heading, reused as the grid's accessible name. */
  monthLabel: string;
  /** 3 / 2 chips per cell, or 0 for the compact dot presentation. */
  chipLimit: number;
  /** A window is loading behind the current one. */
  busy: boolean;
  sourceLabelOf: (id: string) => string;
}>();

const emit = defineEmits<{
  (e: 'select', occurrence: CalendarOccurrence): void;
  (e: 'open-day', iso: IsoDay): void;
  (e: 'create', iso: IsoDay): void;
  /** Ask the parent to move the view by N months (PageUp/PageDown, or arrowing off the edge). */
  (e: 'shift-month', months: number): void;
}>();

const { t } = useI18n();

const weekdays = computed(() => weekdayNames(props.locale, 1, props.chipLimit <= 0 ? 'narrow' : 'short'));

const gridRef = ref<HTMLElement | null>(null);
const liveLabel = ref('');

/** Every ISO day currently rendered — the bounds the arrow keys stay inside. */
const isoSet = computed(() => new Set(props.weeks.flat().map((cell) => cell.iso)));

/**
 * The single tab stop. Seeded on today when today is in view, else on the first day of the
 * displayed month — the same "land somewhere meaningful" rule the pickers use.
 */
function defaultFocus(): IsoDay {
  if (isoSet.value.has(props.today)) return props.today;
  return props.weeks.flat().find((cell) => cell.inCurrentMonth)?.iso ?? props.weeks[0]?.[0]?.iso ?? props.today;
}
const focusIso = ref<IsoDay>(defaultFocus());

/**
 * A day we asked the PARENT to navigate to. The month has to change before that square
 * exists, so the intent is parked here and consumed by the watcher below once the new
 * weeks arrive — without it, arrowing past the end of the window would move the month and
 * silently drop the focus back to the 1st.
 */
const pendingFocus = ref<IsoDay | null>(null);

watch(
  () => props.weeks,
  () => {
    const wanted = pendingFocus.value;
    pendingFocus.value = null;
    if (wanted && isoSet.value.has(wanted)) {
      moveFocusTo(wanted, true);
      return;
    }
    // A month changed under us by some other route (the nav bar, a saved view): keep the
    // tab stop somewhere real.
    if (!isoSet.value.has(focusIso.value)) focusIso.value = defaultFocus();
  },
);

function occurrencesOn(iso: IsoDay): CalendarOccurrence[] {
  return props.buckets.get(iso) ?? [];
}

function announce(iso: IsoDay): void {
  const date = fromIsoDate(iso);
  const count = occurrencesOn(iso).length;
  const label = date ? fullDateLabel(date, props.locale) : iso;
  liveLabel.value =
    count > 0 ? `${label}, ${t('calendar.day.count', '', { n: count })}` : `${label}, ${t('calendar.day.empty')}`;
}

function moveFocusTo(iso: IsoDay, focusElement: boolean): void {
  focusIso.value = iso;
  announce(iso);
  if (!focusElement) return;
  void nextTick(() => {
    gridRef.value?.querySelector<HTMLElement>(`[data-iso="${iso}"]`)?.focus({ preventScroll: true });
  });
}

/**
 * Move to `target`. Inside the window it is a plain focus move; outside it, the parent is
 * asked to page and the day is remembered for when it arrives.
 */
function goTo(target: Date): void {
  const iso = toIsoDate(target);
  if (isoSet.value.has(iso)) {
    moveFocusTo(iso, true);
    return;
  }
  pendingFocus.value = iso;
  const current = fromIsoDate(focusIso.value);
  const monthsAway =
    current != null
      ? (target.getFullYear() - current.getFullYear()) * 12 + (target.getMonth() - current.getMonth())
      : 0;
  emit('shift-month', monthsAway !== 0 ? monthsAway : target > (current ?? target) ? 1 : -1);
}

function focusedDate(): Date {
  return fromIsoDate(focusIso.value) ?? new Date();
}

/** Move the VIEW by whole months/years, taking the focused day along (PageUp/PageDown). */
function shiftBy(months: number): void {
  const current = focusedDate();
  const target = new Date(current.getFullYear(), current.getMonth() + months, current.getDate());
  pendingFocus.value = toIsoDate(target);
  emit('shift-month', months);
}

function onKeydown(event: KeyboardEvent): void {
  const cur = focusedDate();
  switch (event.key) {
    case 'ArrowLeft':
      event.preventDefault();
      goTo(addDays(cur, -1));
      return;
    case 'ArrowRight':
      event.preventDefault();
      goTo(addDays(cur, 1));
      return;
    case 'ArrowUp':
      event.preventDefault();
      goTo(addDays(cur, -7));
      return;
    case 'ArrowDown':
      event.preventDefault();
      goTo(addDays(cur, 7));
      return;
    case 'Home':
      // First / last day of the focused WEEK (Monday-start), matching CalendarPanel.
      event.preventDefault();
      goTo(addDays(cur, -((cur.getDay() + 6) % 7)));
      return;
    case 'End':
      event.preventDefault();
      goTo(addDays(cur, 6 - ((cur.getDay() + 6) % 7)));
      return;
    case 'PageUp':
      event.preventDefault();
      shiftBy(event.shiftKey ? -12 : -1);
      return;
    case 'PageDown':
      event.preventDefault();
      shiftBy(event.shiftKey ? 12 : 1);
      return;
    case 'Enter':
    case ' ': {
      event.preventDefault();
      // A day with something on it opens its popover — the keyboard route to the chips,
      // which are deliberately not tab stops. An empty day opens the create drawer, which
      // is the only thing there is to do with it.
      const iso = focusIso.value;
      if (occurrencesOn(iso).length > 0) emit('open-day', iso);
      else emit('create', iso);
      return;
    }
    default:
  }
}

/** Clicking a cell also takes the tab stop, so Tab returns to where the eye is. */
function onCellFocusIn(iso: IsoDay): void {
  if (focusIso.value !== iso) {
    focusIso.value = iso;
    announce(iso);
  }
}

const isWeekend = (index: number): boolean => index >= 5;
</script>

<template>
  <div class="flex flex-col">
    <!-- Weekday header. `aria-hidden` because every cell's own label already names its
         full date — reading "Mon" before each of 42 cells is noise, not information. -->
    <div class="grid grid-cols-7 border-b border-next-border" aria-hidden="true">
      <div
        v-for="(name, i) in weekdays"
        :key="i"
        class="px-next-1_5 py-next-2 text-next-2xs font-next-semibold uppercase tracking-wide text-next-muted-foreground"
        :class="isWeekend(i) ? 'text-next-muted-foreground/70' : ''"
      >
        {{ name }}
      </div>
    </div>

    <div
      ref="gridRef"
      role="grid"
      :aria-label="monthLabel"
      :aria-busy="busy ? 'true' : undefined"
      class="overflow-hidden rounded-b-next-lg border-l border-t border-next-border transition-opacity duration-[var(--duration-next-fast)]"
      :class="busy ? 'pointer-events-none opacity-60' : ''"
      @keydown="onKeydown"
    >
      <div v-for="(week, wi) in weeks" :key="wi" role="row" class="grid grid-cols-7">
        <DayCell
          v-for="(cell, di) in week"
          :key="cell.iso"
          :cell="cell"
          :rows="collapseDense(occurrencesOn(cell.iso))"
          :total="occurrencesOn(cell.iso).length"
          :timezone="timezone"
          :locale="locale"
          :is-today="cell.iso === today"
          :is-weekend="isWeekend(di)"
          :is-past="cell.iso < today"
          :focused="cell.iso === focusIso"
          :chip-limit="chipLimit"
          :source-label-of="sourceLabelOf"
          @focusin="onCellFocusIn(cell.iso)"
          @select="emit('select', $event)"
          @open-day="emit('open-day', cell.iso)"
          @create="emit('create', cell.iso)"
        />
      </div>
    </div>

    <!-- The focused day, announced politely. The only thing on this screen that speaks
         without being asked. -->
    <span class="next-sr-only" aria-live="polite">{{ liveLabel }}</span>
  </div>
</template>

<style scoped>
.next-sr-only {
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
