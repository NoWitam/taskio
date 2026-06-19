<script setup lang="ts">
// TreeItem — a single node row in the Tree, recursive over its children.
//
// A thin renderer: it reads the shared TreeContext (provided by Tree.vue) for
// state + behavior and renders one `role="treeitem"` row plus, when expanded, a
// nested `role="group"` of child TreeItems. All state and keyboard logic live in
// Tree.vue; this component only renders + forwards events.
//
// A11y: `role="treeitem"` with `aria-level`, `aria-expanded` (only when it has
// children), `aria-selected` (when selectable), and `aria-disabled`. Roving
// tabindex: exactly one treeitem in the whole tree is `tabindex=0`. The chevron,
// icon, connector guides, and badge are decorative; the label carries meaning.
import { computed, inject, onBeforeUnmount, ref, watch } from 'vue';
import Icon from '../primitives/Icon.vue';
import Badge from '../primitives/Badge.vue';
import Spinner from '../primitives/Spinner.vue';
import { TREE_CONTEXT, type TreeNode } from './tree';

const props = defineProps<{
  node: TreeNode;
  /** 1-based depth (root nodes are level 1) for `aria-level` + indentation. */
  level: number;
}>();

const ctx = inject(TREE_CONTEXT);
if (!ctx) {
  throw new Error('[next/TreeItem] must be used inside a <Tree>.');
}

const rowRef = ref<HTMLElement | null>(null);

watch(
  rowRef,
  (el) => ctx.registerEl(props.node.id, el),
  { immediate: true },
);
onBeforeUnmount(() => ctx.registerEl(props.node.id, null));

const expandable = computed(() => ctx.hasChildren(props.node));
const expanded = computed(() => ctx.isExpanded(props.node.id));
const selected = computed(() => ctx.isSelected(props.node.id));
const focused = computed(() => ctx.isFocused(props.node.id));
const loading = computed(() => ctx.isLoading(props.node.id));
const disabled = computed(() => !!props.node.disabled);

const children = computed(() => props.node.children ?? []);

const SIZE_ROW: Record<'sm' | 'md', string> = {
  sm: 'h-7 text-next-xs gap-next-1',
  md: 'h-9 text-next-sm gap-next-1_5',
};
// Indentation per level (the chevron column lives inside this inset).
const indent = computed(() => `${(props.level - 1) * 1.25}rem`);

function onRowClick(): void {
  if (disabled.value) return;
  ctx.setFocus(props.node.id);
  if (ctx.selectable) ctx.select(props.node);
  else if (expandable.value) ctx.toggle(props.node);
}

function onChevronClick(event: MouseEvent): void {
  event.stopPropagation();
  if (disabled.value) return;
  ctx.setFocus(props.node.id);
  ctx.toggle(props.node);
}

function onKeydown(event: KeyboardEvent): void {
  ctx.onItemKeydown(event, props.node, props.level);
}
</script>

<template>
  <li role="none" class="next-tree-item">
    <div
      ref="rowRef"
      role="treeitem"
      :aria-level="level"
      :aria-expanded="expandable ? expanded : undefined"
      :aria-selected="ctx.selectable ? selected : undefined"
      :aria-disabled="disabled ? 'true' : undefined"
      :tabindex="focused ? 0 : -1"
      class="next-tree-item__row group relative flex cursor-pointer select-none items-center rounded-next-md pr-next-2 outline-none transition-colors duration-[var(--duration-next-fast)] focus-visible:ring-2 focus-visible:ring-next-ring"
      :class="[
        SIZE_ROW[ctx.size],
        disabled ? 'cursor-not-allowed opacity-50' : '',
        selected
          ? 'bg-next-primary-subtle text-next-primary-subtle-foreground'
          : 'text-next-fg hover:bg-next-accent hover:text-next-accent-foreground',
      ]"
      :style="{ paddingInlineStart: indent }"
      @click="onRowClick"
      @keydown="onKeydown"
    >
      <!-- Connector guide (decorative): a vertical hairline per ancestor level. -->
      <span
        v-if="ctx.guides && level > 1"
        class="pointer-events-none absolute inset-y-0 left-0 flex"
        aria-hidden="true"
      >
        <span
          v-for="g in level - 1"
          :key="g"
          class="block border-l border-next-border/70"
          style="width: 1.25rem"
        />
      </span>

      <!-- Chevron toggle (or spinner while lazy-loading). Reserved width even
           for leaves so labels align across siblings. -->
      <span class="relative z-[1] flex h-full w-5 shrink-0 items-center justify-center">
        <Spinner v-if="loading" size="xs" tone="muted" decorative />
        <button
          v-else-if="expandable"
          type="button"
          tabindex="-1"
          class="flex h-5 w-5 items-center justify-center rounded-next-sm text-next-muted-foreground transition-transform duration-[var(--duration-next-fast)] hover:text-next-fg"
          :class="expanded ? 'rotate-90' : ''"
          aria-hidden="true"
          @click="onChevronClick"
        >
          <Icon name="chevron-right" />
        </button>
      </span>

      <!-- Optional leading icon (decorative). -->
      <Icon
        v-if="node.icon"
        :name="node.icon"
        class="relative z-[1] shrink-0 text-next-muted-foreground"
        :class="selected ? 'text-current' : ''"
      />

      <span class="relative z-[1] min-w-0 flex-1 truncate">{{ node.label }}</span>

      <Badge
        v-if="node.badge !== undefined"
        class="relative z-[1] shrink-0"
        :variant="selected ? 'primary' : 'neutral'"
        tone="subtle"
        size="sm"
      >
        {{ node.badge }}
      </Badge>
    </div>

    <!-- Children group (only when expanded). -->
    <ul
      v-if="expandable && expanded && children.length > 0"
      role="group"
      class="next-tree-item__group"
    >
      <TreeItem
        v-for="child in children"
        :key="child.id"
        :node="child"
        :level="level + 1"
      />
    </ul>
  </li>
</template>
