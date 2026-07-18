// @vitest-environment happy-dom
// Disk store — the file-manager BROWSE level. Pins the level fetch (folders +
// first file page + breadcrumbs, with folder_id ALWAYS sent so root vs. absent
// never blur), file infinite-scroll append, and the token guard that lets a newer
// open() supersede an in-flight one. The api client is mocked so no real HTTP happens.
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';

vi.mock('../../lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}));

const apiPost = () => (api as unknown as { post: ReturnType<typeof vi.fn> }).post;
const apiPatch = () => (api as unknown as { patch: ReturnType<typeof vi.fn> }).patch;
const apiDelete = () => (api as unknown as { delete: ReturnType<typeof vi.fn> }).delete;

import { api } from '../../lib/api';
import { useDiskStore, isVirtualId } from '../disk';
import type { DiskFile, DiskFolder } from '../../../pages/disk/types';

const apiMock = api as unknown as { get: ReturnType<typeof vi.fn> };

function folder(id: string, over: Partial<DiskFolder> = {}): DiskFolder {
  return {
    id, name: `Folder ${id}`, parent_id: null, depth: 0, ancestor_ids: [],
    created_at: null, updated_at: null, deleted_at: null,
    can_be_updated: true, can_be_moved: true, can_be_deleted: true, can_be_restored: true, ...over,
  };
}
function file(id: string, over: Partial<DiskFile> = {}): DiskFile {
  return {
    id, name: `File ${id}`, path: `/api/disk/${id}`, type: 'image', size: 10, size_human: '10 B',
    created_at: '2026-07-17 10:00', description: null, mime_type: 'image/png', folder_id: null,
    source: 'disk', created_at_iso: null, updated_at_iso: null, disk_trashed_at: null,
    can_be_updated: true, can_be_moved: true, can_be_deleted: true,
    can_be_restored: true, can_be_force_deleted: true, ...over,
  };
}

/** Route each URL to a canned response, in resolution order matching the code. */
function routeGet(handlers: { folders?: unknown; files?: unknown; crumbs?: unknown }): void {
  apiMock.get.mockImplementation((url: string) => {
    if (url.startsWith('/disk/folders/')) return Promise.resolve(handlers.crumbs ?? { data: {}, breadcrumbs: [] });
    if (url.startsWith('/disk/folders')) return Promise.resolve(handlers.folders ?? { data: [] });
    if (url.startsWith('/disk?')) return Promise.resolve(handlers.files ?? { data: [], meta: { next_cursor: null } });
    return Promise.resolve({ data: [] });
  });
}

describe('useDiskStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    apiMock.get.mockReset();
  });

  it('opens the ROOT: folders + files, no breadcrumbs, folder_id sent empty', async () => {
    routeGet({
      folders: { data: [folder('a')] },
      files: { data: [file('f1')], meta: { next_cursor: 'c2' } },
    });
    const store = useDiskStore();
    await store.open(null);

    expect(store.folders).toHaveLength(1);
    expect(store.files).toHaveLength(1);
    expect(store.breadcrumbs).toEqual([]);
    expect(store.filesHasMore).toBe(true);
    // folder_id is ALWAYS present (empty = root), distinct from "absent"; and the
    // folder browser is scoped to disk-native files (source=disk) so resource files
    // (attachments/reports) never leak into the folder view — they live under Zasoby.
    const filesCall = apiMock.get.mock.calls.find((c) => String(c[0]).startsWith('/disk?'));
    expect(String(filesCall?.[0])).toContain('folder_id=');
    expect(String(filesCall?.[0])).toContain('source=disk');
    // No breadcrumbs fetch at the root.
    expect(apiMock.get.mock.calls.some((c) => String(c[0]).startsWith('/disk/folders/'))).toBe(false);
  });

  it('opens a FOLDER: scopes by id and builds a trail ENDING at the open folder', async () => {
    // REGRESSION: the show endpoint returns ANCESTORS only (the materialized path never
    // holds self) — the store must append `data`, or the crumb row loses the current
    // folder and the up-tile of any depth≥2 folder jumps to the root instead of the parent.
    routeGet({
      folders: { data: [folder('child')] },
      files: { data: [], meta: { next_cursor: null } },
      crumbs: { data: folder('b', { name: 'B', parent_id: 'a' }), breadcrumbs: [folder('a', { name: 'A' })] },
    });
    const store = useDiskStore();
    await store.open('b');

    expect(store.currentFolderId).toBe('b');
    expect(store.breadcrumbs.map((f) => f.id)).toEqual(['a', 'b']); // ancestors + self
    expect(apiMock.get.mock.calls.some((c) => String(c[0]).includes('parent_id=b'))).toBe(true);
    expect(apiMock.get.mock.calls.some((c) => String(c[0]) === '/disk/folders/b')).toBe(true);
  });

  it('loadMoreFiles appends the next page and advances the cursor', async () => {
    routeGet({ files: { data: [file('f1')], meta: { next_cursor: 'c2' } } });
    const store = useDiskStore();
    await store.open(null);

    apiMock.get.mockResolvedValueOnce({ data: [file('f2')], meta: { next_cursor: null } });
    await store.loadMoreFiles();

    expect(store.files.map((f) => f.id)).toEqual(['f1', 'f2']);
    expect(store.filesHasMore).toBe(false);
  });

  it('loadMoreFiles is a no-op when there is no next cursor', async () => {
    routeGet({ files: { data: [file('f1')], meta: { next_cursor: null } } });
    const store = useDiskStore();
    await store.open(null);
    apiMock.get.mockClear();

    await store.loadMoreFiles();
    expect(apiMock.get).not.toHaveBeenCalled();
  });

  it('records an error message and stops paging when the level fetch fails', async () => {
    apiMock.get.mockRejectedValue({ response: { data: { message: 'nope' } } });
    const store = useDiskStore();
    await store.open(null);

    expect(store.error).toBe('nope');
    expect(store.filesHasMore).toBe(false);
  });

  it('createFolder posts and inserts into the current level', async () => {
    routeGet({ folders: { data: [] } });
    const store = useDiskStore();
    await store.open(null); // viewing the root (parent_id = null)

    apiPost().mockResolvedValueOnce({ data: folder('new', { parent_id: null, name: 'Campaigns' }) });
    await store.createFolder('Campaigns', null);

    expect(apiPost()).toHaveBeenCalledWith('/disk/folders', { name: 'Campaigns', parent_id: null });
    expect(store.folders.map((f) => f.id)).toContain('new');
  });

  it('createFolder does NOT insert when it lands in a different folder', async () => {
    routeGet({ folders: { data: [] } });
    const store = useDiskStore();
    await store.open(null); // viewing the root

    apiPost().mockResolvedValueOnce({ data: folder('deep', { parent_id: 'elsewhere' }) });
    await store.createFolder('Deep', 'elsewhere');

    expect(store.folders).toHaveLength(0);
  });

  it('uploadFile posts multipart and prepends to the current folder', async () => {
    routeGet({ files: { data: [file('f1')], meta: { next_cursor: null } } });
    const store = useDiskStore();
    await store.open(null);

    apiPost().mockResolvedValueOnce({ data: file('up', { folder_id: null }) });
    const picked = new File(['x'], 'up.png', { type: 'image/png' });
    await store.uploadFile(picked, null);

    const calls = apiPost().mock.calls;
    const [url, body] = calls[calls.length - 1];
    expect(url).toBe('/disk');
    expect(body).toBeInstanceOf(FormData);
    expect(store.files.map((f) => f.id)).toEqual(['up', 'f1']); // prepended
  });

  // --- resources ("Zasoby") tree -------------------------------------------
  const TREE = {
    data: [
      {
        id: 'sys:res:task', type: 'task', label_key: 'disk.resources.task', icon: 'list-checks', files_count: 2,
        buckets: [{ id: 'sys:res:task:2026-07', key: '2026-07', granularity: 'month', files_count: 2 }],
      },
    ],
  };

  it('isVirtualId recognizes the synthetic resource ids only', () => {
    expect(isVirtualId('sys:res')).toBe(true);
    expect(isVirtualId('sys:res:task')).toBe(true);
    expect(isVirtualId('sys:res:task:2026-07')).toBe(true);
    expect(isVirtualId(null)).toBe(false);
    expect(isVirtualId('019f70c4-9961-7297-a0af-5dfd18146322')).toBe(false);
  });

  it('opens the resources ROOT: the types become virtual nodes', async () => {
    apiMock.get.mockImplementation((url: string) =>
      Promise.resolve(url === '/disk/resources' ? TREE : { data: [] }));
    const store = useDiskStore();
    await store.open('sys:res');

    expect(store.viewKind).toBe('resources');
    expect(store.virtualNodes.map((n) => n.id)).toEqual(['sys:res:task']);
    expect(store.virtualNodes[0].labelKey).toBe('disk.resources.task');
  });

  it('opens a TYPE: its date buckets become virtual nodes', async () => {
    apiMock.get.mockImplementation((url: string) =>
      Promise.resolve(url === '/disk/resources' ? TREE : { data: [] }));
    const store = useDiskStore();
    await store.open('sys:res:task');

    expect(store.viewKind).toBe('type');
    expect(store.virtualNodes[0].bucketKey).toBe('2026-07');
    expect(store.virtualNodes[0].granularity).toBe('month');
  });

  it('opens a BUCKET: files come from source+bucket, not folder_id', async () => {
    apiMock.get.mockImplementation((url: string) => {
      if (url === '/disk/resources') return Promise.resolve(TREE);
      if (url.startsWith('/disk?') && url.includes('source=task') && url.includes('bucket=2026-07')) {
        return Promise.resolve({ data: [file('r1')], meta: { next_cursor: null } });
      }
      return Promise.resolve({ data: [] });
    });
    const store = useDiskStore();
    await store.open('sys:res:task:2026-07');

    expect(store.viewKind).toBe('bucket');
    expect(store.files.map((f) => f.id)).toEqual(['r1']);
    expect(apiMock.get.mock.calls.some((c) => String(c[0]).includes('source=task&bucket=2026-07'))).toBe(true);
  });

  // --- file drawer: metadata + history --------------------------------------
  it('updateFile patches name/description/labels and merges the row', async () => {
    routeGet({ files: { data: [file('f1', { name: 'old.png', description: null })], meta: { next_cursor: null } } });
    const store = useDiskStore();
    await store.open(null);

    apiPatch().mockResolvedValueOnce({ data: file('f1', { name: 'new.png', description: 'a note' }) });
    const returned = await store.updateFile('f1', { name: 'new.png', description: 'a note', labels: ['l1'] });

    expect(apiPatch()).toHaveBeenCalledWith('/disk/f1', { name: 'new.png', description: 'a note', labels: ['l1'] });
    expect(returned.name).toBe('new.png');
    expect(store.files[0].name).toBe('new.png'); // reconciled in the grid
    expect(store.files[0].description).toBe('a note');
  });

  it('fetchChangelog reads the generic /file/{id}/changelog endpoint (morph alias)', async () => {
    apiMock.get.mockResolvedValue({
      data: [{ id: 1, event: 'updated', event_description: 'Renamed', details: {}, causer: { id: 'u', name: 'Ada', email: 'a@x' }, created_at: '2026-07-17T10:00:00Z' }],
      meta: { next_cursor: null },
    });
    const store = useDiskStore();
    await store.fetchChangelog('f1');

    expect(apiMock.get).toHaveBeenCalledWith('/file/f1/changelog');
    expect(store.changelog).toHaveLength(1);
    expect(store.changelog[0].event_description).toBe('Renamed');
    expect(store.changelogHasMore).toBe(false);
  });

  // --- trash level (sys:trash) ----------------------------------------------
  it('opens the TRASH: trashed files + flat trashed folders, viewKind=trash', async () => {
    apiMock.get.mockImplementation((url: string) => {
      if (url.startsWith('/disk?') && url.includes('trashed=1')) {
        return Promise.resolve({ data: [file('t1')], meta: { next_cursor: null } });
      }
      if (url.startsWith('/disk/folders') && url.includes('trashed=1')) {
        return Promise.resolve({ data: [folder('tf1')] });
      }
      return Promise.resolve({ data: [] });
    });
    const store = useDiskStore();
    await store.open('sys:trash');

    expect(store.viewKind).toBe('trash');
    expect(store.files.map((f) => f.id)).toEqual(['t1']);
    expect(store.folders.map((f) => f.id)).toEqual(['tf1']);
    // The trash never applies the folder-browser scoping (source=disk / folder_id).
    const filesCall = apiMock.get.mock.calls.find((c) => String(c[0]).startsWith('/disk?'));
    expect(String(filesCall?.[0])).not.toContain('source=disk');
    expect(String(filesCall?.[0])).not.toContain('folder_id');
  });

  it('restoreFile in place OMITS target_folder_id; a picked target SENDS it (null = root)', async () => {
    apiMock.get.mockImplementation((url: string) =>
      Promise.resolve(url.includes('trashed=1') ? { data: [file('t1'), file('t2')], meta: { next_cursor: null } } : { data: [] }));
    const store = useDiskStore();
    await store.open('sys:trash');

    apiPost().mockResolvedValue({});
    // In place: the backend keys on the KEY BEING ABSENT — never send it as null.
    await store.restoreFile('t1', { provided: false, folderId: null });
    expect(apiPost()).toHaveBeenCalledWith('/disk/t1/restore', {});

    // Picked the root: the key is present with null.
    await store.restoreFile('t2', { provided: true, folderId: null });
    expect(apiPost()).toHaveBeenCalledWith('/disk/t2/restore', { target_folder_id: null });

    expect(store.files).toHaveLength(0); // both left the trash view
  });

  it('forceDeleteFile and restoreFolder drop their rows from the trash view', async () => {
    apiMock.get.mockImplementation((url: string) => {
      if (url.startsWith('/disk?')) return Promise.resolve({ data: [file('t1')], meta: { next_cursor: null } });
      return Promise.resolve({ data: [folder('tf1')] });
    });
    const store = useDiskStore();
    await store.open('sys:trash');

    apiDelete().mockResolvedValue(undefined);
    apiPost().mockResolvedValue({});
    await store.forceDeleteFile('t1');
    await store.restoreFolder('tf1');

    expect(apiDelete()).toHaveBeenCalledWith('/disk/t1/force');
    expect(apiPost()).toHaveBeenCalledWith('/disk/folders/tf1/restore');
    expect(store.files).toHaveLength(0);
    expect(store.folders).toHaveLength(0);
  });

  // --- tile actions (rename / trash) ---------------------------------------
  it('renameFolder patches and replaces the row in place', async () => {
    routeGet({ folders: { data: [folder('a', { name: 'Old' })] } });
    const store = useDiskStore();
    await store.open(null);

    apiPatch().mockResolvedValueOnce({ data: folder('a', { name: 'New' }) });
    await store.renameFolder('a', 'New');

    expect(apiPatch()).toHaveBeenCalledWith('/disk/folders/a', { name: 'New' });
    expect(store.folders[0].name).toBe('New');
  });

  it('renameFolder MERGES the response so the item count survives (update omits whenCounted keys)', async () => {
    routeGet({ folders: { data: [folder('a', { name: 'Old', files_count: 12, children_count: 3 })] } });
    const store = useDiskStore();
    await store.open(null);

    // The update endpoint does not loadCount, so its Resource has no files_count/children_count.
    apiPatch().mockResolvedValueOnce({ data: { id: 'a', name: 'New', parent_id: null } });
    await store.renameFolder('a', 'New');

    expect(store.folders[0].name).toBe('New');
    expect(store.folders[0].files_count).toBe(12); // preserved, not dropped to 0
    expect(store.folders[0].children_count).toBe(3);
  });

  it('open() clears a wedged filesLoading, so a superseded load-more cannot kill pagination', async () => {
    routeGet({ files: { data: [file('f1')], meta: { next_cursor: 'c2' } } });
    const store = useDiskStore();
    await store.open(null);

    // Simulate the state a superseded loadMoreFiles leaves behind (its finally is skipped).
    store.filesLoading = true;
    await store.open('x');

    expect(store.filesLoading).toBe(false);
  });

  it('openFolder ENCODES the folder id in the breadcrumb request path (no raw interpolation)', async () => {
    routeGet({ crumbs: { data: folder('x'), breadcrumbs: [] } });
    const store = useDiskStore();
    // A crafted deep-link value with a path separator must be percent-encoded, not interpolated raw.
    await store.open('a/b');

    expect(apiMock.get.mock.calls.some((c) => String(c[0]) === '/disk/folders/a%2Fb')).toBe(true);
    expect(apiMock.get.mock.calls.some((c) => String(c[0]) === '/disk/folders/a/b')).toBe(false);
  });

  it('renameFile patches and replaces the row in place', async () => {
    routeGet({ files: { data: [file('f1', { name: 'old.png' })], meta: { next_cursor: null } } });
    const store = useDiskStore();
    await store.open(null);

    apiPatch().mockResolvedValueOnce({ data: file('f1', { name: 'new.png' }) });
    await store.renameFile('f1', 'new.png');

    expect(apiPatch()).toHaveBeenCalledWith('/disk/f1', { name: 'new.png' });
    expect(store.files[0].name).toBe('new.png');
  });

  it('moveFile patches folder_id and drops the row from the current level', async () => {
    routeGet({ files: { data: [file('f1'), file('f2')], meta: { next_cursor: null } } });
    const store = useDiskStore();
    await store.open(null);

    apiPatch().mockResolvedValueOnce({ data: file('f1', { folder_id: 'dest' }) });
    await store.moveFile('f1', 'dest');

    expect(apiPatch()).toHaveBeenCalledWith('/disk/f1', { folder_id: 'dest' });
    expect(store.files.map((f) => f.id)).toEqual(['f2']); // left this level
  });

  it('moveFolder posts to the move endpoint (target_folder_id) and drops the row', async () => {
    routeGet({ folders: { data: [folder('a'), folder('b')] } });
    const store = useDiskStore();
    await store.open(null);

    apiPost().mockResolvedValueOnce({ data: folder('a', { parent_id: null }) });
    await store.moveFolder('a', null); // to the root

    expect(apiPost()).toHaveBeenCalledWith('/disk/folders/a/move', { target_folder_id: null });
    expect(store.folders.map((f) => f.id)).toEqual(['b']);
  });

  it('copyFile posts to /copy and prepends when the copy lands in the current level', async () => {
    routeGet({ files: { data: [file('f1')], meta: { next_cursor: null } } });
    const store = useDiskStore();
    await store.open(null); // current level = the root (folder_id null)

    apiPost().mockResolvedValueOnce({ data: file('c1', { folder_id: null, name: 'Copy of f1' }) });
    const copy = await store.copyFile('f1', { name: 'Copy of f1', folderId: null });

    expect(apiPost()).toHaveBeenCalledWith('/disk/f1/copy', { name: 'Copy of f1', folder_id: null });
    expect(copy.id).toBe('c1');
    expect(store.files.map((f) => f.id)).toEqual(['c1', 'f1']); // prepended to the open level
  });

  it('copyFile does NOT insert when the copy lands in another folder', async () => {
    routeGet({ files: { data: [file('f1')], meta: { next_cursor: null } } });
    const store = useDiskStore();
    await store.open(null);

    apiPost().mockResolvedValueOnce({ data: file('c1', { folder_id: 'other' }) });
    await store.copyFile('f1', { name: 'x', folderId: 'other' });

    expect(store.files.map((f) => f.id)).toEqual(['f1']); // the copy is elsewhere
  });

  it('trashFolder / trashFile delete and drop the row from the grid', async () => {
    routeGet({
      folders: { data: [folder('a'), folder('b')] },
      files: { data: [file('f1'), file('f2')], meta: { next_cursor: null } },
    });
    const store = useDiskStore();
    await store.open(null);

    apiDelete().mockResolvedValue(undefined);
    await store.trashFolder('a');
    await store.trashFile('f2');

    expect(apiDelete()).toHaveBeenCalledWith('/disk/folders/a');
    expect(apiDelete()).toHaveBeenCalledWith('/disk/f2');
    expect(store.folders.map((f) => f.id)).toEqual(['b']);
    expect(store.files.map((f) => f.id)).toEqual(['f1']);
  });
});
