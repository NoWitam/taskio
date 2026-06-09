---
paths:
  - "resources/js/**/*.vue"
  - "resources/css/**/*.css"
  - "docs/design-system/**/*.md"
  - "docs/frontend/**/*.md"
---

# UX/UI Rules

The design system lives in `resources/js/components/ui/`. Reuse it before inventing UI:
`Button`, `Card`, `Dialog` / `ConfirmDialog`, `Badge` / `StatusBadge`, `Tabs`, `Pagination`,
`DropdownMenu`, `Tooltip`, `LoadingSpinner`, `Skeleton`, `Sidebar`, `Navbar`, plus
`ui/inputs`, `ui/patterns`, `ui/tables`. The single app shell is
`components/layouts/AppLayout.vue`.

## Theming & color
- Use the semantic color tokens defined in `resources/css/app.css` `@theme` (`primary`,
  `background`, `foreground`, `card`, `border`, `muted`, `danger`, `success`, `warning`
  and their `-foreground` pairs) — e.g. `bg-primary`, `text-danger`. Do not hardcode raw
  hex/hsl in components.
- Tailwind v4 is CSS-first; tokens and the dark variant live in `app.css`
  (`@custom-variant dark`, `:root.dark`). Dark mode overrides tokens — support it that way,
  not by ad-hoc color inversion.

## Interaction principles
- Every screen needs a clear goal and primary action.
- Tables, filters, badges, cards, forms, modals, and menus must be consistent across the app.
- Use explicit loading / error / empty / success states (Skeletons for loading,
  `useToast` for feedback).
- Disabled actions must remain understandable; explain why an action is unavailable where
  useful (backend already returns capability flags like `can_be_*`).
- Status badges (`StatusBadge`) should be readable, consistent, and not rely on color alone.
- Cards with metadata should have a consistent metadata area/footer.
- Data-heavy mobile views should not simply shrink desktop tables.
- Modals must have a clear title, purpose, primary action, safe cancel/close behavior, and
  focus management (`useFocusTrap`).
