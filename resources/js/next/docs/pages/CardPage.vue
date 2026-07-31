<script setup lang="ts">
import { ref } from 'vue';
import Card from '../../ui/layout/Card.vue';
import Stack from '../../ui/layout/Stack.vue';
import Grid from '../../ui/layout/Grid.vue';
import Button from '../../ui/primitives/Button.vue';
import Badge from '../../ui/primitives/Badge.vue';
import Icon from '../../ui/primitives/Icon.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import StoryGrid from '../StoryGrid.vue';
import StoryCell from '../StoryCell.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const variants = ['default', 'elevated', 'interactive', 'muted', 'inset'] as const;

const selectedId = ref(2);

const propRows: ApiRow[] = [
  { name: 'variant', type: "'default' | 'elevated' | 'interactive' | 'muted' | 'inset'", default: "'default'", description: 'Visual treatment. `interactive` makes the whole card a single action.' },
  { name: 'selected', type: 'boolean', default: 'false', description: 'Selected styling: accent ring + tint (shape + color, not color alone).' },
  { name: 'disabled', type: 'boolean', default: 'false', description: 'Dim + make inert (also disables the interactive action).' },
  { name: 'loading', type: 'boolean', default: 'false', description: 'Show a skeleton in the body region; sets aria-busy.' },
  { name: 'empty', type: 'boolean', default: 'false', description: 'Render the empty-state placeholder (or #empty slot) instead of the body.' },
  { name: 'bodyCollapsed', type: 'boolean', default: 'false', description: 'The host collapsed the body itself (v-show, children stay mounted): drops the body padding + the header divider so no empty band remains. Card never hides content on its own.' },
  { name: 'as', type: "'a' | 'button'", default: "'button'*", description: 'Element for the whole-card action when interactive (* default when interactive).' },
  { name: 'href', type: 'string', default: '—', description: 'Href for the whole-card link (forces an <a> + makes the card interactive).' },
  { name: 'target', type: 'string', default: '—', description: 'Anchor target; `_blank` adds rel="noopener noreferrer".' },
  { name: 'actionLabel', type: 'string', default: '—', description: 'Accessible name for the whole-card action when the header text is not self-describing.' },
];

const eventRows: ApiRow[] = [
  { name: 'activate', type: '(event: MouseEvent)', description: 'Emitted when the interactive card is activated (suppressed while disabled).' },
];

const slotRows: ApiRow[] = [
  { name: 'header', type: 'content', description: 'Header region (title + meta). For interactive cards this hosts the stretched action.' },
  { name: 'headerActions', type: 'content', description: 'Non-stretched header controls kept clickable above the stretched link.' },
  { name: 'default', type: 'content', description: 'Body region.' },
  { name: 'footer', type: 'content', description: 'Footer / metadata region (consistent placement).' },
  { name: 'empty', type: 'content', description: 'Custom empty-state content (defaults to an inline placeholder).' },
];
</script>

<template>
  <StoryPage
    title="Card"
    description="A themed content container built on Surface, with header / body / footer regions and a full variant + state matrix. Interactive cards expose a single accessible whole-card action via a stretched-link pattern."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>Interactive cards expose exactly <strong>one</strong> tab stop and one accessible name — the whole card is the link/button via a stretched-link pseudo-element. Do not nest competing interactive controls inside; put secondary controls in <code>#headerActions</code> (kept above the stretched link) or outside the card.</li>
        <li>Keyboard focus on the inner action rings the <em>whole card</em> via <code>:focus-within</code> using the <code>--color-next-ring</code> token; <kbd>Enter</kbd> activates a link, <kbd>Enter</kbd>/<kbd>Space</kbd> a button.</li>
        <li><code>loading</code> sets <code>aria-busy="true"</code> and the skeleton is <code>aria-hidden</code> with an sr-only "Loading…" message.</li>
        <li><code>selected</code> uses a ring + tint (shape + color), and <code>disabled</code> sets <code>aria-disabled</code> and removes the action from activation.</li>
      </ul>
    </template>

    <StorySection title="Variants" description="Five visual treatments. Hover the interactive card; Tab into it to see the whole-card focus ring.">
      <Grid :cols="{ base: 1, sm: 2, lg: 3 }" gap="4">
        <Card v-for="v in variants" :key="v" :variant="v" :href="v === 'interactive' ? '#card' : undefined" action-label="Open project Atlas">
          <template #header>
            <Stack direction="horizontal" align="center" justify="between" gap="2">
              <h4 class="text-next-base font-next-semibold">{{ v }} card</h4>
              <Badge variant="neutral" tone="subtle">v</Badge>
            </Stack>
          </template>
          <p class="text-next-sm text-next-muted-foreground">
            Body content. The <code>{{ v }}</code> variant sets background, border, and elevation via Surface.
          </p>
          <template #footer>
            <Icon name="calendar" class="text-next-sm" />
            <span>Updated 2h ago</span>
          </template>
        </Card>
      </Grid>
    </StorySection>

    <StorySection title="Regions" description="Header (title + actions), body, footer (metadata). Header actions stay independently clickable.">
      <div class="max-w-md">
        <Card variant="default">
          <template #header>
            <Stack gap="0_5">
              <h4 class="text-next-base font-next-semibold">Customer feedback</h4>
              <p class="text-next-xs text-next-muted-foreground">Form · 312 submissions</p>
            </Stack>
          </template>
          <template #headerActions>
            <Button size="icon" variant="ghost" aria-label="More actions" leading-icon="more-horizontal" />
          </template>
          <p class="text-next-sm text-next-muted-foreground">
            A standard card with a distinct header, body, and footer. The metadata region keeps a consistent placement across every card.
          </p>
          <template #footer>
            <Badge variant="success" tone="subtle" icon="check-circle">Published</Badge>
            <span class="ml-auto">Owner: A. Rivera</span>
          </template>
        </Card>
      </div>
    </StorySection>

    <StorySection title="States" description="default · selected · disabled · loading (skeleton) · empty.">
      <Grid :cols="{ base: 1, sm: 2, lg: 3 }" gap="4">
        <StoryCell label="default">
          <Card class="w-full">
            <template #header><h4 class="text-next-base font-next-semibold">Default</h4></template>
            <p class="text-next-sm text-next-muted-foreground">Resting state.</p>
          </Card>
        </StoryCell>
        <StoryCell label="selected">
          <Card class="w-full" selected>
            <template #header><h4 class="text-next-base font-next-semibold">Selected</h4></template>
            <p class="text-next-sm text-next-muted-foreground">Accent ring + tint.</p>
          </Card>
        </StoryCell>
        <StoryCell label="disabled">
          <Card class="w-full" variant="interactive" disabled href="#x" action-label="Disabled card">
            <template #header><h4 class="text-next-base font-next-semibold">Disabled</h4></template>
            <p class="text-next-sm text-next-muted-foreground">Dimmed + inert.</p>
          </Card>
        </StoryCell>
        <StoryCell label="loading">
          <Card class="w-full" loading>
            <template #header><h4 class="text-next-base font-next-semibold">Loading</h4></template>
          </Card>
        </StoryCell>
<StoryCell label="empty (with real EmptyState)">
          <Card class="w-full" empty>
            <template #header><h4 class="text-next-base font-next-semibold">Members</h4></template>
            <template #empty>
              <EmptyState
                size="sm"
                icon="users"
                title="No members yet"
                description="Invite teammates to start collaborating."
              >
                <template #action>
                  <Button size="sm" leading-icon="plus">Invite</Button>
                </template>
              </EmptyState>
            </template>
          </Card>
        </StoryCell>
      </Grid>
    </StorySection>

    <StorySection title="Interactive: hover & focus" description="The whole card is one link. Hover lifts the shadow; Tab focuses the card (one tab stop); Enter activates.">
      <Grid :cols="{ base: 1, sm: 2 }" gap="4">
        <Card variant="interactive" href="#card" action-label="Open Atlas project">
          <template #header>
            <Stack direction="horizontal" align="center" gap="2">
              <Icon name="folder" class="text-next-lg text-next-primary" />
              <h4 class="text-next-base font-next-semibold">Atlas</h4>
            </Stack>
          </template>
          <p class="text-next-sm text-next-muted-foreground">Whole-card link — hover and Tab to me.</p>
          <template #footer>
            <span>12 forms</span>
            <Icon name="chevron-right" class="ml-auto text-next-sm" />
          </template>
        </Card>
        <Card variant="interactive" as="button" action-label="Select Orion project" @activate="() => {}">
          <template #header>
            <Stack direction="horizontal" align="center" gap="2">
              <Icon name="folder" class="text-next-lg text-next-primary" />
              <h4 class="text-next-base font-next-semibold">Orion</h4>
            </Stack>
          </template>
          <p class="text-next-sm text-next-muted-foreground">Whole-card <code>button</code> (emits <code>activate</code>).</p>
          <template #footer>
            <span>4 forms</span>
            <Icon name="chevron-right" class="ml-auto text-next-sm" />
          </template>
        </Card>
      </Grid>
    </StorySection>

    <StorySection title="Selectable grid" description="A realistic selectable list: click a card to select it (single-select). Selection is ring + tint, never color alone.">
      <Grid :cols="{ base: 1, sm: 3 }" gap="4">
        <Card
          v-for="id in [1, 2, 3]"
          :key="id"
          variant="interactive"
          as="button"
          :selected="selectedId === id"
          :action-label="`Select plan ${id}`"
          @activate="selectedId = id"
        >
          <template #header>
            <Stack direction="horizontal" align="center" justify="between" gap="2">
              <h4 class="text-next-base font-next-semibold">Plan {{ id }}</h4>
              <Icon v-if="selectedId === id" name="check-circle" class="text-next-lg text-next-primary" label="Selected" />
            </Stack>
          </template>
          <p class="text-next-sm text-next-muted-foreground">${{ id * 9 }}/mo · {{ id * 5 }} seats</p>
        </Card>
      </Grid>
    </StorySection>

    <StorySection title="Inset (flush) variant" description="No padding — host a full-bleed media strip or an edge-to-edge list.">
      <div class="max-w-md">
        <Card variant="inset">
          <template #header>
            <div class="p-next-4">
              <h4 class="text-next-base font-next-semibold">Activity</h4>
            </div>
          </template>
          <ul class="divide-y divide-next-border">
            <li v-for="n in 3" :key="n" class="flex items-center gap-next-3 px-next-4 py-next-3">
              <Icon name="user" class="text-next-lg text-next-muted-foreground" />
              <span class="text-next-sm">Event row {{ n }}</span>
            </li>
          </ul>
        </Card>
      </div>
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
