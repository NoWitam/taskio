---
description: Implement only backend/domain and migration changes for the forms module.
agent: forms-maintainer
---

Implement only backend, domain, and migration changes for the forms module according to AGENTS.md.

First inspect and list existing reusable:
- models
- services
- policies
- validators
- migrations
- tests

Reuse before creating anything new.

Preserve the app's current architecture and style.

Show the planned file changes before editing.

After implementation:
- run the smallest relevant backend test set
- summarize reuse decisions