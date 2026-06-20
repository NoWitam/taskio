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
import FormField from '../../ui/forms/FormField.vue';
import TextInput from '../../ui/forms/TextInput.vue';
import UserSelect from '../../ui/forms/UserSelect.vue';
import LabelSelect from '../../ui/forms/LabelSelect.vue';
import SegmentedControl, { type SegmentOption } from '../../ui/forms/SegmentedControl.vue';
import DatePicker from '../../ui/forms/DatePicker.vue';
import MarkdownEditor from '../../ui/editor/MarkdownEditor.vue';
import TaskAttachmentsField from './TaskAttachmentsField.vue';
import Alert from '../../ui/feedback/Alert.vue';
import Button from '../../ui/primitives/Button.vue';
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
    } else {
      Object.assign(form, blankForm());
      assigneeSeed.value = [];
      labelSeed.value = [];
      attachmentSeed.value = [];
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
  const payload: TaskWritePayload = {
    title: form.title.trim(),
    // The backend stores the description as a ProseMirror doc parsed from a JSON
    // STRING (rule: nullable|string). Convert editor markdown → JSON doc string
    // (or null when empty) so the cast can rebuild the tree.
    description: markdownToTaskDescriptionPayload(form.description),
    priority: form.priority,
    deadline: form.deadline ?? null,
    assigned_id: form.assigned_id ?? '',
    labels: form.labels,
    // Only NEW uploads are sent; the backend attaches them additively, so
    // existing attachments are preserved without re-sending their ids.
    attachments: form.attachments,
  };
  // Preserve form/pipeline links on edit (no picker in this batch) so they are
  // not wiped by the update.
  if (props.task) {
    if (props.task.form_id) payload.form_id = props.task.form_id;
    if (props.task.approval_pipeline_id)
      payload.approval_pipeline_id = props.task.approval_pipeline_id;
  }
  return payload;
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
</script>

<template>
  <Modal v-model:open="open" size="lg" :aria-label="modalTitle">
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

      <!-- Labels (async, multiple, max 5 server-side) -->
      <FormField :label="t('tasks.form.labels')" :error="fieldErrors.labels">
        <LabelSelect
          v-model="form.labels"
          :seed="labelSeed"
          :placeholder="t('tasks.form.labelsPlaceholder')"
          :aria-label="t('tasks.form.labels')"
        />
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
</template>
