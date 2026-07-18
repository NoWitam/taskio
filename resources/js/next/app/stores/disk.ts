// Disk store for the "next" frontend (Pinia setup store).
//
// Owns SERVER STATE for the disk BROWSER: the contents of ONE folder at a time —
// its subfolders, its (cursor-paginated) files, and the breadcrumb trail to it.
// The PAGE owns the "which folder" (from the `?folder=` query) and calls open();
// this store fetches the level and appends file pages for infinite scroll.
//
// Backend contract (verified — do NOT invent fields):
//   GET /disk/folders?parent_id=<id>      → { data: DiskFolder[] }   (one level; roots when omitted)
//   GET /disk/folders/{id}                → { data: DiskFolder, breadcrumbs: DiskFolder[] }
//   GET /disk?folder_id=<id>&cursor=<c>   → { data: DiskFile[], meta: { next_cursor } }
//     `folder_id` PRESENT-but-empty means the workspace ROOT (distinct from absent).
//     File cursor page size is 24.
//
// Self-contained: NO import from the legacy `resources/js/`.
import { defineStore } from 'pinia';
import { ref } from 'vue';
import { api } from '../lib/api';
import type {
  ChangelogEntry,
  ChangelogResponse,
  DiskFile,
  DiskFolder,
  DiskViewKind,
  DiskVirtualNode,
  FileListResponse,
  FolderListResponse,
  FolderShowResponse,
  ResourceTreeResponse,
  ResourceTypeNode,
  RestorePreview,
  RestorePreviewResponse,
} from '../../pages/disk/types';

/** Prefix of every synthetic "Zasoby" id — none of these ever resolve as a real folder. */
const VIRTUAL_ROOT = 'sys:res';

/** The synthetic id of the disk trash level (same `?folder=` navigation channel). */
export const TRASH_ID = 'sys:trash';

/** Whether an id points into the read-only resources tree (rather than a real folder). */
export function isVirtualId(id: string | null): boolean {
  return typeof id === 'string' && (id === VIRTUAL_ROOT || id.startsWith(VIRTUAL_ROOT + ':'));
}

/** Whether an id is the disk trash level. */
export function isTrashId(id: string | null): boolean {
  return id === TRASH_ID;
}

function extractMessage(err: unknown): string {
  const res = (err as { response?: { data?: { message?: string } } })?.response;
  return res?.data?.message ?? 'disk.browser.error';
}

export const useDiskStore = defineStore('next-disk', () => {
  // --- Current level -------------------------------------------------------
  /** The folder we are inside, or null for the workspace root. */
  const currentFolderId = ref<string | null>(null);
  const folders = ref<DiskFolder[]>([]);
  const files = ref<DiskFile[]>([]);
  /** Root first, ending at the current folder; empty at the root. */
  const breadcrumbs = ref<DiskFolder[]>([]);

  const loading = ref(false);
  const error = ref<string | null>(null);

  // File pagination (folders come in one level, files are cursor-paginated).
  const filesCursor = ref<string | null>(null);
  const filesHasMore = ref(true);
  const filesLoading = ref(false);

  // --- Resources ("Zasoby") virtual tree -----------------------------------
  /** Which kind of level is shown (a real folder vs. a node in the resources tree). */
  const viewKind = ref<DiskViewKind>('folder');
  /** The whole resources tree, fetched once and reused for every Zasoby level. */
  const resourceTree = ref<ResourceTypeNode[]>([]);
  /** The navigable nodes at the current virtual level (types, or a type's buckets). */
  const virtualNodes = ref<DiskVirtualNode[]>([]);
  /** The bucket being browsed (drives file pagination), or null. */
  const virtualBucket = ref<{ source: string; key: string } | null>(null);

  // A reset always supersedes work in flight, so rapid folder switches can never
  // leave a stale level rendered.
  let token = 0;

  /**
   * Build the files query for `folderId`: folder_id is ALWAYS sent (empty = root),
   * and `source=disk` restricts the folder browser to disk-native files. Resource
   * files (task attachments, report outputs) live ONLY under the read-only "Zasoby"
   * tree, never in the folder view (the index maps source=disk → fileable IS NULL).
   */
  function filesUrl(folderId: string | null, cursor?: string | null): string {
    const params = new URLSearchParams();
    params.set('folder_id', folderId ?? '');
    params.set('source', 'disk');
    if (cursor) params.set('cursor', cursor);
    return `/disk?${params.toString()}`;
  }

  /** The cursor-paginated files of a resource bucket (source=<type>&bucket=<key>). */
  function bucketFilesUrl(source: string, key: string, cursor?: string | null): string {
    const params = new URLSearchParams();
    params.set('source', source);
    params.set('bucket', key);
    if (cursor) params.set('cursor', cursor);
    return `/disk?${params.toString()}`;
  }

  /**
   * Reset the shared per-level state at the start of any open(). filesLoading is cleared
   * here too: a load-more page in flight when the user switches level early-returns on the
   * token guard WITHOUT hitting its finally, so without this a superseded append would wedge
   * filesLoading=true and kill infinite scroll for every subsequent level.
   */
  function beginLevel(id: string | null): void {
    currentFolderId.value = id;
    loading.value = true;
    error.value = null;
    folders.value = [];
    files.value = [];
    breadcrumbs.value = [];
    virtualNodes.value = [];
    virtualBucket.value = null;
    filesCursor.value = null;
    filesHasMore.value = true;
    filesLoading.value = false;
  }

  /** The cursor-paginated disk trash (files thrown away FROM the disk). */
  function trashFilesUrl(cursor?: string | null): string {
    const params = new URLSearchParams();
    params.set('trashed', '1');
    if (cursor) params.set('cursor', cursor);
    return `/disk?${params.toString()}`;
  }

  /**
   * Open a level. `sys:trash` is the disk trash; a `sys:res…` id navigates the read-only
   * resources tree; anything else (null = root, or a folder uuid) is a real folder.
   * Token-guarded so a newer open wins.
   */
  async function open(id: string | null): Promise<void> {
    if (isTrashId(id)) return openTrash();
    return isVirtualId(id) ? openVirtual(id as string) : openFolder(id);
  }

  /** Open the disk trash: trashed files (paginated) + trashed folders (flat). */
  async function openTrash(): Promise<void> {
    const myToken = (token += 1);
    beginLevel(TRASH_ID);
    viewKind.value = 'trash';

    try {
      const [fileRes, folderRes] = await Promise.all([
        api.get<FileListResponse>(trashFilesUrl()),
        api.get<FolderListResponse>('/disk/folders?trashed=1'),
      ]);

      if (myToken !== token) return; // superseded by a newer open

      files.value = fileRes.data ?? [];
      filesCursor.value = fileRes.meta?.next_cursor ?? null;
      filesHasMore.value = (fileRes.meta?.next_cursor ?? null) !== null;
      folders.value = folderRes.data ?? [];
    } catch (err: unknown) {
      if (myToken !== token) return;
      error.value = extractMessage(err);
      filesHasMore.value = false;
    } finally {
      if (myToken === token) loading.value = false;
    }
  }

  /** Open a real folder: its subfolders, first file page, and breadcrumb trail. */
  async function openFolder(folderId: string | null): Promise<void> {
    const myToken = (token += 1);
    beginLevel(folderId);
    viewKind.value = 'folder';

    try {
      const folderQuery = folderId ? `?parent_id=${encodeURIComponent(folderId)}` : '';
      const [folderRes, fileRes, crumbRes] = await Promise.all([
        api.get<FolderListResponse>(`/disk/folders${folderQuery}`),
        api.get<FileListResponse>(filesUrl(folderId)),
        // encodeURIComponent: folderId is the ?folder= deep-link query, so a crafted value
        // must never be interpolated raw into a credentialed request path.
        folderId
          ? api.get<FolderShowResponse>(`/disk/folders/${encodeURIComponent(folderId)}`)
          : Promise.resolve(null),
      ]);

      if (myToken !== token) return; // superseded by a newer open

      folders.value = folderRes.data ?? [];
      files.value = fileRes.data ?? [];
      filesCursor.value = fileRes.meta?.next_cursor ?? null;
      filesHasMore.value = (fileRes.meta?.next_cursor ?? null) !== null;
      // The show endpoint returns ANCESTORS only (the materialized path never holds self);
      // the current folder rides in `data`, so append it — every consumer (the crumb row,
      // the up-tile's parent lookup) relies on the trail ENDING at the open folder.
      breadcrumbs.value = crumbRes ? [...(crumbRes.breadcrumbs ?? []), crumbRes.data] : [];
    } catch (err: unknown) {
      if (myToken !== token) return;
      error.value = extractMessage(err);
      filesHasMore.value = false;
    } finally {
      if (myToken === token) loading.value = false;
    }
  }

  /**
   * Open a node in the read-only resources tree. `sys:res` lists the types; `sys:res:<type>`
   * lists that type's buckets; `sys:res:<type>:<key>` lists the bucket's files. The whole
   * tree is fetched once and reused for the type/bucket levels.
   */
  async function openVirtual(id: string): Promise<void> {
    const myToken = (token += 1);
    beginLevel(id);

    try {
      if (resourceTree.value.length === 0) {
        const res = await api.get<ResourceTreeResponse>('/disk/resources');
        if (myToken !== token) return;
        resourceTree.value = res.data ?? [];
      }

      // id = sys:res[:<type>[:<key>]] — keys are YYYY[-MM[-DD]], never contain ':'.
      const [, , typeAlias, bucketKey] = id.split(':');

      if (!typeAlias) {
        viewKind.value = 'resources';
        virtualNodes.value = resourceTree.value.map((type) => ({
          id: type.id,
          labelKey: type.label_key,
          icon: type.icon,
          files_count: type.files_count,
        }));
        return;
      }

      const type = resourceTree.value.find((t) => t.type === typeAlias) ?? null;

      if (!bucketKey) {
        viewKind.value = 'type';
        virtualNodes.value = (type?.buckets ?? []).map((bucket) => ({
          id: bucket.id,
          bucketKey: bucket.key,
          granularity: bucket.granularity,
          files_count: bucket.files_count,
        }));
        return;
      }

      viewKind.value = 'bucket';
      virtualBucket.value = { source: typeAlias, key: bucketKey };
      const fileRes = await api.get<FileListResponse>(bucketFilesUrl(typeAlias, bucketKey));
      if (myToken !== token) return;
      files.value = fileRes.data ?? [];
      filesCursor.value = fileRes.meta?.next_cursor ?? null;
      filesHasMore.value = (fileRes.meta?.next_cursor ?? null) !== null;
    } catch (err: unknown) {
      if (myToken !== token) return;
      error.value = extractMessage(err);
      filesHasMore.value = false;
    } finally {
      if (myToken === token) loading.value = false;
    }
  }

  /** Append the next page of files (infinite scroll: folder, resource bucket, or trash). */
  async function loadMoreFiles(): Promise<void> {
    if (filesLoading.value || !filesHasMore.value || !filesCursor.value) return;

    const myToken = token;
    filesLoading.value = true;
    try {
      const url = viewKind.value === 'trash'
        ? trashFilesUrl(filesCursor.value)
        : virtualBucket.value
          ? bucketFilesUrl(virtualBucket.value.source, virtualBucket.value.key, filesCursor.value)
          : filesUrl(currentFolderId.value, filesCursor.value);
      const res = await api.get<FileListResponse>(url);
      if (myToken !== token) return; // level switched mid-flight

      files.value = [...files.value, ...(res.data ?? [])];
      filesCursor.value = res.meta?.next_cursor ?? null;
      filesHasMore.value = (res.meta?.next_cursor ?? null) !== null;
    } catch {
      // Only stop THIS level's pagination — a stale failing append must not clobber the
      // freshly-opened level's hasMore (which beginLevel just set true).
      if (myToken === token) filesHasMore.value = false;
    } finally {
      if (myToken === token) filesLoading.value = false;
    }
  }

  // --- Mutations -----------------------------------------------------------
  /**
   * Create a folder under `parentId` (null = root). When it lands in the level we
   * are viewing, it is inserted into the grid without a refetch.
   */
  async function createFolder(name: string, parentId: string | null): Promise<DiskFolder> {
    const myToken = token;
    const res = await api.post<{ data: DiskFolder }>('/disk/folders', {
      name,
      parent_id: parentId,
    });
    // Insert only if we are STILL on the parent's level (a navigation mid-request bumps the
    // token; without this guard a fresh GET of the same level could have already listed it,
    // and this prepend would duplicate the tile).
    if (myToken === token && (res.data.parent_id ?? null) === currentFolderId.value) {
      folders.value = [...folders.value, res.data];
    }
    return res.data;
  }

  /**
   * Upload ONE file onto the disk, into `folderId` (null = root). Prepends it to
   * the grid when it lands in the folder currently open (and we have not navigated away).
   */
  async function uploadFile(file: File, folderId: string | null): Promise<DiskFile> {
    const myToken = token;
    const fd = new FormData();
    fd.append('file', file, file.name);
    if (folderId) fd.append('folder_id', folderId);
    const res = await api.post<{ data: DiskFile }>('/disk', fd);
    if (myToken === token && (res.data.folder_id ?? null) === currentFolderId.value) {
      files.value = [res.data, ...files.value];
    }
    return res.data;
  }

  // The rename response is a fresh Resource that OMITS the whenCounted() keys (files_count /
  // has_children) — the update path does not loadCount — so we MERGE it over the existing row
  // rather than replace, or a renamed non-empty folder's tile would drop to "0 items".
  function patchFolderInPlace(myToken: number, id: string, patch: Partial<DiskFolder>): void {
    if (myToken !== token) return;
    folders.value = folders.value.map((f) => (f.id === id ? { ...f, ...patch } : f));
  }
  function patchFileInPlace(myToken: number, id: string, patch: Partial<DiskFile>): void {
    if (myToken !== token) return;
    files.value = files.value.map((f) => (f.id === id ? { ...f, ...patch } : f));
  }
  function dropFolder(myToken: number, id: string): void {
    if (myToken === token) folders.value = folders.value.filter((f) => f.id !== id);
  }
  function dropFile(myToken: number, id: string): void {
    if (myToken === token) files.value = files.value.filter((f) => f.id !== id);
  }

  /** Rename a folder in place (PATCH /disk/folders/{id}). */
  async function renameFolder(id: string, name: string): Promise<void> {
    const myToken = token;
    const res = await api.patch<{ data: DiskFolder }>(`/disk/folders/${id}`, { name });
    patchFolderInPlace(myToken, id, res.data);
  }

  /** Rename a file in place (PATCH /disk/{file}). */
  async function renameFile(id: string, name: string): Promise<void> {
    const myToken = token;
    const res = await api.patch<{ data: DiskFile }>(`/disk/${id}`, { name });
    patchFileInPlace(myToken, id, res.data);
  }

  /**
   * Move a file to `targetId` (null = the workspace root) via the metadata PATCH. It leaves
   * the current level (it now lives elsewhere), so it is dropped from the grid.
   */
  async function moveFile(id: string, targetId: string | null): Promise<void> {
    const myToken = token;
    await api.patch<{ data: DiskFile }>(`/disk/${id}`, { folder_id: targetId });
    dropFile(myToken, id);
  }

  /**
   * Move a folder WITH ITS SUBTREE to `targetId` (null = root) — the dedicated endpoint that
   * re-anchors the whole subtree server-side. It leaves the current level, so drop it.
   */
  async function moveFolder(id: string, targetId: string | null): Promise<void> {
    const myToken = token;
    await api.post<{ data: DiskFolder }>(`/disk/folders/${id}/move`, { target_folder_id: targetId });
    dropFolder(myToken, id);
  }

  /**
   * Copy a file into the disk under a new name + folder (POST /disk/{id}/copy). When the copy
   * lands in the level currently open, it is prepended to the grid without a refetch.
   */
  async function copyFile(id: string, payload: { name: string; folderId: string | null }): Promise<DiskFile> {
    const myToken = token;
    const res = await api.post<{ data: DiskFile }>(`/disk/${id}/copy`, {
      name: payload.name,
      folder_id: payload.folderId,
    });
    if (myToken === token && (res.data.folder_id ?? null) === currentFolderId.value) {
      files.value = [res.data, ...files.value];
    }
    return res.data;
  }

  /** Throw a folder into the disk trash (DELETE /disk/folders/{id}); drop it from the grid. */
  async function trashFolder(id: string): Promise<void> {
    const myToken = token;
    await api.delete(`/disk/folders/${id}`);
    dropFolder(myToken, id);
  }

  /** Throw a file into the disk trash (DELETE /disk/{file}); drop it from the grid. */
  async function trashFile(id: string): Promise<void> {
    const myToken = token;
    await api.delete(`/disk/${id}`);
    dropFile(myToken, id);
  }

  /**
   * Edit a file's metadata (name / description / labels) via PATCH /disk/{file}. The fresh
   * Resource is merged into the current grid row and RETURNED so the drawer can reflect it.
   */
  async function updateFile(
    id: string,
    payload: { name?: string; description?: string | null; labels?: string[] },
  ): Promise<DiskFile> {
    const myToken = token;
    const res = await api.patch<{ data: DiskFile }>(`/disk/${id}`, payload);
    patchFileInPlace(myToken, id, res.data);
    return res.data;
  }

  // --- File history (changelog) ---------------------------------------------
  const changelog = ref<ChangelogEntry[]>([]);
  const changelogCursor = ref<string | null>(null);
  const changelogHasMore = ref(true);
  const changelogLoading = ref(false);
  const changelogLoadingMore = ref(false);
  const changelogError = ref<string | null>(null);
  let changelogToken = 0;

  /**
   * Fetch a file's history (GET /file/{id}/changelog — the generic changelog endpoint keyed on
   * the `file` morph alias). Cursor-paginated (8/page); `{reset}` clears first, else appends.
   */
  async function fetchChangelog(id: string, { reset = true }: { reset?: boolean } = {}): Promise<void> {
    if (!reset && (changelogLoadingMore.value || !changelogHasMore.value)) return;

    const myToken = (changelogToken += 1);
    if (reset) {
      changelogLoading.value = true;
      changelog.value = [];
      changelogCursor.value = null;
      changelogHasMore.value = true;
    } else {
      changelogLoadingMore.value = true;
    }
    changelogError.value = null;

    try {
      const cursor = changelogCursor.value;
      const qs = !reset && cursor ? `?cursor=${encodeURIComponent(cursor)}` : '';
      const res = await api.get<ChangelogResponse>(`/file/${encodeURIComponent(id)}/changelog${qs}`);
      if (myToken !== changelogToken) return;

      changelog.value = reset ? (res.data ?? []) : [...changelog.value, ...(res.data ?? [])];
      changelogCursor.value = res.meta?.next_cursor ?? null;
      changelogHasMore.value = (res.meta?.next_cursor ?? null) !== null;
    } catch (err: unknown) {
      if (myToken !== changelogToken) return;
      changelogError.value = extractMessage(err);
      changelogHasMore.value = false;
    } finally {
      if (myToken === changelogToken) {
        changelogLoading.value = false;
        changelogLoadingMore.value = false;
      }
    }
  }

  // --- Trash actions (P5) ---------------------------------------------------
  /** Everything the restore dialog needs (original location + whether it may land there). */
  async function fetchRestorePreview(id: string): Promise<RestorePreview> {
    const res = await api.get<RestorePreviewResponse>(`/disk/${id}/restore-preview`);
    return res.data;
  }

  /**
   * Restore a trashed file. `target.provided=false` restores in place (the key is OMITTED —
   * the backend keys on its presence); `provided=true` restores into `target.folderId`
   * (null = the workspace root). The row leaves the trash view either way.
   */
  async function restoreFile(id: string, target: { provided: boolean; folderId: string | null }): Promise<void> {
    const myToken = token;
    await api.post(`/disk/${id}/restore`, target.provided ? { target_folder_id: target.folderId } : {});
    dropFile(myToken, id);
  }

  /** Permanently delete a trashed file AND its bytes (DELETE /disk/{id}/force). */
  async function forceDeleteFile(id: string): Promise<void> {
    const myToken = token;
    await api.delete(`/disk/${id}/force`);
    dropFile(myToken, id);
  }

  /** Restore a trashed folder (its parent may have vanished — the backend roots it then). */
  async function restoreFolder(id: string): Promise<void> {
    const myToken = token;
    await api.post(`/disk/folders/${id}/restore`);
    dropFolder(myToken, id);
  }

  /** Drop all cached level state. */
  function reset(): void {
    currentFolderId.value = null;
    folders.value = [];
    files.value = [];
    breadcrumbs.value = [];
    virtualNodes.value = [];
    virtualBucket.value = null;
    viewKind.value = 'folder';
    resourceTree.value = [];
    filesCursor.value = null;
    filesHasMore.value = true;
    loading.value = false;
    filesLoading.value = false;
    error.value = null;
    token += 1;
  }

  return {
    currentFolderId,
    folders,
    files,
    breadcrumbs,
    loading,
    error,
    filesCursor,
    filesHasMore,
    filesLoading,
    viewKind,
    resourceTree,
    virtualNodes,
    virtualBucket,
    changelog,
    changelogHasMore,
    changelogLoading,
    changelogLoadingMore,
    changelogError,
    open,
    loadMoreFiles,
    createFolder,
    uploadFile,
    renameFolder,
    renameFile,
    updateFile,
    fetchChangelog,
    moveFile,
    moveFolder,
    copyFile,
    trashFolder,
    trashFile,
    fetchRestorePreview,
    restoreFile,
    forceDeleteFile,
    restoreFolder,
    reset,
  };
});
