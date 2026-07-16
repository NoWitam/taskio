<script setup lang="ts">
// TargetPickerModal — the run-now modal (next, §6), hosted by the module layout's
// `?run=<id>` overlay and opened from a list row's "Run now" and the detail action
// bar. **5.1: only two trigger types remain.** The required target differs by the
// workflow's `trigger_type` (§6.1):
//   • form_submitted → a FormSubmission (uuid). B8: replaces the raw id TextInput
//     with a read-only SELECTED-submission summary + two actions — PICK an existing
//     submission (SubmissionPickerDrawer) or CREATE one (FormFillView → a real
//     FormSubmission). Both resolve to a single `target_id` (the submission uuid);
//     the run contract is UNCHANGED (`store.run(id, target_id)`).
//   • schedule       → no target, confirm-only.
// The task/approval target controls of REV 1 are DELETED (no task/approval triggers).
//
// Scoping by the trigger's `trigger_config.form_id` (string | null; null = "any
// form"): when a form is bound, Pick + Create are scoped to it; when null the Pick
// drawer shows a FormSelect step first, and Create needs a form chosen first.
//
// When the workflow is INACTIVE the modal reframes as a TEST RUN (§6.2): a leading
// warning Alert + the confirm button becomes "Test run". Submit posts {target_id}
// only when a value is provided, via the workflows store `run(id, targetId?)`. On
// 202 → success toast, close, and (if the runs section is active) refetch the runs.
//
// 422 surfacing (§6.3): mapped purely by bag KEY via `mapRunNowError` — the bag now
// has EXACTLY two keys: `target_id` (required/notFound, message-disambiguated) and
// `workflow` (capReached). "targetRequired" is CLIENT-side only: Run stays disabled
// until a submission is selected/created (an empty required target never submits).
// The result renders as an inline danger Alert + a danger toast.
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import Modal from '../../ui/overlay/Modal.vue';
import Drawer from '../../ui/overlay/Drawer.vue';
import Button from '../../ui/primitives/Button.vue';
import Alert from '../../ui/feedback/Alert.vue';
import FormField from '../../ui/forms/FormField.vue';
import FormSelect from '../../ui/forms/FormSelect.vue';
import Skeleton from '../../ui/data/Skeleton.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import EntityCard, { type EntityMetaItem } from '../../ui/patterns/EntityCard.vue';
import CreatorBadge from '../../ui/patterns/CreatorBadge.vue';
import { creatorLabel } from '../../ui/patterns/creator';
import SubmissionPickerDrawer from './SubmissionPickerDrawer.vue';
import FormFillView from '../forms/FormFillView.vue';
import { useWorkflowsStore } from '../../app/stores/workflows';
import { useWorkflowRunsStore } from '../../app/stores/workflowRuns';
import { useToast } from '../../app/composables/useToast';
import { useI18n } from '../../app/i18n';
import { mapRunNowError, extractErrorBag } from './runNowErrors';
import type { WorkflowDetail, WorkflowRunFilters, WorkflowTriggerType } from './types';
import type { FormSubmission } from '../forms/types';

/** Normalize an array/undefined route-query value to a single string. */
const str = (v: unknown): string => (Array.isArray(v) ? String(v[0] ?? '') : String(v ?? ''));
/** Normalize a route-query value to a string[] (tolerating a legacy single scalar). */
const toArr = (v: unknown): string[] =>
  Array.isArray(v) ? v.map(String) : v != null && v !== '' ? [String(v)] : [];

const props = defineProps<{ workflowId: string }>();

const emit = defineEmits<{ (e: 'close'): void }>();

const open = defineModel<boolean>('open', { default: true });

const { t } = useI18n();
const route = useRoute();
const store = useWorkflowsStore();
const runsStore = useWorkflowRunsStore();
const toast = useToast();

// --- Resolve the workflow (prefer the detail cache; fetch on a cache miss) ---
const workflow = ref<WorkflowDetail | null>(
  store.detail && store.detail.id === props.workflowId ? store.detail : null,
);
const loadingWorkflow = ref(false);
const loadError = ref(false);

async function ensureWorkflow(): Promise<void> {
  if (workflow.value) return;
  loadingWorkflow.value = true;
  loadError.value = false;
  const result = await store.fetchWorkflow(props.workflowId);
  loadingWorkflow.value = false;
  if (result) workflow.value = result;
  else loadError.value = true;
}

onMounted(ensureWorkflow);

const triggerType = computed<WorkflowTriggerType | null>(() => workflow.value?.trigger_type ?? null);
const isInactive = computed(() => workflow.value?.status === 'inactive');

// --- Per-trigger-type target field (§6.1 — two cases) ----------------------
// `targetId` is the resolved FormSubmission uuid sent as `{target_id}` — the run
// contract is unchanged; only HOW the user arrives at it changed (pick / create).
const targetId = ref('');
/** The picked/created submission, kept for the read-only summary card. */
const selectedSubmission = ref<FormSubmission | null>(null);

/** Which target the trigger needs: a submission id (form_submitted) or none (schedule). */
const targetKind = computed<'submission' | 'none'>(() =>
  triggerType.value === 'form_submitted' ? 'submission' : 'none',
);

// The trigger's bound form (string) or null ("any form"). Drives whether Pick /
// Create are scoped to a form or need one chosen first.
const boundFormId = computed<string | null>(() => workflow.value?.trigger_config?.form_id ?? null);

/** Run stays disabled until a submission is resolved (schedule needs no target). */
const canConfirm = computed(() => {
  if (!workflow.value) return false;
  if (targetKind.value === 'none') return true;
  return targetId.value.trim() !== '';
});

// --- Selected-submission summary -------------------------------------------
function formatDate(iso: string | null): string {
  if (!iso) return '';
  const m = iso.match(/^(\d{4})-(\d{2})-(\d{2})/);
  return m ? `${m[3]}.${m[2]}.${m[1]}` : iso;
}
const summaryTitle = computed(() =>
  selectedSubmission.value
    ? creatorLabel(selectedSubmission.value.creator, t, t('forms.submissions.anonymous'))
    : '',
);
const summaryMeta = computed<EntityMetaItem[]>(() => {
  const s = selectedSubmission.value;
  if (!s) return [];
  const sourceLabel = s.source === 'task' ? t('forms.submissions.sourceTask') : t('forms.submissions.sourceForm');
  return [
    { icon: 'calendar', label: formatDate(s.approved_at ?? s.created_at) },
    { icon: 'inbox', label: sourceLabel },
  ];
});

function setSelected(submission: FormSubmission): void {
  selectedSubmission.value = submission;
  targetId.value = submission.id;
  formError.value = null;
  fieldErrored.value = false;
}
function clearSelection(): void {
  selectedSubmission.value = null;
  targetId.value = '';
}

// --- Pick drawer -----------------------------------------------------------
const pickOpen = ref(false);
function openPick(): void {
  pickOpen.value = true;
}
function onPicked(_submissionId: string, submission: FormSubmission): void {
  setSelected(submission);
}

// --- Create drawer (FormViewer via FormFillView → a REAL submission) --------
const createOpen = ref(false);
// The form to create a submission for: the bound form, or one chosen inline when
// the trigger accepts any form (null → a FormSelect step precedes FormFillView).
const createFormId = ref<string | null>(null);
function openCreate(): void {
  createFormId.value = boundFormId.value;
  createOpen.value = true;
}
function onCreated(submission: FormSubmission): void {
  setSelected(submission);
  createOpen.value = false;
}

// --- Confirm copy (test-run framing when inactive, §6.2) -------------------
const confirmLabel = computed(() =>
  isInactive.value ? t('workflows.run.testRunConfirm') : t('workflows.run.confirm'),
);

// --- 422 / inline error state ----------------------------------------------
const submitting = ref(false);
const formError = ref<string | null>(null);
/** True when the mapped 422 belongs to the id field (flags the input). */
const fieldErrored = ref(false);

async function onConfirm(): Promise<void> {
  if (submitting.value || !workflow.value) return;
  formError.value = null;
  fieldErrored.value = false;

  // CLIENT-side required guard: an empty target for a trigger that needs one never
  // reaches the server (the backend folds emptiness into its not-found lookup, so
  // the precise "required" copy exists only here — §6.3).
  if (targetKind.value !== 'none' && targetId.value.trim() === '') {
    formError.value = t('workflows.run.errors.targetRequired');
    fieldErrored.value = true;
    return;
  }

  submitting.value = true;
  try {
    const value = targetKind.value === 'none' ? undefined : targetId.value.trim() || undefined;
    await store.run(workflow.value.id, value);
    toast.success(t('workflows.run.toasts.started'));
    // Refetch the runs list when the Runs child route is the active detail section
    // so the new run appears immediately (§6.3). The runs filters live in the URL
    // (state[] / origin[] + a date range), so honor them on the refetch instead of
    // clobbering them.
    if (route.name === 'next.workflows.detail.runs' && runsStore.workflowId === workflow.value.id) {
      const filters: WorkflowRunFilters = {};
      const state = toArr(route.query.state);
      const origin = toArr(route.query.origin);
      const dateFrom = str(route.query.date_from);
      const dateTo = str(route.query.date_to);
      const datePreset = str(route.query.date_preset);
      if (state.length) filters.state = state;
      if (origin.length) filters.origin = origin;
      if (dateFrom) filters.date_from = dateFrom;
      if (dateTo) filters.date_to = dateTo;
      if (datePreset) filters.date_preset = datePreset;
      void runsStore.fetchRuns(workflow.value.id, filters, { reset: true });
    }
    close();
  } catch (err: unknown) {
    const bag = extractErrorBag(err);
    const mapped = mapRunNowError(bag);
    formError.value = t(mapped.key);
    fieldErrored.value = mapped.field === 'target_id';
    toast.danger(t(mapped.key));
  } finally {
    submitting.value = false;
  }
}

function close(): void {
  open.value = false;
  emit('close');
}
</script>

<template>
  <Modal
    v-model:open="open"
    size="md"
    :aria-label="t('workflows.run.title')"
    @close="emit('close')"
  >
    <template #title>{{ t('workflows.run.title') }}</template>
    <template #description>{{ t('workflows.run.purpose') }}</template>

    <!-- Loading the workflow (deep-link / cache miss). -->
    <div v-if="loadingWorkflow && !workflow" class="flex flex-col gap-next-3">
      <Skeleton variant="text" width="60%" />
      <Skeleton variant="rect" height="2.5rem" />
    </div>

    <!-- Couldn't resolve the workflow. -->
    <Alert v-else-if="loadError && !workflow" variant="danger" size="sm">
      <div class="flex items-center justify-between gap-next-2">
        <span>{{ t('workflows.run.errors.generic') }}</span>
        <Button size="sm" variant="outline" leading-icon="rotate-ccw" @click="ensureWorkflow">
          {{ t('workflows.errors.retry') }}
        </Button>
      </div>
    </Alert>

    <template v-else-if="workflow">
      <div class="flex flex-col gap-next-4">
        <!-- Inactive → test-run framing (§6.2). -->
        <Alert v-if="isInactive" variant="warning" size="sm">
          {{ t('workflows.run.testRunNote') }}
        </Alert>

        <!-- Submission target (form_submitted): a read-only summary of the picked /
             created submission + Pick / Create actions. -->
        <template v-if="targetKind === 'submission'">
          <EntityCard
            v-if="selectedSubmission"
            :title="summaryTitle"
            :meta="summaryMeta"
            selected
          >
            <template #leading>
              <CreatorBadge :creator="selectedSubmission.creator" glyph-only size="sm" />
            </template>
            <template #actions>
              <Button
                variant="ghost"
                size="icon-sm"
                leading-icon="x"
                :aria-label="t('workflows.run.clearSelection')"
                @click="clearSelection"
              />
            </template>
          </EntityCard>

          <EmptyState
            v-else
            size="sm"
            icon="inbox"
            :title="t('workflows.run.noSubmission')"
            :description="t('workflows.run.noSubmissionHint')"
          />

          <div class="flex gap-next-2">
            <Button class="flex-1 min-w-0" variant="outline" leading-icon="list" @click="openPick">
              {{ t('workflows.run.pick') }}
            </Button>
            <Button class="flex-1 min-w-0" variant="outline" leading-icon="plus" @click="openCreate">
              {{ t('workflows.run.create') }}
            </Button>
          </div>
        </template>

        <!-- Schedule: no field — confirm-only copy. -->
        <p v-else class="text-next-sm text-next-muted-foreground">
          {{ t('workflows.run.scheduleConfirm') }}
        </p>

        <!-- Inline run error (target not found / cap reached / generic). With no id
             field to pin to, every mapped run error surfaces here. -->
        <Alert v-if="formError" variant="danger" size="sm">
          {{ formError }}
        </Alert>
      </div>
    </template>

    <template #footer>
      <Button variant="ghost" :disabled="submitting" @click="close">
        {{ t('workflows.run.cancel') }}
      </Button>
      <Button
        leading-icon="arrow-right"
        :loading="submitting"
        :disabled="submitting || !canConfirm"
        @click="onConfirm"
      >
        {{ confirmLabel }}
      </Button>
    </template>
  </Modal>

  <!-- Pick an existing submission (scoped to the bound form, or a FormSelect step
       first when the trigger accepts any form). Closes itself on select. -->
  <SubmissionPickerDrawer
    v-model:open="pickOpen"
    :form-id="boundFormId"
    @select="onPicked"
  />

  <!-- Create a REAL submission via FormViewer (fill mode, reused through
       FormFillView). When the trigger accepts any form, a FormSelect step precedes
       the form; on submit the returned submission id becomes the run target. -->
  <Drawer
    v-model:open="createOpen"
    side="right"
    size="xl"
    :aria-label="t('workflows.run.createDrawer.title')"
  >
    <template #title>{{ t('workflows.run.createDrawer.title') }}</template>

    <div v-if="!createFormId" class="flex flex-col gap-next-4">
      <p class="text-next-sm text-next-muted-foreground">
        {{ t('workflows.run.createDrawer.chooseFormHint') }}
      </p>
      <FormField :label="t('workflows.run.createDrawer.chooseForm')">
        <FormSelect v-model="createFormId" :aria-label="t('workflows.run.createDrawer.chooseForm')" />
      </FormField>
    </div>

    <FormFillView
      v-else
      :key="createFormId"
      :form-id="createFormId"
      @submitted="onCreated"
      @close="createOpen = false"
    />
  </Drawer>
</template>
