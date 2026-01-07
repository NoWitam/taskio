<script setup lang="ts">
    import { computed } from "vue";
    import { cn } from "@/lib/helpers";

    const props = withDefaults(
        defineProps<{
            title?: string;
            subtitle?: string;
            href?: string;
            selected?: boolean;
            disabled?: boolean;
            class?: string;
        }>(),
        { selected: false, disabled: false }
    );

    const isLink = computed(() => !!props.href && !props.disabled);

    const rootClasses = computed(() =>
        cn(
            "group rounded-2xl border bg-background p-4 shadow-sm transition",
            "border-secondary/60 hover:border-secondary/80",
            "focus-within:ring-2 focus-within:ring-primary/30",
            props.selected && "border-primary/40 ring-2 ring-primary/20",
            props.disabled && "opacity-60 cursor-not-allowed",
            props.class
        )
    );

    const titleClasses = "min-w-0 truncate text-sm font-semibold text-foreground";
    const subtitleClasses = "mt-0.5 line-clamp-2 text-sm text-foreground/70";
</script>

<template>
  <article :class="rootClasses">
    <component
      :is="isLink ? 'a' : 'div'"
      :href="isLink ? href : undefined"
      :aria-disabled="disabled || undefined"
      :tabindex="isLink ? 0 : undefined"
      class="block"
    >
      <div class="flex items-start gap-3">
        <!-- Leading (avatar/icon) -->
        <div v-if="$slots.leading" class="mt-0.5 shrink-0">
          <slot name="leading" />
        </div>

        <!-- Content -->
        <div class="min-w-0 flex-1">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <slot name="title">
                <div v-if="title" :class="titleClasses">{{ title }}</div>
              </slot>

              <div v-if="subtitle" :class="subtitleClasses">
                {{ subtitle }}
              </div>
            </div>

            <div class="flex shrink-0 items-center gap-2">
              <div v-if="$slots.badges" class="flex flex-wrap items-center justify-end gap-2">
                <slot name="badges" />
              </div>

              <div v-if="$slots.actions" class="ml-1">
                <slot name="actions" />
              </div>
            </div>
          </div>

          <div v-if="$slots.meta" class="mt-3">
            <slot name="meta" />
          </div>
        </div>
      </div>

      <div v-if="$slots.footer" class="mt-4 border-t border-secondary/60 pt-3">
        <slot name="footer" />
      </div>
    </component>
  </article>
</template>
