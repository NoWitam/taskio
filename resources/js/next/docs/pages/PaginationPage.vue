<script setup lang="ts">
// Gallery: Pagination — classic numbered page navigation (Navigation tier).
//
// Shows windowing (siblingCount / boundaryCount), edge-disabled prev/next, the
// compact summary, the per-page-size Select, sizes, light + dark, and the API +
// a11y. Notes that cursor pagination uses infinite scroll + skeletons instead.
import { ref } from 'vue';
import Pagination from '../../ui/navigation/Pagination.vue';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import StoryGrid from '../StoryGrid.vue';
import StoryCell from '../StoryCell.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const page = ref(5);
const pageEdge = ref(1);
const pageSummary = ref(2);
const pageSize = ref(20);
const composedPage = ref(3);

const propRows: ApiRow[] = [
  { name: 'pageCount', type: 'number', default: '—', description: 'Total number of pages.' },
  { name: 'v-model', type: 'number', default: '1', description: 'Current page (1-based).' },
  { name: 'siblingCount', type: 'number', default: '1', description: 'Pages shown either side of the current page.' },
  { name: 'boundaryCount', type: 'number', default: '1', description: 'Pages pinned at each end.' },
  { name: 'size', type: "'sm' | 'md'", default: "'md'", description: 'Control scale.' },
  { name: 'disabled', type: 'boolean', default: 'false', description: 'Disable the whole control.' },
  { name: 'total', type: 'number', default: '—', description: 'Total item count (for the summary).' },
  { name: 'pageSize', type: 'number', default: '—', description: 'Items per page (summary range + Select value).' },
  { name: 'showSummary', type: 'boolean', default: 'false', description: 'Render the "X–Y of N" summary.' },
  { name: 'pageSizes', type: 'number[]', default: '—', description: 'When set, render a per-page-size Select.' },
  { name: 'prevLabel / nextLabel', type: 'string', default: "'Poprzednia' / 'Następna'", description: 'i18n labels for prev/next.' },
  { name: 'summaryLabel', type: '({from,to,total}) => string', default: "'…–… z …'", description: 'Custom summary formatter.' },
  { name: 'pageSizeLabel', type: 'string', default: "'Na stronę'", description: 'Label before the page-size Select.' },
];
const eventRows: ApiRow[] = [
  { name: 'update:modelValue', type: 'number', description: 'New current page.' },
  { name: 'update:pageSize', type: 'number', description: 'New page size from the Select.' },
];
</script>

<template>
  <StoryPage
    title="Pagination"
    description="Classic numbered (offset) page navigation: prev/next + numbered pages with ellipsis windowing, current page marked aria-current, an optional compact summary, and an optional per-page-size selector built on our Select."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li><code>&lt;nav aria-label="Pagination"&gt;</code> of real Buttons; ellipses are inert <code>aria-hidden</code> spans.</li>
        <li>The current page button carries <code>aria-current="page"</code>; prev/next disable at the edges.</li>
        <li>Each page button has an <code>aria-label</code> ("Strona N"); the page-size Select is labelled.</li>
        <li><strong>Cursor</strong> pagination should use infinite scroll + skeletons (see Skeleton rules), not this component.</li>
      </ul>
    </template>

    <StorySection title="Default windowing" description="siblingCount=1, boundaryCount=1 — the window slides as the page changes.">
      <Pagination v-model="page" :page-count="20" />
      <p class="mt-next-2 text-next-sm text-next-muted-foreground">Current page: {{ page }}</p>
    </StorySection>

    <StorySection title="Windowing props" description="More siblings / boundaries show more numbers around the current and ends.">
      <div class="flex flex-col gap-next-4">
        <div>
          <p class="mb-next-1 text-next-xs text-next-muted-foreground">siblingCount=2, boundaryCount=2</p>
          <Pagination v-model="page" :page-count="20" :sibling-count="2" :boundary-count="2" />
        </div>
        <div>
          <p class="mb-next-1 text-next-xs text-next-muted-foreground">siblingCount=0, boundaryCount=1</p>
          <Pagination v-model="page" :page-count="20" :sibling-count="0" :boundary-count="1" />
        </div>
      </div>
    </StorySection>

    <StorySection title="Edge states" description="Prev disables on page 1; Next disables on the last page.">
      <div class="flex flex-col gap-next-4">
        <Pagination v-model="pageEdge" :page-count="5" />
        <Pagination :model-value="5" :page-count="5" />
      </div>
    </StorySection>

    <StorySection title="With summary + page-size selector" description="The summary ('X–Y z N') and a Select-based per-page picker. Changing the size resets nothing here (demo).">
      <Pagination
        v-model="pageSummary"
        :page-count="8"
        :total="154"
        :page-size="pageSize"
        show-summary
        :page-sizes="[10, 20, 50, 100]"
        @update:page-size="(n) => (pageSize = n)"
      />
      <p class="mt-next-2 text-next-sm text-next-muted-foreground">page {{ pageSummary }} · size {{ pageSize }}</p>
    </StorySection>

    <StorySection title="Sizes" description="sm · md.">
      <StoryGrid align="start">
        <StoryCell label="sm">
          <Pagination v-model="page" :page-count="10" size="sm" />
        </StoryCell>
        <StoryCell label="md">
          <Pagination v-model="page" :page-count="10" size="md" />
        </StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection title="English labels" description="prevLabel / nextLabel / summaryLabel are i18n-friendly.">
      <Pagination
        v-model="composedPage"
        :page-count="6"
        :total="120"
        :page-size="20"
        show-summary
        prev-label="Previous"
        next-label="Next"
        :summary-label="({ from, to, total }) => `${from}–${to} of ${total}`"
      />
    </StorySection>

    <StorySection title="Light + dark">
      <div class="grid gap-next-4 sm:grid-cols-2">
        <div class="next-root rounded-next-lg border border-next-border bg-next-bg p-next-4">
          <p class="mb-next-2 text-next-xs text-next-muted-foreground">light</p>
          <Pagination v-model="page" :page-count="12" />
        </div>
        <div class="next-root dark rounded-next-lg border border-next-border bg-next-bg p-next-4">
          <p class="mb-next-2 text-next-xs text-next-muted-foreground">dark</p>
          <Pagination v-model="page" :page-count="12" />
        </div>
      </div>
    </StorySection>

    <StorySection title="API">
      <div class="flex flex-col gap-next-6">
        <ApiTable title="Props" :rows="propRows" show-default />
        <ApiTable title="Events" type-header="Payload" :rows="eventRows" />
      </div>
    </StorySection>
  </StoryPage>
</template>
