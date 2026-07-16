// Shared presentation for bot actions (next, Batch 2).
//
// Maps the backend `BotActionType` enum onto a Timeline node descriptor: an i18n
// label KEY (resolved via t() at the call site so it tracks the locale), a `next`
// IconName, and a node tone. Kept as a pure module (no `<script setup>`) so both
// the Bot detail action-history timeline and the task bot-actions timeline build
// IDENTICAL entries, and the mapping is unit-testable.
import type { IconName } from '../../ui/primitives/icons';
import type { BotAction, BotActionTrigger, BotActionType } from './types';
import { toolIcon, toolLabel, toolUsedId, toolUsedDescription } from './botToolMeta';

type NodeTone = 'neutral' | 'primary' | 'success' | 'warning' | 'danger' | 'info';

interface BotActionMeta {
  /** i18n key for the label (resolve via t()). */
  i18nKey: string;
  icon: IconName;
  tone: NodeTone;
}

// Icons are mapped to the closest available `next` registry icon (the registry
// has no robot/play/send glyphs): start → arrow-right, form → file-text,
// comment → mail, submit-to-test → flag, done → check-circle, failed → alert,
// question → help-circle, resumed → redo, revision → rotate-ccw, handed → log-out,
// tool_used → settings (a per-tool icon overrides this at render — see below).
const BOT_ACTION_META: Record<BotActionType, BotActionMeta> = {
  task_started: { i18nKey: 'bots.actions.types.task_started', icon: 'arrow-right', tone: 'primary' },
  form_filled: { i18nKey: 'bots.actions.types.form_filled', icon: 'file-text', tone: 'info' },
  commented: { i18nKey: 'bots.actions.types.commented', icon: 'mail', tone: 'neutral' },
  submitted_to_test: { i18nKey: 'bots.actions.types.submitted_to_test', icon: 'flag', tone: 'warning' },
  marked_done: { i18nKey: 'bots.actions.types.marked_done', icon: 'check-circle', tone: 'success' },
  execution_failed: { i18nKey: 'bots.actions.types.execution_failed', icon: 'alert-triangle', tone: 'danger' },
  question_asked: { i18nKey: 'bots.actions.types.question_asked', icon: 'help-circle', tone: 'info' },
  resumed: { i18nKey: 'bots.actions.types.resumed', icon: 'redo', tone: 'primary' },
  revision_started: { i18nKey: 'bots.actions.types.revision_started', icon: 'rotate-ccw', tone: 'warning' },
  handed_over: { i18nKey: 'bots.actions.types.handed_over', icon: 'log-out', tone: 'warning' },
  tool_used: { i18nKey: 'bots.actions.types.tool_used', icon: 'settings', tone: 'neutral' },
};

const FALLBACK: BotActionMeta = {
  i18nKey: 'bots.actions.types.unknown',
  icon: 'sparkles',
  tone: 'neutral',
};

/** The Timeline descriptor for a bot-action type (falls back gracefully). */
export function botActionMeta(type: BotActionType | string): BotActionMeta {
  return BOT_ACTION_META[type as BotActionType] ?? FALLBACK;
}

/** All known bot-action types, for the optional `type` filter in the history. */
export const BOT_ACTION_TYPES: BotActionType[] = [
  'task_started',
  'form_filled',
  'tool_used',
  'commented',
  'submitted_to_test',
  'revision_started',
  'question_asked',
  'resumed',
  'handed_over',
  'marked_done',
  'execution_failed',
];

// --- Payload accessors (Batch 4) — all TOLERATE a missing/foreign payload -----

/** Read a numeric `payload.run` (the 1-based run index), or null when absent. */
export function botActionRun(action: Pick<BotAction, 'payload'>): number | null {
  const run = action.payload?.run;
  return typeof run === 'number' ? run : null;
}

/** Read the `payload.trigger` (`initial | resume | revision`), or null when absent. */
export function botActionTrigger(
  action: Pick<BotAction, 'payload'>,
): BotActionTrigger | null {
  const trigger = action.payload?.trigger;
  return trigger === 'initial' || trigger === 'resume' || trigger === 'revision'
    ? trigger
    : null;
}

/** Read the `payload.question` text for a `question_asked` action, or null. */
export function botActionQuestion(action: Pick<BotAction, 'payload'>): string | null {
  const q = action.payload?.question;
  return typeof q === 'string' && q.trim() !== '' ? q : null;
}

/** A minimal translator signature (matches `useI18n().t`). */
type Translate = (key: string, fallback?: string, params?: Record<string, string | number>) => string;

/**
 * Build a Timeline entry's TITLE + DESCRIPTION (+ optional per-tool ICON override)
 * for a bot action, resolving all strings via the caller's `t` so the label
 * follows the locale. Encapsulates the flourishes so BOTH timelines render them
 * identically + tolerate a missing/foreign payload:
 *   • `task_started` with a `payload.run` appends " · run #N (start|resume|revision)".
 *   • `question_asked` uses `payload.question` (truncated) as the description.
 *   • `execution_failed` uses the action's `error` (or a generic fallback).
 *   • `tool_used` (Batch 5) → title "Used tool: {label}" (raw id when unknown) +
 *     a per-tool description (host / query+results / file / list-read); the `icon`
 *     override points at the specific tool's glyph.
 * Non-descriptive actions have no description (undefined).
 */
export function botActionEntryText(
  action: Pick<BotAction, 'type' | 'payload' | 'error'>,
  t: Translate,
): { title: string; description: string | undefined; icon?: IconName } {
  const meta = botActionMeta(action.type);
  let title = t(meta.i18nKey, action.type);
  let icon: IconName | undefined;

  // Append run + trigger meta to a repeated task_started (or any run-tagged row).
  const run = botActionRun(action);
  if (run != null) {
    const trigger = botActionTrigger(action);
    const triggerLabel = trigger
      ? t(`bots.actions.trigger.${trigger}`, trigger)
      : '';
    title = triggerLabel
      ? t('bots.actions.runMetaTrigger', '{title} · run #{run} ({trigger})', {
          title,
          run,
          trigger: triggerLabel,
        })
      : t('bots.actions.runMeta', '{title} · run #{run}', { title, run });
  }

  let description: string | undefined;
  if (action.type === 'question_asked') {
    description = botActionQuestion(action) ?? undefined;
  } else if (action.type === 'execution_failed') {
    description = action.error ?? t('bots.actions.failedNoDetail', 'The bot run failed.');
  } else if (action.type === 'tool_used') {
    const toolId = toolUsedId(action);
    // "Used tool: {label}" — the raw id when the tool is unknown (never dropped).
    title = t('bots.actions.toolUsedTitle', 'Used tool: {tool}', {
      tool: toolId ? toolLabel(toolId, t) : t('bots.tools.unknown', 'a tool'),
    });
    description = toolUsedDescription(action, t);
    if (toolId) icon = toolIcon(toolId);
  }

  return { title, description, icon };
}
