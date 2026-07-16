<script setup lang="ts">
// Gallery: Skeleton — the loading-placeholder primitive (Data tier).
//
// Shows every variant/size, a light + dark pair, a composed "card skeleton", an
// "option list" skeleton (the exact shape Select renders while loading async
// options), the two permanent usage rules, a props table, and a11y notes.
import Skeleton from '../../ui/data/Skeleton.vue';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import StoryGrid from '../StoryGrid.vue';
import StoryCell from '../StoryCell.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const propRows: ApiRow[] = [
  { name: 'variant', type: "'text' | 'circle' | 'rect'", default: "'text'", description: 'Shape: line / circle / block.' },
  { name: 'width', type: 'string | number', default: "'100%'", description: 'Width for text/rect (number = px).' },
  { name: 'height', type: 'string | number', default: 'auto', description: 'Height for rect (number = px); text derives it from the line.' },
  { name: 'diameter', type: 'string | number', default: "'2.5rem'", description: 'Diameter for circle (number = px).' },
  { name: 'radius', type: "'none'|'sm'|'md'|'lg'|'xl'|'full'", default: "'md'", description: 'Corner radius token for rect.' },
  { name: 'count', type: 'number', default: '1', description: 'Repeat the shape N times in a vertical stack.' },
  { name: 'label', type: 'string', default: '—', description: 'Turns the wrapper into a labelled live region (shapes stay decorative).' },
  { name: 'role', type: "'status' | 'alert' | 'none'", default: "'status'", description: 'Region role when label is set.' },
];
</script>

<template>
  <StoryPage
    title="Skeleton"
    description="A token-driven, subtly pulsing loading placeholder that graphically mimics the element it stands in for. Three variants (text / circle / rect), composable into item-shaped layouts. Honors reduced motion."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>Individual shapes are <code>aria-hidden</code> — they are pure decoration.</li>
        <li>For a loading <strong>region</strong>, pass <code>label</code> so the wrapper becomes a single polite <code>role="status"</code> live region (e.g. "Loading options…") instead of silence.</li>
        <li>The pulse respects <code>prefers-reduced-motion</code> — the global <code>.next-root</code> rule collapses it (with a local fallback).</li>
      </ul>
    </template>

    <StorySection title="Usage rules" description="These are permanent design-system rules.">
      <ul class="ml-next-4 list-disc space-y-next-2 text-next-sm">
        <li>
          <strong>Skeletons replace Spinner + “Loading…” for cursor-pagination loading.</strong>
          Async lists (e.g. <code>Select</code>) render option-row-shaped skeletons for the
          initial load AND the “loading more” row — not a spinner.
        </li>
        <li>
          <strong>Every skeleton must mimic the element it replaces, and usually several are shown.</strong>
          Compose skeletons into the item’s real layout (icon circle + text line, card,
          table row), and use <code>count</code> to repeat the shape.
        </li>
      </ul>
    </StorySection>

    <StorySection title="Variants" description="text (line, rounded ends) · circle (diameter) · rect (w×h, radius).">
      <StoryGrid align="center">
        <StoryCell label="text">
          <div class="w-48"><Skeleton variant="text" /></div>
        </StoryCell>
        <StoryCell label="text (60%)">
          <div class="w-48"><Skeleton variant="text" width="60%" /></div>
        </StoryCell>
        <StoryCell label="circle">
          <Skeleton variant="circle" />
        </StoryCell>
        <StoryCell label="circle (sm)">
          <Skeleton variant="circle" diameter="1.25rem" />
        </StoryCell>
        <StoryCell label="rect">
          <Skeleton variant="rect" :width="120" :height="72" />
        </StoryCell>
        <StoryCell label="rect (full radius)">
          <Skeleton variant="rect" :width="120" :height="40" radius="full" />
        </StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection title="Repeated lines (count)" description="The common multi-line text placeholder. Pass count to repeat the shape.">
      <div class="max-w-md">
        <Skeleton variant="text" :count="4" />
      </div>
    </StorySection>

    <StorySection title="Light + dark" description="Token-driven tint works on both themes. The right panel is a .next-root.dark island.">
      <div class="grid gap-next-4 sm:grid-cols-2">
        <div class="next-root rounded-next-lg border border-next-border bg-next-card p-next-4">
          <p class="mb-next-2 text-next-xs text-next-muted-foreground">light</p>
          <div class="flex items-center gap-next-3">
            <Skeleton variant="circle" diameter="2.5rem" />
            <div class="flex-1 space-y-next-2">
              <Skeleton variant="text" width="70%" />
              <Skeleton variant="text" width="40%" />
            </div>
          </div>
        </div>
        <div class="next-root dark rounded-next-lg border border-next-border bg-next-card p-next-4">
          <p class="mb-next-2 text-next-xs text-next-muted-foreground">dark</p>
          <div class="flex items-center gap-next-3">
            <Skeleton variant="circle" diameter="2.5rem" />
            <div class="flex-1 space-y-next-2">
              <Skeleton variant="text" width="70%" />
              <Skeleton variant="text" width="40%" />
            </div>
          </div>
        </div>
      </div>
    </StorySection>

    <StorySection title="Composed: card skeleton" description="Mimic the real card geometry — header strip, title, body lines, footer.">
      <div class="max-w-sm rounded-next-lg border border-next-border bg-next-card p-next-4">
        <Skeleton variant="rect" :height="120" radius="lg" />
        <div class="mt-next-4 space-y-next-2">
          <Skeleton variant="text" width="80%" height="1rem" />
          <Skeleton variant="text" />
          <Skeleton variant="text" width="90%" />
        </div>
        <div class="mt-next-4 flex items-center gap-next-3">
          <Skeleton variant="circle" diameter="2rem" />
          <Skeleton variant="text" width="35%" />
        </div>
      </div>
    </StorySection>

    <StorySection title="Composed: option list (Select async)" description="The exact shape Select renders while loading async options: an icon circle + a text line per row, several rows.">
      <div class="max-w-sm rounded-next-md border border-next-border bg-next-popover p-next-1" role="status" aria-label="Loading options…">
        <div
          v-for="n in 5"
          :key="n"
          class="mx-next-1 flex items-center gap-next-2 rounded-next-sm px-next-2 py-next-1_5"
          aria-hidden="true"
        >
          <Skeleton variant="circle" diameter="1rem" />
          <Skeleton variant="text" :width="`${55 + ((n * 13) % 35)}%`" />
        </div>
      </div>
    </StorySection>

    <StorySection title="Labelled region" description="Pass label to announce a single polite 'Loading…' for the whole region.">
      <div class="max-w-md">
        <Skeleton label="Loading dashboard…" variant="text" :count="3" />
      </div>
    </StorySection>

    <StorySection title="API">
      <ApiTable title="Props" :rows="propRows" show-default />
    </StorySection>
  </StoryPage>
</template>
