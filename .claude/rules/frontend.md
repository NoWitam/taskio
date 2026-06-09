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
