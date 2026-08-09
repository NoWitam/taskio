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
import {
  useOverlayStack,
  type OverlayHandle,
} from '../../../app/composables/useOverlayStack';
import type { VariableNode } from '../../variables/types';

/**
 * ONE flat row of a LIST-shaped popup (the variable popup renders `nodes`, not rows).
 *
 * Shared by the MENTION (`@`) and WIKILINK (`[[`) triggers. The extra fields are optional and
 * variant-specific — a second row type would have forced a second store, a second popup contract
 * and a second copy of the keyboard model for what is the same listbox.
 */
export interface SuggestionRow {
  id: string;
  label: string;
  /** Mention avatar url. */
  avatar?: string | null;
  /** WIKILINK: the entry's slug — what actually gets inserted as `[[slug]]`. */
  slug?: string;
  /** WIKILINK: the entry's editorial status, rendered as a badge. */
  status?: string;
  /**
   * WIKILINK: `create` marks the trailing "Create entry «query»" affordance. Modelling it as a ROW
   * rather than as popup-only chrome keeps ↑/↓/Enter arithmetic over a single array — an off-list
   * extra row is how a keyboard model quietly grows an off-by-one.
   */
  kind?: 'entry' | 'create';
}

export interface SuggestionState {
  active: boolean;
  /** Which trigger opened the popup, drives row rendering + aria label. */
  variant: 'mention' | 'variable' | 'wikilink';
  /** Current text typed after the trigger. */
  query: string;
  /** Viewport-space caret rect the popup anchors to. */
  rect: DOMRect | null;
  /** MENTION variant: filtered/loaded rows. (The variable variant uses `nodes`.) */
  items: SuggestionRow[];
  loading: boolean;
  /**
   * The last fetch FAILED, as distinct from having returned nothing.
   *
   * Both used to leave `items` empty, so a broken search and a base with no such entry produced
   * the same "No matches" — and the user, told there is no such entry, writes a red link instead
   * of retrying. A separate flag is the only way the popup can tell the two apart.
   */
  errored: boolean;
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
    errored: false,
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

/** The open/close seam a trigger plugin uses to keep its popup in the shared overlay stack. */
export interface SuggestionOverlay {
  /** Join the stack (idempotent) — call when the popup becomes active. */
  acquire: () => void;
  /** Leave the stack (idempotent) — call from the plugin's `close()` and on destroy. */
  release: () => void;
}

/**
 * Register an ACTIVE caret popup with the shared overlay stack.
 *
 * WHY BOTH TRIGGER PLUGINS NEED THIS: they answer Escape from ProseMirror's `handleKeyDown`, which
 * is a BUBBLE-phase listener on the editor surface. The overlay stack listens on `document` in the
 * CAPTURE phase and `stopPropagation()`s Escape on behalf of the topmost overlay — so with the
 * editor inside a Modal/Drawer (where every markdown editor in Taskio lives) the first Escape closed
 * the MODAL and discarded the user's text while the suggestion list stayed open. Registering makes
 * the active popup the topmost overlay: Escape closes the list, a second one the modal. `Select`
 * was fixed the same way, and since it now registers too it is the most common overlay in the app,
 * which is what turned this ordering gap from theoretical into reachable.
 *
 * Not a Vue component, so there is no `onBeforeUnmount`: the plugin owns the lifetime and releases
 * from its own `close()` / `destroy()`.
 */
export function createSuggestionOverlay(close: () => void): SuggestionOverlay {
  let handle: OverlayHandle | null = null;
  return {
    acquire(): void {
      if (handle) return;
      handle = useOverlayStack({ kind: 'popover', close });
    },
    release(): void {
      handle?.release();
      handle = null;
    },
  };
}
