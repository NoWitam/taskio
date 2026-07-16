<script setup lang="ts">
// DropdownMenuLabel — a non-interactive group heading inside a DropdownMenu.
// Presentational (`role="presentation"`); skipped by keyboard navigation.
</script>

<template>
  <div
    role="presentation"
    class="px-next-3 pb-next-1 pt-next-2 text-next-2xs font-next-semibold uppercase tracking-[var(--tracking-next-wide)] text-next-muted-foreground"
  >
    <slot />
  </div>
</template>
