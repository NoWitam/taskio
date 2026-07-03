// Unit tests for the pure bot-action presentation helpers (Batch 2 + Batch 4).
// Covers: the type → label-key/icon/tone map (incl. the new B4 types), the
// payload accessors (run / trigger / question — all tolerating a missing payload),
// and `botActionEntryText` (title + run/trigger meta + question/error description).
import { describe, expect, it } from 'vitest';
import {
  botActionMeta,
  botActionEntryText,
  botActionRun,
  botActionTrigger,
  botActionQuestion,
  BOT_ACTION_TYPES,
} from '../botActionMeta';
import type { BotAction } from '../types';

// A deterministic fake translator. Mirrors the real `t(key, fallback?, params?)`:
// with params it interpolates into the TEMPLATE (the fallback string when given —
// as the run-meta calls pass their template there — else the key). This lets the
// run-meta assertions verify interpolation while label keys still echo the key.
function fakeT(key: string, fallback?: string, params?: Record<string, string | number>): string {
  if (!params) return key;
  let out = fallback && fallback !== '' ? fallback : key;
  for (const [k, v] of Object.entries(params)) out = out.replace(`{${k}}`, String(v));
  return out;
}

function action(overrides: Partial<BotAction> = {}): BotAction {
  return {
    id: 'a1',
    bot_id: 'b1',
    task_id: 't1',
    type: 'task_started',
    payload: null,
    status: null,
    error: null,
    created_at: '2026-06-25T10:00:00Z',
    updated_at: '2026-06-25T10:00:00Z',
    ...overrides,
  };
}

describe('botActionMeta — type map', () => {
  it('maps the new Batch 4 types to distinct label keys + icons', () => {
    expect(botActionMeta('question_asked')).toMatchObject({
      i18nKey: 'bots.actions.types.question_asked',
      icon: 'help-circle',
    });
    expect(botActionMeta('resumed').icon).toBe('redo');
    expect(botActionMeta('revision_started').icon).toBe('rotate-ccw');
    expect(botActionMeta('handed_over')).toMatchObject({
      i18nKey: 'bots.actions.types.handed_over',
      icon: 'log-out',
      tone: 'warning',
    });
  });

  it('falls back gracefully for an unknown type', () => {
    expect(botActionMeta('something_new')).toMatchObject({
      i18nKey: 'bots.actions.types.unknown',
      icon: 'sparkles',
    });
  });

  it('BOT_ACTION_TYPES includes every new B4 type', () => {
    for (const t of ['question_asked', 'resumed', 'revision_started', 'handed_over'] as const) {
      expect(BOT_ACTION_TYPES).toContain(t);
    }
  });
});

describe('botActionMeta — payload accessors tolerate missing/foreign payload', () => {
  it('botActionRun reads a numeric run or null', () => {
    expect(botActionRun(action({ payload: { run: 3 } }))).toBe(3);
    expect(botActionRun(action({ payload: null }))).toBeNull();
    expect(botActionRun(action({ payload: { run: 'x' } }))).toBeNull();
  });

  it('botActionTrigger reads a valid trigger or null', () => {
    expect(botActionTrigger(action({ payload: { trigger: 'resume' } }))).toBe('resume');
    expect(botActionTrigger(action({ payload: { trigger: 'nope' } }))).toBeNull();
    expect(botActionTrigger(action({ payload: null }))).toBeNull();
  });

  it('botActionQuestion reads a non-empty question or null', () => {
    expect(botActionQuestion(action({ payload: { question: 'Which tone?' } }))).toBe('Which tone?');
    expect(botActionQuestion(action({ payload: { question: '   ' } }))).toBeNull();
    expect(botActionQuestion(action({ payload: null }))).toBeNull();
  });
});

describe('botActionEntryText', () => {
  it('appends run + trigger meta to a repeated task_started', () => {
    const { title } = botActionEntryText(
      action({ type: 'task_started', payload: { run: 2, trigger: 'revision' } }),
      fakeT,
    );
    // fakeT fills the runMetaTrigger template: "{title} · run #{run} ({trigger})".
    expect(title).toContain('bots.actions.types.task_started');
    expect(title).toContain('2');
    expect(title).toContain('bots.actions.trigger.revision');
  });

  it('uses the plain run meta when no trigger is present', () => {
    const { title } = botActionEntryText(
      action({ type: 'task_started', payload: { run: 5 } }),
      fakeT,
    );
    expect(title).toContain('5');
    expect(title).not.toContain('trigger');
  });

  it('renders the question text as the description for question_asked', () => {
    const { description } = botActionEntryText(
      action({ type: 'question_asked', payload: { question: 'What voice?' } }),
      fakeT,
    );
    expect(description).toBe('What voice?');
  });

  it('renders the error as the description for execution_failed', () => {
    const { description } = botActionEntryText(
      action({ type: 'execution_failed', error: 'boom' }),
      fakeT,
    );
    expect(description).toBe('boom');
  });

  it('has no description for a plain action + tolerates a missing payload', () => {
    const { title, description } = botActionEntryText(action({ type: 'commented', payload: null }), fakeT);
    expect(title).toBe('bots.actions.types.commented');
    expect(description).toBeUndefined();
  });

  // --- tool_used (Batch 5) ------------------------------------------------
  it('tool_used → "Used tool: {label}" title + per-tool icon + host description', () => {
    const { title, description, icon } = botActionEntryText(
      action({ type: 'tool_used', payload: { tool: 'fetch_url', host: 'example.com' } }),
      fakeT,
    );
    // fakeT fills the toolUsedTitle template; {tool} = the fetch_url label key.
    expect(title).toContain('bots.tools.fetch_url.label');
    expect(description).toBe('example.com');
    expect(icon).toBe('link'); // per-tool icon override
  });

  it('tool_used with an unknown tool keeps the raw id + generic icon', () => {
    const { title, description, icon } = botActionEntryText(
      action({ type: 'tool_used', payload: { tool: 'calendar' } }),
      fakeT,
    );
    expect(title).toContain('calendar');
    expect(description).toBeUndefined();
    expect(icon).toBe('settings');
  });

  it('tool_used tolerates a missing payload (no tool id, no override icon)', () => {
    const { title, description, icon } = botActionEntryText(
      action({ type: 'tool_used', payload: null }),
      fakeT,
    );
    expect(title).toContain('bots.tools.unknown');
    expect(description).toBeUndefined();
    expect(icon).toBeUndefined();
  });

  it('BOT_ACTION_TYPES includes tool_used', () => {
    expect(BOT_ACTION_TYPES).toContain('tool_used');
  });
});
