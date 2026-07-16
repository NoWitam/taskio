<script setup lang="ts">
import { ref } from 'vue';
import IconInput from '../../ui/forms/IconInput.vue';
import FormField from '../../ui/forms/FormField.vue';
import type { IconName } from '../../ui/primitives/icons';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import StoryCell from '../StoryCell.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const sizes = ['sm', 'md', 'lg'] as const;

const chosen = ref<IconName | null>('star');
const empty = ref<IconName | null>(null);
const formIcon = ref<IconName | null>('folder');

const propRows: ApiRow[] = [
  { name: 'v-model', type: 'IconName | null', default: 'null', description: 'The chosen icon name (a member of ICON_NAMES), or null.' },
  { name: 'size', type: "'sm' | 'md' | 'lg'", default: "'md'", description: 'Trigger height + text scale.' },
  { name: 'icons', type: 'IconName[]', default: 'ICON_NAMES', description: 'Override the icon pool shown in the grid.' },
  { name: 'columns', type: 'number', default: '8', description: 'Grid columns (drives ArrowUp/Down navigation).' },
  { name: 'clearable', type: 'boolean', default: 'true', description: 'Show a clear (✕) affordance + Clear button.' },
  { name: 'searchPlaceholder', type: 'string', default: "'Search icons…'", description: 'Placeholder for the search box.' },
  { name: 'placeholder', type: 'string', default: "'Select an icon…'", description: 'Trigger text when empty.' },
  { name: 'disabled / readonly', type: 'boolean', default: 'false', description: 'Disabled or read-only state.' },
  { name: 'success / dirty', type: 'boolean', default: 'false', description: 'Force the success / dirty line standalone (FormField sets these).' },
  { name: 'ariaInvalid / id / describedById / ariaLabel', type: 'string | boolean', default: '—', description: 'Standalone wiring; provided inside a FormField.' },
];

const eventRows: ApiRow[] = [
  { name: 'update:modelValue', type: 'IconName | null', description: 'Emitted when an icon is chosen or cleared.' },
];
</script>

<template>
  <StoryPage
    title="IconInput"
    description="Pick an icon from our local icon set (ICON_NAMES). A FieldShell trigger shows the chosen Icon + its name; the popover holds a searchable, scrollable grid with full keyboard grid navigation. Model is an IconName or null."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>The trigger is a <code>button</code> with <code>aria-haspopup="dialog"</code> / <code>aria-expanded</code> / <code>aria-controls</code>; the popover (FieldPopover) closes on <kbd>Esc</kbd> + outside click and returns focus to the trigger.</li>
        <li>The grid is <code>role="grid"</code>; each icon is a <code>role="gridcell"</code> with <code>aria-selected</code> and an <code>aria-label</code> of its name.</li>
        <li>Roving <code>tabindex</code>: <kbd>←</kbd>/<kbd>→</kbd> move within a row, <kbd>↑</kbd>/<kbd>↓</kbd> between rows (same column), <kbd>Home</kbd>/<kbd>End</kbd> to row ends, <kbd>Enter</kbd>/<kbd>Space</kbd> select.</li>
        <li>Opening focuses the search box; <kbd>↓</kbd> / <kbd>Enter</kbd> from search dives into the grid. The empty state has a text message (not color alone).</li>
      </ul>
    </template>

    <StorySection title="Sizes">
      <div class="grid max-w-sm gap-next-4">
        <StoryCell v-for="s in sizes" :key="s" :label="s">
          <div class="w-56"><IconInput :size="s" v-model="chosen" /></div>
        </StoryCell>
      </div>
    </StorySection>

    <StorySection title="States" description="filled · empty · readonly · disabled. Open to search + arrow-navigate the grid.">
      <div class="grid max-w-sm gap-next-4">
        <StoryCell label="filled"><div class="w-56"><IconInput v-model="chosen" /></div></StoryCell>
        <StoryCell label="empty"><div class="w-56"><IconInput v-model="empty" /></div></StoryCell>
        <StoryCell label="readonly"><div class="w-56"><IconInput :model-value="'bell'" readonly /></div></StoryCell>
        <StoryCell label="disabled"><div class="w-56"><IconInput :model-value="'bell'" disabled /></div></StoryCell>
      </div>
    </StorySection>

    <StorySection title="Restricted pool" description="Pass :icons to limit the choices (e.g. status icons only).">
      <div class="w-56">
        <IconInput
          :model-value="'check-circle'"
          :icons="['check-circle', 'x-circle', 'alert-triangle', 'alert-circle', 'info', 'help-circle']"
          :columns="6"
        />
      </div>
    </StorySection>

    <StorySection title="Validation (error / success)">
      <div class="grid max-w-sm gap-next-4">
        <FormField label="Icon" :error="empty == null ? 'Pick an icon.' : undefined">
          <div class="w-56"><IconInput v-model="empty" /></div>
        </FormField>
        <FormField label="Icon" success="Nice choice.">
          <div class="w-56"><IconInput :model-value="'heart'" success /></div>
        </FormField>
      </div>
    </StorySection>

    <StorySection title="Realistic usage (FormField)">
      <FormField
        label="Folder icon"
        required
        description="Shown next to the folder name in the sidebar."
        :error="formIcon == null ? 'Choose an icon.' : undefined"
      >
        <div class="w-56"><IconInput v-model="formIcon" /></div>
      </FormField>
    </StorySection>

    <StorySection title="API">
      <div class="flex flex-col gap-next-6">
        <ApiTable title="Props" :rows="propRows" show-default />
        <ApiTable title="Events" type-header="Payload" :rows="eventRows" />
      </div>
    </StorySection>
  </StoryPage>
</template>
