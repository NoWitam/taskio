<script setup lang="ts">
// Tree — a hierarchical tree view for the "next" frontend (WAI-ARIA tree pattern).
//
// Props: `nodes` (TreeNode[]). Expansion is `v-model:expanded` (an id array;
// uncontrolled if unbound). Selection is opt-in via `selectable` ('single' |
// 'multiple') + `v-model:selected` (an id array). Lazy children: a node with
// `loadable: true` (and no `children`) triggers `loadChildren(node)` on first
// expand; a per-node spinner shows while it resolves and the returned children
// are merged in.
//
// This component owns ALL state + keyboard logic; TreeItem is a thin recursive
// renderer reading the provided context. Keyboard (per WAI-ARIA tree):
//   ↑ / ↓        move to the previous / next VISIBLE node
//   → on closed  expand; on open node move to first child; on leaf no-op
//   ← on open    collapse; otherwise move to parent
//   Home / End   first / last visible node
//   Enter/Space  select (when selectable) else toggle
//   type-ahead   jump to the next visible node whose label starts with the typed
//                characters
//
// A11y: `role="tree"` (+ `aria-multiselectable` when multiple); each row is a
// `role="treeitem"` with `aria-level`/`aria-expanded`/`aria-selected`; one row
// owns the roving `tabindex=0`. Color is never the only signal (selection adds a
// subtle surface + token text color; the chevron rotates to show open/closed).
import { computed, provide, reactive, ref, watch } from 'vue';
import TreeItem from './TreeItem.vue';
import { TREE_CONTEXT, type TreeNode, type TreeSelectionMode } from './tree';

const props = withDefaults(
  defineProps<{
    nodes: TreeNode[];
    /** Enable selection: 'single' or 'multiple'. Omit for a navigation-only tree. */
    selectable?: TreeSelectionMode | null;
    size?: 'sm' | 'md';
    /** Draw connector / indent guide lines. */
    guides?: boolean;
    /** Async loader for a `loadable` node's children. */
    loadChildren?: (node: TreeNode) => Promise<TreeNode[]>;
    /** Accessible label for the tree (recommended). */
    ariaLabel?: string;
  }>(),
  {
    selectable: null,
    size: 'md',
    guides: true,
  },
);

const emit = defineEmits<{
  (e: 'select', node: TreeNode): void;
  (e: 'expand', node: TreeNode): void;
  (e: 'collapse', node: TreeNode): void;
}>();

// --- v-model: expanded + selected -------------------------------------------
const expanded = defineModel<string[]>('expanded', { default: () => [] });
const selected = defineModel<string[]>('selected', { default: () => [] });

const expandedSet = computed(() => new Set(expanded.value));
const selectedSet = computed(() => new Set(selected.value));

function setExpanded(id: string, open: boolean): void {
  const set = new Set(expanded.value);
  if (open) set.add(id);
  else set.delete(id);
  expanded.value = [...set];
}

// --- Lazy load tracking -----------------------------------------------------
// Children resolved at runtime are stored here and merged over the node's static
// children so the original `nodes` prop is never mutated.
const loadedChildren = reactive<Record<string, TreeNode[]>>({});
const loadingIds = reactive<Set<string>>(new Set());
const loadedIds = reactive<Set<string>>(new Set());

function resolveChildren(node: TreeNode): TreeNode[] {
  if (node.children && node.children.length > 0) return node.children;
  return loadedChildren[node.id] ?? [];
}

function hasChildren(node: TreeNode): boolean {
  if (node.children && node.children.length > 0) return true;
  if ((loadedChildren[node.id]?.length ?? 0) > 0) return true;
  // A loadable node that hasn't loaded yet is still expandable.
  return !!node.loadable && !loadedIds.has(node.id);
}

async function maybeLoad(node: TreeNode): Promise<void> {
  if (
    !node.loadable ||
    loadedIds.has(node.id) ||
    loadingIds.has(node.id) ||
    !props.loadChildren
  ) {
    return;
  }
  loadingIds.add(node.id);
  try {
    const kids = await props.loadChildren(node);
    loadedChildren[node.id] = kids;
    loadedIds.add(node.id);
  } finally {
    loadingIds.delete(node.id);
  }
}

function toggle(node: TreeNode): void {
  if (node.disabled || !hasChildren(node)) return;
  const open = expandedSet.value.has(node.id);
  if (open) {
    setExpanded(node.id, false);
    emit('collapse', node);
  } else {
    setExpanded(node.id, true);
    emit('expand', node);
    void maybeLoad(node);
  }
}

function expand(node: TreeNode): void {
  if (node.disabled || !hasChildren(node) || expandedSet.value.has(node.id)) return;
  setExpanded(node.id, true);
  emit('expand', node);
  void maybeLoad(node);
}

function collapse(node: TreeNode): void {
  if (!expandedSet.value.has(node.id)) return;
  setExpanded(node.id, false);
  emit('collapse', node);
}

// --- Selection --------------------------------------------------------------
function select(node: TreeNode): void {
  if (node.disabled || !props.selectable) return;
  if (props.selectable === 'multiple') {
    const set = new Set(selected.value);
    if (set.has(node.id)) set.delete(node.id);
    else set.add(node.id);
    selected.value = [...set];
  } else {
    selected.value = [node.id];
  }
  emit('select', node);
}

// --- Flat visible list (for ↑/↓/Home/End + type-ahead) ----------------------
interface FlatRow {
  node: TreeNode;
  level: number;
  parentId: string | null;
}

const flatVisible = computed<FlatRow[]>(() => {
  const rows: FlatRow[] = [];
  const walk = (list: TreeNode[], level: number, parentId: string | null): void => {
    for (const node of list) {
      rows.push({ node, level, parentId });
      if (hasChildren(node) && expandedSet.value.has(node.id)) {
        walk(resolveChildren(node), level + 1, node.id);
      }
    }
  };
  walk(props.nodes, 1, null);
  return rows;
});

const firstEnabledId = computed<string | null>(
  () => flatVisible.value.find((r) => !r.node.disabled)?.node.id ?? null,
);

// --- Roving focus -----------------------------------------------------------
const focusedId = ref<string | null>(null);
const elements = reactive<Record<string, HTMLElement | null>>({});

function registerEl(id: string, el: HTMLElement | null): void {
  if (el) elements[id] = el;
  else delete elements[id];
}

// Keep a valid focus target: default to the first enabled node; if the focused
// node disappears (collapsed away), fall back to the first visible row.
watch(
  [flatVisible, firstEnabledId],
  () => {
    if (focusedId.value && flatVisible.value.some((r) => r.node.id === focusedId.value)) {
      return;
    }
    focusedId.value = firstEnabledId.value;
  },
  { immediate: true },
);

function setFocus(id: string): void {
  focusedId.value = id;
}

function focusRow(id: string | null): void {
  if (id == null) return;
  focusedId.value = id;
  // Defer so the new roving tabindex has applied.
  requestAnimationFrame(() => {
    elements[id]?.focus();
    elements[id]?.scrollIntoView({ block: 'nearest' });
  });
}

function visibleIndex(id: string | null): number {
  if (id == null) return -1;
  return flatVisible.value.findIndex((r) => r.node.id === id);
}

function moveBy(delta: 1 | -1): void {
  const rows = flatVisible.value;
  if (rows.length === 0) return;
  let i = visibleIndex(focusedId.value);
  if (i === -1) i = delta === 1 ? -1 : rows.length;
  // Skip disabled rows.
  for (let step = 0; step < rows.length; step++) {
    i += delta;
    if (i < 0 || i >= rows.length) return;
    if (!rows[i].node.disabled) {
      focusRow(rows[i].node.id);
      return;
    }
  }
}

function moveToEdge(edge: 'first' | 'last'): void {
  const rows = flatVisible.value.filter((r) => !r.node.disabled);
  if (rows.length === 0) return;
  focusRow(edge === 'first' ? rows[0].node.id : rows[rows.length - 1].node.id);
}

// --- Type-ahead -------------------------------------------------------------
let typeBuffer = '';
let typeTimer: ReturnType<typeof setTimeout> | null = null;

function typeAhead(char: string): void {
  typeBuffer += char.toLowerCase();
  if (typeTimer) clearTimeout(typeTimer);
  typeTimer = setTimeout(() => {
    typeBuffer = '';
  }, 600);

  const rows = flatVisible.value.filter((r) => !r.node.disabled);
  if (rows.length === 0) return;
  const startIdx = Math.max(0, rows.findIndex((r) => r.node.id === focusedId.value));
  // Search after the current row, wrapping around.
  for (let k = 1; k <= rows.length; k++) {
    const row = rows[(startIdx + k) % rows.length];
    if (row.node.label.toLowerCase().startsWith(typeBuffer)) {
      focusRow(row.node.id);
      return;
    }
  }
}

// --- Keyboard delegation ----------------------------------------------------
function onItemKeydown(event: KeyboardEvent, node: TreeNode, _level: number): void {
  switch (event.key) {
    case 'ArrowDown':
      event.preventDefault();
      moveBy(1);
      break;
    case 'ArrowUp':
      event.preventDefault();
      moveBy(-1);
      break;
    case 'ArrowRight': {
      event.preventDefault();
      if (node.disabled) break;
      if (hasChildren(node)) {
        if (!expandedSet.value.has(node.id)) {
          expand(node);
        } else {
          // Move to first child.
          const kids = resolveChildren(node).filter((c) => !c.disabled);
          if (kids[0]) focusRow(kids[0].id);
        }
      }
      break;
    }
    case 'ArrowLeft': {
      event.preventDefault();
      if (hasChildren(node) && expandedSet.value.has(node.id)) {
        collapse(node);
      } else {
        // Move to parent.
        const row = flatVisible.value.find((r) => r.node.id === node.id);
        if (row?.parentId) focusRow(row.parentId);
      }
      break;
    }
    case 'Home':
      event.preventDefault();
      moveToEdge('first');
      break;
    case 'End':
      event.preventDefault();
      moveToEdge('last');
      break;
    case 'Enter':
    case ' ':
      event.preventDefault();
      if (node.disabled) break;
      if (props.selectable) select(node);
      else if (hasChildren(node)) toggle(node);
      break;
    default:
      // Type-ahead for printable single characters (no modifiers).
      if (
        event.key.length === 1 &&
        !event.ctrlKey &&
        !event.metaKey &&
        !event.altKey
      ) {
        typeAhead(event.key);
      }
  }
}

provide(TREE_CONTEXT, {
  selectable: props.selectable,
  isExpanded: (id) => expandedSet.value.has(id),
  isSelected: (id) => selectedSet.value.has(id),
  isFocused: (id) => focusedId.value === id,
  isLoading: (id) => loadingIds.has(id),
  hasChildren,
  toggle,
  select,
  setFocus,
  onItemKeydown,
  registerEl,
  size: props.size,
  guides: props.guides,
});

const multiselectable = computed(() => props.selectable === 'multiple');
</script>

<template>
  <ul
    role="tree"
    :aria-label="ariaLabel"
    :aria-multiselectable="multiselectable ? 'true' : undefined"
    class="next-tree min-w-0 select-none"
  >
    <TreeItem
      v-for="node in nodes"
      :key="node.id"
      :node="node"
      :level="1"
    />
  </ul>
</template>
