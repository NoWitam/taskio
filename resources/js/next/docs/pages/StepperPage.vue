<script setup lang="ts">
// Gallery: Stepper — horizontal/vertical, statuses, display-only vs clickable,
// and a wizard demo using the #content slot + Prev/Next. Text via t().
import { computed, ref } from 'vue';
import Stepper, { type StepItem } from '../../ui/navigation/Stepper.vue';
import Button from '../../ui/primitives/Button.vue';
import { useI18n } from '../../app/i18n';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const { t } = useI18n();

const statusSteps: StepItem[] = [
  { value: 'a', label: 'Account', description: 'Sign in', status: 'complete' },
  { value: 'b', label: 'Profile', description: 'Your details', status: 'complete' },
  { value: 'c', label: 'Payment', description: 'Card declined', status: 'error' },
  { value: 'd', label: 'Review', description: 'Confirm', status: 'current' },
  { value: 'e', label: 'Done', description: 'Finish', status: 'upcoming' },
];

const navSteps: StepItem[] = [
  { value: 's1', label: 'Details' },
  { value: 's2', label: 'Address' },
  { value: 's3', label: 'Payment' },
  { value: 's4', label: 'Confirm' },
];

const clickableActive = ref<string | null>('s2');

// Wizard demo.
const wizard = ref<string | null>('w1');
const wizardSteps: StepItem[] = [
  { value: 'w1', label: 'Details' },
  { value: 'w2', label: 'Address' },
  { value: 'w3', label: 'Confirm' },
];
const wizardIndex = computed(() => wizardSteps.findIndex((s) => s.value === wizard.value));
function goPrev(): void {
  if (wizardIndex.value > 0) wizard.value = wizardSteps[wizardIndex.value - 1].value;
}
function goNext(): void {
  if (wizardIndex.value < wizardSteps.length - 1) wizard.value = wizardSteps[wizardIndex.value + 1].value;
}

const propRows: ApiRow[] = [
  { name: 'steps', type: 'StepItem[]', default: '—', description: '{ value, label, description?, status?, disabled? } per step.' },
  { name: 'orientation', type: "'horizontal' | 'vertical'", default: "'horizontal'", description: 'Layout direction.' },
  { name: 'clickable', type: 'boolean', default: 'false', description: 'Steps are real buttons that emit navigation.' },
  { name: 'linear', type: 'boolean', default: 'false', description: 'When clickable, block jumping past the current step.' },
  { name: 'ariaLabel', type: 'string', default: '—', description: 'Accessible label for the step list.' },
  { name: 'v-model:active', type: 'string | null', default: 'null', description: 'Active step value; derives statuses + drives #content.' },
];
const eventRows: ApiRow[] = [
  { name: 'step-click', type: 'string', description: 'Emitted when a clickable step is activated.' },
  { name: 'update:active', type: 'string', description: 'Emitted when the active step changes.' },
];
const slotRows: ApiRow[] = [
  { name: 'content', type: '{ value, index, step }', description: "The active step's panel (wizard body).", },
];
</script>

<template>
  <StoryPage
    title="Stepper"
    description="A multi-step progress / wizard indicator. Horizontal or vertical; each step shows a number or a complete/error icon inside a status-toned node (never color alone). Steps can be display-only or clickable; an optional #content slot renders the active step's panel."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>Renders an ordered <code>&lt;ol&gt;</code>; the current step carries <code>aria-current="step"</code>.</li>
        <li>When <code>clickable</code>, each step is a real button whose accessible name includes the status word (e.g. “Payment, step 3: error”).</li>
        <li>Nodes (number / check / x) and connector lines are decorative; meaning is in the label + status word.</li>
      </ul>
    </template>

    <StorySection title="Statuses (horizontal)" description="complete · current · upcoming · error — explicit per-step status.">
      <Stepper :steps="statusSteps" aria-label="Checkout progress" />
    </StorySection>

    <StorySection title="Vertical">
      <Stepper :steps="statusSteps" orientation="vertical" aria-label="Checkout progress" />
    </StorySection>

    <StorySection title="Clickable (derived status)" description="Click a step to navigate; status derives from the active step.">
      <div class="flex flex-col gap-next-3">
        <Stepper v-model:active="clickableActive" :steps="navSteps" clickable aria-label="Navigation" />
        <p class="font-next-mono text-next-xs text-next-muted-foreground">active: {{ clickableActive ?? '—' }}</p>
      </div>
    </StorySection>

    <StorySection title="Wizard" description="The #content slot renders the active step's body; Prev/Next drive v-model:active.">
      <div class="flex flex-col gap-next-6">
        <Stepper v-model:active="wizard" :steps="wizardSteps" clickable linear aria-label="Setup wizard">
          <template #content="{ step }">
            <div class="rounded-next-md border border-next-border bg-next-bg p-next-4 text-next-sm">
              <p class="font-next-medium text-next-fg">{{ step.label }}</p>
              <p class="text-next-muted-foreground">Content for the “{{ step.label }}” step goes here.</p>
            </div>
          </template>
        </Stepper>
        <div class="flex gap-next-2">
          <Button variant="outline" size="sm" :disabled="wizardIndex <= 0" @click="goPrev">
            {{ t('common.previous', 'Previous') }}
          </Button>
          <Button size="sm" :disabled="wizardIndex >= wizardSteps.length - 1" @click="goNext">
            {{ t('common.next', 'Next') }}
          </Button>
        </div>
      </div>
    </StorySection>

    <StorySection title="API">
      <div class="flex flex-col gap-next-6">
        <ApiTable title="Props" :rows="propRows" show-default />
        <ApiTable title="Events" type-header="Payload" :rows="eventRows" />
        <ApiTable title="Slots" type-header="Slot props" :rows="slotRows" />
      </div>
    </StorySection>
  </StoryPage>
</template>
