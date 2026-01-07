<script setup lang="ts">
    import { computed } from "vue";
    import { cn } from "@/lib/helpers";
    import Badge from "@/components/ui/Badge.vue";
    import Button from "@/components/ui/Button.vue";

    export type ActiveFilter = {
        key: string;
        label: string;
    };

    const props = withDefaults(
        defineProps<{
            active: ActiveFilter[];
            title?: string;
            clearLabel?: string;
            class?: string;
        }>(),
        {
            title: "Filtry",
            clearLabel: "Wyczyść",
        }
    );

    const emit = defineEmits<{
        (e: "remove", key: string): void;
        (e: "clear"): void;
    }>();

    const hasActive = computed(() => props.active?.length > 0);

    function remove(key: string) {
        emit("remove", key);
    }

    function clearAll() {
        emit("clear");
    }
</script>

<template>
  <section
    :class="cn(
      'rounded-2xl border border-secondary/60 bg-background p-4 shadow-sm',
      props.class
    )"
    aria-label="Pasek filtrów"
  >
    <!-- Góra: kontrolki + akcje -->
    <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
      <div class="min-w-0">
        <div class="text-sm font-semibold text-foreground">
          {{ title }}
        </div>

        <div class="mt-2 flex flex-wrap items-end gap-3">
          <slot />
        </div>
      </div>

      <div v-if="$slots.actions" class="shrink-0">
        <slot name="actions" />
      </div>
    </div>

    <!-- Dół: aktywne filtry -->
    <div v-if="hasActive" class="mt-4 border-t border-secondary/60 pt-3">
      <div class="flex flex-wrap items-center gap-2">
        <span class="text-xs font-medium text-foreground/70">Aktywne:</span>

        <Badge
          v-for="f in active"
          :key="f.key"
          tone="neutral"
          class="gap-1.5 pr-1"
        >
          <span class="max-w-[14rem] truncate">{{ f.label }}</span>

          <button
            type="button"
            class="inline-flex h-5 w-5 items-center justify-center rounded-full border border-secondary/60 bg-background text-foreground/70 transition hover:bg-secondary/60 hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/30"
            :aria-label="`Usuń filtr: ${f.label}`"
            @click="remove(f.key)"
          >
            ✕
          </button>
        </Badge>

        <div class="ml-auto">
          <Button size="sm" variant="ghost" @click="clearAll">
            {{ clearLabel }}
          </Button>
        </div>
      </div>
    </div>
  </section>
</template>
