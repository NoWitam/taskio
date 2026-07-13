<script setup lang="ts">
// TargetPickerModal — the run-now modal (next, §6), hosted by the module layout's
// `?run=<id>` overlay and opened from a list row's "Run now" and the detail action
// bar. **5.1: only two trigger types remain.** The required target differs by the
// workflow's `trigger_type` (§6.1):
//   • form_submitted → a FormSubmission id (mono TextInput; no submission picker
//     component exists — the honest MVP field, flagged §8),
//   • schedule       → no field, confirm-only.
// The task/approval target controls of REV 1 are DELETED (no task/approval triggers).
//
// When the workflow is INACTIVE the modal reframes as a TEST RUN (§6.2): a leading
// warning Alert + the confirm button becomes "Test run". Submit posts {target_id}
// only when a value is provided, via the workflows store `run(id, targetId?)`. On
// 202 → success toast, close, and (if the runs section is active) refetch the runs.
//
// 422 surfacing (§6.3): mapped purely by bag KEY via `mapRunNowError` — the bag now
// has EXACTLY two keys: `target_id` (required/notFound, message-disambiguated) and
// `workflow` (capReached). "targetRequired" is CLIENT-side only: an empty required
// target never submits. The result renders as an inline danger Alert + a danger
// toast, flagging the id field when the error belongs to it; anything else → generic.
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import Modal from '../../ui/overlay/Modal.vue';
import Button from '../../ui/primitives/Button.vue';
import Alert from '../../ui/feedback/Alert.vue';
import FormField from '../../ui/forms/FormField.vue';
import TextInput from '../../ui/forms/TextInput.vue';
import Skeleton from '../../ui/data/Skeleton.vue';
import { useWorkflowsStore } from '../../app/stores/workflows';
import { useWorkflowRunsStore } from '../../app/stores/workflowRuns';
import { useToast } from '../../app/composables/useToast';
import { useI18n } from '../../app/i18n';
import { mapRunNowError, extractErrorBag } from './runNowErrors';
import type { WorkflowDetail, WorkflowRunFilters, WorkflowTriggerType } from './types';

/** Normalize an array/undefined route-query value to a single string. */
const str = (v: unknown): string => (Array.isArray(v) ? String(v[0] ?? '') : String(v ?? ''));

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
const targetId = ref('');

/** Which target the trigger needs: a submission id (form_submitted) or none (schedule). */
const targetKind = computed<'submission' | 'none'>(() =>
  triggerType.value === 'form_submitted' ? 'submission' : 'none',
);

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
    // (state / origin), so honor them on the refetch instead of clobbering them.
    if (route.name === 'next.workflows.detail.runs' && runsStore.workflowId === workflow.value.id) {
      const filters: WorkflowRunFilters = {};
      const state = str(route.query.state);
      const origin = str(route.query.origin);
      if (state) filters.state = state as WorkflowRunFilters['state'];
      if (origin) filters.origin = origin as WorkflowRunFilters['origin'];
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

        <!-- Submission-id target (form_submitted). -->
        <FormField
          v-if="targetKind === 'submission'"
          :label="t('workflows.run.submissionIdLabel')"
          :description="t('workflows.run.submissionIdHint')"
          :error="fieldErrored ? (formError ?? undefined) : undefined"
        >
          <TextInput
            v-model="targetId"
            class="font-next-mono"
            :placeholder="t('workflows.run.submissionIdPlaceholder')"
            :aria-invalid="fieldErrored"
          />
        </FormField>

        <!-- Schedule: no field — confirm-only copy. -->
        <p v-else class="text-next-sm text-next-muted-foreground">
          {{ t('workflows.run.scheduleConfirm') }}
        </p>

        <!-- Inline error not tied to the field (e.g. cap reached / generic). -->
        <Alert v-if="formError && !fieldErrored" variant="danger" size="sm">
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
        :disabled="submitting || !workflow"
        @click="onConfirm"
      >
        {{ confirmLabel }}
      </Button>
    </template>
  </Modal>
</template>
