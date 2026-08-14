<script setup lang="ts">
// OccurrencePopover — the preview for an occurrence this screen does not own.
//
// Built ENTIRELY from the occurrence's own fields. The Calendar deliberately never
// dereferences a subject's morph alias — that is the dependency it refuses to take, and
// the reason a fifth source can appear without this module changing — so the client cannot
// pretend to know anything more either. There is no fetch here, and there will not be one:
// everything sayable is already on the chip.
//
// The "Open" affordance is therefore the interesting part, and it has three outcomes:
//   • a route that opens the thing        → "Open"
//   • a route that only reaches its SCREEN → a differently-worded action, because a button
//     that says "Open" and lands on a list is a small lie repeated daily (see
//     `subjectLink` for which subject this is and why)
//   • no route at all                      → NO button, and the rest renders normally.
//     Required, not defensive: R4 Publishing will add an alias this build has never seen,
//     and the preview must survive it without a dead link.
//
// Like `DayPopover`, this is a `Modal` rather than an anchored `Popover` — same reason: the
// design system's Popover positions against a trigger it wraps, and there is no element in
// the grid it could wrap without breaking the `row`/`gridcell` structure.
import { computed } from 'vue';
import Modal from '../../ui/overlay/Modal.vue';
import Button from '../../ui/primitives/Button.vue';
import Badge from '../../ui/primitives/Badge.vue';
import Icon from '../../ui/primitives/Icon.vue';
import DescriptionList, { type DescriptionItem } from '../../ui/data/DescriptionList.vue';
import { useI18n } from '../../app/i18n';
import { colorBadgeVariant, sourceIcon, subjectLink } from './calendarMeta';
import { instantToZonedParts } from './calendarZone';
import { fromIsoDate, fullDateLabel } from '../../ui/forms/date/dateCore';
import type { CalendarOccurrence } from './types';

const props = defineProps<{
  occurrence: CalendarOccurrence | null;
  timezone: string;
  locale: string;
  /** The source's server-translated name — rendered, never translated here. */
  sourceLabel: string;
}>();

const emit = defineEmits<{ (e: 'open', to: { path: string; query?: Record<string, string> }): void }>();

const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();

function dayLabel(iso: string | null): string {
  const date = iso ? fromIsoDate(iso) : null;
  return date ? fullDateLabel(date, props.locale) : (iso ?? '');
}

/**
 * The "when" line, in the shape the occurrence actually has.
 *
 * The all-day branch never touches the clock — not even to say "00:00". `start_date` is a
 * calendar day and is formatted as one; it is never parsed as an instant, which is the
 * single conversion that would put a deadline on the wrong date.
 */
const whenText = computed<string>(() => {
  const occurrence = props.occurrence;
  if (!occurrence) return '';

  if (occurrence.all_day) {
    return `${t('calendar.occurrence.allDay')} · ${dayLabel(occurrence.start_date)}`;
  }

  const start = occurrence.starts_at ? instantToZonedParts(occurrence.starts_at, props.timezone) : null;
  if (!start) return '';
  const end = occurrence.ends_at ? instantToZonedParts(occurrence.ends_at, props.timezone) : null;

  const date = dayLabel(start.day);
  return end
    ? `${date}, ${t('calendar.occurrence.range', '', { from: start.time, to: end.time })}`
    : `${date}, ${t('calendar.occurrence.from', '', { time: start.time })}`;
});

const items = computed<DescriptionItem[]>(() => {
  const occurrence = props.occurrence;
  if (!occurrence) return [];
  const rows: DescriptionItem[] = [
    { key: 'when', label: t('calendar.occurrence.when'), value: whenText.value },
    { key: 'source', label: t('calendar.occurrence.source'), value: props.sourceLabel },
  ];
  // The zone is only meaningful for a MOMENT. Printing it beside an all-day date would
  // imply that the day itself has one, which is exactly the confusion `all_day` exists to
  // prevent.
  if (!occurrence.all_day) {
    rows.push({ key: 'tz', label: t('calendar.occurrence.timezone'), value: props.timezone });
  }
  return rows;
});

const link = computed(() => subjectLink(props.occurrence?.subject ?? null));
const linkLabel = computed(() =>
  link.value?.kind === 'list' ? t('calendar.occurrence.openList') : t('calendar.occurrence.open'),
);
</script>

<template>
  <Modal v-model:open="open" size="sm" :aria-label="occurrence?.title ?? ''">
    <template #title>
      <span class="flex min-w-0 items-center gap-next-2">
        <Icon
          v-if="occurrence"
          :name="sourceIcon(occurrence.source)"
          class="shrink-0 text-next-muted-foreground"
          aria-hidden="true"
        />
        <span class="min-w-0 truncate">{{ occurrence?.title }}</span>
      </span>
    </template>

    <div v-if="occurrence" class="flex flex-col gap-next-3">
      <!-- The badge is finished, translated server prose. Rendered verbatim. -->
      <div v-if="occurrence.badge">
        <Badge :variant="colorBadgeVariant(occurrence.badge.color)" tone="subtle">
          {{ occurrence.badge.label }}
        </Badge>
      </div>

      <DescriptionList :items="items" layout="horizontal" size="sm" />
    </div>

    <template #footer>
      <!-- No route → no button. The preview above is still the whole truth. -->
      <Button
        v-if="link"
        variant="outline"
        size="sm"
        trailing-icon="arrow-right"
        @click="emit('open', link.to)"
      >
        {{ linkLabel }}
      </Button>
    </template>
  </Modal>
</template>
