<script setup lang="ts">
// FormBuilderView — the full-page form builder (next), for `/forms/new` and
// `/forms/:id/edit`.
//
// Layout (legacy-inspired): the PAGE never scrolls — only its columns do. A fixed
// header + compact metadata sit above a full-height 3-column workspace:
//   1. LEFT  — the PALETTE (drag elements onto the canvas / click to add); when an
//      element is SELECTED this panel becomes its ElementEditor (no modal).
//   2. CENTER — the canvas (ElementCanvas): drag elements from the palette to the
//      exact drop position, drag to reorder, click to select. A Preview toggle
//      swaps it for a clean FormViewer.
//   3. RIGHT — ElementTree: the form's structure outline (select + reorder).
//
// Element ids are client temp ids; the server NORMALIZES them on save, so we
// navigate back to the list (which refetches) afterwards. All strings via i18n.
import { computed, onMounted, provide, reactive, ref } from 'vue';
import Button from '../../../ui/primitives/Button.vue';
import Card from '../../../ui/layout/Card.vue';
import Surface from '../../../ui/layout/Surface.vue';
import FormField from '../../../ui/forms/FormField.vue';
import TextInput from '../../../ui/forms/TextInput.vue';
import IconInput from '../../../ui/forms/IconInput.vue';
import EmptyState from '../../../ui/data/EmptyState.vue';
import Alert from '../../../ui/feedback/Alert.vue';
import SegmentedControl, { type SegmentOption } from '../../../ui/forms/SegmentedControl.vue';
import ElementPalette from './ElementPalette.vue';
import ElementTree from './ElementTree.vue';
import ElementEditor from './ElementEditor.vue';
import ElementCanvas from './ElementCanvas.vue';
import FormViewer from '../FormViewer.vue';
import { useFormsStore } from '../../../app/stores/forms';
import { useToast } from '../../../app/composables/useToast';
import { useI18n } from '../../../app/i18n';
import { PICKABLE_ICONS, toIconEnumValue } from '../../../ui/forms/filterTabIcon';
import { resolveLabelIcon } from '../../../ui/forms/labelIcon';
import type { IconName } from '../../../ui/primitives/icons';
import { BUILDER_CTX, type BuilderDragState } from './dnd';
import {
  addChild,
  createElement,
  findElement,
  hasInputFields,
  removeElement,
  duplicateElement,
  updateElementConfig,
} from './elements';
import type { FormDetail, FormElement, FormElementType } from '../types';

const props = defineProps<{
  formId?: string | null;
  /** Build a task-only ANONYMOUS form: no name/icon/description, hidden from the
   *  Forms list, auto-enabled server-side. */
  anonymous?: boolean;
}>();
const emit = defineEmits<{ (e: 'close'): void; (e: 'saved', form: FormDetail): void }>();

const store = useFormsStore();
const toast = useToast();
const { t } = useI18n();

const formId = computed(() => props.formId ?? null);
const isEdit = computed(() => formId.value !== null);

// --- Metadata + content state --------------------------------------------
const name = ref('');
const icon = ref<IconName | null>(null);
const description = ref('');
const elements = ref<FormElement[]>([]);
const selectedId = ref<string | null>(null);

const loading = ref(false);
const loadError = ref(false);
const saving = ref(false);
const nameError = ref<string | null>(null);
const centerMode = ref<'edit' | 'preview'>('edit');

const iconPool = PICKABLE_ICONS;
const centerOptions = computed<SegmentOption[]>(() => [
  { value: 'edit', label: t('forms.builder.editTab'), icon: 'pencil' },
  { value: 'preview', label: t('forms.preview'), icon: 'eye' },
]);

const selected = computed(() =>
  selectedId.value ? findElement(elements.value, selectedId.value) : null,
);

// Container the click-shortcut adds INTO: a selected section/repeater, else root.
const addTarget = computed(() => {
  const el = selected.value;
  return el && (el.type === 'section' || el.type === 'repeater') ? el : null;
});
const addTargetLabel = computed(() => addTarget.value?.config.name || undefined);
const needsInputHint = computed(() => elements.value.length > 0 && !hasInputFields(elements.value));

// --- Shared builder context (drag state + tree access) --------------------
const dnd = reactive<BuilderDragState>({ type: null, id: null });
provide(BUILDER_CTX, {
  dnd,
  startNew: (type) => {
    dnd.type = type;
    dnd.id = null;
  },
  startMove: (id) => {
    dnd.id = id;
    dnd.type = null;
  },
  endDnd: () => {
    dnd.type = null;
    dnd.id = null;
  },
  create: (type) => createElement(type, t(`forms.elementTypes.${type}`)),
  root: () => elements.value,
  selectedId: () => selectedId.value,
  select: (id) => {
    selectedId.value = id;
  },
});

// --- Load (edit) ----------------------------------------------------------
onMounted(async () => {
  if (!isEdit.value || !formId.value) return;
  loading.value = true;
  loadError.value = false;
  const detail = await store.fetchForm(formId.value);
  loading.value = false;
  if (!detail) {
    loadError.value = true;
    return;
  }
  name.value = detail.name;
  icon.value = detail.icon ? resolveLabelIcon(detail.icon) : null;
  description.value = detail.description ?? '';
  elements.value = (detail.content ?? []) as FormElement[];
});

// --- Palette click shortcut (drag is handled by the canvas) ---------------
function onAdd(type: FormElementType): void {
  if (type === 'section' && addTarget.value) {
    toast.info(t('forms.builder.noNestedSections'));
    return;
  }
  const element = createElement(type, t(`forms.elementTypes.${type}`));
  if (addTarget.value) {
    addChild(elements.value, addTarget.value.id, element);
  } else {
    elements.value.push(element);
  }
  selectedId.value = element.id;
}

function onEditorUpdate(config: Record<string, unknown>): void {
  if (!selectedId.value) return;
  updateElementConfig(elements.value, selectedId.value, config);
}
function onDuplicate(id: string): void {
  const copy = duplicateElement(elements.value, id);
  if (copy) selectedId.value = copy.id;
}
function onDelete(id: string): void {
  removeElement(elements.value, id);
  if (selectedId.value === id) selectedId.value = null;
}

// --- Save -----------------------------------------------------------------
async function save(): Promise<void> {
  nameError.value = null;
  // Anonymous (task-only) forms need no name — the server auto-generates one.
  if (!props.anonymous && !name.value.trim()) {
    nameError.value = t('forms.builder.nameRequired');
    return;
  }
  saving.value = true;
  const payload = {
    name: props.anonymous ? '' : name.value.trim(),
    icon: props.anonymous ? null : toIconEnumValue(icon.value),
    description: props.anonymous ? null : description.value.trim() || null,
    content: elements.value,
    is_anonymous: !!props.anonymous,
  };
  try {
    const detail = isEdit.value && formId.value
      ? await store.updateForm(formId.value, payload)
      : await store.createForm(payload);
    toast.success(isEdit.value ? t('forms.builder.saved') : t('forms.builder.created'));
    emit('saved', detail);
  } catch (err: unknown) {
    const e = err as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } };
    const fieldErrors = e.response?.data?.errors;
    if (fieldErrors?.name?.length) {
      nameError.value = fieldErrors.name[0];
    } else if (fieldErrors?.content?.length) {
      toast.danger(fieldErrors.content[0]);
    } else {
      toast.danger(e.response?.data?.message ?? t('forms.builder.saveError'));
    }
  } finally {
    saving.value = false;
  }
}

function cancel(): void {
  emit('close');
}
</script>

<template>
  <!-- Full-height page: header + metadata fixed; columns scroll, not the page. -->
  <div class="flex min-h-0 flex-1 flex-col gap-next-4">
    <div class="flex flex-wrap items-center justify-between gap-next-3">
      <div class="flex items-center gap-next-3">
        <Button variant="ghost" size="icon-sm" leading-icon="arrow-left" :aria-label="t('common.back')" @click="cancel" />
        <h1 class="text-next-xl font-next-semibold text-next-fg">
          {{ isEdit ? t('forms.builder.editTitle') : anonymous ? t('forms.builder.createAnonymousTitle') : t('forms.builder.createTitle') }}
        </h1>
      </div>
      <div class="flex items-center gap-next-2">
        <Button variant="outline" @click="cancel">{{ t('common.cancel') }}</Button>
        <Button leading-icon="check" :loading="saving" @click="save">
          {{ isEdit ? t('common.save') : t('common.create') }}
        </Button>
      </div>
    </div>

    <EmptyState
      v-if="loadError"
      variant="error"
      :title="t('forms.error.title')"
      :description="t('forms.error.description')"
    />

    <template v-else>
      <!-- Compact metadata: name + icon on one row, description on its own row.
           Hidden for an anonymous (task-only) form, which needs no name/icon/
           description; only the "needs an input field" hint stays. -->
      <Card v-if="!anonymous || needsInputHint" class="flex flex-col gap-next-4 p-next-5">
        <template v-if="!anonymous">
          <div class="grid grid-cols-1 gap-next-3 next-md:grid-cols-[1fr_13rem]">
            <FormField :label="t('common.name')" required :error="nameError ?? undefined">
              <TextInput v-model="name" :placeholder="t('forms.builder.namePlaceholder')" />
            </FormField>
            <FormField :label="t('common.icon')">
              <IconInput v-model="icon" :icons="iconPool" clearable />
            </FormField>
          </div>
          <FormField :label="t('common.description')">
            <TextInput v-model="description" :placeholder="t('forms.builder.descriptionPlaceholder')" />
          </FormField>
        </template>
        <Alert v-if="needsInputHint" variant="warning" size="sm" :title="t('forms.builder.needInputTitle')">
          {{ t('forms.builder.needInputBody') }}
        </Alert>
      </Card>

      <!-- 3-column workspace. FLEX (not grid) so each column STRETCHES to the
           workspace height and scrolls internally (a grid's auto row would grow
           with content and defeat the scroll). -->
      <div class="flex min-h-0 flex-1 flex-col gap-next-4 next-lg:flex-row">
        <!-- LEFT: the element palette (always). -->
        <Surface
          bg="card"
          border
          elevation="sm"
          radius="lg"
          class="hidden w-64 shrink-0 min-h-0 flex-col overflow-y-auto p-next-4 next-lg:flex"
        >
          <ElementPalette :target-label="addTargetLabel" @add="onAdd" />
        </Surface>

        <!-- CENTER: built on Surface (NOT Card) so the BODY is a real flex child
             that scrolls — Card's `.next-card__body` isn't height-bounded. The
             header is fixed (shrink-0); the body fills + scrolls. `min-w-0` lets
             the column shrink instead of blowing out past the screen. -->
        <Surface
          bg="card"
          border
          elevation="sm"
          radius="lg"
          class="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden"
        >
          <div class="flex shrink-0 items-center justify-between gap-next-2 border-b border-next-border p-next-3">
            <SegmentedControl v-model="centerMode" :options="centerOptions" size="sm" />
            <span class="text-next-xs text-next-muted-foreground">{{ elements.length }}</span>
          </div>
          <div class="min-h-0 min-w-0 flex-1 overflow-y-auto p-next-4">
            <ElementCanvas
              v-if="centerMode === 'edit'"
              :elements="elements"
              accept="root"
              @duplicate="onDuplicate"
              @delete="onDelete"
            />
            <FormViewer v-else :content="elements" mode="preview" />
          </div>
        </Surface>

        <!-- RIGHT: the selected element's editor, else the structure outline. -->
        <Surface
          bg="card"
          border
          elevation="sm"
          radius="lg"
          class="hidden w-[17rem] shrink-0 min-h-0 flex-col overflow-y-auto p-next-4 next-lg:flex"
        >
          <ElementEditor
            v-if="selected"
            :key="selected.id"
            :element="selected"
            @update="onEditorUpdate"
            @select="selectedId = $event"
            @close="selectedId = null"
          />
          <template v-else>
            <h4 class="mb-next-3 text-next-2xs font-next-semibold uppercase tracking-next-wide text-next-muted-foreground">
              {{ t('forms.builder.structure') }}
            </h4>
            <ElementTree
              v-if="elements.length"
              :elements="elements"
              :selected-id="selectedId"
              @select="selectedId = $event"
            />
            <p v-else class="text-next-sm text-next-muted-foreground">
              {{ t('forms.builder.structureEmpty') }}
            </p>
          </template>
        </Surface>
      </div>
    </template>
  </div>
</template>
