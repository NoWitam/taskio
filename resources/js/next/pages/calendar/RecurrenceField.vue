<script setup lang="ts">
// RecurrenceField — "does this repeat, and until when?", in the vocabulary of somebody
// planning a meeting rather than somebody writing an automation.
//
// ─────────────────────────────────────────────────────────────────────────────────────────
// FOUR THINGS HERE ARE LOAD-BEARING
// ─────────────────────────────────────────────────────────────────────────────────────────
//
// 1. THE PRESET IS DERIVED FROM THE START DAY, AND RE-DERIVED WHEN THAT DAY MOVES.
//    The server requires the event's own start to be the rule's FIRST occurrence and refuses
//    otherwise — on the START field (`anchor_not_an_occurrence`), which is not the control the
//    user just touched. Re-deriving turns that refusal into something unreachable: pick "every
//    Tuesday", move the date to a Wednesday, and the select VISIBLY redraws as "every
//    Wednesday". Visibly, deliberately — a silent substitution would be worse than the refusal.
//
// 2. A RULE THIS CONTROL CANNOT PRODUCE IS NEVER REWRITTEN INTO THE NEAREST ONE IT CAN.
//    The API accepts a wider grammar (several weekdays, several months, …). Such a rule shows
//    as "Another rule" — selected, disabled — and is echoed back verbatim, so editing the
//    TITLE of such a series cannot quietly reshape its cadence. Everything else on the form
//    keeps working; only the cadence is frozen, and choosing any preset replaces it for good.
//
// 3. THE SENTENCE ABOUT A STORED RULE COMES FROM THE SERVER, ALWAYS.
//    The preset labels below describe an INTENT — a rule that does not exist yet, which the
//    server therefore has no opinion about. They are never used to describe a rule that is
//    already saved: that sentence is `recurrence_label` / `cadence_label`, finished and
//    translated, and this client composes no cadence prose of its own anywhere.
//
// 4. "AFTER N REPEATS" IS A WAY OF SAYING A DATE.
//    The server walks the count to a real day once, at write time, and only that day is ever
//    stored or returned. So the control says so UP FRONT instead of keeping a counter that
//    would drift from the row on the first edit made from anywhere else.
import { computed, watch } from 'vue';
import FormField from '../../ui/forms/FormField.vue';
import Select, { type SelectOption } from '../../ui/forms/Select.vue';
import RadioGroup from '../../ui/forms/RadioGroup.vue';
import Radio from '../../ui/forms/Radio.vue';
import DatePicker from '../../ui/forms/DatePicker.vue';
import NumberInput from '../../ui/forms/NumberInput.vue';
import { useI18n } from '../../app/i18n';
import {
  RECURRENCE_COUNT_MAX,
  presetLabel,
  presetsFor,
  remapPreset,
  type RecurrenceEndMode,
  type RecurrencePresetId,
  type RecurrenceSelection,
  type RecurrenceState,
} from './recurrencePresets';
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

// ── Presets ─────────────────────────────────────────────────────────────────
const presets = computed(() => presetsFor(props.anchorDay));

const options = computed<SelectOption[]>(() => {
  const list: SelectOption[] = [{ value: 'none', label: t('calendar.recurrence.none') }];
  for (const preset of presets.value) {
    list.push({ value: preset.id, label: presetLabel(preset, t, props.locale) });
  }
  // Present ONLY while it is the current value, and never selectable: this is the state a
  // rule from outside this vocabulary sits in, and there is no way back into it once a preset
  // has replaced the rule (which the note under the control says out loud).
  if (state.value.selection === 'other') {
    list.push({ value: 'other', label: t('calendar.recurrence.other'), disabled: true });
  }
  return list;
});

const selection = computed<string>({
  get: () => state.value.selection,
  set: (value) => {
    const next = (value || 'none') as RecurrenceSelection;
    state.value = {
      ...state.value,
      selection: next,
      // Leaving "does not repeat" behind clears an end nobody chose; entering it keeps the
      // carried values so flipping back does not lose them.
      endMode: next === 'none' ? 'never' : state.value.endMode,
    };
  },
});

/** Fact 1. Runs on every start-day change, including the one that seeds a create. */
watch(
  () => props.anchorDay,
  () => {
    const current = state.value.selection;
    if (current === 'none' || current === 'other') return;
    const remapped = remapPreset(current as RecurrencePresetId, props.anchorDay);
    // A day that can anchor nothing at all (an unparseable value mid-typing) leaves the
    // choice alone rather than silently dropping it.
    if (remapped && remapped.id !== current) {
      state.value = { ...state.value, selection: remapped.id };
    }
  },
);

const chosenPreset = computed(() =>
  presets.value.find((preset) => preset.id === state.value.selection) ?? null,
);

const repeats = computed(() => state.value.selection !== 'none');

/**
 * Everything the reader has to know about the rule they picked, as ONE field description —
 * which is what wires it to the control through `aria-describedby`. Deliberately not a
 * `title=`, and deliberately not a loose paragraph beside the field: these are conditions of
 * the rule, and somebody who cannot see the layout has to get them WITH the control.
 *
 * Three sentences can appear, none of them ever together with the first:
 *
 *   • THE RULE IS NOT ONE THIS CONTROL SPEAKS. Then the sentence about it is the SERVER's
 *     (`recurrence_label`) — a wider rule usually has one ("Weekly on Mon, Wed"); the control
 *     simply cannot PRODUCE it. Only when the server has none too does this say that much,
 *     which is a statement about the FORM, not about the cadence. Plus the consequence:
 *     picking any preset replaces the rule for good.
 *   • THE RULE SKIPS SHORT MONTHS. True, and the engine simply does not fire — but without
 *     saying so the missing squares read as lost occurrences.
 *   • THE SERIES HAS ONE HOUR, AND IT IS SET ABOVE. The server authors the series' hour from
 *     the event's own and REFUSES a `recurrence.time`, so there will never be an hour field
 *     here; without this, a user hunts for one.
 */
const description = computed<string | undefined>(() => {
  if (state.value.selection === 'other') {
    const sentence = props.storedLabel?.trim() || t('calendar.series.unknownRule');
    return `${sentence} ${t('calendar.recurrence.otherReplaces')}`;
  }
  const notes: string[] = [];
  if (chosenPreset.value?.skipsShortMonths) {
    notes.push(t('calendar.recurrence.shortMonthsNote', '', { day: chosenPreset.value.dayOfMonth ?? 0 }));
  }
  if (repeats.value && props.hour) {
    notes.push(t('calendar.recurrence.hourNote', '', { time: props.hour.time, tz: props.hour.tz }));
  }
  return notes.length > 0 ? notes.join(' ') : undefined;
});

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

// ── Server messages ─────────────────────────────────────────────────────────
// The map from 422 paths to controls (UX spec §24.2.4). Anything about the CADENCE belongs
// under the select; anything about the END belongs under the end control — including
// `exclusions.dates`, because when a series has been emptied out the remedy is to relax its
// end, and the message says so.
const ruleError = computed<string | undefined>(() => {
  const errors = props.errors;
  if (errors.recurrence) return errors.recurrence;
  const key = Object.keys(errors).find(
    (path) => path.startsWith('recurrence.day') || path.startsWith('recurrence.month'),
  );
  return key ? errors[key] : undefined;
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
    <FormField :label="t('calendar.recurrence.label')" :error="ruleError" :description="description">
      <Select
        v-model="selection"
        :options="options"
        :readonly="readonly"
        leading-icon="repeat"
        :aria-label="t('calendar.recurrence.label')"
      />
    </FormField>

    <!-- ONE FIELD for the whole end of the series — the mode and its value together.
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
             write time and never comes back (fact 4), which the field's description says
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
