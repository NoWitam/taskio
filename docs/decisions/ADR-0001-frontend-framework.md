# ADR-0001: Keep Vue as the default frontend framework

## Status

Accepted by default; can be revisited only through Planning Mode.

## Context

Taskio currently uses Vue 3, Vue Router, Pinia, Vite, TailwindCSS, and Vitest.

## Decision

Frontend Agent must default to Vue and must not switch to another framework or custom Web Components framework during implementation.

## Consequences

- Refactor complexity is reduced.
- Existing components/modules can be reused.
- Framework migration remains possible later, but only through an explicit architecture decision.
