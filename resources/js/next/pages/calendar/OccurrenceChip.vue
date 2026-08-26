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
// 3. THE BADGE IS NOT SHOWN IN THE GRID (spec D5) — BUT ITS COLOUR IS, WHERE IT SAYS
//    SOMETHING NEW. In a cell that narrow the badge's prose eats the title, and the title
//    is what people scan by; the words stay one click (the day popover) or one toggle (the
//    agenda) away. What may NOT stay there is the fact that the badge is a SECOND AXIS: for
//    `task` the chip's colour is priority and the badge is status, so hiding the badge
//    outright makes "urgent, done" and "urgent, not done" the same red square — and since
//    past days dim, an overdue urgent deadline then draws fainter than a future low one.
//    So the grid draws a bare DOT in the badge's colour, and only when that colour DIFFERS
//    from the chip's: exactly where the two axes disagree, and nowhere else. The three
//    sources whose badge merely restates their colour are unaffected, and the rule above
//    keeps its meaning for them.
//
// 4. TWO MARKERS, TWO FACTS, NEVER ONE GLYPH FOR BOTH.
//
//      SERIES (`repeat`)  — a fact about the SUBJECT: this square is one of many. Permanent.
//                           Read from `recurring`, and NEVER from `cadence_label !== null`:
//                           the prose is sufficient evidence of a series but not necessary,
//                           and a schedule in fixed-times mode repeats while carrying `null`.
//                           Branching on the prose calls every one of those squares a one-off,
//                           silently.
//      SAMPLE (`layers`)  — a fact about the ANSWER: the rest of this item is not in this
//                           window. Incidental. Read from `dense` (folded).
//
//    `repeat` used to carry both, which taught a reader to ignore it: it appeared on things
//    that were fine and on things that were missing data, identically. In the GRID, where an
//    ~11rem cell has room for one, the SAMPLE wins — "you are not seeing everything" is more
//    urgent than "this recurs", and for an `event` a sample implies a series anyway. Nothing
//    is lost: both facts are in the accessible label either way.
//
// Editability is signalled BEFORE the click and by DIRECTION, not by presence: a chevron
// when this opens HERE, an external-link when it opens somewhere else. Two glyphs, both
// naming a DIRECTION rather than a verb — a pencil stood here once and promised the wrong
// thing, because the click opens a READ surface and the pencil that really edits is two
// clicks further in. `editable` is only an affordance HINT — the truth about permissions is
// the event's own `can_be_edited` / `can_be_deleted`.
import { computed } from 'vue';
import Icon from '../../ui/primitives/Icon.vue';
import Badge from '../../ui/primitives/Badge.vue';
import { useI18n } from '../../app/i18n';
import { colorBadgeVariant, colorTokens, normalizeColor, sourceIcon } from './calendarMeta';
import { instantToZonedParts } from './calendarZone';
import type { CalendarOccurrence, OccurrenceVariant } from './types';

/**
 * ONE HAIRLINE, EVERY PLACE A SUBTLE SURFACE SITS ON ANOTHER SUBTLE SURFACE.
 *
 * The chip's own surface is `bg-next-{color}-subtle`, and so are several of the things it
 * is drawn on top of and several of the things drawn on top of IT. Two of those pairings
 * are not "close" — they are the SAME TOKEN, in both themes:
 *
 *   • a `primary` chip on the "today" cell (`bg-next-primary-subtle` both), which is every
 *     event this module owns, on the square people look at every day; and
 *   • a `neutral` badge on a `neutral` chip (`bg-next-muted` both).
 *
 * `warning`-on-`primary` (the sample marker, spec §24.11) is the near-miss of the same
 * family: identical lightness in dark, separated by hue alone.
 *
 * `next-input` is the app's border for an INTERACTIVE control, which is what a chip is, and
 * it is the one that survives being drawn on a tinted surface. Measured against the tokens
 * as they stand, with the grid's own `border-next-border`-on-`card` hairline as the bar the
 * app already accepts (1.30:1 light / 1.76:1 dark):
 *
 *              light        dark          (edge vs the chip fill it encloses)
 *   primary    1.46         2.28
 *   neutral    1.42         2.17
 *   warning    1.50         1.87          ← the weakest pairing, still above the bar
 *   success    1.51         1.96
 *   danger     1.44         2.21
 *   info       1.47         2.09
 *
 * `next-border` was the first choice and is NOT enough here: on these tinted fills it lands
 * at 1.16–1.23 light / 1.25–1.53 dark, i.e. below the bar in every case and barely a line at
 * all on a `warning` chip in dark.
 *
 * IT IS A BARE TOKEN, WITH NO OPACITY MODIFIER, AND THAT IS THE LOAD-BEARING PART.
 * Two tempting spellings are both LIGHT-MODE-ONLY FIXES for a defect that exists in both
 * themes, and the build is where you find that out rather than the browser:
 *
 *   • a faded theme token (`ring-next-fg/15`) — Tailwind v4 resolves a themed colour plus
 *     an opacity modifier at BUILD time and emits a flattened hex. The dark overrides in
 *     `next.css` are ordinary CSS under `.next-root.dark`, not a second `@theme`, so the
 *     dark value never reaches the baked rule: the hairline stays near-black and vanishes
 *     into a dark chip on a dark cell, which is precisely the case this exists for.
 *   • a faded `currentColor` (`ring-current/20`) — v4 compiles that to a bare
 *     `--tw-ring-color:currentcolor`, DROPPING the modifier, which draws the chip's full
 *     text colour as a 1px outline.
 *
 * A bare token compiles to `--tw-ring-color:var(--color-next-input)`, which is resolved by
 * the browser and therefore follows the theme swap like everything else here.
 *
 * `ring-inset` rather than a border, so the chip's box does not grow and the colour rail
 * keeps its exact 1-unit width. It also makes the FOCUS ring inset (the variable is set on
 * the base), which is why `ring-offset-1` is gone: an offset drawn INSIDE the chip is a
 * band of `--tw-ring-offset-color` — invisible in light, a white line in dark.
 */
const SUBTLE_EDGE = 'ring-1 ring-inset ring-next-input';

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
/** The full-width surface. It alone owns the 44px touch target (spec §17). */
const isAgenda = computed(() => props.variant === 'agenda');

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
 * Whether this square belongs to a SERIES. The contract's own flag, never an inference from
 * the prose being present (rule 4). Absent on the wire is impossible, but a stale cached
 * payload is not, so a missing key reads as `false` rather than as a crash.
 */
const isSeries = computed<boolean>(() => props.occurrence.recurring === true);

/**
 * What the SERIES marker says, in the roomy variants: the server's cadence sentence, or
 * NOTHING AT ALL.
 *
 * There is no third option, and that is rule 2 at its limit. A repeating subject is allowed
 * to have no sentence (a fixed-times schedule has none), and composing one here from
 * `recurrence.day.*` would be the client growing a vocabulary per source — which is exactly
 * what makes "a new source needs no frontend change" stop being true. So the marker renders
 * as a bare glyph, and what keeps it from being a glyph with no accessible name is the chip's
 * own label below, which says what the marker MEANS ("occurrence of a series") without saying
 * anything about how often.
 */
const seriesText = computed<string | null>(() => cadenceLabel.value);

/**
 * What the SAMPLE marker says. The count only when it stands for more than one: "sample: 1"
 * is a sentence about nothing, and a folded event series is always exactly one row per day
 * (a calendar series has one hour by construction), so that is the common case rather than
 * the exception.
 */
const sampleText = computed<string>(() =>
  props.shown > 1 ? t('calendar.dense.chip', '', { shown: props.shown }) : t('calendar.sample.marker'),
);

/**
 * The trailing DIRECTION glyph — the affordance signal (spec D4).
 *
 * Both glyphs name a direction, and neither names an action. A `pencil` stood in the
 * `editable` slot once and read as "edit", which is not what the click does: it opens a
 * READ surface, and the pencil that genuinely edits lives inside it, two clicks away. One
 * glyph meaning two different things on one screen is how a reader learns to distrust it.
 */
const trailingIcon = computed(() => (props.occurrence.editable ? 'chevron-right' : 'external-link'));
const trailingHint = computed(() =>
  props.occurrence.editable ? t('calendar.occurrence.editable') : t('calendar.occurrence.external'),
);

// ── Which markers this chip draws ───────────────────────────────────────────
// Named rather than inlined, because the roomy layout needs to know whether the marker row
// exists AT ALL: below `next-sm` it is a second LINE, and an empty second line is a gap
// under every chip that has no markers.

/**
 * The SERIES marker as a badge: only where there is width AND server prose to put in it.
 * Without prose the badge would render as an empty pill with the glyph pushed off its own
 * centre by the label's gap — so the bare glyph below takes over instead, in every variant.
 */
const showSeriesBadge = computed(() => isSeries.value && !isGrid.value && seriesText.value !== null);
/** The SERIES marker as a bare glyph: no prose to show, or no width to show it in. */
const showSeriesGlyph = computed(
  () =>
    isSeries.value &&
    (isGrid.value ? !props.folded : seriesText.value === null),
);
/** The STATUS badge — the roomy variants only (rule 3). */
const showStatusBadge = computed(() => !isGrid.value && !!props.occurrence.badge);

/**
 * THE STATUS DOT — the grid's answer to two independent axes drawn in one colour.
 *
 * For the `task` source the chip's colour carries PRIORITY and the badge carries STATUS,
 * and rule 3 hides the badge in a cell. That makes "urgent, done" and "urgent, not done"
 * the same red square — and because past days are dimmed, an overdue urgent deadline draws
 * FAINTER than a future low-priority one. The dimming is not the defect: the missing axis
 * is.
 *
 * So the badge comes back as a dot, and ONLY where the two axes actually disagree. When
 * `badge.color === color` the dot would restate the surface it sits on, which is noise on
 * the one surface that has none to spare. Normalised on both sides, so an unknown colour
 * landing on `neutral` compares as `neutral` rather than as a difference.
 *
 * The label is not lost either way: the chip's own accessible name already carries
 * `badge.label` in every variant, which is why the dot itself is `aria-hidden`.
 */
const statusDot = computed<string | null>(() => {
  const badge = props.occurrence.badge;
  if (!isGrid.value || !badge) return null;
  const badgeColor = normalizeColor(badge.color);
  if (badgeColor === normalizeColor(props.occurrence.color)) return null;
  return colorTokens(badgeColor).dot;
});

/** Whether the marker group renders at all — see the note above this block. */
const hasMarkers = computed(
  () =>
    showSeriesBadge.value ||
    showSeriesGlyph.value ||
    props.folded ||
    showStatusBadge.value ||
    statusDot.value !== null,
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
  if (isSeries.value) {
    // THE SERVER'S SENTENCE WHEN THERE IS ONE, otherwise the marker's own meaning. The
    // fallback is a name for the GLYPH ("occurrence of a series"), not a sentence about a
    // cadence — a glyph with no accessible name at all would be worse than either, and
    // composing "repeats weekly" here would be the client inventing prose.
    parts.push(seriesText.value ?? t('calendar.series.marker'));
  }
  if (props.folded) {
    // WHAT THE MARKER SAYS, then WHY IT IS THERE — in that order, and in the grid too, where
    // the marker itself is only a number. An aria-label REPLACES the button's content, so a
    // folded row would otherwise lose the marker's words entirely for anyone who cannot see
    // it; and the accessible name has to contain the visible text, or a voice-control user
    // saying what they can read finds nothing to activate.
    parts.push(sampleText.value);
    parts.push(t('calendar.dense.aria'));
  }
  parts.push(trailingHint.value);
  return parts.join(' · ');
});
</script>

<template>
  <button
    type="button"
    class="next-occurrence-chip group relative flex w-full min-w-0 items-center overflow-hidden rounded-next-sm pl-next-2 text-left outline-none transition-colors duration-[var(--duration-next-fast)] focus-visible:ring-2 focus-visible:ring-next-ring hover:brightness-95 dark:hover:brightness-110"
    :class="[
      tokens.surface,
      SUBTLE_EDGE,
      isGrid
        ? 'gap-next-1 py-next-0_5 pr-next-1 text-next-2xs'
        : 'gap-next-2 py-next-1_5 pr-next-2 text-next-xs',
      // §17: a full-width row is a touch target before it is a line of text. The row stays
      // `items-center`, so a chip with nothing on its second line does not hang its single
      // line from the top of 44px of air; the direction glyph opts out on its own (below).
      isAgenda ? 'min-h-[2.75rem]' : '',
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

    <!--
      THE BODY, in one structure that is a ROW in the grid and a ROW-THEN-COLUMN elsewhere.

      Below `next-sm` the agenda is the ONLY surface this module has (the grid is not a
      thing a phone can show), and on a single line the markers — a cadence badge carrying
      prose as long as "Every year, on the last day of August" — take their width before
      the title takes its first pixel. So the identity line and the marker line separate,
      and the direction glyph stays pinned to the identity line where the tap actually
      lands.
    -->
    <span
      data-part="body"
      class="flex min-w-0 flex-1"
      :class="
        isGrid
          ? 'items-center gap-next-1'
          : 'flex-col gap-next-1 next-sm:flex-row next-sm:items-center next-sm:gap-next-2'
      "
    >
      <!-- LINE 1 — who and when. -->
      <span
        class="flex min-w-0 items-center"
        :class="isGrid ? 'flex-1 gap-next-1' : 'gap-next-2 next-sm:flex-1'"
      >
        <Icon
          :name="sourceIcon(occurrence.source)"
          class="shrink-0 opacity-80"
          :class="isGrid ? '' : 'text-next-sm'"
        />

        <!-- RULE 1: rendered only when there is genuinely a time to render. -->
        <span v-if="timeText" class="shrink-0 tabular-nums opacity-90">{{ timeText }}</span>

        <span class="min-w-0 flex-1 truncate font-next-medium">{{ occurrence.title }}</span>
      </span>

      <!-- LINE 2 (or the tail of line 1, from `next-sm` up) — what state this is in. -->
      <span
        v-if="hasMarkers"
        data-part="markers"
        class="flex min-w-0 items-center"
        :class="isGrid ? 'shrink-0 gap-next-1' : 'flex-wrap gap-next-1_5'"
      >
        <!-- THE SERIES MARKER — a fact about the subject (rule 4). A badge only when the
             SERVER sent a cadence sentence to put in it; the client never fills that
             silence with a sentence of its own, and an empty badge is a pill with a glyph
             knocked off its own centre. `truncate` because that sentence is unbounded
             prose and the title is what people scan by — the whole of it stays in the
             tooltip and in the chip's accessible name. Both markers are aria-hidden:
             their meaning is already in that name, and a second reading would make every
             square longer to hear. -->
        <Badge
          v-if="showSeriesBadge"
          variant="neutral"
          tone="subtle"
          size="sm"
          icon="repeat"
          truncate
          :class="['shrink-0', SUBTLE_EDGE]"
          data-marker="series"
          aria-hidden="true"
          :title="seriesText ?? t('calendar.series.marker')"
        >
          {{ seriesText }}
        </Badge>
        <!-- `title` sits on a wrapper rather than on the <svg>: a `title` ATTRIBUTE on an
             SVG element is not a tooltip (SVG wants a `<title>` CHILD), so the span is
             what actually makes the sentence reachable with a mouse. `opacity` lifts
             slightly in dark, where a muted glyph on a subtle surface goes too soft to
             find. -->
        <span
          v-else-if="showSeriesGlyph"
          class="inline-flex shrink-0 opacity-60 dark:opacity-70"
          data-marker="series"
          aria-hidden="true"
          :title="seriesText ?? t('calendar.series.marker')"
        >
          <Icon name="repeat" /></span>

        <!-- THE SAMPLE MARKER — a fact about the answer. `warning` is the tone of the
             `item_densified` notice at the top of the screen, and the pairing is what ties
             the two together without one extra word. The count appears only when this row
             stands in for more than one; "sample: 1" would be a sentence about nothing. -->
        <Badge
          v-if="folded"
          variant="warning"
          tone="subtle"
          size="sm"
          icon="layers"
          :class="['shrink-0', SUBTLE_EDGE]"
          data-marker="sample"
          aria-hidden="true"
          :title="sampleText"
        >
          {{ shown > 1 ? String(shown) : '' }}
        </Badge>

        <!-- RULE 3: the badge belongs to the roomy variants only… -->
        <Badge
          v-if="showStatusBadge && occurrence.badge"
          :variant="colorBadgeVariant(occurrence.badge.color)"
          tone="subtle"
          size="sm"
          :class="['shrink-0', SUBTLE_EDGE]"
        >
          {{ occurrence.badge.label }}
        </Badge>

        <!-- …and in the grid it comes back as a DOT, but only where the state axis and the
             colour axis actually disagree. Solid token on a subtle surface, so it reads
             without competing with the title. -->
        <span
          v-else-if="statusDot"
          class="h-1.5 w-1.5 shrink-0 rounded-next-full"
          :class="statusDot"
          data-marker="status"
          aria-hidden="true"
          :title="occurrence.badge?.label"
        />
      </span>
    </span>

    <!-- Direction, always visible (never hover-only — that does not exist on touch), and
         always on the IDENTITY line: it describes what the tap does, not what state the
         subject is in. It leaves the row's vertical centring only when there genuinely is
         a second line to be above — `hasMarkers` decides that, because CSS cannot. -->
    <Icon
      :name="trailingIcon"
      class="shrink-0 opacity-60"
      :class="!isGrid && hasMarkers ? 'mt-px self-start next-sm:mt-0 next-sm:self-center' : ''"
      aria-hidden="true"
    />
  </button>
</template>
