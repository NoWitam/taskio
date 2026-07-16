// Theme management for the isolated "next" frontend.
//
// Dark mode is a token-override scheme scoped to `.next-root.dark`
// (see resources/css/next.css). This util toggles the `dark` class on the
// root element (`#next-app` / `.next-root`) — never on <html> — so it cannot
// affect the legacy app, and persists the user's choice under `next-theme`.
//
// Self-contained: no imports from the legacy `resources/js/`.
import { ref, computed, readonly, type ComputedRef, type Ref } from 'vue';

export type ThemePreference = 'light' | 'dark' | 'system';
export type ResolvedTheme = 'light' | 'dark';

const STORAGE_KEY = 'next-theme';
const ROOT_ID = 'next-app';

/** Module-level singleton state so every `useTheme()` caller shares one source. */
const preference: Ref<ThemePreference> = ref(readStoredPreference());
let mediaQuery: MediaQueryList | null = null;
let mediaListenerBound = false;

function readStoredPreference(): ThemePreference {
  try {
    const stored = localStorage.getItem(STORAGE_KEY);
    if (stored === 'light' || stored === 'dark' || stored === 'system') {
      return stored;
    }
  } catch {
    /* localStorage may be unavailable (private mode / SSR-less guard) */
  }
  return 'system';
}

function getMediaQuery(): MediaQueryList | null {
  if (typeof window === 'undefined' || !window.matchMedia) return null;
  if (!mediaQuery) {
    mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
  }
  return mediaQuery;
}

function systemPrefersDark(): boolean {
  return getMediaQuery()?.matches ?? false;
}

/** The effective theme after resolving `system` against the OS preference. */
function resolve(pref: ThemePreference): ResolvedTheme {
  if (pref === 'system') return systemPrefersDark() ? 'dark' : 'light';
  return pref;
}

function getRootElement(): HTMLElement | null {
  if (typeof document === 'undefined') return null;
  return document.getElementById(ROOT_ID);
}

/** Apply the resolved theme by toggling `dark` on the next-root element. */
function applyTheme(pref: ThemePreference): void {
  const root = getRootElement();
  if (!root) return;
  const resolved = resolve(pref);
  root.classList.toggle('dark', resolved === 'dark');
}

function persist(pref: ThemePreference): void {
  try {
    localStorage.setItem(STORAGE_KEY, pref);
  } catch {
    /* ignore persistence failure */
  }
}

/** React to OS theme changes while the user preference is `system`. */
function bindSystemListener(): void {
  if (mediaListenerBound) return;
  const mq = getMediaQuery();
  if (!mq) return;
  mq.addEventListener('change', () => {
    if (preference.value === 'system') {
      applyTheme('system');
    }
  });
  mediaListenerBound = true;
}

/**
 * Initialise the theme on app boot. Call once from main.ts after the root
 * element exists. Idempotent and safe to call again.
 */
export function initTheme(): void {
  applyTheme(preference.value);
  bindSystemListener();
}

export function setTheme(pref: ThemePreference): void {
  preference.value = pref;
  persist(pref);
  applyTheme(pref);
}

export interface UseThemeReturn {
  /** The user's stored preference: light | dark | system. */
  preference: Readonly<Ref<ThemePreference>>;
  /** The effective theme after resolving `system`. */
  resolved: ComputedRef<ResolvedTheme>;
  /** Whether the resolved theme is dark. */
  isDark: ComputedRef<boolean>;
  setTheme: (pref: ThemePreference) => void;
  /** Toggle between light and dark (resolving `system` to its current value first). */
  toggle: () => void;
}

export function useTheme(): UseThemeReturn {
  const resolved = computed<ResolvedTheme>(() => resolve(preference.value));
  const isDark = computed(() => resolved.value === 'dark');

  function toggle(): void {
    setTheme(isDark.value ? 'light' : 'dark');
  }

  return {
    preference: readonly(preference) as Readonly<Ref<ThemePreference>>,
    resolved,
    isDark,
    setTheme,
    toggle,
  };
}
