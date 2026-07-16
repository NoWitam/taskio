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
