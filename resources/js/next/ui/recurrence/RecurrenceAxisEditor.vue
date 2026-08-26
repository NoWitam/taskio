<script setup lang="ts">
// RecurrenceAxisEditor — THE recurrence picker. One component, two callers: the Workflows
// schedule trigger and the Calendar event drawer.
//
// It renders the axes as TABS (Time | Day | Month), each tab a `RecurrenceOptionCards` radio
// group whose SELECTED card expands with its in-sentence inputs — NEVER tabs-in-tabs (the
// Tabs switch the AXIS; the cards switch the SUB-MODE). Which tabs exist, and which cards
// each tab offers, is entirely the PROFILE's decision.
//
// ─────────────────────────────────────────────────────────────────────────────────────────
// WHAT THIS OWNS, AND WHAT IT DELIBERATELY DOES NOT
// ─────────────────────────────────────────────────────────────────────────────────────────
// OWNS: the tab chrome, the three axis models, the per-axis error routing, and nothing else.
//
// DOES NOT OWN, because these are not properties of a cadence:
//   • WHETHER the thing repeats at all (the Calendar's "does not repeat" — a schedule
//     trigger always repeats, so there is no shared question here),
//   • WHERE the series ENDS (never / on a date / after N times) — Workflows has no notion of
//     an end, and a control nobody on that side can use does not belong inside the shared
//     editor. The Calendar composes it AROUND this component.
//   • the upcoming-runs preview, the AI assist and the exceptions list — all three talk to
//     the Workflows endpoints, so they stay in the Workflows builder that wraps this.
//
// This is why it lives in `ui/`: `ui/**` may never import from `pages/**` (pinned by
// `__tests__/uiLayerImportBoundary.spec.ts`), and a component with no store, no endpoint and
// no module vocabulary satisfies that by construction. Both pages then import DOWN into it,
// which is the direction the layer rule allows.
import { computed, watch } from 'vue';
import Tabs, { type TabItem } from '../navigation/Tabs.vue';
import RecurrenceTimePanel from './RecurrenceTimePanel.vue';
import RecurrenceDayPanel from './RecurrenceDayPanel.vue';
import RecurrenceMonthPanel from './RecurrenceMonthPanel.vue';
import { useI18n } from '../../app/i18n';
import type { DayAxis, MonthAxis, RecurrenceAxisId, RecurrenceProfile, TimeAxis } from './recurrenceAxes';

const props = withDefaults(
  defineProps<{
    /** Which axes and sub-modes this caller's endpoint accepts. */
    profile: RecurrenceProfile;
    /**
     * Resolved, already-translated messages keyed by AXIS-RELATIVE path — `time.at`,
     * `day.weekdays`, `day.special.ordinal`, `month.months`, … Axis-relative because each
     * host's endpoint calls the block something different (`trigger_config.schedule.` vs
     * `recurrence.`); prefixing is the host's job, routing is this component's.
     */
    errors?: Record<string, string | undefined>;
    /** True while the day axis is `last_working_day` — the time axis locks to `at`. */
    lockTimeToAt?: boolean;
    /** Show the one-time "switched to set times" note after an auto-reset. */
    switchedToAt?: boolean;
    /** The start day newly-picked sub-modes seed from (Calendar); null ⇒ neutral seeds. */
    anchorDay?: string | null;
    /** Accessible name for the tab list. */
    ariaLabel?: string;
  }>(),
  {
    errors: () => ({}),
    lockTimeToAt: false,
    switchedToAt: false,
    anchorDay: null,
    ariaLabel: undefined,
  },
);

// Three separate models rather than one composite: the Calendar has no time axis at all and
// must not be made to carry a placeholder for one.
const time = defineModel<TimeAxis>('time');
const day = defineModel<DayAxis>('day', { required: true });
const month = defineModel<MonthAxis>('month', { required: true });

/**
 * The active tab. Optional: an unbound caller gets local state seeded to the profile's first
 * axis; a caller that needs to STEER the tab (Workflows jumps to the axis a 422 landed on)
 * binds `v-model:tab` and keeps that logic where the server paths are known.
 */
const tab = defineModel<RecurrenceAxisId | null>('tab', { default: null });

const { t } = useI18n();

const TAB_META: Record<RecurrenceAxisId, { key: string; icon: string }> = {
  time: { key: 'recurrenceEditor.tab.time', icon: 'clock' },
  day: { key: 'recurrenceEditor.tab.day', icon: 'calendar' },
  month: { key: 'recurrenceEditor.tab.month', icon: 'hash' },
};

const tabItems = computed<TabItem<RecurrenceAxisId>[]>(() =>
  props.profile.axes.map((value) => ({
    value,
    label: t(TAB_META[value].key),
    icon: TAB_META[value].icon,
  })),
);

/** The rendered tab: the bound value when it is one this profile shows, else the first axis. */
const activeTab = computed<RecurrenceAxisId>({
  get: () => {
    const v = tab.value;
    if (v != null && props.profile.axes.includes(v)) return v;
    return props.profile.axes[0] ?? 'day';
  },
  set: (v) => {
    tab.value = v;
  },
});

// A profile that stops offering the active axis (only possible if a caller swaps profiles at
// runtime) must not leave the tab strip pointing at a panel that no longer exists.
watch(
  () => props.profile.axes,
  (axes) => {
    if (tab.value != null && !axes.includes(tab.value)) tab.value = axes[0] ?? null;
  },
);

// --- Error routing: axis-relative paths → each panel's short field keys -------
const e = (path: string): string | undefined => props.errors[path];

const timeErrors = computed(() => ({
  at: e('time.at'),
  n: e('time.n'),
  minute: e('time.minute'),
  window: e('time.window'),
}));
const dayErrors = computed(() => ({
  n: e('day.n'),
  window: e('day.window'),
  weekdays: e('day.weekdays'),
  days: e('day.days'),
  ordinal: e('day.special.ordinal'),
  weekday: e('day.special.weekday'),
}));
const monthErrors = computed(() => ({
  n: e('month.n'),
  window: e('month.window'),
  months: e('month.months'),
}));

/**
 * The time axis is only ever bound by a profile that shows it. Writing through a
 * `defineModel` the caller never bound would silently drop the value, so the panel is
 * rendered only when BOTH the profile lists the axis and a model arrived.
 */
const showTime = computed(() => props.profile.axes.includes('time') && time.value != null);

/** A non-optional view of the time model, safe because `showTime` gates the panel. */
const timeAxis = computed<TimeAxis>({
  get: () => time.value as TimeAxis,
  set: (v) => {
    time.value = v;
  },
});
</script>

<template>
  <Tabs v-model="activeTab" :items="tabItems" variant="underline" size="md" :aria-label="ariaLabel">
    <template v-if="showTime" #panel-time>
      <RecurrenceTimePanel
        v-model="timeAxis"
        :profile="profile"
        :locked="lockTimeToAt"
        :switched-to-at="switchedToAt"
        :errors="timeErrors"
      />
    </template>
    <template #panel-day>
      <RecurrenceDayPanel v-model="day" :profile="profile" :errors="dayErrors" :anchor-day="anchorDay" />
    </template>
    <template #panel-month>
      <RecurrenceMonthPanel v-model="month" :profile="profile" :errors="monthErrors" :anchor-day="anchorDay" />
    </template>
  </Tabs>
</template>
