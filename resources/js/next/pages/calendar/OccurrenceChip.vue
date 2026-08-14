<script setup lang="ts">
// OccurrenceChip — ONE thing on the calendar, in three densities.
//
//   `grid`   — inside a month cell (~11rem wide): colour rail, source glyph, time, title.
//   `agenda` — a full row: adds the badge and a time RANGE.
//   `list`   — inside a day popover: like `agenda`, on a narrower surface.
//
// THREE RULES THIS COMPONENT EXISTS TO KEEP, each of which has a way of quietly breaking:
//
// 1. AN ALL-DAY OCCURRENCE HAS NO TIME SLOT AT ALL. Not "00:00", not "—", not an empty
//    span holding the column open. A task deadline is a DAY and will never have an hour;
//    printing one would be inventing data. `start_date` never reaches `new Date()` here —
//    it is not even read for display, because the day is already the cell it sits in.
//
// 2. THE BADGE IS SERVER PROSE — AND SO IS THE CADENCE. `badge.label` and `cadence_label`
//    arrive finished and translated from the module that owns the vocabulary; both are
//    rendered VERBATIM and never looked up in the client catalog. That is the single
//    mechanism by which a fifth source (R4 Publishing) appears on this screen without a
//    line of frontend changing.
//
// 3. THE BADGE IS NOT SHOWN IN THE GRID (spec D5). In a cell that narrow the badge eats
//    the title, and the title is what people scan by. It is one click (the day popover) or
//    one toggle (the agenda) away — hidden, never lost.
//
// Editability is signalled BEFORE the click and by DIRECTION, not by presence: a pencil
// when this opens here, an external-link when it opens somewhere else. Two glyphs, so the
// user learns both facts. `editable` is only an affordance HINT — the truth about
// permissions is the event's own `can_be_edited` / `can_be_deleted`.
import { computed } from 'vue';
import Icon from '../../ui/primitives/Icon.vue';
import Badge from '../../ui/primitives/Badge.vue';
import { useI18n } from '../../app/i18n';
import { colorBadgeVariant, colorTokens, sourceIcon } from './calendarMeta';
import { instantToZonedParts } from './calendarZone';
import type { CalendarOccurrence, OccurrenceVariant } from './types';

const props = withDefaults(
  defineProps<{
    occurrence: CalendarOccurrence;
    variant?: OccurrenceVariant;
    /** The zone from `meta.timezone`. Every instant on this chip is read in it. */
    timezone: string;
    /** How many occurrences this chip stands for (a folded dense series). 1 = itself. */
    shown?: number;
    /** True when this chip folds a dense series. */
    folded?: boolean;
    /** The source's server-translated name, for the accessible label. */
    sourceLabel?: string;
    /** Dim the chip: a past occurrence in the grid (never in the agenda — see §6.3). */
    past?: boolean;
  }>(),
  { variant: 'grid', shown: 1, folded: false, sourceLabel: '', past: false },
);

const emit = defineEmits<{ (e: 'select', occurrence: CalendarOccurrence): void }>();

const { t } = useI18n();

const tokens = computed(() => colorTokens(props.occurrence.color));
const isGrid = computed(() => props.variant === 'grid');

/** `HH:mm` in the workspace zone, or null. Never computed for an all-day occurrence. */
function timeAt(iso: string | null): string | null {
  if (!iso) return null;
  return instantToZonedParts(iso, props.timezone)?.time ?? null;
}

const startTime = computed(() => (props.occurrence.all_day ? null : timeAt(props.occurrence.starts_at)));
const endTime = computed(() => (props.occurrence.all_day ? null : timeAt(props.occurrence.ends_at)));

/**
 * The time text, or null when there must be NO time element (rule 1).
 *
 * In the grid it is the bare start — the cell has no room for a range. In the agenda and
 * the popover it is a range when an end is known, and a start with an ellipsis when the
 * end is genuinely null (a run still going, a schedule occurrence that has no end at all).
 * The ellipsis is doing real work: it distinguishes "we do not know when this ends" from
 * "this is instantaneous".
 */
const timeText = computed<string | null>(() => {
  if (props.occurrence.all_day) {
    return isGrid.value ? null : t('calendar.occurrence.allDay');
  }
  if (!startTime.value) return null;
  if (isGrid.value) return startTime.value;
  return endTime.value
    ? t('calendar.occurrence.range', '', { from: startTime.value, to: endTime.value })
    : t('calendar.occurrence.from', '', { time: startTime.value });
});

/**
 * The series' CADENCE, or null when there is none to state.
 *
 * ABSENT AND `null` MUST BE ONE CASE, which `?.` + the empty-string guard make true: the
 * field is optional on the wire and empty far more often than not (the server labels only
 * interval schedules), so "the key was missing" and "the key was null" have to reach the
 * template as the same nothing. A blank string is nothing too — a marker reading "Series —"
 * would be worse than one reading nothing.
 *
 * NOT TRANSLATED HERE. It is finished server prose, like `badge.label` and the source names.
 */
const cadenceLabel = computed<string | null>(() => {
  const label = props.occurrence.cadence_label?.trim();
  return label ? label : null;
});

/**
 * What the series marker SAYS.
 *
 * The cadence when the server sent one, because "every 5 min" is the fact that makes a
 * folded row worth folding; the count only otherwise. "Showing 64" is a number a reader
 * gains nothing from — it describes this chip, not the thing the chip stands for — so it is
 * the fallback, never the preferred wording.
 *
 * In the GRID the marker stays a bare number regardless: an ~11rem cell has room for the
 * title or for a sentence, not both (the same width argument as rule 3). The cadence still
 * reaches a grid reader — through the accessible label below and the marker's own tooltip —
 * so it is hidden there, never lost.
 */
const seriesText = computed<string>(() =>
  cadenceLabel.value ?? t('calendar.dense.chip', '', { shown: props.shown }),
);

/** The trailing direction glyph — the affordance signal (spec D4). */
const trailingIcon = computed(() => (props.occurrence.editable ? 'pencil' : 'external-link'));
const trailingHint = computed(() =>
  props.occurrence.editable ? t('calendar.occurrence.editable') : t('calendar.occurrence.external'),
);

/**
 * One sentence a screen reader can act on. Assembled from the parts a sighted user reads
 * at a glance — source, title, when, badge, series, and which way this opens — because
 * separately they are five unrelated fragments.
 */
const ariaLabel = computed(() => {
  const parts = [props.occurrence.title];
  if (timeText.value) parts.push(timeText.value);
  else if (props.occurrence.all_day) parts.push(t('calendar.occurrence.allDay'));
  if (props.sourceLabel) parts.push(props.sourceLabel);
  if (props.occurrence.badge) parts.push(props.occurrence.badge.label);
  if (props.folded) {
    // WHAT THE MARKER SAYS, then WHY IT IS THERE — in that order, and in the grid too,
    // where the marker itself is only a number. Two reasons it is `seriesText` rather than
    // the cadence alone: an aria-label REPLACES the button's content, so a folded row would
    // otherwise lose the marker's words entirely for anyone who cannot see it; and the
    // accessible name has to contain the visible text, or a voice-control user saying what
    // they can read finds nothing to activate.
    parts.push(seriesText.value);
    parts.push(t('calendar.dense.aria'));
  }
  parts.push(trailingHint.value);
  return parts.join(' · ');
});
</script>

<template>
  <button
    type="button"
    class="next-occurrence-chip group relative flex w-full min-w-0 items-center overflow-hidden rounded-next-sm pl-next-2 text-left outline-none transition-colors duration-[var(--duration-next-fast)] focus-visible:ring-2 focus-visible:ring-next-ring focus-visible:ring-offset-1 hover:brightness-95 dark:hover:brightness-110"
    :class="[
      tokens.surface,
      isGrid ? 'gap-next-1 py-next-0_5 pr-next-1 text-next-2xs' : 'gap-next-2 py-next-1_5 pr-next-2 text-next-xs',
      past && isGrid ? 'opacity-70' : '',
    ]"
    :aria-label="ariaLabel"
    :title="occurrence.title"
    @click="emit('select', occurrence)"
  >
    <!-- The colour RAIL. Solid, so it reads at a glance; the surface behind it is the
         subtle pair of the same token. Decorative — the colour is never the only signal
         (there is always a glyph and a title beside it). -->
    <span class="absolute inset-y-0 left-0 w-1" :class="tokens.bar" aria-hidden="true" />

    <Icon :name="sourceIcon(occurrence.source)" class="shrink-0 opacity-80" :class="isGrid ? '' : 'text-next-sm'" />

    <!-- RULE 1: rendered only when there is genuinely a time to render. -->
    <span v-if="timeText" class="shrink-0 tabular-nums opacity-90">{{ timeText }}</span>

    <span class="min-w-0 flex-1 truncate font-next-medium">{{ occurrence.title }}</span>

    <!-- The SERIES marker. Text, not colour — density is a fact about quantity, and the
         chip's colour already means urgency/state. It states the server's CADENCE when
         there is one ("Every 5 min"), and falls back to how many this row stands in for
         when there is not. The grid keeps the bare number for width; its `title` carries
         the sentence the cell has no room for. -->
    <Badge
      v-if="folded"
      variant="neutral"
      tone="subtle"
      size="sm"
      icon="repeat"
      class="shrink-0"
      :title="isGrid ? seriesText : undefined"
    >
      {{ isGrid ? String(shown) : seriesText }}
    </Badge>

    <!-- RULE 3: the badge belongs to the roomy variants only. -->
    <Badge
      v-if="!isGrid && occurrence.badge"
      :variant="colorBadgeVariant(occurrence.badge.color)"
      tone="subtle"
      size="sm"
      class="shrink-0"
    >
      {{ occurrence.badge.label }}
    </Badge>

    <!-- Direction, always visible (never hover-only — that does not exist on touch). -->
    <Icon :name="trailingIcon" class="shrink-0 opacity-60" aria-hidden="true" />
  </button>
</template>
