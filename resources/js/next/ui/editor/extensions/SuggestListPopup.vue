<script setup lang="ts">
// SuggestListPopup — the SHELL every caret-anchored, list-shaped suggestion popup is made of.
//
// `MentionSuggest` (`@`) and `WikilinkSuggest` (`[[`) were two copies of this file with a different
// row inside. Everything around that row was duplicated verbatim: the synthetic caret anchor, the
// `useAnchoredPosition` wiring, the viewport listeners, the Teleport + `next-root next-overlay-root`
// + `isDark` re-rooting, the panel classes, and the `role="listbox"` / `aria-activedescendant`
// scaffold.
//
// WHY THAT MATTERED, concretely: the two copies DRIFTED. The wikilink popup learned to tell a
// FAILED search from a search that matched nothing — the mention popup did not, so the identical
// failure there kept rendering as "no matches". A shell with one state contract is what stops the
// next such fix from landing on one popup only.
//
// STATE CONTRACT (in precedence order, and the SAME for every host):
//   loading → option-shaped Skeleton rows (mimic the row, show several — never a spinner);
//   error   → its own row (`[data-suggest-error]`), because a broken search and an empty result are
//             different news: told there is nothing, a writer stops looking;
//   empty   → a muted "no matches" row;
//   list    → the host's rows, through the `row` slot.
//
// WHAT A HOST STILL OWNS: the row's shape (slot), the panel width, the leading skeleton's diameter
// so the placeholder matches its own row, the listbox id, and ALL copy — this is a `ui/` component
// and it carries no strings of its own, in any language.
//
// A11y: the editor keeps DOM focus (the trigger plugin forwards ↑/↓/Enter/Esc), so this is a
// NON-FOCUSABLE companion listbox addressed through `aria-activedescendant`. Escape is answered by
// the plugin, which registers the active popup in the shared overlay stack (`createSuggestionOverlay`)
// so it closes the LIST and not the Modal/Drawer holding the editor.
import { computed, ref, watch, nextTick, onBeforeUnmount } from 'vue';
import { useAnchoredPosition } from '../../../app/composables/useAnchoredPosition';
import { useTheme } from '../../../app/lib/theme';
import Icon from '../../primitives/Icon.vue';
import Skeleton from '../../data/Skeleton.vue';
import type { SuggestionRow, SuggestionStore } from './suggestionStore';

const props = withDefaults(
  defineProps<{
    store: SuggestionStore;
    /** The listbox element id; option ids derive from it. Owned by the host so each trigger keeps its own. */
    listboxId: string;
    /** The listbox's accessible name — TRANSLATED by the host. */
    ariaLabel: string;
    /** Localized state copy. REQUIRED: this component has no fallbacks, in any language. */
    labels: {
      /** Nothing matched the query. */
      empty: string;
      /** The search itself failed, as distinct from matching nothing. */
      error: string;
    };
    /** Panel width: `sm` for a name list, `md` where a row carries a badge + slug too. */
    size?: 'sm' | 'md';
    /** Diameter of the leading skeleton circle, so the placeholder matches the host's own row. */
    skeletonLead?: string;
  }>(),
  { size: 'sm', skeletonLead: '1.5rem' },
);

const { isDark } = useTheme();

const optionId = (i: number) => `${props.listboxId}-opt-${i}`;

const SIZE_CLASS: Record<'sm' | 'md', string> = {
  sm: 'w-64 max-w-[min(92vw,18rem)]',
  md: 'w-72 max-w-[min(92vw,20rem)]',
};

const anchorRef = ref<HTMLElement | null>(null);
const panelRef = ref<HTMLElement | null>(null);

const { style: anchorStyle, update: updatePosition } = useAnchoredPosition(anchorRef, panelRef, {
  placement: () => 'bottom-start',
  gap: 6,
  flip: true,
});

// A STABLE synthetic anchor whose rect always reflects the store's CURRENT caret rect. Keeping it
// stable (instead of re-creating it per rect change) means `updatePosition` reads fresh caret
// coordinates on every recompute — that is what lets the popup follow the caret on scroll.
const syntheticAnchor = {
  getBoundingClientRect: () => props.store.rect ?? new DOMRect(),
} as unknown as HTMLElement;

watch(
  () => props.store.rect,
  (rect) => {
    anchorRef.value = rect ? syntheticAnchor : null;
    if (!rect) return;
    void nextTick(() => {
      updatePosition();
      requestAnimationFrame(updatePosition);
    });
  },
  { immediate: true },
);

// On scroll/resize while open, ask the PLUGIN to re-measure the caret (`store.reposition` updates
// `store.rect` → the watch above repositions, or CLOSES the popup when the caret scrolled out of
// view). Fall back to a plain reposition when no plugin callback is wired (isolated component tests).
function onViewportChange(): void {
  if (props.store.reposition) props.store.reposition();
  else updatePosition();
}

function bindViewportListeners(): void {
  window.addEventListener('scroll', onViewportChange, true);
  window.addEventListener('resize', onViewportChange);
}
function unbindViewportListeners(): void {
  window.removeEventListener('scroll', onViewportChange, true);
  window.removeEventListener('resize', onViewportChange);
}

watch(
  () => props.store.active,
  (active) => {
    if (active) bindViewportListeners();
    else unbindViewportListeners();
  },
);

onBeforeUnmount(unbindViewportListeners);

const showError = computed(() => !props.store.loading && props.store.errored);
const showEmpty = computed(
  () => !props.store.loading && !props.store.errored && props.store.items.length === 0,
);

function pick(index: number): void {
  const item: SuggestionRow | undefined = props.store.items[index];
  if (item) props.store.onSelect?.(item);
}
</script>

<template>
  <Teleport to="body">
    <div v-if="store.active" class="next-root next-overlay-root" :class="isDark ? 'dark' : ''">
      <div
        ref="panelRef"
        class="next-suggest-pop fixed z-[var(--z-next-popover)] overflow-hidden rounded-next-lg border border-next-border bg-next-popover text-next-popover-foreground shadow-next-lg"
        :class="SIZE_CLASS[size]"
        :style="{ top: `${anchorStyle.top}px`, left: `${anchorStyle.left}px` }"
      >
        <ul
          :id="listboxId"
          role="listbox"
          :aria-label="ariaLabel"
          :aria-activedescendant="store.items.length ? optionId(store.activeIndex) : undefined"
          class="max-h-64 overflow-y-auto py-next-1"
        >
          <!-- Loading: option-shaped skeleton rows (several, never a spinner). -->
          <template v-if="store.loading">
            <li
              v-for="n in 4"
              :key="`sk-${n}`"
              class="flex items-center gap-next-2 px-next-3 py-next-1_5"
              aria-hidden="true"
            >
              <Skeleton variant="circle" :diameter="skeletonLead" />
              <Skeleton variant="text" :width="`${70 - n * 8}%`" />
            </li>
          </template>

          <!--
            THE SEARCH FAILED. Kept apart from "no matches" on purpose: told there is no such
            match, the writer stops looking, and whatever did exist stays unlinked / unmentioned.
          -->
          <li
            v-else-if="showError"
            class="flex items-center gap-next-2 px-next-3 py-next-2 text-next-sm text-next-warning"
            role="option"
            aria-disabled="true"
            data-suggest-error
          >
            <Icon name="alert-triangle" class="shrink-0 text-next-sm" aria-hidden="true" />
            <span class="min-w-0">{{ labels.error }}</span>
          </li>

          <!-- Empty -->
          <li
            v-else-if="showEmpty"
            class="px-next-3 py-next-2 text-next-sm text-next-muted-foreground"
            role="option"
            aria-disabled="true"
          >
            {{ labels.empty }}
          </li>

          <!-- Rows: the host fills the CONTENT; the option scaffold stays here so the keyboard
               model, the highlight and the aria wiring are written once. -->
          <template v-else>
            <li
              v-for="(item, index) in store.items"
              :id="optionId(index)"
              :key="item.id"
              role="option"
              :aria-selected="index === store.activeIndex"
              class="flex cursor-pointer items-center gap-next-2 px-next-3 py-next-1_5 text-next-sm"
              :class="index === store.activeIndex ? 'bg-next-accent text-next-accent-foreground' : ''"
              @mousedown.prevent="pick(index)"
              @mouseenter="store.activeIndex = index"
            >
              <slot name="row" :item="item" :index="index" />
            </li>
          </template>
        </ul>
      </div>
    </div>
  </Teleport>
</template>
