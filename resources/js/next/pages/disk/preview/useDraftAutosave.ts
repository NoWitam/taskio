// useDraftAutosave — server-side autosave DRAFTS for the preview editor (image + text).
//
// So in-progress edits survive a refresh/crash: the stage's edit state is debounced-serialized to a
// per-user server draft (POST /disk/{id}/draft). The MAIN file is still overwritten only on an explicit
// Save — after which `clear()` discards the now-obsolete draft. On reopen a `pendingDraft` (if any) drives
// a Restore/Discard banner and PAUSES autosave until the user decides, so we never clobber their draft with
// the current file state before they choose.
//
// Best-effort by design: autosave failures are swallowed (never a toast storm); only an explicit Restore
// surfaces feedback. Image bases are deduped — each POST uploads only the bases not yet on the server.
import { ref, watch, type Ref } from 'vue';
import { useDiskStore } from '../../../app/stores/disk';
import { useDebounce } from '../../../app/composables/useDebounce';
import type { DraftInfo, DraftManifest, ImageDraftManifest } from '../types';

/** ~2.5s after the last change — long enough to collapse a burst of slider ticks into one save. */
const AUTOSAVE_DELAY = 2500;

export interface UseDraftAutosaveOptions {
  /** The file being edited (a getter so it stays correct if the prop object is replaced). */
  fileId: () => string;
  /** The file's `updated_at_iso` — pinned as the draft's `base_version` + compared to flag staleness. */
  fileVersion: () => string | null;
  kind: 'image' | 'text';
  /** Whether there are unsaved edits (autosave only runs while dirty). */
  isDirty: Ref<boolean>;
  /** A cheap change pulse to debounce on (image: `editor.revision`; text: the content ref). */
  changeSignal: Ref<number | string>;
  /** Whether the file is KNOWN to have a draft (the list/info `has_draft`). When it returns false the
   *  restore probe is SKIPPED entirely — no draft, no `GET /{file}/draft`. Absent → always probe. */
  hasDraft?: () => boolean;
  /** Snapshot the current edit as a draft manifest + any base blobs. */
  serialize: () => Promise<{ manifest: DraftManifest; bases: { id: number; blob: Blob }[] }>;
  /** IMAGE: rebuild the engine from a restored manifest (wired to `editor.hydrateDraft`). */
  hydrate?: (manifest: ImageDraftManifest, fetchBase: (id: number) => Promise<Blob>) => Promise<void>;
  /** TEXT: apply the restored content back into the buffer. */
  onRestoreText?: (content: string) => void;
}

export interface UseDraftAutosaveReturn {
  /** The draft found on reopen (drives the banner); null once restored/discarded or when none exists. */
  pendingDraft: Ref<DraftInfo | null>;
  /** The file changed since the pending draft was saved (`base_version` mismatch) — a soft warning. */
  stale: Ref<boolean>;
  /** Restore the pending draft into the editor, then resume autosave. Rejects if a base is missing. */
  restore: () => Promise<void>;
  /** Delete the pending draft, then resume autosave. */
  discard: () => Promise<void>;
  /** Drop the draft after an explicit Save (the file now equals the edit, so the draft is obsolete). */
  clear: () => Promise<void>;
}

/** The base ids a manifest references (text drafts have none). */
function referencedIds(manifest: DraftManifest): number[] {
  return manifest.kind === 'image' ? manifest.baseIds : [];
}

export function useDraftAutosave(options: UseDraftAutosaveOptions): UseDraftAutosaveReturn {
  const store = useDiskStore();

  const pendingDraft = ref<DraftInfo | null>(null);
  const stale = ref(false);

  // Autosave is PAUSED while a draft is pending (probe in flight, or a banner awaiting a decision), so
  // we never overwrite the user's draft with the current file state before they choose.
  let paused = true;
  // Image bases already on the server — the diff each POST sends only the NEW ones.
  let uploadedBaseIds = new Set<number>();
  // ALL draft network ops (save POST, clear/discard DELETE) run through this serial chain so they can
  // never reorder: a Save's `clear()` DELETE always lands AFTER an autosave POST that was already in
  // flight, instead of racing it on separate workers (which would let the POST resurrect the draft the
  // user just saved). Each link swallows so one failure never locks the queue.
  let chain: Promise<unknown> = Promise.resolve();
  function enqueue<T>(task: () => Promise<T>): Promise<T> {
    const run = chain.then(task, task); // run after the previous op SETTLES (fulfilled OR rejected)
    chain = run.then(() => undefined, () => undefined);
    return run;
  }

  // --- Restore probe (on create / stage remount) -------------------------------
  async function probe(): Promise<void> {
    // The list/info already told us whether a draft exists — skip the probe request entirely when it
    // does NOT (no `GET /{file}/draft` 404 on every open). Absent flag → probe, to stay correct.
    if (options.hasDraft && !options.hasDraft()) {
      pendingDraft.value = null;
      resumeAutosave();
      return;
    }
    try {
      const draft = await store.fetchDraft(options.fileId());
      if (draft) {
        pendingDraft.value = draft;
        stale.value = draft.base_version !== options.fileVersion();
        // Stay paused until restore()/discard().
      } else {
        pendingDraft.value = null;
        resumeAutosave(); // no draft → autosave is live
      }
    } catch {
      // Probe failed (offline / unexpected) — behave as if there is no draft; autosave stays best-effort.
      pendingDraft.value = null;
      resumeAutosave();
    }
  }

  // --- Autosave ----------------------------------------------------------------
  function scheduleSave(): void {
    if (paused || !options.isDirty.value) return;
    // Enqueued so it serializes with any in-flight save AND with clear()/discard() DELETEs.
    void enqueue(async () => {
      if (paused || !options.isDirty.value) return; // state may have changed while queued
      const { manifest, bases } = await options.serialize();
      const newBases = bases.filter((base) => !uploadedBaseIds.has(base.id));
      await store.saveDraft(options.fileId(), manifest, options.fileVersion(), newBases);
      uploadedBaseIds = new Set(referencedIds(manifest));
      // The draft now exists on the server → light the browser grid indicator for this file. Only on
      // success (a throw above skips this), so the flag matches server truth; idempotent per save.
      store.setFileHasDraft(options.fileId(), true);
    }).catch(() => {
      // Autosave is best-effort — swallow (a refresh simply loses the last <delay> of edits).
      if (import.meta.env?.DEV) console.debug('[disk] draft autosave failed (will retry on the next change)');
    });
  }

  const debouncedSave = useDebounce(() => scheduleSave(), AUTOSAVE_DELAY);

  /** Flip autosave live and, if edits already exist (e.g. made during the probe), kick one save. */
  function resumeAutosave(): void {
    paused = false;
    if (options.isDirty.value) debouncedSave();
  }

  watch(options.changeSignal, () => {
    if (paused || !options.isDirty.value) return;
    debouncedSave();
  });

  // --- User decisions ----------------------------------------------------------
  function seedUploaded(draft: DraftInfo): void {
    uploadedBaseIds = new Set(draft.base_ids);
  }

  async function restore(): Promise<void> {
    const draft = pendingDraft.value;
    if (!draft) return;
    try {
      if (draft.kind === 'image' && options.hydrate) {
        await options.hydrate(draft.manifest as ImageDraftManifest, (id) => store.fetchDraftBase(options.fileId(), id));
      } else if (draft.kind === 'text' && options.onRestoreText) {
        options.onRestoreText(String(draft.manifest?.content ?? ''));
      }
    } catch (err) {
      // A missing/undecodable base — give up on the draft and let the caller fall back to the file.
      pendingDraft.value = null;
      paused = false;
      throw err;
    }
    seedUploaded(draft); // the restored bases are already on the server
    pendingDraft.value = null;
    paused = false;
  }

  async function discard(): Promise<void> {
    pendingDraft.value = null;
    paused = false; // resume editing/autosaving; a resumed save enqueues AFTER this delete
    await enqueue(async () => {
      await store.deleteDraft(options.fileId()).catch(() => {});
      uploadedBaseIds = new Set(); // server draft gone → a later save re-uploads every base
    });
    store.setFileHasDraft(options.fileId(), false); // draft gone → clear the grid indicator
    if (options.isDirty.value) debouncedSave(); // autosave edits made before the discard, if any
  }

  async function clear(): Promise<void> {
    debouncedSave.cancel(); // drop a not-yet-fired autosave
    // Enqueued so the DELETE runs AFTER any autosave POST already in flight (which would otherwise
    // resurrect the draft the user just saved). Reset uploaded ids only once the delete has landed.
    await enqueue(async () => {
      await store.deleteDraft(options.fileId()).catch(() => {});
      uploadedBaseIds = new Set();
    });
    store.setFileHasDraft(options.fileId(), false); // draft gone (obsolete after the Save) → clear the indicator
  }

  void probe();

  return { pendingDraft, stale, restore, discard, clear };
}
