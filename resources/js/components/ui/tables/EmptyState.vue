<script setup lang="ts">
    import Button from "@/components/ui/Button.vue";
    import { cn } from "@/lib/helpers";

    const props = defineProps<{
        title: string;
        description?: string;
        actionLabel?: string;
        class?: string;
    }>();

    const emit = defineEmits<{
        (e: "action"): void;
    }>();
</script>

<template>
  <div
    :class="cn(
      'rounded-2xl border border-secondary/60 bg-background p-8 text-center shadow-sm',
      props.class
    )"
  >
    <div v-if="$slots.icon" class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-secondary">
      <slot name="icon" />
    </div>

    <h3 class="text-base font-semibold text-foreground">
      {{ title }}
    </h3>

    <p v-if="description" class="mt-2 text-sm text-foreground/70">
      {{ description }}
    </p>

    <div v-if="actionLabel" class="mt-5">
      <Button variant="primary" @click="emit('action')">
        {{ actionLabel }}
      </Button>
    </div>
  </div>
</template>
