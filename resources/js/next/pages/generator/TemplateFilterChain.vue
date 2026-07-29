<script setup lang="ts">
// TemplateFilterChain — the ORDERED image FILTER-CHAIN authoring, extracted from TemplateImagePlanPart so
// BOTH an image_plan (base + chain) AND a storyboard (style + chain, no base) reuse the SAME chain UI with
// no duplication. A chain step is a `pixel` op (from the imageOps.ts vocabulary, with per-op params) or an
// `ai_edit` prompt (authored like the body — slots / if-blocks / ai-text allowed). The model maps 1:1 to
// the backend wire (`ImageFilterStep[]`). Add / remove / reorder; the host owns the surrounding layout.
import { computed, ref } from 'vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Select, { type SelectOption } from '../../ui/forms/Select.vue';
import TypedLiteralInput from '../../ui/variables/TypedLiteralInput.vue';
import FormField from '../../ui/forms/FormField.vue';
import MarkdownEditor from '../../ui/editor/MarkdownEditor.vue';
import { PIXEL_OPS, makeAiEditFilter, makePixelFilter, moveStep, pixelParamShape } from './imagePlan';
import { useI18n } from '../../app/i18n';
import type { ImageFilterStep, PixelFilter, PixelOp } from './types';
import type {
  AiTextFeatureConfig,
  IfBlockFeatureConfig,
  VariableFeatureConfig,
} from '../../ui/editor/extensions/types';

const props = withDefaults(
  defineProps<{
    /** The ordered filter chain (v-model). */
    modelValue: ImageFilterStep[];
    /** The shared editor variable feature — for the ai_edit prompt's slot inserts. */
    variables: VariableFeatureConfig;
    /** The if-block feature — unlocks IF / ELSE blocks in the ai_edit prompt editors (like the body). */
    ifBlocks?: IfBlockFeatureConfig;
    /** The ai-text feature (personas) — unlocks the nested `@[ai-text]` block in the ai_edit prompt editors. */
    aiText?: AiTextFeatureConfig;
    submitting?: boolean;
  }>(),
  { submitting: false },
);

const emit = defineEmits<{ 'update:modelValue': [ImageFilterStep[]] }>();

const { t } = useI18n();

const filters = computed<ImageFilterStep[]>(() => props.modelValue ?? []);

function update(next: ImageFilterStep[]): void {
  emit('update:modelValue', next);
}

// --- Add / remove / reorder -------------------------------------------------
const pixelOpOptions = computed<SelectOption[]>(() =>
  PIXEL_OPS.map(({ op }) => ({ value: op, label: t(`generator.templates.editor.pixelOp.${op}`) })),
);
const opToAdd = ref<PixelOp>('grayscale');

function addPixel(): void {
  update([...filters.value, makePixelFilter(opToAdd.value)]);
}
function addAiEdit(): void {
  update([...filters.value, makeAiEditFilter()]);
}
function removeStep(index: number): void {
  update(filters.value.filter((_, i) => i !== index));
}
function move(index: number, dir: -1 | 1): void {
  update(moveStep(filters.value, index, dir));
}

/** Replace ONE step, preserving the rest. */
function patchStep(index: number, next: ImageFilterStep): void {
  update(filters.value.map((step, i) => (i === index ? next : step)));
}

// --- pixel params helpers (read defensively, write a fresh params object) ----
function pixelStep(step: ImageFilterStep): PixelFilter | null {
  return step.kind === 'pixel' ? step : null;
}
function paramShape(step: ImageFilterStep): string {
  return step.kind === 'pixel' ? pixelParamShape(step.op) : 'none';
}
function amountOf(step: ImageFilterStep): number {
  const p = pixelStep(step)?.params?.amount;
  return typeof p === 'number' ? p : 0;
}
function setAmount(index: number, step: ImageFilterStep, value: number): void {
  if (step.kind !== 'pixel') return;
  patchStep(index, { kind: 'pixel', op: step.op, params: { amount: clamp(value, -100, 100) } });
}
function rectOf(step: ImageFilterStep): { x: number; y: number; w: number; h: number } {
  const rect = (pixelStep(step)?.params?.rect ?? {}) as Record<string, unknown>;
  return {
    x: num(rect.x, 0),
    y: num(rect.y, 0),
    w: num(rect.w, 1),
    h: num(rect.h, 1),
  };
}
function setRectEdge(index: number, step: ImageFilterStep, edge: 'x' | 'y' | 'w' | 'h', value: number): void {
  if (step.kind !== 'pixel') return;
  const rect = { ...rectOf(step), [edge]: clamp(value, 0, 1) };
  patchStep(index, { kind: 'pixel', op: step.op, params: { rect } });
}
function turnsOf(step: ImageFilterStep): number {
  const turns = pixelStep(step)?.params?.quarterTurns;
  return typeof turns === 'number' ? turns : 1;
}
function setTurns(index: number, step: ImageFilterStep, value: number): void {
  if (step.kind !== 'pixel') return;
  patchStep(index, { kind: 'pixel', op: step.op, params: { quarterTurns: value } });
}
function axisOf(step: ImageFilterStep): string {
  const axis = pixelStep(step)?.params?.axis;
  return axis === 'vertical' ? 'vertical' : 'horizontal';
}
function setAxis(index: number, step: ImageFilterStep, value: string): void {
  if (step.kind !== 'pixel') return;
  patchStep(index, { kind: 'pixel', op: step.op, params: { axis: value === 'vertical' ? 'vertical' : 'horizontal' } });
}
function setPrompt(index: number, step: ImageFilterStep, value: string): void {
  if (step.kind !== 'ai_edit') return;
  patchStep(index, { kind: 'ai_edit', prompt: value, ...(step.mask != null ? { mask: step.mask } : {}) });
}
function promptOf(step: ImageFilterStep): string {
  return step.kind === 'ai_edit' ? step.prompt : '';
}

const turnsOptions: SelectOption[] = [
  { value: '1', label: '90°' },
  { value: '2', label: '180°' },
  { value: '3', label: '270°' },
];
const axisOptions = computed<SelectOption[]>(() => [
  { value: 'horizontal', label: t('generator.templates.editor.flipAxis.horizontal') },
  { value: 'vertical', label: t('generator.templates.editor.flipAxis.vertical') },
]);

function num(value: unknown, fallback: number): number {
  return typeof value === 'number' && !Number.isNaN(value) ? value : fallback;
}
function clamp(value: number, min: number, max: number): number {
  return Number.isNaN(value) ? min : Math.min(max, Math.max(min, value));
}

function stepSummary(step: ImageFilterStep): string {
  return step.kind === 'pixel'
    ? t(`generator.templates.editor.pixelOp.${step.op}`)
    : t('generator.templates.editor.imagePlan.aiEditStep');
}
</script>

<template>
  <div class="flex flex-col gap-next-2">
    <span class="text-next-xs font-next-medium text-next-muted-foreground">{{ t('generator.templates.editor.imagePlan.chainLabel') }}</span>

    <ol v-if="filters.length > 0" class="flex flex-col gap-next-2">
      <li
        v-for="(step, index) in filters"
        :key="index"
        class="flex flex-col gap-next-2 rounded-next-md border border-next-border bg-next-muted/20 p-next-2"
      >
        <div class="flex items-center gap-next-2">
          <span class="inline-flex items-center gap-next-1 text-next-sm font-next-medium text-next-fg">
            <Icon :name="step.kind === 'ai_edit' ? 'sparkles' : 'image'" class="text-next-muted-foreground" aria-hidden="true" />
            {{ stepSummary(step) }}
          </span>
          <span class="flex-1" />
          <Button variant="ghost" size="icon-xs" type="button" :disabled="submitting || index === 0" :aria-label="t('generator.templates.editor.imagePlan.moveUp')" @click="move(index, -1)">
            <Icon name="chevron-up" />
          </Button>
          <Button variant="ghost" size="icon-xs" type="button" :disabled="submitting || index === filters.length - 1" :aria-label="t('generator.templates.editor.imagePlan.moveDown')" @click="move(index, 1)">
            <Icon name="chevron-down" />
          </Button>
          <Button variant="ghost" size="icon-xs" type="button" :disabled="submitting" :aria-label="t('generator.templates.editor.imagePlan.removeStep')" @click="removeStep(index)">
            <Icon name="trash" />
          </Button>
        </div>

        <!-- pixel params by op shape -->
        <div v-if="paramShape(step) === 'amount'" class="w-40">
          <FormField :label="t('generator.templates.editor.pixelParam.amount')">
            <TypedLiteralInput
              :model-value="amountOf(step)"
              base="number"
              :aria-label="t('generator.templates.editor.pixelParam.amount')"
              @update:model-value="(v) => setAmount(index, step, Number(v))"
            />
          </FormField>
        </div>

        <div v-else-if="paramShape(step) === 'crop'" class="grid grid-cols-2 gap-next-2 next-sm:grid-cols-4">
          <FormField v-for="edge in (['x', 'y', 'w', 'h'] as const)" :key="edge" :label="t(`generator.templates.editor.pixelParam.${edge}`)">
            <TypedLiteralInput
              :model-value="rectOf(step)[edge]"
              base="number"
              :aria-label="t(`generator.templates.editor.pixelParam.${edge}`)"
              @update:model-value="(v) => setRectEdge(index, step, edge, Number(v))"
            />
          </FormField>
        </div>

        <div v-else-if="paramShape(step) === 'rotate'" class="w-40">
          <FormField :label="t('generator.templates.editor.pixelParam.quarterTurns')">
            <Select
              :model-value="String(turnsOf(step))"
              :options="turnsOptions"
              size="sm"
              :disabled="submitting"
              :aria-label="t('generator.templates.editor.pixelParam.quarterTurns')"
              @update:model-value="(v) => setTurns(index, step, Number(v))"
            />
          </FormField>
        </div>

        <div v-else-if="paramShape(step) === 'flip'" class="w-48">
          <FormField :label="t('generator.templates.editor.pixelParam.axis')">
            <Select
              :model-value="axisOf(step)"
              :options="axisOptions"
              size="sm"
              :disabled="submitting"
              :aria-label="t('generator.templates.editor.pixelParam.axis')"
              @update:model-value="(v) => setAxis(index, step, String(v))"
            />
          </FormField>
        </div>

        <!-- ai_edit prompt (authored like the body — slots allowed) -->
        <div v-else-if="step.kind === 'ai_edit'">
          <FormField :label="t('generator.templates.editor.imagePlan.aiEditPrompt')">
            <MarkdownEditor
              :model-value="promptOf(step)"
              min-height="4rem"
              :variables="variables"
              :if-blocks="ifBlocks"
              :ai-text="aiText"
              :disabled="submitting"
              :placeholder="t('generator.templates.editor.imagePlan.aiEditPlaceholder')"
              :aria-label="t('generator.templates.editor.imagePlan.aiEditPrompt')"
              @update:model-value="(v) => setPrompt(index, step, v)"
            />
          </FormField>
        </div>
      </li>
    </ol>

    <!-- add controls -->
    <div class="flex flex-wrap items-center gap-next-2">
      <div class="w-44">
        <Select
          :model-value="opToAdd"
          :options="pixelOpOptions"
          size="sm"
          :disabled="submitting"
          :aria-label="t('generator.templates.editor.imagePlan.addPixel')"
          @update:model-value="(v) => (opToAdd = v as PixelOp)"
        />
      </div>
      <Button variant="outline" size="sm" type="button" leading-icon="plus" :disabled="submitting" @click="addPixel">
        {{ t('generator.templates.editor.imagePlan.addPixel') }}
      </Button>
      <Button variant="ghost" size="sm" type="button" leading-icon="sparkles" :disabled="submitting" @click="addAiEdit">
        {{ t('generator.templates.editor.imagePlan.addAiEdit') }}
      </Button>
    </div>
  </div>
</template>
