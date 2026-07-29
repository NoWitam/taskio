---
paths:
  - "resources/js/**/*.vue"
  - "resources/js/**/*.ts"
  - "resources/js/**/*.js"
  - "resources/js/**/*.tsx"
  - "resources/css/**/*.css"
  - "tailwind.config.*"
  - "vite.config.*"
---

# Frontend Rules

Stack: Vue 3 (`<script setup>`), Pinia, Vue Router, Vite, Tailwind v4. TypeScript is the
target — new stores/composables/modules are `.ts`; some older files are still `.js`
(`store/index.js`, `store/user.js`, `router/*.js`). Prefer TS for new code; do not
mass-convert existing `.js`.

## Structure
- Feature code lives in `resources/js/modules/<name>/` (`components/`, `views/`,
  `routes.ts`). Shared building blocks: `components/ui/` (design system),
  `components/layouts/`, `composables/`, `lib/`, `store/`, `types/`.
- Routing is aggregated in `resources/js/router/index.js`. Two patterns coexist: some
  modules export routes from `router/modules/<name>.js`, while `forms` colocates them in
  `modules/forms/routes.ts`. Follow whichever pattern the module you touch already uses.

## Data & state
- All HTTP goes through the `api` singleton in `@/lib/api` (`api.get/post/put/patch/delete`,
  typed `<T>`). Do not import `axios` directly in components or stores. Auth is
  cookie/session based (`withCredentials`, CSRF from the meta tag); a 401 redirects to
  `/login` centrally in the interceptor.
- Server state lives in Pinia setup stores (`defineStore` with `ref`/`computed`). Typed
  filter interfaces in a store mirror the backend list query params 1:1 (e.g.
  `FormFilters.search/trashed/enabled/indexed/date_preset`). Keep filters in sync with the
  URL via `useRouteQueryHydration`.

## Reuse
- Reuse composables before writing logic: `useToast` (user feedback), `useInfiniteScroll`
  (paginated lists), `useDebounce` (search inputs), `useFocusTrap` / `useOverlayStack`
  (modals/overlays), `usePermissions` (gating UI), `useTheme`, `useI18n`.
- Reuse `components/ui/*` before building new widgets (see UX/UI rules).
- Do not invent backend response fields; read the API Resource. Handle loading (Skeletons),
  error, empty, and success states.
- Do not change the framework/stack without Planning Mode and explicit approval.

## "next" frontend (isolated rebuild)

- A separate, greenfield frontend lives under `resources/js/next/` with its own
  Vite entry, router, Pinia, api client, theme, and icons, and its own tokens in
  `resources/css/next.css` (scoped under a `.next-root` element). See
  [`docs/decisions/ADR-0002-parallel-next-frontend.md`](../../docs/decisions/ADR-0002-parallel-next-frontend.md).
- When working inside `resources/js/next/`, build against **its** design system
  and tokens — do **not** import from the legacy `resources/js/` (`components/ui`,
  `composables`, `lib`, `store`) and vice versa.
- The legacy app stays as documented above and is frozen; the rules in the
  sections above describe the **legacy** frontend.

## Internationalization (MANDATORY, both frontends)

- Taskio is multilingual: **Polish + English, switchable at runtime**. ALL
  user-facing text — labels, buttons, placeholders, aria-labels, empty/error
  states, toasts, validation — MUST be internationalized. **Never hardcode
  display strings.** Adding a feature/text without i18n is incomplete.
- Legacy: `useI18n()` (Pinia `store/locale.ts`) + `resources/js/locales/{pl,en}.json`,
  `t(key, default?, params?)` with `{param}` interpolation.
- "next": its own isolated i18n under `resources/js/next/app/i18n/` (no legacy
  import), `useI18n()` composable, `t()` helper, persisted to `next-locale`, with
  a language switcher in the app shell / styleguide. Every new key goes into BOTH
  `pl` and `en` catalogs.
