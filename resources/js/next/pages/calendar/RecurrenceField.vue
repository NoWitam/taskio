<script setup lang="ts">
// RecurrenceField — "does this repeat, how, and until when?", for somebody planning a meeting.
//
// THE CADENCE ITSELF IS NO LONGER AUTHORED HERE. It is the SAME editor the Workflows schedule
// trigger uses (`ui/recurrence/RecurrenceAxisEditor`), mounted on the Calendar PROFILE — the
// subset `StoreCalendarEventRequest` accepts. What this file still owns is everything that
// profile has no opinion about, and each of those is a fact about the Calendar's contract
// rather than a layout choice:
//
// 1. WHETHER IT REPEATS AT ALL. A schedule trigger always repeats, so the axis grammar cannot
//    say "once": `every_day` + `every_month` is "every day forever". An absent `recurrence`
//    key is the only way to say once, so the switch below decides between sending the key and
//    omitting it — never between two shapes of it.
//
// 2. WHERE THE SERIES ENDS. Workflows has no notion of an end; the whole never/until/count
//    control is the Calendar's, composed AROUND the shared editor rather than pushed into it.
//
// 3. THE ANCHOR RULE. The server requires the event's own start to be the rule's FIRST
//    occurrence and refuses otherwise — on the START field (`anchor_not_an_occurrence`), which
//    is not the control the user just touched. The old preset control made that unreachable by
//    never OFFERING a rule the start day fails; a full editor cannot, so the guarantee is
//    rebuilt from two halves. Every sub-mode SEEDS from the start day, so the ordinary path
//    never produces a mismatch. And when a deliberate edit does produce one — "every Monday
//    and Wednesday" on a Tuesday — it is said HERE, under the rule, and it blocks the save,
//    instead of arriving later on a field nobody connects with "repeat weekly".
//
// 4. THE SENTENCE ABOUT A STORED RULE COMES FROM THE SERVER, ALWAYS. This client composes no
//    cadence prose anywhere: that sentence is `recurrence_label` / `cadence_label`, finished
//    and translated. The editor's card titles describe a CHOICE, which is a different thing —
//    the server has no opinion about a rule that does not exist yet.
//
// 5. "AFTER N REPEATS" IS A WAY OF SAYING A DATE. The server walks the count to a real day
//    once, at write time, and only that day is ever stored or returned. So the control says so
//    UP FRONT instead of keeping a counter that would drift on the first edit made elsewhere.
import { computed, watch } from 'vue';
import FormField from '../../ui/forms/FormField.vue';
import Switch from '../../ui/forms/Switch.vue';
import RadioGroup from '../../ui/forms/RadioGroup.vue';
import Radio from '../../ui/forms/Radio.vue';
import DatePicker from '../../ui/forms/DatePicker.vue';
import NumberInput from '../../ui/forms/NumberInput.vue';
import Alert from '../../ui/feedback/Alert.vue';
import Button from '../../ui/primitives/Button.vue';
import RecurrenceAxisEditor from '../../ui/recurrence/RecurrenceAxisEditor.vue';
import {
  CALENDAR_RECURRENCE_PROFILE,
  daySubmodeOf,
  monthSubmodeOf,
  seedDay,
  seedMonth,
  type DayAxis,
  type MonthAxis,
} from '../../ui/recurrence/recurrenceAxes';
import { useI18n } from '../../app/i18n';
import {
  RECURRENCE_COUNT_MAX,
  recurrenceAnchorSatisfied,
  type RecurrenceEndMode,
  type RecurrenceState,
} from './calendarRecurrence';
import type { IsoDay } from './types';

const props = withDefaults(
  defineProps<{
    /** The start day the FORM currently holds — the rule's anchor, in the workspace zone. */
    anchorDay: IsoDay | null;
    locale: string;
    readonly?: boolean;
    /** Server 422 messages by path (`recurrence`, `recurrence.until`, …), already translated. */
    errors?: Record<string, string>;
    /** For a timed event: the hour every occurrence starts at, and whose clock it is on. */
    hour?: { time: string; tz: string } | null;
    /** The SERVER's sentence about the stored rule, when there is one. Never composed here. */
    storedLabel?: string | null;
  }>(),
  { readonly: false, errors: () => ({}), hour: null, storedLabel: null },
);

const state = defineModel<RecurrenceState>({ required: true });

const { t } = useI18n();

// ── Does it repeat? ─────────────────────────────────────────────────────────
const repeats = computed<boolean>({
  get: () => state.value.repeats,
  set: (value) => {
    state.value = {
      ...state.value,
      repeats: value,
      // Leaving "does not repeat" behind clears an end nobody chose; entering it keeps the
      // carried values so flipping back does not lose them.
      endMode: value ? state.value.endMode : 'never',
    };
  },
});

// ── The cadence, through the shared editor ──────────────────────────────────
const day = computed<DayAxis>({
  get: () => state.value.day,
  set: (value) => {
    state.value = { ...state.value, day: value };
  },
});
const month = computed<MonthAxis>({
  get: () => state.value.month,
  set: (value) => {
    state.value = { ...state.value, month: value };
  },
});

/**
 * THE START DAY MOVED — RE-DERIVE ONLY WHAT THIS CONTROL DERIVED.
 *
 * Pick "every Tuesday", move the date to a Wednesday, and the rule VISIBLY redraws as "every
 * Wednesday", exactly as the old preset control did. What is deliberately NOT re-derived is
 * anything the user composed themselves: a rule that says Monday AND Wednesday is left alone
 * and the mismatch is reported, because silently dropping a weekday somebody chose is worse
 * than refusing — the whole reason a rule outside the old vocabulary was frozen rather than
 * rewritten.
 *
 * The test for "this control derived it" is exact: the axis equals what the editor itself
 * would have seeded for the PREVIOUS anchor.
 */
watch(
  () => props.anchorDay,
  (next, previous) => {
    if (!state.value.repeats || state.value.unsupported) return;
    const patch: Partial<RecurrenceState> = {};

    const daySeed = seedDay(daySubmodeOf(state.value.day), next);
    if (same(state.value.day, seedDay(daySubmodeOf(state.value.day), previous ?? null)) && !same(state.value.day, daySeed)) {
      patch.day = daySeed;
    }
    const monthSeed = seedMonth(monthSubmodeOf(state.value.month), next);
    if (same(state.value.month, seedMonth(monthSubmodeOf(state.value.month), previous ?? null)) && !same(state.value.month, monthSeed)) {
      patch.month = monthSeed;
    }

    // Only when something actually MOVES: `every_day` re-seeds to itself on every keystroke in
    // the date field, and rewriting the state with an equal value would churn the model for no
    // reason — and make an untouched cadence look edited to anything watching identity.
    if (Object.keys(patch).length > 0) state.value = { ...state.value, ...patch };
  },
);

/** Structural equality for an axis value — small, closed objects of scalars and number lists. */
function same(a: unknown, b: unknown): boolean {
  return JSON.stringify(a) === JSON.stringify(b);
}

/** Drop a rule this editor cannot render and start composing a fresh one (never automatic). */
function replaceUnsupported(): void {
  state.value = {
    ...state.value,
    unsupported: null,
    day: { mode: 'every_day' },
    month: { mode: 'every_month' },
  };
}

// ── Notes under the rule ────────────────────────────────────────────────────
/**
 * The days-of-month a rule names that some months simply do not have. The engine does not
 * fire on them — true, and harmless — but without saying so the missing squares read as lost
 * occurrences.
 */
const shortMonthDays = computed<number[]>(() =>
  state.value.day.mode === 'month_days' ? state.value.day.days.filter((d) => d >= 29) : [],
);

/**
 * Everything the reader has to know about the rule they composed, as ONE field description —
 * which is what wires it to the control through `aria-describedby`. Deliberately not a
 * `title=` and not a loose paragraph beside the field: these are conditions of the rule, and
 * somebody who cannot see the layout has to get them WITH the control.
 */
const ruleDescription = computed<string | undefined>(() => {
  const notes: string[] = [];
  if (shortMonthDays.value.length > 0) {
    notes.push(t('calendar.recurrence.shortMonthsNote', '', { day: shortMonthDays.value.join(', ') }));
  }
  // The server authors the series' hour from the event's own and REFUSES a `recurrence.time`,
  // so there will never be an hour field here; without this, a user hunts for one.
  if (props.hour) notes.push(t('calendar.recurrence.hourNote', '', { time: props.hour.time, tz: props.hour.tz }));
  return notes.length > 0 ? notes.join(' ') : undefined;
});

/** The server's sentence about a rule this editor is only echoing, or an honest admission. */
const unsupportedSentence = computed(() => props.storedLabel?.trim() || t('calendar.series.unknownRule'));

// ── End of the series ───────────────────────────────────────────────────────
const endMode = computed<string>({
  get: () => state.value.endMode,
  set: (value) => {
    state.value = { ...state.value, endMode: (value || 'never') as RecurrenceEndMode };
  },
});

const until = computed<string | null>({
  get: () => state.value.until,
  set: (value) => {
    state.value = { ...state.value, until: value || null };
  },
});

const count = computed<number | null>({
  get: () => state.value.count,
  set: (value) => {
    state.value = { ...state.value, count: value == null ? null : Number(value) };
  },
});

// ── Messages ────────────────────────────────────────────────────────────────
/**
 * The client reading of `anchor_not_an_occurrence`, said where the cause is.
 *
 * The server reports it on `start_date`/`starts_at`, and it still would — this only makes
 * sure the user never gets that far: the drawer's own save gate asks the same question
 * (`recurrenceAnchorSatisfied`), so the refusal is a message under the rule rather than a
 * failed request pointing at the date field.
 */
const anchorError = computed<string | undefined>(() =>
  recurrenceAnchorSatisfied(state.value, props.anchorDay)
    ? undefined
    : t('calendar.recurrence.anchorMismatch'),
);

/**
 * The map from 422 paths to controls (UX spec §24.2.4). Anything about the CADENCE belongs
 * under the editor; anything about the END belongs under the end control — including
 * `exclusions.dates`, because when a series has been emptied out the remedy is to relax its
 * end, and the message says so.
 */
const serverRuleError = computed<string | undefined>(() => {
  const errors = props.errors;
  if (errors.recurrence) return errors.recurrence;
  const key = Object.keys(errors).find(
    (path) =>
      // Anything about an axis, EXCEPT the paths the editor renders on the offending control
      // itself — those would otherwise say the same sentence twice, once beside the chips and
      // once under the whole field.
      !ROUTED_TO_A_CONTROL.has(path) &&
      (path.startsWith('recurrence.day') || path.startsWith('recurrence.month')),
  );
  return key ? errors[key] : undefined;
});

/** The 422 paths the shared editor puts under a specific control (see `axisErrors`). */
const ROUTED_TO_A_CONTROL = new Set([
  'recurrence.day.weekdays',
  'recurrence.day.days',
  'recurrence.day.ordinal',
  'recurrence.day.weekday',
  'recurrence.month.months',
]);

/** The client rule first — it is the one the user can act on without a round trip. */
const ruleError = computed<string | undefined>(() => anchorError.value ?? serverRuleError.value);

/**
 * The per-axis messages the shared editor routes to its own panels, keyed AXIS-RELATIVE.
 * The Calendar's block is called `recurrence`, so that prefix is stripped here — the editor
 * is shared with a module whose block is called something else entirely.
 */
const axisErrors = computed<Record<string, string | undefined>>(() => {
  const e = props.errors;
  return {
    'day.weekdays': e['recurrence.day.weekdays'],
    'day.days': e['recurrence.day.days'],
    'day.special.ordinal': e['recurrence.day.ordinal'],
    'day.special.weekday': e['recurrence.day.weekday'],
    'month.months': e['recurrence.month.months'],
  };
});

/**
 * Every refusal about the END of the series, in one place.
 *
 * `recurrence.exclusions.dates` belongs here rather than nowhere: it carries
 * `series_has_no_occurrences` for a series with NO end date, and this form has no exclusions
 * control — the remedy the message names is relaxing the end, which is exactly what these
 * controls do.
 */
const endError = computed<string | undefined>(
  () =>
    props.errors['recurrence.until'] ??
    props.errors['recurrence.count'] ??
    props.errors['recurrence.exclusions.dates'],
);
</script>

<template>
  <div class="flex flex-col gap-next-3">
    <!-- 1. Does it repeat? A switch, because the answer is not a cadence: "no" is the ABSENCE
         of the whole block, and no rule in the grammar below can say it. -->
    <FormField :label="t('calendar.recurrence.label')" :error="ruleError" :description="ruleDescription">
      <div class="flex flex-col gap-next-3">
        <!-- The switch carries the FIELD's name, not the state — a switch's accessible name
             has to be stable, and `aria-checked` is what says which way it is set. The state
             is still readable in words below, for the off case where nothing else shows it. -->
        <div class="flex items-center gap-next-2">
          <Switch
            v-model="repeats"
            :disabled="readonly"
            :aria-invalid="!!ruleError"
            :aria-label="t('calendar.recurrence.label')"
          />
          <span v-if="!repeats" class="text-next-sm text-next-muted-foreground">
            {{ t('calendar.recurrence.none') }}
          </span>
        </div>

        <template v-if="repeats">
          <!-- A rule from outside this editor's vocabulary: shown as the SERVER's sentence,
               frozen, and echoed back untouched on save. Replacing it is a deliberate act
               with its own button — never a side effect of editing the title. -->
          <div v-if="state.unsupported" class="flex flex-col items-start gap-next-2">
            <Alert variant="info" size="sm">
              {{ unsupportedSentence }} {{ t('calendar.recurrence.unsupportedNote') }}
            </Alert>
            <Button variant="secondary" size="sm" :disabled="readonly" @click="replaceUnsupported">
              {{ t('calendar.recurrence.unsupportedReplace') }}
            </Button>
          </div>

          <!-- 2. THE SHARED EDITOR. `fieldset[disabled]` is what makes the whole tree
               read-only while a save is in flight: the HTML rule disables every descendant
               control — the option-card radios included — with no prop to thread through
               four components and no chance of one of them being missed. -->
          <fieldset v-else :disabled="readonly" class="min-w-0">
            <RecurrenceAxisEditor
              v-model:day="day"
              v-model:month="month"
              :profile="CALENDAR_RECURRENCE_PROFILE"
              :errors="axisErrors"
              :anchor-day="anchorDay"
              :aria-label="t('recurrenceEditor.tabsAria')"
            />
          </fieldset>
        </template>
      </div>
    </FormField>

    <!-- 3. ONE FIELD for the whole end of the series — the mode and its value together.
         Not two, and that is not tidiness: `series_has_no_occurrences` arrives on
         `recurrence.exclusions.dates` when the series has NO end date, and the form has no
         exclusions control at all. Split into two fields, that message would have had nowhere
         to render on the very shape it describes. One field always exists while the series
         repeats, so every end-related refusal lands beside the controls that can relax it. -->
    <FormField
      v-if="repeats"
      :label="t('calendar.recurrence.end.label')"
      :error="endError"
      :description="endMode === 'count' ? t('calendar.recurrence.end.countNote') : undefined"
    >
      <div class="flex flex-col gap-next-3">
        <!-- Vertical below `next-md`, where the drawer goes full width and three options in a
             row stop fitting; horizontal from there up. Base + breakpoint rather than the
             `orientation` prop alone, because the prop cannot be responsive. -->
        <RadioGroup
          v-model="endMode"
          orientation="vertical"
          class="next-md:flex-row next-md:flex-wrap next-md:items-center next-md:gap-next-4"
          :aria-label="t('calendar.recurrence.end.label')"
        >
          <Radio value="never" :label="t('calendar.recurrence.end.never')" />
          <Radio value="until" :label="t('calendar.recurrence.end.until')" />
          <Radio value="count" :label="t('calendar.recurrence.end.count')" />
        </RadioGroup>

        <DatePicker
          v-if="endMode === 'until'"
          v-model="until"
          :min="anchorDay"
          :readonly="readonly"
          :locale="locale"
          :aria-label="t('calendar.recurrence.end.until')"
        />

        <!-- The ceiling is `RECURRENCE_COUNT_MAX`, whose comment says where the real cap lives
             (`calendar.recurrence_count_max`, env-driven) and why this side can only mirror it.
             Not a literal here: one number in one place, so a drift is a one-line fix rather
             than a hunt. No counter is kept anywhere either: this number becomes a DATE at
             write time and never comes back (fact 5), which the field's description says
             before anyone is surprised. -->
        <NumberInput
          v-else-if="endMode === 'count'"
          v-model="count"
          :min="1"
          :max="RECURRENCE_COUNT_MAX"
          :readonly="readonly"
          :aria-label="t('calendar.recurrence.end.count')"
        />
      </div>
    </FormField>
  </div>
</template>
