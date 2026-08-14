// Shared form-field wiring for the "next" form controls.
//
// `FormField.vue` provides a small context (control id, the id of the
// description/error text to wire via `aria-describedby`, the resolved validation
// state, dirty tracking, and disabled/required/readonly flags). Controls
// (TextInput, NumberInput, Select, …) inject it so they can be fully labelled +
// described AND so their FieldShell can draw the right state line without the
// caller threading anything by hand. Every control ALSO accepts its own
// `id` / `ariaInvalid` / `describedById` props so it works standalone outside a
// FormField.
//
// ─────────────────────────────────────────────────────────────────────────────
// `ariaInvalid` MUST default to `undefined` in every control.
// ─────────────────────────────────────────────────────────────────────────────
// Controls resolve their error state as
//
//     const invalid = computed(() => props.ariaInvalid ?? field?.invalid.value ?? false);
//
// i.e. "an explicit prop wins; absence hands the verdict to the FormField". That
// only holds while ABSENCE IS `undefined`. A `boolean`-typed prop with no declared
// default gets Vue's boolean casting — absent becomes `false` — and `false ?? x`
// is `false`, so the `field` branch becomes unreachable and a FormField carrying
// an error hands its control nothing: no `aria-invalid` for assistive technology
// and no error line on the FieldShell. That was a live, app-wide defect.
//
// Therefore every control (and every wrapper that FORWARDS the prop, e.g.
// UserSelect → Select) declares `ariaInvalid: undefined` in `withDefaults`, which
// opts out of the cast while leaving the bare-attribute shorthand
// (`<TextInput aria-invalid />` → `true`) intact. Pinned by
// `__tests__/ariaInvalidDelegation.spec.ts`.
import { inject, provide, type ComputedRef, type InjectionKey, type Ref } from 'vue';

/**
 * A read-only reactive value the FormField exposes to its control(s). Accepts a
 * `computed` OR a `toRef(props, …)` (both are read-only refs), so the provider
 * can mix derived state and prop pass-throughs without casting.
 */
type ReadonlyRef<T> = ComputedRef<T> | Readonly<Ref<T>>;

/**
 * The validation outcome a FormField communicates to its control(s):
 *  - `error`   — invalid (danger line + message),
 *  - `success` — passed validation (success line + message),
 *  - `dirty`   — changed from initial but not yet validated (subtle accent),
 *  - `none`    — untouched / pristine (default treatment).
 *
 * `error`/`success` are caller-driven (the `error`/`success` props). `dirty` is
 * tracked automatically by comparing the model to its initial value, and is only
 * surfaced when there's no error/success verdict yet.
 */
export type FieldValidationState = 'error' | 'success' | 'dirty' | 'none';

export interface FormFieldContext {
  /** id to put on the control; matches the <label for>. */
  id: ComputedRef<string>;
  /** Space-joined ids of description + message text for aria-describedby (or undefined). */
  describedById: ComputedRef<string | undefined>;
  /** Whether the field is in an error state (kept for back-compat with controls). */
  invalid: ReadonlyRef<boolean>;
  /** Whether the field has a success verdict. */
  valid: ReadonlyRef<boolean>;
  /** Whether the field's value changed from its initial value (pre-validation). */
  dirty: ReadonlyRef<boolean>;
  /** The resolved validation state the FieldShell should render. */
  validationState: ReadonlyRef<FieldValidationState>;
  /** Whether the field (and its control) is disabled. */
  disabled: ReadonlyRef<boolean>;
  /** Whether the field (and its control) is read-only. */
  readonly: ReadonlyRef<boolean>;
  /** Whether the field is required (so controls can set aria-required). */
  required: ReadonlyRef<boolean>;
  /**
   * Register a control's value so the FormField can track dirtiness across one OR
   * several controls under one label. Returns a disposer. Optional: controls that
   * don't call it simply don't contribute to dirty tracking.
   */
  registerValue?: (read: () => unknown) => () => void;
}

export const FormFieldKey: InjectionKey<FormFieldContext> =
  Symbol('next-form-field');

export function provideFormField(ctx: FormFieldContext): void {
  provide(FormFieldKey, ctx);
}

/** Inject the surrounding FormField context, if any. */
export function useFormField(): FormFieldContext | undefined {
  return inject(FormFieldKey, undefined);
}

let uid = 0;
/** Stable-ish unique id for a control, prefixed for readability in the DOM. */
export function nextId(prefix = 'next-field'): string {
  uid += 1;
  return `${prefix}-${uid}`;
}
