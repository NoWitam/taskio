<script setup lang="ts">
// MentionSuggest — the teleported, caret-anchored suggestion popup for BOTH the
// `@` mention trigger AND the `{` variable trigger. Driven entirely by the
// reactive `suggestionStore` (a ProseMirror plugin publishes caret rect / query /
// items / loading there; this component renders + positions, and reports the
// highlighted index back). The `variant` on the store selects the row rendering:
// mentions show an avatar; variables show a type icon.
//
// POSITIONING (no tippy): we anchor against a SYNTHETIC element whose
// `getBoundingClientRect()` returns the store's caret rect, then reuse
// `useAnchoredPosition` (same flip/clamp the rest of `next` uses) + a Teleport to
// `body`. Recomputed whenever the rect changes and on scroll/resize.
//
// A11y: `role="listbox"` with `role="option"` rows and `aria-activedescendant`
// pointing at the highlighted row; the editor keeps DOM focus (the plugin
// forwards ↑/↓/Enter/Esc), so this is a non-focusable companion listbox.
//
// STATES: loading → option-shaped Skeleton rows (skeleton rule: mimic the row,
// show several — never a spinner). empty → a muted "No matches" row.
import { computed, ref, watch, nextTick } from 'vue';
import { useAnchoredPosition } from '../../../app/composables/useAnchoredPosition';
import { useTheme } from '../../../app/lib/theme';
import Avatar from '../../primitives/Avatar.vue';
import Icon from '../../primitives/Icon.vue';
import Skeleton from '../../data/Skeleton.vue';
import type { SuggestionStore } from './suggestionStore';

const props = defineProps<{ store: SuggestionStore }>();

const { isDark } = useTheme();

const listboxId = computed(() => `next-${props.store.variant}-listbox`);
const optionId = (i: number) => `${listboxId.value}-opt-${i}`;
const ariaLabel = computed(() =>
  props.store.variant === 'variable' ? 'Variables' : 'Mentions',
);

const anchorRef = ref<HTMLElement | null>(null);
const panelRef = ref<HTMLElement | null>(null);

const { style: anchorStyle, update: updatePosition } = useAnchoredPosition(
  anchorRef,
  panelRef,
  { placement: () => 'bottom-start', gap: 6, flip: true },
);

watch(
  () => props.store.rect,
  (rect) => {
    if (!rect) {
      anchorRef.value = null;
      return;
    }
    anchorRef.value = {
      getBoundingClientRect: () => rect,
    } as unknown as HTMLElement;
    void nextTick(() => {
      updatePosition();
      requestAnimationFrame(updatePosition);
    });
  },
  { immediate: true },
);

watch(
  () => props.store.active,
  (active) => {
    if (active) {
      window.addEventListener('scroll', updatePosition, true);
      window.addEventListener('resize', updatePosition);
    } else {
      window.removeEventListener('scroll', updatePosition, true);
      window.removeEventListener('resize', updatePosition);
    }
  },
);

const showEmpty = computed(
  () => !props.store.loading && props.store.items.length === 0,
);

function pick(index: number): void {
  const item = props.store.items[index];
  if (item) props.store.onSelect?.(item);
}
</script>

<template>
  <Teleport to="body">
    <div v-if="store.active" class="next-root next-overlay-root" :class="isDark ? 'dark' : ''">
      <div
        ref="panelRef"
        class="next-suggest-pop fixed z-[var(--z-next-popover)] w-64 max-w-[min(92vw,18rem)] overflow-hidden rounded-next-lg border border-next-border bg-next-popover text-next-popover-foreground shadow-next-lg"
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
              <Skeleton variant="circle" diameter="1.5rem" />
              <Skeleton variant="text" :width="`${70 - n * 8}%`" />
            </li>
          </template>

          <!-- Empty -->
          <li
            v-else-if="showEmpty"
            class="px-next-3 py-next-2 text-next-sm text-next-muted-foreground"
            role="option"
            aria-disabled="true"
          >
            No matches
          </li>

          <!-- Results -->
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
              <span
                v-if="store.variant === 'variable'"
                class="flex h-6 w-6 shrink-0 items-center justify-center rounded-next-full bg-next-muted text-next-fg"
              >
                <Icon :name="item.icon ?? 'type'" />
              </span>
              <Avatar v-else :src="item.avatar ?? undefined" :name="item.label" size="xs" class="shrink-0" />
              <span class="min-w-0 truncate">{{ item.label }}</span>
            </li>
          </template>
        </ul>
      </div>
    </div>
  </Teleport>
</template>
