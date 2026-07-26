// Disk store for the "next" frontend (Pinia setup store).
//
// Owns SERVER STATE for the disk BROWSER: the contents of ONE folder at a time —
// its subfolders, its (cursor-paginated) files, and the breadcrumb trail to it.
// The PAGE owns the "which folder" (from the `/next/disk/<id>` path param) and calls open();
// this store fetches the level and appends file pages for infinite scroll.
//
// Backend contract (verified — do NOT invent fields):
//   GET /disk/items[/<folder>]?cursor=<c> → { data: DiskItem[], meta: { next_cursor },
//                                             folder: DiskFolder|null, breadcrumbs: DiskFolder[] }
//     The ONE folder-browse endpoint: subfolders THEN files as a single cursor-paginated list
//     (each item tagged `kind`), page size 24; omit <folder> for the workspace ROOT.
//   GET /disk?source=<t>&bucket=<k>&cursor → { data: DiskFile[], meta } (Zasoby buckets, files-only)
//   GET /disk?trashed=1&cursor / GET /disk/folders?trashed=1          (the disk trash)
//
// Self-contained: NO import from the legacy `resources/js/`.
import { defineStore } from 'pinia';
import { ref } from 'vue';
import { api } from '../lib/api';
import type {
  ChangelogEntry,
  ChangelogResponse,
  DiskFile,
  DiskFilters,
  DiskFolder,
  DiskItem,
  DiskItemsResponse,
  DiskViewKind,
  DiskVirtualNode,
  DraftInfo,
  FolderLabelMode,
  FileListResponse,
  FolderListResponse,
  ResourceTreeResponse,
  ResourceTypeNode,
  RestorePreview,
  RestorePreviewResponse,
} from '../../pages/disk/types';
import { DEFAULT_DISK_FILTERS, defaultDirFor } from '../../pages/disk/types';

/** Prefix of every synthetic "Zasoby" id — none of these ever resolve as a real folder. */
const VIRTUAL_ROOT = 'sys:res';

/** The synthetic id of the disk trash level (same `:folder` path-param navigation channel). */
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

  // --- Filters (the disk filter bar; folder view only) ---------------------
  /** Mirrors the `GET /disk/items` query params 1:1; persists across folder navigation. */
  const filters = ref<DiskFilters>({ ...DEFAULT_DISK_FILTERS });

  /** Append the active (non-default) filters to a query, so the URL and saved views stay lean. */
  function appendFilterParams(params: URLSearchParams): void {
    const f = filters.value;
    f.types.forEach((t) => params.append('types[]', t));
    if (f.q.trim()) params.set('q', f.q.trim());
    if (f.searchIn !== 'name') params.set('search_in', f.searchIn);
    if (f.where !== 'folder') params.set('search_where', f.where);
    if (f.sort !== 'name') params.set('sort', f.sort);
    if (f.dir !== defaultDirFor(f.sort)) params.set('dir', f.dir);
  }

  /**
   * The unified folder-items endpoint (subfolders + disk-native files as ONE cursor-paginated
   * list): the folder rides the PATH (null = root), the active filters ride the query. Resource
   * files (task attachments, report outputs) still live ONLY under the read-only "Zasoby" tree.
   */
  function itemsUrl(folderId: string | null, cursor?: string | null): string {
    const base = folderId ? `/disk/items/${encodeURIComponent(folderId)}` : '/disk/items';
    const params = new URLSearchParams();
    if (cursor) params.set('cursor', cursor);
    appendFilterParams(params);
    const qs = params.toString();
    return qs ? `${base}?${qs}` : base;
  }

  /** Replace the filters and re-fetch the current level (filters apply to the folder view only). */
  function applyFilters(next: DiskFilters): Promise<void> {
    filters.value = next;
    return viewKind.value === 'folder' ? openFolder(currentFolderId.value) : Promise.resolve();
  }

  /** Clear the filters back to the defaults, then re-fetch. */
  function resetFilters(): Promise<void> {
    return applyFilters({ ...DEFAULT_DISK_FILTERS });
  }

  /** Split one page of mixed items back into folders + files (they arrive folders-first). */
  function splitItems(items: DiskItem[]): { folders: DiskFolder[]; files: DiskFile[] } {
    const fs: DiskFolder[] = [];
    const fl: DiskFile[] = [];
    for (const item of items) {
      item.kind === 'folder' ? fs.push(item) : fl.push(item);
    }
    return { folders: fs, files: fl };
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

  /**
   * Open a real folder via the ONE unified endpoint: its subfolders + files as a single
   * cursor-paginated page (split back into `folders`/`files` by kind), plus the breadcrumb trail.
   */
  async function openFolder(folderId: string | null): Promise<void> {
    const myToken = (token += 1);
    beginLevel(folderId);
    viewKind.value = 'folder';

    try {
      const res = await api.get<DiskItemsResponse>(itemsUrl(folderId));

      if (myToken !== token) return; // superseded by a newer open

      const split = splitItems(res.data ?? []);
      folders.value = split.folders;
      files.value = split.files;
      filesCursor.value = res.meta?.next_cursor ?? null;
      filesHasMore.value = (res.meta?.next_cursor ?? null) !== null;
      // The endpoint returns ANCESTORS only (the path never holds self) plus the folder object,
      // so append it — every consumer (the crumb row, the up-tile's parent lookup) relies on the
      // trail ENDING at the open folder.
      breadcrumbs.value = res.folder ? [...(res.breadcrumbs ?? []), res.folder] : [];
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

  /** Append the next page (infinite scroll: folder items, resource bucket, or trash). */
  async function loadMoreFiles(): Promise<void> {
    if (filesLoading.value || !filesHasMore.value || !filesCursor.value) return;

    const myToken = token;
    filesLoading.value = true;
    try {
      if (viewKind.value === 'folder') {
        // Unified folder browse: ONE cursor over folders THEN files — split the page by kind.
        const res = await api.get<DiskItemsResponse>(itemsUrl(currentFolderId.value, filesCursor.value));
        if (myToken !== token) return; // level switched mid-flight
        const split = splitItems(res.data ?? []);
        if (split.folders.length) folders.value = [...folders.value, ...split.folders];
        if (split.files.length) files.value = [...files.value, ...split.files];
        filesCursor.value = res.meta?.next_cursor ?? null;
        filesHasMore.value = (res.meta?.next_cursor ?? null) !== null;
      } else {
        // Trash / resource bucket are files-only (their own endpoints).
        const url = viewKind.value === 'trash'
          ? trashFilesUrl(filesCursor.value)
          : bucketFilesUrl(virtualBucket.value!.source, virtualBucket.value!.key, filesCursor.value);
        const res = await api.get<FileListResponse>(url);
        if (myToken !== token) return;
        files.value = [...files.value, ...(res.data ?? [])];
        filesCursor.value = res.meta?.next_cursor ?? null;
        filesHasMore.value = (res.meta?.next_cursor ?? null) !== null;
      }
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

  /**
   * Live-patch a file's `has_draft` flag on the matching grid row — the optimistic layer that keeps
   * the browser's draft indicator in sync the instant the preview editor creates/clears a draft, with
   * no refetch. No-op (and no reactivity churn) when the file is not in the loaded list. NOT
   * token-guarded: it mirrors a local draft-state change valid for whatever level is showing the row;
   * a (re)fetch reloads the authoritative server value, which wins. The row-merge helpers above spread
   * fresh Resources OVER the row, so a server response carrying `has_draft` simply reconciles it.
   */
  function setFileHasDraft(fileId: string, value: boolean): void {
    if (!files.value.some((f) => f.id === fileId)) return;
    files.value = files.value.map((f) => (f.id === fileId ? { ...f, has_draft: value } : f));
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

  /**
   * Fetch one folder's full detail (GET /disk/folders/{id}) — notably its governance `labels`,
   * which the lean items grid does not carry. Used to hydrate the folder preview.
   */
  async function fetchFolder(id: string): Promise<DiskFolder> {
    const res = await api.get<{ data: DiskFolder }>(`/disk/folders/${id}`);
    return res.data;
  }

  /**
   * Fetch one file's metadata as JSON (GET /disk/{id}/info — the bare GET /disk/{id} serves the
   * BINARY). The preview's deep-link resolution + refresh-after-save source.
   */
  async function fetchFile(id: string): Promise<DiskFile> {
    const res = await api.get<{ data: DiskFile }>(`/disk/${encodeURIComponent(id)}/info`);
    return res.data;
  }

  /**
   * Overwrite a file's CONTENT (the preview editor's "Zapisz") via POST /disk/{id}/content.
   * The row keeps its identity (name/placement/labels); the fresh Resource is merged into the
   * grid row and returned so the preview reflects the new size/mime immediately.
   */
  async function replaceFileContent(id: string, blob: Blob, filename: string): Promise<DiskFile> {
    const myToken = token;
    const form = new FormData();
    form.append('file', new File([blob], filename, { type: blob.type }));
    const res = await api.post<{ data: DiskFile }>(`/disk/${encodeURIComponent(id)}/content`, form);
    patchFileInPlace(myToken, id, res.data);
    return res.data;
  }

  /**
   * Run an AI transformation over `content` with `prompt` (POST /disk/ai/text) and RETURN the
   * rewritten text — the text preview replaces its editor buffer with it (saving stays manual via
   * Zapisz / Zapisz jako). Whole-content edit; no selection handling. Errors bubble to the caller,
   * which surfaces `err.response.data.message` (422 validation / 502 provider) as a toast.
   */
  async function aiEditText(content: string, prompt: string): Promise<string> {
    const res = await api.post<{ data: { text: string } }>('/disk/ai/text', { content, prompt });
    return res.data.text;
  }

  // --- Per-user edit drafts (autosave) --------------------------------------
  // The preview editor autosaves its in-progress edit here (POST) so a refresh/crash never loses
  // work, fetches it back on reopen (GET / GET base) and clears it on an explicit Save (DELETE).
  // Nothing here touches the MAIN file — that is overwritten only via replaceFileContent. Drafts
  // are per-user and self-contained: the caller sends ONLY the bases not yet uploaded each POST,
  // and the server GCs any base blob whose id is no longer in `manifest.baseIds`.

  /**
   * Upsert this user's draft: the FE-owned `manifest`, the `baseVersion` (the file's updated_at_iso
   * when the draft began), and one `base_{id}` PNG part per NEW base. Returns the live base ids the
   * server now holds + the draft's updated_at, so the caller can mark those bases as uploaded.
   */
  async function saveDraft(
    fileId: string,
    manifest: unknown,
    baseVersion: string | null,
    newBases: { id: number; blob: Blob }[],
  ): Promise<{ base_ids: number[]; updated_at: string }> {
    const form = new FormData();
    form.append('manifest', JSON.stringify(manifest));
    if (baseVersion !== null) form.append('base_version', baseVersion);
    for (const base of newBases) {
      form.append(`base_${base.id}`, new File([base.blob], `base_${base.id}.png`, { type: 'image/png' }));
    }
    const res = await api.post<{ data: DraftInfo }>(`/disk/${encodeURIComponent(fileId)}/draft`, form);
    return { base_ids: res.data.base_ids, updated_at: res.data.updated_at };
  }

  /** Fetch this user's draft for the file, or null when none exists (the endpoint 404s → api throws). */
  async function fetchDraft(fileId: string): Promise<DraftInfo | null> {
    try {
      const res = await api.get<{ data: DraftInfo }>(`/disk/${encodeURIComponent(fileId)}/draft`);
      return res.data;
    } catch (err: unknown) {
      if ((err as { response?: { status?: number } })?.response?.status === 404) return null;
      throw err;
    }
  }

  /** Fetch one image base blob of this user's draft (PNG). */
  async function fetchDraftBase(fileId: string, baseId: number): Promise<Blob> {
    return api.get<Blob>(`/disk/${encodeURIComponent(fileId)}/draft/base/${baseId}`, { responseType: 'blob' });
  }

  /** Discard this user's draft (idempotent — the endpoint 204s even when none exists). */
  async function deleteDraft(fileId: string): Promise<void> {
    await api.delete(`/disk/${encodeURIComponent(fileId)}/draft`);
  }

  /**
   * Edit a folder's metadata (name / description / icon / governance labels) via PATCH
   * /disk/folders/{id}. Like updateFile, the fresh Resource is merged into the grid row and
   * RETURNED so the drawer reflects it. `labels` carries the full set as `[{id, mode}]`.
   */
  async function updateFolder(
    id: string,
    payload: {
      name?: string;
      description?: string | null;
      icon?: string | null;
      labels?: { id: string; mode: FolderLabelMode }[];
    },
  ): Promise<DiskFolder> {
    const myToken = token;
    const res = await api.patch<{ data: DiskFolder }>(`/disk/folders/${id}`, payload);
    patchFolderInPlace(myToken, id, res.data);
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

  // The morph the current changelog belongs to — captured on reset so a "load more" page keeps
  // hitting the same endpoint (only one drawer, file OR folder, is ever open at a time).
  let changelogModule: 'file' | 'folder' = 'file';

  /**
   * Fetch an item's history via the generic changelog endpoint (GET /{module}/{id}/changelog),
   * keyed on the `file` or `folder` morph alias. Cursor-paginated (8/page); `{reset}` clears
   * first, else appends.
   */
  async function fetchChangelog(
    id: string,
    { reset = true, module }: { reset?: boolean; module?: 'file' | 'folder' } = {},
  ): Promise<void> {
    if (!reset && (changelogLoadingMore.value || !changelogHasMore.value)) return;

    const myToken = (changelogToken += 1);
    if (reset) {
      changelogModule = module ?? 'file';
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
      const res = await api.get<ChangelogResponse>(`/${changelogModule}/${encodeURIComponent(id)}/changelog${qs}`);
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
    filters,
    applyFilters,
    resetFilters,
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
    updateFolder,
    setFileHasDraft,
    fetchFolder,
    fetchFile,
    replaceFileContent,
    aiEditText,
    saveDraft,
    fetchDraft,
    fetchDraftBase,
    deleteDraft,
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
