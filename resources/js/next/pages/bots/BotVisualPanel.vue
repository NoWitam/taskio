<script setup lang="ts">
// BotVisualPanel — the "Wygląd" module's panel inside the bot editor: the likeness STRIP, the written
// IDENTITY the image is drawn from, and the CREATE zone that generates a new one.
//
// It lives in its own component (not inline in the drawer) because it owns a long-running ASYNC flow —
// auto-save → queue → follow → refetch — that has nothing to do with the rest of the form.
//
// FOUR THINGS ABOUT THE BACKEND SHAPE THIS DESIGN.
//
// 1. THE PROMPT IS COMPOSED FROM THE SAVED MODULE, not from this form
//    (`BotVisualIdentityService::composePrompt` reads `$bot->visualIdentity()`). So "Generate" SAVES the
//    bot first, and says so before you click — otherwise you would edit the wardrobe, generate, and get
//    an image of the previous one.
//
// 2. A BOT SAVE OVERWRITES `visual` WHOLE (`BotDTO::normalizeVisual`), while a generation writes
//    `candidates` ASYNCHRONOUSLY in a worker. Sending a stale snapshot would DELETE a candidate that
//    landed while the drawer was open. Hence the state split this component is built around: the TEXT
//    fields are form state (v-models), and the FILE POINTERS are a read-only MIRROR of the server
//    (`server`), replaced only from a server response. This panel never writes into that mirror.
//
// 3. THE MODULE KEEPS 6 IMAGES and a seventh generation permanently evicts the oldest unapproved one, so
//    a full strip warns BEFORE the click, not after the deletion.
//
// 4. `enabled = false` DOES NOT BLOCK GENERATING — it only stops sessions using the likeness. So an OFF
//    module is NOT greyed out here (that would say "you can't work on this", which is false); the
//    difference is carried by a warning Alert. This is a deliberate departure from the knowledge /
//    task-execution panels, whose fields really are inert when off.
//
// The daily image cap is not exposed anywhere on the wire — it only surfaces reactively as a 429, which
// is why `errors.dailyCap` is the residual arm of the three-way 429 discrimination below.
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import Alert from '../../ui/feedback/Alert.vue';
import Badge from '../../ui/primitives/Badge.vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import FormField from '../../ui/forms/FormField.vue';
import Textarea from '../../ui/forms/Textarea.vue';
import FileDropzone from '../../ui/forms/FileDropzone.vue';
import SegmentedControl, { type SegmentOption } from '../../ui/forms/SegmentedControl.vue';
import StringListInput from './StringListInput.vue';
import BotVisualCandidates from './BotVisualCandidates.vue';
import BotVisualImage from './BotVisualImage.vue';
import DiskFilePickerModal from '../disk/DiskFilePickerModal.vue';
import { useAiImageJob } from '../../app/composables/useAiImageJob';
import { useConfirm } from '../../app/composables/useConfirm';
import { useToast } from '../../app/composables/useToast';
import { useAiUsageStore } from '../../app/stores/aiUsage';
import { useAuthStore } from '../../app/stores/auth';
import { useBotsStore } from '../../app/stores/bots';
import { useI18n } from '../../app/i18n';
import type { DiskFile } from '../disk/types';
import type { BotDetail, BotVisualIdentity, BotVisualMode } from './types';
import { BOT_VISUAL_MAX_CANDIDATES } from './types';

/** Per-field server messages the drawer maps a 422 onto. */
export interface BotVisualErrors {
  descriptor?: string | null;
  wardrobe?: string | null;
  aesthetic?: string | null;
  prohibitions?: string | null;
  reference?: string | null;
  instruction?: string | null;
}

const props = withDefaults(
  defineProps<{
    /** The bot being edited, or null for an unsaved one (the create zone is then blocked). */
    botId: string | null;
    botName: string;
    /** The module toggle (owned by the drawer's nav switch) — drives the "off" warning only. */
    enabled: boolean;
    /** The SERVER's file pointers (candidates / approved / reference). Never written here. */
    server: BotVisualIdentity | null;
    /** The editor form has unsaved changes → "Generate" saves first. */
    dirty?: boolean;
    /** Server field errors from the last save / generate. */
    errors?: BotVisualErrors;
    /**
     * Persist the bot (the drawer's own validate + PUT). Resolves with the fresh bot, or null when the
     * save failed — the drawer has already surfaced the field errors, so this panel just stops.
     * A function PROP (not an event) because the flow must AWAIT the save before it may spend money.
     * `overrides.canonicalFileId` lets "clear the approval" ride the same save (there is no endpoint
     * for it — the approval is just a field of the module).
     */
    save: (overrides?: { canonicalFileId?: string | null }) => Promise<BotDetail | null>;
  }>(),
  { dirty: false, errors: () => ({}) },
);

const emit = defineEmits<{
  /** A fresh bot arrived (save / generate / approve / delete) — the drawer re-mirrors `server`. */
  (e: 'sync', bot: BotDetail): void;
}>();

// The written identity is FORM state (see note 2) — the drawer owns it, this panel edits it.
const descriptor = defineModel<string>('descriptor', { default: '' });
const wardrobe = defineModel<string>('wardrobe', { default: '' });
const aesthetic = defineModel<string>('aesthetic', { default: '' });
const prohibitions = defineModel<string[]>('prohibitions', { default: () => [] });

const { t } = useI18n();
const router = useRouter();
const toast = useToast();
const confirm = useConfirm();
const store = useBotsStore();
const auth = useAuthStore();
const aiUsage = useAiUsageStore();

/** The shared queued-image wait (the SAME machinery the Disk editor follows). */
const job = useAiImageJob();

const MAX = BOT_VISUAL_MAX_CANDIDATES;
/** Explicit ids so the safety notice can be wired to the wardrobe field WITHOUT dropping its own hint. */
const WARDROBE_ID = 'bot-visual-wardrobe';
const SAFETY_NOTICE_ID = 'bot-visual-safety-notice';
/** The upload cap mirrors the FormRequest (`max:25600` KB). */
const MAX_REFERENCE_BYTES = 25 * 1024 * 1024;
/** How long before a still-running generation is called out as slow. */
const SLOW_AFTER_MS = 180_000;

const candidates = computed(() => props.server?.candidates ?? []);
const canonicalFileId = computed(() => props.server?.canonical_file_id ?? null);
const atCapacity = computed(() => candidates.value.length >= MAX);

// --- Create zone state ------------------------------------------------------
const mode = ref<BotVisualMode>(props.server?.reference_file_id ? 'reference' : 'description');
/** A fresh upload (exclusive with `referenceFileId` — the server accepts exactly one source). */
const referenceFile = ref<File | null>(null);
/** An existing file id: the module's stored reference, or a Disk pick. */
const referenceFileId = ref<string | null>(props.server?.reference_file_id ?? null);
/** The user changed the reference themselves — stop mirroring the server until the next run. */
const referenceTouched = ref(false);
const instruction = ref('');
const diskPickerOpen = ref(false);

// Mirror the module's stored reference until the user takes over (a pick, an upload or a clear).
watch(
  () => props.server?.reference_file_id ?? null,
  (id) => {
    if (!referenceTouched.value) referenceFileId.value = id;
  },
);

// --- Run state --------------------------------------------------------------
const savingForRun = ref(false);
const generating = ref(false);
const slow = ref(false);
let slowTimer: ReturnType<typeof setTimeout> | null = null;
/** The file id whose approve / delete is in flight (that tile's control spins). */
const busyFileId = ref<string | null>(null);

/** The last failure of a RUN (not a field error): a localized message + how to act on it. */
interface RunFailure {
  message: string;
  /** The provider's moderation refused the content — the fix is the wardrobe / descriptor. */
  safety?: boolean;
  /** The monthly $ budget is out: the owner can raise it, everyone else must ask them to. */
  budget?: 'manage' | 'contact';
  /** A retry can plausibly succeed (a moderation refusal is deterministic — retrying buys the same no). */
  retryable?: boolean;
}
const failure = ref<RunFailure | null>(null);
/** The wardrobe field is highlighted for a moment after a moderation refusal points at it. */
const wardrobeFlagged = ref(false);
let flagTimer: ReturnType<typeof setTimeout> | null = null;

/** Wraps the generate Button so focus can be handed back to it after the strip empties. */
const generateWrapRef = ref<HTMLElement | null>(null);

onBeforeUnmount(() => {
  if (slowTimer) clearTimeout(slowTimer);
  if (flagTimer) clearTimeout(flagTimer);
  job.cancel();
});

// --- Gating (mirrors the server's own refusals so a doomed click costs no round trip) --------
const noBot = computed(() => props.botId === null);
/**
 * `composePrompt` 422s when the descriptor, wardrobe, aesthetic AND this run's instruction are all
 * empty — a reference anchor alone describes nothing. Mirrored here so the button explains itself.
 */
const hasMaterial = computed(
  () =>
    !!descriptor.value.trim() ||
    !!wardrobe.value.trim() ||
    !!aesthetic.value.trim() ||
    !!instruction.value.trim(),
);
const needsReference = computed(
  () => mode.value === 'reference' && !referenceFile.value && !referenceFileId.value,
);
const busy = computed(() => savingForRun.value || generating.value);
const canGenerate = computed(
  () => !noBot.value && hasMaterial.value && !needsReference.value && !busy.value,
);

/** Why "Generate" is unavailable — always rendered, never a bare disabled control. */
const generateReason = computed<string | null>(() => {
  if (noBot.value) return t('bots.editor.visual.generateNeedsBot');
  if (busy.value) return t('bots.editor.visual.generateBusy');
  if (needsReference.value) return t('bots.editor.visual.referenceRequired');
  if (!hasMaterial.value) return t('bots.editor.visual.generateNeedsMaterial');
  return null;
});

const modeOptions = computed<SegmentOption<BotVisualMode>[]>(() => [
  {
    value: 'reference',
    label: t('bots.editor.visual.mode.reference'),
    icon: 'image',
    description: t('bots.editor.visual.mode.referenceHint'),
  },
  {
    value: 'description',
    label: t('bots.editor.visual.mode.description'),
    icon: 'sparkles',
    description: t('bots.editor.visual.mode.descriptionHint'),
  },
]);

// --- Status line -------------------------------------------------------------
const statusMessage = computed<string | null>(() => {
  if (savingForRun.value) return t('bots.editor.visual.status.saving');
  if (!generating.value) return null;
  return job.status.value === 'processing'
    ? t('bots.editor.visual.status.processing')
    : t('bots.editor.visual.status.queued');
});

// --- Reference handling -------------------------------------------------------
function onReferenceUpload(file: File[] | File | null): void {
  const picked = Array.isArray(file) ? (file[0] ?? null) : file;
  referenceTouched.value = true;
  referenceFile.value = picked;
  // Exactly one source: an upload supersedes any stored / picked id.
  if (picked) referenceFileId.value = null;
}

/**
 * A Disk pick is passed straight through as `reference_file_id` — DELIBERATELY WITHOUT the
 * copy-to-temp the other hosts of this modal do. The visual endpoint accepts a disk-native file
 * (`BotVisualFile(allowDiskNative: true)`), so copying would only orphan a duplicate on the Disk.
 */
function onDiskFilePicked(file: DiskFile): void {
  referenceTouched.value = true;
  referenceFile.value = null;
  referenceFileId.value = file.id;
}

function clearReference(): void {
  referenceTouched.value = true;
  referenceFile.value = null;
  referenceFileId.value = null;
}

// --- Failure mapping ----------------------------------------------------------
/** Best-effort refresh of the workspace usage summary — the only way to tell a $ block from a day cap. */
async function refreshBudget(): Promise<void> {
  const id = auth.currentWorkspaceId;
  if (id == null) return;
  await aiUsage.fetchAiUsage(id).catch(() => undefined);
}

function flagWardrobe(): void {
  wardrobeFlagged.value = true;
  if (flagTimer) clearTimeout(flagTimer);
  flagTimer = setTimeout(() => {
    wardrobeFlagged.value = false;
  }, 3_000);
}

/**
 * ONE status, THREE different fixes. A 429 off this endpoint can mean:
 *   • the route's own tight throttle bucket (`throttle:10,1,bot-visual`) — wait a moment, it clears;
 *   • the workspace's monthly $ budget is exhausted — someone must raise the cap;
 *   • the workspace's DAILY image count cap — nothing to do but come back tomorrow.
 * The first is told apart by the rate-limit headers Laravel's throttle adds (the budget/cap aborts carry
 * none), the second by the usage summary's own `blocked` flag; whatever is left is the daily cap.
 */
async function mapRequestError(err: unknown): Promise<RunFailure> {
  const response = (err as { response?: { status?: number; headers?: Record<string, unknown> } })?.response;
  if (!response) return { message: t('bots.editor.visual.errors.network'), retryable: true };

  if (response.status === 403) return { message: t('bots.editor.visual.errors.forbidden') };

  if (response.status === 429) {
    const headers = response.headers ?? {};
    const throttled = headers['retry-after'] != null || headers['x-ratelimit-limit'] != null;
    if (throttled) return { message: t('bots.editor.visual.errors.throttled'), retryable: true };
    await refreshBudget();
    if (aiUsage.summary?.blocked === true) {
      return {
        message: t('bots.editor.visual.errors.budget'),
        budget: aiUsage.summary.can_manage === true ? 'manage' : 'contact',
      };
    }
    return { message: t('bots.editor.visual.errors.dailyCap') };
  }

  // 422 field messages are mapped onto the fields by the drawer; the tile carries the generic line.
  return { message: t('bots.editor.visual.errors.failed'), retryable: true };
}

/** A finished-but-failed JOB. `safety_rejected` is the one the user can actually fix. */
function mapJobFailure(errorCode: string | null, message?: string): RunFailure {
  if (errorCode === 'safety_rejected') {
    flagWardrobe();
    return { message: t('bots.editor.visual.errors.safety'), safety: true };
  }
  return { message: message || t('bots.editor.visual.errors.failed'), retryable: true };
}

// --- Generate -----------------------------------------------------------------
function armSlow(): void {
  slow.value = false;
  if (slowTimer) clearTimeout(slowTimer);
  slowTimer = setTimeout(() => {
    slow.value = true;
  }, SLOW_AFTER_MS);
}
function disarmSlow(): void {
  slow.value = false;
  if (slowTimer) clearTimeout(slowTimer);
  slowTimer = null;
}

/** Pull the bot fresh and hand it to the drawer — the produced candidate is already filed server-side. */
async function syncFromServer(): Promise<void> {
  const id = props.botId;
  if (!id) return;
  const fresh = await store.fetchBot(id);
  if (fresh) emit('sync', fresh);
}

async function generate(): Promise<void> {
  if (!canGenerate.value || !props.botId) return;
  failure.value = null;

  // The image is drawn from the SAVED module (note 1) — persist first, and stop if that failed.
  if (props.dirty) {
    savingForRun.value = true;
    let saved: BotDetail | null = null;
    try {
      saved = await props.save();
    } finally {
      savingForRun.value = false;
    }
    if (!saved) return; // the drawer surfaced the reason
    emit('sync', saved);
  }

  generating.value = true;
  armSlow();
  let jobId: string;
  try {
    jobId = await store.generateVisual(props.botId, {
      mode: mode.value,
      reference: mode.value === 'reference' ? referenceFile.value : null,
      reference_file_id: mode.value === 'reference' && !referenceFile.value ? referenceFileId.value : null,
      instruction: instruction.value.trim() || null,
    });
  } catch (err) {
    failure.value = await mapRequestError(err);
    generating.value = false;
    disarmSlow();
    return;
  }

  toast.success(t('bots.editor.visual.toasts.generateStarted'));

  try {
    // `keepImage` stays FALSE: the produced PNG is already filed as a candidate server-side, so holding
    // a multi-megabyte base64 string in reactive state would buy nothing.
    const outcome = await job.start(jobId);
    if (outcome.status === 'done') {
      await syncFromServer();
      // The run consumed the upload; the module now names the persisted reference itself.
      referenceFile.value = null;
      referenceTouched.value = false;
      toast.success(t('bots.editor.visual.toasts.generated'));
    } else if (outcome.reason !== 'cancelled') {
      failure.value = mapJobFailure(outcome.errorCode ?? null, outcome.error);
    }
  } catch {
    failure.value = { message: t('bots.editor.visual.errors.failed'), retryable: true };
  } finally {
    generating.value = false;
    disarmSlow();
  }
}

/** "Check now" for a run that outlived the wait: re-read the bot rather than keep spinning. */
async function checkNow(): Promise<void> {
  await syncFromServer();
  disarmSlow();
}

function goToBudget(): void {
  void router.push({ name: 'next.settings.aiUsage' });
}

// --- Curation ------------------------------------------------------------------
async function approve(fileId: string): Promise<void> {
  if (!props.botId || busyFileId.value) return;
  busyFileId.value = fileId;
  try {
    emit('sync', await store.approveVisualCandidate(props.botId, fileId));
    toast.success(t('bots.editor.visual.toasts.approved'));
  } catch {
    toast.danger(t('bots.editor.visual.toasts.error'));
  } finally {
    busyFileId.value = null;
  }
}

async function remove(fileId: string): Promise<void> {
  if (!props.botId || busyFileId.value) return;
  const ok = await confirm({
    title: t('bots.editor.visual.candidates.removeConfirmTitle'),
    message: t('bots.editor.visual.candidates.removeConfirmBody'),
    variant: 'danger',
  });
  if (!ok) return;
  busyFileId.value = fileId;
  try {
    emit('sync', await store.deleteVisualCandidate(props.botId, fileId));
    toast.success(t('bots.editor.visual.toasts.removed'));
  } catch {
    toast.danger(t('bots.editor.visual.toasts.error'));
  } finally {
    busyFileId.value = null;
  }
}

/**
 * Clearing the approval is a normal bot SAVE with `canonical_file_id: null` — there is no endpoint for
 * it, and the save also writes whatever else is in the form, which the confirmation says out loud.
 */
async function unapprove(): Promise<void> {
  if (!props.botId || !canonicalFileId.value) return;
  const ok = await confirm({
    title: t('bots.editor.visual.candidates.unapproveConfirmTitle'),
    message: t('bots.editor.visual.candidates.unapproveConfirmBody'),
  });
  if (!ok) return;
  const saved = await props.save({ canonicalFileId: null });
  if (saved) {
    emit('sync', saved);
    toast.success(t('bots.editor.visual.toasts.unapproved'));
  }
}

function focusGenerate(): void {
  generateWrapRef.value?.querySelector('button')?.focus();
}

/**
 * Everything the wardrobe control is DESCRIBED by, in reading order: the moderation hint (always), the
 * moderation refusal that just pointed here (while flagged), and the field's own error message. Built by
 * hand because the field carries an explicit id and renders its hint itself (it needs the emphasis a
 * plain FormField description cannot give it) — so nothing is silently dropped from the a11y tree.
 */
const wardrobeDescribedBy = computed(() =>
  [
    `${WARDROBE_ID}-description`,
    wardrobeFlagged.value && failure.value ? SAFETY_NOTICE_ID : '',
    props.errors.wardrobe ? `${WARDROBE_ID}-message` : '',
  ]
    .filter(Boolean)
    .join(' '),
);
</script>

<template>
  <div class="flex flex-col gap-next-5">
    <!-- What this module IS (always visible — on or off). -->
    <Alert variant="info" size="sm">{{ t('bots.editor.visualHint') }}</Alert>

    <!-- OFF does not block WORK here, only USE in sessions — say exactly that (note 4). -->
    <Alert v-if="!enabled" variant="warning" size="sm">{{ t('bots.editor.visual.offHint') }}</Alert>

    <!-- 1. THE STRIP — the result the user comes back for, so it leads. -->
    <section class="flex flex-col gap-next-2">
      <div class="flex flex-wrap items-center justify-between gap-next-2">
        <h4 class="text-next-sm font-next-semibold text-next-fg">
          {{ t('bots.editor.visual.candidates.legend', '', { count: candidates.length, max: MAX }) }}
        </h4>
        <Button
          v-if="canonicalFileId"
          variant="ghost"
          size="sm"
          leading-icon="rotate-ccw"
          :disabled="noBot || busy || !!busyFileId"
          @click="unapprove"
        >
          {{ t('bots.editor.visual.candidates.unapprove') }}
        </Button>
      </div>

      <BotVisualCandidates
        :candidates="candidates"
        :canonical-file-id="canonicalFileId"
        :bot-name="botName"
        :max="MAX"
        :pending="generating"
        :busy-file-id="busyFileId"
        :disabled="noBot"
        @approve="approve"
        @remove="remove"
        @focus-generate="focusGenerate"
      />
    </section>

    <!-- 2. THE WRITTEN IDENTITY — in the order the prompt is composed (subject → outfit → look). -->
    <section class="flex flex-col gap-next-4">
      <h4 class="text-next-sm font-next-semibold text-next-fg">
        {{ t('bots.editor.visual.identityLegend') }}
      </h4>

      <FormField
        :label="t('bots.editor.visual.descriptorLabel')"
        :description="t('bots.editor.visual.descriptorHint')"
        :error="errors.descriptor ?? undefined"
      >
        <Textarea
          v-model="descriptor"
          :rows="2"
          :maxlength="240"
          counter
          :placeholder="t('bots.editor.visual.descriptorPlaceholder')"
        />
      </FormField>

      <!-- The wardrobe is a FIRST-CLASS field, not part of the aesthetic: it is the one steerable
           defence against the provider's output-side moderation, so its hint is emphasised. -->
      <FormField
        :id="WARDROBE_ID"
        :label="t('bots.editor.visual.wardrobeLabel')"
        :error="errors.wardrobe ?? undefined"
      >
        <div
          class="flex flex-col gap-next-2 rounded-next-md p-next-0_5 transition-shadow"
          :class="wardrobeFlagged ? 'ring-2 ring-next-warning' : ''"
        >
          <p
            :id="`${WARDROBE_ID}-description`"
            class="flex items-start gap-next-1_5 rounded-next-md bg-next-warning-subtle px-next-3 py-next-2 text-next-xs text-next-warning-subtle-foreground"
          >
            <Icon name="alert-triangle" class="mt-px shrink-0" aria-hidden="true" />
            <span>{{ t('bots.editor.visual.wardrobeHint') }}</span>
          </p>
          <Textarea
            v-model="wardrobe"
            :rows="2"
            :maxlength="500"
            counter
            :described-by-id="wardrobeDescribedBy"
            :placeholder="t('bots.editor.visual.wardrobePlaceholder')"
          />
        </div>
      </FormField>

      <FormField
        :label="t('bots.editor.visual.aestheticLabel')"
        :description="t('bots.editor.visual.aestheticHint')"
        :error="errors.aesthetic ?? undefined"
      >
        <Textarea
          v-model="aesthetic"
          :rows="3"
          :maxlength="2000"
          counter
          :placeholder="t('bots.editor.visual.aestheticPlaceholder')"
        />
      </FormField>

      <FormField
        :label="t('bots.editor.visual.prohibitionsLabel')"
        :description="t('bots.editor.visual.prohibitionsHint')"
        :error="errors.prohibitions ?? undefined"
      >
        <StringListInput
          v-model="prohibitions"
          :placeholder="t('bots.editor.visual.prohibitionsPlaceholder')"
          :add-label="t('bots.editor.visual.prohibitionsAdd')"
          :empty-label="t('bots.editor.visual.prohibitionsEmpty')"
          :remove-label="t('bots.editor.visual.prohibitionsRemove')"
          :max="50"
        />
      </FormField>
    </section>

    <!-- 3. THE CREATE ZONE — an iterative loop, deliberately not a wizard. -->
    <section class="flex flex-col gap-next-3 rounded-next-lg border border-next-border bg-next-muted/20 p-next-4">
      <h4 class="text-next-sm font-next-semibold text-next-fg">{{ t('bots.editor.visual.createLegend') }}</h4>

      <!-- An unsaved bot cannot generate (the prompt is read from the saved module). -->
      <p
        v-if="noBot"
        class="flex items-center gap-next-2 rounded-next-md border border-dashed border-next-border px-next-3 py-next-2 text-next-xs text-next-muted-foreground"
      >
        <Icon name="info" class="shrink-0" aria-hidden="true" />
        {{ t('bots.editor.visual.generateNeedsBot') }}
      </p>

      <FormField :label="t('bots.editor.visual.modeLabel')">
        <SegmentedControl
          v-model="mode"
          :options="modeOptions"
          :columns="2"
          :disabled="noBot || busy"
          :aria-label="t('bots.editor.visual.modeLabel')"
        />
      </FormField>

      <!-- Reference source: an upload OR a Disk pick (the server takes exactly one). -->
      <FormField
        v-if="mode === 'reference'"
        :label="t('bots.editor.visual.referenceLabel')"
        :error="errors.reference ?? undefined"
      >
        <div class="flex flex-col gap-next-2">
          <!-- The stored / picked reference (an id) — the upload dropzone shows its own file. -->
          <div
            v-if="referenceFileId && !referenceFile"
            class="flex items-center gap-next-3 rounded-next-md border border-next-border bg-next-card p-next-2"
          >
            <span class="h-16 w-16 shrink-0 overflow-hidden rounded-next-sm border border-next-border bg-next-muted/40">
              <BotVisualImage
                :file-id="referenceFileId"
                :alt="t('bots.editor.visual.referenceCurrent')"
                fit="cover"
              />
            </span>
            <span class="min-w-0 flex-1 text-next-xs text-next-muted-foreground">
              {{ t('bots.editor.visual.referenceCurrent') }}
            </span>
            <Button
              variant="ghost"
              size="icon-sm"
              leading-icon="x"
              :disabled="noBot || busy"
              :aria-label="t('bots.editor.visual.referenceClear')"
              :title="t('bots.editor.visual.referenceClear')"
              @click="clearReference"
            />
          </div>

          <FileDropzone
            :model-value="referenceFile"
            accept="image/jpeg,image/png,image/webp"
            :max-size="MAX_REFERENCE_BYTES"
            :disabled="noBot || busy"
            :title="t('bots.editor.visual.referenceUploadTitle')"
            :hint="t('bots.editor.visual.referenceUploadHint')"
            :aria-label="t('bots.editor.visual.referenceLabel')"
            @update:model-value="onReferenceUpload"
          />

          <div>
            <Button
              variant="outline"
              size="sm"
              leading-icon="folder"
              :disabled="noBot || busy"
              @click="diskPickerOpen = true"
            >
              {{ t('bots.editor.visual.referencePick') }}
            </Button>
          </div>
        </div>
      </FormField>

      <FormField
        :label="t('bots.editor.visual.instructionLabel')"
        :description="t('bots.editor.visual.instructionHint')"
        :error="errors.instruction ?? undefined"
      >
        <Textarea
          v-model="instruction"
          :rows="2"
          :maxlength="2000"
          :disabled="noBot || busy"
          :placeholder="t('bots.editor.visual.instructionPlaceholder')"
        />
      </FormField>

      <!-- A seventh image evicts the oldest unapproved one PERMANENTLY — warn before, not after. -->
      <Alert v-if="atCapacity" variant="warning" size="sm">
        {{ t('bots.editor.visual.evictionWarning', '', { max: MAX }) }}
      </Alert>

      <div class="flex flex-col gap-next-1_5">
        <div ref="generateWrapRef">
          <Button
            leading-icon="sparkles"
            :disabled="!canGenerate"
            :loading="busy"
            :title="generateReason ?? undefined"
            @click="generate"
          >
            {{ t('bots.editor.visual.generate') }}
          </Button>
        </div>
        <p class="text-next-xs text-next-muted-foreground">
          {{ generateReason ?? t('bots.editor.visual.generateSavesHint') }}
        </p>
      </div>

      <!-- Live status of the run (visible, not only announced). -->
      <div
        v-if="statusMessage"
        class="flex flex-wrap items-center gap-next-2 text-next-xs text-next-muted-foreground"
        role="status"
        aria-live="polite"
      >
        <Icon name="sparkles" class="shrink-0" aria-hidden="true" />
        <span>{{ statusMessage }}</span>
        <span v-if="generating">· {{ t('bots.editor.visual.status.background') }}</span>
        <template v-if="slow">
          <span>· {{ t('bots.editor.visual.status.slow') }}</span>
          <Button variant="ghost" size="xs" leading-icon="rotate-ccw" @click="checkNow">
            {{ t('bots.editor.visual.status.check') }}
          </Button>
        </template>
      </div>

      <!-- A failed run: the reason, and what to do about it. -->
      <div
        v-if="failure"
        :id="SAFETY_NOTICE_ID"
        class="flex flex-col gap-next-2 rounded-next-md bg-next-danger-subtle p-next-3 text-next-danger-subtle-foreground"
        role="alert"
      >
        <p class="flex items-start gap-next-2 text-next-sm">
          <Icon name="alert-circle" class="mt-px shrink-0" aria-hidden="true" />
          <span>{{ failure.message }}</span>
        </p>
        <div class="flex flex-wrap items-center gap-next-2">
          <Button
            v-if="failure.retryable || failure.safety"
            variant="outline"
            size="sm"
            leading-icon="rotate-ccw"
            :disabled="!canGenerate"
            @click="generate"
          >
            {{ t('bots.editor.visual.errors.retry') }}
          </Button>
          <Button
            v-if="failure.budget === 'manage'"
            variant="outline"
            size="sm"
            leading-icon="wallet"
            @click="goToBudget"
          >
            {{ t('bots.editor.visual.errors.budgetManage') }}
          </Button>
          <Badge v-else-if="failure.budget === 'contact'" variant="neutral" tone="subtle" size="sm" icon="wallet">
            {{ t('bots.editor.visual.errors.budgetContactOwner') }}
          </Badge>
          <Button variant="ghost" size="sm" @click="failure = null">
            {{ t('bots.editor.visual.status.dismiss') }}
          </Button>
        </div>
      </div>
    </section>

    <!-- A Disk pick rides straight through as `reference_file_id` (see onDiskFilePicked). -->
    <DiskFilePickerModal
      v-model:open="diskPickerOpen"
      :accepted-types="['image/*']"
      :max-size="25"
      @select="onDiskFilePicked"
    />
  </div>
</template>
