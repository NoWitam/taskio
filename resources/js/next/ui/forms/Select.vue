<script setup lang="ts">
// Select — a custom accessible single/multi-select listbox/combobox for the
// "next" frontend (NOT the native <select>, because the matrix requires bespoke
// open / option-hover / selected / empty / loading / error visuals).
//
// The TRIGGER now renders THROUGH FieldShell so it shares the exact border + the
// "state line" (focus/error/success/dirty) with the rest of the control family,
// reads the surrounding FormField validation/dirty context, and respects the
// no-grow rule (fixed height per size). The popover is a listbox positioned
// below the trigger.
//
// Modes:
//  - SINGLE (default): v-model is `string | null`. Selecting an option closes.
//  - MULTIPLE (`:multiple`): v-model is `string[]`. Selected values render as
//    removable Badge chips inside the trigger; the trigger NEVER grows — it fits as
//    many WHOLE chips as physically fit on a single row (measured with refs + a
//    ResizeObserver via the shared `useChipOverflow` composable) and collapses the
//    rest into a DYNAMIC `+N` overflow chip (recomputed on resize / selection /
//    `chipMaxWidth` change). Chips render at natural width unless `chipMaxWidth` is
//    set, which caps + truncates each chip. Rows in the menu show a checkbox
//    affordance; toggling does NOT close the menu. With `:summary` the trigger
//    instead shows a compact "N selected" pill.
//
// Data sources (mutually exclusive):
//  - STATIC: `options` / `groups` arrays. Type-ahead + client-side `searchable`
//    filtering are available.
//  - ASYNC: a `fetchOptions(args)` loader that returns cursor-paginated pages.
//    `args` is `{ cursor, query, filters }`. The first page loads on open; the
//    next page loads when a sentinel near the list bottom scrolls into view
//    (IntersectionObserver) and `nextCursor` is non-null. The typed search query
//    + the in-dropdown `#header` filter state are passed to the loader; changing
//    either resets the cursor (cursor=null) and refetches from the start
//    (debounced for the query). Stale responses are dropped via a request token.
//
// Keyboard (matrix contract):
//   ↑/↓        move active option (skips disabled)
//   Home/End   first / last option
//   Enter      single: select+close · multi: toggle active
//   Space      single: select+close · multi: toggle active (open when closed)
//   Backspace  multi, empty search: remove the last chip
//   Esc        close, keep selection, return focus to trigger
//   type-ahead (static, non-searchable) matches option labels by prefix
//   Tab        closes the popover (focus leaves naturally)
// ARIA: trigger has role="combobox", aria-expanded, aria-controls,
// aria-haspopup="listbox", aria-activedescendant, aria-multiselectable; the list
// is role="listbox"; options role="option" with aria-selected / aria-disabled.
import {
  computed,
  nextTick,
  onBeforeUnmount,
  ref,
  watch,
} from 'vue';
import Icon, { type IconName } from '../primitives/Icon.vue';
import Badge from '../primitives/Badge.vue';
import Skeleton from '../data/Skeleton.vue';
import ChipOverflow from './ChipOverflow.vue';
import FieldShell from './FieldShell.vue';
import { useFormField, nextId } from './formField';
import { useOutsideClick } from '../../app/composables/useOutsideClick';
import { useChipOverflow } from '../../app/composables/useChipOverflow';
import { useAnchoredPosition } from '../../app/composables/useAnchoredPosition';
import { useTheme } from '../../app/lib/theme';
import { useI18n } from '../../app/i18n';
import { type ControlSize } from './fieldShell';

const { t } = useI18n();

export interface SelectOption {
  value: string;
  label: string;
  disabled?: boolean;
  icon?: IconName;
  /**
   * Arbitrary extra data carried alongside an option (e.g. `avatar`, `color`, a
   * full entity). Wrapper components (UserSelect / LabelSelect) read these in the
   * `#option` / `#chip` / `#value` scoped slots. Untyped by design so any wrapper
   * can attach what it needs without changing the base Select contract.
   */
  [key: string]: unknown;
}
export interface SelectGroup {
  label: string;
  options: SelectOption[];
}

/** Arguments handed to the async `fetchOptions` loader. */
export interface SelectFetchArgs {
  /** Cursor for the page to load; `null` means "first page". */
  cursor: string | null;
  /** The debounced search query (built-in search box or `#header` search). */
  query: string;
  /** Arbitrary state from the in-dropdown `#header` controls / `v-model:filters`. */
  filters: Record<string, unknown>;
}
/** Shape the `fetchOptions` loader must resolve to. */
export interface SelectFetchResult {
  options: SelectOption[];
  /** Cursor for the NEXT page, or `null` when there are no more pages. */
  nextCursor: string | null;
}
export type SelectFetchOptions = (
  args: SelectFetchArgs,
) => Promise<SelectFetchResult>;

const props = withDefaults(
  defineProps<{
    /** Flat options OR grouped options (static data source). */
    options?: SelectOption[];
    groups?: SelectGroup[];
    /** Async cursor-paginated data source (replaces options/groups). */
    fetchOptions?: SelectFetchOptions;
    /** Allow selecting multiple values (v-model becomes string[]). */
    multiple?: boolean;
    /**
     * Multi-select trigger display: `'chips'` (default) renders removable Badge
     * chips with a `+N` overflow, `'summary'` renders a compact "N selected" pill.
     * Boolean shorthand: `:summary` === `display="summary"`.
     */
    display?: 'chips' | 'summary';
    /** Shorthand for `display="summary"`. */
    summary?: boolean;
    size?: ControlSize;
    placeholder?: string;
    disabled?: boolean;
    readonly?: boolean;
    /**
     * Show a built-in search box at the top of the dropdown. In static mode it
     * filters the options client-side; in async mode it drives the `fetchOptions`
     * `query` (debounced) and resets the cursor.
     */
    searchable?: boolean;
    /** Placeholder for the built-in search box. */
    searchPlaceholder?: string;
    /** Debounce (ms) applied to the search query before (re)fetching. */
    searchDebounce?: number;
    /** Force a spinner + "Loading…" in the list (static mode). */
    loading?: boolean;
    /** Custom empty-state text when there are no options. */
    emptyText?: string;
    /**
     * Cap each chip's width (multi/chips mode). A number is treated as px; any
     * other CSS length string is used verbatim. When OMITTED, chips render at
     * their natural width and are never internally truncated — the row instead
     * collapses whole chips into the `+N` pill when they don't fit.
     */
    chipMaxWidth?: number | string;
    /** Standalone aria-invalid (FormField provides this otherwise). */
    ariaInvalid?: boolean;
    /** Standalone success styling (FormField provides this otherwise). */
    success?: boolean;
    /** Standalone dirty styling (FormField tracks this otherwise). */
    dirty?: boolean;
    id?: string;
    describedById?: string;
    ariaLabel?: string;
    /**
     * Seed labels for already-selected values that may not be in the loaded
     * async page yet, so chips/trigger render correctly. Merged with options as
     * they load. `{ [value]: label }` or a list of SelectOption.
     */
    selectedOptions?: SelectOption[];
  }>(),
  {
    multiple: false,
    summary: false,
    size: 'md',
    disabled: false,
    readonly: false,
    searchable: false,
    searchDebounce: 250,
    loading: false,
    success: false,
    dirty: false,
  },
);

// i18n-defaulted display strings: keep the prop overridable, fall back to the
// translated catalog so switching the language updates them reactively.
const searchPlaceholderText = computed(
  () => props.searchPlaceholder ?? t('select.searchPlaceholder', 'Search…'),
);
const emptyTextResolved = computed(
  () => props.emptyText ?? t('select.empty', 'No results'),
);
const placeholderText = computed(
  () => props.placeholder ?? t('select.placeholder', 'Select…'),
);

const emit = defineEmits<{
  (e: 'open'): void;
  (e: 'close'): void;
}>();

// v-model: a single value (string|null) OR an array of values (string[]).
const single = defineModel<string | null>({ default: null });
const multi = defineModel<string[]>('values', { default: () => [] });
// In-dropdown filter state, two-way bindable as v-model:filters.
const filters = defineModel<Record<string, unknown>>('filters', {
  default: () => ({}),
});

const field = useFormField();
const generatedId = nextId('next-select');
const resolvedId = computed(() => props.id ?? field?.id.value ?? generatedId);
const listId = computed(() => `${resolvedId.value}-listbox`);
const searchId = computed(() => `${resolvedId.value}-search`);
const overflowPanelId = computed(() => `${resolvedId.value}-overflow`);
const resolvedDescribedBy = computed(
  () => props.describedById ?? field?.describedById.value,
);
const invalid = computed(() => props.ariaInvalid ?? field?.invalid.value ?? false);
const success = computed(() => props.success || (field?.valid.value ?? false));
const disabled = computed(() => props.disabled || (field?.disabled.value ?? false));
const readonly = computed(() => props.readonly || (field?.readonly.value ?? false));
const required = computed(() => field?.required.value ?? false);

// Register this control's value for the FormField's dirty tracking.
if (field?.registerValue) {
  const dispose = field.registerValue(() =>
    props.multiple ? multi.value : single.value,
  );
  onBeforeUnmount(dispose);
}
const dirty = computed(() => props.dirty || (field?.dirty.value ?? false));

const isAsync = computed(() => typeof props.fetchOptions === 'function');
// Resolved multi display mode: explicit `display` wins, else the `summary` flag.
const summaryMode = computed(
  () => props.display === 'summary' || (props.display == null && props.summary),
);

// --- Option pools ---------------------------------------------------------
// Static options + a running label cache so async-loaded / pre-seeded selections
// always have a label to render in chips/trigger, even off-page.
const asyncOptions = ref<SelectOption[]>([]);
const labelCache = new Map<string, SelectOption>();

function cacheOption(opt: SelectOption): void {
  labelCache.set(opt.value, opt);
}

// Static flat list (for keyboard nav / lookup) + grouped view (render).
// Declared BEFORE the immediate watchers that read it, to avoid a temporal
// dead-zone ReferenceError when the watcher runs synchronously on setup.
const staticFlat = computed<SelectOption[]>(() => {
  if (props.groups?.length) return props.groups.flatMap((g) => g.options);
  return props.options ?? [];
});

// Seed the cache from `selectedOptions` (and re-seed when it changes).
watch(
  () => props.selectedOptions,
  (seed) => seed?.forEach(cacheOption),
  { immediate: true, deep: true },
);
// Cache static options too (so a value present statically resolves its label).
watch(
  () => [props.options, props.groups] as const,
  () => staticFlat.value.forEach(cacheOption),
  { immediate: true, deep: true },
);

// Client-side search applied to static options when `searchable`.
const query = ref('');
const matchesQuery = (o: SelectOption) =>
  !query.value || o.label.toLowerCase().includes(query.value.toLowerCase());

const renderGroups = computed<SelectGroup[]>(() => {
  if (isAsync.value) {
    return [{ label: '', options: asyncOptions.value }];
  }
  if (props.groups?.length) {
    if (props.searchable) {
      return props.groups
        .map((g) => ({ label: g.label, options: g.options.filter(matchesQuery) }))
        .filter((g) => g.options.length);
    }
    return props.groups;
  }
  const opts = props.searchable
    ? (props.options ?? []).filter(matchesQuery)
    : (props.options ?? []);
  return [{ label: '', options: opts }];
});

// Flat list of all CURRENTLY-RENDERED options, for keyboard nav + active id.
const flatOptions = computed<SelectOption[]>(() =>
  renderGroups.value.flatMap((g) => g.options),
);

// --- Selection helpers ----------------------------------------------------
function resolveOption(value: string): SelectOption {
  return (
    flatOptions.value.find((o) => o.value === value) ??
    labelCache.get(value) ?? { value, label: value }
  );
}

const selectedValues = computed<string[]>(() =>
  props.multiple ? multi.value ?? [] : single.value != null ? [single.value] : [],
);
const selectedOptions = computed<SelectOption[]>(() =>
  selectedValues.value.map(resolveOption),
);
const selectedSingle = computed<SelectOption | null>(() =>
  !props.multiple && single.value != null ? resolveOption(single.value) : null,
);
function isSelected(value: string): boolean {
  return selectedValues.value.includes(value);
}
const hasSelection = computed(() => selectedValues.value.length > 0);

// Multi/chips: cap chips to a fixed single row → as many WHOLE chips as physically
// fit + a `+N` overflow chip. The visible count is DYNAMIC (measured), not a fixed
// threshold. Driven by the shared useChipOverflow composable below.
const chipRowRef = ref<HTMLElement | null>(null);
const chipMeasureRef = ref<HTMLElement | null>(null);

// Optional per-chip max width. Number → px; any other CSS length used as-is.
const chipMaxWidthStyle = computed(() => {
  if (props.chipMaxWidth == null) return undefined;
  return typeof props.chipMaxWidth === 'number'
    ? `${props.chipMaxWidth}px`
    : props.chipMaxWidth;
});
const chipsTruncate = computed(() => props.chipMaxWidth != null);

const { visibleCount: visibleChipCount, hiddenCount: overflowCount, recompute: recomputeChips } =
  useChipOverflow({
    trackRef: chipRowRef,
    measureRef: chipMeasureRef,
    // The X + chevron live OUTSIDE the chip track (FieldShell #trailing), so the
    // track width is already net of them — nothing to reserve here.
    total: () => (props.multiple && !summaryMode.value ? selectedOptions.value.length : 0),
  });

const visibleChips = computed(() =>
  selectedOptions.value.slice(0, visibleChipCount.value),
);
// Hidden (overflowed) options behind the "+N" pill — labels for the shared
// ChipOverflow's tooltip/panel rows, values for its per-item remove.
const overflowOptions = computed(() =>
  selectedOptions.value.slice(visibleChipCount.value),
);
const overflowLabels = computed(() => overflowOptions.value.map((o) => o.label));
const overflowValues = computed(() => overflowOptions.value.map((o) => o.value));
// Recompute the fit when the selection or chip-width cap changes.
watch(
  () => [selectedOptions.value, chipMaxWidthStyle.value] as const,
  () => recomputeChips(),
  { deep: true },
);
// All selected labels, for the summary pill's title tooltip.
const allSelectedLabels = computed(() =>
  selectedOptions.value.map((o) => o.label).join(', '),
);

// --- Open / active state --------------------------------------------------
const open = ref(false);
const activeIndex = ref(-1);

const triggerRef = ref<HTMLButtonElement | null>(null);
// Anchor for the popover: the component root (wraps the whole FieldShell), NOT
// the inner <button> — the button excludes the shell's padding/leading/trailing,
// so anchoring to it made the popover narrower than the visible field.
const rootRef = ref<HTMLDivElement | null>(null);
const listRef = ref<HTMLDivElement | null>(null);
const popoverRef = ref<HTMLDivElement | null>(null);
const searchRef = ref<HTMLInputElement | null>(null);
const sentinelRef = ref<HTMLDivElement | null>(null);

const { isDark } = useTheme();

// The options popover is TELEPORTED to <body> (Issue 2c) so overflow-clipping
// ancestors (a resizable/clipped box) can't trap it; it floats above the page and
// is anchored to the trigger via useAnchoredPosition. `matchTriggerWidth` keeps
// the menu at least as wide as the field.
const popoverWidth = ref(0);
// Width FROZEN to the menu's natural (max-content) width — at least the trigger
// width, capped to the viewport — ONCE the first page of real options has
// rendered. With the width pinned, appending more (longer) options while
// scrolling, or filtering via search, can no longer resize the menu mid-interaction
// (the previous bug); longer rows truncate within the frozen width instead. Reset
// on every open and on each data reset so the next content re-measures.
const lockedWidth = ref<number | null>(null);
let widthLocked = false;
const { style: popoverPos, update: updatePopoverPos } = useAnchoredPosition(
  rootRef,
  popoverRef,
  { placement: () => 'bottom-start', gap: 4, flip: true },
);
function repositionPopover(): void {
  if (rootRef.value) popoverWidth.value = rootRef.value.offsetWidth;
  updatePopoverPos();
}
// Freeze the menu to its current natural width, but only ONCE per open and only
// after REAL options have rendered (never the loading skeleton). Measured while
// still auto-width (unlocked), so it captures the full max-content width.
function lockMenuWidth(): void {
  if (widthLocked || showInitialLoading.value) return;
  const el = popoverRef.value;
  if (!el) return;
  const max = window.innerWidth - 16;
  lockedWidth.value = Math.min(Math.max(popoverWidth.value, el.offsetWidth), max);
  widthLocked = true;
  nextTick(updatePopoverPos);
}

function optionId(index: number): string {
  return `${resolvedId.value}-opt-${index}`;
}
function indexOfOption(opt: SelectOption): number {
  return flatOptions.value.indexOf(opt);
}

function firstEnabledIndex(): number {
  return flatOptions.value.findIndex((o) => !o.disabled);
}
function lastEnabledIndex(): number {
  for (let i = flatOptions.value.length - 1; i >= 0; i -= 1) {
    if (!flatOptions.value[i].disabled) return i;
  }
  return -1;
}
// Step from `from` in `dir`, skipping disabled options. Clamps at the edges.
function nextEnabled(from: number, dir: 1 | -1): number {
  const len = flatOptions.value.length;
  let i = from + dir;
  while (i >= 0 && i < len) {
    if (!flatOptions.value[i].disabled) return i;
    i += dir;
  }
  return from;
}

function openList(): void {
  if (disabled.value || readonly.value) return;
  open.value = true;
  emit('open');
  // Release any prior width lock so this open re-measures the natural width.
  lockedWidth.value = null;
  widthLocked = false;
  // Start on the first selected option, else the first enabled one.
  const selectedIdx = flatOptions.value.findIndex((o) => isSelected(o.value));
  activeIndex.value = selectedIdx >= 0 ? selectedIdx : firstEnabledIndex();
  // (Re)load the first page if we have nothing yet, or if a query/filter changed
  // while the popover was closed (asyncDirty) so re-opening reflects it.
  if (isAsync.value && (asyncOptions.value.length === 0 || asyncDirty.value)) {
    asyncDirty.value = false;
    void refetch();
  }
  // Position the teleported popover BEFORE moving focus into it, and focus with
  // preventScroll, so a body-teleported panel never scroll-jumps the page (Issue 3).
  nextTick(() => {
    repositionPopover();
    requestAnimationFrame(() => {
      repositionPopover();
      // Static / already-cached async options are present now → freeze the width.
      // A fresh async load locks later (after its first page) via refetch().
      lockMenuWidth();
    });
    if (props.searchable) searchRef.value?.focus({ preventScroll: true });
    scrollActiveIntoView();
    observeSentinel();
  });
  window.addEventListener('scroll', updatePopoverPos, true);
  window.addEventListener('resize', updatePopoverPos);
}
function closeList(returnFocus = true): void {
  if (!open.value) return;
  open.value = false;
  activeIndex.value = -1;
  lockedWidth.value = null;
  widthLocked = false;
  unobserveSentinel();
  window.removeEventListener('scroll', updatePopoverPos, true);
  window.removeEventListener('resize', updatePopoverPos);
  emit('close');
  if (returnFocus) nextTick(() => triggerRef.value?.focus({ preventScroll: true }));
}
function toggle(): void {
  open.value ? closeList() : openList();
}

function chooseIndex(index: number): void {
  const opt = flatOptions.value[index];
  if (!opt || opt.disabled) return;
  cacheOption(opt);
  if (props.multiple) {
    const set = new Set(multi.value ?? []);
    set.has(opt.value) ? set.delete(opt.value) : set.add(opt.value);
    multi.value = [...set];
    // Stay open in multi mode; keep focus where the user is interacting.
    if (props.searchable) nextTick(() => searchRef.value?.focus({ preventScroll: true }));
  } else {
    single.value = opt.value;
    closeList();
  }
}

function removeValue(value: string): void {
  if (disabled.value || readonly.value) return;
  if (props.multiple) {
    multi.value = (multi.value ?? []).filter((v) => v !== value);
  } else {
    single.value = null;
  }
}
function clearAll(): void {
  if (disabled.value || readonly.value) return;
  if (props.multiple) multi.value = [];
  else single.value = null;
  nextTick(() => {
    if (open.value && props.searchable) searchRef.value?.focus({ preventScroll: true });
    else triggerRef.value?.focus({ preventScroll: true });
  });
}

function move(dir: 1 | -1): void {
  if (!open.value) {
    openList();
    return;
  }
  if (activeIndex.value === -1) {
    activeIndex.value = dir === 1 ? firstEnabledIndex() : lastEnabledIndex();
  } else {
    activeIndex.value = nextEnabled(activeIndex.value, dir);
  }
  nextTick(scrollActiveIntoView);
}

function scrollActiveIntoView(): void {
  if (activeIndex.value < 0) return;
  const list = listRef.value;
  const node = list?.querySelector<HTMLElement>(
    `#${CSS.escape(optionId(activeIndex.value))}`,
  );
  if (!list || !node) return;
  // Scroll ONLY the option list — never scrollIntoView(), which also scrolls
  // document ancestors and can yank the page when the panel is mid-transition.
  const listRect = list.getBoundingClientRect();
  const nodeRect = node.getBoundingClientRect();
  if (nodeRect.top < listRect.top) list.scrollTop += nodeRect.top - listRect.top;
  else if (nodeRect.bottom > listRect.bottom)
    list.scrollTop += nodeRect.bottom - listRect.bottom;
}

// Type-ahead buffer (static, non-searchable mode only).
let typeBuffer = '';
let typeTimer: ReturnType<typeof setTimeout> | undefined;
function onTypeAhead(char: string): void {
  typeBuffer += char.toLowerCase();
  if (typeTimer) clearTimeout(typeTimer);
  typeTimer = setTimeout(() => (typeBuffer = ''), 600);
  const match = flatOptions.value.findIndex(
    (o) => !o.disabled && o.label.toLowerCase().startsWith(typeBuffer),
  );
  if (match >= 0) {
    if (!open.value) openList();
    activeIndex.value = match;
    nextTick(scrollActiveIntoView);
  }
}

function onTriggerKeydown(event: KeyboardEvent): void {
  if (disabled.value || readonly.value) return;
  handleNavKey(event);
}

// Shared nav handling for both the trigger and the in-dropdown search box.
function handleNavKey(event: KeyboardEvent): void {
  switch (event.key) {
    case 'ArrowDown':
      event.preventDefault();
      move(1);
      break;
    case 'ArrowUp':
      event.preventDefault();
      move(-1);
      break;
    case 'Home':
      if (open.value) {
        event.preventDefault();
        activeIndex.value = firstEnabledIndex();
        nextTick(scrollActiveIntoView);
      }
      break;
    case 'End':
      if (open.value) {
        event.preventDefault();
        activeIndex.value = lastEnabledIndex();
        nextTick(scrollActiveIntoView);
      }
      break;
    case 'Enter':
      event.preventDefault();
      if (open.value && activeIndex.value >= 0) chooseIndex(activeIndex.value);
      else openList();
      break;
    case ' ':
      // Space toggles/selects when open; opens when closed. In a search box,
      // let the space character through (don't hijack typing).
      if (props.searchable && open.value) return;
      event.preventDefault();
      if (open.value && activeIndex.value >= 0) chooseIndex(activeIndex.value);
      else openList();
      break;
    case 'Backspace':
      // Multi + empty query: remove the last chip.
      if (
        props.multiple &&
        open.value &&
        !query.value &&
        selectedValues.value.length
      ) {
        event.preventDefault();
        removeValue(selectedValues.value[selectedValues.value.length - 1]);
      }
      break;
    case 'Escape':
      if (open.value) {
        event.preventDefault();
        event.stopPropagation();
        closeList();
      }
      break;
    case 'Tab':
      if (open.value) closeList(false);
      break;
    default:
      if (
        !props.searchable &&
        event.key.length === 1 &&
        !event.metaKey &&
        !event.ctrlKey &&
        !event.altKey
      ) {
        onTypeAhead(event.key);
      }
  }
}

useOutsideClick([popoverRef, triggerRef], () => closeList(false), open);

// --- Async fetching -------------------------------------------------------
const fetching = ref(false);
const fetchError = ref(false);
const cursor = ref<string | null>(null);
const hasMore = ref(true);
// Set when a query/filter changed while the popover was CLOSED, so the next open
// reloads instead of showing stale cached options.
const asyncDirty = ref(false);
// A token guards against out-of-order async resolutions after reset.
let fetchToken = 0;

async function loadPage(reset: boolean): Promise<void> {
  if (!props.fetchOptions) return;
  // A reset (open / query / filter / retry) ALWAYS supersedes work in flight:
  // bumping the token below makes any pending response a no-op, so rapid filter
  // toggles can never leave stale options. Appends (loadMore) instead wait for
  // the current page so we don't request the same cursor twice or skip pages.
  if (!reset && fetching.value) return;
  if (!reset && !hasMore.value) return;

  const token = ++fetchToken;
  fetching.value = true;
  fetchError.value = false;
  if (reset) {
    cursor.value = null;
    hasMore.value = true;
  }
  try {
    const result = await props.fetchOptions({
      cursor: reset ? null : cursor.value,
      query: query.value,
      filters: filters.value ?? {},
    });
    if (token !== fetchToken) return; // a newer request superseded this one
    result.options.forEach(cacheOption);
    asyncOptions.value = reset
      ? result.options
      : [...asyncOptions.value, ...result.options];
    cursor.value = result.nextCursor;
    hasMore.value = result.nextCursor != null;
  } catch {
    if (token !== fetchToken) return;
    fetchError.value = true;
    hasMore.value = false;
  } finally {
    if (token === fetchToken) fetching.value = false;
  }
}

/** Reset cursor + reload the first page (used on open, query/filter change, retry). */
async function refetch(): Promise<void> {
  asyncOptions.value = [];
  activeIndex.value = -1;
  await loadPage(true);
  nextTick(() => {
    observeSentinel();
    // First async page is rendered → freeze the width (guarded to once per open,
    // so later search-driven refetches keep the established width and stay stable).
    lockMenuWidth();
    if (props.searchable) searchRef.value?.focus({ preventScroll: true });
  });
}

function loadMore(): void {
  if (isAsync.value && !fetching.value && hasMore.value) void loadPage(false);
}

// Debounced query → refetch (async) or just re-filter (static, reactive already).
let queryTimer: ReturnType<typeof setTimeout> | undefined;
function onQueryInput(value: string): void {
  query.value = value;
  if (!isAsync.value) {
    activeIndex.value = firstEnabledIndex();
    return;
  }
  if (queryTimer) clearTimeout(queryTimer);
  queryTimer = setTimeout(() => {
    if (open.value) void refetch();
    else asyncDirty.value = true;
  }, props.searchDebounce);
}

// A filter change (from #header slot / v-model:filters) resets + refetches when
// open; when closed it defers the reload to the next open.
watch(
  filters,
  () => {
    if (!isAsync.value) return;
    if (open.value) void refetch();
    else asyncDirty.value = true;
  },
  { deep: true },
);

// IntersectionObserver sentinel → load next page when scrolled near the bottom.
let io: IntersectionObserver | null = null;
function observeSentinel(): void {
  unobserveSentinel();
  if (!isAsync.value || !sentinelRef.value || !listRef.value) return;
  io = new IntersectionObserver(
    (entries) => {
      if (entries.some((e) => e.isIntersecting)) loadMore();
    },
    { root: listRef.value, rootMargin: '120px' },
  );
  io.observe(sentinelRef.value);
}
function unobserveSentinel(): void {
  io?.disconnect();
  io = null;
}
onBeforeUnmount(unobserveSentinel);
onBeforeUnmount(() => {
  window.removeEventListener('scroll', updatePopoverPos, true);
  window.removeEventListener('resize', updatePopoverPos);
});

// --- List view flags ------------------------------------------------------
const showInitialLoading = computed(
  () =>
    (props.loading && !isAsync.value) ||
    (isAsync.value && fetching.value && asyncOptions.value.length === 0),
);
const showError = computed(
  () => isAsync.value && fetchError.value && asyncOptions.value.length === 0,
);
const isEmpty = computed(
  () =>
    !showInitialLoading.value &&
    !showError.value &&
    flatOptions.value.length === 0,
);
const showLoadingMore = computed(
  () => isAsync.value && fetching.value && asyncOptions.value.length > 0,
);

// Header filter-context: the scoped-slot payload exposed to the #header (and
// #footer) slots so in-dropdown controls (search inputs, on/off toggles, …) can
// drive the loader. `setQuery`/`setFilter` update state, reset the cursor, and
// re-invoke `fetchOptions` with the new args (debounced for the query).
function setQuery(value: string): void {
  onQueryInput(value);
}
function setFilter(key: string, value: unknown): void {
  // Assigning the deep-watched `filters` model triggers the reset+refetch below.
  filters.value = { ...(filters.value ?? {}), [key]: value };
}
const headerSlotProps = computed(() => ({
  /** Current (possibly debounced) search query. */
  query: query.value,
  /** Set the query → resets the cursor + (async) refetches, debounced. */
  setQuery,
  /** Current in-dropdown filter state. */
  filters: filters.value ?? {},
  /** Set one filter key → resets the cursor + (async) refetches. */
  setFilter,
  /** Manually re-run the loader from the first page (e.g. a custom refresh). */
  refetch,
  /** True while a page is being fetched. */
  loading: fetching.value,
}));
</script>

<template>
  <div ref="rootRef" class="relative w-full">
    <!-- Trigger renders through FieldShell so it shares the border + state line. -->
    <FieldShell
      :size="size"
      :disabled="disabled"
      :readonly="readonly"
      :error="invalid"
      :success="success"
      :dirty="dirty"
      :focused="open || undefined"
    >
      <button
        :id="resolvedId"
        ref="triggerRef"
        type="button"
        role="combobox"
        class="flex h-full w-full min-w-0 flex-1 items-center gap-next-2 px-next-3 text-left outline-none disabled:cursor-not-allowed"
        :disabled="disabled"
        :aria-expanded="open"
        :aria-controls="listId"
        aria-haspopup="listbox"
        :aria-multiselectable="multiple ? 'true' : undefined"
        :aria-activedescendant="
          open && activeIndex >= 0 ? optionId(activeIndex) : undefined
        "
        :aria-invalid="invalid ? 'true' : undefined"
        :aria-describedby="resolvedDescribedBy"
        :aria-required="required ? 'true' : undefined"
        :aria-readonly="readonly ? 'true' : undefined"
        :aria-label="ariaLabel"
        @click="toggle"
        @keydown="onTriggerKeydown"
      >
        <!-- MULTI · SUMMARY: a compact "N selected" pill (always one line). -->
        <span
          v-if="multiple && summaryMode"
          class="flex min-w-0 flex-1 items-center overflow-hidden"
        >
          <Badge
            v-if="hasSelection"
            variant="primary"
            size="sm"
            :title="allSelectedLabels"
          >
            {{ t('select.summaryCount', '{count} selected', { count: selectedValues.length }) }}
          </Badge>
          <span v-else class="truncate text-next-muted-foreground">
            {{ placeholderText }}
          </span>
        </span>

        <!-- MULTI · CHIPS: chip row, fixed single line, dynamic +N overflow.
             `chipRowRef` is the measured track; a hidden measuring row holds every
             chip at natural width so useChipOverflow can fit as many WHOLE chips as
             physically fit and collapse the rest into "+N" (no-grow). -->
        <span
          v-else-if="multiple"
          ref="chipRowRef"
          class="relative flex min-w-0 flex-1 flex-nowrap items-center gap-next-1 overflow-hidden"
        >
          <template v-if="hasSelection">
            <!-- Each visible chip. Consumers can fully replace the chip via the
                 #chip scoped slot ({ option, remove }); the default is the
                 removable neutral Badge. The chip is wrapped so #chip content can
                 still be capped by `chipMaxWidth`. -->
            <template v-for="opt in visibleChips" :key="opt.value">
              <span
                v-if="$slots.chip"
                class="inline-flex min-w-0 items-center"
                :style="chipMaxWidthStyle ? { maxWidth: chipMaxWidthStyle } : undefined"
              >
                <slot
                  name="chip"
                  :option="opt"
                  :remove="() => removeValue(opt.value)"
                />
              </span>
              <Badge
                v-else
                variant="neutral"
                size="sm"
                :truncate="chipsTruncate"
                :style="chipMaxWidthStyle ? { maxWidth: chipMaxWidthStyle } : undefined"
                removable
                :remove-label="t('select.removeItem', 'Remove {label}', { label: opt.label })"
                :icon="opt.icon"
                @remove="removeValue(opt.value)"
              >
                {{ opt.label }}
              </Badge>
            </template>
            <!-- "+N" overflow (shared with PillGroupInput): hover → tooltip of
                 hidden labels; click/Enter → teleported interactive remove panel
                 with a per-item ✕ (same behavior as PillGroupInput). -->
            <ChipOverflow
              v-if="overflowCount > 0"
              :items="overflowLabels"
              :values="overflowValues"
              :count="overflowCount"
              :removable="!disabled && !readonly"
              :panel-id="overflowPanelId"
              @remove="removeValue"
            />

            <!-- Hidden measuring row: every chip at natural width, off-flow. -->
            <span
              ref="chipMeasureRef"
              class="next-select__measure pointer-events-none invisible absolute left-0 top-0 flex flex-nowrap items-center gap-next-1 whitespace-nowrap"
              aria-hidden="true"
            >
              <template v-for="opt in selectedOptions" :key="opt.value">
                <span
                  v-if="$slots.chip"
                  data-measure-chip
                  class="inline-flex items-center"
                  :style="chipMaxWidthStyle ? { maxWidth: chipMaxWidthStyle } : undefined"
                >
                  <slot name="chip" :option="opt" :remove="() => {}" />
                </span>
                <Badge
                  v-else
                  data-measure-chip
                  variant="neutral"
                  size="sm"
                  :truncate="chipsTruncate"
                  :style="chipMaxWidthStyle ? { maxWidth: chipMaxWidthStyle } : undefined"
                  :icon="opt.icon"
                >
                  {{ opt.label }}
                </Badge>
              </template>
            </span>
          </template>
          <span v-else class="truncate text-next-muted-foreground">
            {{ placeholderText }}
          </span>
        </span>

        <!-- SINGLE: selected label (or placeholder). Consumers can fully replace
             the selected-value display via the #value scoped slot. -->
        <span v-else class="flex min-w-0 flex-1 items-center gap-next-2">
          <template v-if="selectedSingle">
            <slot name="value" :option="selectedSingle">
              <Icon
                v-if="selectedSingle.icon"
                :name="selectedSingle.icon"
                class="shrink-0 text-next-muted-foreground"
              />
              <span class="truncate">{{ selectedSingle.label }}</span>
            </slot>
          </template>
          <span v-else class="truncate text-next-muted-foreground">
            {{ placeholderText }}
          </span>
        </span>
      </button>

      <!-- Trailing controls, in the project-mandated order: the CONDITIONAL clear
           "X" first, then the PERMANENT open/close chevron. The chevron is always
           visible, so it must NEVER move; the X is conditional and sits to its left
           in reserved space. The clear box is reserved whenever the field is
           editable so toggling it as the selection changes never shifts the layout
           (no-grow rule) — only its visibility flips. -->
      <template #trailing>
        <span class="flex items-center gap-next-1">
          <span
            v-if="!disabled && !readonly"
            class="flex h-5 w-5 items-center justify-center"
          >
            <button
              type="button"
              class="flex items-center rounded-next-sm p-next-0_5 text-next-muted-foreground hover:text-next-fg"
              :class="hasSelection ? '' : 'invisible'"
              :aria-hidden="hasSelection ? undefined : 'true'"
              :aria-label="t('select.clear', 'Clear selection')"
              tabindex="-1"
              @click.stop="clearAll"
            >
              <Icon name="x" />
            </button>
          </span>
          <Icon
            name="chevron-down"
            class="shrink-0 text-next-muted-foreground transition-transform duration-[var(--duration-next-fast)]"
            :class="open ? 'rotate-180' : ''"
            aria-hidden="true"
          />
        </span>
      </template>
    </FieldShell>

    <!-- Options popover: TELEPORTED to <body> (Issue 2c) so an overflow-clipping
         ancestor can't trap it; positioned `fixed` via useAnchoredPosition against
         the trigger, on the dropdown z-layer. Outside-click (which includes this
         teleported panel via popoverRef), Esc, keyboard nav + aria wiring all keep
         working through the teleport. -->
    <Teleport to="body">
      <!-- Opacity-only transition: a transform on this wrapper would make it the
           containing block for the `fixed` panel inside (CSS spec), pinning the
           panel to the wrapper's in-flow spot at the bottom of <body> during the
           animation — which scroll-jumped the page. Never add translate here. -->
      <Transition
        enter-active-class="transition-opacity duration-[var(--duration-next-fast)] ease-[var(--ease-next-emphasized)]"
        enter-from-class="opacity-0"
        leave-active-class="transition-opacity duration-[var(--duration-next-fast)] ease-[var(--ease-next-exit)]"
        leave-to-class="opacity-0"
      >
        <div
          v-if="open"
          class="next-root next-overlay-root"
          :class="isDark ? 'dark' : ''"
        >
          <div
            ref="popoverRef"
            class="fixed z-[var(--z-next-popover)] flex max-h-[28rem] flex-col overflow-hidden rounded-next-md border border-next-border bg-next-popover text-next-popover-foreground shadow-next-lg"
            :style="{
              top: `${popoverPos.top}px`,
              left: `${popoverPos.left}px`,
              minWidth: `${popoverWidth}px`,
              maxWidth: 'calc(100vw - 1rem)',
              ...(lockedWidth != null ? { width: `${lockedWidth}px` } : {}),
            }"
          >
        <!-- Sticky header: built-in search + the #header slot. The slot receives
             the filter-context payload { query, setQuery, filters, setFilter, … }
             so custom controls drive the query/filters that feed fetchOptions
             (each change resets the cursor and re-runs the loader). -->
        <div
          v-if="searchable || $slots.header"
          class="sticky top-0 z-10 flex flex-col gap-next-2 border-b border-next-border bg-next-popover p-next-2"
        >
          <div v-if="searchable" class="relative flex items-center">
            <Icon
              name="search"
              class="pointer-events-none absolute left-next-3 top-1/2 -translate-y-1/2 text-next-muted-foreground"
            />
            <input
              :id="searchId"
              ref="searchRef"
              :value="query"
              type="text"
              role="searchbox"
              :placeholder="searchPlaceholderText"
              class="h-9 w-full rounded-next-sm border border-next-input bg-next-card pl-next-8 pr-next-2 text-next-sm text-next-fg outline-none placeholder:text-next-muted-foreground focus-visible:border-next-ring focus-visible:ring-2 focus-visible:ring-next-ring/30"
              :aria-controls="listId"
              :aria-label="t('select.searchLabel', 'Search options')"
              @input="onQueryInput(($event.target as HTMLInputElement).value)"
              @keydown="handleNavKey"
            />
          </div>
          <slot name="header" v-bind="headerSlotProps" />
        </div>

        <!-- Initial loading — option-row-shaped skeletons (project rule: skeletons
             replace the spinner + "Loading…" for cursor-pagination loading). Each
             mimics an option row: an icon-sized circle + a text line. -->
        <div
          v-if="showInitialLoading"
          class="py-next-1"
          role="status"
          :aria-label="t('select.loading', 'Loading options…')"
        >
          <div
            v-for="n in 5"
            :key="n"
            class="mx-next-1 flex items-center gap-next-2 rounded-next-sm px-next-2 py-next-1_5"
            aria-hidden="true"
          >
            <Skeleton variant="circle" diameter="1rem" />
            <Skeleton variant="text" :width="`${55 + ((n * 13) % 35)}%`" />
          </div>
        </div>

        <!-- Error (async) with retry -->
        <div
          v-else-if="showError"
          class="flex flex-col items-start gap-next-2 px-next-3 py-next-3 text-next-sm"
          role="alert"
        >
          <span class="flex items-center gap-next-2 text-next-danger">
            <Icon name="alert-circle" class="shrink-0" aria-hidden="true" />
            {{ t('select.loadError', 'Couldn’t load options.') }}
          </span>
          <button
            type="button"
            class="rounded-next-sm border border-next-border px-next-2 py-next-1 text-next-xs font-next-medium text-next-fg hover:bg-next-accent"
            @click="refetch"
          >
            {{ t('select.retry', 'Retry') }}
          </button>
        </div>

        <!-- Empty -->
        <div
          v-else-if="isEmpty"
          class="px-next-3 py-next-3 text-next-sm text-next-muted-foreground"
        >
          {{ emptyTextResolved }}
        </div>

        <!-- Options -->
        <div
          v-else
          ref="listRef"
          class="min-h-0 flex-1 overflow-y-auto py-next-1"
        >
          <ul :id="listId" role="listbox" :aria-multiselectable="multiple ? 'true' : undefined" tabindex="-1">
            <template v-for="(group, gi) in renderGroups" :key="gi">
              <li
                v-if="group.label"
                :id="`${listId}-group-${gi}`"
                role="presentation"
                class="px-next-3 pb-next-1 pt-next-2 text-next-2xs font-next-semibold uppercase tracking-[var(--tracking-next-wide)] text-next-muted-foreground"
              >
                {{ group.label }}
              </li>
              <li
                v-for="opt in group.options"
                :id="optionId(indexOfOption(opt))"
                :key="opt.value"
                role="option"
                :aria-selected="isSelected(opt.value)"
                :aria-disabled="opt.disabled ? 'true' : undefined"
                class="mx-next-1 flex cursor-pointer items-center gap-next-2 rounded-next-sm px-next-2 py-next-1_5 text-next-sm"
                :class="[
                  opt.disabled ? 'cursor-not-allowed opacity-50' : '',
                  indexOfOption(opt) === activeIndex && !opt.disabled
                    ? 'bg-next-accent text-next-accent-foreground'
                    : '',
                  isSelected(opt.value) && indexOfOption(opt) !== activeIndex
                    ? 'bg-next-primary-subtle text-next-primary-subtle-foreground'
                    : '',
                ]"
                @mouseenter="!opt.disabled && (activeIndex = indexOfOption(opt))"
                @click="chooseIndex(indexOfOption(opt))"
              >
                <!-- Multi: checkbox affordance -->
                <span
                  v-if="multiple"
                  class="flex h-4 w-4 shrink-0 items-center justify-center rounded-next-xs border"
                  :class="
                    isSelected(opt.value)
                      ? 'border-next-primary bg-next-primary text-next-primary-foreground'
                      : 'border-next-input bg-next-card'
                  "
                  aria-hidden="true"
                >
                  <Icon v-if="isSelected(opt.value)" name="check" :stroke-width="3" class="text-[0.7rem]" />
                </span>
                <!-- Option row content. Consumers can fully customize it via the
                     #option scoped slot ({ option, selected, active }); the
                     default is the current leading-icon + label. The multi
                     checkbox (above) and single-select check (below) stay owned
                     by Select. -->
                <span class="flex min-w-0 flex-1 items-center gap-next-2">
                  <slot
                    name="option"
                    :option="opt"
                    :selected="isSelected(opt.value)"
                    :active="indexOfOption(opt) === activeIndex"
                  >
                    <Icon v-if="opt.icon" :name="opt.icon" class="shrink-0" />
                    <span class="min-w-0 flex-1 truncate">{{ opt.label }}</span>
                  </slot>
                </span>
                <Icon
                  v-if="!multiple && isSelected(opt.value)"
                  name="check"
                  class="shrink-0 text-next-primary"
                />
              </li>
            </template>
          </ul>

          <!-- Async sentinel + load-more spinner -->
          <div v-if="isAsync" ref="sentinelRef" aria-hidden="true" class="h-px w-full" />
          <!-- Loading more — option-row-shaped skeletons appended at the bottom
               (project rule: skeletons replace the spinner for cursor pagination). -->
          <div
            v-if="showLoadingMore"
            class="py-next-1"
            role="status"
            :aria-label="t('select.loadingMore', 'Loading more options…')"
          >
            <div
              v-for="n in 3"
              :key="n"
              class="mx-next-1 flex items-center gap-next-2 rounded-next-sm px-next-2 py-next-1_5"
              aria-hidden="true"
            >
              <Skeleton variant="circle" diameter="1rem" />
              <Skeleton variant="text" :width="`${50 + ((n * 17) % 40)}%`" />
            </div>
          </div>
          <div
            v-else-if="isAsync && fetchError && asyncOptions.length > 0"
            class="flex items-center justify-between gap-next-2 px-next-3 py-next-2 text-next-xs text-next-danger"
            role="alert"
          >
            <span class="flex items-center gap-next-1">
              <Icon name="alert-circle" aria-hidden="true" />
              {{ t('select.loadMoreError', 'Failed to load more.') }}
            </span>
            <button
              type="button"
              class="rounded-next-sm border border-next-border px-next-2 py-next-0_5 text-next-fg hover:bg-next-accent"
              @click="loadMore"
            >
              {{ t('select.retry', 'Retry') }}
            </button>
          </div>
        </div>

        <!-- Sticky footer: arbitrary content (counts, a "create" action, …). Gets
             the same filter-context payload as #header. -->
        <div
          v-if="$slots.footer"
          class="sticky bottom-0 z-10 border-t border-next-border bg-next-popover p-next-2"
        >
          <slot name="footer" v-bind="headerSlotProps" />
        </div>
        </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>
