<script setup lang="ts">
// Renders a props / events / slots reference table for a story page from a
// simple row array, so every component documents its API consistently.
export interface ApiRow {
  name: string;
  type?: string;
  default?: string;
  description: string;
}

defineProps<{
  title: string;
  /** Column header for the "type/payload/content" middle column. */
  typeHeader?: string;
  rows: ApiRow[];
  /** Hide the default column (events/slots don't need it). */
  showDefault?: boolean;
}>();
</script>

<template>
  <div class="flex flex-col gap-next-2">
    <h4 class="text-next-base font-next-semibold">{{ title }}</h4>
    <div class="overflow-x-auto rounded-next-lg border border-next-border">
      <table class="w-full border-collapse text-left text-next-sm">
        <thead>
          <tr class="bg-next-muted text-next-muted-foreground">
            <th class="px-next-3 py-next-2 font-next-medium">Name</th>
            <th class="px-next-3 py-next-2 font-next-medium">{{ typeHeader ?? 'Type' }}</th>
            <th v-if="showDefault" class="px-next-3 py-next-2 font-next-medium">Default</th>
            <th class="px-next-3 py-next-2 font-next-medium">Description</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="row in rows"
            :key="row.name"
            class="border-t border-next-border bg-next-card align-top"
          >
            <td class="px-next-3 py-next-2 font-next-mono text-next-xs text-next-fg">
              {{ row.name }}
            </td>
            <td class="px-next-3 py-next-2 font-next-mono text-next-xs text-next-muted-foreground">
              {{ row.type ?? '—' }}
            </td>
            <td
              v-if="showDefault"
              class="px-next-3 py-next-2 font-next-mono text-next-xs text-next-muted-foreground"
            >
              {{ row.default ?? '—' }}
            </td>
            <td class="px-next-3 py-next-2 text-next-sm text-next-fg">
              {{ row.description }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
