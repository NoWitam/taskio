---
description: Add or update tests for the forms module in the current repository style.
agent: forms-maintainer
---

Add or update tests for the forms module according to AGENTS.md.

First inspect the existing:
- testing stack
- factories
- builders
- naming conventions
- test organization

Reuse fixtures and helpers where possible.

Cover:
- state transitions
- disabled + indexed legality
- validation behavior
- assignment rules
- indexing compatibility
- backup restore checks
- indexed vs unindexed reporting differences