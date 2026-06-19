<script setup lang="ts">
// Gallery: DescriptionList — stacked / horizontal / grid layouts, per-item value
// slots (badges, links), empty values, and sizes. All text via t().
import DescriptionList, { type DescriptionItem } from '../../ui/data/DescriptionList.vue';
import StatusBadge from '../../ui/data/StatusBadge.vue';
import Link from '../../ui/primitives/Link.vue';
import Card from '../../ui/layout/Card.vue';
import { useI18n } from '../../app/i18n';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const { t } = useI18n();

const items: DescriptionItem[] = [
  { key: 'name', label: t('story.dl.name', 'Name'), value: 'Onboarding survey' },
  { key: 'owner', label: t('story.dl.owner', 'Owner'), value: 'Jane Cooper' },
  { key: 'status', label: t('story.dl.status', 'Status') },
  { key: 'created', label: t('story.dl.created', 'Created'), value: '14 Jun 2026' },
  { key: 'archived', label: t('story.dl.archived', 'Archived at'), value: null },
  { key: 'link', label: t('story.dl.publicLink', 'Public link') },
];

const propRows: ApiRow[] = [
  { name: 'items', type: 'DescriptionItem[]', default: '—', description: '{ key, label, value? } pairs.' },
  { name: 'layout', type: "'stacked' | 'horizontal' | 'grid'", default: "'stacked'", description: 'Pair arrangement.' },
  { name: 'columns', type: '1 | 2 | 3 | 4', default: '2', description: 'Columns for `grid` at >= next-md.' },
  { name: 'emptyValue', type: 'string', default: "'—'", description: 'Placeholder for empty/nullish values.' },
  { name: 'size', type: "'sm' | 'md'", default: "'md'", description: 'Text scale.' },
];
const slotRows: ApiRow[] = [
  { name: 'value-<key>', type: '{ item, value }', description: 'Override a single value (badges, links, status).' },
  { name: 'default', type: '—', description: 'Free-form rows appended after the items.' },
];
</script>

<template>
  <StoryPage
    title="DescriptionList"
    :description="t('story.dl.desc', 'Key→value pairs for detail panels, rendered as a proper <dl>/<dt>/<dd>. Stacked, horizontal, or grid layouts; per-item value slots for badges/links; empty values show an em dash.')"
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>{{ t('story.dl.a11y1', 'Semantic <dl> with <dt> labels and <dd> values — read as term/definition pairs.') }}</li>
        <li>{{ t('story.dl.a11y2', 'Empty values render a visible em dash so a row never reads as blank/broken.') }}</li>
      </ul>
    </template>

    <StorySection :title="t('story.dl.stacked', 'Stacked (default)')">
      <Card>
        <DescriptionList :items="items">
          <template #value-status>
            <StatusBadge status="active" />
          </template>
          <template #value-link>
            <Link href="#" variant="standalone">forms.example.com/onboarding</Link>
          </template>
        </DescriptionList>
      </Card>
    </StorySection>

    <StorySection :title="t('story.dl.horizontal', 'Horizontal')">
      <Card>
        <DescriptionList :items="items" layout="horizontal">
          <template #value-status>
            <StatusBadge status="active" />
          </template>
          <template #value-link>
            <Link href="#" variant="standalone">forms.example.com/onboarding</Link>
          </template>
        </DescriptionList>
      </Card>
    </StorySection>

    <StorySection :title="t('story.dl.grid', 'Grid (2 columns)')">
      <Card>
        <DescriptionList :items="items" layout="grid" :columns="2">
          <template #value-status>
            <StatusBadge status="active" />
          </template>
          <template #value-link>
            <Link href="#" variant="standalone">forms.example.com/onboarding</Link>
          </template>
        </DescriptionList>
      </Card>
    </StorySection>

    <StorySection :title="t('story.dl.grid3', 'Grid (3 columns), small')">
      <Card>
        <DescriptionList :items="items" layout="grid" :columns="3" size="sm">
          <template #value-status>
            <StatusBadge status="active" size="sm" />
          </template>
          <template #value-link>
            <Link href="#" variant="standalone">forms.example.com/onboarding</Link>
          </template>
        </DescriptionList>
      </Card>
    </StorySection>

    <StorySection title="API">
      <div class="flex flex-col gap-next-6">
        <ApiTable title="Props" :rows="propRows" show-default />
        <ApiTable title="Slots" type-header="Slot props" :rows="slotRows" />
      </div>
    </StorySection>
  </StoryPage>
</template>
