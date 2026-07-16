<script setup lang="ts">
// Gallery: EmptyState — a "nothing here" panel (Data tier).
//
// Shows the three variants (default / search / error), both sizes, the action +
// secondary slots, in-card and page-level usage, light + dark, and the API + a11y.
import EmptyState from '../../ui/data/EmptyState.vue';
import Button from '../../ui/primitives/Button.vue';
import Link from '../../ui/primitives/Link.vue';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const propRows: ApiRow[] = [
  { name: 'variant', type: "'default' | 'search' | 'error'", default: "'default'", description: 'Intent: empty list / no-results / failed load.' },
  { name: 'size', type: "'sm' | 'md'", default: "'md'", description: 'sm for in-table/in-card, md for page-level.' },
  { name: 'title', type: 'string', default: '—', description: 'Primary heading.' },
  { name: 'description', type: 'string', default: '—', description: 'Supporting copy.' },
  { name: 'icon', type: 'IconName', default: 'per variant', description: 'Override the variant icon.' },
];
const slotRows: ApiRow[] = [
  { name: 'action', type: '—', description: 'Primary action — drop a Button (New / Retry / Clear filters).' },
  { name: 'secondary', type: '—', description: 'Optional secondary link below the primary action.' },
];
</script>

<template>
  <StoryPage
    title="EmptyState"
    description="A centered panel for empty lists, no-results, and failed loads: icon bubble + title + description + a primary action slot and an optional secondary link. Two sizes (sm for in-table/in-card, md for page-level)."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>The icon is decorative (<code>aria-hidden</code>); the title is a real heading.</li>
        <li>The <code>error</code> variant uses <code>role="alert"</code> so a failed load is announced.</li>
        <li>Actions are real Buttons / Links passed into the slots — keyboard + focus come for free.</li>
      </ul>
    </template>

    <StorySection title="Variants" description="default (empty list) · search (no results) · error (failed load).">
      <div class="grid gap-next-4 next-md:grid-cols-3">
        <div class="rounded-next-lg border border-next-border bg-next-card">
          <EmptyState
            title="No forms yet"
            description="Create your first form to start collecting responses."
          >
            <template #action>
              <Button leading-icon="plus">New form</Button>
            </template>
          </EmptyState>
        </div>

        <div class="rounded-next-lg border border-next-border bg-next-card">
          <EmptyState
            variant="search"
            title="No results"
            description="No forms match “quarterly”. Try a different search."
          >
            <template #action>
              <Button variant="outline" leading-icon="x">Clear filters</Button>
            </template>
          </EmptyState>
        </div>

        <div class="rounded-next-lg border border-next-border bg-next-card">
          <EmptyState
            variant="error"
            title="Couldn’t load forms"
            description="Something went wrong while fetching. Check your connection and retry."
          >
            <template #action>
              <Button variant="outline" leading-icon="arrow-right">Retry</Button>
            </template>
          </EmptyState>
        </div>
      </div>
    </StorySection>

    <StorySection title="Sizes" description="sm (in-table / in-card) · md (page-level).">
      <div class="grid gap-next-4 next-md:grid-cols-2">
        <div class="rounded-next-lg border border-next-border bg-next-card">
          <EmptyState
            size="sm"
            icon="users"
            title="No members"
            description="Invite teammates to collaborate."
          >
            <template #action>
              <Button size="sm" leading-icon="plus">Invite</Button>
            </template>
          </EmptyState>
        </div>
        <div class="rounded-next-lg border border-next-border bg-next-card">
          <EmptyState
            size="md"
            title="Your inbox is empty"
            description="When you receive messages they’ll show up here."
          >
            <template #action>
              <Button>Compose</Button>
            </template>
            <template #secondary>
              <Link href="#docs">Learn about the inbox</Link>
            </template>
          </EmptyState>
        </div>
      </div>
    </StorySection>

    <StorySection title="Light + dark">
      <div class="grid gap-next-4 sm:grid-cols-2">
        <div class="next-root rounded-next-lg border border-next-border bg-next-card p-next-1">
          <EmptyState variant="search" size="sm" title="No results" description="Adjust your filters." />
        </div>
        <div class="next-root dark rounded-next-lg border border-next-border bg-next-card p-next-1">
          <EmptyState variant="error" size="sm" title="Load failed" description="Retry the request." />
        </div>
      </div>
    </StorySection>

    <StorySection title="API">
      <div class="flex flex-col gap-next-6">
        <ApiTable title="Props" :rows="propRows" show-default />
        <ApiTable title="Slots" type-header="Scope" :rows="slotRows" />
      </div>
    </StorySection>
  </StoryPage>
</template>
