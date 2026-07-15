<script setup lang="ts">
// WorkflowSchedulePreviewStrip — the upcoming-runs rail (§4.5.4, REV5). A horizontally
// scrolled list of COMPACT two-line date tiles showing the CONCRETE effect of the
// AND-composition, fed by `POST /workflows/meta/schedule-preview` (via the store). REV5:
//   • it renders INSIDE the host's header-segment frame with NO visible heading — the
//     "Najbliższe uruchomienia" text is the rail region's `aria-label`;
//   • the "Skocz do daty" trigger MOVED to the segment row (the HOST owns it), so the
//     `anchor` arrives as a PROP; this component only consumes it to re-seed the rail;
//   • tiles are compact (`w-[7rem]`, weekday+date on line 1, time on line 2); the
//     "previous" (prev-or-at) tile is dashed + a leading `rotate-ccw` glyph, with
//     "poprzednie" ONLY in its `aria-label` (no caption line).
// It still owns the 4 UI states, FORWARD lazy paging (re-call with `anchor` = the last
// shown run, append the rest), and edge-fade gradients over the muted frame. It exposes
// `{ empty, loading }` so the builder's `isValid` gate can block save on an empty
// schedule / a mid-flight preview (§4.5.11); a network error is NON-blocking.
import { computed, nextTick, ref, watch } from 'vue';
import Surface from '../../ui/layout/Surface.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Alert from '../../ui/feedback/Alert.vue';
import Skeleton from '../../ui/data/Skeleton.vue';
import { useI18n } from '../../app/i18n';
import { useWorkflowsStore } from '../../app/stores/workflows';
import { useDebounce } from '../../app/composables/useDebounce';
import {
  occurrencePartsFormatter,
  formatOccurrenceParts,
  isPreviousOccurrence,
} from './workflowSchedule';
import type { WorkflowScheduleConfig } from './types';

const props = withDefaults(
  defineProps<{
    /** The v2 wire config to preview (built from the draft by the host). */
    config: WorkflowScheduleConfig;
    /** The schedule tz for RENDERING ('' ⇒ the active browser tz, §4.5.8). */
    tz?: string | null;
    /** The shared "jump to date" anchor, owned by the HOST (§4.5.4). Null ⇒ "now" view. */
    anchor?: string | null;
    /** When true, the host knows the draft is client-invalid → skip previewing. */
    disabled?: boolean;
  }>(),
  { tz: null, anchor: null, disabled: false },
);

const { t, locale } = useI18n();
const store = useWorkflowsStore();

const PAGE = 6;

// --- Rail state (the 4 UI states + paging) ----------------------------------
const occurrences = ref<string[]>([]);
const loading = ref(false);
const loadingMore = ref(false);
const empty = ref(false);
const errored = ref(false);
const hasMore = ref(true);

// A reset token: a fresh first-load supersedes any in-flight first-load AND drops a
// paging append that lands after a reset (so a stale page can never be stitched on).
let resetToken = 0;

defineExpose({ empty, loading });

/** The render zone: the schedule tz, or the active browser tz when blank (§4.5.8). */
const renderZone = computed(() => {
  const z = props.tz?.trim();
  if (z) return z;
  try {
    return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
  } catch {
    return 'UTC';
  }
});
const formatters = computed(() => occurrencePartsFormatter(locale.value, renderZone.value));

/** Whether tile[0] is the "previous" (prev-or-at) tile for the active anchor. */
const previousIsFirst = computed(
  () => props.anchor != null && occurrences.value.length > 0 && isPreviousOccurrence(occurrences.value[0], props.anchor),
);

interface Tile {
  iso: string;
  weekday: string;
  date: string;
  time: string;
  previous: boolean;
}

const tiles = computed<Tile[]>(() =>
  occurrences.value.map((iso, i) => {
    const parts = formatOccurrenceParts(iso, formatters.value);
    return { iso, ...parts, previous: i === 0 && previousIsFirst.value };
  }),
);

/** A screen-reader label announcing the whole instant (+ the "previous" caption). */
function tileAria(tile: Tile): string {
  const base = t('workflows.schedule.preview.tileAria', undefined, {
    weekday: tile.weekday,
    date: tile.date,
    time: tile.time,
  });
  return tile.previous ? `${t('workflows.schedule.preview.previousTile')}, ${base}` : base;
}

// --- Fetching ----------------------------------------------------------------
/** First load / re-seed: resets the rail from `now` or the host's active anchor. */
async function loadFirst(): Promise<void> {
  if (props.disabled) return;
  const my = (resetToken += 1);
  loading.value = true;
  loadingMore.value = false;
  errored.value = false;
  empty.value = false;
  hasMore.value = true;
  try {
    const res = await store.schedulePreview(props.config, { count: PAGE, anchor: props.anchor });
    if (my !== resetToken) return;
    occurrences.value = res.occurrences;
    empty.value = res.empty;
    // A full page suggests there may be more; empty / a short page ends paging.
    hasMore.value = !res.empty && res.occurrences.length >= PAGE;
    void ensureFilled();
  } catch {
    if (my !== resetToken) return;
    // Quiet + non-blocking: the summary sentence stays and the schedule is savable.
    errored.value = true;
    occurrences.value = [];
    empty.value = false;
    hasMore.value = false;
  } finally {
    if (my === resetToken) loading.value = false;
  }
}

/** FORWARD paging: re-call with `anchor` = the last shown run, append the rest. */
async function loadMore(): Promise<void> {
  if (props.disabled || loading.value || loadingMore.value || !hasMore.value || empty.value || errored.value) return;
  const last = occurrences.value[occurrences.value.length - 1];
  if (!last) return;
  const my = resetToken; // capture — a reset while paging drops this append
  const lastMs = new Date(last).getTime();
  loadingMore.value = true;
  try {
    const res = await store.schedulePreview(props.config, { count: PAGE, anchor: last });
    if (my !== resetToken) return;
    // Drop occurrences[0] (the prev-or-at of `last` = `last` itself) + any overlap.
    const fresh = res.occurrences.filter((o) => new Date(o).getTime() > lastMs);
    occurrences.value = [...occurrences.value, ...fresh];
    hasMore.value = fresh.length > 0;
    void ensureFilled();
  } catch {
    if (my !== resetToken) return;
    hasMore.value = false; // stop paging, keep what we have (non-blocking)
  } finally {
    if (my === resetToken) loadingMore.value = false;
    void nextTick(updateEdges);
  }
}

/**
 * Keep loading pages until the rail OVERFLOWS (or paging ends). Without a load-more
 * button the sentinel is the only paging driver, but IntersectionObserver only fires on
 * VISIBILITY TRANSITIONS — a sentinel that stays in view on a wide rail never re-fires,
 * which would strand paging at one page. The `clientWidth > 0` guard skips environments
 * with no real layout (jsdom/happy-dom in specs).
 */
async function ensureFilled(): Promise<void> {
  await nextTick();
  const el = railRef.value;
  if (!el || el.clientWidth === 0) return;
  if (hasMore.value && !loading.value && !loadingMore.value && !empty.value && !errored.value && el.scrollWidth <= el.clientWidth) {
    void loadMore();
  }
}

const debouncedLoadFirst = useDebounce(loadFirst, 400);

/** Reset the rail: show skeletons immediately, debounce the actual fetch. */
function resetRail(): void {
  if (props.disabled) {
    resetToken += 1; // drop anything in flight
    loading.value = false;
    occurrences.value = [];
    empty.value = false;
    errored.value = false;
    hasMore.value = false; // no stray "Load more" on the blank rail
    return;
  }
  loading.value = true;
  debouncedLoadFirst();
}

// Re-seed on config / tz / anchor / disabled change. Immediate so a valid seeded
// config previews as soon as the strip mounts.
watch(
  () => [props.config, props.disabled, props.anchor] as const,
  resetRail,
  { deep: true, immediate: true },
);

// --- Lazy paging (scroll-edge driven) -----------------------------------------
// Deliberately NOT IntersectionObserver-based: a horizontal rail owns its scroll
// events anyway (edge fades), and IO callbacks are suspended in backgrounded /
// embedded renderers — the rail's own scroll position is always trustworthy.
const railRef = ref<HTMLElement | null>(null);
const LOAD_AHEAD_PX = 200;

function maybeLoadMore(): void {
  const el = railRef.value;
  if (!el) return;
  if (el.scrollLeft + el.clientWidth >= el.scrollWidth - LOAD_AHEAD_PX) void loadMore();
}

// --- Edge fades (shown only while scrollable that way) -----------------------
const canScrollLeft = ref(false);
const canScrollRight = ref(false);
function updateEdges(): void {
  const el = railRef.value;
  if (!el) return;
  canScrollLeft.value = el.scrollLeft > 1;
  canScrollRight.value = el.scrollLeft + el.clientWidth < el.scrollWidth - 1;
}
watch(occurrences, () => void nextTick(updateEdges));

/** Scroll: refresh the edge fades AND drive the lazy paging (near the right edge). */
function onScroll(): void {
  updateEdges();
  maybeLoadMore();
}

/** Translate a vertical mouse wheel into horizontal rail scrolling (the rail is the
 *  only horizontal region under the cursor, so the gesture is unambiguous). Native
 *  horizontal gestures (trackpads, shift+wheel) pass through untouched. */
function onWheel(event: WheelEvent): void {
  const el = railRef.value;
  if (!el || el.scrollWidth <= el.clientWidth) return;
  if (Math.abs(event.deltaY) <= Math.abs(event.deltaX)) return;
  event.preventDefault();
  el.scrollLeft += event.deltaY;
}
</script>

<template>
  <!-- No visible heading (REV5): the rail region is NAMED for assistive tech via the
       scroll list's aria-label; the "Skocz do daty" trigger lives in the segment row. -->
  <div class="flex flex-col gap-next-2">
    <!-- Loading: compact skeleton tiles. -->
    <div
      v-if="loading"
      class="flex gap-next-2 overflow-hidden"
      role="status"
      :aria-label="t('workflows.schedule.preview.loading')"
    >
      <Skeleton v-for="n in 6" :key="n" variant="rect" width="7rem" height="3.25rem" radius="md" />
    </div>

    <!-- Empty: the AND-composition rules out every run → a data WARNING (blocks save). -->
    <Alert v-else-if="empty" variant="warning" size="sm">
      {{ t('workflows.schedule.preview.empty') }}
    </Alert>

    <!-- Error: quiet, NON-blocking (a preview outage never blocks the builder). -->
    <p v-else-if="errored" class="text-next-sm text-next-muted-foreground">
      {{ t('workflows.schedule.preview.unavailable') }}
    </p>

    <!-- Success: the horizontally-scrolled COMPACT tile rail. -->
    <div v-else class="relative">
      <!-- Visible scrollbar (signals scrollability alongside the edge fades) + vertical
           wheel → horizontal scroll. Paging is scroll-driven ONLY (the sentinel below);
           in-flight pages render as trailing skeleton tiles. -->
      <ul
        ref="railRef"
        class="flex gap-next-2 overflow-x-auto pb-next-2"
        :aria-label="t('workflows.schedule.preview.title')"
        @scroll="onScroll"
        @wheel="onWheel"
      >
        <li v-for="tile in tiles" :key="tile.iso">
          <Surface
            bg="card"
            border
            radius="md"
            :class="[
              'flex min-w-[7rem] shrink-0 flex-col gap-next-0_5 whitespace-nowrap p-next-2',
              tile.previous ? 'border-dashed bg-next-muted text-next-muted-foreground' : '',
            ]"
            :aria-label="tileAria(tile)"
          >
            <!-- Line 1: weekday + date (the previous tile leads with a rotate-ccw glyph).
                 whitespace-nowrap keeps every tile ONE height — a long weekday must widen
                 the tile, never wrap it taller. -->
            <span class="flex items-center gap-next-1">
              <Icon
                v-if="tile.previous"
                name="rotate-ccw"
                class="text-next-2xs text-next-muted-foreground"
                aria-hidden="true"
              />
              <span class="text-next-2xs uppercase tracking-wide text-next-muted-foreground">{{ tile.weekday }}</span>
              <span class="text-next-sm font-next-semibold tabular-nums text-next-fg">{{ tile.date }}</span>
            </span>
            <!-- Line 2: time. -->
            <span class="text-next-sm tabular-nums text-next-fg">{{ tile.time }}</span>
          </Surface>
        </li>

        <!-- Trailing skeleton tiles while the next page loads on scroll. -->
        <template v-if="loadingMore">
          <li v-for="n in 3" :key="`sk-${n}`" aria-hidden="true" class="shrink-0">
            <Skeleton variant="rect" width="7rem" height="3.25rem" radius="md" />
          </li>
        </template>
      </ul>

      <!-- Edge fades over the MUTED segment frame — positioned siblings paint above the
           static rail (shown only while scrollable that way). -->
      <div
        v-show="canScrollLeft"
        class="pointer-events-none absolute inset-y-0 left-0 w-8 bg-gradient-to-r from-next-muted to-transparent"
        aria-hidden="true"
      />
      <div
        v-show="canScrollRight"
        class="pointer-events-none absolute inset-y-0 right-0 w-8 bg-gradient-to-l from-next-muted to-transparent"
        aria-hidden="true"
      />
    </div>
  </div>
</template>
