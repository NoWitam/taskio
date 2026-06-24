<script setup lang="ts">
// TaskFormModal — create + edit a task in a single Modal (next frontend).
//
// Opens for CREATE (no `task`) or EDIT (a `task` to prefill). The payload matches
// the verified backend contract exactly (StoreTasksRequest + TaskDTO):
//   title (required), description (markdown string, nullable), priority (required,
//   enum), deadline (nullable ISO yyyy-mm-dd), assigned_id (required uuid),
//   labels[] (0..5 uuids). `status` is NOT sent on create — new tasks default to
//   `to_do` server-side; status changes go through the dedicated endpoint.
//   attachments[]/form_id/approval_pipeline_id exist in the contract but file
//   uploads + form/pipeline pickers are DEFERRED (not in this batch); existing
//   values are preserved on edit so they are not wiped.
//
// States: idle/submitting (button), 422 field errors surfaced on the FormFields +
// an error-summary Alert. i18n + a11y throughout (labelled fields, focus-managed
// Modal). All design-system components; no legacy imports; namespaced tokens.
import { computed, reactive, ref, watch } from 'vue';
import Modal from '../../ui/overlay/Modal.vue';
import Drawer from '../../ui/overlay/Drawer.vue';
import FormField from '../../ui/forms/FormField.vue';
import TextInput from '../../ui/forms/TextInput.vue';
import UserSelect from '../../ui/forms/UserSelect.vue';
import LabelSelect from '../../ui/forms/LabelSelect.vue';
import FormSelect from '../../ui/forms/FormSelect.vue';
import PipelineSelect from '../../ui/forms/PipelineSelect.vue';
import SegmentedControl, { type SegmentOption } from '../../ui/forms/SegmentedControl.vue';
import DatePicker from '../../ui/forms/DatePicker.vue';
import MarkdownEditor from '../../ui/editor/MarkdownEditor.vue';
import TaskAttachmentsField from './TaskAttachmentsField.vue';
import FormBuilderView from '../forms/builder/FormBuilderView.vue';
import Alert from '../../ui/feedback/Alert.vue';
import Button from '../../ui/primitives/Button.vue';
import type { FormDetail } from '../forms/types';
import { useTasksStore } from '../../app/stores/tasks';
import { useToast } from '../../app/composables/useToast';
import { useI18n } from '../../app/i18n';
import {
  ALL_PRIORITIES,
  priorityMeta,
  type TaskAttachment,
  type TaskDetail,
  type TaskPriority,
  type TaskWritePayload,
} from './types';
import {
  markdownToTaskDescriptionPayload,
  taskDescriptionToMarkdown,
} from './description';
import { buildTaskPayload } from './taskPayload';

const props = defineProps<{
  /** When set, the task to edit (prefill); omit for create mode. */
  task?: TaskDetail | null;
}>();

const open = defineModel<boolean>('open', { default: false });

const emit = defineEmits<{
  /** Emitted after a successful create/update with the saved task. */
  (e: 'saved', task: TaskDetail): void;
}>();

const { t } = useI18n();
const store = useTasksStore();
const toast = useToast();

const isEdit = computed(() => !!props.task);

// --- Form state -----------------------------------------------------------
interface FormState {
  title: string;
  description: string;
  priority: TaskPriority;
  deadline: string | null;
  assigned_id: string | null;
  labels: string[];
  /** Attached form id (nullable). ALWAYS sent in the payload (see buildPayload). */
  form_id: string | null;
  /** Attached approval-pipeline id (nullable). ALWAYS sent (see buildPayload). */
  approval_pipeline_id: string | null;
  /** Temp file ids of NEW uploads (existing attachments are managed via seed). */
  attachments: string[];
}

function blankForm(): FormState {
  return {
    title: '',
    description: '',
    priority: 'medium',
    deadline: null,
    assigned_id: null,
    labels: [],
    form_id: null,
    approval_pipeline_id: null,
    attachments: [],
  };
}

const form = reactive<FormState>(blankForm());
const submitting = ref(false);
// 422 field errors keyed by the backend field name (e.g. `assigned_id`).
const fieldErrors = ref<Record<string, string>>({});
const formError = ref<string | null>(null);

// Seed options so already-selected assignee/labels render before async loads.
// Shapes match UserSelect / LabelSelect `seed` props (entity-ish, not SelectOption).
const assigneeSeed = ref<Array<{ id: string; name: string; email?: string | null; avatar?: string | null }>>([]);
const labelSeed = ref<Array<{ id: string; name: string; color?: string | null; icon?: string | null }>>([]);
const attachmentSeed = ref<TaskAttachment[]>([]);
// Seed the FormSelect option from the task's currently-attached form so its name
// renders immediately on edit (before any async forms page loads).
const formSeed = ref<Array<{ id: string; name: string; icon?: string | null }>>([]);
// Seed the PipelineSelect option from the task's currently-attached pipeline so
// its name renders immediately on edit (before any async pipelines page loads).
const pipelineSeed = ref<Array<{ id: string; name: string; icon?: string | null }>>([]);

// Reset/prefill whenever the modal opens (or the task changes while open).
watch(
  () => [open.value, props.task] as const,
  ([isOpen]) => {
    if (!isOpen) return;
    fieldErrors.value = {};
    formError.value = null;
    const task = props.task;
    if (task) {
      form.title = task.title ?? '';
      // `description` arrives as a ProseMirror doc object; the MarkdownEditor
      // speaks a markdown string, so convert doc → markdown for prefill.
      form.description = taskDescriptionToMarkdown(task.description);
      form.priority = task.priority ?? 'medium';
      form.deadline = task.deadline ?? null;
      form.assigned_id = task.assigned ? String(task.assigned.id) : null;
      form.labels = (task.labels ?? []).map((l) => String(l.id));
      assigneeSeed.value = task.assigned
        ? [{
            id: String(task.assigned.id),
            name: task.assigned.name,
            email: task.assigned.email ?? null,
            avatar: task.assigned.avatar ?? null,
          }]
        : [];
      labelSeed.value = (task.labels ?? []).map((l) => ({
        id: String(l.id),
        name: l.name,
        color: l.color ?? null,
        icon: l.icon ?? null,
      }));
      form.attachments = [];
      attachmentSeed.value = task.attachments ?? [];
      // Seed the form picker from `form_id` + the eager-loaded `form` (name/icon).
      form.form_id = task.form_id ?? null;
      formSeed.value = task.form
        ? [{ id: String(task.form.id), name: task.form.name, icon: task.form.icon ?? null }]
        : [];
      // Seed the pipeline picker from `approval_pipeline_id` + the eager-loaded
      // `approval_pipeline` (name/icon) so it shows immediately on edit.
      form.approval_pipeline_id = task.approval_pipeline_id ?? null;
      pipelineSeed.value = task.approval_pipeline
        ? [{
            id: String(task.approval_pipeline.id),
            name: task.approval_pipeline.name,
            icon: task.approval_pipeline.icon ?? null,
          }]
        : [];
    } else {
      Object.assign(form, blankForm());
      assigneeSeed.value = [];
      labelSeed.value = [];
      attachmentSeed.value = [];
      formSeed.value = [];
      pipelineSeed.value = [];
    }
  },
  { immediate: true },
);

// --- Options --------------------------------------------------------------
const priorityOptions = computed<SegmentOption<TaskPriority>[]>(() =>
  ALL_PRIORITIES.map((p) => ({ value: p, label: t(`tasks.priorities.${p}`), icon: priorityMeta(p).icon })),
);

// --- Submit ---------------------------------------------------------------
function buildPayload(): TaskWritePayload {
  // The backend stores the description as a ProseMirror doc parsed from a JSON
  // STRING (rule: nullable|string). Convert editor markdown → JSON doc string (or
  // null when empty) so the cast can rebuild the tree. The shared builder ALWAYS
  // emits form_id AND approval_pipeline_id (string|null) so each picker's value —
  // incl. a cleared detach — is honored. Both come straight from the modal's
  // pickers. Only NEW uploads are sent (additive on the backend).
  return buildTaskPayload({
    title: form.title,
    description: markdownToTaskDescriptionPayload(form.description),
    priority: form.priority,
    deadline: form.deadline,
    assigned_id: form.assigned_id,
    labels: form.labels,
    attachments: form.attachments,
    form_id: form.form_id,
    approval_pipeline_id: form.approval_pipeline_id,
  });
}

/** Client-side guard for the required fields (server is authoritative). */
function validate(): boolean {
  const errs: Record<string, string> = {};
  if (!form.title.trim()) errs.title = t('tasks.form.required');
  if (!form.assigned_id) errs.assigned_id = t('tasks.form.required');
  fieldErrors.value = errs;
  return Object.keys(errs).length === 0;
}

async function submit(): Promise<void> {
  formError.value = null;
  if (!validate()) {
    formError.value = t('tasks.form.errorDescription');
    return;
  }
  submitting.value = true;
  try {
    const payload = buildPayload();
    const saved =
      props.task != null
        ? await store.updateTask(props.task.id, payload)
        : await store.createTask(payload);
    toast.success(
      props.task ? t('tasks.toasts.updated') : t('tasks.toasts.created'),
    );
    emit('saved', saved);
    open.value = false;
  } catch (err: unknown) {
    const response = (err as {
      response?: { status?: number; data?: { message?: string; errors?: Record<string, string[]> } };
    }).response;
    if (response?.status === 422 && response.data?.errors) {
      const mapped: Record<string, string> = {};
      Object.entries(response.data.errors).forEach(([key, messages]) => {
        mapped[key] = messages?.[0] ?? '';
      });
      fieldErrors.value = mapped;
      formError.value = response.data.message ?? t('tasks.form.errorDescription');
    } else {
      formError.value = response?.data?.message ?? t('tasks.toasts.saveError');
      toast.danger(t('tasks.toasts.saveError'));
    }
  } finally {
    submitting.value = false;
  }
}

const modalTitle = computed(() =>
  isEdit.value ? t('tasks.form.editTitle') : t('tasks.form.createTitle'),
);

// Removing an EXISTING attachment is a standalone action (edit mode only):
// it hits the dedicated delete endpoint immediately and never touches the
// other attachments.
async function removeExistingAttachment(fileId: string): Promise<void> {
  if (!props.task) return;
  try {
    await store.removeAttachment(props.task.id, fileId);
    attachmentSeed.value = attachmentSeed.value.filter((a) => String(a.id) !== fileId);
  } catch {
    toast.danger(t('attachments.removeError', 'Could not remove the attachment.'));
  }
}

// --- Form + pipeline pickers: attach existing (pipeline) / + create (form) ----
// The server returns 403 for edits (incl. swapping the form or pipeline) while a
// task is in an approval process, so the pickers + create action are disabled in
// that state (one shared lock for both).
const formLocked = computed(() => !!props.task?.is_in_approval);

// Inline-create hosts the full FormBuilderView in a Drawer that STACKS over this
// Modal (Drawer + Modal share the useOverlayStack, so Esc dismisses the builder
// first and the scrim/focus-trap target the topmost overlay). On save we adopt the
// new form: set form_id + seed the select so its name shows immediately, then close.
const builderOpen = ref(false);
function openFormBuilder(): void {
  if (formLocked.value) return;
  builderOpen.value = true;
}
function onFormCreated(created: FormDetail): void {
  form.form_id = String(created.id);
  formSeed.value = [
    ...formSeed.value.filter((f) => f.id !== String(created.id)),
    { id: String(created.id), name: created.name, icon: created.icon ?? null },
  ];
  builderOpen.value = false;
}
</script>

<template>
  <Modal v-model:open="open" size="xl" :aria-label="modalTitle">
    <template #title>{{ modalTitle }}</template>

    <form class="flex flex-col gap-next-4" @submit.prevent="submit">
      <!-- Error summary (announced via Alert role) -->
      <Alert
        v-if="formError"
        variant="danger"
        size="sm"
        :title="t('tasks.form.errorTitle')"
      >
        {{ formError }}
      </Alert>

      <!-- Title (required) -->
      <FormField
        :label="t('tasks.form.titleLabel')"
        required
        :error="fieldErrors.title"
      >
        <TextInput
          v-model="form.title"
          :placeholder="t('tasks.form.titlePlaceholder')"
          :aria-label="t('tasks.form.titleLabel')"
        />
      </FormField>

      <!-- Priority (required, default medium) -->
      <FormField :label="t('tasks.form.priority')" required :error="fieldErrors.priority">
        <SegmentedControl
          v-model="form.priority"
          :options="priorityOptions"
          equal-width
          :aria-label="t('tasks.form.priority')"
        />
      </FormField>

      <div class="grid grid-cols-1 gap-next-4 next-sm:grid-cols-2">
        <!-- Assignee (async, single, required) -->
        <FormField
          :label="t('tasks.form.assignee')"
          required
          :error="fieldErrors.assigned_id"
        >
          <UserSelect
            v-model="form.assigned_id"
            :seed="assigneeSeed"
            :aria-invalid="!!fieldErrors.assigned_id"
            :placeholder="t('tasks.form.assigneePlaceholder')"
            :aria-label="t('tasks.form.assignee')"
          />
        </FormField>

        <!-- Deadline (optional, ISO yyyy-mm-dd) -->
        <FormField :label="t('tasks.form.deadline')" :error="fieldErrors.deadline">
          <DatePicker v-model="form.deadline" :aria-label="t('tasks.form.deadline')" />
        </FormField>
      </div>

      <!-- Labels + approval pipeline share a row on wider viewports to keep the
           form compact (stacks to one column on mobile). Labels: async, multiple,
           max 5 server-side. Pipeline: attach EXISTING only (pipelines are created
           in the Approvals module); clearable → detach; disabled while the task is
           in approval (server returns 403 for edits). -->
      <div class="grid grid-cols-1 gap-next-4 next-sm:grid-cols-2">
        <FormField :label="t('tasks.form.labels')" :error="fieldErrors.labels">
          <LabelSelect
            v-model="form.labels"
            :seed="labelSeed"
            :placeholder="t('tasks.form.labelsPlaceholder')"
            :aria-label="t('tasks.form.labels')"
          />
        </FormField>

        <FormField
          :label="t('tasks.form.pipelineLabel')"
          :error="fieldErrors.approval_pipeline_id"
          :description="formLocked ? t('tasks.form.pipelineLockedHint') : undefined"
        >
          <PipelineSelect
            v-model="form.approval_pipeline_id"
            :seed="pipelineSeed"
            :disabled="formLocked"
            :placeholder="t('tasks.form.pipelinePlaceholder')"
            :aria-label="t('tasks.form.pipelineLabel')"
          />
        </FormField>
      </div>

      <!-- Form (attach existing, or create a new one inline). Full width so the
           select + "new form" action have room. Clearable → detach. Disabled while
           the task is in approval (server returns 403 for edits). -->
      <FormField
        :label="t('tasks.form.formLabel')"
        :error="fieldErrors.form_id"
        :description="formLocked ? t('tasks.form.formLockedHint') : undefined"
      >
        <div class="flex items-start gap-next-2">
          <FormSelect
            v-model="form.form_id"
            :seed="formSeed"
            :disabled="formLocked"
            class="min-w-0 flex-1"
            :placeholder="t('tasks.form.formPlaceholder')"
            :aria-label="t('tasks.form.formLabel')"
          />
          <Button
            type="button"
            variant="outline"
            leading-icon="plus"
            :disabled="formLocked"
            @click="openFormBuilder"
          >
            {{ t('tasks.form.createForm') }}
          </Button>
        </div>
      </FormField>

      <!-- Description (markdown string) -->
      <FormField :label="t('tasks.form.description')" :error="fieldErrors.description">
        <MarkdownEditor
          v-model="form.description"
          :placeholder="t('tasks.form.descriptionPlaceholder')"
          :aria-label="t('tasks.form.description')"
        />
      </FormField>

      <!-- Attachments (existing via seed + new uploads, additive on the backend) -->
      <FormField :label="t('tasks.detail.attachments')" :error="fieldErrors.attachments">
        <TaskAttachmentsField
          v-model="form.attachments"
          :seed="attachmentSeed"
          :max="5"
          @remove-existing="removeExistingAttachment"
        />
      </FormField>
    </form>

    <template #footer="{ close }">
      <Button variant="ghost" :disabled="submitting" @click="close">
        {{ t('tasks.form.cancel') }}
      </Button>
      <Button :loading="submitting" @click="submit">
        {{ isEdit ? t('tasks.form.save') : t('tasks.form.create') }}
      </Button>
    </template>
  </Modal>

  <!-- Inline form builder. Stacks OVER the create/edit Modal via the shared
       overlay stack (Esc closes the builder first; the topmost scrim/focus-trap
       wins). The body owns its own scroll regions, so scroll-body is off. On save
       we adopt the new form (set form_id + seed) and close; cancel just closes. -->
  <Drawer
    v-model:open="builderOpen"
    side="right"
    size="cover"
    floating
    :scroll-body="false"
    :show-close="false"
    :aria-label="t('forms.builder.createTitle')"
  >
    <FormBuilderView @saved="onFormCreated" @close="builderOpen = false" />
  </Drawer>
</template>
