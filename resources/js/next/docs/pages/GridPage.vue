<script setup lang="ts">
import Grid from '../../ui/layout/Grid.vue';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const propRows: ApiRow[] = [
  { name: 'cols', type: 'number | { base?, sm?, md?, lg?, xl? }', default: '1', description: 'Column count — a single number, or a per-breakpoint object that cascades up.' },
  { name: 'gap', type: "SpacingKey ('0'…'12')", default: "'4'", description: 'Gap between cells — a `--spacing-next-*` token key.' },
  { name: 'as', type: 'string', default: "'div'", description: 'Rendered element.' },
];

const slotRows: ApiRow[] = [
  { name: 'default', type: 'cells', description: 'Grid items. Span an item across N columns with inline style `grid-column: span N`.' },
];
</script>

<template>
  <StoryPage
    title="Grid"
    description="A small, token-driven responsive CSS grid. `cols` is a single count or a per-breakpoint object; counts cascade up the design-system breakpoints. Keep the API tiny — span items with a one-off `grid-column: span N` style."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>Grid is presentational — visual column order matches DOM (reading/tab) order. Avoid reordering cells visually in a way that diverges from source order.</li>
        <li>For tabular data use a real <code>&lt;table&gt;</code>, not a grid of cells.</li>
      </ul>
    </template>

    <StorySection title="Fixed columns" description="A single number sets the column count at every breakpoint.">
      <div class="flex flex-col gap-next-4">
        <div v-for="c in [2, 3, 4]" :key="c">
          <p class="mb-next-1 font-next-mono text-next-2xs text-next-muted-foreground">:cols="{{ c }}"</p>
          <Grid :cols="c" gap="3">
            <div v-for="n in c * 2" :key="n" class="flex h-12 items-center justify-center rounded-next-md bg-next-primary-subtle text-next-sm font-next-medium text-next-primary-subtle-foreground">
              {{ n }}
            </div>
          </Grid>
        </div>
      </div>
    </StorySection>

    <StorySection title="Responsive columns" description="Resize the window: this grid is 1 column on mobile, 2 at next-sm, 3 at next-md, 4 at next-lg.">
      <Grid :cols="{ base: 1, sm: 2, md: 3, lg: 4 }" gap="4">
        <div v-for="n in 8" :key="n" class="flex h-16 items-center justify-center rounded-next-md bg-next-accent text-next-sm font-next-medium text-next-accent-foreground">
          Card {{ n }}
        </div>
      </Grid>
    </StorySection>

    <StorySection title="Column span" description="Span a cell across multiple tracks with an inline `grid-column: span N` style — kept out of the props API to stay small.">
      <Grid :cols="3" gap="3">
        <div class="flex h-16 items-center justify-center rounded-next-md bg-next-primary-subtle text-next-sm font-next-medium text-next-primary-subtle-foreground" :style="{ gridColumn: 'span 2' }">
          span 2
        </div>
        <div class="flex h-16 items-center justify-center rounded-next-md bg-next-muted text-next-sm">1</div>
        <div class="flex h-16 items-center justify-center rounded-next-md bg-next-muted text-next-sm">1</div>
        <div class="flex h-16 items-center justify-center rounded-next-md bg-next-primary-subtle text-next-sm font-next-medium text-next-primary-subtle-foreground" :style="{ gridColumn: 'span 2' }">
          span 2
        </div>
      </Grid>
    </StorySection>

    <StorySection title="Realistic usage" description="A responsive stat grid: stacks on mobile, fans out on wider screens.">
      <Grid :cols="{ base: 1, sm: 2, lg: 4 }" gap="4">
        <div v-for="stat in (['Forms', 'Submissions', 'Members', 'Active'])" :key="stat" class="rounded-next-lg border border-next-border bg-next-card p-next-4 shadow-next-xs">
          <p class="text-next-2xl font-next-semibold">{{ Math.floor(Math.random() * 900 + 100) }}</p>
          <p class="text-next-sm text-next-muted-foreground">{{ stat }}</p>
        </div>
      </Grid>
    </StorySection>

    <StorySection title="API">
      <div class="flex flex-col gap-next-6">
        <ApiTable title="Props" :rows="propRows" show-default />
        <ApiTable title="Slots" type-header="Content" :rows="slotRows" />
      </div>
    </StorySection>
  </StoryPage>
</template>
