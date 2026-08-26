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
//   activeLocale()  — non-reactive read of the rendered locale, for callers
//       outside the component tree (the HTTP client declares it per request).
//   availableLocales — the list for a switcher control.
//
// Initial locale resolution: localStorage('next-locale') → browser language
// (`pl*` → pl, else en) → default 'en'. NOTE that this resolution is entirely
// client-side, which is why `lib/api.ts` states the result on every request:
// the server cannot otherwise know a Polish screen is reading its answers.
import { computed, readonly, ref, type ComputedRef, type Ref } from 'vue';
import { en } from './en';
import { pl } from './pl';
import { api } from '../lib/api';
import { hasAuthToken } from '../lib/token';

export type NextLocale = 'pl' | 'en';

const STORAGE_KEY = 'next-locale';
const DEFAULT_LOCALE: NextLocale = 'en';

const CATALOGS = { en, pl } as const;

export const AVAILABLE_LOCALES: readonly NextLocale[] = ['pl', 'en'];

/** Module-level singleton so every `useI18n()` caller shares one source. */
const locale: Ref<NextLocale> = ref<NextLocale>(resolveInitialLocale());

/**
 * The locale this client is rendering RIGHT NOW — the non-reactive read, for callers outside the
 * component tree. `lib/api.ts` sends it on every request so server prose can match the screen.
 *
 * A `function` declaration on purpose: api.ts imports this module and this module imports api.ts, so
 * one of the two is evaluated while the other is still in progress. A hoisted declaration is defined
 * before either body runs; a `const` arrow would be in its temporal dead zone in one of the two orders.
 * Neither module touches the other's bindings at module scope, so the cycle itself is inert.
 */
export function activeLocale(): NextLocale {
  return locale.value;
}

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
 * failure (offline, endpoint missing) is swallowed so it can never break a
 * language switch — exactly like the legacy store's try/catch.
 *
 * NOT ATTEMPTED WHEN NOBODY IS LOGGED IN. There is no account to store the
 * choice on, and the api client's 401 interceptor navigates to the login page:
 * on the invite-accept screen (a `public: true` route that carries a language
 * switcher) a speculative PUT would throw an anonymous visitor off the page
 * they were invited to. Such a visitor is not left behind — the server reads
 * the locale this client is rendering from the request header instead.
 *
 * The check comes from `lib/token`, not `lib/api`, so that it is asked OUTSIDE
 * the try/catch below: a missing export is a broken contract and should be
 * loud, and only the request itself belongs in a swallow-everything guard.
 */
function syncLocaleToServer(value: NextLocale): void {
  if (!hasAuthToken()) return;

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

/**
 * Switch the language — and RE-ASSERT it even when it is already the active one.
 *
 * The old early return made clicking the lit-up segment of the switcher completely inert, which was a
 * problem because that click is the only lever a user has: someone whose account stores `pl` (chosen on
 * another device) but whose browser here renders `en` sees two languages at once, and pressing "EN" —
 * the obviously correct thing to press — did nothing. Now it persists the choice for this session and
 * tells the server, which is what the user was asking for.
 *
 * Re-running the whole body when the value is unchanged is safe and cheap: assigning a ref its current
 * value does not trigger Vue reactivity, `localStorage` and `<html lang>` are idempotent writes, and
 * the only real cost is one fire-and-forget PUT per deliberate click.
 *
 * A value outside the locale set is still rejected outright — including for `<html lang>`, which the
 * old guard would happily have set to the junk it had just refused to apply.
 */
export function setLocale(value: NextLocale): void {
  if (!isLocale(value)) return;

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
