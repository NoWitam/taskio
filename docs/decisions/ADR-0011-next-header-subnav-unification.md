# ADR-0011 — "next" header & sub-navigation unification: one PageHeader per page, shared module nav, child-route sections

**Date:** 2026-07-11 (created)
**Status:** Accepted
**Module:** "next" frontend (`resources/js/next/`)

---

## Context

A consistency audit (8 agents, 100 % coverage of the `next` router) found the page-top chrome had
drifted into 4 header variants and 5 sub-navigation dialects: the canonical `PageHeader` was used
on 8 list pages while the Forms sub-views hand-rolled small `h1` rows and the Bot/Workflow details
had NO page heading at all (identity lived only in the module aside — which is hidden below
`next-lg`, leaving mobile with no title and no section nav). The four `*ModuleLayout.vue` files
were copy-paste near-duplicates with three different section-sync mechanisms (Forms child routes;
Bots/Workflows `?section=`; Approvals static), the Navbar rendered a second `h1` per page, the
global sidebar lost its active state on child routes, and in the light theme `--color-next-accent`
was identical to `--color-next-primary-subtle` (hover of an inactive nav item looked active).

The user decided (D1–D6, accepted plan v2):

- **D1=A** — every routed page gets a PageHeader; on details the h1 is the ENTITY name (entity
  icon + StatusBadge + actions); sub-views use a smaller variant.
- **D2=B** — the module aside stays at ≥ `next-lg`, extracted into shared components; below
  `next-lg` the fallback is tabs.
- **D3** — subtle tint / underline = NAVIGATION; a solid pill = data-scope FILTER; the
  `accent` / `primary-subtle` tokens were split in light to keep that distinction visible.
- **D4=A** — child routes everywhere (no `?section=`); sidebar prefix-matching; `?tab=` URL sync
  for in-page buckets.
- **D5=A** — the Navbar stops rendering an h1; it carries breadcrumb context instead.
- **D6** — docs-only minimum: explicit h1 weight in the gallery shells + deep-linkable
  styleguide stories.

Work shipped in reviewed batches: tokens + PageHeader API (`size="sm"`, `#meta`), sidebar
prefix-match (`isPathActive`), `?tab=` sync (Forms buckets), the `?section=` → child-route
migration, the shared `ModuleAside`/`ModuleTabs` pair + PageHeader rollout, the Navbar breadcrumb,
and container/rhythm cleanups. Each risky batch passed an adversarial multi-agent review gate.

---

## Decisions

### 1. Detail sections are CHILD ROUTES; legacy `?section=` deep links redirect with the query intact

`/bots/:id` and `/workflows/:id` gained named children (`next.bots.detail.{inbox,activity,config}`,
`next.workflows.detail.{overview,runs}`). The bare `:id` record keeps the legacy name as a
redirect record, so existing named pushes land on the default section. The pure
`sectionRedirect()` (`app/router/sectionRedirect.ts`) maps old `?section=` URLs onto the right
child while preserving EVERY other query key (`run`, `run_detail`, `state`, `origin`, `bot`,
`workflow`, …) — overlay state and filters survive the migration. Bots' three sections share one
component (componentless `:id` parent; the view derives the section from the route name); the
workflows detail is a SHELL component with a nested `<RouterView>` because Runs owns its own
lifecycle (`WorkflowOverviewView` + the thin `WorkflowRunsSection` adapter around the unchanged
`WorkflowRunsView`). Real-router wiring is pinned by `routerSectionWiring.spec.ts`.

### 2. The h1 names the PAGE's purpose; entity identity lives once — in the aside's selected block

**(Reworked on user feedback after the first rollout, which had put the identity into the detail
PageHeader with a smaller `size="sm"` header on sub-views — the size split read as inconsistency.)**
Every routed page renders the SAME full-size PageHeader whose h1 says what the PAGE is for:
list pages keep their module titles; bot/workflow detail sections and Forms sub-views use the
section name + a one-line section description (`bots.detail.sectionDescriptions.*`,
`workflows.detail.sectionDescriptions.*`). The open entity's identity (icon + name + status +
short description) lives exactly once — in the module aside's SELECTED resource block (plus the
Navbar breadcrumb). Entity actions (run / activate / edit) stay in the page header's `#actions`;
the header "back" button was dropped (the aside's module nav + the breadcrumb cover it).
PageHeader keeps its `size` prop for future panel use, but no routed page uses `sm` anymore.

### 3. One shared TWO-LEVEL module nav pair: `ModuleAside` (≥ next-lg) + `ModuleTabs` (below)

`ui/layout/ModuleAside.vue` renders two stacked levels:
- the **module block** (icon + title + hint saying what the module is for) above the
  **module nav** — pages that need no resource ("All bots", Approvals' Queue/Pipelines);
- for modules with resource-scoped pages, a **resource section**: with nothing open, a muted
  DASHED placeholder shaped like the future selected block that LINKS to the module list (picking
  happens there) above a purely decorative `aria-hidden` preview of the resource nav in a disabled
  look; with a resource open, the section is PROMOTED ABOVE the module block as a tinted
  "selected" identity block (icon + name + `#resource-meta` status + clamped description) over the
  live resource nav (Preview/Submissions/Reports · Inbox/Activity/Config · Overview/Runs).

Hosts feed `moduleItems`/`resourceItems` + one `activeMatch` closure; both navs are labelled
(`common.moduleNav` + per-module `*.module.resourceNav`), the active link carries
`aria-current="page"`. `ui/layout/ModuleTabs.vue` stays a SINGLE underline-Tabs row below
`next-lg` (the resource items when a resource is open, else the module items), route-pushing,
rendered by the layouts ABOVE their `<RouterView>` — not through PageHeader's `#tabs` slot
(tension 2). All four module layouts consume the pair; per-module code keeps only the item lists,
drawer hosting, query helpers and detail prefetch. `Tabs` itself gained a nav-only mode: with no
panel slot it emits no tabpanels and no `aria-controls` (review-gate finding: an empty focusable
tabpanel + dead gap otherwise). The Forms enabled/draft status line — lost in the first rollout —
returned as the form's `#resource-meta`.

### 4. Navbar renders a breadcrumb, never an h1 (D5)

`AppLayout` replaced the Navbar `h1` with `Breadcrumbs`: module title (linked to the module root
when an entity is open) › entity name (`aria-current="page"`). The entity name travels through
`app/lib/pageContext.ts` — a plain module-level ref the three entity layouts set — NOT
`route.meta.contextLabel` as first planned: meta is merged per navigation and is not reactive for
values assigned after the entity loads, and a ref keeps AppLayout free of feature-store imports.
**Lifecycle trap (review-gate MAJOR, fixed + pinned by `pageContext.spec.ts`):** layouts clear the
label in `onBeforeUnmount`, not `onUnmounted` — on a cross-module swap the outgoing layout's
`onUnmounted` is deferred post-flush and would wipe the label the incoming layout just set
synchronously for a cached detail, sticking the breadcrumb at null.

### 5. Tokens: `accent` ≠ `primary-subtle` in light

Light `--color-next-accent` became the neutral muted hover (`hsl(320 12% 94%)` +
matching foreground); dark was already distinct and was left untouched. This is what makes the
D3 navigation-vs-filter distinction legible.

### 6. Rhythm / containers

MembersView dropped its padded nested `<main>` (`Container … flush`; the app shell owns the gutter
and the main landmark). Forms sub-views moved to the standard `gap-next-6` page rhythm (the
builder keeps its denser `gap-next-4`). WorkflowsView's info Alert under the PageHeader was
removed as a near-verbatim duplicate of the header's own description (the orphaned
`workflows.list.moduleDescription` key was deleted from both catalogs).

### 7. Docs minimum (D6)

`StoryPage` / `TokensPage` set an explicit h1 weight (Tailwind preflight resets h1 to 400);
styleguide stories are deep-linkable via `?story=<id>` (hydrate on load, `router.replace` on
selection, back/forward followed). New gallery page: Layout › Module navigation
(`ModuleNavPage.vue`) documenting both components; the Tabs page notes the nav-only mode.

---

## Consequences

- Every routed page has exactly one h1 at ONE header scale; mobile finally shows a page title +
  section nav (PageHeader + ModuleTabs) that used to exist only in the ≥ `next-lg` aside.
- The aside doubles as a resource affordance: an empty module invites picking a resource (the
  placeholder links to the list), an open one keeps its identity + sections pinned top-left.
- Old `?section=` links keep working forever at one redirect's cost; new section URLs are
  path-shaped and bookmarkable per section.
- The four layouts can no longer drift apart visually — aside/tabs markup lives in two shared
  components with their own specs.
- `pageContextLabel` is a tiny global; if more shell-level page context ever accumulates,
  fold it into a dedicated store rather than growing this module.
- Known follow-ups (accepted, out of scope here): the FormSubmissions/Reports headers share the
  uniform PageHeader contract pinned on the Preview spec only; `happy-dom` + `@vue/test-utils`
  are used by every spec but still missing from `package.json` devDependencies (flagged as a
  separate task).

## Validation

`npm run test:unit`: 85 files / 730 tests green (WSL, Node 22); `npm run build` passes. Review
gates: Batch 3 — 0 defects (3 coverage gaps closed the same session); Batch 4+6 — 1 MAJOR
(pageContext lifecycle) + 3 minors, all fixed and covered; the two-level redesign re-reviewed
after the user-feedback iteration.
