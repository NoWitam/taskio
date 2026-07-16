---
paths:
  - "tests/**/*.php"
  - "resources/js/**/*.test.*"
  - "resources/js/**/*.spec.*"
  - "vitest.config.*"
  - "phpunit.xml"
---

# Testing Rules

- Add tests gradually.
- Prefer high-value feature tests before low-level unit tests.
- Capture current behavior before refactor when practical.
- Test behavior, not implementation details.
- Avoid brittle snapshots unless justified.
- Backend uses **PHPUnit** (not Pest); feature tests in `tests/Feature`, unit in
  `tests/Unit`. Run `php artisan test --compact` with a path or `--filter` to scope.
- Frontend uses **Vitest**: `npm run test:unit`. Coverage is currently thin (one spec) —
  add tests next to the code you change.
- Add regression tests for fixed bugs.
