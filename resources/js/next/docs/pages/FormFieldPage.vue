<script setup lang="ts">
import { ref } from 'vue';
import FormField from '../../ui/forms/FormField.vue';
import TextInput from '../../ui/forms/TextInput.vue';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const email = ref('');
const project = ref('');
const required = ref('');
const dirtyName = ref('Acme HQ');
const okSlug = ref('acme-hq');
const firstName = ref('');
const lastName = ref('');

const propRows: ApiRow[] = [
  { name: 'label', type: 'string', default: '—', description: 'Visible field label, wired to the control via `for`.' },
  { name: 'required', type: 'boolean', default: 'false', description: 'Adds an asterisk + (required) SR text; sets aria-required on the control.' },
  { name: 'description', type: 'string', default: '—', description: 'Help text under the label; linked via aria-describedby.' },
  { name: 'error', type: 'string', default: '—', description: 'Error message; marks the field invalid (danger line + alert icon).' },
  { name: 'success', type: 'string', default: '—', description: 'Success message; shows the success line + check icon (when no error).' },
  { name: 'disabled', type: 'boolean', default: 'false', description: 'Dims the label + control(s) and disables them.' },
  { name: 'readonly', type: 'boolean', default: 'false', description: 'Passes read-only down to the control(s).' },
  { name: 'id', type: 'string', default: 'auto', description: 'Explicit control id; auto-generated when omitted.' },
  { name: 'hideLabel', type: 'boolean', default: 'false', description: 'Visually hides the label (kept for screen readers).' },
];

const slotRows: ApiRow[] = [
  { name: 'default', type: '{ id, describedById, invalid, valid, dirty, validationState, disabled, readonly, required }', description: 'The control(s). Context-aware controls consume provide/inject; others can bind the scoped-slot payload. Several controls may live here under one label.' },
];
</script>

<template>
  <StoryPage
    title="FormField"
    description="The wrapper that owns labelling, description/help, error message, and all id + aria wiring for every form control. Controls inside it consume a provided context; the same values are also exposed via a scoped slot."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li><code>&lt;label for&gt;</code> is wired to the control <code>id</code> (auto-generated).</li>
        <li>Description + error get stable ids and are joined into <code>aria-describedby</code> on the control.</li>
        <li>Error sets <code>aria-invalid</code> and renders an alert icon + message — never color alone. The message uses <code>role="alert"</code>; <code>success</code> uses a check icon + <code>role="status"</code>.</li>
        <li><strong>Validation state</strong> (<code>error</code> &gt; <code>success</code> &gt; <code>dirty</code> &gt; <code>none</code>) is forwarded to each control's FieldShell to draw the matching line. <code>dirty</code> is tracked automatically across all controls in the field.</li>
        <li>Required renders a visible <code>*</code> plus visually-hidden “(required)” and sets <code>aria-required</code>.</li>
      </ul>
    </template>

    <StorySection title="Anatomy" description="Label, required marker, description, control, and error message.">
      <div class="grid max-w-md gap-next-5">
        <FormField label="Project name">
          <TextInput v-model="project" placeholder="Acme website" />
        </FormField>

        <FormField label="Work email" required description="We’ll only use this for account notifications.">
          <TextInput v-model="email" type="email" placeholder="you@company.com" />
        </FormField>

        <FormField
          label="Slug"
          required
          description="Lowercase, no spaces."
          error="Slug is already taken."
        >
          <TextInput v-model="required" placeholder="acme" />
        </FormField>

        <FormField label="Disabled field" description="The whole field is dimmed and the control is disabled." disabled>
          <TextInput model-value="Read-only value" />
        </FormField>
      </div>
    </StorySection>

    <StorySection title="Validation states" description="error · success · dirty. Error/success render an icon + message; dirty is a subtle line only (changed, not yet validated).">
      <div class="grid max-w-md gap-next-5">
        <FormField label="Workspace name" description="Edit the value — the field goes “dirty” (subtle accent) until validated." :id="'ff-dirty'">
          <TextInput v-model="dirtyName" />
        </FormField>

        <FormField label="Slug" success="Available." :id="'ff-success'">
          <TextInput v-model="okSlug" />
        </FormField>

        <FormField label="Slug" error="That slug is already taken." :id="'ff-error'">
          <TextInput model-value="taken-slug" />
        </FormField>
      </div>
    </StorySection>

    <StorySection title="Multiple controls under one label" description="One label / description / message hosting several controls. Dirty tracking spans all of them.">
      <FormField label="Full name" description="First and last name." required>
        <div class="grid grid-cols-2 gap-next-3">
          <TextInput v-model="firstName" placeholder="First" aria-label="First name" />
          <TextInput v-model="lastName" placeholder="Last" aria-label="Last name" />
        </div>
      </FormField>
    </StorySection>

    <StorySection title="Scoped slot (non-aware control)" description="A FormField also exposes its id/aria wiring via a scoped slot, so a plain element can be wired by hand.">
      <FormField v-slot="{ id, describedById, invalid }" label="Native control" description="Bound via the scoped slot payload.">
        <input
          :id="id"
          :aria-describedby="describedById"
          :aria-invalid="invalid || undefined"
          class="h-10 w-full max-w-md rounded-next-md border border-next-input bg-next-card px-next-3 text-next-sm"
          placeholder="Plain <input> wired by hand"
        />
      </FormField>
    </StorySection>

    <StorySection title="API">
      <div class="flex flex-col gap-next-6">
        <ApiTable title="Props" :rows="propRows" show-default />
        <ApiTable title="Slots" type-header="Scoped payload" :rows="slotRows" />
      </div>
    </StorySection>
  </StoryPage>
</template>
