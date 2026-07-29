<script setup lang="ts">
// TemplateScenePlanPart — LEAN v1 authoring of a `scene_plan` part (D4): an ORDERED list of scenes, each
// = a NARRATION (a body-like markdown editor, REUSES TemplateBodyPart) + an OPTIONAL nested IMAGE PLAN
// (REUSES TemplateImagePlanPart). Scenes can be added / removed / reordered; a scene's image is opt-in.
// Modest by design — the shape is reserved now; polish lands later. The model maps 1:1 to the wire
// `{scenes:[{narration:{markdown}, image_plan?}]}`.
import { computed } from 'vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Switch from '../../ui/forms/Switch.vue';
import TemplateBodyPart from './TemplateBodyPart.vue';
import TemplateImagePlanPart from './TemplateImagePlanPart.vue';
import { emptyBody } from './templateContent';
import { emptyImagePlan } from './imagePlan';
import { useI18n } from '../../app/i18n';
import type { BodyContent, ImagePlanContent, Scene, ScenePlanContent } from './types';
import type {
  AiTextFeatureConfig,
  IfBlockFeatureConfig,
  VariableFeatureConfig,
} from '../../ui/editor/extensions/types';

const props = withDefaults(
  defineProps<{
    modelValue: ScenePlanContent | null;
    variables: VariableFeatureConfig;
    aiText: AiTextFeatureConfig;
    ifBlocks?: IfBlockFeatureConfig;
    fileSlots: string[];
    submitting?: boolean;
  }>(),
  { modelValue: null, submitting: false },
);

const emit = defineEmits<{ 'update:modelValue': [ScenePlanContent] }>();

const { t } = useI18n();

const scenes = computed<Scene[]>(() => props.modelValue?.scenes ?? []);

function update(next: Scene[]): void {
  emit('update:modelValue', { scenes: next });
}
function patchScene(index: number, next: Partial<Scene>): void {
  update(scenes.value.map((scene, i) => (i === index ? { ...scene, ...next } : scene)));
}

function addScene(): void {
  update([...scenes.value, { narration: emptyBody() }]);
}
function removeScene(index: number): void {
  update(scenes.value.filter((_, i) => i !== index));
}
function move(index: number, dir: -1 | 1): void {
  const target = index + dir;
  if (target < 0 || target >= scenes.value.length) return;
  const next = [...scenes.value];
  [next[index], next[target]] = [next[target], next[index]];
  update(next);
}

function setNarration(index: number, narration: BodyContent): void {
  patchScene(index, { narration });
}
function toggleImage(index: number, on: boolean): void {
  patchScene(index, { image_plan: on ? emptyImagePlan() : null });
}
function setImage(index: number, image: ImagePlanContent): void {
  patchScene(index, { image_plan: image });
}
function hasImage(scene: Scene): boolean {
  return scene.image_plan != null;
}
</script>

<template>
  <div class="flex flex-col gap-next-3">
    <ol v-if="scenes.length > 0" class="flex flex-col gap-next-3">
      <li
        v-for="(scene, index) in scenes"
        :key="index"
        class="flex flex-col gap-next-3 rounded-next-lg border border-next-border p-next-3"
      >
        <div class="flex items-center gap-next-2">
          <span class="text-next-sm font-next-medium text-next-fg">
            {{ t('generator.templates.editor.scene.title', '', { n: index + 1 }) }}
          </span>
          <span class="flex-1" />
          <Button variant="ghost" size="icon-xs" type="button" :disabled="submitting || index === 0" :aria-label="t('generator.templates.editor.scene.moveUp')" @click="move(index, -1)">
            <Icon name="chevron-up" />
          </Button>
          <Button variant="ghost" size="icon-xs" type="button" :disabled="submitting || index === scenes.length - 1" :aria-label="t('generator.templates.editor.scene.moveDown')" @click="move(index, 1)">
            <Icon name="chevron-down" />
          </Button>
          <Button variant="ghost" size="icon-xs" type="button" :disabled="submitting" :aria-label="t('generator.templates.editor.scene.remove')" @click="removeScene(index)">
            <Icon name="trash" />
          </Button>
        </div>

        <!-- Narration (body-like) -->
        <div class="flex flex-col gap-next-1">
          <span class="text-next-xs font-next-medium text-next-muted-foreground">{{ t('generator.templates.editor.scene.narration') }}</span>
          <TemplateBodyPart
            :model-value="scene.narration"
            :variables="variables"
            :ai-text="aiText"
            :if-blocks="ifBlocks"
            :submitting="submitting"
            :placeholder="t('generator.templates.editor.scene.narrationPlaceholder')"
            :aria-label="t('generator.templates.editor.scene.narration')"
            @update:model-value="(v) => setNarration(index, v)"
          />
        </div>

        <!-- Optional nested image plan -->
        <div class="flex flex-col gap-next-2">
          <Switch
            :model-value="hasImage(scene)"
            size="sm"
            :disabled="submitting"
            :label="t('generator.templates.editor.scene.addImage')"
            @update:model-value="(v) => toggleImage(index, v)"
          />
          <TemplateImagePlanPart
            v-if="hasImage(scene)"
            :model-value="scene.image_plan ?? null"
            :file-slots="fileSlots"
            :variables="variables"
            :ai-text="aiText"
            :if-blocks="ifBlocks"
            :submitting="submitting"
            @update:model-value="(v) => setImage(index, v)"
          />
        </div>
      </li>
    </ol>

    <p v-else class="text-next-xs text-next-muted-foreground">{{ t('generator.templates.editor.scene.empty') }}</p>

    <div>
      <Button variant="outline" size="sm" type="button" leading-icon="plus" :disabled="submitting" @click="addScene">
        {{ t('generator.templates.editor.scene.add') }}
      </Button>
    </div>
  </div>
</template>
