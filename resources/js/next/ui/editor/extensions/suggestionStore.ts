// suggestionStore — the reactive bridge between a trigger ProseMirror plugin
// (mention `@` OR variable `{`) and the Vue `SuggestionList.vue` popup, WITHOUT
// tippy.
//
// WHY no tippy: the legacy editor anchored its suggestion popup with tippy
// (`getReferenceClientRect` → caret coords). The "next" frontend forbids new deps
// and ships its own anchored-positioning primitive (`useAnchoredPosition` +
// Teleport). So the plugin publishes the caret rect + query + items into this
// reactive store; a single, always-mounted popup reads the store and positions
// itself against a synthetic anchor whose `getBoundingClientRect()` returns the
// caret rect. Same flip/clamp as FieldPopover, zero extra deps.
//
// The store is GENERIC over the row variant: mentions render avatar rows;
// variables render a type-icon + name row. The rows carry an optional `icon`
// (variable type) and `avatar` (mention) so one popup component serves both.
import { reactive } from 'vue';
import type { IconName } from '../../primitives/Icon.vue';

export interface SuggestionRow {
  id: string;
  label: string;
  /** Mention avatar url. */
  avatar?: string | null;
  /** Variable type icon name. */
  icon?: IconName;
}

export interface SuggestionState {
  active: boolean;
  /** Which trigger opened the popup, drives row rendering + aria label. */
  variant: 'mention' | 'variable';
  /** Current text typed after the trigger. */
  query: string;
  /** Viewport-space caret rect the popup anchors to. */
  rect: DOMRect | null;
  /** Filtered/loaded items. */
  items: SuggestionRow[];
  loading: boolean;
  /** Highlighted option index (for ↑/↓/Enter + aria-activedescendant). */
  activeIndex: number;
  /** Picks the highlighted item; wired by the plugin to insert the node. */
  onSelect: ((item: SuggestionRow) => void) | null;
  /** Closes the popup (Esc); wired by the plugin. */
  onClose: (() => void) | null;
  /** Monotonic id so each open is a fresh popup instance (resets state). */
  token: number;
}

export function createSuggestionStore(): SuggestionState {
  return reactive<SuggestionState>({
    active: false,
    variant: 'mention',
    query: '',
    rect: null,
    items: [],
    loading: false,
    activeIndex: 0,
    onSelect: null,
    onClose: null,
    token: 0,
  });
}

export type SuggestionStore = ReturnType<typeof createSuggestionStore>;
