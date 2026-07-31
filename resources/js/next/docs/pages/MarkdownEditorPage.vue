<script setup lang="ts">
import { computed, ref } from 'vue';
import MarkdownEditor from '../../ui/editor/MarkdownEditor.vue';
import MarkdownViewer from '../../ui/editor/MarkdownViewer.vue';
import FormField from '../../ui/forms/FormField.vue';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const SAMPLE = [
  '# Release notes',
  '',
  'Taskio **v2** ships with *faster* exports, ~~legacy~~ <u>refreshed</u> theming,',
  'and `inline code` everywhere.',
  '',
  '## Highlights',
  '',
  '- Faster CSV export',
  '- New dark mode',
  '  - Token-driven',
  '  - No flashes',
  '- Bulk actions',
  '',
  '> Upgrade is zero-downtime.',
  '',
  '```ts',
  'const ok: boolean = true;',
  '```',
  '',
  '| Plan | Seats | Price |',
  '| :--- | :---: | ---: |',
  '| Free | 3 | $0 |',
  '| Team | 25 | $49 |',
  '',
  '[Read the docs](https://taskio.test/docs)',
  '',
  '---',
].join('\n');

const live = ref(SAMPLE);
const empty = ref('');
const counted = ref('Type here to watch the live word and character counters update.');
const formValue = ref('');

// --- PART 2 demos -----------------------------------------------------------
import type { MentionItem } from '../../ui/editor/extensions';
import type {
  AiTextFeatureConfig,
  VariableDefinition,
  VariableFeatureConfig,
  VariablePrimitive,
} from '../../ui/editor/extensions/types';
import { standardOperationsCatalog } from '../../ui/editor/extensions/standardOperations';
import { getVariableIconLabel } from '../../ui/editor/extensions/operationHelpers';

// Mock mention source with deliberate latency so the SKELETON rows are visible.
const MOCK_USERS: MentionItem[] = [
  { id: 'u_1', label: 'Alice Johnson', avatar: '' },
  { id: 'u_2', label: 'Bob Smith', avatar: '' },
  { id: 'u_3', label: 'Carmen Diaz', avatar: '' },
  { id: 'u_4', label: 'Dmitri Volkov', avatar: '' },
  { id: 'u_5', label: 'Esi Mensah', avatar: '' },
];
async function fetchMentions(query: string): Promise<MentionItem[]> {
  await new Promise((r) => setTimeout(r, 650)); // skeleton-visible latency
  const q = query.toLowerCase();
  return q ? MOCK_USERS.filter((u) => u.label.toLowerCase().includes(q)) : MOCK_USERS;
}

// Realistic variable config: a few of each type + an operations catalog whose
// type-flow lets a user build a BOOLEAN result for an IF condition.
const DEMO_VARIABLES: VariableDefinition[] = [
  { id: 'customer_name', name: 'Customer name', type: 'text' },
  { id: 'order_note', name: 'Order note', type: 'text' },
  { id: 'order_total', name: 'Order total', type: 'number' },
  { id: 'item_count', name: 'Item count', type: 'number' },
  { id: 'is_vip', name: 'Is VIP', type: 'boolean' },
  // Extended vocabulary: enum/multi carry their OPTIONS (they feed the
  // sourceOption/sourceOptions comparison args); date uses ISO YYYY-MM-DD.
  { id: 'delivery_date', name: 'Delivery date', type: 'date' },
  {
    id: 'priority',
    name: 'Priority',
    type: 'enum',
    options: [
      { label: 'Low', value: 'low' },
      { label: 'Medium', value: 'medium' },
      { label: 'High', value: 'high' },
    ],
  },
  {
    id: 'channels',
    name: 'Channels',
    type: 'multi',
    options: [
      { label: 'E-mail', value: 'email' },
      { label: 'SMS', value: 'sms' },
      { label: 'Push', value: 'push' },
    ],
  },
];
// The FULL standard catalog (66 ops, shared with the app) — computed so labels
// follow the active locale.
const DEMO_VARIABLE_CONFIG = computed<VariableFeatureConfig>(() => ({
  variables: DEMO_VARIABLES,
  operationsCatalog: standardOperationsCatalog(),
  trigger: '{',
}));

// --- The operations reference (rendered from the LIVE catalog, never drifts) --
const OPS_TYPE_ORDER: VariablePrimitive[] = ['text', 'number', 'boolean', 'date', 'enum', 'multi'];
const opsReference = computed(() =>
  OPS_TYPE_ORDER.map((type) => ({
    type,
    heading: getVariableIconLabel(type),
    rows: standardOperationsCatalog()
      .filter((op) => op.inputTypes.includes(type))
      .map<ApiRow>((op) => ({
        name: op.label,
        type: getVariableIconLabel(op.outputType),
        description: (op.args ?? []).map((a) => `${a.label} (${a.type})`).join(', ') || '—',
      })),
  })),
);
// `personas` is now ONLY the label source for a LEGACY tone (a block saved before the
// per-block author existed); the panel never offers it for selection. Labels are retired
// (both real hosts pass `labelsEnabled: false`), so the demo mirrors production.
const DEMO_AI_CONFIG: AiTextFeatureConfig = {
  personas: [
    { id: 'friendly', label: 'Friendly' },
    { id: 'formal', label: 'Formal' },
    { id: 'concise', label: 'Concise' },
  ],
  labelsEnabled: false,
};

const mentionDoc = ref('Ping @[mention]("{\\"v\\":1,\\"data\\":{\\"id\\":\\"u_1\\",\\"name\\":\\"Alice Johnson\\",\\"avatar\\":\\"\\"}}") for review. Type @ to add another.');
const variableDoc = ref('Hello @[variable]("{\\"v\\":1,\\"data\\":{\\"id\\":\\"customer_name\\",\\"name\\":\\"Customer name\\",\\"type\\":\\"text\\",\\"locked\\":false,\\"pipeline\\":[],\\"resultType\\":\\"text\\"}}"), your order total is @[variable]("{\\"v\\":1,\\"data\\":{\\"id\\":\\"order_total\\",\\"name\\":\\"Order total\\",\\"type\\":\\"number\\",\\"locked\\":false,\\"pipeline\\":[],\\"resultType\\":\\"number\\"}}"). Type { to add more.');
const ifBlockDoc = ref([
  'Order summary:',
  '',
  '```if-block {"id":"if_1","v":1}',
  '[[IF {"id":"b1","condition":{"variableId":"is_vip","pipeline":[],"resultType":"boolean"}}]]',
  'Thanks for being a VIP member!',
  '[[ELSE {"id":"b2"}]]',
  'Welcome to the store.',
  '```',
].join('\n'));
const aiDoc = ref('Generate the intro here: @[ai-text]("{\\"v\\":1,\\"data\\":{\\"id\\":\\"ai_1\\",\\"personaId\\":null,\\"prompt\\":\\"Write a warm welcome.\\",\\"labels\\":[\\"intro\\"]}}") then continue.');

const FORMAT_EXAMPLES = [
  { node: 'Mention', code: '@[mention]("{\\"v\\":1,\\"data\\":{\\"id\\":\\"u_1\\",\\"name\\":\\"Alice\\",\\"avatar\\":\\"/a.png\\"}}")' },
  { node: 'Variable', code: '@[variable]("{\\"v\\":1,\\"data\\":{\\"id\\":\\"total\\",\\"name\\":\\"Order total\\",\\"type\\":\\"number\\",\\"locked\\":false,\\"pipeline\\":[],\\"resultType\\":\\"number\\"}}")' },
  { node: 'AI text', code: '@[ai-text]("{\\"v\\":1,\\"data\\":{\\"id\\":\\"ai_1\\",\\"personaId\\":null,\\"prompt\\":\\"…md…\\",\\"labels\\":[\\"intro\\"]}}")' },
  {
    node: 'If-block',
    code: [
      '```if-block {"id":"if_1","v":1}',
      '[[IF {"id":"b1","condition":{"variableId":"var_bool","pipeline":[],"resultType":"boolean"}}]]',
      '…branch body markdown…',
      '[[ELSE {"id":"b2"}]]',
      '…branch body markdown…',
      '```',
    ].join('\n'),
  },
];

const shortcuts: { keys: string; action: string }[] = [
  { keys: '⌘/Ctrl + B', action: 'Bold' },
  { keys: '⌘/Ctrl + I', action: 'Italic' },
  { keys: '⌘/Ctrl + U', action: 'Underline' },
  { keys: '⌘/Ctrl + Shift + S', action: 'Strikethrough' },
  { keys: '⌘/Ctrl + E', action: 'Inline code' },
  { keys: '⌘/Ctrl + K', action: 'Add / edit link' },
  { keys: '⌘/Ctrl + Z', action: 'Undo' },
  { keys: '⌘/Ctrl + Shift + Z', action: 'Redo' },
  { keys: '⌘/Ctrl + Shift + 7 / 8', action: 'Ordered / bullet list' },
  { keys: 'Tab / Shift+Tab', action: 'Indent / outdent inside a list' },
  { keys: '# , ## , ###', action: 'Heading (input rule, on space)' },
  { keys: '- , 1. , > , ```', action: 'List / quote / code (input rules)' },
];

const propRows: ApiRow[] = [
  { name: 'v-model', type: 'string', default: "''", description: 'The markdown content. Serialized on edit, parsed on external set.' },
  { name: 'placeholder', type: 'string', default: "'Write something…'", description: 'Shown when the document is empty.' },
  { name: 'disabled', type: 'boolean', default: 'false', description: 'Not editable + toolbar disabled (also inherited from FormField).' },
  { name: 'readonly', type: 'boolean', default: 'false', description: 'Not editable; toolbar hidden (view-only).' },
  { name: 'hideToolbar', type: 'boolean', default: 'false', description: 'Hide the toolbar while keeping editing.' },
  { name: 'loading', type: 'boolean', default: 'false', description: 'Paragraph-shaped skeleton instead of the editor.' },
  { name: 'counter', type: 'boolean', default: 'false', description: 'Show a word + character counter footer.' },
  { name: 'maxlength', type: 'number', default: '—', description: 'Soft character limit; turns the counter danger-colored past it.' },
  { name: 'minHeight / maxHeight', type: 'string (CSS)', default: "'8rem' / '24rem'", description: 'Content height bounds; content scrolls internally past maxHeight.' },
  { name: 'ariaInvalid / success / dirty', type: 'boolean', default: 'false', description: 'Force the field state line standalone (FormField sets these).' },
  { name: 'extensions', type: 'AnyExtension[]', default: '—', description: 'Raw Tiptap extensions merged after the core schema (escape hatch).' },
  { name: 'mentions', type: '{ fetch: (q) => Promise<MentionItem[]> }', default: '—', description: 'PART 2: enable @-mentions with an async source. Triggered by typing @.' },
  { name: 'variables', type: '{ variables: VariableDefinition[]; operationsCatalog: VariableOperationDefinition[]; trigger? }', default: '—', description: 'PART 2: enable template variables. Inserted via a trigger (default {); click a chip to build its operations pipeline in a Modal. Six value types (text/number/boolean/date/enum/multi); enum/multi definitions carry options, consumed by sourceOption/sourceOptions operation args.' },
  { name: 'ifBlocks', type: 'boolean | { maxElseIf?; maxDepth? }', default: 'false', description: 'PART 2: enable conditional if-blocks (inline-editable branches + boolean conditions). maxDepth defaults to 3.' },
  { name: 'aiText', type: 'boolean | { personas?; labelsEnabled?; labelsCatalog? }', default: 'false', description: 'PART 2: enable AI-text chips + the sparkles toolbar button. The panel edits the block AUTHOR (a bot, picked with BotSelect — it contributes a VOICE, not knowledge or tools) and the nested-editor prompt. `personas` is now only the LABEL SOURCE for a legacy tone a block was saved with (read-only, clearable); `labelsEnabled` / `labelsCatalog` are retired — the labels field is no longer rendered, though existing `labels` data is carried through untouched.' },
  { name: 'id / describedById / ariaLabel', type: 'string', default: '—', description: 'Standalone wiring; provided automatically inside a FormField.' },
];

const viewerRows: ApiRow[] = [
  { name: 'source', type: 'string', default: "''", description: 'The markdown to render (marked + DOMPurify).' },
  { name: 'openLinksInNewTab', type: 'boolean', default: 'false', description: 'Add target=_blank + safe rel to links.' },
  { name: 'ariaLabel', type: 'string', default: '—', description: 'Accessible label for the rendered region.' },
];

const eventRows: ApiRow[] = [
  { name: 'update:modelValue', type: '(markdown: string)', description: 'Emitted when the content changes (only when the serialized markdown differs).' },
];
</script>

<template>
  <StoryPage
    title="MarkdownEditor"
    description="Flagship Tiptap-based rich-text control whose v-model is a markdown string. Mirrors the FieldShell state language (height grows like Textarea), integrates with FormField, and ships a grouped toolbar with link + table flows. MarkdownViewer renders the same markdown read-only."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>The editable surface is a <code>role="textbox"</code> with <code>aria-multiline</code>; inside a FormField it gets <code>id</code>, <code>aria-describedby</code> and <code>aria-invalid</code>.</li>
        <li>Toolbar is a <code>role="toolbar"</code>; every icon button has an <code>aria-label</code>, toggles expose <code>aria-pressed</code>, and disabled when the command can't run.</li>
        <li>Tooltips carry the label + shortcut for each control; the block-type and table menus are full keyboard-navigable DropdownMenus.</li>
        <li>The state line + focus ring live on the shell border (offset 0), matching every other control; the content drops its own outline.</li>
        <li>Loading shows paragraph-shaped skeletons inside a <code>role="status"</code> region.</li>
        <li><strong>PART 2 chips</strong> (mention/variable/AI) are atomic nodes: arrow into one to select it (ring), then <kbd>Backspace</kbd>/<kbd>Delete</kbd> removes it as a single unit; variable/AI chips open their edit <strong>Modal</strong> on <kbd>Enter</kbd>/click (<code>aria-haspopup="dialog"</code>, focus-trapped).</li>
        <li>The <strong>@-mention</strong> and <strong>{-variable</strong> popups are <code>role="listbox"</code> with <code>role="option"</code> rows + <code>aria-activedescendant</code>; <kbd>↑</kbd>/<kbd>↓</kbd> move, <kbd>Enter</kbd> selects, <kbd>Esc</kbd> closes. The mention source is async (option-shaped <strong>skeleton rows</strong> while loading, never a spinner); the variable source is the predefined list with local fuzzy filter.</li>
        <li><strong>If-block branches</strong> are real inline editor regions (full marks/mentions/variables/nested if-blocks). Each IF/ELSE-IF header carries a boolean-valid status icon; editing a condition opens a Modal that blocks saving unless the pipeline resolves to <code>boolean</code>. Nesting is capped (depth 3) — the toolbar insert disables at the cap.</li>
      </ul>
    </template>

    <StorySection title="Live editor ↔ markdown" description="Edit on the left; the serialized markdown and the rendered viewer update live on the right.">
      <div class="grid gap-next-4 next-lg:grid-cols-2">
        <MarkdownEditor v-model="live" counter />
        <div class="flex flex-col gap-next-4">
          <div>
            <p class="mb-next-1 text-next-xs font-next-semibold text-next-muted-foreground">Serialized markdown</p>
            <pre class="max-h-80 overflow-auto rounded-next-md border border-next-border bg-next-muted p-next-3 font-next-mono text-next-xs whitespace-pre-wrap">{{ live }}</pre>
          </div>
          <div>
            <p class="mb-next-1 text-next-xs font-next-semibold text-next-muted-foreground">MarkdownViewer</p>
            <div class="rounded-next-md border border-next-border bg-next-card">
              <MarkdownViewer :source="live" />
            </div>
          </div>
        </div>
      </div>
    </StorySection>

    <StorySection title="States" description="default · error · success · dirty · disabled · readonly. Focus is live (click in).">
      <div class="grid gap-next-4 next-lg:grid-cols-2">
        <div>
          <p class="mb-next-1 text-next-xs text-next-muted-foreground">default (empty + placeholder)</p>
          <MarkdownEditor v-model="empty" placeholder="Start writing in markdown…" />
        </div>
        <div>
          <p class="mb-next-1 text-next-xs text-next-muted-foreground">error</p>
          <MarkdownEditor model-value="This field has a problem." aria-invalid />
        </div>
        <div>
          <p class="mb-next-1 text-next-xs text-next-muted-foreground">success</p>
          <MarkdownEditor model-value="Looks good." success />
        </div>
        <div>
          <p class="mb-next-1 text-next-xs text-next-muted-foreground">dirty</p>
          <MarkdownEditor model-value="Changed but not yet validated." dirty />
        </div>
        <div>
          <p class="mb-next-1 text-next-xs text-next-muted-foreground">disabled</p>
          <MarkdownEditor model-value="**Disabled** content — not editable." disabled />
        </div>
        <div>
          <p class="mb-next-1 text-next-xs text-next-muted-foreground">readonly (toolbar hidden)</p>
          <MarkdownEditor model-value="**Read-only** view. The toolbar is hidden." readonly />
        </div>
      </div>
    </StorySection>

    <StorySection title="Loading" description="Paragraph-shaped skeletons (skeleton rule: mimic the element, show several).">
      <MarkdownEditor model-value="" loading />
    </StorySection>

    <StorySection title="Counter + soft maxLength" description="Word + character counters; the character count turns danger-colored past maxLength (no hard block).">
      <MarkdownEditor v-model="counted" counter :maxlength="80" />
    </StorySection>

    <StorySection title="Link & table flows" description="Use the link button (⌘/Ctrl+K) to add/edit/remove a link; insert a table, then the contextual table-options menu appears.">
      <MarkdownEditor model-value="Select some text and press the link button. Insert a table to reveal its options menu." />
    </StorySection>

    <StorySection title="Realistic usage (FormField)" description="Wrapped in a FormField with label, help, and validation.">
      <FormField
        label="Description"
        required
        description="Markdown is supported."
        :error="formValue.length > 0 && formValue.length < 10 ? 'Please write at least 10 characters.' : undefined"
      >
        <MarkdownEditor v-model="formValue" counter placeholder="Describe the task…" />
      </FormField>
    </StorySection>

    <StorySection title="Keyboard shortcuts">
      <div class="overflow-x-auto rounded-next-lg border border-next-border">
        <table class="w-full border-collapse text-left text-next-sm">
          <thead>
            <tr class="bg-next-muted text-next-muted-foreground">
              <th class="px-next-3 py-next-2 font-next-medium">Shortcut</th>
              <th class="px-next-3 py-next-2 font-next-medium">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in shortcuts" :key="row.keys" class="border-t border-next-border bg-next-card">
              <td class="px-next-3 py-next-2 font-next-mono text-next-xs">{{ row.keys }}</td>
              <td class="px-next-3 py-next-2">{{ row.action }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </StorySection>

    <StorySection
      title="PART 2 — Mentions (@)"
      description="Type @ to open the suggestion popup, anchored at the caret (no tippy — our useAnchoredPosition + Teleport). The mock source has ~650ms latency so the option-shaped skeleton rows are visible; ↑/↓/Enter/Esc and type-to-filter all work. Mentions render as deletable avatar chips."
    >
      <div class="grid gap-next-4 next-lg:grid-cols-2">
        <MarkdownEditor v-model="mentionDoc" :mentions="{ fetch: fetchMentions }" />
        <div>
          <p class="mb-next-1 text-next-xs font-next-semibold text-next-muted-foreground">Serialized markdown</p>
          <pre class="max-h-60 overflow-auto rounded-next-md border border-next-border bg-next-muted p-next-3 font-next-mono text-next-2xs whitespace-pre-wrap">{{ mentionDoc }}</pre>
        </div>
      </div>
    </StorySection>

    <StorySection
      title="PART 2 — Variables (trigger + operations pipeline)"
      description="Type { to open the variable picker (caret-anchored listbox with type icons), pick a predefined variable, then click the chip to open its Modal and build an operations pipeline — watch the result type change as you add ops. Result type drives the chip icon. SIX value types: text / number / boolean plus the extended date (calendar, ISO YYYY-MM-DD), enum (list — a select field's options) and multi (list-checks — a multi-select's options). Enum/multi definitions carry their options; comparison args of kind sourceOption / sourceOptions pick FROM those options (try Priority → Is, or Channels → Includes; Delivery date → Between opens DatePickers). Serializes byte-compatibly (pipeline included)."
    >
      <div class="grid gap-next-4 next-lg:grid-cols-2">
        <MarkdownEditor v-model="variableDoc" :variables="DEMO_VARIABLE_CONFIG" />
        <div>
          <p class="mb-next-1 text-next-xs font-next-semibold text-next-muted-foreground">Serialized markdown</p>
          <pre class="max-h-60 overflow-auto rounded-next-md border border-next-border bg-next-muted p-next-3 font-next-mono text-next-2xs whitespace-pre-wrap">{{ variableDoc }}</pre>
        </div>
      </div>
    </StorySection>

    <StorySection
      title="Standard operations catalog (66 ops)"
      description="The canonical, i18n-labelled catalog every pipeline surface shares (standardOperations.ts). Grouped by INPUT type; each op lists its arguments and the OUTPUT type it hands to the next step. Every type can terminate in a boolean, so any variable can become a condition. Ids are the stable wire vocabulary the backend condition engine implements."
    >
      <div class="flex flex-col gap-next-6">
        <div v-for="group in opsReference" :key="group.type">
          <ApiTable :title="group.heading" type-header="Result" :rows="group.rows" />
        </div>
      </div>
    </StorySection>

    <StorySection
      title="PART 2 — If-blocks (inline branches + boolean conditions + depth cap 3)"
      description="A bordered container with INLINE-editable branch bodies (full editor: marks, mentions, variables, even nested if-blocks). Each IF / ELSE-IF picks a variable + the same pipeline; the condition is invalid unless it resolves to boolean (status icon + blocked save). Add Else-if / Else (single). Nesting is capped at depth 3 — the toolbar insert is disabled at the cap."
    >
      <div class="grid gap-next-4 next-lg:grid-cols-2">
        <MarkdownEditor v-model="ifBlockDoc" :if-blocks="{ maxElseIf: 3, maxDepth: 3 }" :variables="DEMO_VARIABLE_CONFIG" />
        <div>
          <p class="mb-next-1 text-next-xs font-next-semibold text-next-muted-foreground">Serialized markdown</p>
          <pre class="max-h-72 overflow-auto rounded-next-md border border-next-border bg-next-muted p-next-3 font-next-mono text-next-2xs whitespace-pre-wrap">{{ ifBlockDoc }}</pre>
        </div>
      </div>
    </StorySection>

    <StorySection
      title="PART 2 — AI text (persona + nested prompt + labels)"
      description="Insert via the sparkles button; click the chip to open its Modal — pick a persona, write the prompt in a NESTED MarkdownEditor (which can itself contain variables + if-blocks), and pick knowledge labels. Marks AI-generated / AI-insertable text."
    >
      <div class="grid gap-next-4 next-lg:grid-cols-2">
        <MarkdownEditor v-model="aiDoc" :ai-text="DEMO_AI_CONFIG" :variables="DEMO_VARIABLE_CONFIG" :if-blocks="true" />
        <div>
          <p class="mb-next-1 text-next-xs font-next-semibold text-next-muted-foreground">Serialized markdown</p>
          <pre class="max-h-60 overflow-auto rounded-next-md border border-next-border bg-next-muted p-next-3 font-next-mono text-next-2xs whitespace-pre-wrap">{{ aiDoc }}</pre>
        </div>
      </div>
    </StorySection>

    <StorySection
      title="PART 2 — Everything on"
      description="All four features enabled at once on one editor. Type @ for a mention, { for a variable, use the toolbar for an if-block or AI text."
    >
      <MarkdownEditor
        model-value="Mention @, type { for a variable, or insert an if-block / AI text from the toolbar."
        :mentions="{ fetch: fetchMentions }"
        :variables="DEMO_VARIABLE_CONFIG"
        :if-blocks="{ maxDepth: 3 }"
        :ai-text="DEMO_AI_CONFIG"
      />
    </StorySection>

    <StorySection
      title="Directive FORMAT (portable encoding)"
      description="Each app node serializes to the legacy FORMAT.md directive encoding byte-for-byte, so content is portable between the legacy and next editors."
    >
      <div class="flex flex-col gap-next-3">
        <div v-for="ex in FORMAT_EXAMPLES" :key="ex.node">
          <p class="mb-next-1 text-next-xs font-next-semibold text-next-muted-foreground">{{ ex.node }}</p>
          <pre class="overflow-auto rounded-next-md border border-next-border bg-next-muted p-next-3 font-next-mono text-next-2xs whitespace-pre-wrap">{{ ex.code }}</pre>
        </div>
      </div>
    </StorySection>

    <StorySection title="API">
      <div class="flex flex-col gap-next-6">
        <ApiTable title="MarkdownEditor — Props" :rows="propRows" show-default />
        <ApiTable title="MarkdownEditor — Events" type-header="Payload" :rows="eventRows" />
        <ApiTable title="MarkdownViewer — Props" :rows="viewerRows" show-default />
      </div>
    </StorySection>

    <StorySection title="Format contract & PART 2" description="What the editor reads/writes, and where app nodes plug in.">
      <div class="flex flex-col gap-next-2 text-next-sm text-next-fg">
        <p>The base markdown dialect (headings, bold/italic/underline via <code>&lt;u&gt;</code>/strike, inline + fenced code, links, bullet/ordered lists, blockquote, hr, GFM tables) is committed in <code>resources/js/next/ui/editor/markdown.ts</code> and documented in that folder's <code>README.md</code>.</p>
        <p><strong>PART 2 (implemented):</strong> mentions, variables, if-blocks and AI chips are enabled via the <code>mentions</code> / <code>variables</code> / <code>ifBlocks</code> / <code>aiText</code> props (all OFF by default). Each owns its own markdown form following the legacy <code>FORMAT.md</code> directive encoding, registered through the <code>markdown.ts</code> node registry so round-trips stay portable with the legacy editor.</p>
      </div>
    </StorySection>
  </StoryPage>
</template>
