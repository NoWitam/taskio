<script setup lang="ts">
// TemplateImagePlanPart — the authoring UI for an `image_plan` part (and a scene's optional image). An
// image plan = a BASE (where the image starts) + an ORDERED FILTER CHAIN (how it is transformed),
// mirroring the variable pipeline. It is a DECLARED plan; execution (resolving the base, running the
// chain) is sub-stage 2.
//   BASE (D5+D6):
//     • disk_file    pick a fixed Disk file (REUSES DiskFilePickerModal),
//     • from_slot    pick a declared FILE-typed slot (filled per session),
//     • ai_generate  a text→image prompt (sub-stage 6, LIVE) — authored in the SAME variable-fed
//                    MarkdownEditor the body / ai_edit prompt use, bound to `base.prompt`.
//   CHAIN: the ordered filter chain is authored by the SHARED {@see TemplateFilterChain} (also reused by
//   the storyboard) — add / remove / reorder pixel + ai_edit steps. The model maps 1:1 to the backend wire.
import { computed, ref } from 'vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Select, { type SelectOption } from '../../ui/forms/Select.vue';
import FormField from '../../ui/forms/FormField.vue';
import MarkdownEditor from '../../ui/editor/MarkdownEditor.vue';
import DiskFilePickerModal from '../disk/DiskFilePickerModal.vue';
import TemplateFilterChain from './TemplateFilterChain.vue';
import { IMAGE_BASE_KINDS, makeBase } from './imagePlan';
import { useI18n } from '../../app/i18n';
import type { ImageBaseKind, ImageCharacterMode, ImageFilterStep, ImagePlanContent } from './types';
import type { DiskFile } from '../disk/types';
import type {
  AiTextFeatureConfig,
  IfBlockFeatureConfig,
  VariableFeatureConfig,
} from '../../ui/editor/extensions/types';

const props = withDefaults(
  defineProps<{
    /** The image plan (v-model). Null renders "no base chosen yet". */
    modelValue: ImagePlanContent | null;
    /** The declared FILE-typed slot names a `from_slot` base may reference. */
    fileSlots: string[];
    /** The shared editor variable feature — for the ai_edit prompt's slot inserts. */
    variables: VariableFeatureConfig;
    /**
     * The if-block feature — unlocks IF / ELSE blocks in the ai_generate + ai_edit prompt editors,
     * exactly like the post body. Optional: an unconfigured host keeps plain prompts.
     */
    ifBlocks?: IfBlockFeatureConfig;
    /**
     * The ai-text feature (personas) — unlocks the nested `@[ai-text]` block in the prompt editors,
     * exactly like the post body. Optional: an unconfigured host keeps plain prompts.
     */
    aiText?: AiTextFeatureConfig;
    submitting?: boolean;
  }>(),
  { modelValue: null, submitting: false },
);

const emit = defineEmits<{ 'update:modelValue': [ImagePlanContent] }>();

const { t } = useI18n();

/** The current plan (a stable empty when null so the template never reads a null). */
const plan = computed<ImagePlanContent>(() => props.modelValue ?? { base: null, filters: [] });

function update(next: Partial<ImagePlanContent>): void {
  const merged: ImagePlanContent = { ...plan.value, ...next };
  // `auto` IS the absence of the key (the same convention as a storyboard's `max_shots`): an unauthored
  // plan means "whatever the run has", so only the deliberate `never` is written to the wire.
  if (merged.character !== 'never') delete merged.character;
  emit('update:modelValue', merged);
}

// --- Base -------------------------------------------------------------------
const baseKindOptions = computed<SelectOption[]>(() =>
  IMAGE_BASE_KINDS.map((kind) => ({
    value: kind,
    label: t(`generator.templates.editor.imageBase.${kind}`),
  })),
);

const baseKind = computed<ImageBaseKind | ''>(() => plan.value.base?.kind ?? '');

/** A local display name for a picked Disk file (the wire carries only the opaque id). */
const baseFileName = ref('');

function setBaseKind(kind: ImageBaseKind): void {
  baseFileName.value = '';
  update({ base: makeBase(kind) });
}

function setBaseSlot(slot: string): void {
  update({ base: { kind: 'from_slot', slot } });
}

/** The current ai_generate prompt (empty for any other base). */
const basePrompt = computed<string>(() =>
  plan.value.base?.kind === 'ai_generate' ? (plan.value.base.prompt ?? '') : '',
);
function setBasePrompt(value: string): void {
  update({ base: { kind: 'ai_generate', prompt: value } });
}

const diskPickerOpen = ref(false);
function onDiskFilePicked(file: DiskFile): void {
  baseFileName.value = file.name;
  update({ base: { kind: 'disk_file', file: file.id } });
}

const fileSlotOptions = computed<SelectOption[]>(() =>
  props.fileSlots.map((name) => ({ value: name, label: name })),
);

// --- Character (whether a delegated session's creator may appear in THIS image) --------------
// A Select, not a Switch: `auto` is CONDITIONAL ("when the session has a character"), not "on", and a
// switch would claim this image always shows someone. Only two authored images exist per template, so
// the cost of an explicit choice is small next to a product shot that keeps growing a face.
const characterOptions = computed<SelectOption[]>(() =>
  (['auto', 'never'] as const).map((value) => ({
    value,
    label: t(`generator.templates.editor.imagePlan.character.${value}`),
  })),
);
const characterMode = computed<ImageCharacterMode>(() => plan.value.character ?? 'auto');
function setCharacter(mode: ImageCharacterMode): void {
  update({ character: mode });
}

// --- Filter chain (delegated to the shared component) -----------------------
function setFilters(filters: ImageFilterStep[]): void {
  update({ filters });
}

// --- Plan card summary ------------------------------------------------------
const baseSummary = computed<string>(() => {
  const base = plan.value.base;
  if (!base) return t('generator.templates.editor.imagePlan.noBase');
  if (base.kind === 'disk_file') return baseFileName.value || (base.file ?? '') || t('generator.templates.editor.imageBase.disk_file');
  if (base.kind === 'from_slot') return `slots.${base.slot ?? '…'}`;
  return t('generator.templates.editor.imageBase.ai_generate');
});
function stepSummary(step: ImageFilterStep): string {
  return step.kind === 'pixel'
    ? t(`generator.templates.editor.pixelOp.${step.op}`)
    : t('generator.templates.editor.imagePlan.aiEditStep');
}
</script>

<template>
  <div class="flex flex-col gap-next-4">
    <!-- BASE picker -->
    <div class="flex flex-col gap-next-2">
      <span class="text-next-xs font-next-medium text-next-muted-foreground">{{ t('generator.templates.editor.imagePlan.baseLabel') }}</span>
      <div class="flex flex-wrap items-start gap-next-2">
        <div class="w-48 shrink-0">
          <Select
            :model-value="baseKind"
            :options="baseKindOptions"
            size="sm"
            :disabled="submitting"
            :placeholder="t('generator.templates.editor.imagePlan.chooseBase')"
            :aria-label="t('generator.templates.editor.imagePlan.baseLabel')"
            @update:model-value="(v) => setBaseKind(v as ImageBaseKind)"
          />
        </div>

        <!-- disk_file: pick a Disk file. -->
        <div v-if="baseKind === 'disk_file'" class="flex min-w-0 flex-1 items-center gap-next-2">
          <Button variant="outline" size="sm" type="button" leading-icon="folder" :disabled="submitting" @click="diskPickerOpen = true">
            {{ t('generator.templates.editor.imagePlan.chooseDiskFile') }}
          </Button>
          <span v-if="plan.base?.file" class="min-w-0 flex-1 truncate text-next-sm text-next-fg">{{ baseSummary }}</span>
        </div>

        <!-- from_slot: pick a declared file slot. -->
        <div v-else-if="baseKind === 'from_slot'" class="min-w-0 flex-1">
          <Select
            v-if="fileSlots.length > 0"
            :model-value="plan.base?.slot ?? ''"
            :options="fileSlotOptions"
            size="sm"
            :disabled="submitting"
            :placeholder="t('generator.templates.editor.imagePlan.chooseSlot')"
            :aria-label="t('generator.templates.editor.imagePlan.chooseSlot')"
            @update:model-value="(v) => setBaseSlot(String(v))"
          />
          <p v-else class="text-next-xs text-next-muted-foreground">{{ t('generator.templates.editor.imagePlan.noFileSlots') }}</p>
        </div>
      </div>

      <!-- ai_generate: a text→image prompt (authored like the body — slots allowed, live feed). -->
      <div v-if="baseKind === 'ai_generate'">
        <FormField :label="t('generator.templates.editor.imagePlan.aiGeneratePrompt')">
          <MarkdownEditor
            :model-value="basePrompt"
            min-height="4rem"
            :variables="variables"
            :if-blocks="ifBlocks"
            :ai-text="aiText"
            :disabled="submitting"
            :placeholder="t('generator.templates.editor.imagePlan.aiGeneratePlaceholder')"
            :aria-label="t('generator.templates.editor.imagePlan.aiGeneratePrompt')"
            @update:model-value="setBasePrompt"
          />
        </FormField>
      </div>
    </div>

    <!-- CHARACTER: may a delegated session's creator appear in this image? -->
    <div class="flex flex-wrap items-start gap-next-2">
      <span class="w-full text-next-xs font-next-medium text-next-muted-foreground">
        {{ t('generator.templates.editor.imagePlan.character.label') }}
      </span>
      <div class="w-56 shrink-0">
        <Select
          :model-value="characterMode"
          :options="characterOptions"
          size="sm"
          :disabled="submitting"
          :aria-label="t('generator.templates.editor.imagePlan.character.label')"
          @update:model-value="(v) => setCharacter(v as ImageCharacterMode)"
        />
      </div>
      <p class="min-w-0 flex-1 text-next-xs text-next-muted-foreground">
        {{ t('generator.templates.editor.imagePlan.character.hint') }}
      </p>
    </div>

    <!-- FILTER chain (shared) -->
    <TemplateFilterChain
      :model-value="plan.filters"
      :variables="variables"
      :if-blocks="ifBlocks"
      :ai-text="aiText"
      :submitting="submitting"
      @update:model-value="setFilters"
    />

    <!-- PLAN CARD summary -->
    <div class="flex flex-col gap-next-1 rounded-next-md border border-next-border bg-next-card p-next-3">
      <span class="text-next-xs font-next-medium text-next-muted-foreground">{{ t('generator.templates.editor.imagePlan.summaryLabel') }}</span>
      <div class="flex items-center gap-next-2 text-next-sm text-next-fg">
        <Icon name="image" class="text-next-muted-foreground" aria-hidden="true" />
        <span class="truncate">{{ baseSummary }}</span>
      </div>
      <ol v-if="plan.filters.length > 0" class="flex flex-wrap items-center gap-next-1 text-next-xs text-next-muted-foreground">
        <li v-for="(step, index) in plan.filters" :key="index" class="inline-flex items-center gap-next-1">
          <Icon name="chevron-right" class="text-next-xs" aria-hidden="true" />
          {{ stepSummary(step) }}
        </li>
      </ol>
    </div>

    <DiskFilePickerModal
      v-model:open="diskPickerOpen"
      :accepted-types="['image/*']"
      @select="onDiskFilePicked"
    />
  </div>
</template>
