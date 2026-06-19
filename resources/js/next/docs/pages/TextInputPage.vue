<script setup lang="ts">
import { ref } from 'vue';
import TextInput from '../../ui/forms/TextInput.vue';
import FormField from '../../ui/forms/FormField.vue';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import StoryGrid from '../StoryGrid.vue';
import StoryCell from '../StoryCell.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const sizes = ['sm', 'md', 'lg'] as const;

const text = ref('');
const filled = ref('Acme Inc.');
const pwd = ref('hunter2');
const searchV = ref('invoices');
const loadingV = ref('checking…');
const formEmail = ref('');
const longV = ref(
  'A very long value that overflows and must truncate with an ellipsis instead of widening the control',
);

const propRows: ApiRow[] = [
  { name: 'v-model', type: 'string', default: "''", description: 'The input value.' },
  { name: 'type', type: "'text' | 'email' | 'password' | 'search' | 'url' | 'tel'", default: "'text'", description: 'Input type. password adds a show/hide toggle; search defaults to clearable.' },
  { name: 'size', type: "'sm' | 'md' | 'lg'", default: "'md'", description: 'Control height + text scale.' },
  { name: 'placeholder', type: 'string', default: '—', description: 'Placeholder text.' },
  { name: 'disabled', type: 'boolean', default: 'false', description: 'Disables the control (also inherited from FormField).' },
  { name: 'readonly', type: 'boolean', default: 'false', description: 'Read-only; value shown, not editable.' },
  { name: 'leadingIcon', type: 'IconName', default: '—', description: 'Icon inside the control, before the text.' },
  { name: 'trailingIcon', type: 'IconName', default: '—', description: 'Icon inside the control, after the text.' },
  { name: 'loading', type: 'boolean', default: 'false', description: 'Trailing spinner + aria-busy.' },
  { name: 'clearable', type: 'boolean', default: 'false', description: 'Show a clear (✕) button when filled. Auto-on for search.' },
  { name: 'ariaInvalid', type: 'boolean', default: '—', description: 'Force invalid styling standalone (FormField sets this).' },
  { name: 'success', type: 'boolean', default: 'false', description: 'Force the success line standalone (FormField sets this).' },
  { name: 'dirty', type: 'boolean', default: 'false', description: 'Force the subtle dirty accent standalone (FormField tracks this).' },
  { name: 'id / describedById', type: 'string', default: '—', description: 'Standalone wiring; provided automatically inside a FormField.' },
  { name: 'autocomplete / name', type: 'string', default: '—', description: 'Native passthrough attributes.' },
];

const slotRows: ApiRow[] = [
  { name: 'leading', type: 'addon', description: 'Custom leading addon (overrides leadingIcon position).' },
  { name: 'trailing', type: 'addon', description: 'Custom trailing addon (e.g. a unit or button).' },
];

const eventRows: ApiRow[] = [
  { name: 'clear', type: '()', description: 'Emitted when the clear button is pressed.' },
];
</script>

<template>
  <StoryPage
    title="TextInput"
    description="Single-line text control: text / email / password / search / url, with leading/trailing icons or addons, a loading spinner, a clear button, and a password show/hide toggle."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>The visible border + focus ring live on the wrapper (<code>focus-within</code>); the inner <code>&lt;input&gt;</code> drops its own outline to avoid a double ring.</li>
        <li>Inside a FormField the input gets <code>id</code>, <code>aria-describedby</code>, <code>aria-invalid</code>, and <code>aria-required</code> automatically.</li>
        <li>Clear, password-toggle buttons have <code>aria-label</code>s; the toggle also exposes <code>aria-pressed</code>.</li>
        <li><code>loading</code> sets <code>aria-busy</code>; the spinner is decorative.</li>
      </ul>
    </template>

    <StorySection title="Sizes" description="sm · md · lg, consistent across the control family.">
      <StoryGrid align="center">
        <StoryCell v-for="s in sizes" :key="s" :label="s">
          <div class="w-56"><TextInput :size="s" v-model="text" placeholder="Placeholder" /></div>
        </StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection title="States" description="placeholder · filled · readonly · disabled · error. Hover + focus are live.">
      <div class="grid max-w-md gap-next-4">
        <StoryCell label="placeholder"><div class="w-full"><TextInput placeholder="Placeholder text" /></div></StoryCell>
        <StoryCell label="filled"><div class="w-full"><TextInput v-model="filled" /></div></StoryCell>
        <StoryCell label="readonly"><div class="w-full"><TextInput model-value="Read-only value" readonly /></div></StoryCell>
        <StoryCell label="disabled"><div class="w-full"><TextInput model-value="Disabled" disabled /></div></StoryCell>
        <StoryCell label="error (standalone)"><div class="w-full"><TextInput model-value="bad value" aria-invalid /></div></StoryCell>
        <StoryCell label="success (standalone)"><div class="w-full"><TextInput model-value="looks good" success /></div></StoryCell>
        <StoryCell label="dirty (standalone)"><div class="w-full"><TextInput model-value="changed value" dirty /></div></StoryCell>
      </div>
    </StorySection>

    <StorySection title="No-grow / truncation" description="A long value truncates with an ellipsis — the control keeps a fixed width + height.">
      <div class="w-64"><TextInput v-model="longV" /></div>
    </StorySection>

    <StorySection title="Types & affordances" description="Type-specific behaviors.">
      <div class="grid max-w-md gap-next-4">
        <StoryCell label="email + leading icon"><div class="w-full"><TextInput type="email" leading-icon="mail" placeholder="you@company.com" /></div></StoryCell>
        <StoryCell label="password (show/hide)"><div class="w-full"><TextInput type="password" v-model="pwd" /></div></StoryCell>
        <StoryCell label="search (clear)"><div class="w-full"><TextInput type="search" leading-icon="search" v-model="searchV" placeholder="Search…" /></div></StoryCell>
        <StoryCell label="url + trailing icon"><div class="w-full"><TextInput type="url" trailing-icon="external-link" placeholder="https://" /></div></StoryCell>
        <StoryCell label="loading (trailing spinner)"><div class="w-full"><TextInput v-model="loadingV" loading /></div></StoryCell>
        <StoryCell label="clearable text"><div class="w-full"><TextInput v-model="filled" clearable /></div></StoryCell>
        <StoryCell label="addon slot (trailing)">
          <div class="w-full">
            <TextInput placeholder="0.00">
              <template #trailing><span class="pr-next-2 text-next-sm text-next-muted-foreground">USD</span></template>
            </TextInput>
          </div>
        </StoryCell>
      </div>
    </StorySection>

    <StorySection title="Realistic usage (FormField)" description="Wrapped in a FormField with label, help text, and validation.">
      <FormField label="Work email" required description="We’ll send a confirmation here." :error="formEmail && !formEmail.includes('@') ? 'Enter a valid email address.' : undefined">
        <TextInput v-model="formEmail" type="email" leading-icon="mail" placeholder="you@company.com" />
      </FormField>
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
