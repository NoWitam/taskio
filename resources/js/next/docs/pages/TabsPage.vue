<script setup lang="ts">
// Gallery: Tabs — tab list + panels (Navigation tier).
//
// Shows the underline + pills variants, sizes, icons/badges, disabled tabs,
// overflowing horizontal scroll with edge fades, controlled vs uncontrolled,
// automatic vs manual activation, lazy panels, light + dark, and the API + a11y.
import { ref } from 'vue';
import Tabs, { type TabItem } from '../../ui/navigation/Tabs.vue';
import Badge from '../../ui/primitives/Badge.vue';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import StoryGrid from '../StoryGrid.vue';
import StoryCell from '../StoryCell.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const basic: TabItem[] = [
  { value: 'overview', label: 'Overview' },
  { value: 'activity', label: 'Activity' },
  { value: 'settings', label: 'Settings' },
];

const withMeta: TabItem[] = [
  { value: 'inbox', label: 'Inbox', icon: 'inbox', badge: 12 },
  { value: 'sent', label: 'Sent', icon: 'mail' },
  { value: 'archive', label: 'Archive', icon: 'folder', badge: 3 },
  { value: 'trash', label: 'Trash', icon: 'trash', disabled: true },
];

const many: TabItem[] = Array.from({ length: 14 }, (_, i) => ({
  value: `t${i}`,
  label: `Section ${i + 1}`,
}));

const controlled = ref<string | null>('activity');

const propRows: ApiRow[] = [
  { name: 'items', type: 'TabItem[]', default: '—', description: '{ value, label, icon?, badge?, disabled? } per tab.' },
  { name: 'variant', type: "'underline' | 'pills'", default: "'underline'", description: 'Underline indicator or segmented pills.' },
  { name: 'size', type: "'sm' | 'md'", default: "'md'", description: 'Control height + text scale.' },
  { name: 'activation', type: "'automatic' | 'manual'", default: "'automatic'", description: 'automatic: arrow-move selects; manual: arrow-move only focuses (Enter/Space selects).' },
  { name: 'lazy', type: 'boolean', default: 'false', description: 'Only mount a panel once it has been activated (keeps it mounted after).' },
  { name: 'ariaLabel', type: 'string', default: '—', description: 'Accessible label for the tablist.' },
  { name: 'v-model', type: 'string | null', default: 'null', description: 'Active tab value. Omit to self-manage (uncontrolled, seeds from first enabled tab).' },
];
const eventRows: ApiRow[] = [
  { name: 'update:modelValue', type: 'string', description: 'Emitted when the active tab changes.' },
];
const slotRows: ApiRow[] = [
  { name: 'panel', type: '{ value }', description: 'Default panel renderer (receives the active value).' },
  { name: 'panel-<value>', type: '—', description: 'Per-tab panel override (e.g. #panel-overview).' },
];
</script>

<template>
  <StoryPage
    title="Tabs"
    description="A tab list + panels. Underline (default) or pills variant; sizes sm/md; icons, badge counts, disabled tabs; the list scrolls horizontally (never wraps) with edge fades. Controlled or uncontrolled, automatic or manual activation, optional lazy panels."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li><code>role="tablist"</code> / <code>tab</code> / <code>tabpanel</code> with <code>aria-controls</code> / <code>aria-labelledby</code> wiring.</li>
        <li>Roving tabindex — only the active tab is a tab stop; <kbd>←</kbd>/<kbd>→</kbd> (and <kbd>↑</kbd>/<kbd>↓</kbd>) move, <kbd>Home</kbd>/<kbd>End</kbd> jump, skipping disabled tabs.</li>
        <li><code>activation="automatic"</code>: arrow-move selects. <code>"manual"</code>: arrow-move only focuses; <kbd>Enter</kbd>/<kbd>Space</kbd> commits.</li>
        <li>The active tab carries <code>aria-selected="true"</code>; the panel is focusable for keyboard reading.</li>
      </ul>
    </template>

    <StorySection title="Variants" description="underline (default) and pills/segmented.">
      <div class="flex flex-col gap-next-6">
        <Tabs :items="basic" aria-label="Underline example">
          <template #panel="{ value }">
            <p class="text-next-sm text-next-muted-foreground">Underline panel: <strong>{{ value }}</strong></p>
          </template>
        </Tabs>
        <Tabs :items="basic" variant="pills" aria-label="Pills example">
          <template #panel="{ value }">
            <p class="text-next-sm text-next-muted-foreground">Pills panel: <strong>{{ value }}</strong></p>
          </template>
        </Tabs>
      </div>
    </StorySection>

    <StorySection title="Sizes" description="sm · md (both variants).">
      <div class="flex flex-col gap-next-6">
        <StoryGrid align="start">
          <StoryCell label="underline sm">
            <Tabs :items="basic" size="sm" aria-label="sm underline" />
          </StoryCell>
          <StoryCell label="underline md">
            <Tabs :items="basic" size="md" aria-label="md underline" />
          </StoryCell>
        </StoryGrid>
        <StoryGrid align="start">
          <StoryCell label="pills sm">
            <Tabs :items="basic" variant="pills" size="sm" aria-label="sm pills" />
          </StoryCell>
          <StoryCell label="pills md">
            <Tabs :items="basic" variant="pills" size="md" aria-label="md pills" />
          </StoryCell>
        </StoryGrid>
      </div>
    </StorySection>

    <StorySection title="Icons, badge counts & disabled" description="A leading icon, a numeric Badge, and a disabled tab (skipped by keyboard).">
      <Tabs :items="withMeta" aria-label="Mailbox">
        <template #panel="{ value }">
          <p class="text-next-sm text-next-muted-foreground">Showing <strong>{{ value }}</strong>.</p>
        </template>
      </Tabs>
    </StorySection>

    <StorySection title="Overflowing tab list" description="Too many tabs to fit: the list scrolls horizontally (never wraps) with edge fades. Resize the box to see the fades appear.">
      <div class="max-w-md resize-x overflow-hidden rounded-next-md border border-dashed border-next-border p-next-3">
        <Tabs :items="many" aria-label="Many sections" />
      </div>
    </StorySection>

    <StorySection title="Controlled v-model" description="A parent owns the active tab; here a button changes it externally.">
      <div class="flex flex-col gap-next-3">
        <div class="flex items-center gap-next-2 text-next-sm">
          <span class="text-next-muted-foreground">External value:</span>
          <Badge variant="primary" tone="subtle">{{ controlled }}</Badge>
          <button class="text-next-primary underline" @click="controlled = 'settings'">jump to settings</button>
        </div>
        <Tabs v-model="controlled" :items="basic" variant="pills" aria-label="Controlled">
          <template #panel="{ value }">
            <p class="text-next-sm text-next-muted-foreground">Active panel: <strong>{{ value }}</strong></p>
          </template>
        </Tabs>
      </div>
    </StorySection>

    <StorySection title="Manual activation + lazy panels" description="Arrow keys only move focus; Enter/Space commits. Panels mount lazily on first activation.">
      <Tabs :items="basic" activation="manual" lazy aria-label="Manual lazy">
        <template #panel-overview>
          <p class="text-next-sm text-next-muted-foreground">Overview content (mounted on first visit).</p>
        </template>
        <template #panel-activity>
          <p class="text-next-sm text-next-muted-foreground">Activity content (mounted on first visit).</p>
        </template>
        <template #panel-settings>
          <p class="text-next-sm text-next-muted-foreground">Settings content (mounted on first visit).</p>
        </template>
      </Tabs>
    </StorySection>

    <StorySection title="Light + dark">
      <div class="grid gap-next-4 sm:grid-cols-2">
        <div class="next-root rounded-next-lg border border-next-border bg-next-bg p-next-4">
          <p class="mb-next-2 text-next-xs text-next-muted-foreground">light</p>
          <Tabs :items="withMeta" aria-label="light tabs" />
        </div>
        <div class="next-root dark rounded-next-lg border border-next-border bg-next-bg p-next-4">
          <p class="mb-next-2 text-next-xs text-next-muted-foreground">dark</p>
          <Tabs :items="withMeta" variant="pills" aria-label="dark tabs" />
        </div>
      </div>
    </StorySection>

    <StorySection title="API">
      <div class="flex flex-col gap-next-6">
        <ApiTable title="Props" :rows="propRows" show-default />
        <ApiTable title="Events" type-header="Payload" :rows="eventRows" />
        <ApiTable title="Slots" type-header="Scope" :rows="slotRows" />
      </div>
    </StorySection>
  </StoryPage>
</template>
