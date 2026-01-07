<script setup lang="ts">
  import { computed } from "vue";
  import EmptyState from "./EmptyState.vue";
  import LoadingSpinner from "../LoadingSpinner.vue";

  export interface Column<T = any> {
    key: string;
    label: string;
    width?: string;
    sortable?: boolean;
    render?: (value: any, row: T) => string;
  }

  const props = withDefaults(
    defineProps<{
      columns: Column[];
      data: any[];
      loading?: boolean;
      empty?: boolean;
      emptyTitle?: string;
      emptyDescription?: string;
      striped?: boolean;
      hoverable?: boolean;
      class?: string;
    }>(),
    {
      loading: false,
      empty: false,
      emptyTitle: "No data",
      emptyDescription: "No items to display.",
      striped: true,
      hoverable: true,
    }
  );

  const emit = defineEmits<{
    (e: "row-click", row: any): void;
  }>();

  const getCellValue = (row: any, column: Column) => {
    if (column.render) {
      return column.render(row[column.key], row);
    }
    return row[column.key];
  };
</script>

<template>
  <div class="w-full rounded-lg border border-border overflow-hidden" :class="class">
    <!-- Loading state -->
    <div v-if="loading" class="flex items-center justify-center p-12">
      <LoadingSpinner label="Loading data..." />
    </div>

    <!-- Empty state -->
    <div v-else-if="empty || data.length === 0" class="p-12">
      <EmptyState
        :title="emptyTitle"
        :description="emptyDescription"
      />
    </div>

    <!-- Table -->
    <table v-else class="w-full text-left text-sm">
      <thead class="border-b border-border bg-secondary dark:bg-card">
        <tr>
          <th
            v-for="column in columns"
            :key="column.key"
            :style="column.width ? { width: column.width } : {}"
            class="px-4 py-3 font-semibold text-foreground"
          >
            {{ column.label }}
            <span v-if="column.sortable" class="ml-1 text-muted-foreground cursor-pointer hover:text-foreground transition">
              ↕
            </span>
          </th>
        </tr>
      </thead>
      <tbody class="divide-y divide-border">
        <tr
          v-for="(row, idx) in data"
          :key="idx"
          :class="[
            'transition',
            striped && idx % 2 === 1 ? 'bg-muted/50' : 'bg-background',
            hoverable ? 'hover:bg-muted/80 cursor-pointer' : '',
          ]"
          @click="emit('row-click', row)"
        >
          <td
            v-for="column in columns"
            :key="column.key"
            :style="column.width ? { width: column.width } : {}"
            class="px-4 py-3 text-foreground"
          >
            {{ getCellValue(row, column) }}
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
