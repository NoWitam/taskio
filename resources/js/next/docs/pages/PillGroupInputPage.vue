<script setup lang="ts">
import { ref } from 'vue';
import PillGroupInput from '../../ui/forms/PillGroupInput.vue';
import FormField from '../../ui/forms/FormField.vue';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import StoryCell from '../StoryCell.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const sizes = ['sm', 'md', 'lg'] as const;

const tags = ref<string[]>(['design', 'frontend']);
const many = ref<string[]>([
  'alpha', 'beta', 'gamma', 'delta', 'epsilon', 'zeta', 'eta', 'theta', 'iota', 'kappa', 'lambda', 'mu',
]);
const single = ref<string[]>(['only-row']);
const emails = ref<string[]>([]);
const capped = ref<string[]>(['one', 'two']);
const skills = ref<string[]>(['vue']);
const formTags = ref<string[]>([]);
const collapsed = ref<string[]>([
  'design', 'frontend', 'backend', 'accessibility', 'performance', 'testing', 'docs', 'devops',
]);

const techSuggestions = [
  'vue', 'react', 'svelte', 'angular', 'laravel', 'rails', 'django', 'node', 'go', 'rust', 'python',
];

function validateEmail(value: string): boolean | string {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value) || 'not a valid email';
}

const propRows: ApiRow[] = [
  { name: 'v-model', type: 'string[]', default: '[]', description: 'The list of tags.' },
  { name: 'size', type: "'sm' | 'md' | 'lg'", default: "'md'", description: 'Control height + text scale.' },
  { name: 'maxRows', type: 'number', default: '3', description: 'Scroll mode: visible pill rows before the area scrolls (caps height — no-grow). 1 = single-row scroll.' },
  { name: 'overflow', type: "'scroll' | 'collapse'", default: "'scroll'", description: "Overflow behaviour. 'scroll' caps height to maxRows and scrolls; 'collapse' keeps a single fixed row and hides overflow behind a +N pill." },
  { name: 'collapse', type: 'boolean', default: 'false', description: "Shorthand for overflow=\"collapse\". Single fixed-size row; overflowing pills collapse into a +N pill with a hover list + interactive remove panel." },
  { name: 'max', type: 'number', default: '—', description: 'Maximum number of pills; adding is blocked at the cap.' },
  { name: 'addOnComma', type: 'boolean', default: 'true', description: 'Also commit a pill on comma (and on paste of comma/newline lists).' },
  { name: 'validate', type: '(v) => boolean | string', default: '—', description: 'Reject a value; return a string for a reason.' },
  { name: 'allowed', type: 'string[]', default: '—', description: 'Restrict additions to this allow-list (also seeds suggestions).' },
  { name: 'suggestions', type: 'string[]', default: '—', description: 'Autocomplete pool shown in a dropdown as you type.' },
  { name: 'transform', type: "'none' | 'lower' | 'trim'", default: "'trim'", description: 'Normalize added values (dedupe runs after).' },
  { name: 'disabled / readonly', type: 'boolean', default: 'false', description: 'Disabled or read-only state.' },
  { name: 'success / dirty', type: 'boolean', default: 'false', description: 'Force the success / dirty line standalone (FormField sets these).' },
  { name: 'ariaInvalid / id / describedById / ariaLabel', type: 'string | boolean', default: '—', description: 'Standalone wiring; provided inside a FormField.' },
];

const eventRows: ApiRow[] = [
  { name: 'update:modelValue', type: 'string[]', description: 'Emitted on every add/remove.' },
  { name: 'add', type: 'string', description: 'A pill was added.' },
  { name: 'remove', type: 'string', description: 'A pill was removed.' },
  { name: 'invalid', type: '(value, reason)', description: 'A value was rejected (validation / not-allowed / max).' },
];
</script>

<template>
  <StoryPage
    title="PillGroupInput"
    description="A tag / token input rendered through FieldShell. Type + Enter (or comma) to add a pill; Backspace removes the last when the text is empty; each pill is removable by mouse or keyboard. Dedupe, an optional validate predicate, a max count, and an allowed/suggestions autocomplete are supported. Model is string[]."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>The text field is a <code>role="combobox"</code> with <code>aria-expanded</code> / <code>aria-controls</code> / <code>aria-activedescendant</code> wired to the suggestion <code>listbox</code> (when suggestions are provided).</li>
        <li><kbd>Enter</kbd> / <kbd>,</kbd> add the typed value (or the highlighted suggestion); <kbd>Backspace</kbd> on an empty field removes the last pill; <kbd>↑</kbd>/<kbd>↓</kbd> move through suggestions; <kbd>Esc</kbd> closes the suggestion list.</li>
        <li>Each pill is a Badge with a focusable, labelled remove (✕) operable by mouse + keyboard.</li>
        <li>Pasting a comma/newline-separated list adds each value at once.</li>
        <li>Collapse mode: the <code>+N</code> pill is a button (<code>aria-expanded</code> / <code>aria-controls</code>) labelled with the hidden values; hover/focus shows a read-only tooltip, <kbd>Enter</kbd>/click opens an interactive panel where each hidden pill has a labelled remove (✕).</li>
      </ul>
    </template>

    <StorySection title="Sizes">
      <div class="grid max-w-md gap-next-4">
        <StoryCell v-for="s in sizes" :key="s" :label="s">
          <div class="w-80"><PillGroupInput :size="s" v-model="tags" /></div>
        </StoryCell>
      </div>
    </StorySection>

    <StorySection title="No-grow (maxRows)" description="The pill area caps its height at maxRows and scrolls internally — the field never grows unbounded. maxRows=1 gives a single scrolling row.">
      <div class="grid max-w-md gap-next-4">
        <StoryCell label="maxRows=1 (single row)"><div class="w-80"><PillGroupInput v-model="many" :max-rows="1" /></div></StoryCell>
        <StoryCell label="maxRows=2"><div class="w-80"><PillGroupInput v-model="many" :max-rows="2" /></div></StoryCell>
        <StoryCell label="maxRows=3 (default)"><div class="w-80"><PillGroupInput v-model="many" /></div></StoryCell>
      </div>
    </StorySection>

    <StorySection title="States" description="filled · empty · readonly · disabled.">
      <div class="grid max-w-md gap-next-4">
        <StoryCell label="filled"><div class="w-80"><PillGroupInput v-model="tags" /></div></StoryCell>
        <StoryCell label="empty"><div class="w-80"><PillGroupInput v-model="emails" placeholder="Add a tag…" /></div></StoryCell>
        <StoryCell label="readonly"><div class="w-80"><PillGroupInput v-model="single" readonly /></div></StoryCell>
        <StoryCell label="disabled"><div class="w-80"><PillGroupInput v-model="single" disabled /></div></StoryCell>
      </div>
    </StorySection>

    <StorySection
      title="Collapse mode (fixed size, +N overflow)"
      description="With :collapse the control keeps the SAME single-row fixed width + height. Pills that don't fit collapse into a +N pill — hover/focus it to see the hidden values, or click/Enter to open an interactive remove panel. Fit is measured with the SHARED useChipOverflow composable (also used by Select multi mode); resizing recomputes which pills show. The row NEVER scrolls horizontally — the first chip's left edge is always fully visible — and the +N panel is teleported above everything, so it is never clipped by the field. Try narrowing the cells below, and open the +N panel near the page edge."
    >
      <div class="grid gap-next-4">
        <StoryCell label="collapse — narrow (first chip stays visible)"><div class="w-56"><PillGroupInput v-model="collapsed" collapse placeholder="Add a tag…" /></div></StoryCell>
        <StoryCell label="collapse — wide"><div class="w-96"><PillGroupInput v-model="collapsed" collapse placeholder="Add a tag…" /></div></StoryCell>
        <StoryCell label="resize me — overflow is width-driven">
          <div class="resize-x overflow-auto rounded-next-md border border-dashed border-next-border p-next-2" style="min-width: 10rem; max-width: 100%; width: 18rem">
            <PillGroupInput v-model="collapsed" collapse placeholder="Add a tag…" />
          </div>
        </StoryCell>
      </div>
    </StorySection>

    <StorySection
      title="Collapse — +N panel escapes a clipping container"
      description="The +N interactive panel is teleported to <body> on the popover z-layer, so it renders ABOVE an ancestor with overflow:hidden instead of being cut off. Open the +N below to confirm it isn't clipped by the bordered, clipped wrapper."
    >
      <div class="h-16 overflow-hidden rounded-next-md border border-next-border p-next-2">
        <div class="w-64"><PillGroupInput v-model="collapsed" collapse placeholder="Add a tag…" /></div>
      </div>
    </StorySection>

    <StorySection title="Suggestions (autocomplete)" description="Type to filter the suggestion pool; ↑/↓ then Enter, or click, to add.">
      <div class="w-80"><PillGroupInput v-model="skills" :suggestions="techSuggestions" placeholder="Add a skill…" /></div>
    </StorySection>

    <StorySection title="Max count" description=":max=4 — adding is blocked once full (emits invalid).">
      <div class="w-80"><PillGroupInput v-model="capped" :max="4" /></div>
    </StorySection>

    <StorySection title="Validation predicate" description="Only valid emails are accepted; invalid entries are rejected (try 'foo' then Enter).">
      <FormField label="Invite emails" description="Press Enter or comma after each address." :error="emails.length === 0 ? 'Add at least one email.' : undefined">
        <PillGroupInput v-model="emails" :validate="validateEmail" transform="lower" placeholder="name@example.com" />
      </FormField>
    </StorySection>

    <StorySection title="Realistic usage (FormField)">
      <FormField
        label="Tags"
        required
        description="Used to filter and group items. Press Enter or comma to add."
        :error="formTags.length === 0 ? 'Add at least one tag.' : undefined"
      >
        <PillGroupInput v-model="formTags" :max="8" />
      </FormField>
    </StorySection>

    <StorySection title="API">
      <div class="flex flex-col gap-next-6">
        <ApiTable title="Props" :rows="propRows" show-default />
        <ApiTable title="Events" type-header="Payload" :rows="eventRows" />
      </div>
    </StorySection>
  </StoryPage>
</template>
