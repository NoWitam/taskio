// AI-usage store for the isolated "next" frontend (Pinia setup store).
//
// Owns SERVER STATE for the workspace AI COST meter (R2 sub-stage 4): the $-first usage SUMMARY (any
// member reads it) plus the OWNER-only monthly cap write. The page owns the view state; this store does
// the HTTP and caches the latest summary so the usage page AND the generator's inline budget signals read
// ONE source. All owner-only affordances are driven off `summary.can_manage` (SERVER-authoritative) — never
// a client role guess.
//
// Backend contract (VERIFIED against AiUsageSummaryResource + WorkspaceAiUsageController — do NOT invent
// fields):
//   GET   /workspaces/{workspace}/ai-usage        (authz: any member)  → { data: AiUsageSummary }
//   PATCH /workspaces/{workspace}/ai-usage/cap     (authz: OWNER only)  body { monthly_cost_cap: number>=0 | null }
//         → { data: AiUsageSummary }   (the REFRESHED summary — reconcile with it)
//   Cap semantics: null CLEARS the workspace override (inherit the env default); a positive value is the
//   workspace cap; 0 is explicit UNLIMITED for this workspace.
//
// Self-contained: NO import from the legacy `resources/js/`. Mirrors the workspaces / members store style.
import { defineStore } from 'pinia';
import { ref } from 'vue';
import { api } from '../lib/api';

/** The reporting currency (the backend fixes this at 'USD' today). */
export type AiUsageCurrency = 'USD';

/** Where the effective monthly cap comes from — for the UI's cap-source hint. */
export type AiUsageCapSource = 'workspace' | 'default' | 'unlimited';

/** The metered AI channels the per-channel breakdown reports. */
export type AiUsageChannelKey = 'ai_text' | 'ai_image_edit' | 'ai_image_generate';

/**
 * The actor a spend is attributed to. `user`/`bot`/`workflow_run` are real morph types; `others` is the
 * synthetic bucket the backend folds the long tail into (beyond the top-5 by $). Kept open (`string`) so an
 * unforeseen actor type still renders (via the localized per-type fallback) rather than breaking the wire.
 */
export type AiUsageActorType = 'user' | 'bot' | 'workflow_run' | 'others' | (string & {});

/** One channel's month-to-date spend ($ primary, tokens secondary). */
export interface AiUsageChannel {
  channel: AiUsageChannelKey | string;
  cost: number;
  tokens: number;
}

/**
 * One actor's month-to-date spend. `display_name` is resolved at READ time via the morph map and may be
 * null (e.g. a `workflow_run` actor, a departed member, or the `others` bucket) → the UI localizes a
 * per-type fallback. `icon` is a raw model attribute (not a guaranteed next icon key), so the UI renders a
 * TYPE-based glyph and does not trust it as an icon name.
 */
export interface AiUsageActor {
  actor_type: AiUsageActorType;
  actor_id: string | null;
  display_name: string | null;
  icon: string | null;
  cost: number;
  tokens: number;
}

/** The current billing period (calendar month) + when it resets. */
export interface AiUsagePeriod {
  /** `YYYY-MM`. */
  month: string;
  /** ISO datetime the window resets (the start of next month). */
  resets_at: string;
}

/**
 * The whole workspace AI-usage summary. $-first: `cost_*` are the primary figures, `tokens_used` is a
 * secondary display, and every number is an ESTIMATE (`estimated: true`). `cost_cap` 0 / `cap_source`
 * 'unlimited' = NO LIMIT (`cost_remaining` is then null). `can_manage` is the OWNER-only capability flag
 * the UI gates the cap editor on.
 *
 * NOTE (wire fidelity): `cost_remaining` is `number | null` — the backend sends null when uncapped (the FE
 * contract's shorthand said `number`; the real resource is nullable, which this mirrors).
 */
export interface AiUsageSummary {
  currency: AiUsageCurrency;
  estimated: boolean;
  cost_used: number;
  cost_cap: number;
  cost_remaining: number | null;
  cap_source: AiUsageCapSource;
  warn_ratio: number;
  warn_reached: boolean;
  blocked: boolean;
  period: AiUsagePeriod;
  tokens_used: number;
  per_channel: AiUsageChannel[];
  per_actor: AiUsageActor[];
  can_manage: boolean;
}

/** Single-resource response wrapper (`{ data: AiUsageSummary }`). */
interface AiUsageResponse {
  data: AiUsageSummary;
}

export const useAiUsageStore = defineStore('next-ai-usage', () => {
  // --- State ---------------------------------------------------------------
  /** The latest fetched summary (shared by the usage page + the generator budget signals), or null. */
  const summary = ref<AiUsageSummary | null>(null);
  /** True while the first (or a refresh) GET is in flight. */
  const loading = ref(false);
  /** True when the last GET failed (the page shows an error + retry). */
  const errored = ref(false);
  /** True while a PATCH cap write is in flight (the editor's Save spinner). */
  const saving = ref(false);

  // A fetch token so a workspace switch / re-fetch always supersedes an older in-flight GET.
  let token = 0;

  // --- Actions -------------------------------------------------------------
  /**
   * Fetch the $-first usage summary for a workspace the caller is a member of (`GET …/ai-usage`). Reads
   * `res.data` and caches it. Rejects on failure (setting `errored`) so the caller can branch; a stale
   * (superseded) response is dropped.
   */
  async function fetchAiUsage(workspaceId: string | number): Promise<AiUsageSummary> {
    const myToken = (token += 1);
    loading.value = true;
    errored.value = false;
    try {
      const res = await api.get<AiUsageResponse>(`/workspaces/${workspaceId}/ai-usage`);
      if (myToken === token) summary.value = res.data;
      return res.data;
    } catch (err) {
      if (myToken === token) errored.value = true;
      throw err;
    } finally {
      if (myToken === token) loading.value = false;
    }
  }

  /**
   * Set (or clear) the workspace's monthly $ cap (`PATCH …/ai-usage/cap`, OWNER only). `cap`:
   *   - a positive number → this workspace's cap,
   *   - 0                 → explicit UNLIMITED for this workspace,
   *   - null              → CLEAR the override (inherit the env default).
   * The endpoint returns the REFRESHED summary; we reconcile the cache with it so the new effective
   * cap/source shows immediately. Rejects (403/422/409) for the caller to surface.
   */
  async function updateAiCap(
    workspaceId: string | number,
    cap: number | null,
  ): Promise<AiUsageSummary> {
    saving.value = true;
    try {
      const res = await api.patch<AiUsageResponse>(`/workspaces/${workspaceId}/ai-usage/cap`, {
        monthly_cost_cap: cap,
      });
      summary.value = res.data;
      return res.data;
    } finally {
      saving.value = false;
    }
  }

  /** Drop the cached summary (e.g. on a workspace switch / logout). */
  function reset(): void {
    summary.value = null;
    loading.value = false;
    errored.value = false;
    saving.value = false;
    token += 1;
  }

  return {
    // state
    summary,
    loading,
    errored,
    saving,
    // actions
    fetchAiUsage,
    updateAiCap,
    reset,
  };
});
