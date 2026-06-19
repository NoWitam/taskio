<script setup lang="ts">
import { ref } from 'vue';
import Button from '../../ui/primitives/Button.vue';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import StoryGrid from '../StoryGrid.vue';
import StoryCell from '../StoryCell.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const variants = ['primary', 'secondary', 'outline', 'ghost', 'subtle', 'danger', 'link'] as const;
const sizes = ['xs', 'sm', 'md', 'lg'] as const;

const submitting = ref(false);
function fakeSubmit() {
  submitting.value = true;
  setTimeout(() => (submitting.value = false), 1600);
}

const propRows: ApiRow[] = [
  { name: 'variant', type: "'primary' | 'secondary' | 'outline' | 'ghost' | 'subtle' | 'danger' | 'link'", default: "'primary'", description: 'Visual intent.' },
  { name: 'size', type: "'xs' | 'sm' | 'md' | 'lg' | 'icon'", default: "'md'", description: 'Control height + padding + text scale. `icon` is square.' },
  { name: 'href', type: 'string', default: '—', description: 'Renders an <a> instead of <button>.' },
  { name: 'target', type: 'string', default: '—', description: 'Anchor target; `_blank` adds rel="noopener noreferrer".' },
  { name: 'type', type: "'button' | 'submit' | 'reset'", default: "'button'", description: 'Native button type (ignored for anchors).' },
  { name: 'disabled', type: 'boolean', default: 'false', description: 'Disables the control (native attr on buttons; aria-disabled on links).' },
  { name: 'loading', type: 'boolean', default: 'false', description: 'Shows spinner, sets aria-busy, blocks activation, preserves width.' },
  { name: 'leadingIcon', type: 'IconName', default: '—', description: 'Icon before the label (replaced by spinner while loading).' },
  { name: 'trailingIcon', type: 'IconName', default: '—', description: 'Icon after the label.' },
  { name: 'fullWidth', type: 'boolean', default: 'false', description: 'Stretches to the container width.' },
  { name: 'ariaLabel', type: 'string', default: '—', description: 'Required for icon-only buttons (no visible text).' },
];

const eventRows: ApiRow[] = [
  { name: 'click', type: '(event: MouseEvent)', description: 'Emitted on activation. Suppressed while disabled or loading.' },
];

const slotRows: ApiRow[] = [
  { name: 'default', type: 'label content', description: 'Button label (omit for icon-only buttons + provide ariaLabel).' },
];
</script>

<template>
  <StoryPage
    title="Button"
    description="Primary interactive control. Renders a real <button>, or an <a> when href is set. Seven variants, five sizes, with loading, icon, and full-width states."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>Native <code>&lt;button&gt;</code> — <kbd>Enter</kbd> / <kbd>Space</kbd> activate; focus ring uses the <code>--color-next-ring</code> token.</li>
        <li>Loading sets <code>aria-busy="true"</code> and blocks activation; the spinner is decorative (the busy state carries meaning).</li>
        <li>Icon-only buttons (size <code>icon</code>) require <code>ariaLabel</code>; a dev warning fires if it is missing.</li>
        <li>Disabled <code>&lt;button&gt;</code> uses the native <code>disabled</code> attribute (non-focusable); disabled link-buttons drop their href and set <code>aria-disabled</code>.</li>
      </ul>
    </template>

    <StorySection title="Variants" description="Each variant at the default md size.">
      <StoryGrid>
        <StoryCell v-for="v in variants" :key="v" :label="v">
          <Button :variant="v">{{ v }}</Button>
        </StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection title="Sizes" description="xs · sm · md · lg, plus the square icon size.">
      <StoryGrid align="center">
        <StoryCell v-for="s in sizes" :key="s" :label="s">
          <Button :size="s">Label</Button>
        </StoryCell>
        <StoryCell label="icon">
          <Button size="icon" aria-label="Add item" leading-icon="plus" />
        </StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection title="States" description="Universal interactive states. Hover / focus-visible / active are live — hover with the mouse, Tab to focus.">
      <StoryGrid align="center">
        <StoryCell label="default"><Button>Default</Button></StoryCell>
        <StoryCell label="focus (Tab to me)"><Button>Focusable</Button></StoryCell>
        <StoryCell label="disabled"><Button disabled>Disabled</Button></StoryCell>
        <StoryCell label="loading"><Button loading>Saving</Button></StoryCell>
        <StoryCell label="disabled outline"><Button variant="outline" disabled>Disabled</Button></StoryCell>
        <StoryCell label="loading danger"><Button variant="danger" loading>Deleting</Button></StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection title="With icons" description="Leading icon, trailing icon, and icon-only (requires aria-label).">
      <StoryGrid align="center">
        <StoryCell label="leading"><Button leading-icon="plus">New form</Button></StoryCell>
        <StoryCell label="trailing"><Button variant="outline" trailing-icon="arrow-right">Continue</Button></StoryCell>
        <StoryCell label="both"><Button variant="secondary" leading-icon="download" trailing-icon="chevron-down">Export</Button></StoryCell>
        <StoryCell label="icon-only"><Button size="icon" variant="ghost" aria-label="Settings" leading-icon="settings" /></StoryCell>
        <StoryCell label="icon danger"><Button size="icon" variant="danger" aria-label="Delete" leading-icon="trash" /></StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection title="Loading keeps width (no layout shift)" description="The same button before and during loading — the control does not jump.">
      <StoryGrid align="center">
        <StoryCell label="idle"><Button variant="primary" leading-icon="check">Confirm order</Button></StoryCell>
        <StoryCell label="loading"><Button variant="primary" loading>Confirm order</Button></StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection title="Full width">
      <div class="max-w-sm">
        <Button full-width leading-icon="check">Full-width action</Button>
      </div>
    </StorySection>

    <StorySection title="Link-styled button" description="The link variant is text-only with an underline on hover; use a real Link for navigation.">
      <StoryGrid align="center">
        <StoryCell label="link"><Button variant="link">Text button</Button></StoryCell>
        <StoryCell label="link + icon"><Button variant="link" trailing-icon="arrow-right">Learn more</Button></StoryCell>
        <StoryCell label="link disabled"><Button variant="link" disabled>Disabled</Button></StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection title="As a link (href)" description="When href is set, a real anchor is rendered. External targets get rel=noopener.">
      <StoryGrid align="center">
        <StoryCell label="anchor"><Button href="#button" variant="outline">Internal anchor</Button></StoryCell>
        <StoryCell label="new tab"><Button href="https://example.com" target="_blank" variant="ghost" trailing-icon="external-link">Open external</Button></StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection title="Realistic usage" description="A modal-style action bar: cancel + a submitting primary action.">
      <div class="flex flex-col gap-next-4 rounded-next-lg border border-next-border bg-next-bg p-next-4">
        <div>
          <p class="text-next-base font-next-semibold">Delete project?</p>
          <p class="text-next-sm text-next-muted-foreground">This permanently removes the project and all its forms.</p>
        </div>
        <div class="flex justify-end gap-next-2">
          <Button variant="ghost">Cancel</Button>
          <Button variant="danger" :loading="submitting" leading-icon="trash" @click="fakeSubmit">
            {{ submitting ? 'Deleting…' : 'Delete project' }}
          </Button>
        </div>
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
