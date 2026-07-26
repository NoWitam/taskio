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
// The store is GENERIC over the row variant, and the two variants render through
// DIFFERENT popups (B4):
//   • MENTION  → `MentionSuggest` over the flat `items` rows (avatar + label).
//   • VARIABLE → `VariableSuggest` over the shared `VariableBrowser`, which browses the
//     `nodes` TREE instead of a flat list (type glyphs with `?`/`[]` markers, expandable
//     containers, its own search). `items` is unused there; `nodes` / `onKey` /
//     `onSelectNode` below are its channel.
//
// VIRTUAL FOCUS is the invariant both variants share: ProseMirror keeps DOM focus and the
// plugin FORWARDS keys into the popup, so the caret never leaves the text. For the variable
// variant that forwarding goes through `onKey`, which the popup wires to the browser's one
// keyboard model.
import { reactive } from 'vue';
import type { VariableNode } from '../../variables/types';

/** ONE flat row of the MENTION popup (the variable popup renders `nodes`, not rows). */
export interface SuggestionRow {
  id: string;
  label: string;
  /** Mention avatar url. */
  avatar?: string | null;
}

export interface SuggestionState {
  active: boolean;
  /** Which trigger opened the popup, drives row rendering + aria label. */
  variant: 'mention' | 'variable';
  /** Current text typed after the trigger. */
  query: string;
  /** Viewport-space caret rect the popup anchors to. */
  rect: DOMRect | null;
  /** MENTION variant: filtered/loaded rows. (The variable variant uses `nodes`.) */
  items: SuggestionRow[];
  loading: boolean;
  /** Highlighted option index (for ↑/↓/Enter + aria-activedescendant). */
  activeIndex: number;
  /**
   * VARIABLE variant: the browsable TREE (already built + policy-applied by the plugin from the
   * host's live feed). A container is EXPAND-ONLY here exactly as in every other picker, which is
   * what makes it safe to offer sections / object globals in the `{` list at all.
   */
  nodes: VariableNode[];
  /**
   * VARIABLE variant: the popup registers the browser's keyboard model here and the PLUGIN calls
   * it for the keys it forwards (↑/↓/Enter/Esc always; ←/→ only while the query is empty, so a
   * typed query keeps the caret's own left/right movement). Returns true when the key was
   * consumed. DOM focus stays in ProseMirror the whole time.
   */
  onKey: ((event: KeyboardEvent) => boolean) | null;
  /** VARIABLE variant: a picked (always SELECTABLE) tree node; wired by the plugin to insert. */
  onSelectNode: ((node: VariableNode) => void) | null;
  /** Picks the highlighted item; wired by the plugin to insert the node. */
  onSelect: ((item: SuggestionRow) => void) | null;
  /** Closes the popup (Esc); wired by the plugin. */
  onClose: (() => void) | null;
  /**
   * Re-measures the caret rect from ProseMirror and either updates `rect` (so the
   * popup FOLLOWS the caret on scroll) or CLOSES the popup when the caret scrolled
   * out of view (SF3.1). Wired by the plugin; the popup calls it on scroll/resize.
   */
  reposition: (() => void) | null;
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
    nodes: [],
    onKey: null,
    onSelectNode: null,
    onSelect: null,
    onClose: null,
    reposition: null,
    token: 0,
  });
}

export type SuggestionStore = ReturnType<typeof createSuggestionStore>;
