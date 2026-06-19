<script setup lang="ts">
import Tooltip from '../../ui/overlay/Tooltip.vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import StoryGrid from '../StoryGrid.vue';
import StoryCell from '../StoryCell.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const placements = ['top', 'bottom', 'left', 'right'] as const;

const propRows: ApiRow[] = [
  { name: 'label', type: 'string', default: '—', description: 'Tooltip text (use #content for richer, still non-interactive, content).' },
  { name: 'placement', type: "Placement", default: "'top'", description: 'Preferred side; flips toward the viewport when it would overflow.' },
  { name: 'openDelay', type: 'number', default: '150', description: 'Delay before showing on hover/focus, in ms.' },
  { name: 'closeDelay', type: 'number', default: '0', description: 'Delay before hiding on leave/blur, in ms.' },
  { name: 'offset', type: 'number', default: '6', description: 'Gap between trigger and tooltip, in px.' },
  { name: 'disabled', type: 'boolean', default: 'false', description: 'Renders the trigger only; no tooltip.' },
];

const slotRows: ApiRow[] = [
  { name: 'default', type: 'trigger', description: 'The element the tooltip describes (wrapped in an inline span).' },
  { name: 'content', type: 'tooltip body', description: 'Overrides `label` for richer, non-interactive content.' },
];
</script>

<template>
  <StoryPage
    title="Tooltip"
    description="A lightweight, descriptive label shown on hover and keyboard focus. It never traps focus and must not contain interactive content — it is purely a hint."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>Opens on pointer hover <em>and</em> keyboard <kbd>focus</kbd> of the trigger, so keyboard users get the same hint.</li>
        <li>The floating label has <code>role="tooltip"</code> and a stable id; the trigger is wired via <code>aria-describedby</code> while open.</li>
        <li><kbd>Esc</kbd> dismisses an open tooltip; it never moves or traps focus.</li>
        <li>Do not put buttons or links inside a tooltip — use a Popover for interactive content.</li>
      </ul>
    </template>

    <StorySection title="Basic" description="Hover or Tab to the button to reveal the hint.">
      <StoryGrid>
        <StoryCell label="text trigger">
          <Tooltip label="Saves the current form">
            <Button variant="outline">Save</Button>
          </Tooltip>
        </StoryCell>
        <StoryCell label="icon trigger">
          <Tooltip label="More information">
            <Button variant="ghost" size="icon" aria-label="Help">
              <Icon name="help-circle" />
            </Button>
          </Tooltip>
        </StoryCell>
        <StoryCell label="disabled (no tooltip)">
          <Tooltip label="Never shown" disabled>
            <Button variant="outline">No hint</Button>
          </Tooltip>
        </StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection title="Placement" description="Preferred side; each flips toward the viewport if it would overflow.">
      <StoryGrid>
        <StoryCell v-for="p in placements" :key="p" :label="p">
          <Tooltip :label="`Placed ${p}`" :placement="p">
            <Button variant="outline" size="sm">{{ p }}</Button>
          </Tooltip>
        </StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection title="Delay" description="Tune the open delay for snappier or calmer reveals.">
      <StoryGrid>
        <StoryCell label="instant (0ms)">
          <Tooltip label="No delay" :open-delay="0">
            <Button variant="outline" size="sm">Instant</Button>
          </Tooltip>
        </StoryCell>
        <StoryCell label="slow (500ms)">
          <Tooltip label="Delayed reveal" :open-delay="500">
            <Button variant="outline" size="sm">Patient</Button>
          </Tooltip>
        </StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection title="API">
      <div class="flex flex-col gap-next-6">
        <ApiTable title="Props" :rows="propRows" show-default />
        <ApiTable title="Slots" type-header="Content" :rows="slotRows" />
      </div>
    </StorySection>
  </StoryPage>
</template>
