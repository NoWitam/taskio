// Shared context for the DropdownMenu family (next frontend).
//
// The menu root provides an injection that items use to register themselves for
// roving keyboard navigation, type-ahead, and activation/close behavior. Kept in
// a plain .ts module so both the root and item components can import the symbol
// and types.
import type { InjectionKey } from 'vue';

export interface DropdownMenuContext {
  /**
   * Register a menuitem and receive its assigned index (stable while mounted).
   * `getLabel` is used for type-ahead; `isDisabled` excludes it from roving.
   */
  register: (item: {
    el: () => HTMLElement | null;
    getLabel: () => string;
    isDisabled: () => boolean;
  }) => number;
  unregister: (index: number) => void;
  /** Stable DOM id for the item at `index` (used for aria-activedescendant). */
  itemId: (index: number) => string;
  /** The currently active (virtually focused) item index. */
  activeIndex: () => number;
  /** Set the active item (e.g. on mouseenter). */
  setActive: (index: number) => void;
  /** Activate an item (run its action) then close the menu. */
  activate: (index: number) => void;
  /** Close the menu and restore focus to the trigger. */
  close: () => void;
}

export const DROPDOWN_MENU_KEY: InjectionKey<DropdownMenuContext> =
  Symbol('next-dropdown-menu');
