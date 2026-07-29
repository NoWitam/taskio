<script setup lang="ts">
// SessionChatView — the generation SESSION as a conversation (R2 Generator / Templatki, sub-stage 2b).
//
// A resource-scoped, FULL-HEIGHT page: a compact PageHeader (type glyph + name + StatusBadge + the
// whole-session Generate + a disabled Save split), then a two-region body — the CONVERSATION column
// (the single scroll owner: a role="log" stream of turns + a sticky composer) and, at ≥ next-xl, the
// docked FinalPostPane; below next-xl the same FinalPostBody is pinned as the last turn.
//
// The conversation reads: a system intro (draft) → the collapsible Setup turn (SessionSlotSetupCard) →
// while generating, an assistant "Generuję…" turn with per-part skeletons → once settled, one
// SessionResultCard turn per part in the content type's DECLARED ORDER. The whole-session Generate
// (POST /generate → 202 → WAIT for the `.generation-session.updated` websocket event) drives the initial
// run; the per-part refine loop — regenerate, the composer refine, undo — plus Save-to-Disk are all LIVE
// (R2 sub-stage 2c/2d; a failed part op is surfaced via last_op_status, {@link SESSION_CAPABILITIES}).
// The run NEVER polls: {@link useSessionSettle} waits for the terminal push then re-fetches. The FE never
// interpolates — the server produces the parts.
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import PageHeader from '../../../ui/patterns/PageHeader.vue';
import StatusBadge from '../../../ui/data/StatusBadge.vue';
import Badge from '../../../ui/primitives/Badge.vue';
import Button, { type ButtonMenuItem } from '../../../ui/primitives/Button.vue';
import Icon from '../../../ui/primitives/Icon.vue';
import EmptyState from '../../../ui/data/EmptyState.vue';
import Skeleton from '../../../ui/data/Skeleton.vue';
import Card from '../../../ui/layout/Card.vue';
import DropdownMenu from '../../../ui/overlay/DropdownMenu.vue';
import DropdownMenuItem from '../../../ui/overlay/DropdownMenuItem.vue';
import SessionTurn from './SessionTurn.vue';
import SessionSlotSetupCard from './SessionSlotSetupCard.vue';
import SessionDirectionCard from './SessionDirectionCard.vue';
import SessionResultCard from './SessionResultCard.vue';
import SessionComposer, { type ComposerTarget } from './SessionComposer.vue';
import FinalPostPane from './FinalPostPane.vue';
import FinalPostBody from './FinalPostBody.vue';
import SessionSaveToDiskModal from './SessionSaveToDiskModal.vue';
import DelegateBotDialog from './DelegateBotDialog.vue';
import SessionFillReportPanel from './SessionFillReportPanel.vue';
import BotAuthorChip from './BotAuthorChip.vue';
import SessionBudgetChip from './SessionBudgetChip.vue';
import SessionBudgetBanner from './SessionBudgetBanner.vue';
import Tooltip from '../../../ui/overlay/Tooltip.vue';
import { useSessionSettle } from './useSessionSettle';
import { hasCreativeDirection } from './sessionDirection';
import { sessionStatusMap } from './sessionStatus';
import { SESSION_CAPABILITIES } from './sessionGating';
import { collectSavableImages, pngFileName, type SaveImageRequest, type SavableImage } from './sessionImages';
import { contentTypeIcon, partKindIcon } from '../templateMeta';
import { isUncapped, meterState } from '../../workspaces/aiUsageMeta';
import { useSessionsStore, isBudgetError } from '../../../app/stores/sessions';
import { useAuthStore } from '../../../app/stores/auth';
import { useAiUsageStore } from '../../../app/stores/aiUsage';
import { useToast } from '../../../app/composables/useToast';
import { useConfirm } from '../../../app/composables/useConfirm';
import { useDebounce } from '../../../app/composables/useDebounce';
import { useI18n } from '../../../app/i18n';
import type { ContentTypeDefinition, ContentTypePart, TemplateSlot } from '../types';
import type { Session, SlotFillMode, SlotFillReport } from '../sessionTypes';

const route = useRoute();
const router = useRouter();
const store = useSessionsStore();
const settler = useSessionSettle();
const auth = useAuthStore();
const aiUsage = useAiUsageStore();
const toast = useToast();
const confirm = useConfirm();
const { t } = useI18n();

// --- AI budget signals (R2 sub-stage 4) -------------------------------------
// The inline chip + blocked banner read the SHARED workspace usage summary. `runBudgetBlocked` is the TYPED
// state set when a generate/refine is refused by the budget ({@see isBudgetError}) — surfaced as THIS banner
// instead of a generic toast; `summary.blocked` is the pre-emptive over-cap signal (banner too). Owner-only
// affordance (raise the limit) is driven by the server `can_manage`, never a client guess.
const runBudgetBlocked = ref(false);
const canManageBudget = computed(() => !!aiUsage.summary?.can_manage);
const showBudgetBanner = computed(() => runBudgetBlocked.value || !!aiUsage.summary?.blocked);
// The workspace is ALREADY over its monthly AI cap (the pre-emptive over-cap signal): the spend affordances
// (whole Generate, the composer refine, per-part regenerate/refine) are disabled proactively — not merely on a
// server 429 — so an over-cap user sees them inert + explained rather than firing a doomed run.
const budgetBlocked = computed(() => !!aiUsage.summary?.blocked);
// The inline chip only renders at/above the warn threshold (or blocked) on a CAPPED workspace — mirror
// SessionBudgetChip's own visibility so the surrounding divider strip isn't an empty border below that threshold.
const showBudgetChip = computed(() => {
  const s = aiUsage.summary;
  if (!s || isUncapped(s)) return false;
  const state = meterState(s);
  return state === 'warn' || state === 'blocked';
});

/** Best-effort refresh of the workspace usage summary (fetch on load, refresh after a run settles). */
async function refreshBudget(): Promise<void> {
  const id = auth.currentWorkspaceId;
  if (id == null) return;
  await aiUsage.fetchAiUsage(id).catch(() => undefined);
}

/** Owner CTA from the banner → the AI-usage page to raise the limit. */
function goToBudget(): void {
  void router.push({ name: 'next.settings.aiUsage' });
}

const id = computed(() => String(route.params.id ?? ''));
const statusMap = computed(() => sessionStatusMap(t));
const caps = SESSION_CAPABILITIES;

// --- Page-level load state --------------------------------------------------
const loading = ref(true);
const errored = ref(false);

// The open session (prefetched by the module layout, kept fresh by the websocket settle re-fetch).
const session = computed<Session | null>(() =>
  store.detail && store.detail.id === id.value ? store.detail : null,
);
const status = computed(() => session.value?.status ?? 'draft');
const generating = computed(() => status.value === 'generating');
const isArchived = computed(() => session.value?.is_archived ?? false);
const canArchive = computed(() => !!session.value?.can_archive);

// --- Content-type parts (declared order) ------------------------------------
const contentTypes = ref<ContentTypeDefinition[]>([]);
const definition = computed<ContentTypeDefinition | null>(
  () => contentTypes.value.find((ct) => ct.id === session.value?.content_type) ?? null,
);
const parts = computed<ContentTypePart[]>(() => definition.value?.parts ?? []);
const typeIcon = computed(() => contentTypeIcon(session.value?.content_type ?? 'post'));

// --- Source-template slots (for the editable setup form) --------------------
const slots = ref<TemplateSlot[]>([]);
const loadingSlots = ref(false);
const slotsError = ref(false);

// --- Editable slot values (local; PATCHed on edit) --------------------------
const slotValues = ref<Record<string, unknown>>({});
const baseline = ref('');
const modified = ref(false);
const hydrating = ref(false);

function stable(map: Record<string, unknown>): string {
  return JSON.stringify(map ?? {});
}

function hydrateFromSession(s: Session): void {
  hydrating.value = true;
  slotValues.value = { ...s.slot_values };
  baseline.value = stable(s.slot_values);
  modified.value = false;
  void nextTick(() => {
    hydrating.value = false;
  });
}

// --- Load orchestration -----------------------------------------------------
async function ensureContentTypes(): Promise<void> {
  if (contentTypes.value.length === 0) {
    contentTypes.value = await store.fetchContentTypes();
  }
}

async function loadSourceTemplate(templateId: string): Promise<void> {
  loadingSlots.value = true;
  slotsError.value = false;
  try {
    const template = await store.fetchSourceTemplate(templateId);
    slots.value = template.slots ?? [];
  } catch {
    slotsError.value = true;
  } finally {
    loadingSlots.value = false;
  }
}

async function load(): Promise<void> {
  loading.value = true;
  errored.value = false;
  // The fill report is transient (it rides the delegate response) — never carry it across a session load.
  fillReport.value = null;
  fillReportBotName.value = '';
  try {
    // Reuse the layout's prefetch when it matches; otherwise fetch.
    const [, s] = await Promise.all([
      ensureContentTypes(),
      session.value ? Promise.resolve(session.value) : store.fetchSession(id.value),
    ]);
    hydrateFromSession(s);
    void loadSourceTemplate(s.template_id);
    // Warm the workspace budget summary so the inline chip / blocked banner reflect reality on open.
    void refreshBudget();
    // A reload that lands mid-run resumes waiting for the terminal push (no poll).
    if (s.status === 'generating') void settle();
  } catch {
    errored.value = true;
  } finally {
    loading.value = false;
  }
}

/**
 * WAIT for the whole-session run to settle via the `.generation-session.updated` websocket event (never a
 * poll), then re-fetch. `settled === false` means the safety timeout / Reverb-absent give-up fired without a
 * terminal state — surface a non-blocking "refresh" hint instead of hanging.
 */
async function settle(): Promise<void> {
  const { session: settledSession, settled } = await settler.waitForSettle(id.value);
  // A run consumed budget — refresh the meter so the chip / banner reflect the new spend.
  void refreshBudget();
  // Keep the local edit buffer, but announce + settle the artifact.
  scrollToBottom();
  if (!settled) {
    toast.warning(t('generator.sessions.toasts.settleTimeout'));
    return;
  }
  if (settledSession?.status === 'failed') {
    // A run that STARTED under budget but crossed the cap MID-run fails soft (no 429): surface the
    // budget-specific `last_op_error` (mirrors runPartOp) instead of a generic "failed". `refreshBudget()`
    // above also flips `summary.blocked` → the blocked banner shows once it lands.
    toast.danger(settledSession.last_op_error || t('generator.sessions.toasts.generateFailed'));
  }
}

onMounted(load);
watch(id, (next, prev) => {
  if (next && next !== prev) void load();
});

// --- Generate (the one live generative path) --------------------------------
async function onGenerate(): Promise<void> {
  if (!session.value || generating.value || !caps.wholeGenerate) return;
  // The setup-time fill report is stale once a run consumes the inputs.
  fillReport.value = null;
  // A fresh attempt clears any prior transient budget refusal (it re-asserts below if refused again).
  runBudgetBlocked.value = false;
  try {
    await store.generate(id.value);
    baseline.value = stable(slotValues.value);
    modified.value = false;
    scrollToBottom();
    void settle();
  } catch (err: unknown) {
    const code = (err as { response?: { status?: number } })?.response?.status;
    // 409 = a run is already in flight — just wait for its terminal event.
    if (code === 409) {
      void settle();
      return;
    }
    // A budget/over-cap refusal surfaces the typed blocked banner (not a generic toast) + a fresh meter.
    if (isBudgetError(err)) {
      runBudgetBlocked.value = true;
      void refreshBudget();
      return;
    }
    toast.danger(t('generator.sessions.toasts.generateError'));
  }
}

// --- Per-part refine loop (R2 sub-stage 2d) --------------------------------
// The part currently being regenerated / refined. While a part op is in flight the session status is
// `generating` (like a whole run) but we keep the RESULTS visible (only the targeted card spins) instead of
// blowing the whole surface back to skeletons — that is the difference between a whole generate and a part op.
const partOpKey = ref<string | null>(null);
const partOpBusy = computed(() => partOpKey.value !== null);
/** The whole-session skeleton turn shows ONLY for a whole generate — a per-part op keeps the results visible. */
const wholeGenerating = computed(() => generating.value && !partOpBusy.value);

/**
 * Run one async part op (regenerate / refine): claim (202 → `generating`), WAIT for the terminal
 * `.generation-session.updated` websocket event to settle (never a poll), then READ `last_op_status` to surface
 * the outcome. This is the review-mandated failed-op surfacing — a failed refine otherwise returns `ready` at
 * the SAME version (a silent no-op), so without this toast the user would think nothing happened. `'failed'` →
 * a danger toast of `last_op_error`; `'ok'` → a success toast; an un-settled wait (timeout / Reverb-absent) →
 * a non-blocking "refresh" hint.
 */
async function runPartOp(partKey: string, action: () => Promise<Session>): Promise<void> {
  if (!session.value || generating.value || partOpBusy.value) return;
  partOpKey.value = partKey;
  runBudgetBlocked.value = false;
  try {
    await action(); // 202 — the session is now `generating` (reconciled into detail).
    scrollToBottom();
    const { session: settled, settled: didSettle } = await settler.waitForSettle(id.value);
    // The op consumed budget — refresh the meter so the chip / banner reflect the new spend.
    void refreshBudget();
    if (!didSettle) {
      toast.warning(t('generator.sessions.toasts.settleTimeout'));
    } else if (settled?.last_op_status === 'failed') {
      toast.danger(settled.last_op_error || t('generator.sessions.toasts.partOpFailed'));
    } else if (settled?.last_op_status === 'ok') {
      toast.success(t('generator.sessions.toasts.partOpOk'));
    }
  } catch (err: unknown) {
    const code = (err as { response?: { status?: number } })?.response?.status;
    // A budget/over-cap refusal surfaces the typed blocked banner (not a generic toast) + a fresh meter.
    if (isBudgetError(err)) {
      runBudgetBlocked.value = true;
      void refreshBudget();
      return;
    }
    // 409 = a run is already in flight (one op per session); 422 = a non-refinable / invalid instruction.
    toast.danger(code === 409 ? t('generator.sessions.toasts.busy') : t('generator.sessions.toasts.partOpError'));
  } finally {
    partOpKey.value = null;
  }
}

function onRegeneratePart(partKey: string): void {
  void runPartOp(partKey, () => store.regeneratePart(id.value, partKey));
}
function onRefinePart(partKey: string, instruction: string): void {
  void runPartOp(partKey, () => store.refinePart(id.value, partKey, instruction));
}
function onComposerSend(payload: { partKey: string; instruction: string }): void {
  onRefinePart(payload.partKey, payload.instruction);
}

/** UNDO is SYNCHRONOUS — no poll. 200 reverts to the previous version; 409 = generating / nothing to undo. */
async function onUndoPart(partKey: string): Promise<void> {
  if (!session.value || generating.value || partOpBusy.value) return;
  try {
    await store.undoPart(id.value, partKey);
    toast.success(t('generator.sessions.toasts.undone'));
    scrollToBottom();
  } catch (err: unknown) {
    const code = (err as { response?: { status?: number } })?.response?.status;
    toast.danger(
      code === 409 ? t('generator.sessions.toasts.undoUnavailable') : t('generator.sessions.toasts.undoError'),
    );
  }
}

// --- Archive / un-archive (R2 sub-stage 2d) --------------------------------
async function onToggleArchive(): Promise<void> {
  if (!session.value || !canArchive.value) return;
  try {
    if (isArchived.value) {
      await store.unarchiveSession(id.value);
      toast.success(t('generator.sessions.toasts.unarchived'));
    } else {
      await store.archiveSession(id.value);
      toast.success(t('generator.sessions.toasts.archived'));
    }
  } catch {
    toast.danger(t('generator.sessions.toasts.error'));
  }
}

// --- Delegate to a bot / undo the delegation (R2 sub-stage 3) --------------
// A delegated session gains a bot AUTHOR (ADDITIVE — the human stays owner/creator). `can_delegate` gates
// DELEGATE (creator AND editable draft/ready), so it auto-disables mid-run. UNDO is gated SEPARATELY on
// `can_undo_delegation` (owner && delegated && not mid-run) — it is NOT tied to an editable state, so a
// FAILED delegated session can still be reverted (undo is only blocked mid-run). The fill report rides the
// delegate RESPONSE only (not the persisted wire), so we hold the latest here to render it.
const canDelegate = computed(() => !!session.value?.can_delegate);
const canUndoDelegation = computed(() => !!session.value?.can_undo_delegation);
const isDelegated = computed(() => !!session.value?.is_delegated);
// Why the delegate button is disabled — distinguish a mid-run session (temporary) from a non-editable one
// (e.g. failed / not owner), so the tooltip doesn't blame "generating" when that isn't the real reason.
const delegateDisabledReason = computed(() =>
  generating.value
    ? t('generator.sessions.delegate.buttonDisabledGenerating')
    : t('generator.sessions.delegate.buttonDisabled'),
);
const botAuthor = computed(() => session.value?.bot_author ?? null);

const delegateOpen = ref(false);
const delegating = ref(false);
const fillReport = ref<SlotFillReport | null>(null);
const fillReportBotName = ref('');

function openDelegate(): void {
  if (!canDelegate.value) return;
  delegateOpen.value = true;
}

/** Map a delegate failure to a localized toast: 409 mid-run, 403 non-owner, 422 non-editable, else generic. */
function delegateErrorMessage(code?: number): string {
  if (code === 409) return t('generator.sessions.delegate.toasts.busy');
  if (code === 403) return t('generator.sessions.delegate.toasts.forbidden');
  if (code === 422) return t('generator.sessions.delegate.toasts.notEditable');
  return t('generator.sessions.delegate.toasts.error');
}

async function onDelegateConfirm(payload: {
  botId: string;
  autoGenerate: boolean;
  fillMode: SlotFillMode;
}): Promise<void> {
  if (!session.value || delegating.value) return;
  delegating.value = true;
  try {
    const { session: updated, fillReport: report } = await store.delegate(
      id.value,
      payload.botId,
      payload.autoGenerate,
      payload.fillMode,
    );
    // Reflect the bot-filled slot_values in the editable buffer + surface the report.
    hydrateFromSession(updated);
    fillReport.value = report;
    fillReportBotName.value = updated.bot_author?.name ?? '';
    delegateOpen.value = false;

    if (payload.autoGenerate) {
      // The backend already claimed a run (202 → generating) — settle via the SAME websocket wait as generate.
      toast.success(t('generator.sessions.delegate.toasts.autoOk', '', { name: fillReportBotName.value }));
      scrollToBottom();
      void settle();
    } else if (report.nothing_to_fill) {
      // Gaps mode with no empty inputs: the server made NO AI call — say so instead of "filled 0 inputs".
      toast.info(
        t('generator.sessions.delegate.toasts.nothingToFill', '', { name: fillReportBotName.value }),
      );
      scrollToSetup();
    } else {
      // Gate-before-spend: ready-to-generate; the human reviews the filled inputs, then hits Generate.
      toast.success(
        t('generator.sessions.delegate.toasts.ok', '', {
          name: fillReportBotName.value,
          count: report.filled.length,
        }),
      );
      scrollToSetup();
    }
  } catch (err: unknown) {
    const code = (err as { response?: { status?: number } })?.response?.status;
    toast.danger(delegateErrorMessage(code));
  } finally {
    delegating.value = false;
  }
}

async function onUndoDelegation(): Promise<void> {
  const botId = session.value?.bot_author?.id;
  if (!session.value || !isDelegated.value || !canUndoDelegation.value || !botId) return;
  const ok = await confirm({
    title: t('generator.sessions.delegate.undoConfirmTitle'),
    message: t('generator.sessions.delegate.undoConfirmMessage'),
    confirmLabel: t('generator.sessions.delegate.undo'),
    cancelLabel: t('common.cancel'),
    variant: 'danger',
  });
  if (!ok) return;
  try {
    const updated = await store.undoDelegation(id.value, botId);
    hydrateFromSession(updated);
    fillReport.value = null;
    fillReportBotName.value = '';
    toast.success(t('generator.sessions.delegate.toasts.undone'));
  } catch (err: unknown) {
    const code = (err as { response?: { status?: number } })?.response?.status;
    toast.danger(
      code === 409 ? t('generator.sessions.delegate.toasts.busy') : t('generator.sessions.delegate.toasts.undoError'),
    );
  }
}

// --- Edit slot values (debounced PATCH; server-gated by can_edit) -----------
const canEdit = computed(() => !!session.value?.can_edit && caps.editSlots);

const debouncedPatch = useDebounce(() => {
  if (!session.value || !canEdit.value) return;
  void store
    .patchSession(id.value, { slot_values: slotValues.value })
    .catch(() => toast.danger(t('generator.sessions.toasts.editError')));
}, 600);

watch(
  slotValues,
  () => {
    if (hydrating.value || !canEdit.value) return;
    modified.value = status.value === 'ready' && stable(slotValues.value) !== baseline.value;
    debouncedPatch();
  },
  { deep: true },
);

// --- Header affordances -----------------------------------------------------
const canGenerate = computed(
  () => !!session.value?.can_generate && caps.wholeGenerate && !budgetBlocked.value,
);
const generateLabel = computed(() =>
  status.value === 'draft'
    ? t('generator.sessions.generate')
    : t('generator.sessions.regenerateAll'),
);
// When the workspace is over cap the disabled Generate explains WHY (over the generic label) and points the
// user at the AI-usage page via the blocked banner below.
const generateTooltip = computed(() =>
  budgetBlocked.value ? t('generator.sessions.budget.affordanceBlocked') : generateLabel.value,
);
const isReady = computed(() => status.value === 'ready');

// --- Save-to-Disk (2c): the page owns the ONE save dialog; children emit `save` requests up ---
function partLabelFor(part: ContentTypePart): string {
  return t(`generator.templates.editor.partLabel.${part.kind}`, part.label);
}
/** Every produced image across the parts (image parts + scene images), in declared order. */
const savableImages = computed<SavableImage[]>(() =>
  collectSavableImages(parts.value, session.value?.results ?? null, partLabelFor),
);
const canSave = computed(() => caps.saveToDisk && savableImages.value.length > 0);
/** When there are several produced images, the header split menu lets the user pick which to save. */
const saveMenu = computed<ButtonMenuItem[]>(() =>
  savableImages.value.length > 1
    ? savableImages.value.map((image, index) => ({
        value: String(index),
        label: t('generator.sessions.result.saveImageNamed', '', { label: image.label }),
        icon: 'folder',
      }))
    : [],
);

const saveOpen = ref(false);
const savePartKey = ref<string | null>(null);
const saveName = ref('');
function onSaveRequest(request: SaveImageRequest): void {
  savePartKey.value = request.partKey;
  saveName.value = request.name;
  saveOpen.value = true;
}
/** Header split button → build the request from a chosen produced image (seed the default name). */
function requestSaveImage(image: SavableImage | undefined): void {
  if (!image) return;
  const base = session.value?.name ? `${session.value.name} — ${image.label}` : image.label;
  onSaveRequest({ partKey: image.partKey, name: pngFileName(base) });
}

// --- Creative direction (the direction layer) -------------------------------
// The run's DERIVED creative frame — read-only provenance that explains why the parts below agree with each
// other. DETAIL-only on the wire (the list projection omits it), so it is read off the OPEN session, never a
// list row. A full-run claim NULLS it (it is re-derived per run), so it is legitimately absent while
// `generating` and arrives with the settled session — the turn simply appears then. The visibility gate is
// the SHARED predicate the card itself uses, so an empty/null direction renders no turn at all (a
// SessionTurn would otherwise leave an empty gutter glyph + author line behind).
const creativeDirection = computed(() => session.value?.creative_direction ?? null);
const showDirection = computed(() => hasCreativeDirection(creativeDirection.value));

// --- Turn helpers -----------------------------------------------------------
const showIntro = computed(() => status.value === 'draft');
// Results stay visible during a per-part op (a whole generate still swaps to the skeleton turn via wholeGenerating).
const showResults = computed(
  () =>
    !!session.value?.results &&
    (status.value === 'ready' || status.value === 'failed' || partOpBusy.value),
);
function partResult(part: ContentTypePart) {
  return session.value?.results?.[part.key];
}

/**
 * The refinable, produced parts (text_body / script / image_plan — NOT scene_plan) that the composer can
 * target, in declared order but with the PRIMARY text part (post body) first so it is the sensible default.
 */
const refinableTargets = computed<ComposerTarget[]>(() => {
  const results = session.value?.results;
  if (!showResults.value || !results) return [];
  const eligible = parts.value.filter(
    (p) =>
      (p.kind === 'text_body' || p.kind === 'script' || p.kind === 'image_plan' || p.kind === 'shot_list') &&
      results[p.key]?.status === 'ok',
  );
  const primaryText = eligible.filter((p) => p.kind === 'text_body');
  const rest = eligible.filter((p) => p.kind !== 'text_body');
  return [...primaryText, ...rest].map((p) => ({ key: p.key, label: partLabelFor(p) }));
});

// --- Scroll (single owner: the conversation region) -------------------------
const convoRef = ref<HTMLElement | null>(null);
const setupRef = ref<HTMLElement | null>(null);
function scrollToBottom(): void {
  void nextTick(() => {
    const el = convoRef.value;
    if (el) el.scrollTop = el.scrollHeight;
  });
}
/** Scroll the human back to the setup form (e.g. from the fill report's "Complete inputs"). */
function scrollToSetup(): void {
  void nextTick(() => setupRef.value?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
}
watch(status, (s) => {
  if (s === 'ready' || s === 'failed') scrollToBottom();
});

const skeletonKeys = Array.from({ length: 4 }, (_, i) => i);
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col gap-next-4">
    <!-- Header: identity + whole-session generate + (disabled) save. -->
    <PageHeader
      size="sm"
      :icon="typeIcon"
      :title="session?.name ?? '…'"
    >
      <template #meta>
        <StatusBadge v-if="session" :status="status" :status-map="statusMap" size="sm" />
        <!-- Bot author chip (ADDITIVE — the human stays owner; shown when delegated). Icon + text. -->
        <BotAuthorChip v-if="botAuthor" :author="botAuthor" size="xs" class="text-next-xs" />
        <!-- Archived marker (never color-only: an icon + text Badge). -->
        <Badge v-if="isArchived" variant="neutral" tone="subtle" size="sm" icon="archive">
          {{ t('generator.sessions.archived') }}
        </Badge>
      </template>
      <template #actions>
        <!-- Delegate to a bot (the "let the bot fill the form" alternative). Disabled + explained mid-run / non-owner. -->
        <Tooltip
          v-if="session"
          :label="canDelegate ? t('generator.sessions.delegate.button') : delegateDisabledReason"
        >
          <Button
            variant="outline"
            leading-icon="sparkles"
            :disabled="!canDelegate"
            @click="openDelegate"
          >
            {{ t('generator.sessions.delegate.button') }}
          </Button>
        </Tooltip>
        <!-- Whole-session Generate. Over cap it is DISABLED with a budget tooltip (the blocked banner below
             points to the AI-usage page); otherwise the tooltip just names the action. -->
        <Tooltip v-if="session" :label="generateTooltip">
          <Button
            :variant="status === 'draft' ? 'primary' : 'outline'"
            :leading-icon="status === 'draft' ? 'sparkles' : 'rotate-ccw'"
            :loading="generating"
            :disabled="!canGenerate"
            @click="onGenerate"
          >
            {{ generateLabel }}
          </Button>
        </Tooltip>
        <Button
          v-if="isReady"
          variant="primary"
          leading-icon="folder"
          :disabled="!canSave"
          :menu-items="saveMenu"
          :menu-aria-label="t('generator.sessions.result.saveToDisk')"
          @click="requestSaveImage(savableImages[0])"
          @menu-select="(value) => requestSaveImage(savableImages[Number(value)])"
        >
          {{ t('generator.sessions.result.saveToDisk') }}
        </Button>
        <!-- Overflow: archive / un-archive (creator-only, gated on can_archive). -->
        <DropdownMenu v-if="session" placement="bottom-end" :aria-label="t('generator.sessions.menu')">
          <template #trigger="{ props: triggerProps }">
            <button
              type="button"
              class="inline-flex h-10 w-10 items-center justify-center rounded-next-md text-next-fg transition-colors hover:bg-next-accent hover:text-next-accent-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-next-ring"
              :aria-haspopup="triggerProps['aria-haspopup']"
              :aria-expanded="triggerProps['aria-expanded'] === 'true'"
              :aria-controls="triggerProps['aria-controls']"
              :aria-label="t('generator.sessions.menu')"
            >
              <Icon name="more-vertical" />
            </button>
          </template>
          <DropdownMenuItem
            :icon="isArchived ? 'rotate-ccw' : 'archive'"
            :disabled="!canArchive"
            :label="isArchived ? t('generator.sessions.unarchive') : t('generator.sessions.archive')"
            @select="onToggleArchive"
          >
            {{ isArchived ? t('generator.sessions.unarchive') : t('generator.sessions.archive') }}
          </DropdownMenuItem>
          <!-- Undo delegation (renders only when delegated; ACTIONABLE via can_undo_delegation — i.e. not
               mid-run — so a FAILED delegated session can still be reverted, unlike delegate's can_delegate). -->
          <DropdownMenuItem
            v-if="isDelegated"
            icon="rotate-ccw"
            :disabled="!canUndoDelegation"
            :label="t('generator.sessions.delegate.undo')"
            @select="onUndoDelegation"
          >
            {{ t('generator.sessions.delegate.undo') }}
          </DropdownMenuItem>
        </DropdownMenu>
      </template>
    </PageHeader>

    <!-- Error (load failed). -->
    <EmptyState
      v-if="errored"
      variant="error"
      :title="t('generator.sessions.errors.title')"
      :description="t('generator.sessions.errors.description')"
    >
      <template #action>
        <Button variant="outline" size="sm" leading-icon="rotate-ccw" @click="load">
          {{ t('generator.sessions.errors.retry') }}
        </Button>
      </template>
    </EmptyState>

    <!-- Initial loading skeleton. -->
    <div v-else-if="loading && !session" class="flex flex-col gap-next-4" aria-hidden="true">
      <div class="flex flex-col gap-next-2 rounded-next-lg border border-next-border p-next-4">
        <Skeleton variant="text" width="20%" />
        <Skeleton variant="text" width="60%" />
      </div>
      <div class="flex flex-col gap-next-2 rounded-next-lg border border-next-border p-next-4">
        <Skeleton variant="text" width="90%" />
        <Skeleton variant="text" width="100%" />
        <Skeleton variant="text" width="70%" />
      </div>
    </div>

    <!-- The chat + dock. -->
    <div v-else-if="session" class="flex min-h-0 flex-1 gap-next-4">
      <!-- Conversation column (the single scroll owner). -->
      <div class="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden rounded-next-xl border border-next-border bg-next-bg">
        <div
          ref="convoRef"
          class="flex min-h-0 flex-1 flex-col gap-next-4 overflow-y-auto p-next-4"
          role="log"
          aria-live="polite"
          :aria-label="t('generator.sessions.conversation')"
        >
          <!-- System intro (draft). -->
          <SessionTurn v-if="showIntro" role="system">
            <div class="rounded-next-lg bg-next-muted px-next-3 py-next-2 text-next-sm text-next-muted-foreground">
              {{ t('generator.sessions.intro') }}
            </div>
          </SessionTurn>

          <!-- Setup turn (collapsible). -->
          <SessionTurn
            role="user"
            :author="t('generator.sessions.turn.you')"
            :aria-label="t('generator.sessions.setup.title')"
          >
            <div ref="setupRef" class="w-full max-w-2xl">
              <SessionSlotSetupCard
                v-model="slotValues"
                :slots="slots"
                :status="status"
                :can-edit="canEdit"
                :modified="modified"
                :loading-slots="loadingSlots"
                :slots-error="slotsError"
                @generate="onGenerate"
              />
            </div>
          </SessionTurn>

          <!-- Delegation fill report (transient) — what the bot filled / skipped / left for the human. -->
          <SessionTurn
            v-if="fillReport"
            role="assistant"
            :author="fillReportBotName || t('generator.sessions.turn.assistant')"
            :aria-label="t('generator.sessions.delegate.report.title', '', { name: fillReportBotName })"
          >
            <div class="w-full max-w-2xl">
              <SessionFillReportPanel
                :report="fillReport"
                :bot-name="fillReportBotName"
                :slots="slots"
                @dismiss="fillReport = null"
                @complete-inputs="scrollToSetup"
              />
            </div>
          </SessionTurn>

          <!-- The run's derived CREATIVE DIRECTION (read-only, collapsed): the shared frame the parts below
               were made to. Absent while a full run is claimed — it is re-derived per run. -->
          <SessionTurn
            v-if="showDirection"
            role="assistant"
            :author="t('generator.sessions.turn.assistant')"
            :aria-label="t('generator.sessions.direction.title')"
          >
            <div class="w-full max-w-2xl">
              <SessionDirectionCard :direction="creativeDirection" />
            </div>
          </SessionTurn>

          <!-- Whole generate: an assistant turn with per-part skeletons (a per-part op keeps results visible). -->
          <SessionTurn
            v-if="wholeGenerating"
            role="assistant"
            :author="t('generator.sessions.turn.assistant')"
            :aria-label="t('generator.sessions.generating')"
          >
            <div class="flex w-full flex-col gap-next-3">
              <div class="flex items-center gap-next-2 text-next-sm text-next-muted-foreground">
                <Icon name="loader" class="animate-spin" aria-hidden="true" />
                <span>{{ t('generator.sessions.generating') }}</span>
              </div>
              <Card v-for="part in parts" :key="part.key" variant="default">
                <template #header>
                  <div class="flex items-center gap-next-2">
                    <Icon :name="partKindIcon(part.kind)" class="text-next-muted-foreground" aria-hidden="true" />
                    <span class="text-next-xs font-next-medium text-next-muted-foreground">
                      {{ t(`generator.templates.editor.partLabel.${part.kind}`, part.label) }}
                    </span>
                  </div>
                </template>
                <div v-if="part.kind === 'image_plan'" aria-hidden="true">
                  <Skeleton variant="rect" width="100%" height="8rem" radius="md" />
                </div>
                <div v-else class="flex flex-col gap-next-2" aria-hidden="true">
                  <Skeleton variant="text" width="90%" />
                  <Skeleton variant="text" width="100%" />
                  <Skeleton variant="text" width="60%" />
                </div>
              </Card>
            </div>
          </SessionTurn>

          <!-- Results: one turn per part (declared order). -->
          <template v-if="showResults">
            <SessionTurn
              v-for="part in parts"
              :key="part.key"
              role="assistant"
              :author="t('generator.sessions.turn.assistant')"
              :aria-label="t('generator.sessions.result.aria', '', { label: t(`generator.templates.editor.partLabel.${part.kind}`, part.label) })"
            >
              <SessionResultCard
                :part="part"
                :result="partResult(part)"
                :session-id="id"
                :session-name="session.name"
                :generating="generating"
                :blocked="budgetBlocked"
                :busy="partOpKey === part.key"
                :part-history="session.part_history?.[part.key]"
                :part-history-map="session.part_history"
                :busy-part-key="partOpKey"
                @save="onSaveRequest"
                @regenerate="onRegeneratePart"
                @refine="(payload) => onRefinePart(payload.partKey, payload.instruction)"
                @undo="onUndoPart"
              />
            </SessionTurn>
          </template>

          <!-- Pinned "Gotowy post" turn (below next-xl only — the dock owns it above). -->
          <div v-if="isReady" class="next-xl:hidden">
            <SessionTurn role="assistant" :aria-label="t('generator.sessions.final.title')">
              <FinalPostBody
                :parts="parts"
                :results="session.results"
                :status="status"
                :session-id="id"
                :session-name="session.name"
                @save="onSaveRequest"
              />
            </SessionTurn>
          </div>
        </div>

        <!-- AI budget signals (R2 sub-stage 4): the blocked banner when a run is refused / the workspace is
             over cap; otherwise the compact warn chip (self-hides below the warn threshold / when uncapped). -->
        <div
          v-if="showBudgetBanner || showBudgetChip"
          class="border-t border-next-border bg-next-card px-next-3 pt-next-3"
        >
          <SessionBudgetBanner
            v-if="showBudgetBanner"
            :summary="aiUsage.summary"
            :can-manage="canManageBudget"
            :dismissible="runBudgetBlocked && !aiUsage.summary?.blocked"
            @dismiss="runBudgetBlocked = false"
            @manage="goToBudget"
          />
          <div v-else class="flex justify-end">
            <SessionBudgetChip :summary="aiUsage.summary" />
          </div>
        </div>

        <!-- Composer (LIVE in 2d): a free-text instruction that refines a produced part. -->
        <!-- A blocked (over-cap) user can't refine either — disable the composer alongside the mid-run case. -->
        <SessionComposer :targets="refinableTargets" :disabled="generating || budgetBlocked" @send="onComposerSend" />
      </div>

      <!-- Docked final-post rail (≥ next-xl). -->
      <FinalPostPane
        :parts="parts"
        :results="session.results"
        :status="status"
        :session-id="id"
        :session-name="session.name"
        :aria-label="t('generator.sessions.final.title')"
        @save="onSaveRequest"
      />
    </div>

    <!-- The ONE Save-to-Disk dialog for the whole surface (per-part, per-scene, dock + header all feed it). -->
    <SessionSaveToDiskModal
      v-model:open="saveOpen"
      :session-id="id"
      :part-key="savePartKey"
      :default-name="saveName"
    />

    <!-- Delegate-to-bot picker (bot list + auto-generate toggle DEFAULT OFF). -->
    <DelegateBotDialog v-model:open="delegateOpen" :submitting="delegating" @confirm="onDelegateConfirm" />
  </div>
</template>
