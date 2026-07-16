// Shared context for the Timeline family (next frontend).
//
// The Timeline root provides its density so TimelineItem children can size
// themselves consistently without the consumer passing `compact` to each item.
import type { ComputedRef, InjectionKey } from 'vue';

export interface TimelineContext {
  /** Dense layout (smaller nodes / tighter spacing). */
  compact: ComputedRef<boolean>;
}

export const TIMELINE_KEY: InjectionKey<TimelineContext> = Symbol('next-timeline');
