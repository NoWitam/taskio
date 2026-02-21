<script setup lang="ts">
    import { cn } from "@/lib/helpers";

    export type BreadcrumbItem = {
    label: string;
    href?: string;
    };

    const props = withDefaults(
        defineProps<{
            title: string;
            description?: string;
            breadcrumbs?: BreadcrumbItem[];
            class?: string;
        }>(),
        { breadcrumbs: () => [] }
    );
</script>

<template>
  <header :class="cn('w-full', props.class)">
    <!-- Main row -->
    <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
      <div class="min-w-0">
          <div class="min-w-0">
            <div class="flex items-center gap-3">
                <div v-if="$slots.icon" class="shrink-0 flex items-center">
                    <slot name="icon" />
                </div>

                <h1 class="text-2xl font-semibold text-foreground md:text-3xl text-left">
                    {{ title }}
                </h1>
            </div>


            <p v-if="description" class="mt-1 max-w-3xl text-sm text-foreground/70">
              {{ description }}
            </p>

            <div v-if="$slots.extra" class="mt-3">
              <slot name="extra" />
            </div>
          </div>
      </div>

      <div v-if="$slots.actions" class="shrink-0">
        <div class="flex flex-wrap items-center justify-start gap-2 md:justify-end">
          <slot name="actions" />
        </div>
      </div>
    </div>
  </header>
</template>
