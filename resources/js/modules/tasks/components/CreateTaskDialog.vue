<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import Dialog from '@/components/ui/Dialog.vue';
import Button from '@/components/ui/Button.vue';
import TextInput from '@/components/ui/inputs/TextInput.vue';
import TextareaInput from '@/components/ui/inputs/TextareaInput.vue';
import SelectInput from '@/components/ui/inputs/SelectInput.vue';
import DateInput from '@/components/ui/inputs/DateInput.vue';
import UserSelect from '@/components/ui/inputs/reusable/UserSelect.vue';
import LabelSelect from '@/modules/labels/components/LabelSelect.vue';
import FileDropzone from '@/components/ui/inputs/FileDropzone.vue';
import Icon from '@/components/ui/Icon.vue';
import { useTasksStore } from '@/store/tasks';
import { useUsersStore } from '@/store/users';
import { useLabelsStore } from '@/store/labels';
import { useToast } from '@/composables/useToast';
import { useI18n } from '@/composables/useI18n';
import type { TaskAttachment } from '@/store/tasks';

type Priority = 'urgent' | 'high' | 'medium' | 'low';

const props = defineProps<{ 
  modelValue: boolean;
  editMode?: boolean;
  taskId?: string;
}>();
const emit = defineEmits<{
  (e: 'update:modelValue', v: boolean): void;
  (e: 'created', task: any): void;
  (e: 'updated', task: any): void;
}>();

const open = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
});

const tasksStore = useTasksStore();
const usersStore = useUsersStore();
const labelsStore = useLabelsStore();
const { push: pushToast } = useToast();
const { t } = useI18n();

const submitting = ref(false);
const existingAttachments = ref<TaskAttachment[]>([]);

const form = reactive({
  title: '',
  description: '',
  priority: 'medium' as Priority,
  deadline: null as string | null, // YYYY-MM-DD
  assigned_id: null as string | number | null,
  labels: [] as string[],

  // Future: attach a generated/custom form to the task.
  // We'll later replace this with the real generator flow.
  task_form: {
    mode: 'none' as 'none' | 'generated',
    template_id: null as string | null,
  },
});

const attachments = ref<string[]>([]);

const errors = reactive<Record<string, string>>({});

function resetErrors() {
  Object.keys(errors).forEach((k) => delete errors[k]);
}

function resetForm() {
  form.title = '';
  form.description = '';
  form.priority = 'medium';
  form.deadline = null;
  form.assigned_id = null;
  form.labels = [];
  form.task_form.mode = 'none';
  form.task_form.template_id = null;
  attachments.value = [];
  existingAttachments.value = [];
  resetErrors();
}

watch(
  () => open.value,
  async (v) => {
    if (v) {
      resetErrors();
      
      // Jeśli tryb edycji, załaduj dane zadania
      if (props.editMode && props.taskId) {
        submitting.value = true;
        try {
          const task = await tasksStore.fetchTask(props.taskId);
          form.title = task.title;
          form.description = task.description || '';
          form.priority = task.priority;
          form.deadline = task.deadline || null;
          form.assigned_id = task.assigned?.id || null;
          form.labels = task.labels?.map((l) => String(l.id)) || [];
          attachments.value = task.attachments?.map((a) => a.id) || [];
          existingAttachments.value = task.attachments || [];
          
          // Zapisz użytkownika i etykiety bezpośrednio do cache z otrzymanych danych
          if (task.assigned) {
            usersStore.usersById[String(task.assigned.id)] = task.assigned;
          }
          if (task.labels?.length) {
            task.labels.forEach(label => {
              labelsStore.labelsById[String(label.id)] = label;
            });
          }
        } catch (e) {
          pushToast({
            title: 'Błąd podczas ładowania zadania',
            message: 'Nie udało się pobrać danych zadania.',
            tone: 'danger',
            timeoutMs: 4500,
          });
        } finally {
          submitting.value = false;
        }
      }
      return;
    }
    // When closing, reset so next open is clean.
    resetForm();
  }
);

function validate() {
  resetErrors();

  const title = (form.title || '').trim();
  if (!title) errors.title = 'Tytuł jest wymagany.';

  if (!form.priority) errors.priority = 'Priorytet jest wymagany.';

  if (!form.assigned_id) errors.assigned_id = 'Wybierz użytkownika.';

  return Object.keys(errors).length === 0;
}

function toFormData(isUpdate = false) {
  const fd = new FormData();

  // Laravel method spoofing dla PUT/PATCH przez POST
  if (isUpdate) {
    fd.append('_method', 'PUT');
  }

  fd.append('title', form.title.trim());
  if (form.description?.trim()) fd.append('description', form.description.trim());
  fd.append('priority', form.priority);
  if (form.deadline) fd.append('deadline', form.deadline);
  if (form.assigned_id != null) fd.append('assigned_id', String(form.assigned_id));
  
  // Przy edycji nie zmieniamy statusu
  if (!isUpdate) {
    fd.append('status', 'to_do');
  }

  (form.labels || []).forEach((id) => fd.append('labels[]', String(id)));

  // Future extension point
  fd.append('task_form_mode', form.task_form.mode);
  if (form.task_form.template_id) fd.append('task_form_template_id', form.task_form.template_id);

  // Załączniki
  (attachments.value || []).forEach((uuid) => fd.append('attachments[]', String(uuid)));

  return fd;
}

function removeExistingAttachment(attachmentId: string) {
  existingAttachments.value = existingAttachments.value.filter(a => a.id !== attachmentId);
  attachments.value = attachments.value.filter(id => id !== attachmentId);
}

async function submit() {
  if (submitting.value) return;
  if (!validate()) return;

  submitting.value = true;
  try {
    if (props.editMode && props.taskId) {
      // Tryb edycji - użyj POST z _method=PUT
      const payload = toFormData(true);
      const updated = await tasksStore.updateTask(props.taskId, payload as any);

      pushToast({
        title: t('tasks.taskUpdated'),
        message: t('tasks.taskUpdatedMessage', null, { title: updated?.title ?? form.title }),
        tone: 'success',
        timeoutMs: 3500,
      });

      emit('updated', updated);
      open.value = false;
    } else {
      // Tryb tworzenia
      const payload = toFormData(false);
      const created = await tasksStore.createTask(payload as any);

      pushToast({
        title: t('tasks.taskCreated'),
        message: t('tasks.taskCreatedMessage', null, { title: created?.title ?? form.title }),
        tone: 'success',
        timeoutMs: 3500,
      });

      emit('created', created);
      open.value = false;
    }
  } catch (e: any) {
    pushToast({
      title: props.editMode ? t('tasks.taskUpdateFailed') : t('tasks.taskCreateFailed'),
      message: tasksStore.error ?? t('tasks.checkData'),
      tone: 'danger',
      timeoutMs: 4500,
    });
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <Dialog
    v-model="open"
    :title="props.editMode ? t('tasks.editTask') : t('tasks.newTask')"
    :description="props.editMode ? t('tasks.editTaskDescription') : t('tasks.newTaskDescription')"
    width="lg"
  >
    <div class="space-y-6">
      <div class="grid grid-cols-12 gap-4">
        <div class="col-span-12">
          <TextInput
            v-model="form.title"
            :label="t('tasks.taskName')"
            :placeholder="t('tasks.searchPlaceholder')"
            :error="errors.title"
          >
            <template #left>
              <span class="text-muted-foreground">
                <Icon name="check-circle" />
              </span>
            </template>
          </TextInput>
        </div>

        <div class="col-span-12">
          <TextareaInput
            v-model="form.description"
            :label="t('tasks.description')"
            :placeholder="t('tasks.descriptionPlaceholder')"
          />
        </div>

        <div class="col-span-4">
          <SelectInput
            v-model="form.priority"
            :label="t('tasks.priority')"
            :error="errors.priority"
            :options="[
              { label: t('tasks.priorityUrgent'), value: 'urgent' },
              { label: t('tasks.priorityHigh'), value: 'high' },
              { label: t('tasks.priorityMedium'), value: 'medium' },
              { label: t('tasks.priorityLow'), value: 'low' },
            ]"
          >
            <template #left>
              <span class="text-muted-foreground">
                <Icon name="flag" />
              </span>
            </template>
          </SelectInput>
        </div>

        <div class="col-span-4">
          <DateInput
            v-model="form.deadline"
            :label="t('tasks.deadline')"
            clearable
            :error="errors.deadline"
            class="min-h-11"
          >
            <template #left>
              <span class="text-muted-foreground">
                <Icon name="calendar" />
              </span>
            </template>
          </DateInput>
        </div>

        <div class="col-span-4">
          <UserSelect
            v-model="form.assigned_id"
            :label="t('tasks.assignedTo')"
            :error="errors.assigned_id"
            :multiple="false"
            :clearable="true"
          />
        </div>

        <div class="col-span-12">
          <LabelSelect
            v-model="form.labels"
            :label="t('tasks.labels')"
            addable
          />
        </div>

        <div class="col-span-12">
          <div class="rounded-2xl border border-border bg-secondary/20 p-4">
            <div class="flex items-start gap-3">
              <div class="mt-0.5 text-muted-foreground">
                <Icon name="sparkles" />
              </div>
              <div class="min-w-0">
                <div class="text-sm font-semibold">{{ t('tasks.formTitle') }}</div>
                <div class="mt-1 text-sm text-muted-foreground">
                  {{ t('tasks.formDescription') }}
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="col-span-12">
          <div class="space-y-3">
            <div class="text-sm font-medium text-foreground">{{ t('tasks.attachments') }}</div>
            
            <FileDropzone v-model="attachments" :multiple="true" />
            
            <!-- Istniejące załączniki (tylko w trybie edycji) -->
            <div v-if="props.editMode && existingAttachments.length" class="space-y-2">
              <div
                v-for="attachment in existingAttachments"
                :key="attachment.id"
                class="rounded-xl border border-border bg-card px-3 py-2"
              >
                <div class="flex items-start gap-3">
                  <!-- Thumbnail/Icon -->
                  <div class="shrink-0">
                    <div
                      v-if="attachment.type === 'image'"
                      class="relative w-32 h-18 rounded-lg overflow-hidden bg-secondary"
                    >
                      <img
                        :src="attachment.path"
                        :alt="attachment.name"
                        class="w-full h-full object-cover"
                      />
                    </div>
                    <div
                      v-else-if="attachment.type === 'document'"
                      class="w-32 h-18 rounded-lg flex items-center justify-center bg-red-100 dark:bg-red-950"
                    >
                      <svg
                        class="w-12 h-12 text-red-600 dark:text-red-400"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                      >
                        <path
                          stroke-linecap="round"
                          stroke-linejoin="round"
                          stroke-width="2"
                          d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"
                        />
                      </svg>
                    </div>
                    <div
                      v-else
                      class="w-32 h-18 rounded-lg flex items-center justify-center bg-secondary"
                    >
                      <svg
                        class="w-10 h-10 text-muted-foreground"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                      >
                        <path
                          stroke-linecap="round"
                          stroke-linejoin="round"
                          stroke-width="2"
                          d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
                        />
                      </svg>
                    </div>
                  </div>

                  <!-- File Info -->
                  <div class="flex-1 min-w-0 flex items-start justify-between gap-3">
                    <div class="min-w-0">
                      <div class="truncate text-sm font-semibold">{{ attachment.name }}</div>
                      <div class="text-xs text-muted-foreground">
                        {{ attachment.size_human }}
                      </div>
                    </div>

                    <div class="shrink-0">
                      <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        :disabled="submitting"
                        @click="removeExistingAttachment(attachment.id)"
                      >
                        Usuń
                      </Button>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <template #footer>
      <Button type="button" variant="secondary" :disabled="submitting" @click="open = false">{{ t('common.cancel') }}</Button>
      <Button type="button" variant="primary" :loading="submitting" @click="submit">
        <Icon :name="props.editMode ? 'save' : 'plus'" size="sm" />
        {{ props.editMode ? t('common.save') : t('common.create') }}
      </Button>
    </template>
  </Dialog>
</template>
