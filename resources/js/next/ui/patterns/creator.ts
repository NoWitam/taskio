// creator — the shared polymorphic "creator" identity (Phase 3).
//
// The backend `creator` field (emitted by Task DETAIL, Form, FormList,
// FormSubmission, FormReport, Workflow, ApprovalPipeline and Bot resources) is a
// DISCRIMINATED UNION tagged by `type`: a User, a workflow_run (automation), or a
// named Bot. The relation may also be null (loaded but empty) or the key OMITTED
// (relation not loaded) — so every resource references it as OPTIONAL and it is
// typed `Creator | null`. `CreatorBadge` renders it, and every module's `types.ts`
// imports THIS union instead of redefining a bespoke user shape.
//
// Mirrors the verified backend shape 1:1 — no invented fields:
//   user         → { type:'user',         id, name, email,   avatar }
//   workflow_run → { type:'workflow_run', id, run_id, label }
//   bot          → { type:'bot',          id, name,           avatar }
//
// The switch is centralized in `creatorIcon` / `creatorLabel` so the 5+ render
// sites agree (and the logic stays unit-testable without mounting a component).
import type { IconName } from '../primitives/icons';

/** A human user (UserResource). `email` is always present for a real user. */
export interface UserCreator {
  type: 'user';
  id: string;
  name: string;
  email: string;
  avatar: string | null;
}

/**
 * A workflow run (automation). `label` is the workflow's name — it may be null
 * when the originating workflow is gone/unnamed, in which case the UI falls back
 * to a generic "Automation" label. `run_id` mirrors the run's uuid (same as `id`).
 */
export interface WorkflowRunCreator {
  type: 'workflow_run';
  id: string;
  run_id: string;
  label: string | null;
}

/** A named bot (Bot resource). Bots have no email; `avatar` is usually null. */
export interface BotCreator {
  type: 'bot';
  id: string;
  name: string;
  avatar: string | null;
}

/** The polymorphic creator identity. Switch on `type`; also handle null/undefined. */
export type Creator = UserCreator | WorkflowRunCreator | BotCreator;

/** The minimal translate signature these helpers need (matches i18n `t`). */
type TranslateFn = (
  key: string,
  defaultValue?: string,
  params?: Record<string, string | number>,
) => string;

/**
 * The leading icon for a creator kind (used in the plain icon+text sites — card
 * metadata footers, drawer footers — and inside `CreatorBadge`'s glyph):
 *   user → `user` · workflow_run → `workflow` · bot → `sparkles` · none → `user`.
 */
export function creatorIcon(creator: Creator | null | undefined): IconName {
  if (!creator) return 'user';
  switch (creator.type) {
    case 'workflow_run':
      return 'workflow';
    case 'bot':
      return 'sparkles';
    case 'user':
    default:
      return 'user';
  }
}

/**
 * The display TEXT for a creator, localized:
 *   user / bot   → the identity `name`,
 *   workflow_run → `common.creator.automation` ("Automatyzacja: {name}") with the
 *                  workflow label, or `automationGeneric` when the label is null,
 *   null/omitted → the caller's `fallback`, or `common.creator.system` ("System").
 * Never returns blank for a present creator (a nameless user/bot degrades to the
 * system fallback rather than an empty string).
 */
export function creatorLabel(
  creator: Creator | null | undefined,
  t: TranslateFn,
  fallback?: string,
): string {
  const systemLabel = fallback ?? t('common.creator.system', 'System');
  if (!creator) return systemLabel;
  switch (creator.type) {
    case 'user':
    case 'bot':
      return creator.name?.trim() || systemLabel;
    case 'workflow_run':
      return creator.label?.trim()
        ? t('common.creator.automation', 'Automation: {name}', { name: creator.label })
        : t('common.creator.automationGeneric', 'Automation');
    default:
      return systemLabel;
  }
}

/** True when the creator renders as a bot (sparkles glyph, primary-subtle tint). */
export function isBotCreator(creator: Creator | null | undefined): creator is BotCreator {
  return !!creator && creator.type === 'bot';
}

/** True when the creator renders as an automation (workflow glyph, info tint). */
export function isAutomationCreator(
  creator: Creator | null | undefined,
): creator is WorkflowRunCreator {
  return !!creator && creator.type === 'workflow_run';
}
