<script setup lang="ts">
  export interface BreadcrumbItem {
    label: string;
    href?: string;
  }

  withDefaults(
    defineProps<{
      items: BreadcrumbItem[];
      class?: string;
    }>(),
    {}
  );
</script>

<template>
  <nav class="flex items-center gap-2 text-sm" :class="class" aria-label="Breadcrumb">
    <ol class="flex items-center gap-2">
      <li v-for="(item, index) in items" :key="index">
        <div class="flex items-center gap-2">
          <a
            v-if="item.href"
            :href="item.href"
            class="text-primary hover:text-primary-dark dark:hover:text-primary-glow underline transition"
          >
            {{ item.label }}
          </a>
          <span v-else class="text-foreground">
            {{ item.label }}
          </span>

          <span v-if="index < items.length - 1" class="text-muted-foreground">
            /
          </span>
        </div>
      </li>
    </ol>
  </nav>
</template>
