// Unit tests for the pure bot-tool presentation helpers (Batch 5): the known-tool
// guard, label/description/icon resolution (unknown id → raw id, generic icon),
// and the `tool_used` payload accessors + per-tool description building (all
// tolerating a missing/foreign payload).
import { describe, expect, it } from 'vitest';
import {
  isKnownTool,
  toolIcon,
  toolLabel,
  toolDescription,
  toolUsedId,
  toolUsedDescription,
} from '../botToolMeta';
import type { BotAction } from '../types';

// A deterministic fake translator. With params it fills the fallback TEMPLATE (as
// the toolUsed calls pass their template there), else echoes the key.
function fakeT(key: string, fallback?: string, params?: Record<string, string | number>): string {
  if (!params) return key;
  let out = fallback && fallback !== '' ? fallback : key;
  for (const [k, v] of Object.entries(params)) out = out.replace(`{${k}}`, String(v));
  return out;
}

function action(payload: Record<string, unknown> | null): Pick<BotAction, 'payload'> {
  return { payload };
}

describe('botToolMeta — id map', () => {
  it('recognizes the four known tool ids', () => {
    for (const id of ['fetch_url', 'web_search', 'generate_file', 'read_attachments']) {
      expect(isKnownTool(id)).toBe(true);
    }
    expect(isKnownTool('calendar')).toBe(false);
  });

  it('maps known ids to distinct icons; unknown → settings', () => {
    expect(toolIcon('fetch_url')).toBe('link');
    expect(toolIcon('web_search')).toBe('search');
    expect(toolIcon('generate_file')).toBe('file-text');
    expect(toolIcon('read_attachments')).toBe('download');
    expect(toolIcon('calendar')).toBe('settings');
  });

  it('resolves label keys for known ids and the RAW id for unknown', () => {
    expect(toolLabel('fetch_url', fakeT)).toBe('bots.tools.fetch_url.label');
    expect(toolLabel('calendar', fakeT)).toBe('calendar');
  });

  it('resolves a description key for known ids and empty for unknown', () => {
    expect(toolDescription('web_search', fakeT)).toBe('bots.tools.web_search.description');
    expect(toolDescription('calendar', fakeT)).toBe('');
  });
});

describe('botToolMeta — tool_used payload', () => {
  it('reads the tool id or null', () => {
    expect(toolUsedId(action({ tool: 'fetch_url' }))).toBe('fetch_url');
    expect(toolUsedId(action({}))).toBeNull();
    expect(toolUsedId(action(null))).toBeNull();
  });

  it('fetch_url → the host (plain text, prefers host over url)', () => {
    expect(toolUsedDescription(action({ tool: 'fetch_url', host: 'example.com', url: 'https://example.com/x' }), fakeT))
      .toBe('example.com');
    // Falls back to url when host absent.
    expect(toolUsedDescription(action({ tool: 'fetch_url', url: 'https://example.com/x' }), fakeT))
      .toBe('https://example.com/x');
  });

  it('web_search → query + results, query-only, or results-only', () => {
    expect(toolUsedDescription(action({ tool: 'web_search', query: 'pizza', results: 7 }), fakeT))
      .toBe('pizza · 7 results');
    expect(toolUsedDescription(action({ tool: 'web_search', query: 'pizza' }), fakeT)).toBe('pizza');
    expect(toolUsedDescription(action({ tool: 'web_search', results: 3 }), fakeT)).toBe('3 results');
  });

  it('generate_file → the file name', () => {
    expect(toolUsedDescription(action({ tool: 'generate_file', file: 'report.pdf' }), fakeT)).toBe('report.pdf');
  });

  it('read_attachments → verb · detail (file or count)', () => {
    // fakeT echoes the key when no params are passed, so the verb resolves to the
    // action* KEY here; production resolves it to the localized "read"/"list".
    expect(toolUsedDescription(action({ tool: 'read_attachments', action: 'read', file: 'brief.docx' }), fakeT))
      .toBe('bots.tools.toolUsed.actionRead · brief.docx');
    expect(toolUsedDescription(action({ tool: 'read_attachments', action: 'list', count: 4 }), fakeT))
      .toBe('bots.tools.toolUsed.actionList · 4');
  });

  it('read_attachments with an UNKNOWN action passes the raw action string through', () => {
    expect(toolUsedDescription(action({ tool: 'read_attachments', action: 'delete', count: 1 }), fakeT))
      .toBe('delete · 1');
  });

  it('tolerates a missing/foreign payload (undefined description)', () => {
    expect(toolUsedDescription(action(null), fakeT)).toBeUndefined();
    expect(toolUsedDescription(action({ tool: 'fetch_url' }), fakeT)).toBeUndefined();
    expect(toolUsedDescription(action({ tool: 'unknown' }), fakeT)).toBeUndefined();
  });
});
