---
name: extract-project-rules
description: Analyze Taskio code and extract coding, architecture, frontend, UX, testing, and documentation conventions into rules.
---

Use before major refactors.

Workflow:
1. Inspect representative backend modules in `app/modules`.
2. Inspect app-level Laravel files in `app/Http`, `routes`, `database`.
3. Inspect frontend modules in `resources/js/modules`, components, store, router, composables.
4. Inspect CSS/Tailwind patterns.
5. Inspect current tests.
6. Inspect existing `AGENTS.md` and `.opencode`.
7. Produce proposed updates to `.claude/rules/*.md`.
8. Do not overwrite rules without explaining changes.
