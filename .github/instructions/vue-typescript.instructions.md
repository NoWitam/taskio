---
description: "Use when writing, editing, or reviewing Vue 3, TypeScript, or frontend code. Covers component patterns, Pinia stores, composables, and API conventions."
applyTo: "resources/js/**"
---

# Vue 3 & TypeScript Conventions

## Component Style

- Always use `<script setup lang="ts">` with Composition API.
- Define typed props: `defineProps<Props>()` with a local `interface Props {}`.
- Define typed emits: `defineEmits<Emits>()` with a local `interface Emits {}`.
- Use `computed()` for derived state, `ref()` for reactive local state.
- PascalCase for component names and imports: `FormCard.vue`, `import FormCard from ...`.
- Template goes in `<template>`, styles (if any) via Tailwind utility classes — no `<style>` blocks unless necessary.

## Directory Structure

- Feature modules: `resources/js/modules/{module}/` with `views/`, `components/`, `routes.ts`.
- Dialogs: `resources/js/modules/{module}/components/Dialogs/`.
- Global shared components: `resources/js/components/ui/`.
- Composables: `resources/js/composables/` (e.g., `useI18n`, `useToast`, `useAuth`, `useInfiniteScroll`).
- Type definitions: `resources/js/types/`.
- Stores: `resources/js/store/`.

## Pinia Stores

- Use `defineStore('name', () => { ... })` (setup/composition syntax).
- State as `ref()`, getters as `computed()`, actions as `async` functions.
- Group sections with comments: `// COMPUTED`, `// ACTIONS`.
- Cursor-based pagination: track `cursors`, `hasMore`, `loading` per resource key.
- Cache entities by ID in `ref<Record<string, Entity>>({})`.

## API Calls

- Use `api` client from `@/lib/api` (axios wrapper).
- Type responses with interfaces: `ApiResponse<T>`, `ApiMeta`.
- Build query params with `URLSearchParams`.
- Filter interfaces defined in store file (e.g., `FormFilters`, `SubmissionFilters`).

## Composables

- Reuse existing composables before creating new ones:
  - `useI18n()` — translations with `t('namespace.key')`.
  - `useToast()` — toast notifications.
  - `useAuth()` — current user, permissions.
  - `useInfiniteScroll()` — scroll-triggered loading.
  - `useDebounce()`, `useClickOutside()`, `useFocusTrap()`, `useOverlayStack()`.
- New composable files go in `resources/js/composables/use{Name}.ts`.

## Styling

- Tailwind CSS utility classes — no custom CSS unless unavoidable.
- Support light/dark mode via Tailwind dark variant.
- Common patterns: `bg-background`, `text-foreground`, `border-border`, `text-muted-foreground`.
- Use existing UI components from `@/components/ui/` — `Button`, `Icon`, `Badge`, etc.

## i18n

- Two locales: `pl` (primary), `en`.
- Translation files in `lang/{locale}/`.
- Use `t('module.key')` in templates and scripts.

## Security

- Sanitize user HTML with `DOMPurify` before rendering via `v-html`.
- Never trust user input in template interpolation for HTML context.
