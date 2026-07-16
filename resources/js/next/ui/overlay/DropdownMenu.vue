<script setup lang="ts">
// DropdownMenu — a menu button + popup menu for the "next" frontend.
//
// Built on Popover (positioning + overlay stack + outside-click/Esc topmost
// dismissal). The trigger slot provides a real <button>; the menu panel uses
// `role="menu"` with `role="menuitem"` children (DropdownMenuItem). Keyboard:
//   ↑/↓        roving move (skips disabled, wraps)
//   Home/End   first / last item
//   Enter/Space activate the active item
//   Esc        close + restore focus to the trigger (handled by Popover/stack)
//   type-ahead jump to an item by label prefix
//   Tab        closes the menu
// Virtual focus: the active item is tracked via `aria-activedescendant` on the
// menu; individual items are not tab stops. Provides a context (see
// dropdownMenu.ts) that items register with.
import {
  computed,
  nextTick,
  provide,
  ref,
} from 'vue';
import Popover from './Popover.vue';
import { DROPDOWN_MENU_KEY, type DropdownMenuContext } from './dropdownMenu';
import type { Placement } from '../../app/composables/useAnchoredPosition';

const props = withDefaults(
  defineProps<{
    placement?: Placement;
    offset?: number;
    /** Accessible label for the menu region. */
    ariaLabel?: string;
    /** Match the menu's min-width to the trigger. */
    matchWidth?: boolean;
  }>(),
  {
    placement: 'bottom-start',
    offset: 6,
    matchWidth: false,
  },
);

const open = defineModel<boolean>('open', { default: false });

const popoverRef = ref<InstanceType<typeof Popover> | null>(null);
const menuRef = ref<HTMLElement | null>(null);
const menuId = `next-menu-${Math.random().toString(36).slice(2, 8)}`;

interface RegisteredItem {
  el: () => HTMLElement | null;
  getLabel: () => string;
  isDisabled: () => boolean;
}
const items = ref<RegisteredItem[]>([]);
const activeIndex = ref(-1);

function enabledIndices(): number[] {
  return items.value
    .map((it, i) => (it.isDisabled() ? -1 : i))
    .filter((i) => i >= 0);
}

function firstEnabled(): number {
  return enabledIndices()[0] ?? -1;
}
function lastEnabled(): number {
  const list = enabledIndices();
  return list[list.length - 1] ?? -1;
}
function step(dir: 1 | -1): void {
  const list = enabledIndices();
  if (list.length === 0) return;
  const pos = list.indexOf(activeIndex.value);
  if (pos === -1) {
    activeIndex.value = dir === 1 ? list[0] : list[list.length - 1];
  } else {
    const next = (pos + dir + list.length) % list.length;
    activeIndex.value = list[next];
  }
  scrollActiveIntoView();
}

function scrollActiveIntoView(): void {
  const item = items.value[activeIndex.value];
  item?.el()?.scrollIntoView({ block: 'nearest' });
}

function activeItemId(): string | undefined {
  if (activeIndex.value < 0) return undefined;
  return `${menuId}-item-${activeIndex.value}`;
}

// --- Type-ahead -----------------------------------------------------------
let typeBuffer = '';
let typeTimer: ReturnType<typeof setTimeout> | undefined;
function onTypeAhead(char: string): void {
  typeBuffer += char.toLowerCase();
  if (typeTimer) clearTimeout(typeTimer);
  typeTimer = setTimeout(() => (typeBuffer = ''), 600);
  const match = items.value.findIndex(
    (it) => !it.isDisabled() && it.getLabel().toLowerCase().startsWith(typeBuffer),
  );
  if (match >= 0) {
    activeIndex.value = match;
    scrollActiveIntoView();
  }
}

// --- Context for items ----------------------------------------------------
const ctx: DropdownMenuContext = {
  register(item) {
    items.value.push(item);
    return items.value.length - 1;
  },
  unregister(index) {
    if (index >= 0 && index < items.value.length) {
      items.value.splice(index, 1);
    }
  },
  itemId: (index) => itemId(index),
  activeIndex: () => activeIndex.value,
  setActive: (index) => {
    activeIndex.value = index;
  },
  activate: (index) => {
    if (items.value[index]?.isDisabled()) return;
    close();
  },
  close: () => close(),
};
provide(DROPDOWN_MENU_KEY, ctx);

function close(): void {
  open.value = false;
}

function onOpen(): void {
  activeIndex.value = firstEnabled();
  nextTick(() => {
    menuRef.value?.focus({ preventScroll: true });
    scrollActiveIntoView();
  });
}

function onClose(): void {
  activeIndex.value = -1;
  typeBuffer = '';
}

function onMenuKeydown(event: KeyboardEvent): void {
  switch (event.key) {
    case 'ArrowDown':
      event.preventDefault();
      step(1);
      break;
    case 'ArrowUp':
      event.preventDefault();
      step(-1);
      break;
    case 'Home':
      event.preventDefault();
      activeIndex.value = firstEnabled();
      scrollActiveIntoView();
      break;
    case 'End':
      event.preventDefault();
      activeIndex.value = lastEnabled();
      scrollActiveIntoView();
      break;
    case 'Enter':
    case ' ':
      event.preventDefault();
      if (activeIndex.value >= 0) {
        items.value[activeIndex.value]?.el()?.click();
      }
      break;
    case 'Tab':
      // Let focus leave; close the menu.
      close();
      break;
    default:
      if (
        event.key.length === 1 &&
        !event.metaKey &&
        !event.ctrlKey &&
        !event.altKey
      ) {
        event.preventDefault();
        onTypeAhead(event.key);
      }
  }
}

// Assign stable ids to items for aria-activedescendant after open.
function itemId(index: number): string {
  return `${menuId}-item-${index}`;
}

defineExpose({ open: computed(() => open.value), close });
</script>

<template>
  <Popover
    ref="popoverRef"
    v-model:open="open"
    :placement="placement"
    :offset="offset"
    role="none"
    haspopup="menu"
    :auto-focus="false"
    :match-width="matchWidth"
    @open="onOpen"
    @close="onClose"
  >
    <template #trigger="{ open: isOpen, toggle, props: triggerProps }">
      <slot name="trigger" :open="isOpen" :toggle="toggle" :props="triggerProps" />
    </template>

    <template #content="{ close: closePanel }">
      <ul
        :id="menuId"
        ref="menuRef"
        role="menu"
        tabindex="-1"
        :aria-label="ariaLabel"
        :aria-activedescendant="activeItemId()"
        class="max-h-[min(24rem,80vh)] min-w-48 overflow-y-auto py-next-1 outline-none"
        @keydown="onMenuKeydown"
      >
        <slot :close="closePanel" />
      </ul>
    </template>
  </Popover>
</template>
