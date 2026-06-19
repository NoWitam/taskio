<script setup lang="ts">
import Spinner from '../../ui/primitives/Spinner.vue';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import StoryGrid from '../StoryGrid.vue';
import StoryCell from '../StoryCell.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const sizes = ['xs', 'sm', 'md', 'lg'] as const;

const propRows: ApiRow[] = [
  { name: 'size', type: "'xs' | 'sm' | 'md' | 'lg'", default: "'md'", description: 'Spinner diameter (driven by font-size).' },
  { name: 'tone', type: "'current' | 'primary' | 'muted' | 'on-overlay'", default: "'current'", description: 'Color source. `current` inherits the text color.' },
  { name: 'label', type: 'string', default: "'Loading…'", description: 'Accessible status text (visually-hidden unless showLabel).' },
  { name: 'showLabel', type: 'boolean', default: 'false', description: 'Render the label visibly beside the spinner.' },
  { name: 'decorative', type: 'boolean', default: 'false', description: 'Drop role/label when a parent already announces busy (e.g. a button).' },
];
</script>

<template>
  <StoryPage
    title="Spinner"
    description="An indeterminate loading indicator. Four sizes, color tones, optional visible label, and an overlay-centered usage. Honors reduced motion."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>Uses <code>role="status"</code> with a visually-hidden label (default “Loading…”) so screen readers announce it.</li>
        <li>The spin animation respects <code>prefers-reduced-motion</code> — the global rule in <code>next.css</code> collapses it.</li>
        <li>When nested inside a control that already sets <code>aria-busy</code> (e.g. Button), pass <code>decorative</code> to avoid a duplicate status announcement.</li>
      </ul>
    </template>

    <StorySection title="Sizes">
      <StoryGrid align="center">
        <StoryCell v-for="s in sizes" :key="s" :label="s">
          <Spinner :size="s" />
        </StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection title="Tones">
      <StoryGrid align="center">
        <StoryCell label="current (text)">
          <span class="text-next-fg"><Spinner size="lg" tone="current" /></span>
        </StoryCell>
        <StoryCell label="primary"><Spinner size="lg" tone="primary" /></StoryCell>
        <StoryCell label="muted"><Spinner size="lg" tone="muted" /></StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection title="On overlay" description="The on-overlay tone uses the primary-foreground color so the spinner reads on a colored/dimmed scrim.">
      <div
        class="flex h-32 items-center justify-center rounded-next-lg"
        style="background-color: var(--color-next-overlay)"
      >
        <Spinner size="lg" tone="on-overlay" show-label label="Uploading…" />
      </div>
    </StorySection>

    <StorySection title="With label">
      <StoryGrid align="center">
        <StoryCell label="inline label"><Spinner show-label label="Loading responses…" /></StoryCell>
        <StoryCell label="primary + label"><Spinner tone="primary" show-label label="Saving…" /></StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection title="Centered in a container" description="The common ‘loading panel’ pattern.">
      <div class="flex h-40 items-center justify-center rounded-next-lg border border-next-border bg-next-bg">
        <Spinner size="lg" tone="muted" show-label label="Loading dashboard…" />
      </div>
    </StorySection>

    <StorySection title="API">
      <ApiTable title="Props" :rows="propRows" show-default />
    </StorySection>
  </StoryPage>
</template>
