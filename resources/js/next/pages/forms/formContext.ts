// Shared context for the Forms module's per-form sub-views (next).
//
// FormsModuleLayout fetches the selected form once (for the inner sidebar) and
// provides it here, so the child views (Preview / Submissions / …) read the same
// form without each re-fetching it.
import type { InjectionKey, Ref } from 'vue';
import type { FormDetail } from './types';

export interface FormModuleContext {
  /** The currently-open form (null on the list, or while loading). */
  form: Ref<FormDetail | null>;
  loading: Ref<boolean>;
}

export const FORM_MODULE_CTX: InjectionKey<FormModuleContext> = Symbol('form-module-ctx');
