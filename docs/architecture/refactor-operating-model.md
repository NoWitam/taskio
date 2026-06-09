# Taskio Refactor Operating Model

## Purpose

Define how Claude Code agents should help refactor and extend Taskio.

## Default order

1. Planning Mode
2. Backend refactor
3. UX/UI plan
4. Frontend implementation
5. Tests
6. Documentation
7. Review

## Agent responsibilities

- Planning Agent: challenge assumptions and produce approved plans.
- Orchestrator Agent: execute approved plans through specialists.
- Backend Agent: Laravel modular monolith implementation.
- UX/UI Agent: product UX and design system.
- Frontend Agent: Vue/Tailwind implementation.
- Testing Agent: gradual test coverage.
- Documentation Agent: in-app/repository docs.
- Reviewer Agent: final consistency and risk review.

## Definition of done

A non-trivial change is done only when:
- backend behavior is implemented or explicitly not needed,
- UX/UI states are specified,
- frontend uses approved API contracts,
- tests are added or gaps are documented,
- docs are updated or gaps are documented,
- reviewer-agent approves or requests explicit user decision.
