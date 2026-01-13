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
import { useToast } from '@/composables/useToast';

type Priority = 'high' | 'medium' | 'low';

const props = defineProps<{ modelValue: boolean }>();
const emit = defineEmits<{
  (e: 'update:modelValue', v: boolean): void;
  (e: 'created', task: any): void;
}>();

const open = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
});

const tasksStore = useTasksStore();
const { push: pushToast } = useToast();

const submitting = ref(false);

const form = reactive({
  title: '',
  description: '',
  priority: 'medium' as Priority,
  due_date: null as string | null, // YYYY-MM-DD
  user_id: null as string | number | null,
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
  form.due_date = null;
  form.user_id = null;
  form.labels = [];
  form.task_form.mode = 'none';
  form.task_form.template_id = null;
  attachments.value = [];
  resetErrors();
}

watch(
  () => open.value,
  (v) => {
    if (v) {
      resetErrors();
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

  if (!form.user_id) errors.user_id = 'Wybierz użytkownika.';

  return Object.keys(errors).length === 0;
}

function toFormData() {
  const fd = new FormData();

  fd.append('title', form.title.trim());
  if (form.description?.trim()) fd.append('description', form.description.trim());
  fd.append('priority', form.priority);
  if (form.due_date) fd.append('due_date', form.due_date);
  if (form.user_id != null) fd.append('user_id', String(form.user_id));
  fd.append('status', 'to_do');

  (form.labels || []).forEach((id) => fd.append('labels[]', String(id)));

  // Future extension point
  fd.append('task_form_mode', form.task_form.mode);
  if (form.task_form.template_id) fd.append('task_form_template_id', form.task_form.template_id);

  (attachments.value || []).forEach((uuid) => fd.append('attachments[]', String(uuid)));

  return fd;
}

async function submit() {
  if (submitting.value) return;
  if (!validate()) return;

  submitting.value = true;
  try {
    const payload = toFormData();
    const created = await tasksStore.createTask(payload as any);

    pushToast({
      title: 'Zadanie utworzone',
      message: `"${created?.title ?? form.title}" zostało dodane.`,
      tone: 'success',
      timeoutMs: 3500,
    });

    emit('created', created);
    open.value = false;
  } catch (e: any) {
    pushToast({
      title: 'Nie udało się utworzyć zadania',
      message: tasksStore.error ?? 'Sprawdź dane i spróbuj ponownie.',
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
    title="Nowe zadanie"
    description="Utwórz zadanie i przypisz je do osoby odpowiedzialnej."
    width="lg"
  >
    <div class="space-y-6">
      <div class="grid grid-cols-12 gap-4">
        <div class="col-span-12">
          <TextInput
            v-model="form.title"
            label="Tytuł"
            placeholder="Np. Przygotuj ofertę dla klienta"
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
            label="Opis"
            placeholder="Dodatkowe informacje, kontekst, kryteria akceptacji..."
          />
        </div>

        <div class="col-span-4">
          <SelectInput
            v-model="form.priority"
            label="Priorytet"
            :error="errors.priority"
            :options="[
              { label: 'Wysoki', value: 'high' },
              { label: 'Średni', value: 'medium' },
              { label: 'Niski', value: 'low' },
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
            v-model="form.due_date"
            label="Termin"
            clearable
            :error="errors.due_date"
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
            v-model="form.user_id"
            label="Przypisany użytkownik"
            :error="errors.user_id"
            :multiple="false"
            :clearable="true"
          />
        </div>

        <div class="col-span-12">
          <LabelSelect
            v-model="form.labels"
            label="Etykiety"
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
                <div class="text-sm font-semibold">Formularz do wypełnienia (wkrótce)</div>
                <div class="mt-1 text-sm text-muted-foreground">
                  W przyszłości dodamy generator formularzy podpinany do zadania. Przypisany użytkownik będzie musiał
                  go wypełnić. Ta sekcja jest już przygotowana pod rozbudowę.
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="col-span-12">
          <div class="space-y-3">
            <div class="text-sm font-medium text-foreground">Załączniki</div>
            <FileDropzone v-model="attachments" :multiple="true" />
          </div>
        </div>
      </div>
    </div>

    <template #footer>
      <Button type="button" variant="secondary" :disabled="submitting" @click="open = false">Anuluj</Button>
      <Button type="button" variant="primary" :loading="submitting" @click="submit">
        <Icon name="plus" size="sm" />
        Utwórz
      </Button>
    </template>
  </Dialog>
</template>
