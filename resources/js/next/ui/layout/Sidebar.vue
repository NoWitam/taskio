<script setup lang="ts">
// Sidebar — the vertical navigation container used by AppShell.
//
// Pure structure + slots, no routing knowledge of its own (items decide that
// via SidebarItem `to`/`href`). Regions:
//   #brand   — top brand / logo / workspace switcher
//   default  — the scrollable nav body (SidebarItem / SidebarSection rows)
//   #footer  — pinned footer (user menu, theme toggle, etc.)
//
// It is a <nav> landmark; pass `ariaLabel` to name it (defaults to "Main").
// AppShell handles the responsive fixed/drawer positioning — Sidebar only fills
// the height it is given.
withDefaults(
  defineProps<{
    /** Accessible name for the nav landmark. */
    ariaLabel?: string;
  }>(),
  { ariaLabel: 'Main' },
);
</script>

<template>
  <nav
    class="next-sidebar flex h-full flex-col bg-next-card"
    :aria-label="ariaLabel"
  >
    <!-- Brand / header -->
    <div
      v-if="$slots.brand"
      class="flex h-16 shrink-0 items-center gap-next-2 border-b border-next-border px-next-4"
    >
      <slot name="brand" />
    </div>

    <!-- Scrollable nav body -->
    <div class="min-h-0 flex-1 overflow-y-auto px-next-3 py-next-4">
      <div class="flex flex-col gap-next-4">
        <slot />
      </div>
    </div>

    <!-- Pinned footer -->
    <div
      v-if="$slots.footer"
      class="shrink-0 border-t border-next-border p-next-3"
    >
      <slot name="footer" />
    </div>
  </nav>
</template>
