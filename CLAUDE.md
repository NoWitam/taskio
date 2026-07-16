# Taskio Claude Code Operating Manual

Taskio is an existing Laravel + Vue + TailwindCSS application. Treat it as a mature product being refactored, not a greenfield rewrite.

## Current stack facts

- Backend: Laravel 12, PHP ^8.2, Sanctum, Laravel AI, Laravel Boost, PHPUnit, Pint.
- Frontend: Vue 3, Vue Router, Pinia, Vite, TailwindCSS 4, Vitest, Tiptap.
- Structure: modular monolith with backend modules under `app/modules` and frontend modules under `resources/js/modules`.
- Existing repository already contains `AGENTS.md` and `.opencode` configuration. Reuse their philosophy: analyze first, preserve spirit, avoid duplicate functionality, and prefer the smallest safe change set.

## Target architecture

Use a modular monolith. A module should own its domain behavior and expose clear boundaries.

Preferred backend flow:

1. Route
2. Controller
3. FormRequest
4. DTO
5. Action
6. Service / Manager
7. Repository
8. Model / persistence
9. Resource / response

Layer rules:

- `FormRequest` validates input and handles authorization by delegating to Policies.
- Controllers stay thin: receive request/DTO, call one action, return response/resource.
- Actions orchestrate one use case.
- Services contain larger business logic.
- Managers are for complex workflows with internal states, multi-step transitions, lifecycle/state machines, or coordinated behavior.
- Repositories contain database queries and persistence access so caching or alternative read layers can be added later.
- DTOs move validated data from requests/controllers into actions/services.
- Policies centralize authorization decisions.
- Resources/serializers keep API response shape consistent.

## Refactor sequence

Default sequence for large work:

1. Backend refactor.
2. UX/UI planning and design system.
3. Frontend implementation.
4. Tests.
5. In-app documentation.
6. Review.

Never rewrite backend, UX, frontend, and tests in a single uncontrolled change.

## Planning-first workflow

For any feature, refactor, architecture change, documentation system, or design-system decision:

- Use `planning-agent` first.
- Do not code until the plan is accepted.
- Planning must challenge assumptions, identify gaps, compare options, and produce an implementation-ready plan split by agents.
- After approval, `orchestrator-agent` executes the plan through specialist agents.

## Documentation target

Documentation should live inside the application/repository, not as an external-only site. It should eventually include:

- Backend API documentation.
- Frontend component documentation.
- Design system documentation.
- Usage examples for components and patterns.
- Architecture decisions.
- Refactor notes.

Prefer a simple in-app docs module first. VitePress/Storybook can be proposed only through planning mode if the project benefits from it.

## External knowledge policy

The user may provide articles and links. Agents may use them as inspiration, but must not blindly follow them.

When applying external guidance:
- summarize the concrete principle,
- explain whether it fits Taskio,
- document accepted principles in `docs/ai/reference-links.md` or the appropriate rule file,
- reject or adapt advice that conflicts with the current architecture.

## Mandatory behavior

Before implementation:
- inspect existing code and current conventions,
- identify reusable modules/components/services,
- list files likely to change,
- explain the smallest safe path,
- identify risks.

During implementation:
- keep diffs small,
- do not create parallel implementations when an existing mechanism can be extended,
- do not introduce packages without justification,
- preserve behavior unless a behavior change is explicitly accepted.

After implementation:
- list changed files,
- explain why they changed,
- provide validation commands,
- identify test gaps,
- identify docs updates,
- call `reviewer-agent` for non-trivial work.

## Useful commands

Use these commands only after checking the local environment.

```bash
composer install
npm install
composer run dev
composer test
php artisan test
./vendor/bin/pint
npm run build
npm run test:unit
php artisan boost:update
```

## Safety

- Never edit `.env` secrets.
- Never log passwords, tokens, API keys, session cookies, or sensitive data.
- Never run destructive database commands without explicit approval.
- Never force-push or rewrite Git history without explicit approval.
- Ask before running migrations that modify data.
