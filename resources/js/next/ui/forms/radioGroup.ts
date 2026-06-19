// Shared context between RadioGroup and its Radio children.
//
// RadioGroup owns the selected value, disabled/invalid state, the registration
// order (for roving tabindex + arrow navigation), and the keyboard handler.
// Radio injects this so the group can coordinate which radio is tab-focusable
// and respond to arrow keys.
import { inject, provide, type ComputedRef, type InjectionKey, type Ref } from 'vue';

export interface RadioGroupContext {
  name: string;
  value: Ref<string | null>;
  disabled: ComputedRef<boolean>;
  invalid: ComputedRef<boolean>;
  describedById: ComputedRef<string | undefined>;
  /** Register a radio value; returns an unregister fn. Order = DOM order. */
  register: (value: string) => () => void;
  /** Select a value (no-op when disabled). */
  select: (value: string) => void;
  /** Whether `value` is the roving-tabindex-focusable radio. */
  isTabbable: (value: string) => boolean;
  /** Keydown handler bound on each radio for arrow/Home/End navigation. */
  onKeydown: (event: KeyboardEvent, value: string) => void;
}

export const RadioGroupKey: InjectionKey<RadioGroupContext> =
  Symbol('next-radio-group');

export function provideRadioGroup(ctx: RadioGroupContext): void {
  provide(RadioGroupKey, ctx);
}

export function useRadioGroup(): RadioGroupContext | undefined {
  return inject(RadioGroupKey, undefined);
}
