<script setup lang="ts">
// Gallery: LabelSelect — the global label picker. Colored label pills (the label
// color is user DATA → inline-color exception) with a mapped icon + name in both
// options and chips, and the AND/OR operator toggle living INSIDE the dropdown
// (shown whenever v-model:operator is bound, regardless of the selection count).
// Async cursor pagination via a self-contained mock loader. No real backend.
import { ref } from 'vue';
import LabelSelect from '../../ui/forms/LabelSelect.vue';
import type { SelectFetchArgs, SelectFetchResult, SelectOption } from '../../ui/forms/Select.vue';
import { resolveLabelIcon } from '../../ui/forms/labelIcon';
import FormField from '../../ui/forms/FormField.vue';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import StoryCell from '../StoryCell.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

// --- Mock /labels dataset (cursor pages of 8, 450ms latency) ---------------
interface MockLabel {
  value: string;
  label: string;
  color: string | null;
  icon: string | null; // raw backend IconEnum value (mapped via resolveLabelIcon)
}
const RAW: Array<[string, string, string | null]> = [
  ['Bug', '#ef4444', 'alert-triangle'],
  ['Feature', '#6366f1', 'sparkles'],
  ['Docs', '#0ea5e9', 'file-text'],
  ['Design', '#ec4899', 'image'],
  ['Backend', '#22c55e', 'server'],
  ['Frontend', '#f59e0b', 'layout-grid'],
  ['Urgent', '#dc2626', 'flag'],
  ['Research', '#8b5cf6', 'search'],
  ['Chore', '#64748b', null], // no icon → generic tag fallback
  ['Question', '#14b8a6', 'circle-help'],
  ['Blocked', '#b91c1c', 'lock'],
  ['Idea', '#eab308', 'sparkles'],
];
const ALL_LABELS: MockLabel[] = RAW.map(([name, color, icon], i) => ({
  value: `l${i}`,
  label: name,
  color,
  icon,
}));
const PAGE = 8;

function mockFetchLabels(args: SelectFetchArgs): Promise<SelectFetchResult> {
  const q = args.query.trim().toLowerCase();
  const matches = ALL_LABELS.filter((l) => !q || l.label.toLowerCase().includes(q));
  const start = args.cursor ? Number(args.cursor) : 0;
  const page = matches.slice(start, start + PAGE);
  const next = start + PAGE;
  const nextCursor = next < matches.length ? String(next) : null;
  return new Promise((resolve) =>
    setTimeout(
      () =>
        resolve({
          options: page.map((l) => ({
            value: l.value,
            label: l.label,
            color: l.color,
            icon: resolveLabelIcon(l.icon),
          })) as SelectOption[],
          nextCursor,
        }),
      450,
    ),
  );
}

const labels = ref<string[]>([]);
const operatorModel = ref<'AND' | 'OR'>('OR');
const withOperator = ref<string[]>(['l0', 'l1', 'l6']);
const operator = ref<'AND' | 'OR'>('AND');
const seed = [
  { id: 'l0', name: 'Bug', color: '#ef4444', icon: 'alert-triangle' },
  { id: 'l1', name: 'Feature', color: '#6366f1', icon: 'sparkles' },
  { id: 'l6', name: 'Urgent', color: '#dc2626', icon: 'flag' },
];
const formVal = ref<string[]>([]);

const propRows: ApiRow[] = [
  { name: 'v-model', type: 'string[]', default: '[]', description: 'Selected label ids.' },
  { name: 'v-model:operator', type: "'AND' | 'OR'", default: '—', description: 'Match operator. When bound, the in-dropdown All/Any toggle is always shown (regardless of how many labels are selected). Omit it (e.g. in a form) to hide the toggle entirely.' },
  { name: 'seed', type: '{ id, name, color?, icon? }[]', default: '—', description: 'Pre-known labels so chips render before/without an async page.' },
  { name: 'fetchOptions', type: '(args) => Promise<{ options, nextCursor }>', default: 'GET /labels', description: 'Loader override (defaults to the /labels endpoint). Used by the gallery/tests.' },
  { name: 'display / summary', type: "'chips' | 'summary'", default: "'chips'", description: 'Trigger display (mirrors Select).' },
  { name: 'size', type: "'sm' | 'md' | 'lg'", default: "'md'", description: 'Trigger height + text scale.' },
  { name: 'disabled / readonly', type: 'boolean', default: 'false', description: 'Inert / non-editable trigger.' },
  { name: 'success / dirty', type: 'boolean', default: 'false', description: 'Standalone state lines (auto inside a FormField).' },
  { name: 'ariaInvalid', type: 'boolean', default: '—', description: 'Force the error state standalone; omit it and the surrounding FormField decides.' },
  { name: 'placeholder / ariaLabel', type: 'string', default: 'i18n', description: 'Trigger placeholder + accessible label.' },
];
</script>

<template>
  <StoryPage
    title="LabelSelect"
    description="A global, reusable label picker built on Select. It loads from GET /labels (cursor-paginated, search) and renders each option / chip as a COLORED LABEL PILL — the label's color (user data → the inline-color exception) as a subtle tint + accent, the label's icon mapped onto the local Icon set (generic tag fallback), and the name. The AND/OR operator toggle lives inside the dropdown, not as an external control."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>Inherits Select's full combobox ARIA + keyboard. Pill color is decorative; the name carries meaning, and the colored pill always pairs color with an icon + text (never color alone).</li>
        <li>The operator toggle is a <code>SegmentedControl</code> (<code>role="radiogroup"</code>) inside the dropdown header with a helper line; it is shown whenever <code>v-model:operator</code> is bound.</li>
        <li>Strings resolve through <code>t('labelSelect.*')</code>.</li>
      </ul>
    </template>

    <StorySection title="Basic (no operator)" description="Colored pills in options + chips. Without v-model:operator the in-dropdown toggle never shows — this is the form-field usage.">
      <div class="max-w-md">
        <LabelSelect v-model="labels" :fetch-options="mockFetchLabels" />
      </div>
    </StorySection>

    <StorySection title="With in-dropdown AND/OR operator" description="Bind v-model:operator and open the dropdown: an All / Any SegmentedControl + helper line are always shown in the header (filter usage), regardless of how many labels are selected.">
      <div class="max-w-md">
        <LabelSelect v-model="withOperator" v-model:operator="operator" :seed="seed" :fetch-options="mockFetchLabels" />
        <p class="mt-next-2 text-next-xs text-next-muted-foreground">operator = {{ operator }}</p>
      </div>
    </StorySection>

    <StorySection title="Summary + sizes + disabled">
      <div class="grid max-w-md gap-next-4">
        <StoryCell label="summary"><div class="w-full"><LabelSelect v-model="labels" summary :fetch-options="mockFetchLabels" /></div></StoryCell>
        <StoryCell label="sm"><div class="w-full"><LabelSelect v-model="labels" v-model:operator="operatorModel" size="sm" :fetch-options="mockFetchLabels" /></div></StoryCell>
        <StoryCell label="disabled"><div class="w-full"><LabelSelect v-model="labels" disabled :seed="seed" :fetch-options="mockFetchLabels" /></div></StoryCell>
      </div>
    </StorySection>

    <StorySection title="Inside a FormField" description="No operator in a form — the operator is filter-only. Inherits id / aria-describedby + draws the state line automatically.">
      <FormField label="Labels" description="Up to 5 labels.">
        <LabelSelect v-model="formVal" :fetch-options="mockFetchLabels" />
      </FormField>
    </StorySection>

    <StorySection title="API">
      <ApiTable title="Props" :rows="propRows" show-default />
    </StorySection>
  </StoryPage>
</template>
