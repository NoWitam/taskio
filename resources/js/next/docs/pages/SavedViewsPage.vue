<script setup lang="ts">
// Gallery: Saved Views — FilterTabBar + SaveViewModal + chip trit-state (Patterns tier).
//
// Demonstrates the complete Saved Views composition: FilterTabBar in all its
// states (empty, loading, error, pills, active+dirty), the chip trit-state
// (tab-active / extra / tab-disabled) via a mock FilterBar, and SaveViewModal
// in create + edit modes.
//
// This is a static gallery with controlled props — not a live API demo.
import { ref } from 'vue';
import FilterTabBar from '../../ui/patterns/FilterTabBar.vue';
import FilterBar from '../../ui/patterns/FilterBar.vue';
import SaveViewModal from '../../ui/patterns/SaveViewModal.vue';
import Button from '../../ui/primitives/Button.vue';
import Badge from '../../ui/primitives/Badge.vue';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';
import type { FilterTab } from '../../app/stores/filterTabs';
import type { ActiveFilter } from '../../ui/patterns/FilterBar.vue';

// --- Sample data ------------------------------------------------------------
const SAMPLE_TABS: FilterTab[] = [
  { id: '1', name: 'High priority', icon: 'flag', filters: {}, sort_order: 0 },
  { id: '2', name: 'Mine this week', icon: 'calendar', filters: {}, sort_order: 1 },
  { id: '3', name: 'Overdue', icon: 'alert-triangle', filters: {}, sort_order: 2 },
];

const activeTabId = ref<string | null>(null);
const dirty = ref(false);

function activate(tab: FilterTab) {
  activeTabId.value = String(tab.id);
  dirty.value = false;
}

// --- Trit-state demo --------------------------------------------------------
const tritFilters: ActiveFilter[] = [
  { key: 'priority', label: 'Priority: High', tabState: 'tab-active' },
  { key: 'search',   label: 'Search: refactor', tabState: 'extra' },
  { key: 'date_from', label: 'From: 01.06.2026', tabState: 'tab-disabled' },
];

// --- SaveViewModal ----------------------------------------------------------
const modalOpen = ref(false);
const modalMode = ref<'create' | 'edit'>('create');

// --- API tables -------------------------------------------------------------
const filterTabBarProps: ApiRow[] = [
  { name: 'tabs', type: 'FilterTab[]', default: '—', description: 'Ordered saved views for this context.' },
  { name: 'activeTabId', type: 'string | number | null', default: '—', description: 'Active view ID, or null.' },
  { name: 'dirty', type: 'boolean', default: 'false', description: 'Shows "Modified" badge on the active pill.' },
  { name: 'loading', type: 'boolean', default: 'false', description: 'Pill skeleton loading state.' },
  { name: 'error', type: 'boolean', default: 'false', description: 'Inline error + retry state.' },
  { name: 'hasActiveFilters', type: 'boolean', default: 'false', description: 'Enables the "Save as" button.' },
  { name: 'busy', type: 'boolean', default: 'false', description: 'Disables Save/Save-as while saving.' },
];

const filterTabBarEvents: ApiRow[] = [
  { name: 'activate', type: 'FilterTab', default: '—', description: 'User clicks a pill.' },
  { name: 'save', type: '—', default: '—', description: 'Save (overwrite active dirty view).' },
  { name: 'save-as', type: '—', default: '—', description: 'Open Save as modal.' },
  { name: 'edit', type: 'FilterTab', default: '—', description: 'Kebab → Edit.' },
  { name: 'delete', type: 'FilterTab', default: '—', description: 'Kebab → Delete.' },
  { name: 'move-up', type: 'FilterTab', default: '—', description: 'Kebab → Move up.' },
  { name: 'move-down', type: 'FilterTab', default: '—', description: 'Kebab → Move down.' },
  { name: 'retry', type: '—', default: '—', description: 'Retry loading.' },
];

const saveViewProps: ApiRow[] = [
  { name: 'open (v-model)', type: 'boolean', default: 'false', description: 'Controls modal visibility.' },
  { name: 'mode', type: "'create' | 'edit'", default: "'create'", description: 'Determines title + submit label.' },
  { name: 'initialName', type: 'string', default: "''", description: 'Pre-fills the name (edit mode).' },
  { name: 'initialIcon', type: 'string | null', default: 'null', description: 'Pre-fills the icon.' },
  { name: 'snapshotFrom', type: 'string | null', default: 'null', description: 'Live date_from (shows D1 section).' },
  { name: 'snapshotTo', type: 'string | null', default: 'null', description: 'Live date_to (shows D1 section).' },
  { name: 'submitting', type: 'boolean', default: 'false', description: 'Disables submit + spinner.' },
  { name: 'nameError', type: 'string | null', default: 'null', description: 'i18n key for inline name error (422).' },
];
</script>

<template>
  <StoryPage title="Saved Views" description="FilterTabBar + chip trit-state + SaveViewModal (Patterns tier). Used by TasksView. See docs/frontend/saved-views-*.md for the full integration guide.">

    <!-- FilterTabBar states -->
    <StorySection title="FilterTabBar — empty">
      <FilterTabBar
        :tabs="[]"
        :active-tab-id="null"
        :has-active-filters="false"
      />
    </StorySection>

    <StorySection title="FilterTabBar — loading">
      <FilterTabBar
        :tabs="[]"
        :active-tab-id="null"
        loading
      />
    </StorySection>

    <StorySection title="FilterTabBar — error">
      <FilterTabBar
        :tabs="[]"
        :active-tab-id="null"
        error
      />
    </StorySection>

    <StorySection title="FilterTabBar — with views">
      <FilterTabBar
        :tabs="SAMPLE_TABS"
        :active-tab-id="activeTabId"
        :dirty="dirty"
        :has-active-filters="true"
        @activate="activate"
        @save="dirty = false"
        @save-as="() => {}"
        @edit="() => {}"
        @delete="() => {}"
        @move-up="() => {}"
        @move-down="() => {}"
        @retry="() => {}"
      />
      <div class="mt-next-3 flex gap-next-2">
        <Button size="sm" variant="outline" @click="dirty = !dirty">
          Toggle dirty
        </Button>
        <Button size="sm" variant="outline" @click="activeTabId = null">
          Clear active
        </Button>
      </div>
    </StorySection>

    <!-- Chip trit-state -->
    <StorySection title="Chip trit-state (FilterBar tabState)">
      <p class="mb-next-3 text-next-sm text-next-muted-foreground">
        When a saved view is active, each chip is annotated with its relationship
        to the view's snapshot. The bar below shows all three states simultaneously.
      </p>
      <div class="flex flex-col gap-next-2">
        <div class="flex items-center gap-next-3">
          <Badge variant="neutral" tone="subtle" removable>Priority: High</Badge>
          <span class="text-next-xs text-next-muted-foreground">tab-active — in both snapshot and current state</span>
        </div>
        <div class="flex items-center gap-next-3">
          <Badge variant="primary" tone="subtle" icon="plus" removable class="ring-1 ring-next-primary/30">Search: refactor</Badge>
          <span class="text-next-xs text-next-muted-foreground">extra — only in current state (added on top)</span>
        </div>
        <div class="flex items-center gap-next-3">
          <Badge
            variant="neutral"
            tone="subtle"
            class="border border-dashed border-next-border line-through opacity-70"
            :trailing-action="{ icon: 'rotate-ccw', label: 'Restore filter: From date' }"
          >
            From: 01.06.2026
          </Badge>
          <span class="text-next-xs text-next-muted-foreground">tab-disabled — in snapshot, removed from current state; rotate-ccw restores</span>
        </div>
      </div>
      <div class="mt-next-4">
        <FilterBar
          :active-filters="tritFilters"
          :searchable="false"
          aria-label="Trit-state demo"
          @remove-filter="() => {}"
          @restore-filter="() => {}"
        />
      </div>
    </StorySection>

    <!-- SaveViewModal -->
    <StorySection title="SaveViewModal — create">
      <Button leading-icon="plus" @click="() => { modalMode = 'create'; modalOpen = true; }">
        Open Save as modal
      </Button>
      <SaveViewModal
        v-model:open="modalOpen"
        :mode="modalMode"
        :initial-name="modalMode === 'edit' ? 'High priority' : ''"
        :initial-icon="modalMode === 'edit' ? 'flag' : null"
        :snapshot-from="'2026-06-20'"
        @submit="modalOpen = false"
      />
    </StorySection>

    <StorySection title="SaveViewModal — edit">
      <Button leading-icon="pencil" @click="() => { modalMode = 'edit'; modalOpen = true; }">
        Open Edit modal
      </Button>
    </StorySection>

    <!-- API tables -->
    <StorySection title="FilterTabBar — Props">
      <ApiTable title="FilterTabBar props" :rows="filterTabBarProps" :show-default="true" />
    </StorySection>

    <StorySection title="FilterTabBar — Events">
      <ApiTable title="FilterTabBar events" type-header="Payload" :rows="filterTabBarEvents" />
    </StorySection>

    <StorySection title="SaveViewModal — Props">
      <ApiTable title="SaveViewModal props" :rows="saveViewProps" :show-default="true" />
    </StorySection>

  </StoryPage>
</template>
