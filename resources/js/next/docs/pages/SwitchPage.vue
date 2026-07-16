<script setup lang="ts">
import { ref } from 'vue';
import Switch from '../../ui/forms/Switch.vue';
import FormField from '../../ui/forms/FormField.vue';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import StoryCell from '../StoryCell.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const on = ref(true);
const off = ref(false);
const labelled = ref(true);
const pending = ref(false);
const loading = ref(false);

function fakeAsync() {
  loading.value = true;
  setTimeout(() => {
    pending.value = !pending.value;
    loading.value = false;
  }, 1400);
}

const propRows: ApiRow[] = [
  { name: 'v-model', type: 'boolean', default: 'false', description: 'On/off state.' },
  { name: 'label', type: 'string', default: '—', description: 'Clickable label text (or use the default slot).' },
  { name: 'labelPosition', type: "'leading' | 'trailing'", default: "'trailing'", description: 'Place the label before or after the switch.' },
  { name: 'loading', type: 'boolean', default: 'false', description: 'Pending async toggle: spinner in the thumb, blocks input, aria-busy.' },
  { name: 'disabled', type: 'boolean', default: 'false', description: 'Disables the control (also inherited from FormField).' },
  { name: 'size', type: "'sm' | 'md'", default: "'md'", description: 'Track + thumb size.' },
  { name: 'ariaInvalid / id / describedById / ariaLabel', type: 'string | boolean', default: '—', description: 'Standalone wiring; provided inside a FormField.' },
];
</script>

<template>
  <StoryPage
    title="Switch"
    description="An on/off toggle for immediate settings. Built on a real <button role=switch>; Space/Enter toggle. Supports a clickable leading or trailing label and a loading (pending async) state."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li><code>role="switch"</code> + <code>aria-checked</code>; native button activation means <kbd>Space</kbd>/<kbd>Enter</kbd> toggle.</li>
        <li>The whole label row is clickable; the label is associated via the wrapping <code>&lt;label&gt;</code>.</li>
        <li><code>loading</code> sets <code>aria-busy</code> and blocks toggling for the duration of a pending async change.</li>
      </ul>
    </template>

    <StorySection title="States" description="off · on · disabled (each) · loading.">
      <div class="flex flex-wrap gap-next-8">
        <StoryCell label="off"><Switch :model-value="false" /></StoryCell>
        <StoryCell label="on"><Switch :model-value="true" /></StoryCell>
        <StoryCell label="disabled off"><Switch :model-value="false" disabled /></StoryCell>
        <StoryCell label="disabled on"><Switch :model-value="true" disabled /></StoryCell>
        <StoryCell label="loading"><Switch :model-value="true" loading /></StoryCell>
      </div>
    </StorySection>

    <StorySection title="Sizes">
      <div class="flex flex-wrap items-center gap-next-8">
        <StoryCell label="sm"><Switch v-model="on" size="sm" label="Small" /></StoryCell>
        <StoryCell label="md"><Switch v-model="on" size="md" label="Medium" /></StoryCell>
      </div>
    </StorySection>

    <StorySection title="Labels" description="Trailing (default) or leading label.">
      <div class="flex flex-col gap-next-3">
        <Switch v-model="labelled" label="Enable email notifications" />
        <Switch v-model="off" label="Public profile" label-position="leading" />
      </div>
    </StorySection>

    <StorySection title="Loading (pending async)" description="Click to simulate a server round-trip — the switch shows a spinner and ignores input until it resolves.">
      <Switch :model-value="pending" :loading="loading" label="Two-factor authentication" @click="fakeAsync" />
    </StorySection>

    <StorySection title="Realistic usage (FormField)">
      <FormField label="Notifications" description="Turn submission alerts on or off for this project.">
        <Switch v-model="labelled" label="Notify me on new submissions" />
      </FormField>
    </StorySection>

    <StorySection title="API">
      <ApiTable title="Props" :rows="propRows" show-default />
    </StorySection>
  </StoryPage>
</template>
