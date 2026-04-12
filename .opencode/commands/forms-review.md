---
description: Review the forms module implementation for duplication, style mismatch, regressions, and compliance with AGENTS.md.
agent: forms-maintainer
---

Review the current implementation of the forms module changes.

Do not change code unless explicitly asked.

Verify compliance with AGENTS.md.

Focus on:
- preservation of existing patterns
- reuse of existing abstractions
- absence of duplicated functionality
- legality of disabled + indexed
- validation differences between disabled and enabled
- assignment restrictions
- indexing compatibility rules
- reporting and filter differences
- UI consistency
- test coverage

List findings grouped by severity.