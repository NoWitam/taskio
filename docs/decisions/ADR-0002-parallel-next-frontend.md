# ADR-0002: Build a parallel "next" frontend as an isolated Vite bundle

## Status

Accepted — 2026-06-09

## Context

The existing frontend (`resources/js/`) is a mature application built on a bespoke design
system (`resources/js/components/ui/`) with semantic color tokens defined in
`resources/css/app.css`. It works and continues to serve production traffic.

A clean-slate UX and design system rebuild was explicitly chosen. Incremental migration
inside the existing module tree would require every component change to maintain parity with
the current design system, slow down the redesign, and risk destabilising a working app. The
clean-slate scope justifies a deliberate, bounded exception to the architecture rule
"Avoid parallel v2 implementations" recorded in `.claude/rules/architecture.md`.

The exception is accepted on the following grounds:

- The existing frontend is frozen during the transition — no features are backfilled on both
  sides simultaneously.
- A hard deletion seam is agreed upfront (see Consequences).
- Cross-boundary imports are strictly prohibited so the two bundles cannot silently merge.

## Decision

A second, fully isolated frontend — referred to as the "next" frontend — is built from
scratch as a separate Vite bundle.

**Source boundaries**

| Concern | Location |
|---|---|
| Application code | `resources/js/next/` |
| CSS tokens and stylesheet | `resources/css/next.css` |

The new stylesheet scopes all tokens and Tailwind utilities under a `.next-root` class so
that the new stylesheet cannot leak into the existing app, and vice versa.

**Primary color continuity**

The single design token carried over from the existing system is the primary color
`hsl(325 60% 45%)`. Everything else — semantic token names, dark-mode strategy, component
API, and visual language — is rebuilt from scratch.

**Rebuilt from scratch (no reuse from `resources/js/`)**

- API client
- Vue Router instance and route definitions
- Pinia stores
- Theme and token system
- Icon set
- All UI components
- Layouts
- Pages

**Infrastructure**

- A second Vite input entry point is added in `vite.config.*`.
- A dedicated Blade view serves the new shell (e.g. `resources/views/next.blade.php`).
- A `/next` Laravel route loads that Blade view.

The existing Vite input, Blade view, and route for the current frontend are not modified.

## Constraints and guardrails

1. **No cross-boundary imports.** Files inside `resources/js/next/` must not import from
   `resources/js/` (outside `next/`), and vice versa. Path aliases must not bridge the
   boundary.
2. **Old app is frozen.** No feature backfilling across both frontends. Changes to the
   existing app are limited to bug fixes and critical maintenance.
3. **CSS isolation.** `resources/css/next.css` applies its tokens and utilities only under
   `.next-root`. The old `resources/css/app.css` must not be imported in the next bundle.
4. **No shared component library mid-flight.** A shared package can be proposed through
   Planning Mode only after the next frontend has stabilised.

## Consequences

- **Double maintenance during transition.** Bug fixes that affect both experiences must be
  applied separately to each.
- **Divergence risk.** If the transition drags, the two codebases can drift significantly in
  behaviour. A clear completion milestone should be set.
- **Hard deletion seam.** Decommissioning is a single PR that removes:
  - the Vite input for the old bundle,
  - the old Blade view,
  - the `/next` route (swapped for the root route),
  - `resources/js/next/` renamed/moved to `resources/js/`,
  - `resources/css/next.css` renamed to `resources/css/app.css`.
- **Clean design system.** The next frontend is unconstrained by legacy component APIs,
  enabling a coherent and fully documented design system from the start.
