// Internationalization for the isolated "next" frontend.
//
// A self-contained, dependency-free i18n layer (NO vue-i18n, NO legacy import).
// It mirrors the SINGLETON COMPOSABLE pattern used by `../lib/theme.ts`: one
// module-level reactive `locale` ref shared by every `useI18n()` caller, so
// switching the language re-renders ALL translated strings instantly.
//
// Public API (matches legacy `store/locale.ts` semantics):
//   t(key, defaultValue?, params?) — dot-path lookup into the active catalog,
//       `{param}` interpolation, returns `defaultValue ?? key` on a miss.
//   locale          — readonly ref of the active NextLocale.
//   currentLocale   — alias of `locale` (legacy-compatible name).
//   setLocale(l)    — switch + persist + set <html lang> + best-effort PUT.
//   availableLocales — the list for a switcher control.
//
// Initial locale resolution: localStorage('next-locale') → browser language
// (`pl*` → pl, else en) → default 'en'.
import { computed, readonly, ref, type ComputedRef, type Ref } from 'vue';
import { en } from './en';
import { pl } from './pl';
import { api } from '../lib/api';

export type NextLocale = 'pl' | 'en';

const STORAGE_KEY = 'next-locale';
const DEFAULT_LOCALE: NextLocale = 'en';

const CATALOGS = { en, pl } as const;

export const AVAILABLE_LOCALES: readonly NextLocale[] = ['pl', 'en'];

/** Module-level singleton so every `useI18n()` caller shares one source. */
const locale: Ref<NextLocale> = ref<NextLocale>(resolveInitialLocale());

function isLocale(value: unknown): value is NextLocale {
  return value === 'pl' || value === 'en';
}

function readStoredLocale(): NextLocale | null {
  try {
    const stored = localStorage.getItem(STORAGE_KEY);
    return isLocale(stored) ? stored : null;
  } catch {
    return null;
  }
}

function browserLocale(): NextLocale | null {
  if (typeof navigator === 'undefined') return null;
  const lang = (navigator.language || '').split('-')[0].toLowerCase();
  return lang === 'pl' ? 'pl' : lang === 'en' ? 'en' : null;
}

function resolveInitialLocale(): NextLocale {
  return readStoredLocale() ?? browserLocale() ?? DEFAULT_LOCALE;
}

function persist(value: NextLocale): void {
  try {
    localStorage.setItem(STORAGE_KEY, value);
  } catch {
    /* localStorage may be unavailable (private mode) */
  }
}

function setHtmlLang(value: NextLocale): void {
  if (typeof document !== 'undefined') {
    document.documentElement.lang = value;
  }
}

/**
 * Best-effort sync of the user's locale preference to the backend. Guarded: a
 * failure (unauthenticated, offline, endpoint missing) is swallowed so it can
 * never break a language switch — exactly like the legacy store's try/catch.
 */
function syncLocaleToServer(value: NextLocale): void {
  try {
    void api.put('/user/locale', { locale: value }).catch(() => {});
  } catch {
    /* never throw from a UI-driven language switch */
  }
}

/** Dot-path lookup into a catalog; returns the raw string or null on a miss. */
function lookup(catalog: unknown, key: string): string | null {
  let value: unknown = catalog;
  for (const part of key.split('.')) {
    if (value && typeof value === 'object' && part in (value as Record<string, unknown>)) {
      value = (value as Record<string, unknown>)[part];
    } else {
      return null;
    }
  }
  return typeof value === 'string' ? value : null;
}

/** Replace `{param}` tokens with their values. */
function interpolate(template: string, params?: Record<string, string | number>): string {
  if (!params) return template;
  let result = template;
  for (const [name, value] of Object.entries(params)) {
    // split/join replaces ALL occurrences without needing ES2021 `replaceAll`
    // (the next bundle targets ES2020) or regex escaping of the token name.
    result = result.split(`{${name}}`).join(String(value));
  }
  return result;
}

/**
 * Translate a dot-path key in the active locale. On a miss returns
 * `defaultValue` when provided, else the key itself (legacy semantics). The
 * looked-up template (or the default) is `{param}`-interpolated.
 */
export function translate(
  key: string,
  defaultValue?: string,
  params?: Record<string, string | number>,
): string {
  const hit = lookup(CATALOGS[locale.value], key);
  const template = hit ?? defaultValue ?? key;
  return interpolate(template, params);
}

export function setLocale(value: NextLocale): void {
  if (!isLocale(value) || value === locale.value) {
    // Still ensure <html lang> + persistence are consistent on a no-op set.
    setHtmlLang(value);
    return;
  }
  locale.value = value;
  persist(value);
  setHtmlLang(value);
  syncLocaleToServer(value);
}

/**
 * Initialise i18n on app boot. Call once from main.ts: it sets `<html lang>`
 * from the already-resolved locale. Idempotent.
 */
export function initI18n(): void {
  setHtmlLang(locale.value);
}

export interface UseI18nReturn {
  /** Translate a dot-path key. */
  t: typeof translate;
  /** Readonly active locale. */
  locale: Readonly<Ref<NextLocale>>;
  /** Alias of `locale` (legacy-compatible name). */
  currentLocale: ComputedRef<NextLocale>;
  setLocale: (value: NextLocale) => void;
  availableLocales: readonly NextLocale[];
}

export function useI18n(): UseI18nReturn {
  return {
    t: translate,
    locale: readonly(locale) as Readonly<Ref<NextLocale>>,
    currentLocale: computed(() => locale.value),
    setLocale,
    availableLocales: AVAILABLE_LOCALES,
  };
}
