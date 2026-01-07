<script setup lang="ts">
    import { useToast } from "@/composables/useToast";
    import { cn } from "@/lib/helpers";

    const { toasts, remove } = useToast();

    const toneCls: Record<string, string> = {
        neutral: "border-border",
        success: "border-emerald-500/25",
        warning: "border-amber-500/25",
        danger: "border-danger/25",
    };
</script>

<template>
  <div class="fixed bottom-6 right-6 z-50 w-[min(360px,calc(100vw-3rem))] space-y-3" aria-live="polite">
    <TransitionGroup
      enter-active-class="transition duration-200 ease-out"
      enter-from-class="opacity-0 translate-y-2"
      enter-to-class="opacity-100 translate-y-0"
      leave-active-class="transition duration-150 ease-in"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0"
    >
      <div
        v-for="t in toasts"
        :key="t.id"
        :class="cn(
          'flex items-start justify-between gap-4 rounded-xl border bg-card/95 p-4 shadow-lg backdrop-blur',
          toneCls[t.tone] ?? toneCls.neutral
        )"
        role="status"
      >
        <div>
          <div class="font-semibold">{{ t.title }}</div>
          <div v-if="t.message" class="mt-1 text-sm text-muted-foreground">
            {{ t.message }}
          </div>
        </div>

        <button
          type="button"
          class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-secondary hover:bg-secondary/80"
          aria-label="Zamknij"
          @click="remove(t.id)"
        >
          ✕
        </button>
      </div>
    </TransitionGroup>
  </div>
</template>
