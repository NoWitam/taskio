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

### The queue worker does NOT reload code

`php artisan queue:work` boots the application once and keeps it in memory for the life of the
process. **After editing anything a queued job touches — a module service, an agent's prompt, an enum
— a running worker goes on executing the code as it was when it started.** Nothing warns you: the job
still succeeds, against a snapshot.

This has already cost a full diagnostic cycle. A worker left running for sixteen hours was still
executing a pre-graph build of the Knowledge module: every session it processed stored `graph_ops` as
NULL — which the code on disk cannot do — and the module looked broken from every angle except the
right one.

So after changing backend code, and before testing anything through the UI:

```bash
php artisan queue:restart          # running workers finish their current job, then exit
# then start a fresh one, e.g.:
setsid nohup php artisan queue:work --tries=1 > storage/logs/queue-worker.log 2>&1 < /dev/null &
```

The symptom to recognise: **the tests pass, but the same operation through the UI behaves like an
older version of the code.** Tests run in-process and always see your edits; the worker does not.

### Tests read the developer's `.env`

There is no `.env.testing`, so `php artisan test` inherits whatever is in `.env` — feature flags
included. A test that exercises one side of a flag must SET that flag itself, or it passes or fails
according to what somebody last switched on by hand. When a suite goes red after an unrelated `.env`
change, fix the test's assumption, never the mechanism.

**Concrete case:** `KNOWLEDGE_GRAPH_EXTRACTION_ENABLED` in `.env` picks which branch of
`KnowledgeDraftSessionService::freezeContext()` a drafting session freezes its context from — the
entity-resolution pass when true, the older plain-retrieval pass when false. Six tests assumed the
flag's DEFAULT (`false` in `config/knowledge.php`) and went red the moment a developer's own `.env` set
it to `true` to exercise the Wiki-Graf work — not because the tests or the flag were broken, but because
neither side named which branch it needed. Any test that cares which of the two passes ran must set
`config(['knowledge.graph_extraction.enabled' => …])` explicitly rather than relying on whatever the
environment happens to have.

### Tests behind an env flag do not run, and do not tell you they are stale

`TENANT_DB_TESTS=1` and `KNOWLEDGE_HEAVY_TESTS=1` gate real DDL and heavy fixtures. The default suite
still *loads* those classes — they show as "skipped" — so a parse error or a dead import is caught. What
is NOT caught is a method body scripting a contract that has moved on: `KnowledgeTenantComposerTest`
scripted an obsolete composer contract for days and no run ever went red.

Run `TENANT_DB_TESTS=1 php artisan test --filter=Tenant` **by hand** before committing changes to the
composer's queued path, to tenancy plumbing, or whenever a shared-mode test had to be edited to follow a
contract change — its own-database twin scripts the same contract and will not tell you it rotted. Note
that these tests issue `CREATE DATABASE`/`DROP DATABASE`, so they need the owner's approval per the
Safety rules below. When CI exists, this belongs in a **nightly** job, not the per-push run.

## Safety

- Never edit `.env` secrets.
- Never log passwords, tokens, API keys, session cookies, or sensitive data.
- Never run destructive database commands without explicit approval.
- Never force-push or rewrite Git history without explicit approval.
- Ask before running migrations that modify data.
