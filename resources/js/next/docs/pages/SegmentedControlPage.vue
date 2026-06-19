<script setup lang="ts">
// Gallery: SegmentedControl — sizes, equal-width, icon + icon-only, disabled
// option / whole control, and a note on Segmented vs Tabs. All text via t().
import { ref } from 'vue';
import SegmentedControl, { type SegmentOption } from '../../ui/forms/SegmentedControl.vue';
import { useI18n } from '../../app/i18n';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const { t } = useI18n();

const viewOptions: SegmentOption[] = [
  { value: 'list', label: t('story.seg.list', 'List') },
  { value: 'board', label: t('story.seg.board', 'Board') },
  { value: 'calendar', label: t('story.seg.calendar', 'Calendar') },
];
const view = ref<string | null>('list');

const filterOptions: SegmentOption[] = [
  { value: 'all', label: t('story.seg.all', 'All') },
  { value: 'active', label: t('story.seg.active', 'Active') },
  { value: 'done', label: t('story.seg.done', 'Done'), disabled: true },
];
const filter = ref<string | null>('active');

const iconOptions: SegmentOption[] = [
  { value: 'grid', label: t('story.seg.grid', 'Grid'), icon: 'layout-dashboard' },
  { value: 'rows', label: t('story.seg.rows', 'Rows'), icon: 'list' },
];
const iconView = ref<string | null>('grid');

const iconOnlyOptions: SegmentOption[] = [
  { value: 'light', label: t('story.seg.light', 'Light theme'), icon: 'sun' },
  { value: 'dark', label: t('story.seg.dark', 'Dark theme'), icon: 'moon' },
];
const theme = ref<string | null>('light');

const equalView = ref<string | null>('board');
const smView = ref<string | null>('list');
const disabledView = ref<string | null>('board');

const propRows: ApiRow[] = [
  { name: 'options', type: 'SegmentOption[]', default: '—', description: '{ value, label, icon?, disabled? } per segment.' },
  { name: 'v-model', type: 'string | null', default: 'null', description: 'Selected value (falls back to first enabled).' },
  { name: 'size', type: "'sm' | 'md'", default: "'md'", description: 'Control scale.' },
  { name: 'equalWidth', type: 'boolean', default: 'false', description: 'Each segment takes an equal share (full-width track).' },
  { name: 'iconOnly', type: 'boolean', default: 'false', description: 'Hide labels (label becomes the aria-label).' },
  { name: 'disabled', type: 'boolean', default: 'false', description: 'Disable the whole control.' },
  { name: 'ariaLabel', type: 'string', default: '—', description: 'Accessible label for the radiogroup.' },
];
</script>

<template>
  <StoryPage
    title="Segmented control"
    :description="t('story.seg.desc', 'A compact, mutually-exclusive single-select toggle for view / filter switching (List/Board, All/Active). It is a CHOICE (role=radiogroup), distinct from Tabs which switch panels (role=tablist).')"
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>{{ t('story.seg.a11y1', 'role="radiogroup" with role="radio" options + aria-checked; roving tabindex (only the selected option is a tab stop).') }}</li>
        <li>{{ t('story.seg.a11y2', '←/↑ and →/↓ move selection (skip disabled, wrap); Home/End jump; Space/Enter (re)select.') }}</li>
        <li>{{ t('story.seg.a11y3', 'Selected segment gets a card surface + shadow + medium weight — never color alone.') }}</li>
      </ul>
    </template>

    <StorySection :title="t('story.seg.vsTabs', 'Segmented vs Tabs')">
      <p class="text-next-sm text-next-muted-foreground">
        {{ t('story.seg.vsTabsBody', 'Use a SegmentedControl to pick one value among a few (a filter / view mode). Use Tabs when each choice reveals a different panel of content.') }}
      </p>
    </StorySection>

    <StorySection :title="t('story.seg.basic', 'Default')">
      <SegmentedControl v-model="view" :options="viewOptions" :aria-label="t('story.seg.viewMode', 'View mode')" />
      <p class="mt-next-3 font-next-mono text-next-xs text-next-muted-foreground">value: {{ view }}</p>
    </StorySection>

    <StorySection :title="t('story.seg.sizes', 'Sizes')">
      <div class="flex flex-col items-start gap-next-4">
        <SegmentedControl v-model="smView" :options="viewOptions" size="sm" :aria-label="t('story.seg.viewMode', 'View mode')" />
        <SegmentedControl v-model="view" :options="viewOptions" size="md" :aria-label="t('story.seg.viewMode', 'View mode')" />
      </div>
    </StorySection>

    <StorySection :title="t('story.seg.withIcons', 'With icons')">
      <SegmentedControl v-model="iconView" :options="iconOptions" :aria-label="t('story.seg.layout', 'Layout')" />
    </StorySection>

    <StorySection :title="t('story.seg.iconOnly', 'Icon only')" :description="t('story.seg.iconOnlyDesc', 'Each option still carries its label as an aria-label.')">
      <SegmentedControl v-model="theme" :options="iconOnlyOptions" icon-only :aria-label="t('story.seg.themeMode', 'Theme')" />
    </StorySection>

    <StorySection :title="t('story.seg.equal', 'Equal width (full-width track)')">
      <SegmentedControl v-model="equalView" :options="viewOptions" equal-width :aria-label="t('story.seg.viewMode', 'View mode')" />
    </StorySection>

    <StorySection :title="t('story.seg.disabled', 'Disabled states')">
      <div class="flex flex-col items-start gap-next-4">
        <SegmentedControl v-model="filter" :options="filterOptions" :aria-label="t('story.seg.statusFilter', 'Status filter')" />
        <SegmentedControl v-model="disabledView" :options="viewOptions" disabled :aria-label="t('story.seg.viewMode', 'View mode')" />
      </div>
    </StorySection>

    <StorySection title="API">
      <ApiTable title="Props" :rows="propRows" show-default />
    </StorySection>
  </StoryPage>
</template>
