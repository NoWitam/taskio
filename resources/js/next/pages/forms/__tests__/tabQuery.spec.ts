// Unit tests for the bucket-tab (Active/Trash) ↔ `?tab=` sync helpers shared by
// FormSubmissionsView + FormReportsView: hydration (unknown → default) and
// serialization (omit the default, preserve every foreign query key).
import { describe, expect, it } from 'vitest';
import { hydrateTab, serializeTabQuery } from '../tabQuery';

describe('forms tabQuery sync', () => {
  it('hydrates the trash bucket from ?tab=trash', () => {
    expect(hydrateTab({ tab: 'trash' })).toBe('trash');
  });

  it('falls back to the default bucket for a missing/unknown value', () => {
    expect(hydrateTab({})).toBe('active');
    expect(hydrateTab({ tab: 'active' })).toBe('active');
    expect(hydrateTab({ tab: 'bogus' })).toBe('active');
    // Router hands arrays for repeated keys → the first entry wins.
    expect(hydrateTab({ tab: ['trash', 'active'] })).toBe('trash');
  });

  it('serializes trash as ?tab=trash while keeping every foreign key', () => {
    expect(serializeTabQuery({ fill: '42', edit: '7' }, 'trash')).toEqual({
      fill: '42',
      edit: '7',
      tab: 'trash',
    });
  });

  it('omits the default bucket from the query (drops any stale tab key)', () => {
    expect(serializeTabQuery({ fill: '42', tab: 'trash' }, 'active')).toEqual({ fill: '42' });
    expect(serializeTabQuery({}, 'active')).toEqual({});
  });

  it('does not mutate the source query object', () => {
    const source = { fill: '42', tab: 'trash' };
    serializeTabQuery(source, 'active');
    expect(source).toEqual({ fill: '42', tab: 'trash' });
  });
});
