<script setup lang="ts">
// DayCell — ONE square of the month grid.
//
// It carries four things, in this order of importance: the day number, whatever fits of
// the day's occurrences, an honest count of what did not fit, and — when the day is empty
// — an invitation to put something there.
//
// "TODAY" IS THREE SIGNALS, NOT A COLOUR. A tinted cell, a filled pill around the number,
// and `aria-current="date"`. In dark mode `primary-subtle` sits close to the card surface,
// so the tint alone would be nearly invisible; the pill and the weight carry it. Which day
// is today comes from `workspaceToday(meta.timezone)` upstream — never from the browser.
//
// THE OVERFLOW COUNT IS COMPUTED AFTER FOLDING. The parent hands down an already-folded
// list, so "+3 more" promises exactly what the day popover will contain. Counting before
// the fold would over-promise by however long the dense series was.
//
// FOCUS. The CELL is the tab stop (roving `tabindex`, one `0` per grid); the chips inside
// are explicitly `tabindex="-1"`. Forty-one extra tab stops would turn crossing this screen
// into a journey — the standard `grid` compromise, and the reason the day popover exists
// as the keyboard route to individual chips.
import { computed } from 'vue';
import Icon from '../../ui/primitives/Icon.vue';
import Button from '../../ui/primitives/Button.vue';
import OccurrenceChip from './OccurrenceChip.vue';
import { useI18n } from '../../app/i18n';
import { colorTokens } from './calendarMeta';
import { splitOverflow, type DisplayOccurrence } from './occurrenceGroups';
import { fullDateLabel } from '../../ui/forms/date/dateCore';
import type { CalendarCell } from '../../ui/forms/date/dateCore';
import type { CalendarOccurrence } from './types';

const props = withDefaults(
  defineProps<{
    cell: CalendarCell;
    /** The day's rows, ALREADY folded by `collapseDense` and in the server's order. */
    rows: DisplayOccurrence[];
    /** How many occurrences the day really holds (pre-fold) — for the accessible label. */
    total: number;
    timezone: string;
    locale: string;
    isToday: boolean;
    isWeekend: boolean;
    /** Whole day is behind us — the chips dim (a grid-only affordance). */
    isPast: boolean;
    /** This cell holds the grid's single `tabindex="0"`. */
    focused: boolean;
    /**
     * How many chips fit. `0` switches the cell to the COMPACT presentation (colour dots
     * only, the whole day one target) used between `next-md` and `next-lg`, where a chip
     * with text stops being readable before it stops being clickable.
     */
    chipLimit: number;
    /** Source id → its server-translated name (for chip accessible labels). */
    sourceLabelOf: (id: string) => string;
  }>(),
  { chipLimit: 3 },
);

const emit = defineEmits<{
  (e: 'select', occurrence: CalendarOccurrence): void;
  /** Open the day popover (the "+N more" affordance, or the whole cell when compact). */
  (e: 'open-day'): void;
  /** Create an event on this day (a click on the cell's empty background). */
  (e: 'create'): void;
}>();

const { t } = useI18n();

const compact = computed(() => props.chipLimit <= 0);
const split = computed(() => splitOverflow(props.rows, props.chipLimit));
const isEmpty = computed(() => props.rows.length === 0);

/** Up to four colour dots — the compact cell's entire content besides the number. */
const dots = computed(() => props.rows.slice(0, 4).map((row) => colorTokens(row.occurrence.color).bar));

const dateLabel = computed(() => fullDateLabel(props.cell.date, props.locale));

/**
 * What a screen reader hears on arriving at the cell: the full date and how many things
 * are on it — the two facts a sighted user gets from the number and the chips. The count
 * is the REAL total, not the folded row count: "3 occurrences" must not become "1" just
 * because two of them were drawn as one.
 */
const ariaLabel = computed(() =>
  props.total > 0
    ? `${dateLabel.value}, ${t('calendar.day.count', '', { n: props.total })}`
    : `${dateLabel.value}, ${t('calendar.day.empty')}`,
);

/**
 * A click that did not land on a control is a click on the day itself → create an event
 * here. Tested by walking up to the nearest button rather than with `.self`, because the
 * cell's padding, its layout column and the empty space under the chips are all different
 * elements and all of them mean "the background".
 */
function onCellClick(event: MouseEvent): void {
  if ((event.target as HTMLElement | null)?.closest('button')) return;
  if (compact.value) {
    emit('open-day');
    return;
  }
  emit('create');
}
</script>

<template>
  <!-- Today's tint wins over the weekend one; a day outside the shown month is quieter
       than both but stays fully interactive — it IS in the loaded window. -->
  <div
    role="gridcell"
    :data-iso="cell.iso"
    :tabindex="focused ? 0 : -1"
    :aria-label="ariaLabel"
    :aria-current="isToday ? 'date' : undefined"
    class="next-day-cell group relative flex flex-col gap-next-1 border-b border-r border-next-border p-next-1_5 text-left outline-none transition-colors duration-[var(--duration-next-fast)] focus-visible:z-[1] focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-next-ring"
    :class="[
      compact ? 'min-h-[4.5rem]' : 'min-h-[6.5rem] next-xl:min-h-[7.5rem]',
      isToday ? 'bg-next-primary-subtle' : isWeekend ? 'bg-next-muted/40' : '',
      !cell.inCurrentMonth && !isToday ? 'bg-next-bg/40' : '',
      isEmpty ? 'cursor-pointer hover:bg-next-accent' : '',
    ]"
    @click="onCellClick"
  >
    <!-- Header row: the day number, and the overflow counter pinned opposite it. -->
    <div class="flex items-start justify-between gap-next-1">
      <span
        class="flex h-5 min-w-5 shrink-0 items-center justify-center rounded-next-full px-next-1 text-next-xs tabular-nums"
        :class="[
          isToday ? 'bg-next-primary font-next-semibold text-next-primary-foreground' : '',
          !isToday && cell.inCurrentMonth ? 'text-next-fg' : '',
          !isToday && !cell.inCurrentMonth ? 'text-next-muted-foreground/60' : '',
        ]"
      >
        {{ cell.day }}
      </span>

      <!-- COMPACT: the count rides beside the number, since there is no room for chips. -->
      <span
        v-if="compact && total > 0"
        class="shrink-0 text-next-2xs font-next-medium tabular-nums text-next-muted-foreground"
      >
        {{ total }}
      </span>

      <Button
        v-else-if="split.overflow > 0"
        variant="ghost"
        size="xs"
        class="-mr-next-1 -mt-next-0_5 shrink-0"
        :aria-label="t('calendar.day.showAll', '', { date: dateLabel })"
        @click="emit('open-day')"
      >
        {{ t('calendar.day.more', '', { n: split.overflow }) }}
      </Button>
    </div>

    <!-- COMPACT: colour dots. Decorative — the cell's own aria-label already carries the
         count, and the popover carries the detail. -->
    <div v-if="compact" class="flex flex-wrap items-center gap-next-1" aria-hidden="true">
      <span
        v-for="(dot, i) in dots"
        :key="i"
        class="h-1.5 w-1.5 rounded-next-full"
        :class="dot"
      />
    </div>

    <!-- FULL: the chips, in the server's order, capped at what fits. -->
    <div v-else class="flex min-h-0 flex-col gap-next-0_5">
      <OccurrenceChip
        v-for="row in split.visible"
        :key="row.occurrence.id"
        :occurrence="row.occurrence"
        variant="grid"
        :timezone="timezone"
        :shown="row.shown"
        :folded="row.folded"
        :source-label="sourceLabelOf(row.occurrence.source)"
        :past="isPast"
        :tabindex="-1"
        @select="emit('select', $event)"
      />
    </div>

    <!-- An empty day is an invitation, not a void — but a quiet one: the hint appears on
         hover / keyboard focus so forty-two of them do not shout at once. -->
    <span
      v-if="isEmpty && !compact"
      class="pointer-events-none absolute bottom-next-1 right-next-1 opacity-0 transition-opacity duration-[var(--duration-next-fast)] group-hover:opacity-60 group-focus-visible:opacity-60"
      :title="t('calendar.day.createHere')"
      aria-hidden="true"
    >
      <Icon name="plus" class="text-next-xs text-next-muted-foreground" />
    </span>
  </div>
</template>
