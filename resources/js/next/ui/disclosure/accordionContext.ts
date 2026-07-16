// Shared context between Accordion and its AccordionItem children.
//
// The parent owns the open-state model + the registry of item headers (for
// roving keyboard navigation between headers). Items register on mount, query
// open state, toggle, and ask the parent to move focus to a sibling header.
import { inject, provide, type InjectionKey, type Ref } from 'vue';

export interface AccordionContext {
  /** Reactive single value or array of open item values. */
  isOpen: (value: string) => boolean;
  /** Toggle (or, in single mode, open exclusively) an item. */
  toggle: (value: string) => void;
  /** Register a header element for keyboard navigation; returns an unregister fn. */
  register: (value: string, el: Ref<HTMLElement | null>) => () => void;
  /** Move focus relative to the given item's header. */
  focusMove: (value: string, dir: 'next' | 'prev' | 'first' | 'last') => void;
}

export const ACCORDION_KEY: InjectionKey<AccordionContext> = Symbol('next-accordion');

export function provideAccordion(ctx: AccordionContext): void {
  provide(ACCORDION_KEY, ctx);
}

export function useAccordion(): AccordionContext | null {
  return inject(ACCORDION_KEY, null);
}
