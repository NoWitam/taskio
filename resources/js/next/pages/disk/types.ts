// Disk module types for the "next" frontend.
//
// Mirrors the backend resources 1:1 (do NOT invent fields):
//   - FileResource  (app/modules/Disk/Http/Resources/FileResource.php)
//   - FolderResource (app/modules/Disk/Http/Resources/FolderResource.php)
// FileResource is STRICTLY ADDITIVE and shared with the tasks/report frontends, so
// its `path` is the download URL (not the storage path) and `created_at` is the
// legacy display string; the disk's own fields ride under the *_iso / disk_ keys.

/** A workspace-scoped label (subset the disk cares about). */
export interface DiskLabel {
  id: string;
  name: string;
  color?: string | null;
  icon?: string | null;
}

/** A file, as the disk browser consumes it. */
export interface DiskFile {
  id: string;
  name: string;
  /** The download/serve URL (route `disk.show`), NOT the storage path. */
  path: string;
  /** FileType enum value ('image' | 'document' | …). */
  type: string;
  size: number;
  size_human: string;
  /** Legacy display timestamp ('Y-m-d H:i'); prefer created_at_iso for logic. */
  created_at: string;
  description: string | null;
  mime_type: string | null;
  folder_id: string | null;
  folder?: DiskFolder | null;
  labels?: DiskLabel[];
  /** 'disk' when it lives here in its own right, else a morph alias ('task', …). */
  source: string;
  created_at_iso: string | null;
  updated_at_iso: string | null;
  disk_trashed_at: string | null;
  can_be_updated: boolean;
  can_be_moved: boolean;
  can_be_deleted: boolean;
  /** Trash actions (ownership-gated): the UI must gate on these, not can_be_deleted. */
  can_be_restored: boolean;
  can_be_force_deleted: boolean;
}

/** A folder in the tree. */
export interface DiskFolder {
  id: string;
  name: string;
  parent_id: string | null;
  depth: number;
  /** Ancestor ids, root first. */
  ancestor_ids: string[];
  /** Present only when the caller asked for counts (folders index does). */
  has_children?: boolean;
  children_count?: number;
  files_count?: number;
  created_at: string | null;
  updated_at: string | null;
  deleted_at: string | null;
  can_be_updated: boolean;
  can_be_moved: boolean;
  can_be_deleted: boolean;
  /** Restore (ownership-gated): the trash Restore action gates on this. */
  can_be_restored: boolean;
}

/** A collection envelope (folders index — not paginated). */
export interface FolderListResponse {
  data: DiskFolder[];
}

/**
 * A single folder + its breadcrumbs (folders show). `breadcrumbs` are the ANCESTORS
 * only, root first — the folder itself rides in `data` (the materialized path never
 * holds self), so a consumer wanting a trail ending at the folder appends `data`.
 */
export interface FolderShowResponse {
  data: DiskFolder;
  breadcrumbs: DiskFolder[];
}

/** The cursor-paginated files envelope. */
export interface FileListResponse {
  data: DiskFile[];
  meta?: { next_cursor: string | null };
}

/** A single file envelope (store/update/restore). */
export interface FileResponse {
  data: DiskFile;
}

// --- Resources ("Zasoby") virtual tree (GET /disk/resources) -----------------

/** A created_at bucket under a resource type (its granularity fixes the key format). */
export interface ResourceBucketNode {
  /** Synthetic id: `sys:res:<type>:<key>` (never resolves as a real folder). */
  id: string;
  key: string;
  granularity: 'year' | 'month' | 'day';
  files_count: number;
}

/** A resource type that actually has files (task attachments, report outputs, …). */
export interface ResourceTypeNode {
  /** Synthetic id: `sys:res:<type>`. */
  id: string;
  /** The morph alias (= File.fileable_type): 'task' | 'form_report' | 'form_submission'. */
  type: string;
  /** i18n key (mirrors the FE `disk.resources.*` keys). */
  label_key: string;
  icon: string;
  files_count: number;
  buckets: ResourceBucketNode[];
}

export interface ResourceTreeResponse {
  data: ResourceTypeNode[];
}

/** Which kind of level the browser is showing. */
export type DiskViewKind = 'folder' | 'resources' | 'type' | 'bucket' | 'trash';

// --- File history (GET /file/{id}/changelog — the generic changelog endpoint) ------

export interface ChangelogCauser {
  id: string;
  name: string;
  email: string;
}

/** One audit entry (mirrors the shared ChangelogResource). */
export interface ChangelogEntry {
  id: string | number;
  event: string;
  event_description: string;
  details: Record<string, unknown>;
  causer: ChangelogCauser | null;
  created_at: string;
}

export interface ChangelogResponse {
  data: ChangelogEntry[];
  meta?: { next_cursor: string | null };
}

// --- Restore preview (GET /disk/{id}/restore-preview) ------------------------

/** Why an in-place restore is impossible (mirrors FileService::restorePreview). */
export type RestoreBlockReason = 'detached' | 'folder_missing' | 'folder_trashed';

/** Everything the restore dialog needs to say where the file would land. */
export interface RestorePreview {
  file: DiskFile;
  original_folder: DiskFolder | null;
  /** The original folder's ANCESTORS, root first (the folder itself is above); empty for a root file. */
  breadcrumbs: DiskFolder[];
  can_restore_in_place: boolean;
  reason: RestoreBlockReason | null;
}

export interface RestorePreviewResponse {
  data: RestorePreview;
}

/**
 * A navigable node in the read-only "Zasoby" tree (a resource TYPE or a created_at
 * BUCKET), flattened for the tile grid. Labels stay raw (a `labelKey` to localize, or a
 * `bucketKey` + `granularity` to format) so the view owns i18n.
 */
export interface DiskVirtualNode {
  /** The `sys:res:…` id to navigate into. */
  id: string;
  /** Resource TYPE node → i18n key under `disk.resources.*`. */
  labelKey?: string;
  /** Resource BUCKET node → the raw created_at key (YYYY / YYYY-MM / YYYY-MM-DD). */
  bucketKey?: string;
  granularity?: 'year' | 'month' | 'day';
  icon?: string;
  files_count: number;
}
