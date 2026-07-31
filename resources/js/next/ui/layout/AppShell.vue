<script setup lang="ts">
// AppShell — the single app layout for the "next" frontend.
//
// Structure: a fixed sidebar column + a top navbar + a scrollable main content
// area. Responsive: at >= next-md the sidebar is a permanent fixed column; below
// next-md it collapses to an off-canvas drawer toggled by a menu button (which
// the shell injects into the Navbar `#leading` slot), with a scrim overlay,
// focus trapping, Escape-to-close, and body-scroll lock while open.
//
// Slots:
//   #sidebar — the Sidebar (or any nav). Rendered once; shown as fixed column or
//              drawer depending on viewport.
//   #navbar  — the Navbar. The shell passes `{ openDrawer, drawerOpen }` to the
//              slot scope; the consumer renders the mobile menu button (e.g. in
//              the Navbar `#leading`) and calls `openDrawer`. This keeps the
//              shell in charge of drawer state without owning the navbar layout.
//   default  — main page content (scrolls independently under the navbar).
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import Icon from '../primitives/Icon.vue';
import { useFocusTrap } from '../../app/composables/useFocusTrap';
import {
  useOverlayStack,
  type OverlayHandle,
} from '../../app/composables/useOverlayStack';

withDefaults(
  defineProps<{
    /** Fixed sidebar width on desktop, as a spacing-ish utility class. */
    sidebarWidthClass?: string;
  }>(),
  { sidebarWidthClass: 'w-64' },
);

const drawerOpen = ref(false);
const drawerRef = ref<HTMLElement | null>(null);

useFocusTrap(drawerRef, drawerOpen);

function openDrawer(): void {
  drawerOpen.value = true;
}
function closeDrawer(): void {
  drawerOpen.value = false;
}

// The open drawer joins the shared overlay stack, exactly like Modal / Drawer (same `kind`, since
// this IS a modal off-canvas dialog: scrim + aria-modal + focus trap + scroll lock).
//
// WHY IT MATTERS: the stack's Escape listener runs on `document` in the CAPTURE phase, so it decides
// dismissal for whatever is topmost and stops the event there. The local `@keydown` below only ever
// saw Escape raised INSIDE the drawer's subtree — never from a body-teleported popover opened by a
// control in the drawer — and it took no part in the dismissal order against overlays that ARE
// registered (Modal, Drawer, Popover, and now every open `Select` list). Registering gives it both:
// Escape reaches it from anywhere, and an open list still wins the first press.
let overlay: OverlayHandle | null = null;
watch(drawerOpen, (open) => {
  if (open) {
    overlay = useOverlayStack({ kind: 'modal', close: closeDrawer });
  } else {
    overlay?.release();
    overlay = null;
  }
});
onBeforeUnmount(() => {
  overlay?.release();
  overlay = null;
});

// Kept as the local fallback for an Escape that never reaches the stack listener (e.g. one
// `stopPropagation()`-ed in the capture phase by a handler above us). Idempotent with the stack.
function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape' && drawerOpen.value) {
    event.stopPropagation();
    closeDrawer();
  }
}

// Lock body scroll while the drawer is open (mobile only; harmless otherwise).
watch(drawerOpen, (open) => {
  if (typeof document === 'undefined') return;
  document.body.style.overflow = open ? 'hidden' : '';
});

const overlayZ = computed(() => 'var(--z-next-overlay)');
const drawerZ = computed(() => 'var(--z-next-modal)');
</script>

<template>
  <div class="next-app-shell flex h-screen min-h-screen bg-next-bg text-next-fg">
    <!-- Desktop fixed sidebar column (hidden below next-md). -->
    <aside
      :class="[
        'hidden shrink-0 border-r border-next-border next-md:block',
        sidebarWidthClass,
      ]"
    >
      <div class="sticky top-next-0 h-screen">
        <slot name="sidebar" />
      </div>
    </aside>

    <!-- Mobile off-canvas drawer + scrim (only mounted/active below next-md). -->
    <div class="next-md:hidden" @keydown="onKeydown">
      <transition
        enter-active-class="transition-opacity duration-[var(--duration-next-normal)]"
        leave-active-class="transition-opacity duration-[var(--duration-next-normal)]"
        enter-from-class="opacity-0"
        leave-to-class="opacity-0"
      >
        <div
          v-if="drawerOpen"
          class="fixed inset-next-0 bg-next-overlay"
          :style="{ zIndex: overlayZ }"
          aria-hidden="true"
          @click="closeDrawer"
        />
      </transition>

      <transition
        enter-active-class="transition-transform duration-[var(--duration-next-slow)] ease-[var(--ease-next-emphasized)]"
        leave-active-class="transition-transform duration-[var(--duration-next-slow)] ease-[var(--ease-next-exit)]"
        enter-from-class="-translate-x-full"
        leave-to-class="-translate-x-full"
      >
        <div
          v-if="drawerOpen"
          ref="drawerRef"
          :class="['fixed inset-y-next-0 left-next-0 shadow-next-xl', sidebarWidthClass]"
          :style="{ zIndex: drawerZ }"
          role="dialog"
          aria-modal="true"
          aria-label="Navigation"
          tabindex="-1"
        >
          <div class="relative h-full">
            <slot name="sidebar" />
            <button
              type="button"
              class="absolute right-next-3 top-next-4 rounded-next-md p-next-2 text-next-fg hover:bg-next-accent hover:text-next-accent-foreground"
              aria-label="Close navigation"
              @click="closeDrawer"
            >
              <Icon name="x" class="text-next-lg" />
            </button>
          </div>
        </div>
      </transition>
    </div>

    <!-- Main column: navbar (consumer wires the mobile menu button via the
         scoped slot) + scrollable content.

         `<main>` is a height-constrained region: `min-h-0 flex-1` so it never
         exceeds the viewport, `flex flex-col` so a page can opt into full height
         (a `flex-1 min-h-0` child fills it and scrolls internally), and
         `overflow-y-auto` so a normal document-flow page (e.g. Dashboard) simply
         scrolls within main as usual. -->
    <div class="flex min-w-0 flex-1 flex-col">
      <slot name="navbar" :open-drawer="openDrawer" :drawer-open="drawerOpen" />

      <main class="flex min-h-0 min-w-0 flex-1 flex-col overflow-y-auto overflow-x-hidden">
        <slot />
      </main>
    </div>
  </div>
</template>
