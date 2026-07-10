<script setup lang="ts">
// Gallery: SegmentedControl — SELECTION CARDS. Sizes, equal-width, icon +
// icon-only, description, multiple (checkbox cards), columns grid, disabled
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

// Rich cards: icon + label + a description line (a two-option trigger picker).
const richOptions: SegmentOption[] = [
  {
    value: 'form',
    label: t('story.seg.richFormLabel', 'Form submitted'),
    icon: 'file-text',
    description: t('story.seg.richFormDesc', 'When someone submits a chosen form'),
  },
  {
    value: 'schedule',
    label: t('story.seg.richScheduleLabel', 'Schedule'),
    icon: 'calendar',
    description: t('story.seg.richScheduleDesc', 'Run on a recurring schedule'),
  },
];
const richTrigger = ref<string | null>('form');

// Multiple: a checkbox-card group whose model is an array.
const channelOptions: SegmentOption[] = [
  { value: 'email', label: t('story.seg.email', 'Email'), icon: 'mail' },
  { value: 'bell', label: t('story.seg.inApp', 'In-app'), icon: 'bell' },
  { value: 'sms', label: t('story.seg.sms', 'SMS'), icon: 'inbox' },
];
const channels = ref<string[]>(['email', 'bell']);

const equalView = ref<string | null>('board');
const smView = ref<string | null>('list');
const disabledView = ref<string | null>('board');

const propRows: ApiRow[] = [
  { name: 'options', type: 'SegmentOption[]', default: '—', description: '{ value, label, icon?, description?, disabled? } per card.' },
  { name: 'v-model', type: 'string | null | string[]', default: 'null', description: 'Selected value (single). In `multiple` mode it is a string[].' },
  { name: 'size', type: "'sm' | 'md'", default: "'md'", description: 'Card scale (sm = compact tiles, md = comfortable cards).' },
  { name: 'multiple', type: 'boolean', default: 'false', description: 'Checkbox cards; v-model becomes an array (toggle-select).' },
  { name: 'equalWidth', type: 'boolean', default: 'false', description: 'Each card takes an equal share of the row (flex-1).' },
  { name: 'columns', type: 'number', default: '—', description: 'Lay the cards out in a CSS grid with this many columns.' },
  { name: 'iconOnly', type: 'boolean', default: 'false', description: 'Hide labels + the indicator; the card carries selection (label → aria-label).' },
  { name: 'allowNone', type: 'boolean', default: 'false', description: 'Single mode: permit a genuinely empty selection (no fallback).' },
  { name: 'disabled', type: 'boolean', default: 'false', description: 'Disable the whole control.' },
  { name: 'ariaLabel', type: 'string', default: '—', description: 'Accessible label for the group.' },
];
</script>

<template>
  <StoryPage
    title="Segmented control"
    :description="t('story.seg.desc', 'A mutually-exclusive choice among a few options, rendered as SELECTION CARDS (label + optional icon + optional description, with a visible radio/checkbox indicator). It is a CHOICE (role=radiogroup), distinct from Tabs which switch panels (role=tablist). Add `multiple` to turn the cards into a checkbox group.')"
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>{{ t('story.seg.a11y1', 'Single: role="radiogroup" with role="radio" cards + aria-checked; roving tabindex (only the selected card is a tab stop).') }}</li>
        <li>{{ t('story.seg.a11y2', 'Single: ←/↑ and →/↓ move selection (skip disabled, wrap); Home/End jump; Space/Enter (re)select.') }}</li>
        <li>{{ t('story.seg.a11yMulti', 'Multiple: role="group" with role="checkbox" cards; arrows move FOCUS only; Space/Enter toggle the focused card.') }}</li>
        <li>{{ t('story.seg.a11y3', 'The selected card gets a primary border + tinted surface + filled indicator + medium weight — never color alone. The indicator is decorative (aria-hidden); the card role carries the semantics.') }}</li>
      </ul>
    </template>

    <StorySection :title="t('story.seg.vsTabs', 'Segmented vs Tabs')">
      <p class="text-next-sm text-next-muted-foreground">
        {{ t('story.seg.vsTabsBody', 'Use a SegmentedControl to pick one (or, with `multiple`, several) value among a few. Use Tabs when each choice reveals a different panel of content.') }}
      </p>
    </StorySection>

    <StorySection :title="t('story.seg.basic', 'Default')">
      <SegmentedControl v-model="view" :options="viewOptions" :aria-label="t('story.seg.viewMode', 'View mode')" />
      <p class="mt-next-3 font-next-mono text-next-xs text-next-muted-foreground">value: {{ view }}</p>
    </StorySection>

    <StorySection
      :title="t('story.seg.rich', 'Rich cards (icon + description)')"
      :description="t('story.seg.richDesc', 'Options may carry an icon and a description line. Pair with `columns` for a tidy grid — as the workflow trigger picker does.')"
    >
      <SegmentedControl
        v-model="richTrigger"
        :options="richOptions"
        :columns="2"
        :aria-label="t('story.seg.triggerType', 'Trigger type')"
      />
      <p class="mt-next-3 font-next-mono text-next-xs text-next-muted-foreground">value: {{ richTrigger }}</p>
    </StorySection>

    <StorySection
      :title="t('story.seg.multiple', 'Multiple (checkbox cards)')"
      :description="t('story.seg.multipleDesc', 'With `multiple`, each card is a checkbox and the model is an array. Selecting toggles the value.')"
    >
      <SegmentedControl
        v-model="channels"
        :options="channelOptions"
        multiple
        :columns="3"
        :aria-label="t('story.seg.channels', 'Notification channels')"
      />
      <p class="mt-next-3 font-next-mono text-next-xs text-next-muted-foreground">value: {{ channels.join(', ') || '—' }}</p>
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

    <StorySection :title="t('story.seg.iconOnly', 'Icon only')" :description="t('story.seg.iconOnlyDesc', 'The label becomes an aria-label; the card border/tint carries selection (no indicator).')">
      <SegmentedControl v-model="theme" :options="iconOnlyOptions" icon-only :aria-label="t('story.seg.themeMode', 'Theme')" />
    </StorySection>

    <StorySection :title="t('story.seg.equal', 'Equal width')">
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
