<script setup lang="ts">
// TemplateEditorDrawer — the create / edit form for a generation TEMPLATE (a content RECIPE).
//
// A wide right Drawer, TWO columns on wide screens: LEFT the authoring form, RIGHT the FAITHFUL per-part
// live preview. The form is DATA-DRIVEN by the chosen content type's PARTS (never a hard-coded
// post/image layout):
//   0. CONTENT TYPE — create STEP 1 is the ContentTypePicker (post / post+image / video script, from
//                     `GET /generator/content-types`); edit locks the saved type.
//   1. IDENTITY     — `name` (required) + an optional `description`.
//   2. SLOTS        — the declared typed inputs (TemplateSlotsPanel), each surfaced as `slots.<name>`.
//   3. PARTS        — one SECTION per declared part, rendered BY KIND (D8): text_body / script → the body
//                     editor with the first-class `@[ai-text]` block; image_plan → the base + filter-chain
//                     builder; scene_plan → the lean scene list. Optional parts are opt-in (a toggle).
// The catalog is fetched LIVE from the current draft slots (`POST /generator/catalog`, debounced) so the
// `{`-insert picker updates as slots are declared. The preview posts the draft to `POST /generator/preview`.
//
// The form is PURE: it validates client-side (mirroring the backend validators — the server stays
// authoritative) and, when valid, EMITS a `TemplateWritePayload`. The parent (TemplatesView) owns the
// store call, toasts, and feeds back 422 field errors.
import { computed, markRaw, onMounted, ref, watch } from 'vue';
import Drawer from '../../ui/overlay/Drawer.vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Badge from '../../ui/primitives/Badge.vue';
import FormField from '../../ui/forms/FormField.vue';
import TextInput from '../../ui/forms/TextInput.vue';
import Textarea from '../../ui/forms/Textarea.vue';
import Switch from '../../ui/forms/Switch.vue';
import Alert from '../../ui/feedback/Alert.vue';
import TemplateSlotsPanel from './TemplateSlotsPanel.vue';
import TemplatePreview from './TemplatePreview.vue';
import ContentTypePicker from './ContentTypePicker.vue';
import TemplateBodyPart from './TemplateBodyPart.vue';
import TemplateImagePlanPart from './TemplateImagePlanPart.vue';
import TemplateScenePlanPart from './TemplateScenePlanPart.vue';
import TemplateShotListPart from './TemplateShotListPart.vue';
import TemplateStoryboardPart from './TemplateStoryboardPart.vue';
import WorkflowArgVariableField from '../workflows/WorkflowArgVariableField.vue';
import {
  templateEditorVariables,
  templateOperationsCatalog,
  templateSourceVariables,
} from './templateCatalog';
import { contentTypeIcon, contentTypeLabel, partKindIcon } from './templateMeta';
import {
  activatePart,
  buildWriteContent,
  deactivatePart,
  fileSlotNames,
  isContentComplete,
  isPartActive,
  seedContent,
} from './templateContent';
import {
  catalogSlots,
  slotDraftToWire,
  slotFieldError,
  slotToDraft,
  validateSlotDrafts,
  type SlotDraft,
} from './templateSlots';
import { useTemplatesStore } from '../../app/stores/templates';
import { useDebounce } from '../../app/composables/useDebounce';
import { useI18n } from '../../app/i18n';
import type {
  BodyContent,
  ContentTypeDefinition,
  ContentTypePart,
  GeneratorCatalog,
  ImagePlanContent,
  ScenePlanContent,
  ShotListContent,
  StoryboardContent,
  Template,
  TemplateContent,
  TemplateWritePayload,
} from './types';
import type {
  AiPersona,
  AiTextFeatureConfig,
  IfBlockFeatureConfig,
  VariableFeatureConfig,
} from '../../ui/editor/extensions/types';

const props = withDefaults(
  defineProps<{
    /** The template being edited, or null/undefined for a create. */
    template?: Template | null;
    /** True while the parent's store call is in flight (disables Save + inputs). */
    submitting?: boolean;
    /** Backend 422 field errors keyed by dotted path (name / content_type / content.* / slots.*). */
    serverErrors?: Record<string, string> | null;
  }>(),
  { template: null, submitting: false, serverErrors: null },
);

const emit = defineEmits<{ submit: [TemplateWritePayload]; close: [] }>();

const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();
const store = useTemplatesStore();

const isEdit = computed(() => props.template != null);

// --- Form state -------------------------------------------------------------
const name = ref('');
const description = ref('');
const contentType = ref('');
const content = ref<TemplateContent>({});
const slotDrafts = ref<SlotDraft[]>([]);

// --- Content types (the code-defined registry) ------------------------------
const contentTypes = ref<ContentTypeDefinition[]>([]);
const contentTypesLoading = ref(false);
const contentTypesError = ref(false);

const definition = computed<ContentTypeDefinition | null>(
  () => contentTypes.value.find((type) => type.id === contentType.value) ?? null,
);

/** Create STEP 1: choose the recipe before authoring. Edit skips it (the saved type is locked). */
const pickerMode = computed(() => !isEdit.value && contentType.value === '');

async function loadContentTypes(): Promise<void> {
  contentTypesLoading.value = true;
  contentTypesError.value = false;
  try {
    contentTypes.value = await store.fetchContentTypes();
    // An edit opened before the types loaded: seed its content now the definition exists.
    if (isEdit.value && contentType.value !== '' && definition.value) {
      content.value = seedContent(definition.value, props.template?.content ?? {});
    }
  } catch {
    contentTypesError.value = true;
  } finally {
    contentTypesLoading.value = false;
  }
}

/** Pick a content type (create wizard step 1): set it + seed a fresh content map for its parts. */
function chooseType(id: string): void {
  contentType.value = id;
  content.value = definition.value ? seedContent(definition.value, {}) : {};
}

/** Change the recipe (create only) — back to the picker, discarding the drafted content. */
function changeType(): void {
  contentType.value = '';
  content.value = {};
}

// --- Live template catalog (draft slots → the shared editor feed) ------------
const catalog = ref<GeneratorCatalog | null>(null);
const catalogError = ref(false);
let catalogToken = 0;

/** The valid `{name, descriptor}` slots posted to the catalog + preview. */
const catalogSlotsPayload = computed(() => catalogSlots(slotDrafts.value));
/** The declared FILE-typed slot names a `from_slot` image base may reference. */
const fileSlots = computed(() => fileSlotNames(catalogSlotsPayload.value));

async function refreshCatalog(): Promise<void> {
  if (!open.value) return;
  const myToken = (catalogToken += 1);
  catalogError.value = false;
  try {
    const result = await store.fetchCatalog({ slots: catalogSlotsPayload.value });
    if (myToken !== catalogToken) return;
    catalog.value = result;
  } catch {
    if (myToken !== catalogToken) return;
    catalogError.value = true;
  }
}
const debouncedRefreshCatalog = useDebounce(refreshCatalog, 400);
watch(catalogSlotsPayload, () => debouncedRefreshCatalog(), { deep: true });

// --- MarkdownEditor feature (mirrors WorkflowStepCard's LIVE feed wiring) -----
const editorVariables = computed(() => templateEditorVariables(catalog.value));
const operationsCatalog = computed(() => templateOperationsCatalog(catalog.value));
const sourceVariables = computed(() => templateSourceVariables(catalog.value));
const editorFeature = computed<VariableFeatureConfig>(() => ({
  variables: editorVariables.value,
  operationsCatalog: operationsCatalog.value,
  // The two GETTERS keep the feed LIVE — the catalog is FETCHED (async) and changes as slots are
  // declared, so an already-open editor still sees the newest `slots.<name>` set.
  source: () => sourceVariables.value,
  catalog: () => operationsCatalog.value,
  argVariableField: markRaw(WorkflowArgVariableField),
}));

// ai-text: personas from the closed set (localized). Generation is OFF in this sub-stage — the chips
// author a run-time instruction and render inert in the preview.
const AI_PERSONA_IDS = ['neutral', 'friendly', 'formal', 'concise'];
const aiPersonas = computed<AiPersona[]>(() =>
  AI_PERSONA_IDS.map((id) => ({ id, label: t(`generator.templates.editor.aiPersona.${id}`) })),
);
const aiTextConfig = computed<AiTextFeatureConfig>(() => ({ personas: aiPersonas.value, labelsEnabled: false }));
const IF_BLOCK_CONFIG: IfBlockFeatureConfig = { maxDepth: 3 };

// --- Per-part content binding helpers ---------------------------------------
function setPartContent(key: string, value: unknown): void {
  content.value = { ...content.value, [key]: value };
}
function bodyOf(key: string): BodyContent | null {
  return (content.value[key] as BodyContent | undefined) ?? null;
}
function imageOf(key: string): ImagePlanContent | null {
  return (content.value[key] as ImagePlanContent | undefined) ?? null;
}
function sceneOf(key: string): ScenePlanContent | null {
  return (content.value[key] as ScenePlanContent | undefined) ?? null;
}
function shotListOf(key: string): ShotListContent | null {
  return (content.value[key] as ShotListContent | undefined) ?? null;
}
function storyboardOf(key: string): StoryboardContent | null {
  return (content.value[key] as StoryboardContent | undefined) ?? null;
}
function partActive(part: ContentTypePart): boolean {
  return isPartActive(content.value, part);
}
function togglePart(part: ContentTypePart, on: boolean): void {
  content.value = on ? activatePart(content.value, part) : deactivatePart(content.value, part);
}

// --- Seeding ----------------------------------------------------------------
function seed(): void {
  clearClientErrors();
  const tpl = props.template;
  if (tpl) {
    name.value = tpl.name;
    description.value = tpl.description ?? '';
    contentType.value = tpl.content_type;
    slotDrafts.value = (tpl.slots ?? []).map(slotToDraft);
    // Content is seeded once the content-type registry has loaded (definition available); if it is
    // already loaded (a re-open), seed immediately.
    content.value = definition.value ? seedContent(definition.value, tpl.content ?? {}) : {};
  } else {
    name.value = '';
    description.value = '';
    contentType.value = '';
    slotDrafts.value = [];
    content.value = {};
  }
}

watch(open, (isOpen) => {
  if (isOpen) {
    seed();
    void loadContentTypes();
    void refreshCatalog();
  }
});
onMounted(() => {
  if (open.value) {
    seed();
    void loadContentTypes();
    void refreshCatalog();
  }
});

// --- Validation + submit ----------------------------------------------------
const clientNameError = ref<string | null>(null);
function clearClientErrors(): void {
  clientNameError.value = null;
}

const slotNameErrors = computed(() => validateSlotDrafts(slotDrafts.value));
const hasSlotFieldErrors = computed(() => slotDrafts.value.some((draft) => slotFieldError(draft) !== null));
const hasSlotErrors = computed(
  () => Object.keys(slotNameErrors.value).length > 0 || hasSlotFieldErrors.value,
);

function firstServerError(prefix: string): string | null {
  const errors = props.serverErrors;
  if (!errors) return null;
  for (const [key, message] of Object.entries(errors)) {
    if (key === prefix || key.startsWith(`${prefix}.`)) return message;
  }
  return null;
}

const nameErrorText = computed(
  () => props.serverErrors?.name ?? (clientNameError.value ? t(clientNameError.value) : null),
);
const descriptionErrorText = computed(() => props.serverErrors?.description ?? null);
const contentTypeErrorText = computed(() => props.serverErrors?.content_type ?? null);
const slotsErrorText = computed(() => (props.serverErrors?.slots ? props.serverErrors.slots : null));
function partErrorText(part: ContentTypePart): string | null {
  return firstServerError(`content.${part.key}`);
}

/** The content is structurally complete enough to save (server stays authoritative). */
const contentComplete = computed(
  () => definition.value != null && isContentComplete(definition.value, content.value, fileSlots.value),
);

/** The Save gate: not submitting + a type chosen + every slot name valid + content complete. */
const canSave = computed(
  () => !props.submitting && !pickerMode.value && !hasSlotErrors.value && contentComplete.value,
);

function submit(): void {
  clearClientErrors();
  let ok = true;
  if (name.value.trim() === '') {
    clientNameError.value = 'generator.templates.editor.errors.nameRequired';
    ok = false;
  }
  if (hasSlotErrors.value || !definition.value) ok = false;
  if (!ok || !definition.value) return;

  const payload: TemplateWritePayload = {
    name: name.value.trim(),
    content_type: contentType.value,
    slots: slotDrafts.value.map(slotDraftToWire),
    content: buildWriteContent(definition.value, content.value),
  };
  const trimmedDescription = description.value.trim();
  if (trimmedDescription !== '') payload.description = trimmedDescription;

  emit('submit', payload);
}

function cancel(): void {
  open.value = false;
  emit('close');
}
</script>

<template>
  <Drawer
    v-model:open="open"
    side="right"
    size="4xl"
    :show-close="false"
    :aria-label="isEdit ? t('generator.templates.editor.editTitle') : t('generator.templates.editor.createTitle')"
  >
    <template #title>
      {{ isEdit ? t('generator.templates.editor.editTitle') : t('generator.templates.editor.createTitle') }}
    </template>

    <!-- Create STEP 1: choose the recipe. -->
    <ContentTypePicker
      v-if="pickerMode"
      :content-types="contentTypes"
      :loading="contentTypesLoading"
      :errored="contentTypesError"
      @select="chooseType"
      @retry="loadContentTypes"
    />

    <div v-else class="grid grid-cols-1 gap-next-6 next-lg:grid-cols-2">
      <!-- LEFT: the authoring form. -->
      <form class="flex flex-col gap-next-6" @submit.prevent="submit">
        <!-- Chosen content type (create: changeable; edit: locked). -->
        <div class="flex items-center gap-next-2">
          <Badge variant="neutral" tone="subtle" size="sm" :icon="contentTypeIcon(contentType)">
            {{ contentTypeLabel(contentType, t, definition?.label) }}
          </Badge>
          <Button
            v-if="!isEdit"
            variant="ghost"
            size="xs"
            type="button"
            leading-icon="rotate-ccw"
            :disabled="submitting"
            @click="changeType"
          >
            {{ t('generator.templates.editor.changeType') }}
          </Button>
          <Alert v-if="contentTypeErrorText" variant="danger" size="sm">{{ contentTypeErrorText }}</Alert>
        </div>

        <!-- 1. Identity -->
        <section class="flex flex-col gap-next-4">
          <FormField :label="t('generator.templates.editor.nameLabel')" required :error="nameErrorText ?? undefined">
            <TextInput
              v-model="name"
              :disabled="submitting"
              :placeholder="t('generator.templates.editor.namePlaceholder')"
            />
          </FormField>
          <FormField :label="t('generator.templates.editor.descriptionLabel')" :error="descriptionErrorText ?? undefined">
            <Textarea
              v-model="description"
              :disabled="submitting"
              :rows="2"
              :placeholder="t('generator.templates.editor.descriptionPlaceholder')"
            />
          </FormField>
        </section>

        <!-- 2. Slots -->
        <TemplateSlotsPanel v-model="slotDrafts" :submitting="submitting" :server-errors="serverErrors" />
        <Alert v-if="slotsErrorText" variant="danger" size="sm">{{ slotsErrorText }}</Alert>

        <!-- 3. Data-driven part sections (rendered by KIND from the definition). -->
        <section v-for="part in definition?.parts ?? []" :key="part.key" class="flex flex-col gap-next-2">
          <div class="flex items-center gap-next-2">
            <Icon :name="partKindIcon(part.kind)" class="text-next-muted-foreground" aria-hidden="true" />
            <span class="text-next-sm font-next-medium text-next-fg">
              {{ t(`generator.templates.editor.partLabel.${part.kind}`, part.label) }}
            </span>
            <!-- Optional parts are opt-in. -->
            <template v-if="!part.required">
              <span class="flex-1" />
              <Switch
                :model-value="partActive(part)"
                size="sm"
                :disabled="submitting"
                :label="t('generator.templates.editor.enablePart')"
                @update:model-value="(v) => togglePart(part, v)"
              />
            </template>
          </div>

          <template v-if="part.required || partActive(part)">
            <!-- text_body / script -->
            <TemplateBodyPart
              v-if="part.kind === 'text_body' || part.kind === 'script'"
              :model-value="bodyOf(part.key)"
              :variables="editorFeature"
              :ai-text="aiTextConfig"
              :if-blocks="IF_BLOCK_CONFIG"
              :submitting="submitting"
              :placeholder="t('generator.templates.editor.bodyPlaceholder')"
              :aria-label="t(`generator.templates.editor.partLabel.${part.kind}`, part.label)"
              @update:model-value="(v) => setPartContent(part.key, v)"
            />

            <!-- image_plan -->
            <TemplateImagePlanPart
              v-else-if="part.kind === 'image_plan'"
              :model-value="imageOf(part.key)"
              :file-slots="fileSlots"
              :variables="editorFeature"
              :ai-text="aiTextConfig"
              :if-blocks="IF_BLOCK_CONFIG"
              :submitting="submitting"
              @update:model-value="(v) => setPartContent(part.key, v)"
            />

            <!-- scene_plan (legacy — no longer authored, kept for an old template's back-compat edit) -->
            <TemplateScenePlanPart
              v-else-if="part.kind === 'scene_plan'"
              :model-value="sceneOf(part.key)"
              :variables="editorFeature"
              :ai-text="aiTextConfig"
              :if-blocks="IF_BLOCK_CONFIG"
              :file-slots="fileSlots"
              :submitting="submitting"
              @update:model-value="(v) => setPartContent(part.key, v)"
            />

            <!-- shot_list (video_script Phase B) — a creative BRIEF authored like the body -->
            <TemplateShotListPart
              v-else-if="part.kind === 'shot_list'"
              :model-value="shotListOf(part.key)"
              :variables="editorFeature"
              :ai-text="aiTextConfig"
              :if-blocks="IF_BLOCK_CONFIG"
              :submitting="submitting"
              @update:model-value="(v) => setPartContent(part.key, v)"
            />

            <!-- storyboard (video_script Phase B) — optional style prompt + filter chain (no base) -->
            <TemplateStoryboardPart
              v-else-if="part.kind === 'storyboard'"
              :model-value="storyboardOf(part.key)"
              :variables="editorFeature"
              :ai-text="aiTextConfig"
              :if-blocks="IF_BLOCK_CONFIG"
              :file-slots="fileSlots"
              :submitting="submitting"
              @update:model-value="(v) => setPartContent(part.key, v)"
            />
          </template>

          <Alert v-if="partErrorText(part)" variant="danger" size="sm">{{ partErrorText(part) }}</Alert>
        </section>

        <p v-if="catalogError" class="text-next-xs text-next-muted-foreground">
          {{ t('generator.templates.editor.catalogError') }}
        </p>
      </form>

      <!-- RIGHT: the faithful live preview (sticky on wide screens). -->
      <div class="next-lg:sticky next-lg:top-0 next-lg:self-start">
        <TemplatePreview
          :content-type="contentType"
          :content="content"
          :parts="definition?.parts ?? []"
          :slots="catalogSlotsPayload"
        />
      </div>
    </div>

    <template #footer>
      <Button variant="outline" type="button" :disabled="submitting" @click="cancel">
        {{ t('common.cancel') }}
      </Button>
      <Button
        v-if="!pickerMode"
        variant="primary"
        type="button"
        :loading="submitting"
        :disabled="!canSave"
        @click="submit"
      >
        {{ isEdit ? t('common.save') : t('generator.templates.editor.create') }}
      </Button>
    </template>
  </Drawer>
</template>
