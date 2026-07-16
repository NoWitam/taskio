<script setup lang="ts">
import { ref } from 'vue';
import Modal from '../../ui/overlay/Modal.vue';
import Button from '../../ui/primitives/Button.vue';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import StoryGrid from '../StoryGrid.vue';
import StoryCell from '../StoryCell.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

type ModalSize = 'sm' | 'md' | 'lg' | 'xl' | 'full';
const sizes: ModalSize[] = ['sm', 'md', 'lg', 'xl', 'full'];

const basicOpen = ref(false);
const sizeOpen = ref<ModalSize | null>(null);
const lockedOpen = ref(false); // no scrim/esc dismissal
const nestedOuter = ref(false);
const nestedInner = ref(false);

const propRows: ApiRow[] = [
  { name: 'v-model:open', type: 'boolean', default: 'false', description: 'Controlled open state.' },
  { name: 'size', type: "'sm' | 'md' | 'lg' | 'xl' | 'full'", default: "'md'", description: 'Max width (full fills the viewport with a margin).' },
  { name: 'closeOnEsc', type: 'boolean', default: 'true', description: 'Esc closes it (topmost-only).' },
  { name: 'closeOnScrim', type: 'boolean', default: 'true', description: 'Clicking the scrim closes it (topmost-only).' },
  { name: 'showClose', type: 'boolean', default: 'true', description: 'Show the built-in ✕ button in the header.' },
  { name: 'ariaLabel', type: 'string', default: '—', description: 'Accessible label when no visible #title is provided.' },
];

const eventRows: ApiRow[] = [
  { name: 'open', type: '()', description: 'Emitted after the dialog opens.' },
  { name: 'close', type: '()', description: 'Emitted after the dialog closes.' },
];

const slotRows: ApiRow[] = [
  { name: 'title', type: 'heading', description: 'Visible title; wires aria-labelledby.' },
  { name: 'description', type: 'text', description: 'Supporting text; wires aria-describedby.' },
  { name: 'default', type: 'body', description: 'Main dialog content.' },
  { name: 'footer', type: '{ close }', description: 'Action row; call `close()` to dismiss.' },
];
</script>

<template>
  <StoryPage
    title="Modal"
    description="A teleported, focus-trapped dialog with a scrim. Esc and scrim-click close it (both configurable, topmost-only via the overlay stack), body scroll is locked while open, and focus returns to the opener on close."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li><code>role="dialog"</code> + <code>aria-modal="true"</code>, labelled by the #title id and described by the #description id when present.</li>
        <li>Focus is trapped within the panel (<code>useFocusTrap</code>) and returns to the previously-focused element on close.</li>
        <li><kbd>Esc</kbd> / scrim close only the <strong>topmost</strong> modal, so stacked dialogs dismiss in order; body scroll is reference-counted across them.</li>
        <li>The ✕ button has an <code>aria-label</code>; set <code>:show-close="false"</code> for confirmation-style dialogs.</li>
      </ul>
    </template>

    <StorySection title="Basic" description="A titled dialog with description, body and a footer action row.">
      <Button @click="basicOpen = true">Open dialog</Button>
      <Modal v-model:open="basicOpen">
        <template #title>Invite teammates</template>
        <template #description>They will receive an email to join this workspace.</template>
        <p class="text-next-sm">
          Add people by email to collaborate on forms and submissions.
        </p>
        <template #footer="{ close }">
          <Button variant="outline" @click="close">Cancel</Button>
          <Button @click="close">Send invites</Button>
        </template>
      </Modal>
    </StorySection>

    <StorySection title="Sizes" description="sm → md → lg → xl → full.">
      <StoryGrid>
        <StoryCell v-for="s in sizes" :key="s" :label="s">
          <Button variant="outline" size="sm" @click="sizeOpen = s">{{ s }}</Button>
        </StoryCell>
      </StoryGrid>
      <Modal :open="sizeOpen !== null" :size="sizeOpen ?? 'md'" @close="sizeOpen = null">
        <template #title>Size: {{ sizeOpen }}</template>
        <p class="text-next-sm text-next-muted-foreground">
          This dialog uses the <code>{{ sizeOpen }}</code> size token.
        </p>
        <template #footer="{ close }">
          <Button @click="close">Close</Button>
        </template>
      </Modal>
    </StorySection>

    <StorySection title="Non-dismissable" description="closeOnEsc + closeOnScrim disabled — the user must use an explicit action.">
      <Button variant="outline" @click="lockedOpen = true">Open locked dialog</Button>
      <Modal v-model:open="lockedOpen" :close-on-esc="false" :close-on-scrim="false" :show-close="false">
        <template #title>Confirm required</template>
        <template #description>Esc and the scrim are disabled; use a button to continue.</template>
        <template #footer="{ close }">
          <Button @click="close">Got it</Button>
        </template>
      </Modal>
    </StorySection>

    <StorySection title="Stacked dialogs" description="Open a second dialog from the first — Esc / scrim dismiss only the topmost.">
      <Button variant="outline" @click="nestedOuter = true">Open outer dialog</Button>
      <Modal v-model:open="nestedOuter">
        <template #title>Outer dialog</template>
        <p class="text-next-sm">Open a nested dialog to see topmost-only dismissal.</p>
        <template #footer="{ close }">
          <Button variant="outline" @click="close">Close</Button>
          <Button @click="nestedInner = true">Open inner</Button>
        </template>
      </Modal>
      <Modal v-model:open="nestedInner" size="sm">
        <template #title>Inner dialog</template>
        <p class="text-next-sm">Esc closes me first, then the outer dialog.</p>
        <template #footer="{ close }">
          <Button @click="close">Close inner</Button>
        </template>
      </Modal>
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
