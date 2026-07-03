// Unit tests for the pure tools-selection recombine used by the bot editor
// (Batch 5). Guards the payload rule: the AVAILABLE tools the user picked land in
// task_execution.tools[], and any UNAVAILABLE id already saved on the bot is
// PRESERVED (never silently dropped) — appended after the picked ones.
import { describe, expect, it } from 'vitest';
import { combineToolSelection } from '../botToolMeta';

// A registry where web_search is unavailable (no API key) and 'legacy_tool' is
// unknown → both count as "unavailable" for preservation purposes.
const AVAILABLE = new Set(['fetch_url', 'generate_file', 'read_attachments']);
const isAvailable = (id: string) => AVAILABLE.has(id);

describe('combineToolSelection', () => {
  it('the picked available tools land in the payload', () => {
    expect(combineToolSelection(['fetch_url', 'generate_file'], [], isAvailable)).toEqual([
      'fetch_url',
      'generate_file',
    ]);
  });

  it('preserves an unavailable saved id (web_search) after the picked ones', () => {
    // The bot had web_search saved (now unavailable). The user picks fetch_url.
    const saved = ['web_search', 'fetch_url'];
    expect(combineToolSelection(['fetch_url'], saved, isAvailable)).toEqual([
      'fetch_url',
      'web_search',
    ]);
  });

  it('preserves an unknown (removed) saved id', () => {
    const saved = ['legacy_tool', 'generate_file'];
    expect(combineToolSelection(['generate_file'], saved, isAvailable)).toEqual([
      'generate_file',
      'legacy_tool',
    ]);
  });

  it('deselecting an available tool drops it but keeps preserved ids', () => {
    const saved = ['fetch_url', 'web_search'];
    // User deselected fetch_url (picked nothing available now).
    expect(combineToolSelection([], saved, isAvailable)).toEqual(['web_search']);
  });

  it('an empty selection with no saved ids yields an empty payload', () => {
    expect(combineToolSelection([], [], isAvailable)).toEqual([]);
  });
});
