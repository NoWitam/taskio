<script setup lang="ts">
import { ref } from 'vue';
import Textarea from '../../ui/forms/Textarea.vue';
import FormField from '../../ui/forms/FormField.vue';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import StoryCell from '../StoryCell.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const basic = ref('');
const filled = ref('A few lines of existing content that the user can edit freely.');
const auto = ref('Type here — the control grows to fit.\nIt won’t show an inner scrollbar.');
const counted = ref('Short bio…');
const formBio = ref('');

const propRows: ApiRow[] = [
  { name: 'v-model', type: 'string', default: "''", description: 'The textarea value.' },
  { name: 'rows', type: 'number', default: '3', description: 'Initial visible rows.' },
  { name: 'autoGrow', type: 'boolean', default: 'false', description: 'Height tracks content; disables manual resize + inner scroll.' },
  { name: 'counter', type: 'boolean', default: 'false', description: 'Shows a character counter (folds into aria-describedby).' },
  { name: 'maxlength', type: 'number', default: '—', description: 'Hard limit; feeds the counter as "n / max".' },
  { name: 'resize', type: "'none' | 'vertical'", default: "'vertical'", description: 'Manual resize affordance (ignored when autoGrow).' },
  { name: 'disabled / readonly', type: 'boolean', default: 'false', description: 'Disabled or read-only state.' },
  { name: 'success / dirty', type: 'boolean', default: 'false', description: 'Force the success / dirty line standalone (FormField sets these).' },
  { name: 'ariaInvalid / id / describedById', type: 'string | boolean', default: '—', description: 'Standalone wiring; provided automatically inside a FormField.' },
];
</script>

<template>
  <StoryPage
    title="Textarea"
    description="Multi-line text control with an optional auto-grow height, a character counter, and a resize control."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>Inside a FormField the textarea gets <code>id</code>, <code>aria-describedby</code>, <code>aria-invalid</code>, <code>aria-required</code> automatically.</li>
        <li>The character counter has its own id folded into <code>aria-describedby</code> and uses <code>aria-live="polite"</code>; it turns danger when over the limit.</li>
        <li>Shares the FieldShell visual language: the state line (focus/error/success/dirty) is an inset ring ON the border via <code>focus-within</code>. Height may auto-grow; width stays fixed.</li>
      </ul>
    </template>

    <StorySection title="States" description="default · filled · readonly · disabled · error.">
      <div class="grid max-w-md gap-next-4">
        <StoryCell label="default"><div class="w-full"><Textarea v-model="basic" placeholder="Write something…" /></div></StoryCell>
        <StoryCell label="filled"><div class="w-full"><Textarea v-model="filled" /></div></StoryCell>
        <StoryCell label="readonly"><div class="w-full"><Textarea model-value="Read-only content." readonly /></div></StoryCell>
        <StoryCell label="disabled"><div class="w-full"><Textarea model-value="Disabled content." disabled /></div></StoryCell>
        <StoryCell label="error (standalone)"><div class="w-full"><Textarea model-value="Too short" aria-invalid /></div></StoryCell>
        <StoryCell label="success (standalone)"><div class="w-full"><Textarea model-value="Looks good." success /></div></StoryCell>
        <StoryCell label="dirty (standalone)"><div class="w-full"><Textarea model-value="Changed content." dirty /></div></StoryCell>
      </div>
    </StorySection>

    <StorySection title="Auto-grow" description="Height grows with content — no inner scrollbar.">
      <div class="max-w-md"><Textarea v-model="auto" auto-grow /></div>
    </StorySection>

    <StorySection title="Character counter" description="With a maxlength the counter reads n / max and flags over-limit.">
      <div class="max-w-md"><Textarea v-model="counted" counter :maxlength="120" /></div>
    </StorySection>

    <StorySection title="Resize control" description="resize-none vs resize-y (the default).">
      <div class="grid max-w-md gap-next-4">
        <StoryCell label="resize: none"><div class="w-full"><Textarea model-value="Fixed height." resize="none" /></div></StoryCell>
        <StoryCell label="resize: vertical"><div class="w-full"><Textarea model-value="Drag the corner." resize="vertical" /></div></StoryCell>
      </div>
    </StorySection>

    <StorySection title="Realistic usage (FormField)">
      <FormField label="Bio" description="A short description shown on your public profile.">
        <Textarea v-model="formBio" counter :maxlength="160" auto-grow placeholder="Tell us about yourself…" />
      </FormField>
    </StorySection>

    <StorySection title="API">
      <ApiTable title="Props" :rows="propRows" show-default />
    </StorySection>
  </StoryPage>
</template>
