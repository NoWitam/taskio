// @vitest-environment happy-dom
// useDraftAutosave — the server-side draft engine driving both stages. The store is mocked, so no
// real HTTP; fake timers drive the ~2.5s autosave debounce. Covers: (a) a dirty change debounces to
// ONE saveDraft sending only NEW bases (dedupe on the second change), (b) autosave stays paused while
// a draft is pending, (c) restore() hydrates + resumes and discard() deletes + resumes, (d) clear()
// deletes, plus staleness detection.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { defineComponent, h, ref, type Ref } from 'vue';
import { mount, flushPromises } from '@vue/test-utils';

const { fetchDraft, saveDraft, deleteDraft, fetchDraftBase, setFileHasDraft } = vi.hoisted(() => ({
  fetchDraft: vi.fn(),
  saveDraft: vi.fn(),
  deleteDraft: vi.fn(),
  fetchDraftBase: vi.fn(),
  setFileHasDraft: vi.fn(),
}));
vi.mock('../../../../app/stores/disk', () => ({
  useDiskStore: () => ({ fetchDraft, saveDraft, deleteDraft, fetchDraftBase, setFileHasDraft }),
}));

import { useDraftAutosave, type UseDraftAutosaveOptions, type UseDraftAutosaveReturn } from '../useDraftAutosave';
import type { DraftInfo } from '../../types';

function imageDraft(over: Partial<DraftInfo> = {}): DraftInfo {
  return {
    kind: 'image',
    manifest: { kind: 'image', history: [], historyIndex: 0, savedIndex: 0, baseIds: [1, 2] },
    base_ids: [1, 2],
    base_version: 'v1',
    updated_at: '2026-07-21T10:00:00Z',
    ...over,
  };
}

/** A base blob record. */
function b(id: number): { id: number; blob: Blob } {
  return { id, blob: new Blob([`b${id}`], { type: 'image/png' }) };
}

/** Mount the composable inside a host so its watchers/scope behave like a real stage. */
function setup(opts: Partial<UseDraftAutosaveOptions> & { serialize: UseDraftAutosaveOptions['serialize'] }) {
  const isDirty = ref(false);
  const changeSignal: Ref<number> = ref(0);
  let composable!: UseDraftAutosaveReturn;
  const Host = defineComponent({
    setup() {
      composable = useDraftAutosave({
        fileId: () => 'f1',
        fileVersion: () => 'v1',
        kind: opts.kind ?? 'image',
        isDirty,
        changeSignal,
        serialize: opts.serialize,
        hydrate: opts.hydrate,
        onRestoreText: opts.onRestoreText,
        hasDraft: opts.hasDraft,
      });
      return () => h('div');
    },
  });
  const wrapper = mount(Host);
  return { wrapper, isDirty, changeSignal, api: () => composable };
}

describe('useDraftAutosave', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    fetchDraft.mockReset().mockResolvedValue(null);
    saveDraft.mockReset().mockResolvedValue({ base_ids: [], updated_at: 'x' });
    deleteDraft.mockReset().mockResolvedValue(undefined);
    fetchDraftBase.mockReset().mockResolvedValue(new Blob(['png'], { type: 'image/png' }));
    setFileHasDraft.mockReset();
  });
  afterEach(() => vi.useRealTimers());

  it('debounces a dirty change into ONE saveDraft, then dedupes: only new bases on the next save', async () => {
    let manifest: unknown = { kind: 'image', history: [], historyIndex: 0, savedIndex: 0, baseIds: [1, 2] };
    let bases = [b(1), b(2)];
    saveDraft.mockResolvedValue({ base_ids: [1, 2], updated_at: 'x' });

    const { isDirty, changeSignal } = setup({ serialize: async () => ({ manifest: manifest as never, bases }) });
    await flushPromises(); // probe resolves (no draft) → autosave live

    isDirty.value = true;
    changeSignal.value += 1;
    await vi.advanceTimersByTimeAsync(2500);
    await flushPromises();

    expect(saveDraft).toHaveBeenCalledTimes(1);
    expect(saveDraft.mock.calls[0][0]).toBe('f1');
    expect(saveDraft.mock.calls[0][2]).toBe('v1'); // base_version = the file version
    expect((saveDraft.mock.calls[0][3] as { id: number }[]).map((x) => x.id)).toEqual([1, 2]);

    // A second change that adds base 3 → only base 3 is new (1 & 2 already uploaded).
    manifest = { kind: 'image', history: [], historyIndex: 0, savedIndex: 0, baseIds: [1, 2, 3] };
    bases = [b(1), b(2), b(3)];
    changeSignal.value += 1;
    await vi.advanceTimersByTimeAsync(2500);
    await flushPromises();

    expect(saveDraft).toHaveBeenCalledTimes(2);
    expect((saveDraft.mock.calls[1][3] as { id: number }[]).map((x) => x.id)).toEqual([3]);
  });

  it('skips the restore probe entirely when the file is known to have NO draft (has_draft false)', async () => {
    const { isDirty, changeSignal, api } = setup({
      serialize: async () => ({ manifest: { kind: 'text' as const, content: 'x' }, bases: [] }),
      hasDraft: () => false,
    });
    await flushPromises();

    // No draft on the list → no GET /{file}/draft at all, no banner — and autosave is live.
    expect(fetchDraft).not.toHaveBeenCalled();
    expect(api().pendingDraft.value).toBeNull();

    isDirty.value = true;
    changeSignal.value += 1;
    await vi.advanceTimersByTimeAsync(2500);
    await flushPromises();
    expect(saveDraft).toHaveBeenCalledTimes(1);
  });

  it('probes when the file IS flagged as having a draft', async () => {
    fetchDraft.mockResolvedValue(imageDraft());
    const { api } = setup({
      serialize: async () => ({ manifest: imageDraft().manifest, bases: [] }),
      hydrate: vi.fn(async () => {}),
      hasDraft: () => true,
    });
    await flushPromises();

    expect(fetchDraft).toHaveBeenCalledWith('f1');
    expect(api().pendingDraft.value).not.toBeNull();
  });

  it('does not autosave while dirty is false', async () => {
    const { changeSignal } = setup({ serialize: async () => ({ manifest: {} as never, bases: [] }) });
    await flushPromises();

    changeSignal.value += 1; // a change, but not dirty
    await vi.advanceTimersByTimeAsync(2500);
    await flushPromises();

    expect(saveDraft).not.toHaveBeenCalled();
  });

  it('pauses autosave while a draft is pending, and resumes on restore()', async () => {
    fetchDraft.mockResolvedValue(imageDraft());
    const hydrate = vi.fn(async () => {});
    const { isDirty, changeSignal, api } = setup({
      serialize: async () => ({ manifest: imageDraft().manifest, bases: [b(1), b(2)] }),
      hydrate,
    });
    await flushPromises();

    expect(api().pendingDraft.value).not.toBeNull();

    // Paused: a dirty change must NOT autosave while the banner is up.
    isDirty.value = true;
    changeSignal.value += 1;
    await vi.advanceTimersByTimeAsync(2500);
    await flushPromises();
    expect(saveDraft).not.toHaveBeenCalled();

    // Restore → hydrate is called with the manifest + a base fetcher; the banner clears + autosave resumes.
    await api().restore();
    expect(hydrate).toHaveBeenCalledTimes(1);
    const restoreCall = hydrate.mock.calls[0] as unknown[];
    expect(restoreCall[0]).toEqual(imageDraft().manifest);
    expect(typeof restoreCall[1]).toBe('function');
    expect(api().pendingDraft.value).toBeNull();

    changeSignal.value += 1;
    await vi.advanceTimersByTimeAsync(2500);
    await flushPromises();
    expect(saveDraft).toHaveBeenCalledTimes(1);
    // The restored bases (1 & 2) were seeded as uploaded → this resumed save sends none of them.
    expect((saveDraft.mock.calls[0][3] as { id: number }[])).toEqual([]);
  });

  it('discard() deletes the draft, clears the banner, and resumes autosave', async () => {
    fetchDraft.mockResolvedValue(imageDraft());
    const { isDirty, changeSignal, api } = setup({
      serialize: async () => ({ manifest: imageDraft().manifest, bases: [] }),
      hydrate: vi.fn(async () => {}),
    });
    await flushPromises();

    await api().discard();
    expect(deleteDraft).toHaveBeenCalledWith('f1');
    expect(api().pendingDraft.value).toBeNull();

    isDirty.value = true;
    changeSignal.value += 1;
    await vi.advanceTimersByTimeAsync(2500);
    await flushPromises();
    expect(saveDraft).toHaveBeenCalledTimes(1); // resumed
  });

  it('restore() rejects (and resumes) when a base cannot be hydrated', async () => {
    fetchDraft.mockResolvedValue(imageDraft());
    const hydrate = vi.fn(async () => {
      throw new Error('missing base');
    });
    const { api } = setup({ serialize: async () => ({ manifest: imageDraft().manifest, bases: [] }), hydrate });
    await flushPromises();

    await expect(api().restore()).rejects.toThrow('missing base');
    expect(api().pendingDraft.value).toBeNull(); // gave up on the draft
  });

  it('restore() hands text content back through onRestoreText', async () => {
    fetchDraft.mockResolvedValue({
      kind: 'text',
      manifest: { kind: 'text', content: 'draft body' },
      base_ids: [],
      base_version: 'v1',
      updated_at: '2026-07-21T10:00:00Z',
    });
    const onRestoreText = vi.fn();
    const { api } = setup({ kind: 'text', serialize: async () => ({ manifest: { kind: 'text' as const, content: '' }, bases: [] }), onRestoreText });
    await flushPromises();

    await api().restore();
    expect(onRestoreText).toHaveBeenCalledWith('draft body');
    expect(api().pendingDraft.value).toBeNull();
  });

  it('clear() deletes the draft (called after an explicit Save)', async () => {
    const { api } = setup({ serialize: async () => ({ manifest: {} as never, bases: [] }) });
    await flushPromises();

    await api().clear();
    expect(deleteDraft).toHaveBeenCalledWith('f1');
  });

  // --- grid draft indicator (setFileHasDraft) ---------------------------------
  // The composable keeps the browser tile's draft dot live: a successful autosave lights it, and
  // clear()/discard() (both delete the draft) put it out. A FAILED save must NOT light it.
  it('lights the grid indicator after a SUCCESSFUL autosave (setFileHasDraft true)', async () => {
    const { isDirty, changeSignal } = setup({
      serialize: async () => ({
        manifest: { kind: 'image', history: [], historyIndex: 0, savedIndex: 0, baseIds: [1] } as never,
        bases: [b(1)],
      }),
    });
    await flushPromises(); // probe: no draft → autosave live

    isDirty.value = true;
    changeSignal.value += 1;
    await vi.advanceTimersByTimeAsync(2500);
    await flushPromises();

    expect(saveDraft).toHaveBeenCalledTimes(1);
    expect(setFileHasDraft).toHaveBeenCalledWith('f1', true);
  });

  it('does NOT light the indicator when the autosave POST fails', async () => {
    saveDraft.mockRejectedValueOnce(new Error('offline'));
    const { isDirty, changeSignal } = setup({
      serialize: async () => ({
        manifest: { kind: 'image', history: [], historyIndex: 0, savedIndex: 0, baseIds: [1] } as never,
        bases: [b(1)],
      }),
    });
    await flushPromises();

    isDirty.value = true;
    changeSignal.value += 1;
    await vi.advanceTimersByTimeAsync(2500);
    await flushPromises();

    expect(saveDraft).toHaveBeenCalledTimes(1);
    expect(setFileHasDraft).not.toHaveBeenCalledWith('f1', true);
  });

  it('clears the grid indicator after clear() (setFileHasDraft false)', async () => {
    const { api } = setup({ serialize: async () => ({ manifest: {} as never, bases: [] }) });
    await flushPromises();

    await api().clear();
    expect(setFileHasDraft).toHaveBeenCalledWith('f1', false);
  });

  it('clears the grid indicator after discard() (setFileHasDraft false)', async () => {
    fetchDraft.mockResolvedValue(imageDraft());
    const { api } = setup({
      serialize: async () => ({ manifest: imageDraft().manifest, bases: [] }),
      hydrate: vi.fn(async () => {}),
    });
    await flushPromises();

    await api().discard();
    expect(setFileHasDraft).toHaveBeenCalledWith('f1', false);
  });

  it('clear() during an in-flight autosave deletes AFTER the POST resolves (no resurrected draft)', async () => {
    // Regression: a Save's clear() DELETE must not race an autosave POST already in flight — else the
    // POST re-creates the draft after the DELETE, resurrecting a draft == the just-saved file.
    const order: string[] = [];
    let releaseSave!: () => void;
    saveDraft.mockImplementation(async () => {
      await new Promise<void>((r) => (releaseSave = r)); // block the POST until we release it
      order.push('save');
      return { base_ids: [1], updated_at: 'x' };
    });
    deleteDraft.mockImplementation(async () => void order.push('delete'));

    const { isDirty, changeSignal, api } = setup({
      serialize: async () => ({ manifest: { kind: 'image', history: [], historyIndex: 0, savedIndex: 0, baseIds: [1] } as never, bases: [b(1)] }),
    });
    await flushPromises(); // probe: no draft → autosave live

    isDirty.value = true;
    changeSignal.value += 1;
    await vi.advanceTimersByTimeAsync(2500); // debounce fires → POST starts, now blocked
    await flushPromises();
    expect(saveDraft).toHaveBeenCalledTimes(1);
    expect(order).toEqual([]); // POST is in flight

    const clearing = api().clear(); // Save → clear() while the POST is still in flight
    await flushPromises();
    expect(deleteDraft).not.toHaveBeenCalled(); // DELETE is queued behind the in-flight POST

    releaseSave(); // let the POST finish
    await clearing;
    await flushPromises();

    expect(order).toEqual(['save', 'delete']); // DELETE ran strictly AFTER the POST → draft stays gone
    expect(deleteDraft).toHaveBeenCalledTimes(1);
  });

  it('flags a draft as stale when its base_version differs from the current file version', async () => {
    fetchDraft.mockResolvedValue(imageDraft({ base_version: 'OLD' }));
    const { api } = setup({ serialize: async () => ({ manifest: imageDraft().manifest, bases: [] }), hydrate: vi.fn() });
    await flushPromises();
    expect(api().stale.value).toBe(true);
  });

  it('is not stale when the versions match', async () => {
    fetchDraft.mockResolvedValue(imageDraft({ base_version: 'v1' }));
    const { api } = setup({ serialize: async () => ({ manifest: imageDraft().manifest, bases: [] }), hydrate: vi.fn() });
    await flushPromises();
    expect(api().stale.value).toBe(false);
  });
});
