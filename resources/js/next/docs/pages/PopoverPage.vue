<script setup lang="ts">
import { ref } from 'vue';
import Popover from '../../ui/overlay/Popover.vue';
import Button from '../../ui/primitives/Button.vue';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import StoryGrid from '../StoryGrid.vue';
import StoryCell from '../StoryCell.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const placements = ['bottom-start', 'bottom-end', 'top', 'right'] as const;

// Programmatic control demo.
const manualOpen = ref(false);

const propRows: ApiRow[] = [
  { name: 'v-model:open', type: 'boolean', default: 'false', description: 'Controlled open state.' },
  { name: 'placement', type: 'Placement', default: "'bottom-start'", description: 'Preferred side/alignment relative to the trigger.' },
  { name: 'trigger', type: "'click' | 'manual'", default: "'click'", description: 'Open on trigger click, or only programmatically.' },
  { name: 'offset', type: 'number', default: '8', description: 'Gap between trigger and panel, in px.' },
  { name: 'flip', type: 'boolean', default: 'true', description: 'Flip to the opposite side on viewport overflow.' },
  { name: 'matchWidth', type: 'boolean', default: 'false', description: 'Match the panel min-width to the trigger.' },
  { name: 'autoFocus', type: 'boolean', default: 'true', description: 'Move focus into the panel on open.' },
];

const eventRows: ApiRow[] = [
  { name: 'open', type: '()', description: 'Emitted after the panel opens.' },
  { name: 'close', type: '()', description: 'Emitted after the panel closes.' },
];

const slotRows: ApiRow[] = [
  { name: 'trigger', type: '{ open, toggle, props }', description: 'Custom trigger; spread `props` for aria-haspopup/expanded/controls.' },
  { name: 'default', type: '{ open, toggle, props }', description: 'Fallback trigger slot when #trigger is not used.' },
  { name: 'content', type: '{ close }', description: 'Panel content; call `close()` to dismiss and return focus.' },
];
</script>

<template>
  <StoryPage
    title="Popover"
    description="An anchored floating panel toggled from a trigger. It is the positioning base that DropdownMenu builds on: outside-click + Esc close it (topmost-only via the overlay stack), and focus moves in and returns to the trigger."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>The trigger gets <code>aria-haspopup</code>, <code>aria-expanded</code> and <code>aria-controls</code> wired to the panel id.</li>
        <li>On open, focus moves into the panel; on close it returns to the trigger.</li>
        <li><kbd>Esc</kbd> and outside-click close it, but only when it is the <strong>topmost</strong> overlay (nested overlays dismiss in order).</li>
      </ul>
    </template>

    <StorySection title="Basic" description="Click the trigger to toggle the panel.">
      <Popover>
        <template #trigger="{ open, props }">
          <Button variant="outline" v-bind="props">{{ open ? 'Close' : 'Open' }} panel</Button>
        </template>
        <template #content="{ close }">
          <div class="w-64 p-next-4">
            <p class="text-next-sm font-next-semibold">Quick panel</p>
            <p class="mt-next-1 text-next-sm text-next-muted-foreground">
              An anchored panel that can host any non-modal content.
            </p>
            <div class="mt-next-3 flex justify-end">
              <Button size="sm" @click="close">Done</Button>
            </div>
          </div>
        </template>
      </Popover>
    </StorySection>

    <StorySection title="Placement" description="Preferred side/alignment; each flips toward the viewport on overflow.">
      <StoryGrid>
        <StoryCell v-for="p in placements" :key="p" :label="p">
          <Popover :placement="p">
            <template #trigger="{ props }">
              <Button variant="outline" size="sm" v-bind="props">{{ p }}</Button>
            </template>
            <template #content>
              <div class="w-40 p-next-3 text-next-sm">Placed <strong>{{ p }}</strong>.</div>
            </template>
          </Popover>
        </StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection title="Programmatic (manual trigger)" description="trigger=&quot;manual&quot; opens only via v-model:open — drive it from outside.">
      <div class="flex items-center gap-next-3">
        <Button variant="secondary" @click="manualOpen = true">Open externally</Button>
        <Popover v-model:open="manualOpen" trigger="manual" placement="bottom-start">
          <template #trigger>
            <span class="text-next-sm text-next-muted-foreground">(anchor)</span>
          </template>
          <template #content="{ close }">
            <div class="w-56 p-next-4">
              <p class="text-next-sm">Opened programmatically.</p>
              <div class="mt-next-3 flex justify-end">
                <Button size="sm" variant="outline" @click="close">Close</Button>
              </div>
            </div>
          </template>
        </Popover>
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
