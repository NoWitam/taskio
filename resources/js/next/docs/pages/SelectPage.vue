<script setup lang="ts">
import { ref } from 'vue';
import Select, {
  type SelectOption,
  type SelectGroup,
  type SelectFetchArgs,
  type SelectFetchResult,
} from '../../ui/forms/Select.vue';
import FormField from '../../ui/forms/FormField.vue';
import Checkbox from '../../ui/forms/Checkbox.vue';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import StoryCell from '../StoryCell.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const sizes = ['sm', 'md', 'lg'] as const;

const fruits: SelectOption[] = [
  { value: 'apple', label: 'Apple' },
  { value: 'banana', label: 'Banana' },
  { value: 'cherry', label: 'Cherry' },
  { value: 'date', label: 'Date', disabled: true },
  { value: 'elderberry', label: 'Elderberry' },
  { value: 'fig', label: 'Fig' },
];

const iconOptions: SelectOption[] = [
  { value: 'dashboard', label: 'Dashboard', icon: 'layout-dashboard' },
  { value: 'forms', label: 'Forms', icon: 'file-text' },
  { value: 'inbox', label: 'Inbox', icon: 'inbox' },
  { value: 'settings', label: 'Settings', icon: 'settings' },
];

const groups: SelectGroup[] = [
  { label: 'Fruit', options: [{ value: 'apple', label: 'Apple' }, { value: 'pear', label: 'Pear' }] },
  { label: 'Vegetable', options: [{ value: 'carrot', label: 'Carrot' }, { value: 'pea', label: 'Pea' }] },
];

// --- Single-select models -------------------------------------------------
const v1 = ref<string | null>(null);
const v2 = ref<string | null>('banana');
const v3 = ref<string | null>(null);
const v4 = ref<string | null>('forms');
const v5 = ref<string | null>(null);
const vSearch = ref<string | null>(null);
const formVal = ref<string | null>(null);

// --- Multi-select models --------------------------------------------------
const teamRoster: SelectOption[] = [
  { value: 'ava', label: 'Ava Stone' },
  { value: 'ben', label: 'Ben Carter' },
  { value: 'chloe', label: 'Chloe Diaz' },
  { value: 'dan', label: 'Dan Ellis' },
  { value: 'eva', label: 'Eva Frost' },
  { value: 'finn', label: 'Finn Gray' },
  { value: 'gabe', label: 'Gabe Hunt' },
];
const multiChips = ref<string[]>(['ava', 'ben', 'chloe', 'dan']);
const multiSummary = ref<string[]>(['ava', 'ben', 'chloe', 'dan', 'eva']);
// Dynamic-overflow demo: a resizable wrapper proves the +N collapses based on real
// available width, not a fixed count.
const multiDynamic = ref<string[]>(['ava', 'ben', 'chloe', 'dan', 'eva', 'finn', 'gabe']);
const longTags: SelectOption[] = [
  { value: 'frontend', label: 'Frontend engineering' },
  { value: 'backend', label: 'Backend & infrastructure' },
  { value: 'design', label: 'Product design systems' },
  { value: 'qa', label: 'Quality assurance' },
  { value: 'pm', label: 'Project management' },
];
const multiCapped = ref<string[]>(['frontend', 'backend', 'design']);
const multiNatural = ref<string[]>(['frontend', 'backend', 'design']);

// --- Async cursor-pagination mock ----------------------------------------
// A fake dataset of ~60 cities, paginated in cursor "pages" of 10 with a
// setTimeout, filterable by `query` and by an in-#header "EU only" toggle that
// flows through `filters`. No real backend — purely self-contained.
interface City {
  value: string;
  label: string;
  eu: boolean;
}
const ALL_CITIES: City[] = [
  ['paris', 'Paris', true], ['berlin', 'Berlin', true], ['madrid', 'Madrid', true],
  ['rome', 'Rome', true], ['lisbon', 'Lisbon', true], ['vienna', 'Vienna', true],
  ['prague', 'Prague', true], ['warsaw', 'Warsaw', true], ['amsterdam', 'Amsterdam', true],
  ['dublin', 'Dublin', true], ['athens', 'Athens', true], ['helsinki', 'Helsinki', true],
  ['stockholm', 'Stockholm', true], ['oslo', 'Oslo', false], ['zurich', 'Zurich', false],
  ['london', 'London', false], ['tokyo', 'Tokyo', false], ['osaka', 'Osaka', false],
  ['seoul', 'Seoul', false], ['beijing', 'Beijing', false], ['shanghai', 'Shanghai', false],
  ['mumbai', 'Mumbai', false], ['delhi', 'Delhi', false], ['bangkok', 'Bangkok', false],
  ['singapore', 'Singapore', false], ['sydney', 'Sydney', false], ['melbourne', 'Melbourne', false],
  ['auckland', 'Auckland', false], ['toronto', 'Toronto', false], ['montreal', 'Montreal', false],
  ['vancouver', 'Vancouver', false], ['chicago', 'Chicago', false], ['boston', 'Boston', false],
  ['newyork', 'New York', false], ['miami', 'Miami', false], ['austin', 'Austin', false],
  ['denver', 'Denver', false], ['seattle', 'Seattle', false], ['portland', 'Portland', false],
  ['sanfrancisco', 'San Francisco', false], ['losangeles', 'Los Angeles', false],
  ['mexicocity', 'Mexico City', false], ['bogota', 'Bogotá', false], ['lima', 'Lima', false],
  ['santiago', 'Santiago', false], ['buenosaires', 'Buenos Aires', false], ['saopaulo', 'São Paulo', false],
  ['rio', 'Rio de Janeiro', false], ['cairo', 'Cairo', false], ['lagos', 'Lagos', false],
  ['nairobi', 'Nairobi', false], ['capetown', 'Cape Town', false], ['johannesburg', 'Johannesburg', false],
  ['casablanca', 'Casablanca', false], ['istanbul', 'Istanbul', false], ['dubai', 'Dubai', false],
  ['tehran', 'Tehran', false], ['riyadh', 'Riyadh', false], ['telaviv', 'Tel Aviv', false],
  ['reykjavik', 'Reykjavík', true],
].map(([value, label, eu]) => ({ value, label, eu }) as City);

const PAGE_SIZE = 10;

function mockFetchCities(args: SelectFetchArgs): Promise<SelectFetchResult> {
  const euOnly = args.filters.euOnly === true;
  const q = args.query.trim().toLowerCase();
  const matches = ALL_CITIES.filter(
    (c) => (!euOnly || c.eu) && (!q || c.label.toLowerCase().includes(q)),
  );
  const start = args.cursor ? Number(args.cursor) : 0;
  const page = matches.slice(start, start + PAGE_SIZE);
  const next = start + PAGE_SIZE;
  const nextCursor = next < matches.length ? String(next) : null;
  return new Promise((resolve) =>
    setTimeout(
      () =>
        resolve({
          options: page.map((c) => ({ value: c.value, label: c.label })),
          nextCursor,
        }),
      450,
    ),
  );
}

const asyncSingle = ref<string | null>(null);
const asyncMulti = ref<string[]>([]);
// Seed labels so pre-selected async values render even before their page loads.
const asyncMultiSeed: SelectOption[] = [{ value: 'reykjavik', label: 'Reykjavík' }];
const asyncMultiPreselected = ref<string[]>(['reykjavik']);

// --- API tables -----------------------------------------------------------
const propRows: ApiRow[] = [
  { name: 'v-model', type: 'string | null', default: 'null', description: 'Selected value (single mode).' },
  { name: 'v-model:values', type: 'string[]', default: '[]', description: 'Selected values (when :multiple).' },
  { name: 'v-model:filters', type: 'Record<string, unknown>', default: '{}', description: 'In-dropdown filter state passed to fetchOptions.' },
  { name: 'options', type: 'SelectOption[]', default: '—', description: 'Flat static options ({ value, label, disabled?, icon? }).' },
  { name: 'groups', type: 'SelectGroup[]', default: '—', description: 'Grouped static options. Takes precedence over options.' },
  { name: 'fetchOptions', type: '(args) => Promise<{ options, nextCursor }>', default: '—', description: 'Async cursor-paginated loader (replaces options/groups). See contract below.' },
  { name: 'multiple', type: 'boolean', default: 'false', description: 'Multi-select; v-model becomes string[]; options show checkboxes.' },
  { name: 'display', type: "'chips' | 'summary'", default: "'chips'", description: 'Multi trigger: removable chips + “+N”, or a compact “N selected” pill.' },
  { name: 'summary', type: 'boolean', default: 'false', description: 'Shorthand for display="summary".' },
  { name: 'chipMaxWidth', type: 'number | string', default: '—', description: 'Cap + truncate each chip (number = px). Omit → chips render at natural width and are never internally truncated.' },
  { name: 'searchable', type: 'boolean', default: 'false', description: 'Render a built-in search TextInput in the header, wired to the query.' },
  { name: 'searchPlaceholder', type: 'string', default: "'Search…'", description: 'Placeholder for the built-in search box.' },
  { name: 'searchDebounce', type: 'number', default: '250', description: 'Debounce (ms) before the query (re)fetches.' },
  { name: 'selectedOptions', type: 'SelectOption[]', default: '—', description: 'Seed labels for selected values that may not be on the loaded async page.' },
  { name: 'size', type: "'sm' | 'md' | 'lg'", default: "'md'", description: 'Trigger height + text scale (fixed; never grows).' },
  { name: 'placeholder', type: 'string', default: "'Select…'", description: 'Shown when nothing is selected.' },
  { name: 'loading', type: 'boolean', default: 'false', description: 'Static mode: spinner + “Loading…” in the list.' },
  { name: 'emptyText', type: 'string', default: "'No results'", description: 'Empty-state text when the (first) page is empty.' },
  { name: 'disabled / readonly', type: 'boolean', default: 'false', description: 'Inert / non-editable trigger (also inherited from FormField).' },
  { name: 'success / dirty', type: 'boolean', default: 'false', description: 'Standalone state lines; provided automatically inside a FormField.' },
  { name: 'ariaInvalid', type: 'boolean', default: '—', description: 'Force the error state standalone; omit it and the surrounding FormField decides.' },
  { name: 'id / describedById / ariaLabel', type: 'string', default: '—', description: 'Standalone wiring; provided automatically inside a FormField.' },
];

const eventRows: ApiRow[] = [
  { name: 'open', type: '()', description: 'Emitted when the popover opens.' },
  { name: 'close', type: '()', description: 'Emitted when the popover closes.' },
];

const slotRows: ApiRow[] = [
  { name: 'header', type: '{ query, setQuery, filters, setFilter, refetch, loading }', description: 'Sticky region above the list for custom search inputs / toggles. Use the payload to drive fetchOptions.' },
  { name: 'footer', type: '{ query, setQuery, filters, setFilter, refetch, loading }', description: 'Sticky region below the list (counts, a “create” action, …). Same payload.' },
  { name: 'option', type: '{ option, selected, active }', description: 'Custom option-row content (defaults to leading icon + label). The checkbox (multi) / check (single) affordance stays owned by Select. SelectOption carries arbitrary extra data (avatar, color, …) for the slot.' },
  { name: 'chip', type: '{ option, remove }', description: 'Custom selected-chip content in multiple mode (defaults to the removable Badge). Call remove() to deselect. Used to render avatar / colored-label chips.' },
  { name: 'value', type: '{ option }', description: 'Custom trigger display for the selected value in single mode (defaults to icon + label).' },
  { name: 'empty', type: '{ query, setQuery, refetch }', description: 'ADDITIVE — replaces the default empty-state text. query distinguishes "nothing to pick at all" from "the search matched nothing", so a consumer can offer a distinct affordance for each (e.g. BotSelect: a "no bots in this workspace" empty-workspace message + a create-bot link vs. a "clear search" action). With no slot, emptyText renders exactly as before this addition.' },
];

const fetchRows: ApiRow[] = [
  { name: 'args.cursor', type: 'string | null', description: 'Page cursor; null means the first page. Reset to null on query/filter change.' },
  { name: 'args.query', type: 'string', description: 'Debounced search query (built-in search box or a #header control via setQuery).' },
  { name: 'args.filters', type: 'Record<string, unknown>', description: 'In-dropdown filter state (v-model:filters / #header setFilter).' },
  { name: 'returns.options', type: 'SelectOption[]', description: 'The page of options to append (or replace, on reset).' },
  { name: 'returns.nextCursor', type: 'string | null', description: 'Cursor for the next page, or null when there are no more pages.' },
];

const botSelectRows: ApiRow[] = [
  { name: 'statusBadge', type: 'boolean', description: "OFF by default. ON renders each option row's bot status as a StatusBadge (icon + tone + localized label, the SAME botStatusMap the Bots card/detail use) instead of a quiet muted text line. Turn it on wherever the status actually changes the meaning of the pick — e.g. the ai-text author picker, where an INACTIVE bot can still be picked (see below)." },
  { name: '#empty', type: '{ query, setQuery, refetch }', description: "Forwarded to Select's own #empty slot (see the Select Slots table above) — a BotSelect consumer can distinguish an empty WORKSPACE from a fruitless SEARCH exactly like Select allows, with a bots-specific default in between (falls through to Select's emptyText with no slot at all)." },
  { name: '#value', type: '{ option }', description: "Overrides the single-mode trigger display (default: sparkles glyph + name) — used by the ai-text author picker to append an 'Author unavailable' Badge next to the name for a deleted author, without forking the control." },
  { name: 'seed', type: 'Array<{id,name,status?}>', description: "Seeds already-known bots (e.g. a resolved author name) so the trigger/chips render before — or without — an async page containing them, mirroring Select's selectedOptions cache." },
];

const headerCtxRows: ApiRow[] = [
  { name: 'query', type: 'string', description: 'Current (debounced) search query.' },
  { name: 'setQuery', type: '(value: string) => void', description: 'Set the query → resets the cursor + (async) refetches, debounced.' },
  { name: 'filters', type: 'Record<string, unknown>', description: 'Current in-dropdown filter state.' },
  { name: 'setFilter', type: '(key: string, value: unknown) => void', description: 'Set one filter key → resets the cursor + (async) refetches.' },
  { name: 'refetch', type: '() => Promise<void>', description: 'Manually re-run the loader from the first page.' },
  { name: 'loading', type: 'boolean', description: 'True while a page is being fetched.' },
];
</script>

<template>
  <StoryPage
    title="Select"
    description="A custom accessible single/multi-select combobox (not the native <select>) so open / option / selected / empty / loading / error states can be styled. The trigger renders through FieldShell — sharing the exact border + state line as the rest of the field family — and obeys the no-grow rule: fixed height per size, content truncates. Supports static options, grouped options, multi-select (chips or summary), a searchable header, and async cursor-pagination driven by in-dropdown filters."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>Trigger is <code>role="combobox"</code> with <code>aria-expanded</code>, <code>aria-controls</code>, <code>aria-haspopup="listbox"</code>, <code>aria-activedescendant</code>, and <code>aria-multiselectable</code> in multi mode; the list is <code>role="listbox"</code> (<code>+aria-multiselectable</code>) with <code>role="option"</code> items (<code>aria-selected</code> / <code>aria-disabled</code>).</li>
        <li>Keyboard: <kbd>↑</kbd>/<kbd>↓</kbd> move active (skip disabled), <kbd>Home</kbd>/<kbd>End</kbd> jump, <kbd>Enter</kbd>/<kbd>Space</kbd> select (multi: toggle + stay open; single: select + close), <kbd>Esc</kbd> close + restore focus to the trigger, type-ahead by label prefix (static, non-searchable). In multi mode, <kbd>Backspace</kbd> on an empty query removes the last chip.</li>
        <li><strong>App-wide behavior change:</strong> an open popover now registers with the shared overlay stack (<code>useOverlayStack</code>), exactly like Popover/DropdownMenu — the stack's capture-phase <kbd>Esc</kbd> listener makes the open list the TOPMOST overlay. Before this, a <code>Select</code> opened INSIDE a Modal/Drawer lost the race: <kbd>Esc</kbd> closed the whole Modal (discarding unsaved work) before the list's own handler ran. Now the first <kbd>Esc</kbd> closes the list; a second closes the Modal. This applies to EVERY <code>Select</code>-based control (including <code>BotSelect</code>/<code>UserSelect</code>/<code>FormSelect</code>) wherever it renders inside an overlay. This was a real, reachable bug, not a hypothetical: with the ai-text author picker (a <code>BotSelect</code>) open inside <code>AiTextPanel.vue</code>'s modal, the first <kbd>Esc</kbd> used to close the MODAL — discarding whatever was unsaved in the panel — while the picker's own list stayed open underneath. Pinned by <code>ui/forms/__tests__/SelectEscapeInModal.dom.spec.ts</code>.</li>
        <li>The same pass registered two more overlay families that share the identical race, once it was clear <code>Select</code> was not the only offender: <code>ui/layout/AppShell.vue</code>'s mobile navigation drawer joins the stack as <code>kind: 'modal'</code> (it already behaves like one — scrim, <code>aria-modal</code>, focus trap, scroll lock), and both Tiptap suggestion popups — <code>ui/editor/extensions/mention.ts</code> and <code>variable.ts</code> — join as <code>kind: 'popover'</code> through a shared helper, <code>createSuggestionOverlay()</code> in <code>ui/editor/extensions/suggestionStore.ts</code>. Editors live inside modals throughout the app, so the same discard-on-Escape bug applied to an open <strong>@</strong>-mention or <strong>{</strong>-variable popup exactly as it did to an open <code>Select</code> list. Pinned by <code>ui/layout/__tests__/AppShellDrawerOverlayStack.dom.spec.ts</code> and <code>ui/editor/__tests__/suggestEscapeOverlayStack.dom.spec.ts</code>.</li>
        <li>
          <strong>Deliberately NOT registered</strong> — six components were reviewed against the same stack and left out, on record, so the omission reads as a decision rather than an oversight:
          <ul class="ml-next-4 mt-next-1 list-[circle] space-y-next-1">
            <li><code>Tooltip.vue</code> — a tooltip is a passive hint that never traps focus and can open on hover alone; registering it would make it the TOPMOST overlay and swallow the Escape meant for the modal or options list underneath it, the exact inversion the stack exists to prevent.</li>
            <li><code>FieldPopover.vue</code> — its Escape handler is bound to the panel, so it only fires while DOM focus is inside it; a stacked overlay open above it (e.g. a nested <code>Select</code>) takes focus into its own body-teleported list, so the two are never the target of the same keypress.</li>
            <li><code>ChipOverflow.vue</code> — same shape as <code>FieldPopover</code>: bound to the panel, and the panel holds nothing that itself registers (chips + a remove button, no nested overlay), so there is no press a stacked overlay could contest.</li>
            <li><code>MonthPicker.vue</code>'s year-grid handler — its Escape is not a dismissal at all, it steps the grid back to the months view inside an already-open panel; dismissing the panel itself is <code>FieldPopover</code>'s job.</li>
            <li><code>PillGroupInput.vue</code> — bound to the text input, so Escape only arrives while the input has DOM focus, and the suggestion list it closes is an inline dropdown of that same input, never a sibling of another overlay.</li>
            <li><code>VariableBrowser.vue</code> — not an overlay and does not dismiss itself: Escape only emits <code>close</code> and the HOST decides (a popover panel, the <strong>{</strong> caret popup, or a non-dismissible inline panel); registering here would double up with the host's own entry.</li>
          </ul>
          The rule these six fall out of: a component joins the stack only if it can genuinely coexist with another open overlay AND is itself the thing that should be dismissed by that Escape — a passive, hover-opened, or focus-bound-with-no-nested-overlay control never qualifies, because registering it would hand it the topmost slot and eat the Escape meant for whatever is actually open underneath.
        </li>
        <li>With a header search box, typing goes to the search field while <kbd>↑</kbd>/<kbd>↓</kbd> still move the list (virtual focus via <code>aria-activedescendant</code> stays on the search input — combobox pattern). Each chip’s ✕ is keyboard-removable. Outside-click and <kbd>Tab</kbd> close the popover.</li>
        <li>Trailing controls follow the project ordering rule: the conditional clear <strong>✕</strong> renders BEFORE the permanent open/close <strong>chevron</strong> (order <code>[✕][chevron]</code>). The chevron never moves; the ✕ occupies reserved space to its left and only toggles visibility.</li>
      </ul>
    </template>

    <StorySection title="Sizes" description="sm · md · lg. Height is fixed per size; content truncates and never grows the trigger.">
      <div class="grid max-w-md gap-next-4">
        <StoryCell v-for="s in sizes" :key="s" :label="s">
          <div class="w-56"><Select :size="s" :options="fruits" v-model="v1" placeholder="Pick a fruit" /></div>
        </StoryCell>
      </div>
    </StorySection>

    <StorySection title="Field states" description="default · selected · disabled · readonly · error · success · dirty. These are the shared FieldShell state lines (open one to see option hover/active + the disabled option ‘Date’).">
      <div class="grid max-w-md gap-next-4">
        <StoryCell label="placeholder"><div class="w-full"><Select :options="fruits" v-model="v1" placeholder="Select a fruit" /></div></StoryCell>
        <StoryCell label="selected"><div class="w-full"><Select :options="fruits" v-model="v2" /></div></StoryCell>
        <StoryCell label="disabled"><div class="w-full"><Select :options="fruits" v-model="v2" disabled /></div></StoryCell>
        <StoryCell label="readonly"><div class="w-full"><Select :options="fruits" v-model="v2" readonly /></div></StoryCell>
        <StoryCell label="error (standalone)"><div class="w-full"><Select :options="fruits" v-model="v1" placeholder="Required" aria-invalid /></div></StoryCell>
        <StoryCell label="success (standalone)"><div class="w-full"><Select :options="fruits" v-model="v2" success /></div></StoryCell>
        <StoryCell label="dirty (standalone)"><div class="w-full"><Select :options="fruits" v-model="v2" dirty /></div></StoryCell>
      </div>
    </StorySection>

    <StorySection title="Multiple — chips + dynamic overflow" description="Selected values render as removable Badge chips in a single clipped row; chip labels never wrap to a second line; as many WHOLE chips as physically fit are shown and the rest collapse into a “+N” chip so the trigger never grows. Hover/focus the “+N” for a tooltip of the hidden values; click it (or Enter) to open an interactive panel that removes them one by one — the SAME shared ChipOverflow affordance PillGroupInput uses. Remove a visible chip with the mouse or, on an empty query, Backspace.">
      <div class="max-w-md"><Select multiple :options="teamRoster" v-model:values="multiChips" placeholder="Add teammates" searchable /></div>
    </StorySection>

    <StorySection title="Multiple — overflow is width-driven (resize me)" description="The “+N” count is measured from actual available width (refs + ResizeObserver), NOT a fixed threshold. Drag the resize handle on the box below and watch the visible-chip count change live. The box is overflow-clipped, yet the options popover is TELEPORTED to the body and floats above the page (it is no longer trapped inside the clipped box) — open it to confirm.">
      <div class="resize-x overflow-auto rounded-next-md border border-dashed border-next-border p-next-2" style="min-width: 12rem; max-width: 100%; width: 22rem">
        <Select multiple :options="teamRoster" v-model:values="multiDynamic" placeholder="Add teammates" />
      </div>
    </StorySection>

    <StorySection title="Multiple — chipMaxWidth (opt-in truncation)" description="Without chipMaxWidth chips render at natural width (left). With chipMaxWidth each chip is capped + truncated with an ellipsis (right). Either way the row collapses whole chips into “+N”.">
      <div class="grid gap-next-4 sm:grid-cols-2">
        <StoryCell label="natural width (no cap)">
          <div class="w-full"><Select multiple :options="longTags" v-model:values="multiNatural" placeholder="Add areas" /></div>
        </StoryCell>
        <StoryCell label="chipMaxWidth = 90">
          <div class="w-full"><Select multiple :chip-max-width="90" :options="longTags" v-model:values="multiCapped" placeholder="Add areas" /></div>
        </StoryCell>
      </div>
    </StorySection>

    <StorySection title="Multiple — summary mode" description="display=&quot;summary&quot; (or :summary) shows a compact “N selected” pill instead of chips — ideal when many values can be selected.">
      <div class="max-w-md"><Select multiple summary :options="teamRoster" v-model:values="multiSummary" placeholder="Add teammates" /></div>
    </StorySection>

    <StorySection title="Loading, empty & error" description="Static loading + the no-results empty state. The async example further down shows the error-with-retry row.">
      <div class="grid max-w-md gap-next-4">
        <StoryCell label="loading"><div class="w-full"><Select :options="[]" loading placeholder="Loading options…" /></div></StoryCell>
        <StoryCell label="empty"><div class="w-full"><Select :options="[]" v-model="v3" placeholder="No options" empty-text="No results" /></div></StoryCell>
      </div>
    </StorySection>

    <StorySection title="Per-option icons" description="Each option may carry a leading icon; the single-select trigger mirrors the selected one.">
      <div class="max-w-md"><Select :options="iconOptions" v-model="v4" placeholder="Go to…" /></div>
    </StorySection>

    <StorySection title="Grouped options" description="Options organised under group headings.">
      <div class="max-w-md"><Select :groups="groups" v-model="v5" placeholder="Pick produce" /></div>
    </StorySection>

    <StorySection title="Searchable (static)" description="searchable renders a built-in search TextInput in the sticky header and filters the static options client-side.">
      <div class="max-w-md"><Select searchable :options="teamRoster" v-model="vSearch" placeholder="Find a teammate" search-placeholder="Search teammates…" /></div>
    </StorySection>

    <StorySection
      title="Async — cursor pagination + in-header filter"
      description="Backed by a self-contained mock fetchOptions over ~60 cities, paged 10 at a time with a 450ms delay. The built-in search drives the query; the #header checkbox sets a filters.euOnly flag via setFilter — both reset the cursor (cursor=null) and re-request. The initial load and the bottom ‘loading more’ row render option-row-shaped SKELETONS (icon circle + text line) instead of a spinner. Scroll near the bottom to load the next page."
    >
      <div class="grid max-w-md gap-next-6">
        <StoryCell label="single">
          <div class="w-full">
            <Select
              searchable
              :fetch-options="mockFetchCities"
              v-model="asyncSingle"
              placeholder="Pick a city"
              search-placeholder="Search cities…"
            >
              <template #header="{ filters, setFilter }">
                <Checkbox
                  size="sm"
                  :model-value="filters.euOnly === true"
                  label="EU cities only"
                  @update:model-value="setFilter('euOnly', $event)"
                />
              </template>
              <template #footer="{ loading }">
                <span class="text-next-xs text-next-muted-foreground">
                  {{ loading ? 'Loading…' : 'Scroll for more · type to search' }}
                </span>
              </template>
            </Select>
          </div>
        </StoryCell>

        <StoryCell label="multiple (chips) — with a pre-selected, off-page value">
          <div class="w-full">
            <Select
              multiple
              searchable
              :fetch-options="mockFetchCities"
              :selected-options="asyncMultiSeed"
              v-model:values="asyncMultiPreselected"
              placeholder="Pick cities"
              search-placeholder="Search cities…"
            >
              <template #header="{ filters, setFilter }">
                <Checkbox
                  size="sm"
                  :model-value="filters.euOnly === true"
                  label="EU cities only"
                  @update:model-value="setFilter('euOnly', $event)"
                />
              </template>
            </Select>
          </div>
        </StoryCell>

        <StoryCell label="multiple (summary)">
          <div class="w-full">
            <Select
              multiple
              summary
              searchable
              :fetch-options="mockFetchCities"
              v-model:values="asyncMulti"
              placeholder="Pick cities"
              search-placeholder="Search cities…"
            />
          </div>
        </StoryCell>
      </div>
    </StorySection>

    <StorySection title="Realistic usage (FormField)" description="Inside a FormField the trigger inherits id / aria-describedby / disabled / required and draws the error/success/dirty state line automatically.">
      <FormField label="Default project" required description="New forms are created here unless changed." :error="!formVal ? 'Choose a project.' : undefined">
        <Select :options="iconOptions" v-model="formVal" placeholder="Select a project" />
      </FormField>
    </StorySection>

    <StorySection title="API">
      <div class="flex flex-col gap-next-6">
        <ApiTable title="Props" :rows="propRows" show-default />
        <ApiTable title="Events" type-header="Payload" :rows="eventRows" />
        <ApiTable title="Slots" type-header="Scope payload" :rows="slotRows" />
        <ApiTable title="fetchOptions contract" type-header="Type" :rows="fetchRows" />
        <ApiTable title="#header / #footer filter-context payload" type-header="Type" :rows="headerCtxRows" />
      </div>
    </StorySection>

    <StorySection
      title="Domain select: BotSelect"
      description="A thin GLOBAL wrapper over Select (ui/forms/BotSelect.vue) for picking a workspace Bot — loads GET /bots?search=&cursor=, renders a leading sparkles glyph, and mirrors UserSelect/FormSelect 1:1 for a11y/loading/empty. v-model is a single bot id, or string[] with :multiple. Used across the app (task assignee, the ai-text per-block AUTHOR — docs/decisions/ADR-0040-per-block-ai-text-author.md, filters); OPT-IN extras keep every existing consumer's rendering unchanged unless it turns them on."
    >
      <div class="flex flex-col gap-next-3 text-next-sm">
        <ApiTable
          title="BotSelect — additive props/slots on top of Select"
          type-header="Type"
          :rows="botSelectRows"
        />

        <Alert variant="info" size="sm">
          <strong>Inactive bots are never hidden by default.</strong> A bot whose status is <code class="font-next-mono">inactive</code> still appears in the results. Hiding one from, say, the ai-text author picker would misrepresent the system: a session delegated to (or a block authored by) an inactive bot still uses its voice, so the picker must not pretend that bot doesn't exist — <code class="font-next-mono">statusBadge</code> exists precisely so that inclusion is legible rather than silent.
        </Alert>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-1 font-next-semibold text-next-fg text-next-sm">The botDirectory store — resolving a bare bot id embedded in content</p>
          <p class="text-next-xs text-next-muted-foreground">
            <code class="font-next-mono">app/stores/botDirectory.ts</code> (Pinia) is a small id →
            <code class="font-next-mono">&#123;name,status&#125;</code> RESOLVER cache for a bot reference that
            lives INSIDE content rather than a live picker selection — today, an <code class="font-next-mono">
            @[ai-text]</code> block's <code class="font-next-mono">authorId</code>. It is deliberately
            SEPARATE from the browse/edit <code class="font-next-mono">useBotsStore</code>, whose
            <code class="font-next-mono">fetchBot</code> writes the ONE global bot-detail view — resolving a
            chip through it would stomp whatever the user is looking at elsewhere in the app.
          </p>
          <ul class="mt-next-2 flex flex-col gap-next-1 text-next-xs text-next-muted-foreground">
            <li><code class="font-next-mono">resolve(id)</code> — fetches <code class="font-next-mono">GET /bots/&#123;id&#125;</code> at most ONCE per id; concurrent callers for the SAME id share one in-flight promise (no fan-out). <code class="font-next-mono">404</code>/<code class="font-next-mono">403</code> → a DEFINITIVE <code class="font-next-mono">missing</code> verdict; any other failure (network/5xx/timeout) → <code class="font-next-mono">unresolved</code> — never rendered as "deleted".</li>
            <li><code class="font-next-mono">retry(id)</code> — the only way to re-ask an <code class="font-next-mono">unresolved</code> id (a plain re-render never spins on a failing lookup).</li>
            <li><code class="font-next-mono">prime(bot)</code> — feeds the cache from data already on hand (e.g. a bot picked from a loaded page just told the caller its name/status) — no request.</li>
            <li><code class="font-next-mono">entry(id)</code> — the current cached verdict, or <code class="font-next-mono">null</code> if never asked for.</li>
          </ul>
          <p class="mt-next-2 text-next-xs text-next-muted-foreground">
            There is NO batch bot-lookup endpoint, so N distinct authors in one document cost N requests — in
            practice a document names 1–3 distinct authors, and the per-id cache means a repeat id (or a
            repeat mount) never re-fetches. See <code class="font-next-mono">docs/decisions/
            ADR-0040-per-block-ai-text-author.md</code> and <code class="font-next-mono">ui/editor/
            extensions/AiTextPanel.vue</code> for the consumer that drove this store's design.
          </p>
        </div>
      </div>
    </StorySection>
  </StoryPage>
</template>
