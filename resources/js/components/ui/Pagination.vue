<script setup lang="ts">
  import { ref, computed } from "vue";
  import Button from "./Button.vue";

  export interface PaginationOptions {
    page: number;
    pageSize: number;
    total?: number;
  }

  const props = withDefaults(
    defineProps<{
      modelValue: { cursor?: string | null; hasMore: boolean; pageSize: number };
      currentPage?: number;
      variant?: "cursor" | "offset";
      class?: string;
    }>(),
    { variant: "cursor", currentPage: 1 }
  );

  const emit = defineEmits<{
    (e: "update:modelValue", value: { cursor?: string | null; hasMore: boolean; pageSize: number }): void;
    (e: "load-more"): void;
    (e: "page-change", page: number): void;
  }>();

  const handleLoadMore = () => {
    emit("load-more");
  };

  const handlePageChange = (newPage: number) => {
    emit("page-change", newPage);
  };
</script>

<template>
  <div class="flex items-center justify-center gap-2" :class="class">
    <!-- Cursor-based (Infinite Scroll) -->
    <template v-if="variant === 'cursor'">
      <Button
        v-if="modelValue.hasMore"
        size="sm"
        variant="secondary"
        @click="handleLoadMore"
      >
        Load More
      </Button>
      <span v-else class="text-sm text-muted-foreground">
        No more items
      </span>
    </template>

    <!-- Offset-based pagination -->
    <template v-else>
      <Button
        size="sm"
        variant="secondary"
        :disabled="currentPage <= 1"
        @click="handlePageChange(currentPage - 1)"
      >
        ← Previous
      </Button>

      <span class="text-sm text-foreground">
        Page {{ currentPage }}
      </span>

      <Button
        size="sm"
        variant="secondary"
        :disabled="!modelValue.hasMore"
        @click="handlePageChange(currentPage + 1)"
      >
        Next →
      </Button>
    </template>
  </div>
</template>
