<script setup lang="ts">
import Link from '../../ui/primitives/Link.vue';
import Text from '../../ui/primitives/Text.vue';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import StoryGrid from '../StoryGrid.vue';
import StoryCell from '../StoryCell.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const propRows: ApiRow[] = [
  { name: 'href', type: 'string', default: '— (required)', description: 'Destination URL.' },
  { name: 'variant', type: "'default' | 'muted' | 'standalone'", default: "'default'", description: 'Inline primary, muted, or standalone (icon-friendly) link.' },
  { name: 'external', type: 'boolean', default: 'false', description: 'Adds external icon, rel="noopener noreferrer", new-tab announcement.' },
  { name: 'target', type: 'string', default: '—', description: 'Anchor target; `_blank` also triggers external handling.' },
  { name: 'disabled', type: 'boolean', default: 'false', description: 'Drops href, sets aria-disabled, removes from tab order, prevents activation.' },
];

const eventRows: ApiRow[] = [
  { name: 'click', type: '(event: MouseEvent)', description: 'Emitted on activation; suppressed while disabled.' },
];

const slotRows: ApiRow[] = [
  { name: 'default', type: 'link text', description: 'The visible link label.' },
];
</script>

<template>
  <StoryPage
    title="Link"
    description="A real anchor for navigation. Use a Button for actions that mutate state. External links get an icon, rel=noopener, and a new-tab announcement."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>Always a real <code>&lt;a href&gt;</code>; focus ring uses the <code>--color-next-ring</code> token, underline appears on hover and focus.</li>
        <li>External links append a visually-hidden “(opens in new tab)” and set <code>rel="noopener noreferrer"</code>.</li>
        <li>Disabled links lose their <code>href</code>, get <code>aria-disabled</code>, and are removed from the tab order — links can’t be natively disabled.</li>
        <li>Never use a link for an action that should be a button.</li>
      </ul>
    </template>

    <StorySection title="Variants">
      <StoryGrid align="center">
        <StoryCell label="default"><Link href="#link">View documentation</Link></StoryCell>
        <StoryCell label="muted"><Link href="#link" variant="muted">Skip for now</Link></StoryCell>
        <StoryCell label="standalone"><Link href="#link" variant="standalone">Open report</Link></StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection title="States" description="Hover and focus underline the link; Tab to see the focus ring.">
      <StoryGrid align="center">
        <StoryCell label="default"><Link href="#link">Default link</Link></StoryCell>
        <StoryCell label="focusable (Tab)"><Link href="#link">Focus me</Link></StoryCell>
        <StoryCell label="disabled"><Link href="#link" disabled>Disabled link</Link></StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection title="External" description="Trailing external icon + rel + announcement.">
      <StoryGrid align="center">
        <StoryCell label="external"><Link href="https://example.com" external>example.com</Link></StoryCell>
        <StoryCell label="target=_blank"><Link href="https://example.com" target="_blank">Open in new tab</Link></StoryCell>
        <StoryCell label="standalone external"><Link href="https://example.com" variant="standalone" external>API reference</Link></StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection title="Inline in prose" description="Links inherit the surrounding text size and sit comfortably in a paragraph.">
      <Text variant="body">
        Taskio forms can be embedded anywhere. Read the
        <Link href="#link">embedding guide</Link> or jump straight to the
        <Link href="https://example.com" external>public API docs</Link>
        to get started.
      </Text>
    </StorySection>

    <StorySection title="API">
      <div class="flex flex-col gap-next-6">
        <ApiTable title="Props" :rows="propRows" show-default />
        <ApiTable title="Events" type-header="Payload" :rows="eventRows" />
        <ApiTable title="Slots" type-header="Content" :rows="slotRows" />
      </div>
    </StorySection>
  </StoryPage>
</template>
