<script setup lang="ts">
// VariableTreePicker — the structured field's Variable-mode picker, presented as an EXPANDABLE TREE
// (§refinement 5) instead of a flat qualified list. An object-shaped entry (a `file` composite, an
// `object` global, a form section) renders as an expandable NODE; expanding reveals its child fields;
// picking a leaf emits a ref at the composed `<parent>.<key>` path with the child's type (the SAME ref
// the flat list emitted — presentation only). A repeater stays a single, non-expandable list entry.
//
// The trigger renders THROUGH FieldShell (so it shares the value-or-variable box border exactly like
// the old Select did), and the panel is TELEPORTED + anchored (mirroring Select). Keyboard follows the
// WAI-ARIA tree pattern via virtual focus (aria-activedescendant on the tree): ↑/↓ move, → expand /
// into child, ← collapse / to parent, Home/End, Enter/Space pick-or-toggle, type-ahead, Esc closes.
// Type glyphs + nullable/array markers come from the shared VariableTypeIcon (§refinement 3).
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';
import Icon from '../../ui/primitives/Icon.vue';
import FieldShell from '../../ui/forms/FieldShell.vue';
import VariableTypeIcon from '../../ui/editor/extensions/VariableTypeIcon.vue';
import { useAnchoredPosition } from '../../app/composables/useAnchoredPosition';
import { useOutsideClick } from '../../app/composables/useOutsideClick';
import { useTheme } from '../../app/lib/theme';
import { useI18n } from '../../app/i18n';
import {
  flattenPickerNodes,
  variableNodeIcon,
  variablePickerTree,
  type VariablePickerNode,
} from './workflowVariables';
import type { CatalogVariable } from './types';

const props = withDefaults(
  defineProps<{
    /** The offered variables — already type-filtered by the host (flat CatalogVariable[]). */
    variables: CatalogVariable[];
    disabled?: boolean;
    /** aria-label + tree label (the field's own label context). */
    label?: string;
    /** Trigger placeholder. */
    placeholder?: string;
  }>(),
  { disabled: false },
);

const emit = defineEmits<{ pick: [CatalogVariable] }>();

const { t } = useI18n();
const { isDark } = useTheme();

const open = ref(false);
const expanded = ref<Set<string>>(new Set());
const activePath = ref<string | null>(null);

const rootRef = ref<HTMLElement | null>(null);
const panelRef = ref<HTMLElement | null>(null);
const treeRef = ref<HTMLElement | null>(null);
const triggerRef = ref<HTMLButtonElement | null>(null);

const baseId = `next-vtree-${Math.random().toString(36).slice(2, 8)}`;
const treeId = `${baseId}-tree`;

const tree = computed(() => variablePickerTree(props.variables));
const isEmpty = computed(() => tree.value.length === 0);
/** path → node, for scroll-into-view + parent lookup. */
const nodesByPath = computed(() => {
  const map = new Map<string, VariablePickerNode>();
  for (const node of flattenPickerNodes(tree.value)) map.set(node.variable.path, node);
  return map;
});

interface Row {
  node: VariablePickerNode;
  level: number;
  parentPath: string | null;
}

/** The VISIBLE rows (a node's children appear only while it is expanded). */
const flatVisible = computed<Row[]>(() => {
  const rows: Row[] = [];
  const walk = (list: VariablePickerNode[], level: number, parentPath: string | null): void => {
    for (const node of list) {
      rows.push({ node, level, parentPath });
      if (node.children?.length && expanded.value.has(node.variable.path)) {
        walk(node.children, level + 1, node.variable.path);
      }
    }
  };
  walk(tree.value, 1, null);
  return rows;
});

function rowId(path: string): string {
  // Sanitize the dotted path into a selector-safe id (querySelector needs no escaping then).
  return `${baseId}-row-${path.replace(/[^\w-]/g, '_')}`;
}
const activeRowId = computed(() =>
  activePath.value != null ? rowId(activePath.value) : undefined,
);

function hasChildren(node: VariablePickerNode): boolean {
  return !!node.children?.length;
}
function isExpanded(node: VariablePickerNode): boolean {
  return expanded.value.has(node.variable.path);
}

function setExpanded(path: string, isOpen: boolean): void {
  const set = new Set(expanded.value);
  if (isOpen) set.add(path);
  else set.delete(path);
  expanded.value = set;
}
function toggle(node: VariablePickerNode): void {
  if (!hasChildren(node)) return;
  setExpanded(node.variable.path, !isExpanded(node));
}

/** Pick a leaf (emit + close) or toggle a pure container (a section resolves to a map). */
function choose(node: VariablePickerNode): void {
  activePath.value = node.variable.path;
  if (node.selectable) {
    emit('pick', node.variable);
    closeList();
  } else {
    toggle(node);
  }
}

// --- Open / close ----------------------------------------------------------
const { style: panelPos, update: updatePos } = useAnchoredPosition(rootRef, panelRef, {
  placement: () => 'bottom-start',
  gap: 4,
  flip: true,
});
function reposition(): void {
  updatePos();
}

function openList(): void {
  if (props.disabled || open.value) return;
  open.value = true;
  // Seed the active row (first visible) + position, then move focus into the tree.
  activePath.value = flatVisible.value[0]?.node.variable.path ?? null;
  nextTick(() => {
    reposition();
    requestAnimationFrame(reposition);
    treeRef.value?.focus({ preventScroll: true });
    scrollActiveIntoView();
  });
  window.addEventListener('scroll', reposition, true);
  window.addEventListener('resize', reposition);
}
function closeList(returnFocus = true): void {
  if (!open.value) return;
  open.value = false;
  window.removeEventListener('scroll', reposition, true);
  window.removeEventListener('resize', reposition);
  if (returnFocus) nextTick(() => triggerRef.value?.focus({ preventScroll: true }));
}
function toggleOpen(): void {
  open.value ? closeList() : openList();
}
useOutsideClick([panelRef, rootRef], () => closeList(false), open);
onBeforeUnmount(() => {
  window.removeEventListener('scroll', reposition, true);
  window.removeEventListener('resize', reposition);
});

// --- Keyboard (virtual focus over flatVisible) -----------------------------
function activeIndex(): number {
  return flatVisible.value.findIndex((r) => r.node.variable.path === activePath.value);
}
function setActiveByIndex(index: number): void {
  const rows = flatVisible.value;
  if (rows.length === 0) return;
  const clamped = Math.max(0, Math.min(index, rows.length - 1));
  activePath.value = rows[clamped].node.variable.path;
  nextTick(scrollActiveIntoView);
}
function scrollActiveIntoView(): void {
  if (activePath.value == null) return;
  panelRef.value
    ?.querySelector<HTMLElement>(`#${rowId(activePath.value)}`)
    ?.scrollIntoView({ block: 'nearest' });
}

let typeBuffer = '';
let typeTimer: ReturnType<typeof setTimeout> | undefined;
function typeAhead(char: string): void {
  typeBuffer += char.toLowerCase();
  if (typeTimer) clearTimeout(typeTimer);
  typeTimer = setTimeout(() => (typeBuffer = ''), 600);
  const rows = flatVisible.value;
  const start = Math.max(0, activeIndex());
  for (let k = 1; k <= rows.length; k += 1) {
    const row = rows[(start + k) % rows.length];
    if (row.node.variable.name.toLowerCase().startsWith(typeBuffer)) {
      setActiveByIndex((start + k) % rows.length);
      return;
    }
  }
}

function onKeydown(event: KeyboardEvent): void {
  const rows = flatVisible.value;
  const i = activeIndex();
  const current = i >= 0 ? rows[i] : undefined;
  switch (event.key) {
    case 'ArrowDown':
      event.preventDefault();
      setActiveByIndex(i + 1);
      break;
    case 'ArrowUp':
      event.preventDefault();
      setActiveByIndex(i - 1);
      break;
    case 'Home':
      event.preventDefault();
      setActiveByIndex(0);
      break;
    case 'End':
      event.preventDefault();
      setActiveByIndex(rows.length - 1);
      break;
    case 'ArrowRight':
      event.preventDefault();
      if (current && hasChildren(current.node)) {
        if (!isExpanded(current.node)) setExpanded(current.node.variable.path, true);
        else setActiveByIndex(i + 1); // into the first child
      }
      break;
    case 'ArrowLeft':
      event.preventDefault();
      if (current && hasChildren(current.node) && isExpanded(current.node)) {
        setExpanded(current.node.variable.path, false);
      } else if (current?.parentPath != null) {
        setActiveByIndex(rows.findIndex((r) => r.node.variable.path === current.parentPath));
      }
      break;
    case 'Enter':
    case ' ':
      event.preventDefault();
      if (current) choose(current.node);
      break;
    case 'Escape':
      event.preventDefault();
      event.stopPropagation();
      closeList();
      break;
    case 'Tab':
      closeList(false);
      break;
    default:
      if (event.key.length === 1 && !event.metaKey && !event.ctrlKey && !event.altKey) {
        event.preventDefault();
        typeAhead(event.key);
      }
  }
}
</script>

<template>
  <div ref="rootRef" class="relative w-full">
    <FieldShell :disabled="disabled" :focused="open || undefined">
      <button
        :id="baseId"
        ref="triggerRef"
        type="button"
        role="combobox"
        class="flex h-full w-full min-w-0 flex-1 items-center gap-next-2 pl-next-3 pr-next-3 text-left outline-none disabled:cursor-not-allowed"
        :disabled="disabled"
        aria-haspopup="tree"
        :aria-expanded="open"
        :aria-controls="open ? treeId : undefined"
        :aria-label="label ?? t('workflows.field.pickVariable')"
        @click="toggleOpen"
        @keydown.down.prevent="openList"
        @keydown.enter.prevent="openList"
      >
        <span class="truncate text-next-muted-foreground">
          {{ placeholder ?? t('workflows.field.pickVariable') }}
        </span>
      </button>
      <template #trailing>
        <Icon
          name="chevron-down"
          class="shrink-0 text-next-muted-foreground transition-transform duration-[var(--duration-next-fast)]"
          :class="open ? 'rotate-180' : ''"
          aria-hidden="true"
        />
      </template>
    </FieldShell>

    <Teleport to="body">
      <Transition
        enter-active-class="transition-opacity duration-[var(--duration-next-fast)] ease-[var(--ease-next-emphasized)]"
        enter-from-class="opacity-0"
        leave-active-class="transition-opacity duration-[var(--duration-next-fast)] ease-[var(--ease-next-exit)]"
        leave-to-class="opacity-0"
      >
        <div v-if="open" class="next-root next-overlay-root" :class="isDark ? 'dark' : ''">
          <div
            ref="panelRef"
            class="fixed z-[var(--z-next-popover)] max-h-[24rem] min-w-[var(--w)] overflow-y-auto rounded-next-md border border-next-border bg-next-popover p-next-1 text-next-popover-foreground shadow-next-lg"
            :style="{
              top: `${panelPos.top}px`,
              left: `${panelPos.left}px`,
              '--w': `${rootRef?.offsetWidth ?? 0}px`,
              maxWidth: 'calc(100vw - 1rem)',
            }"
          >
            <!-- Empty state -->
            <p v-if="isEmpty" class="px-next-3 py-next-3 text-next-sm text-next-muted-foreground">
              {{ t('workflows.field.noVariables') }}
            </p>

            <!-- The tree (virtual focus via aria-activedescendant). -->
            <div
              v-else
              :id="treeId"
              ref="treeRef"
              role="tree"
              tabindex="0"
              class="flex flex-col outline-none"
              :aria-label="label ?? t('workflows.field.variableTreeLabel')"
              :aria-activedescendant="activeRowId"
              @keydown="onKeydown"
            >
              <div
                v-for="row in flatVisible"
                :id="rowId(row.node.variable.path)"
                :key="row.node.variable.path"
                role="treeitem"
                :aria-level="row.level"
                :aria-expanded="hasChildren(row.node) ? isExpanded(row.node) : undefined"
                :aria-selected="activePath === row.node.variable.path"
                class="flex cursor-pointer select-none items-center gap-next-1 rounded-next-sm py-next-1_5 pr-next-2 text-next-sm"
                :class="
                  activePath === row.node.variable.path
                    ? 'bg-next-accent text-next-accent-foreground'
                    : 'text-next-fg hover:bg-next-accent hover:text-next-accent-foreground'
                "
                :style="{ paddingInlineStart: `${(row.level - 1) * 1 + 0.25}rem` }"
                @mouseenter="activePath = row.node.variable.path"
                @click="choose(row.node)"
              >
                <!-- Expand/collapse chevron (reserved width even for leaves so labels align). -->
                <button
                  v-if="hasChildren(row.node)"
                  type="button"
                  tabindex="-1"
                  class="flex h-5 w-5 shrink-0 items-center justify-center rounded-next-sm text-next-muted-foreground transition-transform duration-[var(--duration-next-fast)]"
                  :class="isExpanded(row.node) ? 'rotate-90' : ''"
                  :aria-label="t('workflows.field.toggleGroup', '', { name: row.node.variable.name })"
                  @click.stop="toggle(row.node)"
                >
                  <Icon name="chevron-right" />
                </button>
                <span v-else class="h-5 w-5 shrink-0" aria-hidden="true" />

                <VariableTypeIcon
                  :icon="variableNodeIcon(row.node.variable)"
                  :nullable="row.node.variable.descriptor?.nullable"
                  :array="row.node.variable.descriptor?.array"
                  class="shrink-0 text-next-muted-foreground"
                />
                <span class="min-w-0 flex-1 truncate">{{ row.node.variable.name }}</span>
              </div>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>
