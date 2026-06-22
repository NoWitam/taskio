<script setup lang="ts">
// FilterBar — the standard list-screen filter row for the "next" frontend.
//
// A slot-driven horizontal bar that standardizes how list screens filter:
//   • a debounced search TextInput (`v-model:search`, search icon, clear),
//   • arbitrary filter controls in the default slot (Selects, DateRangePicker,
//     switches, …) — they wrap onto new lines on narrow screens,
//   • an "active filters" chip row built from `activeFilters` (each chip
//     removable → `remove-filter`; a "Clear all" Button appears when > 1),
//   • a results-count text slot (`#results`),
//   • a trailing `#actions` slot (e.g. a "New" Button).
//
// When the active-filter chips overflow one line they collapse into a shared
// `+N` ChipOverflow pill (the same affordance Select / PillGroupInput use), whose
// remove also emits `remove-filter`.
//
// Search is debounced: typing updates the visible input instantly but only
// commits to `v-model:search` after `searchDebounce` ms (so list refetches don't
// fire on every keystroke). Clearing commits immediately.
//
// A11y: when `searchable`, the bar is a `role="search"` region labelled by
// `searchLabel`; otherwise it's a labelled `group`. Each chip's ✕ is keyboard-
// removable; "Clear all" is a real Button.
import { computed, ref, watch } from 'vue';
import TextInput from '../forms/TextInput.vue';
import Badge from '../primitives/Badge.vue';
import Button from '../primitives/Button.vue';
import { provideControlSize, type ControlSize } from '../forms/fieldShell';
import { useDebounce } from '../../app/composables/useDebounce';
import { useI18n } from '../../app/i18n';

const { t } = useI18n();

/**
 * The chip's relationship to the active saved-view snapshot (Saved Views,
 * Stage 2). Optional + additive: when ABSENT every chip renders exactly as
 * before (neutral/subtle, removable) — zero visual/behavioral regression for
 * bars without saved views.
 *   • `tab-active`   — filter is in BOTH the snapshot and the current state
 *                      (baseline; renders like today, removable).
 *   • `extra`        — filter is only in the current state (added on top of the
 *                      view) → primary tint + `plus` icon, removable.
 *   • `tab-disabled` — filter was in the snapshot but removed from the current
 *                      state → struck-through, dashed, NOT removable; a trailing
 *                      "restore" affordance emits `restore-filter`.
 */
export type FilterTabState = 'tab-active' | 'extra' | 'tab-disabled';

/** One removable value inside a multi-value filter group (e.g. one assignee). */
export interface ActiveFilterValue {
  /** Stable key emitted on remove (e.g. `user_id:42`). */
  key: string;
  /** Display label (e.g. a person's name). */
  label: string;
  /** Saved-view relationship of this individual value (optional, additive). */
  tabState?: FilterTabState;
}

export interface ActiveFilter {
  /** Stable key emitted on remove for a SINGLE-value filter. */
  key: string;
  /**
   * Display label for a single-value filter (e.g. "Status: Active"). Omit when
   * using `values` for a multi-value group.
   */
  label?: string;
  /**
   * Multi-value group: each value renders as its OWN removable chip (so the user
   * sees exactly what is selected, not "3 selected"), removed by its own `key`.
   */
  values?: ActiveFilterValue[];
  /**
   * Optional operator note shown (non-removable, muted) when the group has ≥2
   * values — e.g. "Any" / "All" for how labels combine. Surfaced by EVERY filter
   * bar so the combination mode is always discoverable.
   */
  operatorLabel?: string;
  /** Saved-view relationship of a SINGLE-value filter (optional, additive). */
  tabState?: FilterTabState;
}

/** A single flattened, removable chip rendered in the active-filter row. */
interface FlatChip {
  /** Key emitted on remove. */
  key: string;
  label: string;
  /** Saved-view relationship (undefined = baseline, like before). */
  tabState?: FilterTabState;
}

const props = withDefaults(
  defineProps<{
    /** Show the debounced search input. */
    searchable?: boolean;
    /** Search placeholder. */
    searchPlaceholder?: string;
    /** Debounce (ms) before committing the search value. */
    searchDebounce?: number;
    /** Accessible label for the search region (i18n-friendly). */
    searchLabel?: string;
    /** Accessible label for the whole bar when there's no search. */
    ariaLabel?: string;

    /** The active filter chips. */
    activeFilters?: ActiveFilter[];
    /** "Clear all" button label. */
    clearAllLabel?: string;
    /** Text shown when there are no active filters (omit to render nothing). */
    noFiltersLabel?: string;

    /** Stick the bar to the top of its scroll container. */
    sticky?: boolean;

    /**
     * Ambient size for the form controls placed in the default slot (and the
     * built-in search). Provided to descendants so every control in the bar shares
     * one size without each consumer threading a `size` prop. Defaults to `md`.
     */
    controlSize?: ControlSize;
  }>(),
  {
    searchable: true,
    searchDebounce: 300,
    activeFilters: () => [],
    controlSize: 'md',
  },
);

// Make the bar's control size ambient for its slotted controls + the search input.
provideControlSize(computed(() => props.controlSize));

// i18n-defaulted strings (overridable via props).
const searchPlaceholderText = computed(
  () => props.searchPlaceholder ?? t('filterBar.searchPlaceholder', 'Search…'),
);
const searchLabelText = computed(
  () => props.searchLabel ?? t('filterBar.searchLabel', 'Search and filter'),
);
const barLabelText = computed(() => props.ariaLabel ?? t('filterBar.barLabel', 'Filters'));
const clearAllLabelText = computed(
  () => props.clearAllLabel ?? t('filterBar.clearAll', 'Clear all'),
);

const emit = defineEmits<{
  (e: 'remove-filter', key: string): void;
  (e: 'clear-all'): void;
  /** Restore a `tab-disabled` filter back to the active saved view. */
  (e: 'restore-filter', key: string): void;
}>();

// v-model:search is the COMMITTED (debounced) value. `local` mirrors keystrokes
// instantly for a responsive input; it commits after the debounce.
const search = defineModel<string>('search', { default: '' });
const local = ref(search.value);

const commit = useDebounce((value: string) => {
  search.value = value;
}, props.searchDebounce);

watch(local, (value) => commit(value));

// Keep `local` in sync if the model is reset externally (e.g. "Clear all").
watch(search, (value) => {
  if (value !== local.value) local.value = value;
});

function onClearSearch(): void {
  commit.cancel();
  local.value = '';
  search.value = '';
}

// Flatten the filter groups into individual removable chips: a single-value
// filter contributes one chip; a multi-value group contributes one chip PER value
// (so the user sees each selected item, never "3 selected").
//
// Chips are ordered by their saved-view relationship so the user reads the
// "delta" from the active view at a glance: added (extra) → removed
// (tab-disabled) → from the view (tab-active). The sort is STABLE, so without an
// active view (every chip undefined) the consumer's original order is preserved.
const CHIP_RANK: Record<FilterTabState, number> = {
  extra: 0,
  'tab-disabled': 1,
  'tab-active': 2,
};
const flatChips = computed<FlatChip[]>(() => {
  const chips = (props.activeFilters ?? []).flatMap((f) =>
    f.values?.length
      ? f.values.map((v) => ({ key: v.key, label: v.label, tabState: v.tabState ?? f.tabState }))
      : f.label != null
        ? [{ key: f.key, label: f.label, tabState: f.tabState }]
        : [],
  );
  return chips
    .map((chip, index) => ({ chip, index }))
    .sort(
      (a, b) =>
        (a.chip.tabState ? CHIP_RANK[a.chip.tabState] : 0) -
          (b.chip.tabState ? CHIP_RANK[b.chip.tabState] : 0) || a.index - b.index,
    )
    .map((entry) => entry.chip);
});

// Operator notes (e.g. labels "Any"/"All"): shown non-removably when a group has
// ≥2 values, so the combination mode is always visible — like the legacy bar.
const operatorNotes = computed(() =>
  (props.activeFilters ?? [])
    .filter((f) => f.operatorLabel && (f.values?.length ?? 0) > 1)
    .map((f) => ({ key: f.key, label: f.operatorLabel as string })),
);

const hasActive = computed(() => flatChips.value.length > 0);
const showClearAll = computed(() => flatChips.value.length > 1);

function removeFilter(key: string): void {
  emit('remove-filter', key);
}
function clearAll(): void {
  emit('clear-all');
}
function restoreFilter(key: string): void {
  emit('restore-filter', key);
}
</script>

<template>
  <section
    class="next-filter-bar rounded-next-lg border border-next-border bg-next-card p-next-3 shadow-next-xs"
    :class="sticky ? 'sticky top-0 z-[var(--z-next-raised)]' : ''"
    :role="searchable ? 'search' : 'group'"
    :aria-label="searchable ? searchLabelText : barLabelText"
  >
    <!-- Optional top strip (e.g. the Saved Views toolbar) embedded INSIDE the bar
         to save vertical space; separated from the filter row by a divider. Renders
         nothing — and adds no spacing — when the slot is unused. -->
    <div
      v-if="$slots.top"
      class="mb-next-3 border-b border-next-border pb-next-3"
    >
      <slot name="top" />
    </div>

    <!-- Top row: search + controls + results + actions. Every control STRETCHES to
         share the full width (each is flex-1); the row wraps on narrow screens.
         The slot wrapper is `display:contents` so the consumer's controls become
         direct flex items of this row and grow alongside the search. -->
    <div class="flex flex-wrap items-center gap-next-3">
      <div v-if="searchable" class="min-w-[12rem] flex-[2_1_12rem]">
        <TextInput
          v-model="local"
          type="search"
          leading-icon="search"
          :placeholder="searchPlaceholderText"
          :aria-label="searchPlaceholderText"
          @clear="onClearSearch"
        />
      </div>

      <!-- Arbitrary filter controls (each child should be `flex-1` to stretch). -->
      <template v-if="$slots.default">
        <slot />
      </template>

      <div
        v-if="$slots.results || $slots.actions"
        class="ml-auto flex items-center gap-next-3"
      >
        <span v-if="$slots.results" class="text-next-sm text-next-muted-foreground">
          <slot name="results" />
        </span>
        <div v-if="$slots.actions" class="shrink-0">
          <slot name="actions" />
        </div>
      </div>
    </div>

    <!-- Active-filters chip row (only when there are chips, or a noFiltersLabel). -->
    <div
      v-if="hasActive || noFiltersLabel"
      class="mt-next-3 flex items-start gap-next-3 border-t border-next-border pt-next-3"
    >
      <template v-if="hasActive">
        <!-- All chips are shown — the line simply WRAPS (no +N collapse). Each value
             is its own removable chip. -->
        <div class="flex min-w-0 flex-1 flex-wrap items-center gap-next-1_5">
          <template v-for="chip in flatChips" :key="chip.key">
            <!-- tab-disabled: in the saved view but removed from the current
                 state. NOT removable; struck-through + dashed border (pattern,
                 not just color) + a "restore" trailing action. -->
            <Badge
              v-if="chip.tabState === 'tab-disabled'"
              variant="neutral"
              tone="subtle"
              class="border border-dashed border-next-border line-through opacity-70"
              :aria-label="t('tasks.filters.chip.disabledAria', '{label} (removed from the view — activate to restore)', { label: chip.label })"
              :trailing-action="{
                icon: 'rotate-ccw',
                label: t('tasks.filters.chip.restore', 'Restore filter: {label}', { label: chip.label }),
              }"
              @action="restoreFilter(chip.key)"
            >
              {{ chip.label }}
            </Badge>

            <!-- extra: added on top of the view. Primary tint + `plus` icon +
                 ring (multiple non-color signals). Removable as usual. -->
            <Badge
              v-else-if="chip.tabState === 'extra'"
              variant="primary"
              tone="subtle"
              icon="plus"
              class="ring-1 ring-next-primary/30"
              removable
              :remove-label="t('filterBar.removeFilter', 'Remove filter: {label}', { label: chip.label })"
              :aria-label="t('tasks.filters.chip.extraAria', '{label} (added on top of the view)', { label: chip.label })"
              @remove="removeFilter(chip.key)"
            >
              {{ chip.label }}
            </Badge>

            <!-- tab-active / no saved view (baseline) — IDENTICAL to before. -->
            <Badge
              v-else
              variant="neutral"
              tone="subtle"
              removable
              :remove-label="t('filterBar.removeFilter', 'Remove filter: {label}', { label: chip.label })"
              @remove="removeFilter(chip.key)"
            >
              {{ chip.label }}
            </Badge>
          </template>
        </div>

        <!-- Right rail, pinned to the FIRST line: operator notes (e.g. labels
             "Any"/"All") + the Clear-all button. `shrink-0` so they never wrap. -->
        <div class="flex shrink-0 items-center gap-next-2">
          <Badge
            v-for="note in operatorNotes"
            :key="`op-${note.key}`"
            variant="neutral"
            tone="subtle"
            class="italic"
          >
            {{ note.label }}
          </Badge>

          <Button v-if="showClearAll" size="xs" variant="ghost" @click="clearAll">
            {{ clearAllLabelText }}
          </Button>
        </div>
      </template>

      <span v-else class="text-next-xs font-next-medium text-next-muted-foreground">
        {{ noFiltersLabel }}
      </span>
    </div>
  </section>
</template>
