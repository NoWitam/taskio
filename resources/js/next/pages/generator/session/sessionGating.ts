// sessionGating — the SINGLE capability switch for the generation-session chat surface (R2 sub-stage 2b→2d).
//
// The conversational GRAMMAR ships in full from day one — every affordance renders so the surface reads
// as a conversation — and each capability is WIRED by flipping ONE flag here, with zero layout churn.
// Keep this the ONLY place a session-surface capability decision lives.
//
// LIVE in 2b (endpoints exist): create-from-template, edit slot values (draft/ready), the WHOLE-SESSION
//   generate + poll, delete, and the pure-client Copy.
// LIVE in 2c (endpoints exist): Save-to-Disk for a PRODUCED image — the per-part image Save button, the
//   per-scene image Save, and the "Gotowy post" dock's Save. NOTE: only IMAGE parts are savable — the
//   backend re-reads the produced PNG; a text part has no image, so text→Disk stays out of scope.
// LIVE in 2d (endpoints now exist — the per-part refine loop): per-part Regenerate (fresh variation → poll),
//   the composer's + the per-part "Dopracuj" free-text instructed refine (revise the current output → poll),
//   Undo (synchronous revert to the previous version), and the "Wersja N" version display. Nothing on the
//   session surface remains gated after 2d.
export interface SessionCapabilities {
  /** Whole-session "Generuj" / "Generuj ponownie całość" (POST /generate → poll). */
  wholeGenerate: boolean;
  /** Edit slot values (PATCH; server-gated to draft/ready via `can_edit`). */
  editSlots: boolean;
  /** Delete the session (DELETE). */
  delete: boolean;
  /** Copy a produced text part to the clipboard (pure client — no backend). */
  copy: boolean;
  /** Per-part "Generuj ponownie" — a fresh variation of that part (wired in 2d). */
  regeneratePart: boolean;
  /** The composer's + the per-part "Dopracuj" free-text instructed refine of the current output (wired in 2d). */
  composerSend: boolean;
  /** Save a produced IMAGE part to Disk (wired in 2c — text parts have no image, so they stay inert). */
  saveToDisk: boolean;
  /** Revert a produced part to its previous version ("Cofnij") — synchronous (wired in 2d). */
  undo: boolean;
  /** Per-part version display — the "Wersja N" badge (wired in 2d). */
  history: boolean;
}

/** The 2d capability set — everything on the session surface is now live. */
export const SESSION_CAPABILITIES: SessionCapabilities = {
  wholeGenerate: true,
  editSlots: true,
  delete: true,
  copy: true,
  regeneratePart: true,
  composerSend: true,
  saveToDisk: true,
  undo: true,
  history: true,
};
