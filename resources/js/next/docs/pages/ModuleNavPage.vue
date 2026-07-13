<script setup lang="ts">
// Gallery: Module navigation — ModuleAside (the ≥ next-lg two-level module
// sub-nav) and ModuleTabs (its below-next-lg fallback). The aside always shows
// the MODULE block (+ module-level pages); modules with resource-scoped pages
// add a RESOURCE section — a muted pick-one placeholder linking to the list, or
// the selected resource's identity promoted to the top. One shared item list +
// activeMatch closure drives aside and tabs alike, exactly like the module
// layouts do. All text via t().
//
// The demo links point back at this gallery page (same route + story query) so
// clicking them never leaves the story.
import ModuleAside, { type ModuleNavItem } from '../../ui/layout/ModuleAside.vue';
import ModuleTabs from '../../ui/layout/ModuleTabs.vue';
import Badge from '../../ui/primitives/Badge.vue';
import { useI18n } from '../../app/i18n';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const { t } = useI18n();

// Self-referencing target: stays on this story when a demo link is clicked.
const SELF = { path: '/_styleguide', query: { story: 'layout-module-navigation' } };

const MODULE_ITEMS: ModuleNavItem[] = [
  { key: 'list', label: t('story.moduleNav.all', 'All bots'), icon: 'sparkles', to: SELF },
];
const RESOURCE_ITEMS: ModuleNavItem[] = [
  { key: 'inbox', label: t('story.moduleNav.inbox', 'Inbox'), icon: 'inbox', to: SELF },
  { key: 'activity', label: t('story.moduleNav.activity', 'Activity'), icon: 'clock', to: SELF },
  { key: 'config', label: t('story.moduleNav.config', 'Configuration'), icon: 'settings', to: SELF },
];

const isActivityActive = (item: ModuleNavItem) => item.key === 'activity';
const isListActive = (item: ModuleNavItem) => item.key === 'list';

const asideProps: ApiRow[] = [
  { name: 'moduleIcon / moduleTitle / moduleHint', type: 'IconName / string / string', default: '—', description: 'The always-visible MODULE block: what the whole module is for.' },
  { name: 'moduleItems', type: 'ModuleNavItem[]', default: '—', description: 'Module-level pages (no resource required). { key, label, icon, to?, soon? }.' },
  { name: 'resourceItems', type: 'ModuleNavItem[]', default: '—', description: 'Resource-scoped pages; omit for modules without them (Approvals).' },
  { name: 'resource', type: '{ icon, name, description? } | null', default: 'null', description: 'The open resource — presence promotes the tinted selected block (+ live nav) to the TOP.' },
  { name: 'resourcePlaceholder', type: '{ icon, label, hint, to }', default: '—', description: 'The empty resource slot: muted + dashed, LINKS to the list where a resource is picked.' },
  { name: 'resourceBack', type: '{ label, to }', default: '—', description: 'Back-to-list icon button in the selected block (label = accessible name).' },
  { name: 'activeMatch', type: '(item) => boolean', default: '—', description: 'Decides the active item (route matching differs per module).' },
  { name: 'moduleNavLabel / resourceNavLabel', type: 'string', default: 'common.moduleNav', description: 'Accessible labels of the two navs (e.g. "Bot sections").' },
];
const asideSlots: ApiRow[] = [
  { name: 'resource-meta', type: '—', description: 'Status line inside the selected block (StatusBadge / enabled-draft).' },
];
const tabsProps: ApiRow[] = [
  { name: 'items', type: 'ModuleNavItem[]', default: '—', description: 'ONE row: the resource items when a resource is open, else the module items.' },
  { name: 'activeMatch', type: '(item) => boolean', default: '—', description: 'Same closure the aside uses; selecting a tab pushes its route.' },
  { name: 'ariaLabel', type: 'string', default: 'common.moduleNav', description: 'Accessible label of the tablist.' },
];
</script>

<template>
  <StoryPage
    title="Module navigation"
    :description="t('story.moduleNav.desc', 'The shared two-level module section nav: ModuleAside (≥ next-lg) shows the module block + module pages, and — for modules with resource-scoped pages — a pick-one placeholder that links to the list, or the selected resource promoted to the top. ModuleTabs renders one row of the same items below the breakpoint.')"
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>{{ t('story.moduleNav.a11y1', 'Both navs are labelled <nav> elements; the active link carries aria-current="page".') }}</li>
        <li>{{ t('story.moduleNav.a11y2', 'The disabled section preview under the placeholder is decorative (aria-hidden); the placeholder itself is a real link to the list.') }}</li>
        <li>{{ t('story.moduleNav.a11y3', 'ModuleTabs reuses Tabs (underline) in nav-only mode: no empty tabpanels, arrows move + select, selection navigates.') }}</li>
        <li>{{ t('story.moduleNav.a11y4', 'Underline / subtle tint mean NAVIGATION (D3) — solid pills stay reserved for data-scope filters.') }}</li>
      </ul>
    </template>

    <StorySection :title="t('story.moduleNav.empty', 'No resource selected (placeholder links to the list; preview is decorative)')">
      <!-- Force the aside visible inside the story regardless of viewport. -->
      <ModuleAside
        module-icon="sparkles"
        :module-title="t('story.moduleNav.title', 'Bots')"
        :module-hint="t('story.moduleNav.hint', 'Define the AI characters your workspace uses.')"
        :module-items="MODULE_ITEMS"
        :resource-items="RESOURCE_ITEMS"
        :resource-placeholder="{
          icon: 'sparkles',
          label: t('story.moduleNav.pick', 'Select a bot'),
          hint: t('story.moduleNav.pickHint', 'Choose one from the list to open its sections.'),
          to: SELF,
        }"
        :active-match="isListActive"
        class="!flex w-64"
      />
    </StorySection>

    <StorySection :title="t('story.moduleNav.selected', 'Resource selected (identity block promoted above the module block)')">
      <ModuleAside
        module-icon="sparkles"
        :module-title="t('story.moduleNav.title', 'Bots')"
        :module-hint="t('story.moduleNav.hint', 'Define the AI characters your workspace uses.')"
        :module-items="MODULE_ITEMS"
        :resource-items="RESOURCE_ITEMS"
        :resource="{
          icon: 'sparkles',
          name: t('story.moduleNav.resourceName', 'Support copilot'),
          description: t('story.moduleNav.resourceDesc', 'Answers inbound questions and files follow-up tasks.'),
        }"
        :resource-back="{ label: t('story.moduleNav.all', 'All bots'), to: SELF }"
        :resource-nav-label="t('story.moduleNav.resourceNav', 'Bot sections')"
        :active-match="isActivityActive"
        class="!flex w-64"
      >
        <template #resource-meta>
          <Badge variant="success" tone="subtle" size="sm" icon="check-circle">
            {{ t('story.moduleNav.active', 'Active') }}
          </Badge>
        </template>
      </ModuleAside>
    </StorySection>

    <StorySection :title="t('story.moduleNav.tabs', 'ModuleTabs (the small-screen fallback — one row)')">
      <!-- Force visible inside the story regardless of viewport. -->
      <ModuleTabs :items="RESOURCE_ITEMS" :active-match="isActivityActive" class="!block" />
    </StorySection>

    <StorySection :title="t('story.moduleNav.apiAside', 'ModuleAside API')">
      <ApiTable :rows="asideProps" />
      <ApiTable :rows="asideSlots" />
    </StorySection>

    <StorySection :title="t('story.moduleNav.apiTabs', 'ModuleTabs API')">
      <ApiTable :rows="tabsProps" />
    </StorySection>
  </StoryPage>
</template>
