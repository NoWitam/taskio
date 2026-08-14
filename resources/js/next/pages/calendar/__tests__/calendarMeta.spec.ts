// calendarMeta.spec — the three maps, and specifically their FALLBACKS.
//
// Every map here will one day be asked about a value that did not exist when it was
// written: R4 Publishing adds a fifth source and, with it, a fifth `subject.type`. The
// fallbacks are the whole reason "a new source needs no frontend change" is true, so they
// are tested at least as carefully as the known values.
//
// The truncation half is the other headline: an unknown count must select a different KEY,
// never the number zero.
import { describe, it, expect } from 'vitest';
import {
  colorBadgeVariant,
  colorTokens,
  normalizeColor,
  sourceIcon,
  subjectLink,
  truncationCount,
  truncationIcon,
  truncationKey,
  UNKNOWN_SOURCE_ICON,
} from '../calendarMeta';
import { CALENDAR_COLORS } from '../types';

describe('colours', () => {
  it('maps every value of the closed vocabulary to literal token classes', () => {
    for (const color of CALENDAR_COLORS) {
      const tokens = colorTokens(color);
      expect(tokens.bar).toMatch(/^bg-next-/);
      expect(tokens.surface).toContain('text-next-');
      // Literal strings, never assembled at runtime: Tailwind v4 only emits classes it can
      // see in the source, so `bg-next-${color}-subtle` would resolve to nothing at all.
      expect(tokens.surface).not.toContain('${');
    }
  });

  it('treats a null / unknown colour as neutral rather than as no class', () => {
    // The vocabulary may GROW server-side, and the map is asked about whatever arrives on
    // `CalendarOccurrence.color`. An unrecognised value must land on real classes rather
    // than on an empty string, which renders as an invisible chip.
    expect(normalizeColor(null)).toBe('neutral');
    expect(normalizeColor(undefined)).toBe('neutral');
    expect(normalizeColor('chartreuse')).toBe('neutral');
    expect(colorTokens(null).bar).toBe(colorTokens('neutral').bar);
  });

  it('derives the Badge variant without string concatenation', () => {
    expect(colorBadgeVariant('danger')).toBe('danger');
    expect(colorBadgeVariant('nonsense')).toBe('neutral');
  });
});

describe('source icons', () => {
  it('gives each known source its own glyph', () => {
    const known = ['task', 'workflow_schedule', 'workflow_run', 'event'].map(sourceIcon);
    expect(new Set(known).size).toBe(4);
    expect(known).not.toContain(UNKNOWN_SOURCE_ICON);
  });

  it('falls back for a source this build has never heard of', () => {
    // The R4 case, and the reason the filter chips and legend can be built from
    // `meta.sources` alone.
    expect(sourceIcon('publication')).toBe(UNKNOWN_SOURCE_ICON);
    expect(sourceIcon('')).toBe(UNKNOWN_SOURCE_ICON);
  });
});

describe('subject deep links', () => {
  it('opens a task in its own drawer', () => {
    expect(subjectLink({ type: 'task', id: 'abc' })).toEqual({
      to: { path: '/tasks', query: { task: 'abc' } },
      kind: 'open',
    });
  });

  it('opens a workflow through the workflows layout', () => {
    expect(subjectLink({ type: 'workflow', id: 'wf-1' })?.to).toEqual({
      path: '/workflows',
      query: { workflow: 'wf-1' },
    });
  });

  it('only promises the runs LIST for a run — there is no URL that opens one', () => {
    // `?run=` on the workflows layout is the run-NOW picker and its param is a WORKFLOW id;
    // the real run-detail link (`?run_detail=`) is nested under a workflow id an occurrence
    // does not carry. So the action is worded for what it actually reaches.
    const link = subjectLink({ type: 'workflow_run', id: 'run-1' });
    expect(link?.kind).toBe('list');
    expect(link?.to).toEqual({ path: '/workflows/runs' });
  });

  it('returns null for an event — it opens in place, not by navigating away', () => {
    expect(subjectLink({ type: 'calendar_event', id: 'e1' })).toBeNull();
  });

  it('returns null for an UNKNOWN alias instead of building a dead link', () => {
    expect(subjectLink({ type: 'publication', id: 'p1' })).toBeNull();
    expect(subjectLink(null)).toBeNull();
    expect(subjectLink({ type: 'task', id: '' })).toBeNull();
  });
});

describe('truncation copy selection', () => {
  it('selects a DIFFERENT key when the count is unknown — never the number zero', () => {
    expect(truncationKey('window_trimmed', 5)).toBe('calendar.truncation.windowTrimmed.withCount');
    expect(truncationKey('window_trimmed', null)).toBe('calendar.truncation.windowTrimmed.unknown');
    expect(truncationKey('items_dropped', 2)).toBe('calendar.truncation.itemsDropped.withCount');
    expect(truncationKey('items_dropped', null)).toBe('calendar.truncation.itemsDropped.unknown');
    expect(truncationKey('item_densified', 1)).toBe('calendar.truncation.itemDensified.withCount');
    expect(truncationKey('item_densified', null)).toBe('calendar.truncation.itemDensified.unknown');
  });

  it('treats a REAL zero as a count, not as unknown', () => {
    // The backend never sends 0, but if it ever did, 0 is a number and must not be
    // laundered into the "we do not know" sentence.
    expect(truncationKey('window_trimmed', 0)).toBe('calendar.truncation.windowTrimmed.withCount');
  });

  it('returns no key at all for an unknown kind, so the row is dropped rather than mis-worded', () => {
    expect(truncationKey('something_new', 3)).toBe('');
  });

  it('reads the count from the field the kind actually populates', () => {
    expect(truncationCount('window_trimmed', 7, null)).toBe(7);
    expect(truncationCount('item_densified', null, 3)).toBe(3);
    expect(truncationCount('items_dropped', null, null)).toBeNull();
    // The contract allows null anywhere, so a null in the expected field stays null.
    expect(truncationCount('window_trimmed', null, 9)).toBeNull();
  });

  it('gives the alarming glyph ONLY to the loss a user can act wrongly on', () => {
    expect(truncationIcon('items_dropped')).toBe('alert-triangle');
    expect(truncationIcon('window_trimmed')).toBe('info');
    expect(truncationIcon('item_densified')).toBe('info');
  });
});
