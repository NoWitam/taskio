// Shared state model + sizing for the FieldShell — the bordered "box" that every
// text-like "next" control renders inside (TextInput, NumberInput, the Slider's
// inline number, Select trigger, …). Keeping the state resolution + size scale in
// one place guarantees the whole control family draws an identical border and an
// identical "state line" (the colored line ON the border).
import {
  computed,
  inject,
  provide,
  type ComputedRef,
  type InjectionKey,
  type Ref,
} from 'vue';

export type ControlSize = 'sm' | 'md' | 'lg';

/**
 * Ambient control size for a region of the UI. A container (e.g. FilterBar) can
 * `provideControlSize('md')` so every form control rendered in its slots inherits
 * that size WITHOUT each consumer threading a `size` prop. An explicit `size` prop
 * on a control still wins; the ambient value is only the fallback. This is what
 * makes "all controls in a FilterBar are `md`" a structural rule, not a per-view
 * convention every screen must remember.
 */
export const ControlSizeKey: InjectionKey<Ref<ControlSize> | ComputedRef<ControlSize>> =
  Symbol('next-control-size');

/** Provide an ambient control size to descendants (reactive). */
export function provideControlSize(size: Ref<ControlSize> | ComputedRef<ControlSize>): void {
  provide(ControlSizeKey, size);
}

/**
 * Resolve a control's effective size: the explicit prop wins, else the ambient
 * provided size (FilterBar, …), else the family default `md`. Pass a getter for
 * the prop so the result stays reactive to prop changes.
 */
export function useControlSize(explicit: () => ControlSize | undefined): ComputedRef<ControlSize> {
  const ambient = inject(ControlSizeKey, null);
  return computed(() => explicit() ?? ambient?.value ?? 'md');
}

/**
 * The visual states a field can express, in PRECEDENCE order (first match wins):
 *
 *   disabled > error > success > focus > dirty > default
 *
 * `readonly` is orthogonal to the colored states — it only changes the surface
 * fill/cursor; it never overrides error/success/dirty signalling (a readonly
 * field can still be flagged invalid). It is applied alongside the resolved
 * colored state below.
 *
 * Notes on the rationale:
 *  - `disabled` wins because an inert control must look inert regardless of value.
 *  - validation results (`error`/`success`) outrank `focus`, because the *result*
 *    is more important to communicate than "this is focused" — error+focus share
 *    the SAME line shape, only the color differs, so focusing an invalid field
 *    keeps the danger color (no jarring flip to primary).
 *  - `focus` outranks `dirty`: while you're editing, show the focus line; the
 *    subtler "dirty" accent is what remains once you blur a changed-but-unvalidated
 *    field.
 *  - `dirty` means "value changed from its initial value but not yet validated".
 */
export type FieldState =
  | 'default'
  | 'focus'
  | 'dirty'
  | 'error'
  | 'success'
  | 'disabled';

export interface FieldStateInputs {
  disabled: boolean;
  /** Field is invalid (FormField error set, or control out-of-range, …). */
  error: boolean;
  /** Field passed validation (caller opts in). */
  success: boolean;
  /** Field currently has focus within. */
  focused: boolean;
  /** Value differs from its initial value, not yet validated. */
  dirty: boolean;
}

/** Resolve the single colored state to render, honoring the precedence above. */
export function resolveFieldState(inputs: FieldStateInputs): FieldState {
  if (inputs.disabled) return 'disabled';
  if (inputs.error) return 'error';
  if (inputs.success) return 'success';
  if (inputs.focused) return 'focus';
  if (inputs.dirty) return 'dirty';
  return 'default';
}

/**
 * Outer control height per size. Fixed — content NEVER changes the height (the
 * Textarea is the one exception and renders its own surface). Text scale travels
 * with the size so the whole family stays consistent.
 */
export const FIELD_SIZE: Record<ControlSize, string> = {
  sm: 'h-8 text-next-sm',
  md: 'h-10 text-next-sm',
  lg: 'h-12 text-next-base',
};

/** Horizontal inset for the text region per size token. */
export const FIELD_PADDING_X: Record<ControlSize, string> = {
  sm: 'px-next-2_5',
  md: 'px-next-3',
  lg: 'px-next-4',
};
