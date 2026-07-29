---
paths:
  - "app/**/*.php"
  - "routes/**/*.php"
  - "resources/js/**/*.vue"
  - "resources/js/**/*.ts"
  - "resources/js/**/*.js"
  - "docs/architecture/**/*.md"
---

# Taskio Architecture Rules

- Treat Taskio as an existing product, not a greenfield rewrite.
- Preserve local style and module boundaries.
- Prefer reuse and extension over new abstractions.
- Avoid parallel `v2` implementations.
- Use Planning Mode before architecture changes.
- Large work should move through: backend -> UX/UI -> frontend -> tests -> docs -> review.
- Keep changes small enough to review in one commit/PR.

## "next" frontend (accepted exception)

- A greenfield, isolated frontend is being built in parallel under
  `resources/js/next/` + `resources/css/next.css`, served at `/next`. This is a
  deliberate, approved exception to "avoid parallel v2 implementations" — see
  [`docs/decisions/ADR-0002-parallel-next-frontend.md`](../../docs/decisions/ADR-0002-parallel-next-frontend.md).
- The legacy frontend (`resources/js/`) is **frozen**: do not backfill features
  into both sides. New UI work targets `next`.
- **No cross-boundary imports** between `resources/js/next/` and the legacy
  `resources/js/`. The seam must stay deletable in one PR.
