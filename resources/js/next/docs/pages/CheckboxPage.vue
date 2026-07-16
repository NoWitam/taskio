<script setup lang="ts">
import { computed, ref } from 'vue';
import Checkbox from '../../ui/forms/Checkbox.vue';
import FormField from '../../ui/forms/FormField.vue';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import StoryCell from '../StoryCell.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const a = ref(false);
const b = ref(true);
const terms = ref(false);

// Parent/child indeterminate demo.
const child = ref([true, false, false]);
const allChecked = computed(() => child.value.every(Boolean));
const someChecked = computed(() => child.value.some(Boolean) && !allChecked.value);
function toggleAll(v: boolean) {
  child.value = child.value.map(() => v);
}

const propRows: ApiRow[] = [
  { name: 'v-model', type: 'boolean', default: 'false', description: 'Checked state.' },
  { name: 'label', type: 'string', default: '—', description: 'Inline label (or use the default slot).' },
  { name: 'description', type: 'string', default: '—', description: 'Secondary line under the label.' },
  { name: 'indeterminate', type: 'boolean', default: 'false', description: 'Mixed state (dash icon + aria-checked="mixed").' },
  { name: 'disabled', type: 'boolean', default: 'false', description: 'Disables the control (also inherited from FormField).' },
  { name: 'size', type: "'sm' | 'md'", default: "'md'", description: 'Box size.' },
  { name: 'ariaInvalid / id / describedById', type: 'string | boolean', default: '—', description: 'Standalone wiring; provided inside a FormField.' },
];
</script>

<template>
  <StoryPage
    title="Checkbox"
    description="Boolean control with a third indeterminate (mixed) state. Built on a real <input type=checkbox> for native semantics + Space-to-toggle."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>Real hidden <code>&lt;input type=checkbox&gt;</code> — <kbd>Space</kbd> toggles; the whole label row is clickable.</li>
        <li>Indeterminate sets the DOM <code>indeterminate</code> property and <code>aria-checked="mixed"</code>; shown with a dash (not color alone).</li>
        <li>Focus ring follows the input via <code>peer-focus-visible</code>.</li>
      </ul>
    </template>

    <StorySection title="States" description="unchecked · checked · indeterminate, each enabled + disabled.">
      <div class="flex flex-col gap-next-4">
        <div class="flex flex-wrap gap-next-8">
          <StoryCell label="unchecked"><Checkbox :model-value="false" label="Option" /></StoryCell>
          <StoryCell label="checked"><Checkbox :model-value="true" label="Option" /></StoryCell>
          <StoryCell label="indeterminate"><Checkbox indeterminate label="Option" /></StoryCell>
        </div>
        <div class="flex flex-wrap gap-next-8">
          <StoryCell label="disabled unchecked"><Checkbox :model-value="false" disabled label="Option" /></StoryCell>
          <StoryCell label="disabled checked"><Checkbox :model-value="true" disabled label="Option" /></StoryCell>
          <StoryCell label="disabled mixed"><Checkbox indeterminate disabled label="Option" /></StoryCell>
        </div>
      </div>
    </StorySection>

    <StorySection title="With description" description="A secondary line under the label.">
      <div class="flex flex-col gap-next-3">
        <Checkbox v-model="a" label="Email notifications" description="Get notified when a form is submitted." />
        <Checkbox v-model="b" label="Weekly summary" description="A digest of activity every Monday." />
      </div>
    </StorySection>

    <StorySection title="Indeterminate parent/child" description="The parent reflects mixed state; toggling it checks/unchecks all children.">
      <div class="flex flex-col gap-next-2">
        <Checkbox
          :model-value="allChecked"
          :indeterminate="someChecked"
          label="Select all"
          @update:model-value="toggleAll"
        />
        <div class="ml-next-6 flex flex-col gap-next-2">
          <Checkbox v-model="child[0]" label="Item one" />
          <Checkbox v-model="child[1]" label="Item two" />
          <Checkbox v-model="child[2]" label="Item three" />
        </div>
      </div>
    </StorySection>

    <StorySection title="Realistic usage (FormField error)" description="Required consent with an error message.">
      <FormField :error="!terms ? 'You must accept the terms to continue.' : undefined">
        <Checkbox v-model="terms" label="I accept the terms and privacy policy" :aria-invalid="!terms" />
      </FormField>
    </StorySection>

    <StorySection title="API">
      <ApiTable title="Props" :rows="propRows" show-default />
    </StorySection>
  </StoryPage>
</template>
