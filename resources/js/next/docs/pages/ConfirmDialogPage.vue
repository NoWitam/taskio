<script setup lang="ts">
import { ref } from 'vue';
import ConfirmDialog from '../../ui/overlay/ConfirmDialog.vue';
import Button from '../../ui/primitives/Button.vue';
import { useConfirm } from '../../app/composables/useConfirm';
import { useToast } from '../../app/composables/useToast';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const confirm = useConfirm();
const toast = useToast();

// Declarative usage.
const declarativeOpen = ref(false);
const declarativeResult = ref('—');

// Imperative usage.
const imperativeResult = ref('—');

async function askDefault(): Promise<void> {
  const ok = await confirm({
    title: 'Publish this form?',
    message: 'It will become visible to anyone with the link.',
    confirmLabel: 'Publish',
  });
  imperativeResult.value = ok ? 'confirmed' : 'cancelled';
}

async function askDanger(): Promise<void> {
  const ok = await confirm({
    title: 'Delete form?',
    message: 'This permanently removes the form and all its submissions.',
    confirmLabel: 'Delete',
    variant: 'danger',
  });
  imperativeResult.value = ok ? 'deleted' : 'cancelled';
}

async function askAsync(): Promise<void> {
  try {
    await confirm({
      title: 'Archive workspace?',
      message: 'Runs an async action while the dialog shows a spinner.',
      confirmLabel: 'Archive',
      onConfirm: () => new Promise((resolve) => setTimeout(resolve, 1200)),
    });
    toast.success('Workspace archived');
  } catch {
    toast.danger('Could not archive workspace');
  }
}

const propRows: ApiRow[] = [
  { name: 'v-model:open', type: 'boolean', default: 'false', description: 'Controlled open state (declarative usage).' },
  { name: 'title', type: 'string', default: "'Are you sure?'", description: 'Dialog heading.' },
  { name: 'message', type: 'string', default: '—', description: 'Body text (or use the default slot).' },
  { name: 'confirmLabel', type: 'string', default: "'Confirm'", description: 'Confirm button label.' },
  { name: 'cancelLabel', type: 'string', default: "'Cancel'", description: 'Cancel button label.' },
  { name: 'variant', type: "'default' | 'danger'", default: "'default'", description: 'Danger styles the confirm button + focuses Cancel by default.' },
  { name: 'loading', type: 'boolean', default: 'false', description: 'Spinner on confirm; blocks Esc / scrim / cancel.' },
];

const eventRows: ApiRow[] = [
  { name: 'confirm', type: '()', description: 'Confirm activated (declarative usage).' },
  { name: 'cancel', type: '()', description: 'Cancelled / dismissed via Esc, scrim or Cancel.' },
];

const imperativeRows: ApiRow[] = [
  { name: 'confirm(opts)', type: 'Promise<boolean>', description: 'Resolves true when confirmed, false when cancelled/dismissed.' },
  { name: 'opts.onConfirm', type: '() => void | Promise<void>', description: 'Async action; dialog shows loading until it settles. A throw keeps it open and rejects.' },
];
</script>

<template>
  <StoryPage
    title="ConfirmDialog"
    description="A confirm/cancel dialog built on Modal. Use it declaratively with v-model, or imperatively via useConfirm() — backed by a single ConfirmHost mounted once in App.vue."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>Inherits Modal semantics: <code>role="dialog"</code>, <code>aria-modal</code>, labelled title + described message, focus trap.</li>
        <li><strong>danger</strong> variant focuses the <em>Cancel</em> button by default, so the destructive action is never the accidental Enter target.</li>
        <li>While <code>loading</code>, Esc / scrim / Cancel are blocked and the confirm button shows a spinner with <code>aria-busy</code>.</li>
      </ul>
    </template>

    <StorySection title="Imperative — useConfirm()" description="await confirm({...}) returns a boolean; the shared ConfirmHost renders it.">
      <div class="flex flex-wrap items-center gap-next-3">
        <Button variant="outline" @click="askDefault">Confirm (default)</Button>
        <Button variant="danger" @click="askDanger">Delete (danger)</Button>
        <Button variant="secondary" @click="askAsync">Async confirm</Button>
        <p class="text-next-sm text-next-muted-foreground">
          Result: <span class="font-next-medium text-next-fg">{{ imperativeResult }}</span>
        </p>
      </div>
    </StorySection>

    <StorySection title="Declarative — v-model" description="Drive open state yourself and handle @confirm / @cancel.">
      <div class="flex items-center gap-next-3">
        <Button variant="outline" @click="declarativeOpen = true">Open confirm</Button>
        <p class="text-next-sm text-next-muted-foreground">
          Outcome: <span class="font-next-medium text-next-fg">{{ declarativeResult }}</span>
        </p>
      </div>
      <ConfirmDialog
        v-model:open="declarativeOpen"
        title="Discard changes?"
        message="Your unsaved edits will be lost."
        confirm-label="Discard"
        variant="danger"
        @confirm="declarativeResult = 'discarded'"
        @cancel="declarativeResult = 'kept'"
      />
    </StorySection>

    <StorySection title="Where the host mounts" description="Imperative confirms need exactly one host.">
      <p class="text-next-sm text-next-muted-foreground">
        <code>&lt;ConfirmHost /&gt;</code> is mounted once in
        <code>resources/js/next/App.vue</code>, so <code>useConfirm()</code> works
        anywhere in the app and in this gallery without per-page wiring.
      </p>
    </StorySection>

    <StorySection title="API">
      <div class="flex flex-col gap-next-6">
        <ApiTable title="Props (declarative)" :rows="propRows" show-default />
        <ApiTable title="Events (declarative)" type-header="Payload" :rows="eventRows" />
        <ApiTable title="useConfirm()" type-header="Signature" :rows="imperativeRows" />
      </div>
    </StorySection>
  </StoryPage>
</template>
