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
  { name: 'variant', type: "'default' | 'muted' | 'standalone' | 'plain'", default: "'default'", description: 'Inline primary, muted, standalone (icon-friendly), or plain (inherits the surrounding colour — for row links).' },
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
        <li><code>plain</code> forces no colour, so a row can keep carrying its own state (dismissed, muted, struck through); the hover/focus underline is what still marks it as a link, and the focus ring is unchanged.</li>
        <li>Never use a link for an action that should be a button.</li>
      </ul>
    </template>

    <StorySection title="Variants">
      <StoryGrid align="center">
        <StoryCell label="default"><Link href="#link">View documentation</Link></StoryCell>
        <StoryCell label="muted"><Link href="#link" variant="muted">Skip for now</Link></StoryCell>
        <StoryCell label="standalone"><Link href="#link" variant="standalone">Open report</Link></StoryCell>
        <StoryCell label="plain"><Link href="#link" variant="plain">Inherits its colour</Link></StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection
      title="Plain — links that are rows"
      description="A list, table, or rail row whose whole line is the link. Its colour belongs to the ROW state (here: struck through once dismissed), so the link must not force one of its own. Only the hover/focus underline is kept, so the row still reads as a link."
    >
      <ul class="flex max-w-sm flex-col gap-next-1">
        <li class="flex items-center gap-next-2 rounded-next-md px-next-2 py-next-1_5 text-next-sm hover:bg-next-muted/50">
          <Link href="#link" variant="plain" class="min-w-0 flex-1 truncate">Pricing policy</Link>
          <span class="shrink-0 text-next-xs text-next-muted-foreground">88%</span>
        </li>
        <li class="flex items-center gap-next-2 rounded-next-md px-next-2 py-next-1_5 text-next-sm hover:bg-next-muted/50">
          <Link
            href="#link"
            variant="plain"
            class="min-w-0 flex-1 truncate text-next-muted-foreground line-through"
          >
            Discount policy (dismissed)
          </Link>
          <span class="shrink-0 text-next-xs text-next-muted-foreground">71%</span>
        </li>
      </ul>
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
