---
name: refactor-backend-module
description: Refactor one Taskio backend module using modular monolith layers while preserving behavior.
---

Use `backend-agent`.

Workflow:
1. Define exact module/slice.
2. Inspect current routes, controllers, requests, policies, models, migrations, resources, services, repositories, managers, DTOs.
3. Use Laravel Boost MCP for app/schema/docs when useful.
4. Summarize current behavior.
5. Identify reusable parts and conventions.
6. Propose target structure.
7. Implement smallest safe step.
8. Run/describe validation.
9. Ask testing-agent for tests.
10. Ask documentation-agent for docs.
11. Ask reviewer-agent for review.

Do not touch frontend.
