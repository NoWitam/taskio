<script setup lang="ts">
// ChipOverflow — the shared "+N" overflow affordance for the "next" frontend.
//
// When a fixed single-row chip track (Select multiple/chips mode, PillGroupInput
// collapse mode) can't fit every chip, the remainder collapses into ONE "+N"
// pill. This component owns that pill and its two behaviors, identically in BOTH
// consumers (single implementation, no duplication):
//
//   • HOVER / FOCUS → a read-only Tooltip listing the hidden labels.
//   • CLICK / Enter / ArrowDown → a TELEPORTED interactive panel (dialog) listing
//     each hidden item with a per-item remove ✕. The panel teleports to <body>
//     (the `.next-overlay-root` convention, `--z-next-popover`) anchored to the
//     pill via `useAnchoredPosition`, so it floats above any overflow-clipping
//     ancestor. Esc / outside-click close; focus moves into the panel on open
//     (with `preventScroll` so a body-teleported panel never scroll-jumps the
//     page — Issue 3) and returns to the pill on close.
//
// `items` are display strings (label === value for tags; the host maps options to
// labels and passes a parallel `values` array so remove emits the stable value).
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import Icon from '../primitives/Icon.vue';
import Tooltip from '../overlay/Tooltip.vue';
import { useTheme } from '../../app/lib/theme';
import { useOutsideClick } from '../../app/composables/useOutsideClick';
import { useAnchoredPosition } from '../../app/composables/useAnchoredPosition';
import { useI18n } from '../../app/i18n';

const { t } = useI18n();

const props = withDefaults(
  defineProps<{
    /** Hidden item labels (what the tooltip + panel rows show). */
    items: string[];
    /**
     * Stable values to emit on remove, parallel to `items`. Defaults to `items`
     * (tag inputs where label === value). Length must match `items`.
     */
    values?: string[];
    /** How many items are hidden (== items.length, but explicit for the label). */
    count: number;
    /** Hide the per-item remove ✕ (disabled/readonly host). */
    removable?: boolean;
    /** id wiring the pill's aria-controls to the teleported panel. */
    panelId?: string;
  }>(),
  { removable: true },
);

const emit = defineEmits<{
  /** A hidden item's ✕ was clicked. Emits the stable value. */
  (e: 'remove', value: string): void;
}>();

const { isDark } = useTheme();

const open = ref(false);
const plusRef = ref<HTMLElement | null>(null);
const panelRef = ref<HTMLElement | null>(null);

const resolvedValues = computed(() => props.values ?? props.items);
const tooltipLabel = computed(() => props.items.join(', '));

const { style: pos, update: updatePos } = useAnchoredPosition(plusRef, panelRef, {
  placement: () => 'bottom-start',
  gap: 4,
  flip: true,
});

function openPanel(): void {
  if (open.value) return;
  open.value = true;
  // Position BEFORE focusing so a body-teleported panel never scroll-jumps; then
  // focus with preventScroll (Issue 3).
  nextTick(() => {
    updatePos();
    requestAnimationFrame(() => {
      updatePos();
      panelRef.value?.focus({ preventScroll: true });
    });
  });
}
function closePanel(returnFocus = true): void {
  if (!open.value) return;
  open.value = false;
  if (returnFocus) nextTick(() => plusRef.value?.focus({ preventScroll: true }));
}
function toggle(): void {
  open.value ? closePanel() : openPanel();
}

watch(open, (isOpen) => {
  if (isOpen) {
    window.addEventListener('scroll', updatePos, true);
    window.addEventListener('resize', updatePos);
  } else {
    window.removeEventListener('scroll', updatePos, true);
    window.removeEventListener('resize', updatePos);
  }
});
onBeforeUnmount(() => {
  window.removeEventListener('scroll', updatePos, true);
  window.removeEventListener('resize', updatePos);
});

useOutsideClick([panelRef, plusRef], () => closePanel(false), open);

// DELIBERATELY NOT in `useOverlayStack`: bound to the panel, so it only fires while DOM focus is
// inside it, and the panel holds nothing that registers (chips + a remove button — no nested
// overlay). There is no press both this and a stacked overlay could want, so an entry would only
// add noise to the stack's ordering.
function onPanelKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    event.preventDefault();
    event.stopPropagation();
    closePanel(true);
  }
}

function onRemove(value: string): void {
  emit('remove', value);
  // The host shrinks the hidden set; close when nothing is left, else re-anchor.
  nextTick(() => {
    if (props.count <= 1) closePanel(true);
    else updatePos();
  });
}

// If the host empties the hidden set out from under an open panel, close it.
watch(
  () => props.count,
  (count) => {
    if (count <= 0 && open.value) closePanel(false);
  },
);
</script>

<template>
  <!-- Hover/focus → read-only tooltip of hidden labels. -->
  <Tooltip :label="tooltipLabel" placement="top">
    <button
      ref="plusRef"
      type="button"
      class="inline-flex h-5 shrink-0 items-center gap-next-1 rounded-next-full bg-next-muted px-next-1_5 text-next-2xs font-next-medium text-next-muted-foreground hover:bg-next-fg/10 hover:text-next-fg"
      :aria-expanded="open"
      :aria-controls="panelId"
      :aria-label="t('chipOverflow.more', '{count} more: {items}', { count, items: tooltipLabel })"
      @click.stop="toggle"
      @keydown.enter.prevent="toggle"
      @keydown.down.prevent="openPanel"
    >
      +{{ count }}
    </button>
  </Tooltip>

  <!-- Click/Enter → teleported interactive remove panel. -->
  <Teleport to="body">
    <!-- Opacity-only: a transform on this wrapper would become the containing
         block for the `fixed` panel (CSS spec) and pin it to the bottom of
         <body> mid-animation, scroll-jumping the page. Never add translate. -->
    <Transition
      enter-active-class="transition-opacity duration-[var(--duration-next-fast)] ease-[var(--ease-next-emphasized)]"
      enter-from-class="opacity-0"
      leave-active-class="transition-opacity duration-[var(--duration-next-fast)] ease-[var(--ease-next-exit)]"
      leave-to-class="opacity-0"
    >
      <div
        v-if="open"
        class="next-root next-overlay-root"
        :class="isDark ? 'dark' : ''"
      >
        <div
          :id="panelId"
          ref="panelRef"
          role="dialog"
          :aria-label="t('chipOverflow.hiddenItems', 'Hidden items')"
          tabindex="-1"
          class="fixed z-[var(--z-next-popover)] w-max max-w-[min(92vw,20rem)] rounded-next-lg border border-next-border bg-next-popover text-next-popover-foreground shadow-next-lg outline-none"
          :style="{ top: `${pos.top}px`, left: `${pos.left}px` }"
          @keydown="onPanelKeydown"
        >
          <ul
            role="list"
            class="max-h-56 min-w-[10rem] overflow-y-auto py-next-1"
            :aria-label="t('chipOverflow.hiddenItems', 'Hidden items')"
          >
            <li
              v-for="(label, i) in items"
              :key="resolvedValues[i] ?? label"
              class="mx-next-1 flex items-center justify-between gap-next-2 rounded-next-sm px-next-2 py-next-1 text-next-sm"
            >
              <span class="min-w-0 truncate">{{ label }}</span>
              <button
                v-if="removable"
                type="button"
                class="inline-flex shrink-0 items-center rounded-next-full p-[2px] text-next-muted-foreground hover:bg-next-fg/15 hover:text-next-fg"
                :aria-label="t('chipOverflow.remove', 'Remove {label}', { label })"
                @click="onRemove(resolvedValues[i] ?? label)"
              >
                <Icon name="x" class="text-[0.85em]" :stroke-width="2.5" />
              </button>
            </li>
          </ul>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
