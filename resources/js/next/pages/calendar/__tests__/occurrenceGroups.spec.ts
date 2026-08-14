// occurrenceGroups.spec — day bucketing and dense-series folding.
//
// The first block is the single most important test on this screen: an ALL-DAY occurrence
// must land on the day it names, in every zone on earth. `start_date` is a plain calendar
// day with no zone; the moment anything hands it to `new Date()` it becomes a UTC instant,
// and rendering that instant east or west of UTC moves a task deadline by a day. The bug is
// invisible in a UTC-ish browser and reported by users half a world away.
import { describe, it, expect } from 'vitest';
import { bucketByDay, collapseDense, dayOf, groupMembers, splitOverflow } from '../occurrenceGroups';
import type { CalendarOccurrence } from '../types';

function allDay(id: string, date: string, subjectId = id): CalendarOccurrence {
  return {
    id,
    source: 'task',
    editable: false,
    all_day: true,
    start_date: date,
    starts_at: null,
    ends_at: null,
    title: id,
    color: 'neutral',
    badge: null,
    dense: false,
    cadence_label: null,
    subject: { type: 'task', id: subjectId },
  };
}

function timed(
  id: string,
  startsAt: string,
  { dense = false, subjectId = id }: { dense?: boolean; subjectId?: string } = {},
): CalendarOccurrence {
  return {
    id,
    source: 'workflow_schedule',
    editable: false,
    all_day: false,
    start_date: null,
    starts_at: startsAt,
    ends_at: null,
    title: id,
    color: 'info',
    badge: null,
    dense,
    subject: { type: 'workflow', id: subjectId },
  };
}

describe('dayOf — the all-day invariant', () => {
  it('returns an all-day occurrence’s OWN date, unconverted, in an extreme zone', () => {
    const occurrence = allDay('deadline', '2026-08-09');
    // UTC+14. `new Date('2026-08-09')` is midnight UTC, which here is 14:00 on the 9th —
    // but a naive round trip through a western zone would land on the 8th. The day is not
    // converted at all, so every zone gives the same answer.
    expect(dayOf(occurrence, 'Pacific/Kiritimati')).toBe('2026-08-09');
    expect(dayOf(occurrence, 'Pacific/Niue')).toBe('2026-08-09'); // UTC-11
    expect(dayOf(occurrence, 'UTC')).toBe('2026-08-09');
  });

  it('reads a TIMED occurrence in the workspace zone, where the day genuinely differs', () => {
    const occurrence = timed('run', '2026-08-09T22:30:00Z');
    expect(dayOf(occurrence, 'Pacific/Kiritimati')).toBe('2026-08-10');
    expect(dayOf(occurrence, 'UTC')).toBe('2026-08-09');
  });

  it('returns null for an occurrence carrying neither shape, rather than guessing', () => {
    const broken = { ...timed('x', '2026-08-09T10:00:00Z'), starts_at: null };
    expect(dayOf(broken, 'UTC')).toBeNull();
  });
});

describe('bucketByDay', () => {
  it('groups by day and PRESERVES the server’s order inside each day', () => {
    // The server orders all-day before timed, then by time. Re-sorting would drop that.
    const list = [
      allDay('deadline', '2026-08-09'),
      timed('nine', '2026-08-09T09:00:00Z'),
      timed('fourteen', '2026-08-09T14:00:00Z'),
      allDay('other', '2026-08-10'),
    ];
    const buckets = bucketByDay(list, 'UTC');
    expect([...buckets.keys()]).toEqual(['2026-08-09', '2026-08-10']);
    expect(buckets.get('2026-08-09')?.map((o) => o.id)).toEqual(['deadline', 'nine', 'fourteen']);
  });

  it('drops an occurrence with no resolvable day instead of bucketing it under undefined', () => {
    const broken = { ...timed('x', '2026-08-09T10:00:00Z'), starts_at: null };
    expect(bucketByDay([broken], 'UTC').size).toBe(0);
  });
});

describe('collapseDense', () => {
  it('folds a dense series into ONE row that says how many it stands for', () => {
    const series = [
      timed('s1', '2026-08-09T09:00:00Z', { dense: true, subjectId: 'wf-1' }),
      timed('s2', '2026-08-09T09:05:00Z', { dense: true, subjectId: 'wf-1' }),
      timed('s3', '2026-08-09T09:10:00Z', { dense: true, subjectId: 'wf-1' }),
    ];
    const rows = collapseDense(series);
    expect(rows).toHaveLength(1);
    expect(rows[0].occurrence.id).toBe('s1'); // the FIRST — it carries the earliest time
    expect(rows[0].shown).toBe(3);
    expect(rows[0].folded).toBe(true);
  });

  it('folds a group when ANY member is flagged dense, not only the flagged ones', () => {
    const rows = collapseDense([
      timed('s1', '2026-08-09T09:00:00Z', { subjectId: 'wf-1' }),
      timed('s2', '2026-08-09T09:05:00Z', { dense: true, subjectId: 'wf-1' }),
    ]);
    expect(rows).toHaveLength(1);
    expect(rows[0].shown).toBe(2);
  });

  it('leaves ORDINARY repeats alone — three real meetings are three real rows', () => {
    const rows = collapseDense([
      timed('a', '2026-08-09T09:00:00Z', { subjectId: 'wf-1' }),
      timed('b', '2026-08-09T11:00:00Z', { subjectId: 'wf-1' }),
      timed('c', '2026-08-09T13:00:00Z', { subjectId: 'wf-1' }),
    ]);
    expect(rows.map((r) => r.occurrence.id)).toEqual(['a', 'b', 'c']);
    expect(rows.every((r) => !r.folded && r.shown === 1)).toBe(true);
  });

  it('folds each subject separately and keeps the surrounding order', () => {
    const rows = collapseDense([
      allDay('deadline', '2026-08-09'),
      timed('s1', '2026-08-09T09:00:00Z', { dense: true, subjectId: 'wf-1' }),
      timed('s2', '2026-08-09T09:05:00Z', { dense: true, subjectId: 'wf-1' }),
      timed('t1', '2026-08-09T10:00:00Z', { dense: true, subjectId: 'wf-2' }),
    ]);
    expect(rows.map((r) => r.occurrence.id)).toEqual(['deadline', 's1', 't1']);
    expect(rows.map((r) => r.shown)).toEqual([1, 2, 1]);
  });

  it('groups by SUBJECT id, so two different automations never merge', () => {
    const rows = collapseDense([
      timed('s1', '2026-08-09T09:00:00Z', { dense: true, subjectId: 'wf-1' }),
      timed('t1', '2026-08-09T09:01:00Z', { dense: true, subjectId: 'wf-2' }),
    ]);
    expect(rows).toHaveLength(2);
  });
});

describe('groupMembers', () => {
  it('returns the whole unfolded series behind a folded row', () => {
    const list = [
      timed('s1', '2026-08-09T09:00:00Z', { dense: true, subjectId: 'wf-1' }),
      timed('other', '2026-08-09T09:30:00Z', { subjectId: 'wf-9' }),
      timed('s2', '2026-08-09T09:05:00Z', { dense: true, subjectId: 'wf-1' }),
    ];
    expect(groupMembers(list, list[0]).map((o) => o.id)).toEqual(['s1', 's2']);
  });
});

describe('splitOverflow', () => {
  it('counts the overflow AFTER folding, so "+N more" matches what the day sheet holds', () => {
    // Five raw occurrences, three of which are one dense series → three rows. With room
    // for two, the counter must say +1 — counting the raw five would promise +3.
    const raw = [
      allDay('deadline', '2026-08-09'),
      timed('s1', '2026-08-09T09:00:00Z', { dense: true, subjectId: 'wf-1' }),
      timed('s2', '2026-08-09T09:05:00Z', { dense: true, subjectId: 'wf-1' }),
      timed('s3', '2026-08-09T09:10:00Z', { dense: true, subjectId: 'wf-1' }),
      timed('late', '2026-08-09T18:00:00Z', { subjectId: 'wf-3' }),
    ];
    const rows = collapseDense(raw);
    expect(rows).toHaveLength(3);
    const { visible, overflow } = splitOverflow(rows, 2);
    expect(visible.map((r) => r.occurrence.id)).toEqual(['deadline', 's1']);
    expect(overflow).toBe(1);
  });

  it('reports no overflow when everything fits', () => {
    const rows = collapseDense([allDay('a', '2026-08-09')]);
    expect(splitOverflow(rows, 3)).toEqual({ visible: rows, overflow: 0 });
  });

  it('treats a zero limit as "show nothing here" without a negative count', () => {
    const rows = collapseDense([allDay('a', '2026-08-09')]);
    expect(splitOverflow(rows, 0).overflow).toBe(0);
  });
});
