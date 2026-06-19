<script setup lang="ts">
// Root shell for the "next" app. Router owns everything below.
//
// Two global overlay hosts are mounted ONCE here so the imperative APIs work
// anywhere in the app (and in the styleguide gallery, which renders through this
// same <router-view>):
//   - ToastViewport — renders toasts pushed via `useToast()`.
//   - ConfirmHost   — renders dialogs requested via `useConfirm()`.
// Both teleport to <body>, so their placement in the tree here is purely for
// lifecycle ownership, not layout.
import ToastViewport from './ui/overlay/ToastViewport.vue';
import ConfirmHost from './ui/overlay/ConfirmHost.vue';
</script>

<template>
  <router-view />
  <ToastViewport />
  <ConfirmHost />
</template>
