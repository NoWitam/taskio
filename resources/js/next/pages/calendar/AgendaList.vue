<script setup lang="ts">
// AgendaList — the same window as the grid, read as a list.
//
// SAME DATA, NO REFETCH. The agenda and the grid share one 42-day window (spec D2), so
// switching between them is instant, one set of loss notices covers both, and a user
// toggling back and forth can never be told two different stories about the same month.
// The visible consequence is that the agenda also shows the spill days from the
// neighbouring months — which is honest (they ARE in the window) and is why every group
// heading names its month.
//
// EMPTY DAYS ARE SKIPPED. A grid is a calendar and its empty squares are meaningful; a
// list is a list of what there is, and forty empty headings would bury the four real ones.
//
// The order inside a day is the SERVER's (all-day before timed, then by time, then id) and
// is never recomputed here.
import { computed } from 'vue';
import Badge from '../../ui/primitives/Badge.vue';
import OccurrenceChip from './OccurrenceChip.vue';
import { useI18n } from '../../app/i18n';
import { collapseDense } from './occurrenceGroups';
import { fromIsoDate, fullDateLabel } from '../../ui/forms/date/dateCore';
import type { CalendarOccurrence, IsoDay } from './types';

const props = defineProps<{
  /** Every day of the window, ascending. Empty ones are dropped here. */
  days: IsoDay[];
  buckets: Map<IsoDay, CalendarOccurrence[]>;
  timezone: string;
  locale: string;
  today: IsoDay;
  busy: boolean;
  sourceLabelOf: (id: string) => string;
}>();

const emit = defineEmits<{
  (e: 'select', occurrence: CalendarOccurrence): void;
  (e: 'open-day', iso: IsoDay): void;
}>();

const { t } = useI18n();

interface AgendaGroup {
  iso: IsoDay;
  label: string;
  total: number;
  rows: ReturnType<typeof collapseDense>;
  isToday: boolean;
}

const groups = computed<AgendaGroup[]>(() =>
  props.days
    .map((iso) => {
      const list = props.buckets.get(iso) ?? [];
      const date = fromIsoDate(iso);
      return {
        iso,
        label: date ? fullDateLabel(date, props.locale) : iso,
        total: list.length,
        rows: collapseDense(list),
        isToday: iso === props.today,
      };
    })
    .filter((group) => group.total > 0),
);

/**
 * A folded series opens the day popover (its full, unfolded list); anything else opens the
 * occurrence itself. The row must not pretend a fold is a single thing you can go and look
 * at — there are `shown` of them behind it.
 */
function onRowSelect(group: AgendaGroup, folded: boolean, occurrence: CalendarOccurrence): void {
  if (folded) emit('open-day', group.iso);
  else emit('select', occurrence);
}
</script>

<template>
  <div
    class="flex flex-col gap-next-4 transition-opacity duration-[var(--duration-next-fast)]"
    :aria-busy="busy ? 'true' : undefined"
    :class="busy ? 'pointer-events-none opacity-60' : ''"
  >
    <section
      v-for="group in groups"
      :key="group.iso"
      role="group"
      :aria-labelledby="`agenda-h-${group.iso}`"
      class="flex flex-col"
    >
      <!-- Sticky heading: the full date (with its month, for the spill days), a "Today"
           badge, and the day's REAL count — the pre-fold number, so a folded series does
           not shrink the day. -->
      <div
        class="sticky top-0 z-[var(--z-next-raised)] flex items-center gap-next-2 border-b border-next-border bg-next-bg/95 py-next-2 backdrop-blur"
        :aria-current="group.isToday ? 'date' : undefined"
      >
        <h2
          :id="`agenda-h-${group.iso}`"
          class="min-w-0 flex-1 truncate text-next-sm font-next-semibold capitalize text-next-fg"
        >
          {{ group.label }}
        </h2>
        <Badge v-if="group.isToday" variant="primary" tone="subtle" size="sm">
          {{ t('calendar.nav.today') }}
        </Badge>
        <span class="shrink-0 text-next-xs tabular-nums text-next-muted-foreground">
          {{ group.total }}
        </span>
      </div>

      <ul class="flex flex-col gap-next-1 pt-next-2">
        <li v-for="row in group.rows" :key="row.occurrence.id">
          <OccurrenceChip
            :occurrence="row.occurrence"
            variant="agenda"
            :timezone="timezone"
            :shown="row.shown"
            :folded="row.folded"
            :source-label="sourceLabelOf(row.occurrence.source)"
            @select="onRowSelect(group, row.folded, $event)"
          />
        </li>
      </ul>
    </section>
  </div>
</template>
