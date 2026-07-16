<script setup lang="ts">
// Gallery: Accordion + AccordionItem — single vs multiple open, controlled vs
// uncontrolled, disabled item, icons, and the API + a11y. Text via t().
import { ref } from 'vue';
import Accordion from '../../ui/disclosure/Accordion.vue';
import AccordionItem from '../../ui/disclosure/AccordionItem.vue';
import { useI18n } from '../../app/i18n';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const { t } = useI18n();

const single = ref<string | null>('a');
const multiple = ref<string[]>(['x']);

const accordionRows: ApiRow[] = [
  { name: 'type', type: "'single' | 'multiple'", default: "'single'", description: 'At most one open, or any number open.' },
  { name: 'defaultValue', type: 'string | string[] | null', default: '—', description: 'Initial open value(s) when uncontrolled.' },
  { name: 'v-model', type: 'string | null  /  string[]', default: '—', description: 'Open value(s) — string|null for single, string[] for multiple. Omit to self-manage.' },
];
const itemRows: ApiRow[] = [
  { name: 'value', type: 'string', default: '—', description: 'Unique id within the accordion.' },
  { name: 'title', type: 'string', default: '—', description: 'Header text (or use the #header slot).' },
  { name: 'icon', type: 'IconName', default: '—', description: 'Optional leading icon in the header.' },
  { name: 'disabled', type: 'boolean', default: 'false', description: 'Non-toggleable, dimmed.' },
];
const itemSlotRows: ApiRow[] = [
  { name: 'header', type: '—', description: 'Rich header content (overrides title).' },
  { name: 'default', type: '—', description: 'Collapsible region body.' },
];
</script>

<template>
  <StoryPage
    title="Accordion"
    description="Collapsible disclosure sections. Single mode keeps at most one open; multiple allows any number. Controlled (v-model) or uncontrolled (defaultValue). Each item is a header button with a rotating chevron over a smoothly-animated region."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>Header is a real <code>&lt;button aria-expanded aria-controls&gt;</code>; the region is <code>role="region" aria-labelledby</code> the header.</li>
        <li><kbd>↑</kbd>/<kbd>↓</kbd> move between headers, <kbd>Home</kbd>/<kbd>End</kbd> jump; <kbd>Enter</kbd>/<kbd>Space</kbd> toggle.</li>
        <li>The chevron rotates on open; the height transition respects reduced motion. A collapsed region is <code>inert</code> (out of the tab order).</li>
      </ul>
    </template>

    <StorySection title="Single (uncontrolled)" description="At most one section open at a time.">
      <Accordion type="single" default-value="a">
        <AccordionItem value="a" title="What is Taskio?" icon="info">
          Taskio is a Laravel + Vue product being rebuilt with this design system.
        </AccordionItem>
        <AccordionItem value="b" title="How do I switch language?" icon="settings">
          Use the language switcher in the app shell — every string is translated.
        </AccordionItem>
        <AccordionItem value="c" title="Disabled section" disabled>
          You can’t open this one.
        </AccordionItem>
      </Accordion>
    </StorySection>

    <StorySection title="Multiple (controlled)" description="Several sections open at once; bound to a string[] v-model.">
      <div class="flex flex-col gap-next-3">
        <Accordion v-model="multiple" type="multiple">
          <AccordionItem value="x" title="Section X">First multi-open section.</AccordionItem>
          <AccordionItem value="y" title="Section Y">Second multi-open section.</AccordionItem>
          <AccordionItem value="z" title="Section Z">Third multi-open section.</AccordionItem>
        </Accordion>
        <p class="font-next-mono text-next-xs text-next-muted-foreground">open: {{ multiple.join(', ') || '—' }}</p>
      </div>
    </StorySection>

    <StorySection title="Single (controlled)" description="Bound to a string|null v-model.">
      <div class="flex flex-col gap-next-3">
        <Accordion v-model="single" type="single">
          <AccordionItem value="a" title="Alpha">Controlled section A.</AccordionItem>
          <AccordionItem value="b" title="Beta">Controlled section B.</AccordionItem>
        </Accordion>
        <p class="font-next-mono text-next-xs text-next-muted-foreground">open: {{ single ?? '—' }}</p>
      </div>
    </StorySection>

    <StorySection title="API">
      <div class="flex flex-col gap-next-6">
        <ApiTable title="Accordion props" :rows="accordionRows" show-default />
        <ApiTable title="AccordionItem props" :rows="itemRows" show-default />
        <ApiTable title="AccordionItem slots" type-header="Content" :rows="itemSlotRows" />
      </div>
    </StorySection>
  </StoryPage>
</template>
