<script setup lang="ts">
// TemplateShotListPart — the authoring UI for a `shot_list` part (video_script Phase B). A shot_list is
// authored as ONE creative BRIEF: a text_body-like markdown body (static text + slot values + `parts.*` +
// if-blocks + the first-class `@[ai-text]` block) that STEERS the AI shot-list generation (topic, tone,
// length, audience). There is NO structured-field authoring — the STRUCTURE (hook / shots / cta) is baked
// server-side. The BRIEF editor is IDENTICAL to TemplateBodyPart (same editor feature config), so it is
// reused verbatim; this component only maps the editor's `{markdown}` ↔ the part's `{brief:{markdown}}`.
import { computed } from 'vue';
import TemplateBodyPart from './TemplateBodyPart.vue';
import { emptyBody } from './templateContent';
import { useI18n } from '../../app/i18n';
import type { BodyContent, ShotListContent } from './types';
import type {
  AiTextFeatureConfig,
  IfBlockFeatureConfig,
  VariableFeatureConfig,
} from '../../ui/editor/extensions/types';

const props = withDefaults(
  defineProps<{
    /** The part's `{brief:{markdown}}` content (v-model). */
    modelValue: ShotListContent | null;
    /** The shared editor variable feature (slots + globals + functions), built live from the catalog. */
    variables: VariableFeatureConfig;
    /** The ai-text feature (personas). */
    aiText: AiTextFeatureConfig;
    /** The if-block feature. */
    ifBlocks?: IfBlockFeatureConfig;
    submitting?: boolean;
  }>(),
  { modelValue: null, submitting: false },
);

const emit = defineEmits<{ 'update:modelValue': [ShotListContent] }>();

const { t } = useI18n();

/** Map the editor's `{markdown}` body ↔ the part's `{brief:{markdown}}`. */
const brief = computed<BodyContent>(() => props.modelValue?.brief ?? emptyBody());
function setBrief(value: BodyContent): void {
  emit('update:modelValue', { brief: value });
}
</script>

<template>
  <div class="flex flex-col gap-next-2">
    <p class="text-next-xs text-next-muted-foreground">{{ t('generator.templates.editor.shotList.briefHelp') }}</p>
    <TemplateBodyPart
      :model-value="brief"
      :variables="variables"
      :ai-text="aiText"
      :if-blocks="ifBlocks"
      :submitting="submitting"
      :placeholder="t('generator.templates.editor.shotList.briefPlaceholder')"
      :aria-label="t('generator.templates.editor.shotList.briefLabel')"
      @update:model-value="setBrief"
    />
  </div>
</template>
