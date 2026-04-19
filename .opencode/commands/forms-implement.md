---
description: Implement forms module changes using existing architecture and components wherever possible.
agent: forms-maintainer
---

Implement the required forms module changes according to AGENTS.md.

Start by listing:
- what already exists
- what will be reused
- what should not be duplicated

Preserve architecture and style.
Prefer extending existing services, components, policies, validators, and utilities over creating new ones.

Before editing, show the files you plan to change.

Then implement in this order:
1. backend/domain changes
2. API changes
3. UI changes
4. tests

After implementation:
- review the diff for duplicated logic
- review the diff for duplicated UI
- review for style mismatch
- review for hidden behavior changes