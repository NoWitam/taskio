<script setup lang="ts">
// VariableBrowser — the ONE browsing surface every variable picker renders. It takes a
// built `VariableNode[]` (see ./variableTree) and nothing else: no HTTP, no catalog
// knowledge, no page types. Whoever owns the data decides what the tree contains; this
// component only decides how it is walked, highlighted, announced and picked.
//
// ── LAYOUT: ONE INLINE TREE ────────────────────────────────────────────────────
// Expanding a container reveals its children DIRECTLY BENEATH it, indented one level, in
// the SAME single scrollable list (B3 — the miller-columns experiment is gone, along with
// its `layout` modes and column scrolling). Depth is carried by three cues that must all
// read in light AND dark: per-level INDENTATION, a vertical GUIDE RAIL per ancestor level
// (the row's own parent rail is the strongest one), and a CONTAINER row skin — a chevron
// that rotates 90° when open, the braces glyph, and a heavier label — against the quieter
// leaf rows.
//
// ── QUERY MODE ─────────────────────────────────────────────────────────────────
// A non-empty `query` replaces the tree with a FLAT list of SELECTABLE matches (a
// container is never a search result — picking one is a dead end), each carrying the
// breadcrumb of its ancestors. Clearing the query restores the tree exactly as it was
// (expansion + cursor are untouched). The query INPUT itself belongs to the shell
// (VariableBrowserPopover): it forwards key events here through the exposed `handleKey`,
// so one keyboard model serves both.
//
// ── A11Y ───────────────────────────────────────────────────────────────────────
// This IS a real ARIA tree again: the BODY carries `role="tree"` and is the single
// focusable element (`tabindex=0`) holding `aria-activedescendant` (VIRTUAL focus — DOM
// focus never hops between rows, so the hosting popover keeps one predictable focus
// target, and the search input can drive the same cursor). Rows are `role="treeitem"`
// with `aria-level` / `aria-expanded` / `aria-selected`; because the DOM is FLATTENED (the
// visible rows are siblings, not nested lists) each row also carries `aria-posinset` /
// `aria-setsize` so a screen reader can still state "2 of 5". While QUERYING the same
// element becomes a `listbox` of `option`s — a flat result list is not a tree.
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import Icon from '../primitives/Icon.vue';
import VariableTypeIcon from '../editor/extensions/VariableTypeIcon.vue';
import { useI18n } from '../../app/i18n';
import { nodeIcon, searchNodes } from './variableTree';
import type { VariableNode, VariableNodeMatch } from './types';

const props = withDefaults(
  defineProps<{
    /** The tree to browse (already built + policy-applied by the caller). */
    nodes: VariableNode[];
    /** The path currently CHOSEN by the surface — marks its row `aria-selected`. */
    selectedPath?: string | null;
    /** Accessible name of the tree (the surface's own picker label). */
    rootLabel?: string;
    /** The live search query. Non-empty ⇒ flat results instead of the tree. */
    query?: string;
  }>(),
  { selectedPath: null, rootLabel: undefined, query: '' },
);

const emit = defineEmits<{
  /** A SELECTABLE node was picked (click / Enter / Space). */
  select: [VariableNode];
  /** Esc or Tab — the surface should close. */
  close: [];
  /** ←/→ while browsing RESULTS: hand editing back to the shell's search input. */
  'focus-query': [];
}>();

const { t } = useI18n();

const baseId = `next-vbrowse-${Math.random().toString(36).slice(2, 8)}`;
const bodyRef = ref<HTMLElement | null>(null);
const rootRef = ref<HTMLElement | null>(null);

/** Selector-safe row id (a dotted path is not a valid bare id selector). */
function rowId(path: string): string {
  return `${baseId}-row-${path.replace(/[^\w-]/g, '_')}`;
}

// --- The tree ---------------------------------------------------------------
// `expandedPaths` holds the OPEN container paths; `activePath` is the virtual cursor.
// Both are derived against the LIVE `nodes` on every read, so a changed feed self-heals
// (a stale expansion simply never matches a row).

const expandedPaths = ref<Set<string>>(new Set());
const activePath = ref<string | null>(null);

/** ONE visible row: the node plus everything its rendering + keyboard model needs. */
interface TreeRow {
  node: VariableNode;
  /** The node this row hangs under (null at the root) — drives `rowLabel` + ← movement. */
  parent: VariableNode | null;
  /** 1-based depth, for `aria-level` and the indentation/guide count. */
  level: number;
  /** 1-based position among its SIBLINGS + how many there are (the DOM is flattened). */
  posinset: number;
  setsize: number;
  expandable: boolean;
  expanded: boolean;
  firstChildPath: string | null;
}

/** The rows currently VISIBLE, pre-order — the single list ↑/↓/Home/End walk. */
const rows = computed<TreeRow[]>(() => {
  const out: TreeRow[] = [];
  const walk = (list: VariableNode[], level: number, parent: VariableNode | null): void => {
    list.forEach((node, index) => {
      const expandable = !!node.children?.length;
      const expanded = expandable && expandedPaths.value.has(node.path);
      out.push({
        node,
        parent,
        level,
        posinset: index + 1,
        setsize: list.length,
        expandable,
        expanded,
        firstChildPath: node.children?.[0]?.path ?? null,
      });
      if (expanded) walk(node.children ?? [], level + 1, node);
    });
  };
  walk(Array.isArray(props.nodes) ? props.nodes : [], 1, null);
  return out;
});

const rowIndex = computed(() => rows.value.findIndex((row) => row.node.path === activePath.value));
const activeRow = computed<TreeRow | undefined>(() => rows.value[rowIndex.value]);

// --- Query (flat results) ---------------------------------------------------

const queryText = computed(() => (props.query ?? '').trim());
const isQuerying = computed(() => queryText.value !== '');
const results = computed<VariableNodeMatch[]>(() =>
  isQuerying.value ? searchNodes(props.nodes, queryText.value) : [],
);
/** The highlighted result index while querying. */
const activeResult = ref(0);

watch(
  () => queryText.value,
  () => {
    // A new query re-seeds the results cursor; clearing it restores the tree as it was
    // (expandedPaths / activePath are deliberately untouched).
    activeResult.value = 0;
    if (!isQuerying.value) nextTick(scrollActiveIntoView);
  },
);

/**
 * The ROLE the single focusable body carries: a `tree` while browsing, a `listbox` while a
 * query yields hits, and NOTHING while a query yields none (an empty listbox holding only a
 * "no match" paragraph would be a lie).
 */
const bodyRole = computed<'tree' | 'listbox' | undefined>(() => {
  if (!isQuerying.value) return 'tree';
  return results.value.length ? 'listbox' : undefined;
});

// --- Row semantics ----------------------------------------------------------

/**
 * A file composite's subfields are the fixed `{id,name,type,size,url}` machine keys. When the
 * catalog labelled one with its RAW KEY (the descriptor's own field label), the rendering
 * layer localizes it — the model deliberately stays presentation-free. A subfield that came
 * in as a real catalog entry already carries a human (and qualified) name, so it is left
 * exactly as the catalog wrote it.
 */
function rowLabel(node: VariableNode, parent: VariableNode | null): string {
  if (parent?.base === 'file' && node.path.startsWith(`${parent.path}.`)) {
    const key = node.path.slice(parent.path.length + 1);
    if (node.label === key) return t(`workflows.variable.fileSubfield.${key}`, node.label);
  }
  return node.label;
}

// --- Moving the cursor ------------------------------------------------------

function setActive(path: string | null): void {
  activePath.value = path;
  nextTick(scrollActiveIntoView);
}

function scrollActiveIntoView(): void {
  const id = activeDescendantId.value;
  const row = id ? rootRef.value?.querySelector<HTMLElement>(`#${id}`) : null;
  row?.scrollIntoView?.({ block: 'nearest' });
}

/** Open a container: its children appear directly beneath it, one level in. */
function expand(node: VariableNode): void {
  if (!node.children?.length) return;
  expandedPaths.value.add(node.path);
}

/**
 * Close a container. When the cursor was sitting on one of the rows that just disappeared,
 * it lands on the container itself (never nowhere) — checked STRUCTURALLY against the new
 * visible rows rather than by path prefix, since a slot may rewrite paths.
 */
function collapse(node: VariableNode): void {
  if (!expandedPaths.value.delete(node.path)) return;
  if (!rows.value.some((row) => row.node.path === activePath.value)) setActive(node.path);
}

function toggle(node: VariableNode, expanded: boolean): void {
  if (expanded) collapse(node);
  else expand(node);
}

/**
 * The single row activation rule:
 *   • selectable            → pick it (a file composite included — its chevron is the
 *                             separate, non-selecting way into its subfields),
 *   • container (not selectable, expandable) → clicking ANYWHERE on it toggles it.
 */
function activate(row: TreeRow): void {
  setActive(row.node.path);
  if (row.node.selectable) emit('select', row.node);
  else if (row.expandable) toggle(row.node, row.expanded);
}

/** The chevron is its OWN target: it toggles and never selects. */
function onChevronClick(row: TreeRow): void {
  setActive(row.node.path);
  toggle(row.node, row.expanded);
}

function chooseResult(index: number): void {
  const hit = results.value[index];
  if (hit) emit('select', hit.node);
}

// --- Keyboard ---------------------------------------------------------------

const activeDescendantId = computed(() => {
  if (isQuerying.value) {
    const hit = results.value[activeResult.value];
    return hit ? rowId(hit.node.path) : undefined;
  }
  return activePath.value ? rowId(activePath.value) : undefined;
});

/** ↑/↓ over the VISIBLE rows (a collapsed subtree simply is not there). */
function move(delta: number): void {
  const list = rows.value;
  if (!list.length) return;
  const from = rowIndex.value;
  const next = Math.max(0, Math.min((from < 0 ? 0 : from) + delta, list.length - 1));
  setActive(list[next].node.path);
}

function moveResult(delta: number): void {
  const total = results.value.length;
  if (!total) return;
  activeResult.value = Math.max(0, Math.min(activeResult.value + delta, total - 1));
  nextTick(scrollActiveIntoView);
}

let typeAheadBuffer = '';
let typeAheadTimer: ReturnType<typeof setTimeout> | undefined;
/** Type-ahead is TREE-only: while querying, printable keys belong to the search input. */
function typeAhead(char: string): void {
  typeAheadBuffer += char.toLowerCase();
  if (typeAheadTimer) clearTimeout(typeAheadTimer);
  typeAheadTimer = setTimeout(() => (typeAheadBuffer = ''), 600);
  const list = rows.value;
  const start = Math.max(0, rowIndex.value);
  for (let step = 1; step <= list.length; step += 1) {
    const row = list[(start + step) % list.length];
    if ((row.node.label ?? '').toLowerCase().startsWith(typeAheadBuffer)) {
      setActive(row.node.path);
      return;
    }
  }
}
onBeforeUnmount(() => {
  if (typeAheadTimer) clearTimeout(typeAheadTimer);
});

/**
 * The ONE keyboard model, shared by the body (virtual focus) and the shell's search input
 * (which forwards its key events here). `origin` says where the event came from: while
 * querying, ←/→ must keep editing the TEXT when the caret is in the input, and hand focus
 * BACK to it when the user is arrowing the results from the body.
 *
 * The tree arm is the WAI-ARIA tree contract: → expands a closed container (or steps into an
 * open one), ← collapses an open container (or steps out to the parent).
 *
 * @returns true when the event was consumed (the caller should not act on it further).
 */
function handleKey(event: KeyboardEvent, origin: 'body' | 'query' = 'body'): boolean {
  if (event.key === 'Escape') {
    event.preventDefault();
    event.stopPropagation();
    emit('close');
    return true;
  }
  if (event.key === 'Tab') {
    emit('close'); // let focus move on — never trap it
    return false;
  }

  if (isQuerying.value) {
    switch (event.key) {
      case 'ArrowDown':
        event.preventDefault();
        moveResult(1);
        return true;
      case 'ArrowUp':
        event.preventDefault();
        moveResult(-1);
        return true;
      case 'Home':
        event.preventDefault();
        activeResult.value = 0;
        return true;
      case 'End':
        event.preventDefault();
        activeResult.value = Math.max(0, results.value.length - 1);
        return true;
      case 'Enter':
        event.preventDefault();
        chooseResult(activeResult.value);
        return true;
      case 'ArrowLeft':
      case 'ArrowRight':
        // From the body: give the caret back. From the input: let it move the caret.
        if (origin === 'body') {
          event.preventDefault();
          emit('focus-query');
          return true;
        }
        return false;
      default:
        return false;
    }
  }

  const current = activeRow.value;
  const list = rows.value;
  switch (event.key) {
    case 'ArrowDown':
      event.preventDefault();
      move(1);
      return true;
    case 'ArrowUp':
      event.preventDefault();
      move(-1);
      return true;
    case 'Home':
      event.preventDefault();
      if (list.length) setActive(list[0].node.path);
      return true;
    case 'End':
      event.preventDefault();
      if (list.length) setActive(list[list.length - 1].node.path);
      return true;
    case 'ArrowRight':
      event.preventDefault();
      if (!current?.expandable) return true; // a leaf: no-op
      if (!current.expanded) expand(current.node);
      else if (current.firstChildPath) setActive(current.firstChildPath);
      return true;
    case 'ArrowLeft':
      event.preventDefault();
      if (!current) return true;
      if (current.expanded) collapse(current.node);
      else if (current.parent) setActive(current.parent.path);
      return true;
    case 'Enter':
    case ' ':
      event.preventDefault();
      if (current) activate(current);
      return true;
    default:
      if (event.key.length === 1 && !event.metaKey && !event.ctrlKey && !event.altKey) {
        event.preventDefault();
        typeAhead(event.key);
        return true;
      }
      return false;
  }
}

function onBodyKeydown(event: KeyboardEvent): void {
  handleKey(event, 'body');
}

// --- Seeding + self-healing -------------------------------------------------

/** The chain of nodes from a root down to `path` (inclusive), or null when unknown. */
function trailTo(list: VariableNode[], path: string): VariableNode[] | null {
  for (const node of list) {
    if (node.path === path) return [node];
    const deeper = node.children?.length ? trailTo(node.children, path) : null;
    if (deeper) return [node, ...deeper];
  }
  return null;
}

/**
 * Put the cursor on a sensible first row: the CHOSEN path when the feed still holds it
 * (opening every container on the way, so it is actually visible), else the first root.
 */
function seed(): void {
  const roots = Array.isArray(props.nodes) ? props.nodes : [];
  const trail = props.selectedPath ? trailTo(roots, props.selectedPath) : null;
  if (trail?.length) {
    for (const node of trail.slice(0, -1)) expandedPaths.value.add(node.path);
    activePath.value = trail[trail.length - 1].path;
    return;
  }
  activePath.value = roots[0]?.path ?? null;
}
seed();

watch(
  () => props.nodes,
  () => {
    // A new feed (async catalog, changed filter): re-seed when the cursor no longer exists.
    if (!rows.value.some((row) => row.node.path === activePath.value)) seed();
  },
);

function focus(): void {
  bodyRef.value?.focus({ preventScroll: true });
  nextTick(scrollActiveIntoView);
}

defineExpose({ handleKey, focus, activeDescendantId });
</script>

<template>
  <div ref="rootRef" class="next-vbrowse" data-variable-browser-root>
    <!-- GLOBAL EMPTY: nothing to browse at all. Loading is the caller's concern, so the
         hint only says the catalog MAY still be arriving. -->
    <div
      v-if="!nodes.length"
      class="flex flex-col gap-next-1 px-next-4 py-next-6 text-center"
      data-variable-browser-empty
    >
      <p class="text-next-sm text-next-fg">{{ t('variableBrowser.empty') }}</p>
      <p class="text-next-xs text-next-muted-foreground">{{ t('variableBrowser.emptyHint') }}</p>
    </div>

    <!-- The single focusable element: an ARIA tree (or, while querying, a listbox) whose
         cursor is VIRTUAL — `aria-activedescendant` names the row, DOM focus never moves. -->
    <div
      v-else
      ref="bodyRef"
      tabindex="0"
      class="next-vbrowse__body outline-none"
      data-variable-browser
      :role="bodyRole"
      :aria-label="rootLabel ?? t('variableBrowser.label')"
      :aria-activedescendant="activeDescendantId"
      @keydown="onBodyKeydown"
    >
      <!-- QUERY MODE: a flat list of SELECTABLE matches (a container is never a result). -->
      <template v-if="isQuerying">
        <p
          v-if="!results.length"
          class="px-next-3 py-next-4 text-next-sm text-next-muted-foreground"
          data-variable-browser-nomatch
        >
          {{ t('variableBrowser.noMatches', '', { query: queryText }) }}
        </p>
        <template v-else>
          <div
            v-for="(hit, index) in results"
            :id="rowId(hit.node.path)"
            :key="hit.node.path"
            role="option"
            :aria-selected="hit.node.path === selectedPath"
            class="next-vbrowse__row"
            :class="index === activeResult ? 'is-active' : ''"
            @mouseenter="activeResult = index"
            @click="chooseResult(index)"
          >
            <VariableTypeIcon
              :icon="nodeIcon(hit.node)"
              :nullable="hit.node.nullable"
              :array="hit.node.array"
              class="shrink-0 text-next-muted-foreground"
            />
            <span class="min-w-0 flex-1 truncate">{{ hit.node.label }}</span>
            <span
              v-if="hit.breadcrumb.length"
              class="ml-next-2 shrink-0 truncate text-next-xs text-next-muted-foreground"
            >{{ hit.breadcrumb.join(' › ') }}</span>
          </div>
        </template>
      </template>

      <!-- TREE MODE: every VISIBLE row, flattened, each carrying its own depth. -->
      <template v-else>
        <div
          v-for="row in rows"
          :id="rowId(row.node.path)"
          :key="row.node.path"
          role="treeitem"
          :aria-level="row.level"
          :aria-posinset="row.posinset"
          :aria-setsize="row.setsize"
          :aria-selected="row.node.path === selectedPath"
          :aria-expanded="row.expandable ? row.expanded : undefined"
          class="next-vbrowse__row"
          :class="[
            activePath === row.node.path ? 'is-active' : '',
            row.expandable ? 'is-container' : '',
          ]"
          :style="{ paddingInlineStart: `${0.25 + (row.level - 1) * 1.25}rem` }"
          @mouseenter="setActive(row.node.path)"
          @click="activate(row)"
        >
          <!-- Depth rails: one hairline per ANCESTOR level, the row's own parent strongest. -->
          <span v-if="row.level > 1" class="next-vbrowse__guides" aria-hidden="true">
            <span
              v-for="guide in row.level - 1"
              :key="guide"
              class="next-vbrowse__guide"
              :class="guide === row.level - 1 ? 'is-near' : ''"
            />
          </span>

          <!-- Chevron column: a real (non-selecting) toggle for a container — so a FILE
               composite, which is expandable AND selectable, can be opened without being
               picked — and a reserved spacer for a leaf, so every label aligns. It is
               `aria-hidden`: the ROW is the treeitem and already announces aria-expanded. -->
          <button
            v-if="row.expandable"
            type="button"
            tabindex="-1"
            aria-hidden="true"
            class="next-vbrowse__chevron"
            :class="row.expanded ? 'is-open' : ''"
            :title="t(row.expanded ? 'variableBrowser.collapse' : 'variableBrowser.expand', '', { name: row.node.label })"
            @click.stop="onChevronClick(row)"
          >
            <Icon name="chevron-right" aria-hidden="true" />
          </button>
          <span v-else class="next-vbrowse__chevron-spacer" aria-hidden="true" />

          <VariableTypeIcon
            :icon="nodeIcon(row.node)"
            :nullable="row.node.nullable"
            :array="row.node.array"
            class="shrink-0 text-next-muted-foreground"
          />
          <span class="min-w-0 flex-1 truncate">{{ rowLabel(row.node, row.parent) }}</span>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
.next-vbrowse {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

/* ONE scrollable list — the tree and the flat results share it, so switching between them
   never changes the panel's shape. */
.next-vbrowse__body {
  max-height: 20rem;
  overflow-y: auto;
  padding: var(--spacing-next-1);
}

.next-vbrowse__row {
  position: relative;
  display: flex;
  align-items: center;
  gap: var(--spacing-next-1);
  padding-block: var(--spacing-next-1_5);
  padding-inline: var(--spacing-next-2);
  border-radius: var(--radius-next-sm);
  font-size: var(--text-next-sm);
  color: var(--color-next-fg);
  cursor: pointer;
  user-select: none;
}
/* The row the cursor is on (mouse or keyboard) — the picker's accent, as before. */
.next-vbrowse__row.is-active {
  background-color: var(--color-next-accent);
  color: var(--color-next-accent-foreground);
}
/* A CONTAINER reads heavier than the leaves it holds (with the braces glyph + chevron). */
.next-vbrowse__row.is-container {
  font-weight: var(--font-weight-next-medium);
}
/* The already-chosen path, when the cursor is elsewhere: a quieter accent wash. */
.next-vbrowse__row[aria-selected='true']:not(.is-active) {
  background-color: color-mix(in srgb, var(--color-next-accent) 55%, transparent);
}

/* Depth rails: a hairline at each ANCESTOR level's chevron centre, so a child visibly
   belongs to the branch above it. The nearest rail (the row's own parent) is full
   strength; the outer ones fade back, which is what makes nesting depth countable. */
.next-vbrowse__guides {
  position: absolute;
  inset-block: 0;
  inset-inline-start: 0.875rem;
  display: flex;
  pointer-events: none;
}
.next-vbrowse__guide {
  display: block;
  width: 1.25rem;
  border-inline-start: 1px solid color-mix(in srgb, var(--color-next-border) 60%, transparent);
}
.next-vbrowse__guide.is-near {
  border-inline-start-color: var(--color-next-border);
}

.next-vbrowse__chevron,
.next-vbrowse__chevron-spacer {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  width: 1.25rem;
  height: 1.25rem;
}
.next-vbrowse__chevron {
  border-radius: var(--radius-next-sm);
  color: var(--color-next-muted-foreground);
  cursor: pointer;
  transition: transform var(--duration-next-fast) var(--ease-next-standard);
}
/* The open/closed cue, identical to the design system's Tree. */
.next-vbrowse__chevron.is-open {
  transform: rotate(90deg);
}
.next-vbrowse__row.is-active .next-vbrowse__chevron {
  color: inherit;
}
</style>
