<script setup lang="ts">
// Gallery: PageHeader — breadcrumbs + title + description + actions + tabs,
// leading icon vs avatar, and a minimal variant. All text via t().
import { ref } from 'vue';
import PageHeader from '../../ui/patterns/PageHeader.vue';
import type { BreadcrumbItem } from '../../ui/navigation/Breadcrumbs.vue';
import Button from '../../ui/primitives/Button.vue';
import Avatar from '../../ui/primitives/Avatar.vue';
import Tabs, { type TabItem } from '../../ui/navigation/Tabs.vue';
import { useI18n } from '../../app/i18n';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const { t } = useI18n();

const crumbs: BreadcrumbItem[] = [
  { label: t('story.ph.home', 'Home'), href: '#' },
  { label: t('story.ph.forms', 'Forms'), href: '#' },
  { label: t('story.ph.onboarding', 'Onboarding survey') },
];

const tabItems: TabItem[] = [
  { value: 'overview', label: t('story.ph.overview', 'Overview') },
  { value: 'responses', label: t('story.ph.responses', 'Responses'), badge: 128 },
  { value: 'settings', label: t('story.ph.settings', 'Settings') },
];
const tab = ref<string | null>('overview');

const propRows: ApiRow[] = [
  { name: 'title', type: 'string', default: '—', description: 'Page title (or use #title).' },
  { name: 'description', type: 'string', default: '—', description: 'Sub-heading (or #description).' },
  { name: 'level', type: '1 | 2 | 3', default: '1', description: 'Semantic heading level for the title.' },
  { name: 'breadcrumbs', type: 'BreadcrumbItem[]', default: '—', description: 'Crumbs for the built-in Breadcrumbs (or #breadcrumbs).' },
  { name: 'icon', type: 'IconName', default: '—', description: 'Leading icon in a tinted bubble (or #leading for an Avatar).' },
];
const slotRows: ApiRow[] = [
  { name: 'breadcrumbs', type: '—', description: 'Custom breadcrumb row.' },
  { name: 'leading', type: '—', description: 'Leading visual (e.g. an Avatar).' },
  { name: 'title / description', type: '—', description: 'Title / description content.' },
  { name: 'actions', type: '—', description: 'Trailing action buttons (wrap on small screens).' },
  { name: 'tabs', type: '—', description: 'A Tabs row under the header.' },
];
const eventRows: ApiRow[] = [
  { name: 'breadcrumb-navigate', type: 'BreadcrumbItem', description: 'A breadcrumb was activated (host routes).' },
];
</script>

<template>
  <StoryPage
    title="PageHeader"
    :description="t('story.ph.desc', 'The standard page-top header: an optional breadcrumb row, a leading visual, a title + description, a trailing actions cluster, and an optional tabs row. Responsive — actions stack on small screens.')"
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>{{ t('story.ph.a11y1', 'Renders a real <header> and a single configurable <h1> (level prop).') }}</li>
        <li>{{ t('story.ph.a11y2', 'The leading icon bubble is decorative; an Avatar carries its own label; Breadcrumbs/Tabs keep their own ARIA.') }}</li>
      </ul>
    </template>

    <StorySection :title="t('story.ph.full', 'Full header (breadcrumbs + actions + tabs)')">
      <PageHeader
        :breadcrumbs="crumbs"
        icon="file-text"
        :title="t('story.ph.onboarding', 'Onboarding survey')"
        :description="t('story.ph.fullDesc', 'Collect details from new teammates during their first week.')"
      >
        <template #actions>
          <Button variant="outline" leading-icon="download">{{ t('story.ph.export', 'Export') }}</Button>
          <Button leading-icon="plus">{{ t('story.ph.newResponse', 'New response') }}</Button>
        </template>
        <template #tabs>
          <Tabs v-model="tab" :items="tabItems" :aria-label="t('story.ph.sections', 'Form sections')" />
        </template>
      </PageHeader>
    </StorySection>

    <StorySection :title="t('story.ph.avatar', 'With an avatar (leading slot)')">
      <PageHeader
        :title="t('story.ph.profileTitle', 'Jane Cooper')"
        :description="t('story.ph.profileDesc', 'Product designer · joined March 2024')"
      >
        <template #leading>
          <Avatar name="Jane Cooper" size="lg" status="online" />
        </template>
        <template #actions>
          <Button variant="outline" leading-icon="mail">{{ t('story.ph.message', 'Message') }}</Button>
          <Button variant="ghost" size="icon" aria-label="More" leading-icon="more-horizontal" />
        </template>
      </PageHeader>
    </StorySection>

    <StorySection :title="t('story.ph.minimal', 'Minimal (title only)')">
      <PageHeader :title="t('story.ph.dashboard', 'Dashboard')" />
    </StorySection>

    <StorySection :title="t('story.ph.sub', 'Sub-page (level 2, no actions)')">
      <PageHeader
        :level="2"
        :title="t('story.ph.billing', 'Billing')"
        :description="t('story.ph.billingDesc', 'Manage your plan and payment methods.')"
        icon="settings"
      />
    </StorySection>

    <StorySection title="API">
      <div class="flex flex-col gap-next-6">
        <ApiTable title="Props" :rows="propRows" show-default />
        <ApiTable title="Slots" type-header="Slot props" :rows="slotRows" />
        <ApiTable title="Events" type-header="Payload" :rows="eventRows" />
      </div>
    </StorySection>
  </StoryPage>
</template>
