<script setup lang="ts">
// A self-contained, backend-free example assembling the forms tier into one
// realistic form: FormField wrappers around several controls, client-side
// validation on submit, an error summary, and a submitting primary Button.
import { computed, reactive, ref } from 'vue';
import FormField from '../../ui/forms/FormField.vue';
import TextInput from '../../ui/forms/TextInput.vue';
import Textarea from '../../ui/forms/Textarea.vue';
import Select, { type SelectOption } from '../../ui/forms/Select.vue';
import RadioGroup from '../../ui/forms/RadioGroup.vue';
import Radio from '../../ui/forms/Radio.vue';
import Checkbox from '../../ui/forms/Checkbox.vue';
import Switch from '../../ui/forms/Switch.vue';
import NumberInput from '../../ui/forms/NumberInput.vue';
import Slider from '../../ui/forms/Slider.vue';
import Button from '../../ui/primitives/Button.vue';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';

const projects: SelectOption[] = [
  { value: 'website', label: 'Website', icon: 'layout-dashboard' },
  { value: 'mobile', label: 'Mobile app', icon: 'file-text' },
  { value: 'internal', label: 'Internal tools', icon: 'folder' },
];

const form = reactive({
  name: '',
  email: '',
  project: null as string | null,
  visibility: 'private' as string | null,
  seats: 3 as number | null,
  budget: 500,
  description: '',
  notify: true,
  terms: false,
});

const submitting = ref(false);
const submitted = ref(false);
const showErrors = ref(false);

const errors = computed(() => {
  const e: Record<string, string> = {};
  if (!form.name.trim()) e.name = 'Workspace name is required.';
  if (!form.email.includes('@')) e.email = 'Enter a valid email address.';
  if (!form.project) e.project = 'Choose a project type.';
  if (form.seats == null || form.seats < 1) e.seats = 'At least one seat is required.';
  if (!form.terms) e.terms = 'You must accept the terms.';
  return e;
});
const errorList = computed(() => Object.entries(errors.value));

function err(key: string): string | undefined {
  return showErrors.value ? errors.value[key] : undefined;
}

function submit() {
  showErrors.value = true;
  submitted.value = false;
  if (errorList.value.length) return;
  submitting.value = true;
  setTimeout(() => {
    submitting.value = false;
    submitted.value = true;
  }, 1400);
}
</script>

<template>
  <StoryPage
    title="Form composition"
    description="A realistic, self-contained form assembled from the Tier 3 controls — FormField wrappers, client-side validation, an error summary, and a submitting primary Button. No backend involved."
  >
    <StorySection title="Create workspace">
      <form class="flex max-w-xl flex-col gap-next-5" novalidate @submit.prevent="submit">
        <!-- Error summary -->
        <div
          v-if="showErrors && errorList.length"
          class="rounded-next-md border border-next-danger bg-next-danger-subtle p-next-3"
          role="alert"
        >
          <p class="text-next-sm font-next-semibold text-next-danger-subtle-foreground">
            Please fix {{ errorList.length }} {{ errorList.length === 1 ? 'error' : 'errors' }}:
          </p>
          <ul class="ml-next-4 mt-next-1 list-disc text-next-sm text-next-danger-subtle-foreground">
            <li v-for="[key, msg] in errorList" :key="key">{{ msg }}</li>
          </ul>
        </div>

        <div
          v-if="submitted"
          class="rounded-next-md border border-next-success bg-next-success-subtle p-next-3 text-next-sm text-next-success-subtle-foreground"
          role="status"
        >
          Workspace created.
        </div>

        <FormField label="Workspace name" required :error="err('name')">
          <TextInput v-model="form.name" placeholder="Acme HQ" />
        </FormField>

        <FormField label="Owner email" required description="The workspace owner’s contact address." :error="err('email')">
          <TextInput v-model="form.email" type="email" leading-icon="mail" placeholder="owner@acme.com" />
        </FormField>

        <FormField label="Project type" required :error="err('project')">
          <Select v-model="form.project" :options="projects" placeholder="Select a project type" />
        </FormField>

        <FormField label="Visibility">
          <RadioGroup v-model="form.visibility" orientation="horizontal" aria-label="Visibility">
            <Radio value="private" label="Private" />
            <Radio value="team" label="Team" />
            <Radio value="public" label="Public" />
          </RadioGroup>
        </FormField>

        <div class="grid gap-next-5 next-sm:grid-cols-2">
          <FormField label="Seats" required :error="err('seats')">
            <div class="w-44"><NumberInput v-model="form.seats" :min="1" :max="50" clamp-on-blur /></div>
          </FormField>

          <FormField label="Monthly budget" description="Drag or type a value.">
            <Slider v-model="form.budget" :min="0" :max="2000" :step="50" number-field prefix="$" aria-label="Monthly budget" />
          </FormField>
        </div>

        <FormField label="Description" description="Optional — shown on the workspace overview.">
          <Textarea v-model="form.description" auto-grow counter :maxlength="240" placeholder="What is this workspace for?" />
        </FormField>

        <FormField label="Preferences">
          <Switch v-model="form.notify" label="Email me on new submissions" />
        </FormField>

        <FormField :error="err('terms')">
          <Checkbox v-model="form.terms" label="I accept the terms and privacy policy" :aria-invalid="showErrors && !form.terms" />
        </FormField>

        <div class="flex justify-end gap-next-2 border-t border-next-border pt-next-4">
          <Button variant="ghost" type="button">Cancel</Button>
          <Button type="submit" :loading="submitting" leading-icon="check">
            {{ submitting ? 'Creating…' : 'Create workspace' }}
          </Button>
        </div>
      </form>
    </StorySection>
  </StoryPage>
</template>
