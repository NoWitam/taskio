<script setup lang="ts">
// Gallery: CommandPalette — open via a button AND a global ⌘K hint; static and
// async (skeleton) modes; grouped commands with shortcuts. All text via t().
import { ref } from 'vue';
import CommandPalette from '../../ui/overlay/CommandPalette.vue';
import { useCommandPalette, type Command } from '../../ui/overlay/commandPalette';
import Button from '../../ui/primitives/Button.vue';
import Kbd from '../../ui/primitives/Kbd.vue';
import { useToast } from '../../app/composables/useToast';
import { useI18n } from '../../app/i18n';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const { t } = useI18n();
const toast = useToast();

function fire(label: string): void {
  toast.show({ title: label });
}

// Static command set (grouped) for the global ⌘K demo.
const commands: Command[] = [
  { id: 'new-form', label: 'New form', group: 'Create', icon: 'plus', shortcut: ['mod', 'n'], keywords: ['add', 'create'], perform: () => fire('New form') },
  { id: 'new-folder', label: 'New folder', group: 'Create', icon: 'folder', keywords: ['add'], perform: () => fire('New folder') },
  { id: 'go-dashboard', label: 'Go to dashboard', group: 'Navigate', icon: 'layout-dashboard', shortcut: ['g', 'd'], perform: () => fire('Dashboard') },
  { id: 'go-inbox', label: 'Go to inbox', group: 'Navigate', icon: 'inbox', shortcut: ['g', 'i'], perform: () => fire('Inbox') },
  { id: 'go-settings', label: 'Open settings', group: 'Navigate', icon: 'settings', shortcut: ['mod', ','], perform: () => fire('Settings') },
  { id: 'toggle-theme', label: 'Toggle theme', group: 'Actions', icon: 'moon', perform: () => fire('Theme toggled') },
  { id: 'invite', label: 'Invite teammate', group: 'Actions', icon: 'users', keywords: ['member', 'user'], perform: () => fire('Invite') },
  { id: 'logout', label: 'Log out', group: 'Actions', icon: 'log-out', disabled: true, perform: () => fire('Log out') },
];

// Global ⌘K → opens the host-mounted palette.
const { open, openPalette } = useCommandPalette();

// Async demo (filtered server-side; skeleton rows while loading).
const asyncOpen = ref(false);
const ALL: Command[] = [
  { id: 'a-1', label: 'Acme Corp', group: 'Customers', icon: 'user', perform: () => fire('Acme Corp') },
  { id: 'a-2', label: 'Globex', group: 'Customers', icon: 'user', perform: () => fire('Globex') },
  { id: 'a-3', label: 'Initech', group: 'Customers', icon: 'user', perform: () => fire('Initech') },
  { id: 'a-4', label: 'Umbrella', group: 'Customers', icon: 'user', perform: () => fire('Umbrella') },
  { id: 'a-5', label: 'Weekly report', group: 'Documents', icon: 'file-text', perform: () => fire('Weekly report') },
  { id: 'a-6', label: 'Q3 invoice', group: 'Documents', icon: 'file-text', perform: () => fire('Q3 invoice') },
];
function fetchCommands(query: string): Promise<Command[]> {
  const q = query.trim().toLowerCase();
  return new Promise((resolve) => {
    setTimeout(() => {
      resolve(q ? ALL.filter((c) => c.label.toLowerCase().includes(q)) : ALL);
    }, 600);
  });
}

const propRows: ApiRow[] = [
  { name: 'v-model:open', type: 'boolean', default: 'false', description: 'Controls visibility.' },
  { name: 'commands', type: 'Command[]', default: '—', description: 'Static commands (filtered locally over label + keywords).' },
  { name: 'fetchCommands', type: '(q) => Promise<Command[]>', default: '—', description: 'Async provider; debounced; enables skeleton-loading mode.' },
  { name: 'placeholder', type: 'string', default: '(i18n)', description: 'Search input placeholder.' },
  { name: 'ariaLabel', type: 'string', default: '(i18n)', description: 'Dialog accessible label.' },
  { name: 'debounce', type: 'number', default: '200', description: 'Async fetch debounce (ms).' },
];
const eventRows: ApiRow[] = [
  { name: 'select', type: 'Command', description: 'Emitted before a command runs.' },
  { name: 'open / close', type: '—', description: 'Visibility lifecycle.' },
];
const cmdShape: ApiRow[] = [
  { name: 'id', type: 'string', description: 'Stable, unique id.' },
  { name: 'label', type: 'string', description: 'Visible label (primary search target).' },
  { name: 'group', type: 'string?', description: 'Group heading bucket.' },
  { name: 'icon', type: 'IconName?', description: 'Leading icon.' },
  { name: 'keywords', type: 'string[]?', description: 'Extra search terms.' },
  { name: 'shortcut', type: 'string[]?', description: 'Shown via <Kbd>.' },
  { name: 'disabled', type: 'boolean?', description: 'Not selectable / dimmed.' },
  { name: 'perform', type: '() => void', description: 'Runs on activation; palette then closes.' },
];
</script>

<template>
  <StoryPage
    title="Command palette"
    :description="t('story.cmdk.desc', 'A ⌘K / Ctrl+K command launcher: a teleported, focus-trapped centered overlay (reusing the Modal mechanics) with an autofocused search and a grouped, keyboard-navigable command list. Static or async (skeleton) data.')"
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>{{ t('story.cmdk.a11y1', 'role="dialog" trap + body lock + overlay stack (topmost-only Esc), exactly like Modal.') }}</li>
        <li>{{ t('story.cmdk.a11y2', 'The input is role="combobox" with aria-controls / aria-activedescendant; the list is role="listbox" of role="option".') }}</li>
        <li>{{ t('story.cmdk.a11y3', '↑/↓ move (skip disabled, wrap), Enter runs + closes, Esc closes, typing filters.') }}</li>
      </ul>
    </template>

    <StorySection
      :title="t('story.cmdk.global', 'Global ⌘K')"
      :description="t('story.cmdk.globalDesc', 'useCommandPalette() registers a global shortcut; the host mounts one palette. Open it from the button or press the shortcut.')"
    >
      <div class="flex flex-wrap items-center gap-next-3">
        <Button leading-icon="search" @click="openPalette">
          {{ t('story.cmdk.openBtn', 'Open command palette') }}
        </Button>
        <span class="inline-flex items-center gap-next-2 text-next-sm text-next-muted-foreground">
          {{ t('story.cmdk.or', 'or press') }} <Kbd :keys="['mod', 'k']" />
        </span>
      </div>
      <CommandPalette v-model:open="open" :commands="commands" />
    </StorySection>

    <StorySection
      :title="t('story.cmdk.async', 'Async mode (skeletons)')"
      :description="t('story.cmdk.asyncDesc', 'fetchCommands(query) is debounced; option-row skeletons show while loading (never a spinner). Type to filter server-side.')"
    >
      <Button variant="outline" leading-icon="search" @click="asyncOpen = true">
        {{ t('story.cmdk.openAsync', 'Open async palette') }}
      </Button>
      <CommandPalette
        v-model:open="asyncOpen"
        :fetch-commands="fetchCommands"
        :placeholder="t('story.cmdk.searchCustomers', 'Search customers and documents…')"
      />
    </StorySection>

    <StorySection title="API">
      <div class="flex flex-col gap-next-6">
        <ApiTable title="Props" :rows="propRows" show-default />
        <ApiTable title="Events" type-header="Payload" :rows="eventRows" />
        <ApiTable title="Command" type-header="Type" :rows="cmdShape" />
      </div>
    </StorySection>
  </StoryPage>
</template>
