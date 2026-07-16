// Shared types + provide/inject context for the Tree component family.
//
// Tree.vue owns all state (expansion, selection, focus, lazy-load status) and
// the keyboard navigation. TreeItem.vue is a thin, recursive renderer that reads
// this context and calls back into the controller — so there is ONE source of
// truth and no duplicated logic across nesting levels.
import type { InjectionKey } from 'vue';
import type { IconName } from '../primitives/Icon.vue';

export interface TreeNode {
  /** Stable, unique id across the whole tree. */
  id: string;
  label: string;
  icon?: IconName;
  /** Child nodes. Omit + provide `loadChildren` for lazy expansion. */
  children?: TreeNode[];
  disabled?: boolean;
  /** Optional trailing count/label badge. */
  badge?: string | number;
  /**
   * Marks a lazily-loadable node that has not loaded yet. When true (and no
   * `children` are present), expanding triggers `loadChildren(node)`.
   */
  loadable?: boolean;
}

export type TreeSelectionMode = 'single' | 'multiple';

export interface TreeContext {
  /** Selection mode, or null when selection is disabled. */
  selectable: TreeSelectionMode | null;
  /** Reactive expanded-id set membership test. */
  isExpanded: (id: string) => boolean;
  /** Reactive selected-id set membership test. */
  isSelected: (id: string) => boolean;
  /** The currently focusable (roving tabindex) node id. */
  isFocused: (id: string) => boolean;
  /** Per-node loading flag (lazy children in flight). */
  isLoading: (id: string) => boolean;
  /** Whether a node can be expanded (has children or is loadable). */
  hasChildren: (node: TreeNode) => boolean;
  /** Toggle / open / close expansion (handles lazy load). */
  toggle: (node: TreeNode) => void;
  /** Activate (select) a node. */
  select: (node: TreeNode) => void;
  /** Set the roving-focus target (on focus / click). */
  setFocus: (id: string) => void;
  /** Keyboard handler delegated up from each treeitem. */
  onItemKeydown: (event: KeyboardEvent, node: TreeNode, level: number) => void;
  /** Register / unregister a node's element for focus management. */
  registerEl: (id: string, el: HTMLElement | null) => void;
  /** Size scale forwarded to every row. */
  size: 'sm' | 'md';
  /** Whether to draw connector/indent guide lines. */
  guides: boolean;
}

export const TREE_CONTEXT: InjectionKey<TreeContext> = Symbol('next-tree');
