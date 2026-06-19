<script setup lang="ts">
// Gallery: Breadcrumbs — hierarchical path nav (Navigation tier).
//
// Shows basic links, a custom separator, icons, label truncation, and long-path
// collapsing into a "…" menu, plus light + dark and the API + a11y notes. Uses
// plain `href` links (no router needed in the gallery).
import Breadcrumbs, { type BreadcrumbItem } from '../../ui/navigation/Breadcrumbs.vue';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const basic: BreadcrumbItem[] = [
  { label: 'Home', href: '#home', icon: 'layout-dashboard' },
  { label: 'Projects', href: '#projects' },
  { label: 'Atlas', href: '#atlas' },
  { label: 'Settings' },
];

const longPath: BreadcrumbItem[] = [
  { label: 'Home', href: '#home', icon: 'layout-dashboard' },
  { label: 'Workspace', href: '#ws' },
  { label: 'Teams', href: '#teams' },
  { label: 'Engineering', href: '#eng' },
  { label: 'Frontend', href: '#fe' },
  { label: 'Design System', href: '#ds' },
  { label: 'Components' },
];

const truncating: BreadcrumbItem[] = [
  { label: 'Home', href: '#home' },
  { label: 'A folder with an extremely long descriptive name that should truncate', href: '#long' },
  { label: 'Current document with a long title too' },
];

const propRows: ApiRow[] = [
  { name: 'items', type: 'BreadcrumbItem[]', default: '—', description: '{ label, to?, href?, icon? } per crumb; the last is the current page.' },
  { name: 'separator', type: 'IconName', default: "'chevron-right'", description: 'Icon between crumbs (decorative).' },
  { name: 'maxVisible', type: 'number', default: '0', description: 'Collapse the middle into a "…" menu when items exceed this; 0 disables.' },
  { name: 'ariaLabel', type: 'string', default: "'Breadcrumbs'", description: 'Accessible label for the nav landmark.' },
];
const eventRows: ApiRow[] = [
  { name: 'navigate', type: 'BreadcrumbItem', description: 'Emitted when a crumb (including a collapsed-menu crumb) is activated.' },
];
</script>

<template>
  <StoryPage
    title="Breadcrumbs"
    description="A hierarchical path nav. Items link via router-link (to) or a plain anchor (href); the last item is plain text marked aria-current. Long paths collapse their middle into a '…' menu; long labels truncate."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li><code>&lt;nav aria-label="Breadcrumbs"&gt;</code> wrapping an <code>&lt;ol&gt;</code> of crumbs.</li>
        <li>The current page is the only non-link and carries <code>aria-current="page"</code>.</li>
        <li>Separators are decorative (<code>aria-hidden</code>).</li>
        <li>The collapse trigger is a real menu button; the menu is keyboard-navigable (DropdownMenu).</li>
      </ul>
    </template>

    <StorySection title="Basic" description="Linked crumbs + a current page; the first crumb has an icon.">
      <Breadcrumbs :items="basic" />
    </StorySection>

    <StorySection title="Custom separator" description="Any icon can be the separator.">
      <Breadcrumbs :items="basic" separator="chevron-right" />
      <div class="mt-next-4">
        <Breadcrumbs :items="basic" separator="arrow-right" />
      </div>
    </StorySection>

    <StorySection title="Long-path collapsing" description="With maxVisible=3 the middle crumbs collapse into a '…' menu (open it to jump). The first crumb + the last two always stay visible.">
      <Breadcrumbs :items="longPath" :max-visible="3" />
    </StorySection>

    <StorySection title="Truncation" description="Long labels truncate with an ellipsis so the bar never overflows.">
      <div class="max-w-md rounded-next-md border border-dashed border-next-border p-next-3">
        <Breadcrumbs :items="truncating" />
      </div>
    </StorySection>

    <StorySection title="Light + dark">
      <div class="grid gap-next-4 sm:grid-cols-2">
        <div class="next-root rounded-next-lg border border-next-border bg-next-bg p-next-4">
          <p class="mb-next-2 text-next-xs text-next-muted-foreground">light</p>
          <Breadcrumbs :items="longPath" :max-visible="3" />
        </div>
        <div class="next-root dark rounded-next-lg border border-next-border bg-next-bg p-next-4">
          <p class="mb-next-2 text-next-xs text-next-muted-foreground">dark</p>
          <Breadcrumbs :items="longPath" :max-visible="3" />
        </div>
      </div>
    </StorySection>

    <StorySection title="API">
      <div class="flex flex-col gap-next-6">
        <ApiTable title="Props" :rows="propRows" show-default />
        <ApiTable title="Events" type-header="Payload" :rows="eventRows" />
      </div>
    </StorySection>
  </StoryPage>
</template>
