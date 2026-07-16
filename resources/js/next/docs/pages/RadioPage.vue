<script setup lang="ts">
import { ref } from 'vue';
import RadioGroup from '../../ui/forms/RadioGroup.vue';
import Radio from '../../ui/forms/Radio.vue';
import FormField from '../../ui/forms/FormField.vue';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const plan = ref<string | null>('pro');
const layout = ref<string | null>('comfortable');
const horiz = ref<string | null>('weekly');
const withDisabled = ref<string | null>('a');
const formPlan = ref<string | null>(null);

const groupRows: ApiRow[] = [
  { name: 'v-model', type: 'string | null', default: 'null', description: 'Selected radio value.' },
  { name: 'name', type: 'string', default: 'auto', description: 'Shared input name; auto-generated when omitted.' },
  { name: 'orientation', type: "'vertical' | 'horizontal'", default: "'vertical'", description: 'Layout direction.' },
  { name: 'disabled', type: 'boolean', default: 'false', description: 'Disables the whole group (also inherited from FormField).' },
  { name: 'ariaLabel', type: 'string', default: '—', description: 'Accessible name when not wrapped in a labelled FormField.' },
  { name: 'ariaInvalid / describedById', type: 'string | boolean', default: '—', description: 'Standalone wiring; provided inside a FormField.' },
];

const radioRows: ApiRow[] = [
  { name: 'value', type: 'string', default: '— (required)', description: 'The value this radio represents.' },
  { name: 'label', type: 'string', default: '—', description: 'Inline label (or use the default slot).' },
  { name: 'description', type: 'string', default: '—', description: 'Secondary line under the label.' },
  { name: 'disabled', type: 'boolean', default: 'false', description: 'Disables just this option.' },
  { name: 'size', type: "'sm' | 'md'", default: "'md'", description: 'Control size.' },
];
</script>

<template>
  <StoryPage
    title="Radio / RadioGroup"
    description="A single-choice group. RadioGroup owns selection + roving tabindex; arrow keys move both focus and selection. Vertical or horizontal layout, per-option or whole-group disabled."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li><code>role="radiogroup"</code> with an accessible name (FormField label or <code>ariaLabel</code>).</li>
        <li>Roving tabindex: only the selected radio (or the first when none) is in the tab order; <kbd>↑</kbd>/<kbd>↓</kbd>/<kbd>←</kbd>/<kbd>→</kbd> move selection + focus, <kbd>Home</kbd>/<kbd>End</kbd> jump.</li>
        <li>Each radio is a real hidden <code>&lt;input type=radio&gt;</code>; the selection dot is shape-based (not color alone).</li>
      </ul>
    </template>

    <StorySection title="Vertical (default)" description="Tab into the group, then use arrow keys.">
      <FormField label="Plan">
        <RadioGroup v-model="plan" aria-label="Plan">
          <Radio value="free" label="Free" description="For personal projects." />
          <Radio value="pro" label="Pro" description="For growing teams." />
          <Radio value="enterprise" label="Enterprise" description="Advanced controls + SSO." />
        </RadioGroup>
      </FormField>
    </StorySection>

    <StorySection title="Horizontal" description="orientation=horizontal.">
      <FormField label="Frequency">
        <RadioGroup v-model="horiz" orientation="horizontal" aria-label="Frequency">
          <Radio value="daily" label="Daily" />
          <Radio value="weekly" label="Weekly" />
          <Radio value="monthly" label="Monthly" />
        </RadioGroup>
      </FormField>
    </StorySection>

    <StorySection title="Disabled" description="A single disabled option, and a fully disabled group.">
      <div class="flex flex-col gap-next-6">
        <RadioGroup v-model="withDisabled" aria-label="With a disabled option">
          <Radio value="a" label="Available" />
          <Radio value="b" label="Unavailable" disabled />
          <Radio value="c" label="Available" />
        </RadioGroup>
        <RadioGroup v-model="layout" disabled aria-label="Disabled group">
          <Radio value="compact" label="Compact" />
          <Radio value="comfortable" label="Comfortable" />
        </RadioGroup>
      </div>
    </StorySection>

    <StorySection title="Realistic usage (FormField error)">
      <FormField label="Choose a plan" required :error="!formPlan ? 'Select a plan to continue.' : undefined">
        <RadioGroup v-model="formPlan" :aria-invalid="!formPlan">
          <Radio value="free" label="Free" />
          <Radio value="pro" label="Pro" />
        </RadioGroup>
      </FormField>
    </StorySection>

    <StorySection title="API">
      <div class="flex flex-col gap-next-6">
        <ApiTable title="RadioGroup props" :rows="groupRows" show-default />
        <ApiTable title="Radio props" :rows="radioRows" show-default />
      </div>
    </StorySection>
  </StoryPage>
</template>
