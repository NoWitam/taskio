<script setup lang="ts">
// Navbar — the sticky top bar used by AppShell.
//
// Two slots: `#leading` (mobile menu button + the breadcrumb trail) and
// `#trailing` (actions, theme toggle, avatar/menu). It is a <header> with
// `role="banner"` semantics provided by the element, sticks to the top using the
// `--z-next-sticky` token, and carries a hairline + subtle shadow so content
// scrolling under it stays separated.
//
// The navbar must NOT host an h1 (D5): every routed page owns its single h1
// through PageHeader — the `#leading` slot carries breadcrumb CONTEXT, not the
// page title heading.
//
// The mobile menu button is intentionally NOT baked in here — AppShell injects
// it into `#leading` so the drawer state stays owned by the shell.
</script>

<template>
  <header
    class="next-navbar sticky top-next-0 flex h-16 shrink-0 items-center gap-next-3 border-b border-next-border bg-next-card px-next-4 shadow-next-xs next-md:px-next-6"
    :style="{ zIndex: 'var(--z-next-sticky)' }"
  >
    <div class="flex min-w-0 flex-1 items-center gap-next-3">
      <slot name="leading" />
    </div>
    <div class="flex shrink-0 items-center gap-next-2">
      <slot name="trailing" />
    </div>
  </header>
</template>
