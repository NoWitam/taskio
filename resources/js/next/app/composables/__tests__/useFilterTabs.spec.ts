// @vitest-environment happy-dom
// Unit tests for the useFilterTabs composable — the saved-views orchestration
// contract: trit-state per chip (tab-active / extra / tab-disabled) including
// PER-VALUE for multi-value groups, dirty-state (order-insensitive), and the
// D1/D2/D3 decisions exercised through realistic page adapters:
//   D1 — dates serialize to null | {mode:'absolute',date} | {mode:'relative',offset}
//        where offset = date - today (days); apply expands relative → today+offset;
//        presets are stored verbatim; absolute keeps the date.
//   D2 — `search` participates in dirty + renders as a scalar chip.
//   D3 — the bucket (Active/Archive/Trash) is NEVER serialized (view state only),
//        so changing it never changes the snapshot/dirty.
//
// The page owns its filter shape; here we model a representative one and supply
// the four pure adapters the composable expects. The api singleton is mocked so
// store reads stay offline.
import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { reactive } from 'vue';

vi.mock('../../lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}));

import { api } from '../../lib/api';
import { useFilterTabs, type FilterSnapshot, type UseFilterTabsAdapters } from '../useFilterTabs';
import { useFilterTabsStore, type FilterTab } from '../../stores/filterTabs';
import type { ActiveFilter } from '../../../ui/patterns/FilterBar.vue';

const apiMock = api as unknown as { put: ReturnType<typeof vi.fn>; post: ReturnType<typeof vi.fn> };

// A fixed "today" so relative-date math is deterministic. 2026-06-20.
const TODAY = new Date(2026, 5, 20);
const MS_PER_DAY = 86_400_000;

function ymd(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
function parseYmd(s: string): Date {
  const [y, m, d] = s.split('-').map(Number);
  return new Date(y, m - 1, d);
}
function dayDiff(a: Date, b: Date): number {
  // whole-day difference a - b (both treated as local midnights)
  const am = new Date(a.getFullYear(), a.getMonth(), a.getDate()).getTime();
  const bm = new Date(b.getFullYear(), b.getMonth(), b.getDate()).getTime();
  return Math.round((am - bm) / MS_PER_DAY);
}

/** A page's live filter state (the composable never sees this directly). */
interface PageState {
  search: string;
  priority: string | null;
  labels: number[]; // multi-value set
  // D1 date: either a preset string, an absolute YYYY-MM-DD, or null.
  datePreset: string | null;
  dateAbsolute: string | null;
  // D3 bucket — pure view state, must NOT enter the snapshot.
  bucket: 'active' | 'archive' | 'trash';
}

function makePage() {
  const state = reactive<PageState>({
    search: '',
    priority: null,
    labels: [],
    datePreset: null,
    dateAbsolute: null,
    bucket: 'active',
  });

  const adapters: UseFilterTabsAdapters = {
    serialize(): FilterSnapshot {
      const snap: FilterSnapshot = {};
      if (state.search) snap.search = state.search;
      if (state.priority) snap.priority = state.priority;
      if (state.labels.length) snap.labels = [...state.labels];
      // D1: preset stored verbatim; absolute date → relative offset from today.
      if (state.datePreset) {
        snap.date = { mode: 'preset', preset: state.datePreset };
      } else if (state.dateAbsolute) {
        snap.date = { mode: 'relative', offset: dayDiff(parseYmd(state.dateAbsolute), TODAY) };
      }
      // D3: bucket deliberately NOT serialized.
      return snap;
    },
    apply(snapshot: FilterSnapshot): void {
      state.search = (snapshot.search as string) ?? '';
      state.priority = (snapshot.priority as string) ?? null;
      state.labels = Array.isArray(snapshot.labels) ? [...(snapshot.labels as number[])] : [];
      state.datePreset = null;
      state.dateAbsolute = null;
      const date = snapshot.date as { mode: string; preset?: string; offset?: number } | undefined;
      if (date?.mode === 'preset') {
        state.datePreset = date.preset ?? null;
      } else if (date?.mode === 'relative') {
        const d = new Date(TODAY.getTime() + (date.offset ?? 0) * MS_PER_DAY);
        state.dateAbsolute = ymd(d);
      }
      // bucket untouched (view state).
    },
    normalize(snapshot: FilterSnapshot): string[] {
      const keys: string[] = [];
      if (snapshot.search) keys.push('search');
      if (snapshot.priority) keys.push('priority');
      const labels = snapshot.labels as number[] | undefined;
      if (labels?.length) [...labels].sort((a, b) => a - b).forEach((id) => keys.push(`labels:${id}`));
      if (snapshot.date) keys.push('date');
      return keys;
    },
    restoreValue(key: string, snapshot: FilterSnapshot): void {
      if (key === 'search') state.search = (snapshot.search as string) ?? '';
      else if (key === 'priority') state.priority = (snapshot.priority as string) ?? null;
      else if (key.startsWith('labels:')) {
        const id = Number(key.slice('labels:'.length));
        if (!state.labels.includes(id)) state.labels = [...state.labels, id].sort((a, b) => a - b);
      } else if (key === 'date') {
        adapters.apply({ ...adapters.serialize(), date: snapshot.date });
      }
    },
  };

  return { state, adapters };
}

/** Seed the store with one saved view for `tasks` and return it. */
function seedActiveTab(filters: FilterSnapshot, id: string | number = 'v1'): FilterTab {
  const store = useFilterTabsStore();
  const t: FilterTab = { id, name: 'View', icon: null, filters, sort_order: 0 };
  store.tabsByContext.tasks = [t];
  return t;
}

describe('useFilterTabs', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();
    vi.useFakeTimers();
    vi.setSystemTime(TODAY);
  });
  afterEach(() => {
    vi.useRealTimers();
  });

  describe('trit-state (scalars) via decorateActiveFilters', () => {
    it('classifies scalars as tab-active / extra / tab-disabled', () => {
      const { state, adapters } = makePage();
      const tab = seedActiveTab({ search: 'foo', priority: 'high' }); // snapshot has search+priority
      const ft = useFilterTabs('tasks', adapters);
      ft.applyTab(tab);

      // Live: keep `search` (→ tab-active), drop `priority` (→ tab-disabled),
      // add `labels:1` (→ extra).
      state.priority = null;
      state.labels = [1];

      const decorated = ft.decorateActiveFilters([
        { key: 'search', label: 'Search: foo' },
        { key: 'labels', values: [{ key: 'labels:1', label: 'Bug' }] },
      ]);

      const flat = new Map<string, ActiveFilter['tabState']>();
      for (const f of decorated) {
        if (f.values?.length) f.values.forEach((v) => flat.set(v.key, v.tabState));
        else flat.set(f.key, f.tabState);
      }
      expect(flat.get('search')).toBe('tab-active');
      expect(flat.get('labels:1')).toBe('extra');
      // priority is snapshot-only → injected as a tab-disabled chip.
      expect(flat.get('priority')).toBe('tab-disabled');
    });

    it('returns the list UNCHANGED when there is no active view', () => {
      const { adapters } = makePage();
      const ft = useFilterTabs('tasks', adapters);
      const input: ActiveFilter[] = [{ key: 'search', label: 'Search: foo' }];
      const out = ft.decorateActiveFilters(input);
      expect(out).toEqual(input);
      expect(out[0].tabState).toBeUndefined();
    });
  });

  describe('trit-state (multi-value, per value)', () => {
    it('classifies each label value independently', () => {
      const { state, adapters } = makePage();
      // snapshot has labels {1,2}
      const tab = seedActiveTab({ labels: [1, 2] });
      const ft = useFilterTabs('tasks', adapters);
      ft.applyTab(tab);

      // Live: keep 1 (tab-active), drop 2 (tab-disabled), add 3 (extra).
      state.labels = [1, 3];

      const decorated = ft.decorateActiveFilters([
        {
          key: 'labels',
          values: [
            { key: 'labels:1', label: 'One' },
            { key: 'labels:3', label: 'Three' },
          ],
        },
      ]);

      const flat = new Map<string, ActiveFilter['tabState']>();
      for (const f of decorated) {
        if (f.values?.length) f.values.forEach((v) => flat.set(v.key, v.tabState));
        else flat.set(f.key, f.tabState);
      }
      expect(flat.get('labels:1')).toBe('tab-active');
      expect(flat.get('labels:3')).toBe('extra');
      // labels:2 was removed → injected as a tab-disabled chip.
      expect(flat.get('labels:2')).toBe('tab-disabled');
    });
  });

  describe('dirty-state', () => {
    it('is false right after applying a view', () => {
      const { adapters } = makePage();
      const tab = seedActiveTab({ search: 'foo', labels: [1, 2] });
      const ft = useFilterTabs('tasks', adapters);
      ft.applyTab(tab);
      expect(ft.dirty.value).toBe(false);
    });

    it('reordering a multi-value set does NOT produce dirty', () => {
      const { state, adapters } = makePage();
      const tab = seedActiveTab({ labels: [1, 2, 3] });
      const ft = useFilterTabs('tasks', adapters);
      ft.applyTab(tab);

      // same set, different order
      state.labels = [3, 1, 2];
      expect(ft.dirty.value).toBe(false);
    });

    it('changing a scalar / adding / removing a value flips dirty, and reverting clears it', () => {
      const { state, adapters } = makePage();
      const tab = seedActiveTab({ search: 'foo', labels: [1] });
      const ft = useFilterTabs('tasks', adapters);
      ft.applyTab(tab);
      expect(ft.dirty.value).toBe(false);

      state.search = 'bar'; // scalar change keeps the SAME key → not dirty by key-set
      expect(ft.dirty.value).toBe(false); // key-set unchanged ('search' still present)

      state.labels = [1, 2]; // add a value → new key
      expect(ft.dirty.value).toBe(true);

      state.labels = [1]; // revert
      expect(ft.dirty.value).toBe(false);

      state.labels = []; // remove a value → key gone
      expect(ft.dirty.value).toBe(true);

      state.labels = [1]; // revert
      expect(ft.dirty.value).toBe(false);
    });
  });

  describe('D1 — date encoding/decoding', () => {
    it('serialize encodes an absolute date as a relative offset = date - today', async () => {
      const { state, adapters } = makePage();
      seedActiveTab({});
      const ft = useFilterTabs('tasks', adapters);

      // today is 2026-06-20; pick +5 days.
      state.dateAbsolute = '2026-06-25';
      apiMock.post.mockResolvedValueOnce({
        data: { id: 'v2', name: 'D', icon: null, filters: {}, sort_order: 1 },
      });
      await ft.saveAs('D', null);

      const sent = apiMock.post.mock.calls[0][1] as { filters: FilterSnapshot };
      expect(sent.filters.date).toEqual({ mode: 'relative', offset: 5 });
    });

    it('apply expands a relative offset to today + offset', () => {
      const { state, adapters } = makePage();
      const tab = seedActiveTab({ date: { mode: 'relative', offset: -3 } });
      const ft = useFilterTabs('tasks', adapters);
      ft.applyTab(tab);
      // today 2026-06-20, offset -3 → 2026-06-17
      expect(state.dateAbsolute).toBe('2026-06-17');
      expect(state.datePreset).toBeNull();
    });

    it('preset dates are stored verbatim (no offset math)', async () => {
      const { state, adapters } = makePage();
      seedActiveTab({});
      const ft = useFilterTabs('tasks', adapters);

      state.datePreset = 'this_week';
      apiMock.post.mockResolvedValueOnce({
        data: { id: 'v3', name: 'P', icon: null, filters: {}, sort_order: 2 },
      });
      await ft.saveAs('P', null);

      const sent = apiMock.post.mock.calls[0][1] as { filters: FilterSnapshot };
      expect(sent.filters.date).toEqual({ mode: 'preset', preset: 'this_week' });
    });
  });

  describe('D2 — search is a scalar that participates in dirty + chips', () => {
    it('search presence toggles its key in the snapshot', async () => {
      const { state, adapters } = makePage();
      seedActiveTab({});
      const ft = useFilterTabs('tasks', adapters);
      state.search = 'hello';
      apiMock.post.mockResolvedValueOnce({
        data: { id: 'v4', name: 'S', icon: null, filters: {}, sort_order: 3 },
      });
      await ft.saveAs('S', null);
      const sent = apiMock.post.mock.calls[0][1] as { filters: FilterSnapshot };
      expect(sent.filters.search).toBe('hello');
    });

    it('adding search when the view had none makes it dirty + an extra chip', () => {
      const { state, adapters } = makePage();
      const tab = seedActiveTab({}); // empty snapshot
      const ft = useFilterTabs('tasks', adapters);
      ft.applyTab(tab);
      state.search = 'new';
      expect(ft.dirty.value).toBe(true);

      const decorated = ft.decorateActiveFilters([{ key: 'search', label: 'Search: new' }]);
      expect(decorated.find((f) => f.key === 'search')?.tabState).toBe('extra');
    });
  });

  describe('D3 — bucket is never part of the snapshot', () => {
    it('changing the bucket does not change the serialized snapshot or dirty', async () => {
      const { state, adapters } = makePage();
      const tab = seedActiveTab({ search: 'foo' });
      const ft = useFilterTabs('tasks', adapters);
      ft.applyTab(tab);
      expect(ft.dirty.value).toBe(false);

      state.bucket = 'trash';
      expect(ft.dirty.value).toBe(false);
      expect(adapters.serialize()).not.toHaveProperty('bucket');

      state.bucket = 'archive';
      expect(adapters.serialize()).toEqual({ search: 'foo' });
    });
  });

  describe('saveActive / restoreFilter', () => {
    it('saveActive PUTs the current snapshot for the active view', async () => {
      const { state, adapters } = makePage();
      const tab = seedActiveTab({ search: 'foo' });
      const ft = useFilterTabs('tasks', adapters);
      ft.applyTab(tab);
      state.priority = 'high';

      apiMock.put.mockResolvedValueOnce({
        data: { ...tab, filters: { search: 'foo', priority: 'high' } },
      });
      await ft.saveActive();

      expect(apiMock.put).toHaveBeenCalledWith('/filter-tabs/v1', {
        filters: { search: 'foo', priority: 'high' },
      });
    });

    it('restoreFilter restores a single removed value from the active snapshot', () => {
      const { state, adapters } = makePage();
      const tab = seedActiveTab({ labels: [1, 2] });
      const ft = useFilterTabs('tasks', adapters);
      ft.applyTab(tab);

      state.labels = [1]; // drop 2 → tab-disabled
      expect(ft.dirty.value).toBe(true);

      ft.restoreFilter('labels:2');
      expect(state.labels).toEqual([1, 2]);
      expect(ft.dirty.value).toBe(false);
    });
  });
});
