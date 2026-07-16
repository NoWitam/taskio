<script setup lang="ts">
import { ref } from 'vue';
import DropdownMenu from '../../ui/overlay/DropdownMenu.vue';
import DropdownMenuItem from '../../ui/overlay/DropdownMenuItem.vue';
import DropdownMenuLabel from '../../ui/overlay/DropdownMenuLabel.vue';
import DropdownMenuSeparator from '../../ui/overlay/DropdownMenuSeparator.vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const lastAction = ref('—');
function pick(action: string): void {
  lastAction.value = action;
}

const menuPropRows: ApiRow[] = [
  { name: 'v-model:open', type: 'boolean', default: 'false', description: 'Controlled open state.' },
  { name: 'placement', type: 'Placement', default: "'bottom-start'", description: 'Preferred side/alignment of the menu.' },
  { name: 'offset', type: 'number', default: '6', description: 'Gap between trigger and menu, in px.' },
  { name: 'matchWidth', type: 'boolean', default: 'false', description: 'Match the menu min-width to the trigger.' },
  { name: 'ariaLabel', type: 'string', default: '—', description: 'Accessible label for the menu region.' },
];

const itemPropRows: ApiRow[] = [
  { name: 'icon', type: 'IconName', default: '—', description: 'Leading icon.' },
  { name: 'shortcut', type: 'string', default: '—', description: 'Trailing keyboard-shortcut hint.' },
  { name: 'disabled', type: 'boolean', default: 'false', description: 'Skipped by keyboard nav; not activatable.' },
  { name: 'destructive', type: 'boolean', default: 'false', description: 'Danger styling for destructive actions.' },
  { name: 'label', type: 'string', default: '—', description: 'Type-ahead label when content is not plain text.' },
];

const itemEventRows: ApiRow[] = [
  { name: 'select', type: '()', description: 'Emitted on activation (click / Enter / Space) before the menu closes.' },
];
</script>

<template>
  <StoryPage
    title="DropdownMenu"
    description="A menu button + popup menu built on Popover. Items support a leading icon, a trailing shortcut hint, disabled and destructive states, plus group labels and separators."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>The menu is <code>role="menu"</code> with <code>role="menuitem"</code> children; the active item is tracked via <code>aria-activedescendant</code> (items are not tab stops).</li>
        <li><kbd>↑</kbd>/<kbd>↓</kbd> roving (skips disabled, wraps), <kbd>Home</kbd>/<kbd>End</kbd> jump to first/last, type-ahead jumps by label prefix.</li>
        <li><kbd>Enter</kbd>/<kbd>Space</kbd> activate; <kbd>Esc</kbd> closes and restores focus to the trigger; <kbd>Tab</kbd> closes the menu.</li>
        <li>Disabled items set <code>aria-disabled</code>; destructive items use danger color <em>and</em> a label, never color alone.</li>
      </ul>
    </template>

    <StorySection title="Basic" description="Open and choose an action. The last selection is shown below.">
      <div class="flex items-center gap-next-4">
        <DropdownMenu aria-label="Actions">
          <template #trigger="{ open, props }">
            <Button variant="outline" trailing-icon="chevron-down" v-bind="props">
              Actions
            </Button>
          </template>
          <DropdownMenuItem icon="eye" @select="pick('View')">View</DropdownMenuItem>
          <DropdownMenuItem icon="file-text" shortcut="⌘E" @select="pick('Edit')">Edit</DropdownMenuItem>
          <DropdownMenuItem icon="download" @select="pick('Export')">Export</DropdownMenuItem>
          <DropdownMenuSeparator />
          <DropdownMenuItem icon="trash" destructive @select="pick('Delete')">Delete</DropdownMenuItem>
        </DropdownMenu>
        <p class="text-next-sm text-next-muted-foreground">
          Last action: <span class="font-next-medium text-next-fg">{{ lastAction }}</span>
        </p>
      </div>
    </StorySection>

    <StorySection title="Groups, labels & separators" description="Organise items under labelled groups with separators. Icon-only trigger.">
      <DropdownMenu aria-label="Account menu" placement="bottom-end">
        <template #trigger="{ props }">
          <Button variant="ghost" size="icon" aria-label="Open account menu" v-bind="props">
            <Icon name="more-horizontal" />
          </Button>
        </template>
        <DropdownMenuLabel>Account</DropdownMenuLabel>
        <DropdownMenuItem icon="user" @select="pick('Profile')">Profile</DropdownMenuItem>
        <DropdownMenuItem icon="settings" shortcut="⌘," @select="pick('Settings')">Settings</DropdownMenuItem>
        <DropdownMenuSeparator />
        <DropdownMenuLabel>Workspace</DropdownMenuLabel>
        <DropdownMenuItem icon="users" @select="pick('Members')">Members</DropdownMenuItem>
        <DropdownMenuItem icon="bell" disabled>Notifications (soon)</DropdownMenuItem>
        <DropdownMenuSeparator />
        <DropdownMenuItem icon="log-out" destructive @select="pick('Sign out')">Sign out</DropdownMenuItem>
      </DropdownMenu>
    </StorySection>

    <StorySection
      title="Item appearance"
      description="Static rendering of each item state (the active/hover highlight is shown via the live menus above)."
    >
      <div class="max-w-xs rounded-next-lg border border-next-border bg-next-popover py-next-1 text-next-popover-foreground shadow-next-md">
        <DropdownMenuLabel>States</DropdownMenuLabel>
        <DropdownMenuItem icon="check">Default item</DropdownMenuItem>
        <DropdownMenuItem icon="file-text" shortcut="⌘K">With shortcut</DropdownMenuItem>
        <DropdownMenuItem icon="bell" disabled>Disabled item</DropdownMenuItem>
        <DropdownMenuSeparator />
        <DropdownMenuItem icon="trash" destructive>Destructive item</DropdownMenuItem>
      </div>
    </StorySection>

    <StorySection title="API">
      <div class="flex flex-col gap-next-6">
        <ApiTable title="DropdownMenu props" :rows="menuPropRows" show-default />
        <ApiTable title="DropdownMenuItem props" :rows="itemPropRows" show-default />
        <ApiTable title="DropdownMenuItem events" type-header="Payload" :rows="itemEventRows" />
      </div>
    </StorySection>
  </StoryPage>
</template>
