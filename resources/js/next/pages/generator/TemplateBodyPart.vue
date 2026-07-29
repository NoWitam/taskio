<script setup lang="ts">
// TemplateBodyPart — a text_body / script PART authored AS THE POST. It is the SHARED
// `ui/editor/MarkdownEditor` fed the template catalog (slots + globals + functions + if-blocks) with the
// `@[ai-text]` block PROMOTED to a first-class affordance:
//   • "Fragment AI"        inserts one inline `@[ai-text]` block (instruction + slots + persona) at the cursor,
//   • "Cały post przez AI" inserts one big `@[ai-text]` block = the whole post delegated to AI.
// Both REUSE the existing chip command (`insertAiText`, exposed by the editor) — the chip is NOT forked.
// The part's value is stored as `{markdown}`; this component maps the editor's markdown string ↔ that shape.
import { computed, ref } from 'vue';
import MarkdownEditor from '../../ui/editor/MarkdownEditor.vue';
import Button from '../../ui/primitives/Button.vue';
import { bodyMarkdown, makeBody } from './templateContent';
import { useI18n } from '../../app/i18n';
import type { BodyContent } from './types';
import type {
  AiTextFeatureConfig,
  IfBlockFeatureConfig,
  VariableFeatureConfig,
} from '../../ui/editor/extensions/types';

const props = withDefaults(
  defineProps<{
    /** The part's `{markdown}` content (v-model). */
    modelValue: BodyContent | null;
    /** The shared editor variable feature (slots + globals + functions), built live from the catalog. */
    variables: VariableFeatureConfig;
    /** The ai-text feature (personas). */
    aiText: AiTextFeatureConfig;
    /** The if-block feature. */
    ifBlocks?: IfBlockFeatureConfig;
    submitting?: boolean;
    placeholder?: string;
    ariaLabel?: string;
  }>(),
  { modelValue: null, submitting: false },
);

const emit = defineEmits<{ 'update:modelValue': [BodyContent] }>();

const { t } = useI18n();

/** The MarkdownEditor instance (exposes `editor` so we can drive the shared `insertAiText` command). */
const editorRef = ref<InstanceType<typeof MarkdownEditor> | null>(null);

const markdown = computed<string>({
  get: () => bodyMarkdown(props.modelValue),
  set: (value) => emit('update:modelValue', makeBody(value)),
});

/** Insert the shared AI-text chip at the cursor (the promoted first-class block). */
function insertAiBlock(): void {
  const editor = editorRef.value?.editor;
  editor?.chain().focus().insertAiText().run();
}
</script>

<template>
  <div class="flex flex-col gap-next-2">
    <!-- First-class AI-block affordances (reuse the shared chip command). -->
    <div class="flex flex-wrap items-center gap-next-2">
      <Button
        variant="outline"
        size="xs"
        type="button"
        leading-icon="sparkles"
        :disabled="submitting"
        @click="insertAiBlock"
      >
        {{ t('generator.templates.editor.aiFragment') }}
      </Button>
      <Button
        variant="ghost"
        size="xs"
        type="button"
        leading-icon="sparkles"
        :disabled="submitting"
        @click="insertAiBlock"
      >
        {{ t('generator.templates.editor.aiWholePost') }}
      </Button>
      <span class="text-next-xs text-next-muted-foreground">{{ t('generator.templates.editor.aiBlockHint') }}</span>
    </div>

    <MarkdownEditor
      ref="editorRef"
      v-model="markdown"
      min-height="10rem"
      :variables="variables"
      :if-blocks="ifBlocks"
      :ai-text="aiText"
      :disabled="submitting"
      :placeholder="placeholder"
      :aria-label="ariaLabel"
    />
  </div>
</template>
