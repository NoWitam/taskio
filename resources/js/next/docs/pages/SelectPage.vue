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
  { name: 'ariaInvalid / success / dirty', type: 'boolean', default: 'false', description: 'Standalone state lines; provided automatically inside a FormField.' },
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
];

const fetchRows: ApiRow[] = [
  { name: 'args.cursor', type: 'string | null', description: 'Page cursor; null means the first page. Reset to null on query/filter change.' },
  { name: 'args.query', type: 'string', description: 'Debounced search query (built-in search box or a #header control via setQuery).' },
  { name: 'args.filters', type: 'Record<string, unknown>', description: 'In-dropdown filter state (v-model:filters / #header setFilter).' },
  { name: 'returns.options', type: 'SelectOption[]', description: 'The page of options to append (or replace, on reset).' },
  { name: 'returns.nextCursor', type: 'string | null', description: 'Cursor for the next page, or null when there are no more pages.' },
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
  </StoryPage>
</template>
