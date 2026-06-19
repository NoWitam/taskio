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
import ChipOverflow from '../forms/ChipOverflow.vue';
import { useChipOverflow } from '../../app/composables/useChipOverflow';
import { useDebounce } from '../../app/composables/useDebounce';
import { useI18n } from '../../app/i18n';

const { t } = useI18n();

export interface ActiveFilter {
  /** Stable key emitted on remove. */
  key: string;
  /** Display label (e.g. "Status: Active"). */
  label: string;
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
  }>(),
  {
    searchable: true,
    searchDebounce: 300,
    activeFilters: () => [],
  },
);

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

const hasActive = computed(() => (props.activeFilters?.length ?? 0) > 0);
const showClearAll = computed(() => (props.activeFilters?.length ?? 0) > 1);

// Overflow: chips render in one clipped row; overflowing chips collapse into a
// shared +N pill. Measured against the real available width (no fixed count).
const chipTrackRef = ref<HTMLElement | null>(null);
const chipMeasureRef = ref<HTMLElement | null>(null);

const { visibleCount, hiddenCount, recompute } = useChipOverflow({
  trackRef: chipTrackRef,
  measureRef: chipMeasureRef,
  total: () => props.activeFilters?.length ?? 0,
  // Reserve room for the "Clear all" button when it shows (lives in the same row).
  reserved: () => (showClearAll.value ? 96 : 0),
});

watch(
  () => props.activeFilters,
  () => recompute(),
  { deep: true },
);

const visibleFilters = computed(() =>
  (props.activeFilters ?? []).slice(0, visibleCount.value),
);
const hiddenFilters = computed(() =>
  (props.activeFilters ?? []).slice(visibleCount.value),
);
const hiddenLabels = computed(() => hiddenFilters.value.map((f) => f.label));
const hiddenKeys = computed(() => hiddenFilters.value.map((f) => f.key));

function removeFilter(key: string): void {
  emit('remove-filter', key);
}
function clearAll(): void {
  emit('clear-all');
}
</script>

<template>
  <section
    class="next-filter-bar rounded-next-lg border border-next-border bg-next-card p-next-3 shadow-next-xs"
    :class="sticky ? 'sticky top-0 z-[var(--z-next-raised)]' : ''"
    :role="searchable ? 'search' : 'group'"
    :aria-label="searchable ? searchLabelText : barLabelText"
  >
    <!-- Top row: search + controls + results + actions. Wraps on narrow screens. -->
    <div class="flex flex-wrap items-center gap-next-3">
      <div v-if="searchable" class="min-w-[12rem] flex-1">
        <TextInput
          v-model="local"
          type="search"
          size="sm"
          leading-icon="search"
          :placeholder="searchPlaceholderText"
          :aria-label="searchPlaceholderText"
          @clear="onClearSearch"
        />
      </div>

      <!-- Arbitrary filter controls. -->
      <div v-if="$slots.default" class="flex flex-wrap items-center gap-next-2">
        <slot />
      </div>

      <div class="ml-auto flex items-center gap-next-3">
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
      class="relative mt-next-3 flex items-center gap-next-2 border-t border-next-border pt-next-3"
    >
      <template v-if="hasActive">
        <!-- The visible chips render in a single clipped track; overflow → +N. -->
        <div
          ref="chipTrackRef"
          class="flex min-w-0 flex-1 items-center gap-next-1_5 overflow-hidden"
        >
          <Badge
            v-for="f in visibleFilters"
            :key="f.key"
            variant="neutral"
            tone="subtle"
            removable
            :remove-label="t('filterBar.removeFilter', 'Remove filter: {label}', { label: f.label })"
            class="shrink-0"
            @remove="removeFilter(f.key)"
          >
            {{ f.label }}
          </Badge>

          <ChipOverflow
            v-if="hiddenCount > 0"
            :items="hiddenLabels"
            :values="hiddenKeys"
            :count="hiddenCount"
            panel-id="next-filter-bar-overflow"
            @remove="removeFilter"
          />
        </div>

        <!-- Hidden measuring row: every chip at natural width for the fit math. -->
        <div
          ref="chipMeasureRef"
          aria-hidden="true"
          class="pointer-events-none invisible absolute flex items-center gap-next-1_5"
        >
          <Badge
            v-for="f in activeFilters"
            :key="f.key"
            data-measure-chip
            variant="neutral"
            tone="subtle"
            removable
            class="shrink-0"
          >
            {{ f.label }}
          </Badge>
        </div>

        <Button v-if="showClearAll" size="xs" variant="ghost" class="shrink-0" @click="clearAll">
          {{ clearAllLabelText }}
        </Button>
      </template>

      <span v-else class="text-next-xs font-next-medium text-next-muted-foreground">
        {{ noFiltersLabel }}
      </span>
    </div>
  </section>
</template>
