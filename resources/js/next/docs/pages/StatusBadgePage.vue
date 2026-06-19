<script setup lang="ts">
// Gallery: StatusBadge — semantic status chip built on Badge (Data tier).
//
// Shows the full default mapping (icon/dot + label, never color-only), sizes, a
// tone override, a custom domain statusMap, an unknown-status fallback, light +
// dark, the mapping table, and the API + a11y.
import StatusBadge, { type StatusKey, type StatusMap } from '../../ui/data/StatusBadge.vue';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import StoryGrid from '../StoryGrid.vue';
import StoryCell from '../StoryCell.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const allStatuses: StatusKey[] = [
  'active', 'inactive', 'pending', 'success',
  'warning', 'error', 'info', 'draft', 'archived',
];

// A domain map: form publishing states overriding + extending the defaults.
const formStatusMap: StatusMap = {
  published: { label: 'Published', variant: 'success', tone: 'solid', icon: 'check-circle' },
  scheduled: { label: 'Scheduled', variant: 'info', tone: 'subtle', icon: 'clock' },
  closed: { label: 'Closed', variant: 'neutral', tone: 'subtle', icon: 'x-circle' },
  draft: { label: 'Working draft', variant: 'warning', tone: 'subtle', icon: 'file-text' },
};

const mappingRows: ApiRow[] = [
  { name: 'active', type: 'success · subtle', default: 'check-circle', description: 'Active' },
  { name: 'inactive', type: 'neutral · subtle', default: 'dot', description: 'Inactive' },
  { name: 'pending', type: 'warning · subtle', default: 'clock', description: 'Pending' },
  { name: 'success', type: 'success · solid', default: 'check-circle', description: 'Success' },
  { name: 'warning', type: 'warning · solid', default: 'alert-triangle', description: 'Warning' },
  { name: 'error', type: 'danger · solid', default: 'x-circle', description: 'Error' },
  { name: 'info', type: 'info · subtle', default: 'info', description: 'Info' },
  { name: 'draft', type: 'neutral · subtle', default: 'file-text', description: 'Draft' },
  { name: 'archived', type: 'neutral · subtle', default: 'inbox', description: 'Archived' },
];

const propRows: ApiRow[] = [
  { name: 'status', type: 'StatusKey | string', default: '—', description: 'Known status key, or any key covered by statusMap.' },
  { name: 'label', type: 'string', default: 'mapped', description: 'Override the mapped label (e.g. localized).' },
  { name: 'statusMap', type: 'StatusMap', default: '—', description: 'Extend/override the mapping per app domain (merged over defaults).' },
  { name: 'size', type: "'sm' | 'md'", default: "'md'", description: 'Forwarded to Badge.' },
  { name: 'tone', type: "'solid' | 'subtle'", default: 'mapped', description: 'Force the tone (overrides the descriptor).' },
];
</script>

<template>
  <StoryPage
    title="StatusBadge"
    description="A semantic status chip built on Badge. Maps well-known statuses to a tone + icon/dot + label so statuses read consistently — never color-only. Extend or override per domain with statusMap."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>Every status renders an <strong>icon or dot PLUS a text label</strong> — meaning survives in grayscale / for color-blind users.</li>
        <li>Unknown statuses fall back to a neutral dot + the raw key as the label (never blank, never color-only).</li>
        <li>Inherits Badge semantics; the label is always real text (not an <code>aria-label</code> on a colored shape).</li>
      </ul>
    </template>

    <StorySection title="Default mapping" description="The nine built-in statuses, each with its icon/dot + label.">
      <StoryGrid align="center">
        <StoryCell v-for="s in allStatuses" :key="s" :label="s">
          <StatusBadge :status="s" />
        </StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection title="Sizes" description="sm · md.">
      <StoryGrid align="center">
        <StoryCell label="sm">
          <div class="flex gap-next-2">
            <StatusBadge status="active" size="sm" />
            <StatusBadge status="error" size="sm" />
            <StatusBadge status="pending" size="sm" />
          </div>
        </StoryCell>
        <StoryCell label="md">
          <div class="flex gap-next-2">
            <StatusBadge status="active" size="md" />
            <StatusBadge status="error" size="md" />
            <StatusBadge status="pending" size="md" />
          </div>
        </StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection title="Label + tone overrides" description="Localize the label or force a tone without changing the mapping.">
      <StoryGrid align="center">
        <StoryCell label="custom label">
          <StatusBadge status="active" label="Aktywny" />
        </StoryCell>
        <StoryCell label="forced solid">
          <StatusBadge status="info" tone="solid" />
        </StoryCell>
        <StoryCell label="forced subtle">
          <StatusBadge status="error" tone="subtle" />
        </StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection title="Custom domain statusMap" description="Add app-specific statuses (published / scheduled / closed) and override an existing one (draft).">
      <StoryGrid align="center">
        <StoryCell label="published"><StatusBadge status="published" :status-map="formStatusMap" /></StoryCell>
        <StoryCell label="scheduled"><StatusBadge status="scheduled" :status-map="formStatusMap" /></StoryCell>
        <StoryCell label="closed"><StatusBadge status="closed" :status-map="formStatusMap" /></StoryCell>
        <StoryCell label="draft (overridden)"><StatusBadge status="draft" :status-map="formStatusMap" /></StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection title="Unknown status fallback" description="An unmapped key renders a neutral dot + the key text — never blank.">
      <StatusBadge status="quarantined" />
    </StorySection>

    <StorySection title="Light + dark">
      <div class="grid gap-next-4 sm:grid-cols-2">
        <div class="next-root flex flex-wrap gap-next-2 rounded-next-lg border border-next-border bg-next-card p-next-4">
          <StatusBadge v-for="s in allStatuses" :key="s" :status="s" />
        </div>
        <div class="next-root dark flex flex-wrap gap-next-2 rounded-next-lg border border-next-border bg-next-card p-next-4">
          <StatusBadge v-for="s in allStatuses" :key="s" :status="s" />
        </div>
      </div>
    </StorySection>

    <StorySection title="Mapping table">
      <ApiTable title="status → tone · icon · label" type-header="variant · tone" :rows="mappingRows" show-default />
    </StorySection>

    <StorySection title="API">
      <ApiTable title="Props" :rows="propRows" show-default />
    </StorySection>
  </StoryPage>
</template>
