<script setup lang="ts">
import Stack from '../../ui/layout/Stack.vue';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import StoryGrid from '../StoryGrid.vue';
import StoryCell from '../StoryCell.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const gaps = ['1', '2', '4', '6', '8'] as const;

const propRows: ApiRow[] = [
  { name: 'direction', type: "'vertical' | 'horizontal'", default: "'vertical'", description: 'Main axis: column (vertical) or row (horizontal).' },
  { name: 'gap', type: "SpacingKey ('0'…'16')", default: "'4'", description: 'Gap between children — a `--spacing-next-*` token key.' },
  { name: 'align', type: "'start' | 'center' | 'end' | 'stretch' | 'baseline'", default: '—', description: 'Cross-axis alignment (align-items).' },
  { name: 'justify', type: "'start' | 'center' | 'end' | 'between' | 'around' | 'evenly'", default: '—', description: 'Main-axis distribution (justify-content).' },
  { name: 'wrap', type: 'boolean', default: 'false', description: 'Allow children to wrap onto multiple lines.' },
  { name: 'inline', type: 'boolean', default: 'false', description: 'Use inline-flex instead of flex.' },
  { name: 'as', type: 'string', default: "'div'", description: 'Rendered element.' },
];

const slotRows: ApiRow[] = [
  { name: 'default', type: 'content', description: 'The items to lay out along the axis.' },
];
</script>

<template>
  <StoryPage
    title="Stack"
    description="The workhorse spacing primitive: a flexbox with a token-driven gap plus alignment, justification, and wrap controls. Reach for it instead of ad-hoc flex+gap clusters so spacing stays on the 4px scale."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>Purely presentational; it does not change semantics or focus order — DOM order is the reading + tab order.</li>
        <li>Use a semantic <code>as</code> (e.g. <code>ul</code> + <code>li</code> children) when the list has meaning.</li>
      </ul>
    </template>

    <StorySection title="Gap scale" description="Each value maps to a --spacing-next-* token (e.g. gap=4 → 16px).">
      <div class="flex flex-col gap-next-6">
        <div v-for="g in gaps" :key="g">
          <p class="mb-next-1 font-next-mono text-next-2xs text-next-muted-foreground">gap="{{ g }}"</p>
          <Stack direction="horizontal" :gap="g">
            <div v-for="n in 4" :key="n" class="h-8 w-12 rounded-next-md bg-next-primary-subtle" />
          </Stack>
        </div>
      </div>
    </StorySection>

    <StorySection title="Direction" description="Vertical (column) vs horizontal (row).">
      <StoryGrid align="start">
        <StoryCell label="vertical">
          <Stack direction="vertical" gap="2">
            <div v-for="n in 3" :key="n" class="h-8 w-24 rounded-next-md bg-next-accent" />
          </Stack>
        </StoryCell>
        <StoryCell label="horizontal">
          <Stack direction="horizontal" gap="2">
            <div v-for="n in 3" :key="n" class="h-8 w-12 rounded-next-md bg-next-accent" />
          </Stack>
        </StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection title="Justify (horizontal)" description="Distribution along the main axis in a fixed-width track.">
      <div class="flex flex-col gap-next-4">
        <div v-for="j in (['start', 'center', 'end', 'between', 'around', 'evenly'] as const)" :key="j">
          <p class="mb-next-1 font-next-mono text-next-2xs text-next-muted-foreground">justify="{{ j }}"</p>
          <Stack direction="horizontal" :justify="j" gap="2" class="rounded-next-md bg-next-muted p-next-2">
            <div v-for="n in 3" :key="n" class="h-8 w-12 rounded-next-md bg-next-primary-subtle" />
          </Stack>
        </div>
      </div>
    </StorySection>

    <StorySection title="Align (horizontal)" description="Cross-axis alignment with mixed-height children.">
      <StoryGrid align="start">
        <StoryCell v-for="a in (['start', 'center', 'end', 'stretch'] as const)" :key="a" :label="`align=${a}`">
          <Stack direction="horizontal" :align="a" gap="2" class="h-24 rounded-next-md bg-next-muted p-next-2">
            <div class="h-8 w-8 rounded-next-md bg-next-accent" />
            <div class="h-14 w-8 rounded-next-md bg-next-accent" />
            <div class="h-10 w-8 rounded-next-md bg-next-accent" />
          </Stack>
        </StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection title="Wrap" description="With wrap enabled, children flow onto new lines when the row overflows.">
      <Stack direction="horizontal" gap="2" wrap class="max-w-sm rounded-next-md bg-next-muted p-next-2">
        <div v-for="n in 10" :key="n" class="h-8 w-16 rounded-next-md bg-next-primary-subtle" />
      </Stack>
    </StorySection>

    <StorySection title="Realistic usage" description="A toolbar: title pushed left, actions pushed right via justify=between.">
      <Stack direction="horizontal" justify="between" align="center" class="rounded-next-md border border-next-border bg-next-card p-next-4">
        <h4 class="text-next-lg font-next-semibold">Members</h4>
        <Stack direction="horizontal" gap="2">
          <span class="rounded-next-md bg-next-muted px-next-3 py-next-1_5 text-next-sm">Filter</span>
          <span class="rounded-next-md bg-next-primary px-next-3 py-next-1_5 text-next-sm text-next-primary-foreground">Invite</span>
        </Stack>
      </Stack>
    </StorySection>

    <StorySection title="API">
      <div class="flex flex-col gap-next-6">
        <ApiTable title="Props" :rows="propRows" show-default />
        <ApiTable title="Slots" type-header="Content" :rows="slotRows" />
      </div>
    </StorySection>
  </StoryPage>
</template>
