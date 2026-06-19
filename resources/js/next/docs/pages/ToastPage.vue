<script setup lang="ts">
import Toast from '../../ui/overlay/Toast.vue';
import Button from '../../ui/primitives/Button.vue';
import { useToast, type ToastVariant } from '../../app/composables/useToast';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const toast = useToast();

const variants: ToastVariant[] = ['success', 'info', 'warning', 'danger'];

function fire(variant: ToastVariant): void {
  const copy: Record<ToastVariant, { title: string; description: string }> = {
    success: { title: 'Form saved', description: 'Your changes are live.' },
    info: { title: 'Heads up', description: 'A new version is available.' },
    warning: { title: 'Almost out of space', description: 'You have used 90% of your quota.' },
    danger: { title: 'Save failed', description: 'We could not reach the server.' },
  };
  toast[variant](copy[variant].title, { description: copy[variant].description });
}

function fireWithAction(): void {
  toast.danger('Delete failed', {
    description: 'The form could not be deleted.',
    action: { label: 'Retry', onClick: () => toast.success('Retried successfully') },
  });
}

function fireSticky(): void {
  toast.info('Sticky notice', {
    description: 'This stays until dismissed manually (duration: null).',
    duration: null,
  });
}

function fireMany(): void {
  variants.forEach((v, i) => setTimeout(() => fire(v), i * 250));
}

const useToastRows: ApiRow[] = [
  { name: 'success / info / warning / danger', type: '(title, opts?) => id', description: 'Push a toast of that variant; returns its id.' },
  { name: 'show', type: '(opts) => id', description: 'Low-level push with full ToastOptions.' },
  { name: 'dismiss', type: '(id) => void', description: 'Dismiss a toast by id.' },
  { name: 'clear', type: '() => void', description: 'Dismiss all toasts.' },
];

const optionRows: ApiRow[] = [
  { name: 'title', type: 'string', default: '—', description: 'Bold heading (carries the meaning, never color alone).' },
  { name: 'description', type: 'string', default: '—', description: 'Optional supporting line.' },
  { name: 'duration', type: 'number | null', default: '5000', description: 'Auto-dismiss after N ms; null / 0 makes it sticky.' },
  { name: 'action', type: '{ label, onClick }', default: '—', description: 'Optional action button.' },
];

const viewportRows: ApiRow[] = [
  { name: 'position', type: "'top|bottom-left|center|right'", default: "'top-right'", description: 'Corner / edge the stack anchors to. Defaults to top-right; for top positions the NEWEST toast renders at the top (newest-first) and slides in from above.' },
  { name: 'maxVisible', type: 'number', default: '4', description: 'How many render at once; the rest stay queued.' },
];
</script>

<template>
  <StoryPage
    title="Toast"
    description="Transient notifications pushed via useToast() and rendered by a single ToastViewport. Variants pair an icon + colored accent with text (never color alone), auto-dismiss with pause-on-hover, and optional actions."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>The viewport is a <code>role="status"</code> / <code>aria-live="polite"</code> region, so new toasts are announced without stealing focus.</li>
        <li>Status is conveyed by the variant icon <em>and</em> the title text — never color alone.</li>
        <li>Hovering or focusing a toast pauses its auto-dismiss timer; each has a labelled ✕ dismiss button.</li>
      </ul>
    </template>

    <StorySection title="Live triggers" description="Fire toasts into the global viewport (bottom-right).">
      <div class="flex flex-wrap items-center gap-next-3">
        <Button v-for="v in variants" :key="v" variant="outline" size="sm" @click="fire(v)">
          {{ v }}
        </Button>
        <Button variant="secondary" size="sm" @click="fireWithAction">With action</Button>
        <Button variant="secondary" size="sm" @click="fireSticky">Sticky</Button>
        <Button variant="secondary" size="sm" @click="fireMany">Stack of 4</Button>
      </div>
    </StorySection>

    <StorySection title="Variant appearance" description="Static rendering of each variant (icon + accent + text).">
      <div class="flex max-w-sm flex-col gap-next-3">
        <Toast variant="success" title="Form saved" description="Your changes are live." />
        <Toast variant="info" title="Heads up" description="A new version is available." />
        <Toast variant="warning" title="Almost out of space" description="You have used 90% of your quota." />
        <Toast
          variant="danger"
          title="Save failed"
          description="We could not reach the server."
          :action="{ label: 'Retry', onClick: () => {} }"
        />
      </div>
    </StorySection>

    <StorySection title="Where the viewport mounts" description="Toasts need exactly one viewport.">
      <p class="text-next-sm text-next-muted-foreground">
        <code>&lt;ToastViewport /&gt;</code> is mounted once in
        <code>resources/js/next/App.vue</code> and teleports to <code>&lt;body&gt;</code>,
        so <code>useToast()</code> works anywhere in the app and in this gallery.
      </p>
    </StorySection>

    <StorySection
      title="Position &amp; stacking order"
      description="The viewport defaults to top-right: toasts appear at the TOP and the NEWEST stacks at the top, sliding in from above. They render above modals (z-toast 1050 > modal 1030). Fire a few in sequence to watch the newest land on top."
    >
      <div class="flex flex-wrap gap-next-3">
        <Button variant="primary" @click="fireMany">Fire 4 in sequence</Button>
        <Button variant="secondary" @click="() => fire('success')">Fire one more</Button>
      </div>
    </StorySection>

    <StorySection title="API">
      <div class="flex flex-col gap-next-6">
        <ApiTable title="useToast()" type-header="Signature" :rows="useToastRows" />
        <ApiTable title="ToastOptions" :rows="optionRows" show-default />
        <ApiTable title="ToastViewport props" :rows="viewportRows" show-default />
      </div>
    </StorySection>
  </StoryPage>
</template>
