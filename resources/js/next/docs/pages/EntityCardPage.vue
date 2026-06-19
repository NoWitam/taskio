<script setup lang="ts">
// Gallery: EntityCard — the standard domain-object card (Patterns tier).
//
// Built on Card. Shows the region layout, interactive vs. static, selected /
// disabled / loading-skeleton states, a kebab actions menu that stays clickable
// above the stretched link, light + dark, a realistic tasks grid, and the API.
import { ref } from 'vue';
import EntityCard, { type EntityMetaItem } from '../../ui/patterns/EntityCard.vue';
import Avatar from '../../ui/primitives/Avatar.vue';
import Button from '../../ui/primitives/Button.vue';
import Grid from '../../ui/layout/Grid.vue';
import DropdownMenu from '../../ui/overlay/DropdownMenu.vue';
import DropdownMenuItem from '../../ui/overlay/DropdownMenuItem.vue';
import DropdownMenuSeparator from '../../ui/overlay/DropdownMenuSeparator.vue';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import StoryGrid from '../StoryGrid.vue';
import StoryCell from '../StoryCell.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const meta: EntityMetaItem[] = [
  { icon: 'user', label: 'Anna Kowalska' },
  { icon: 'calendar', label: 'Due', value: 'Jun 20' },
  { icon: 'folder', label: 'Marketing' },
];

const lastAction = ref('—');
function pick(action: string): void {
  lastAction.value = action;
}

const selected = ref(true);

interface Task {
  id: number;
  title: string;
  subtitle: string;
  assignee: string;
  status: 'active' | 'pending' | 'draft' | 'archived';
  due: string;
}
const tasks: Task[] = [
  { id: 1, title: 'Design the onboarding flow', subtitle: 'Wireframe the three-step signup and map the empty states for each screen.', assignee: 'Anna Kowalska', status: 'active', due: 'Jun 20' },
  { id: 2, title: 'Migrate billing webhooks', subtitle: 'Move the Stripe webhook handlers to the new queue and add idempotency keys.', assignee: 'Piotr Nowak', status: 'pending', due: 'Jun 24' },
  { id: 3, title: 'Audit color contrast', subtitle: 'Check every status badge against WCAG AA in light and dark themes.', assignee: 'Maria Wiśniewska', status: 'draft', due: 'Jul 02' },
  { id: 4, title: 'Archive legacy exports', subtitle: 'Old CSV export jobs are no longer used; remove the cron and the storage bucket.', assignee: 'Jan Lewandowski', status: 'archived', due: '—' },
];

const propRows: ApiRow[] = [
  { name: 'title', type: 'string', default: '—', description: 'Primary title (or use the #title slot).' },
  { name: 'subtitle', type: 'string', default: '—', description: 'Clamped description (#subtitle slot for rich content).' },
  { name: 'subtitleLines', type: 'number', default: '2', description: 'Clamp the subtitle to N lines.' },
  { name: 'status', type: 'StatusKey | string', default: '—', description: 'Renders a StatusBadge (or use #status).' },
  { name: 'statusLabel', type: 'string', default: '—', description: 'Override the status label.' },
  { name: 'statusMap', type: 'StatusMap', default: '—', description: 'Extend/override the status mapping.' },
  { name: 'meta', type: 'EntityMetaItem[]', default: '—', description: 'Footer pairs { icon?, label, value? } (or #meta slot).' },
  { name: 'to', type: 'RouteLocationRaw', default: '—', description: 'Router target — whole-card link.' },
  { name: 'href', type: 'string', default: '—', description: 'Anchor href — whole-card link.' },
  { name: 'target', type: 'string', default: '—', description: "Anchor target; _blank adds rel=noopener." },
  { name: 'actionLabel', type: 'string', default: '—', description: 'Accessible name for the whole-card action.' },
  { name: 'selected', type: 'boolean', default: 'false', description: 'Accent ring + tint.' },
  { name: 'disabled', type: 'boolean', default: 'false', description: 'Dim + inert (disables the action).' },
  { name: 'loading', type: 'boolean', default: 'false', description: 'Skeleton variant mirroring the geometry.' },
];
const eventRows: ApiRow[] = [
  { name: 'click', type: '(event: MouseEvent)', description: 'Emitted on the whole-card action (when interactive, click-only).' },
];
const slotRows: ApiRow[] = [
  { name: 'leading', type: 'slot', description: 'Leading visual (Avatar / Icon).' },
  { name: 'title', type: 'slot', description: 'Custom title content.' },
  { name: 'subtitle', type: 'slot', description: 'Custom description content.' },
  { name: 'status', type: 'slot', description: 'Custom status region (overrides the status prop).' },
  { name: 'meta', type: 'slot', description: 'Custom metadata footer (overrides the meta prop).' },
  { name: 'actions', type: 'slot', description: 'Kebab menu / buttons — stay clickable above the stretched link.' },
];
</script>

<template>
  <StoryPage
    title="EntityCard"
    description="The standard domain-object card, built on Card. Consistent regions — leading visual, title, clamped subtitle, status badge, a metadata footer of icon+label pairs, and an actions area — with interactive, selected, disabled, and loading-skeleton states."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>When <code>to</code> / <code>href</code> / <code>@click</code> is set the WHOLE card is a single accessible action (stretched link) — exactly one tab stop + accessible name.</li>
        <li>The <code>#actions</code> region (kebab / buttons) sits ABOVE the stretched link (z-index) so its controls stay independently clickable and focusable.</li>
        <li>The interactive card rings via <code>:focus-within</code>; keyboard focus on the action highlights the whole card.</li>
        <li>Disabled cards are inert (the action is removed from the tab order); the status badge is never color-only.</li>
      </ul>
    </template>

    <StorySection title="Anatomy" description="Leading Avatar, title, clamped subtitle, status badge, metadata footer, and a kebab actions menu.">
      <div class="max-w-md">
        <EntityCard
          title="Design the onboarding flow"
          subtitle="Wireframe the three-step signup and map the empty states for each screen so handoff is unambiguous."
          status="active"
          :meta="meta"
        >
          <template #leading>
            <Avatar name="Anna Kowalska" />
          </template>
          <template #actions>
            <DropdownMenu aria-label="Task actions" placement="bottom-end">
              <template #trigger="{ props }">
                <Button variant="ghost" size="icon" aria-label="Task actions" v-bind="props">
                  <span class="text-next-lg" aria-hidden="true">⋯</span>
                </Button>
              </template>
              <DropdownMenuItem icon="eye" @select="pick('View')">View</DropdownMenuItem>
              <DropdownMenuItem icon="file-text" @select="pick('Edit')">Edit</DropdownMenuItem>
              <DropdownMenuSeparator />
              <DropdownMenuItem icon="trash" destructive @select="pick('Delete')">Delete</DropdownMenuItem>
            </DropdownMenu>
          </template>
        </EntityCard>
        <p class="mt-next-2 text-next-sm text-next-muted-foreground">
          Last menu action: <span class="font-next-medium text-next-fg">{{ lastAction }}</span>
        </p>
      </div>
    </StorySection>

    <StorySection title="Interactive vs. static" description="An interactive card (href here) lifts on hover and rings on focus; a static card does not.">
      <Grid :cols="{ base: 1, md: 2 }" gap="4">
        <EntityCard
          title="Open in new context"
          subtitle="The whole card is a link. Hover and Tab to it to see the lift + focus ring."
          href="#entity-card-demo"
          action-label="Open task: Open in new context"
          status="pending"
          :meta="[{ icon: 'calendar', label: 'Due', value: 'Jun 24' }]"
        >
          <template #leading><Avatar name="Piotr Nowak" /></template>
        </EntityCard>
        <EntityCard
          title="Static card"
          subtitle="No to / href / click — a plain content card with the same layout."
          status="draft"
          :meta="[{ icon: 'folder', label: 'Backlog' }]"
        >
          <template #leading><Avatar name="Maria Wiśniewska" /></template>
        </EntityCard>
      </Grid>
    </StorySection>

    <StorySection title="States" description="default · selected · disabled · loading-skeleton.">
      <StoryGrid :cols="2" align="start">
        <StoryCell label="default">
          <EntityCard title="Default" subtitle="A standard card." status="active" :meta="[{ icon: 'user', label: 'Anna' }]">
            <template #leading><Avatar name="Anna Kowalska" /></template>
          </EntityCard>
        </StoryCell>
        <StoryCell label="selected (toggle)">
          <EntityCard
            title="Selected"
            subtitle="Accent ring + tint."
            :selected="selected"
            status="info"
            :meta="[{ icon: 'user', label: 'Piotr' }]"
            @click="selected = !selected"
            action-label="Toggle selected"
          >
            <template #leading><Avatar name="Piotr Nowak" /></template>
          </EntityCard>
        </StoryCell>
        <StoryCell label="disabled">
          <EntityCard title="Disabled" subtitle="Dim + inert; the action is removed from the tab order." href="#nope" disabled status="archived" :meta="[{ icon: 'user', label: 'Jan' }]">
            <template #leading><Avatar name="Jan Lewandowski" /></template>
          </EntityCard>
        </StoryCell>
        <StoryCell label="loading skeleton">
          <EntityCard loading />
        </StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection title="Realistic tasks grid" description="A list screen rendering EntityCards in a responsive grid — the common 'list of domain objects' layout.">
      <Grid :cols="{ base: 1, md: 2 }" gap="4">
        <EntityCard
          v-for="t in tasks"
          :key="t.id"
          :title="t.title"
          :subtitle="t.subtitle"
          :status="t.status"
          href="#task"
          :action-label="`Open task: ${t.title}`"
          :meta="[
            { icon: 'user', label: t.assignee },
            { icon: 'calendar', label: 'Due', value: t.due },
          ]"
        >
          <template #leading><Avatar :name="t.assignee" /></template>
          <template #actions>
            <DropdownMenu aria-label="Task actions" placement="bottom-end">
              <template #trigger="{ props }">
                <Button variant="ghost" size="icon" aria-label="Task actions" v-bind="props">
                  <span class="text-next-lg" aria-hidden="true">⋯</span>
                </Button>
              </template>
              <DropdownMenuItem icon="eye" @select="pick(`View ${t.title}`)">View</DropdownMenuItem>
              <DropdownMenuItem icon="file-text" @select="pick(`Edit ${t.title}`)">Edit</DropdownMenuItem>
            </DropdownMenu>
          </template>
        </EntityCard>
      </Grid>
    </StorySection>

    <StorySection title="Light + dark">
      <div class="grid gap-next-4 sm:grid-cols-2">
        <div class="next-root rounded-next-lg border border-next-border bg-next-bg p-next-4">
          <EntityCard title="Light theme" subtitle="Tokens drive every surface and tint." status="active" :meta="meta">
            <template #leading><Avatar name="Anna Kowalska" /></template>
          </EntityCard>
        </div>
        <div class="next-root dark rounded-next-lg border border-next-border bg-next-bg p-next-4">
          <EntityCard title="Dark theme" subtitle="Tokens drive every surface and tint." status="active" :meta="meta">
            <template #leading><Avatar name="Anna Kowalska" /></template>
          </EntityCard>
        </div>
      </div>
    </StorySection>

    <StorySection title="API">
      <div class="flex flex-col gap-next-6">
        <ApiTable title="Props" :rows="propRows" show-default />
        <ApiTable title="Events" type-header="Payload" :rows="eventRows" />
        <ApiTable title="Slots" type-header="Kind" :rows="slotRows" />
      </div>
    </StorySection>
  </StoryPage>
</template>
