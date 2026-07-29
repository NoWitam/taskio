<script setup lang="ts">
// TemplateStoryboardPart — the authoring UI for a `storyboard` part (video_script Phase B). A storyboard
// has NO base — the base is the auto `ai_generate` per shot (the authored STYLE + the shot's on-screen
// `visual`), so an author only supplies:
//   • an OPTIONAL STYLE prompt — a body-like markdown prompt editor (slots / if-blocks / `@[ai-text]`),
//     prepended to every shot's image prompt (bound to `style.markdown`, identical to the ai_generate /
//     ai_edit prompt editor), and
//   • an OPTIONAL FILTER chain — the SAME chain authoring an image_plan uses (the shared TemplateFilterChain),
//     applied to every shot's generated image, and
//   • an OPTIONAL SHOT CAP (`max_shots`) — how many frames this recipe may produce at most. It is the one
//     authored number that bounds BOTH the shot list the model writes and the per-shot image fan-out, so it
//     is also the real cost lever; the platform ceiling is the upper bound an author may only ever tighten.
// The model maps 1:1 to the backend wire `{style?:{markdown}, filters, max_shots?}`.
import { computed } from 'vue';
import FormField from '../../ui/forms/FormField.vue';
import NumberInput from '../../ui/forms/NumberInput.vue';
import MarkdownEditor from '../../ui/editor/MarkdownEditor.vue';
import TemplateFilterChain from './TemplateFilterChain.vue';
import { emptyBody } from './templateContent';
import { useI18n } from '../../app/i18n';
import {
  STORYBOARD_MAX_SHOTS,
  STORYBOARD_MIN_SHOTS,
  type ImageFilterStep,
  type StoryboardContent,
} from './types';
import type {
  AiTextFeatureConfig,
  IfBlockFeatureConfig,
  VariableFeatureConfig,
} from '../../ui/editor/extensions/types';

const props = withDefaults(
  defineProps<{
    /** The part's `{style?, filters}` content (v-model). */
    modelValue: StoryboardContent | null;
    /** The declared FILE-typed slot names (unused by the storyboard base-less chain, kept for parity). */
    fileSlots?: string[];
    /** The shared editor variable feature (slots + globals + functions), built live from the catalog. */
    variables: VariableFeatureConfig;
    /** The ai-text feature (personas). */
    aiText: AiTextFeatureConfig;
    /** The if-block feature. */
    ifBlocks?: IfBlockFeatureConfig;
    submitting?: boolean;
  }>(),
  { modelValue: null, fileSlots: () => [], submitting: false },
);

const emit = defineEmits<{ 'update:modelValue': [StoryboardContent] }>();

const { t } = useI18n();

const board = computed<StoryboardContent>(() => props.modelValue ?? { style: emptyBody(), filters: [] });

/**
 * The current cap as the NumberInput's model: a usable whole number, else `null` (= the field is empty and
 * the run falls back to the platform ceiling). A malformed stored value reads as empty rather than throwing
 * a nonsense number at the author.
 */
const maxShots = computed<number | null>(() => {
  const value = board.value.max_shots;
  return typeof value === 'number' && Number.isFinite(value) ? value : null;
});

/**
 * Rebuild the wire object. `max_shots` is carried through ONLY when it is set — the key must be ABSENT, not
 * `null` and never `0`, when unauthored (the backend validator rejects a non-integer / out-of-range value,
 * so a `0` here would be a 422 on save).
 */
function base(): StoryboardContent {
  const out: StoryboardContent = { style: board.value.style, filters: board.value.filters };
  if (maxShots.value !== null) out.max_shots = maxShots.value;

  return out;
}

function update(next: Partial<StoryboardContent>): void {
  emit('update:modelValue', { ...base(), ...next });
}

/** The optional style prompt markdown (empty when absent). */
const styleMarkdown = computed<string>(() => board.value.style?.markdown ?? '');
function setStyle(markdown: string): void {
  update({ style: { markdown } });
}
function setFilters(filters: ImageFilterStep[]): void {
  update({ filters });
}

/**
 * Set or CLEAR the shot cap. Clearing emits the object WITHOUT the key (never `0`), which is why this can't
 * go through {@link update} — a `Partial` spread cannot delete a key. A typed value is rounded and clamped
 * into [1, ceiling] client-side so the field can never hold a value the server would reject; the server
 * remains authoritative (its live ceiling may be lower than the mirrored constant).
 */
function setMaxShots(value: number | null): void {
  const next: StoryboardContent = { style: board.value.style, filters: board.value.filters };

  if (value !== null && Number.isFinite(value)) {
    next.max_shots = Math.min(STORYBOARD_MAX_SHOTS, Math.max(STORYBOARD_MIN_SHOTS, Math.round(value)));
  }

  emit('update:modelValue', next);
}
</script>

<template>
  <div class="flex flex-col gap-next-4">
    <p class="text-next-xs text-next-muted-foreground">{{ t('generator.templates.editor.storyboard.help') }}</p>

    <!-- Optional STYLE prompt (prepended to every shot's image prompt). -->
    <FormField :label="t('generator.templates.editor.storyboard.styleLabel')">
      <MarkdownEditor
        :model-value="styleMarkdown"
        min-height="4rem"
        :variables="variables"
        :if-blocks="ifBlocks"
        :ai-text="aiText"
        :disabled="submitting"
        :placeholder="t('generator.templates.editor.storyboard.stylePlaceholder')"
        :aria-label="t('generator.templates.editor.storyboard.styleLabel')"
        @update:model-value="setStyle"
      />
    </FormField>

    <!-- Optional SHOT CAP: bounds both the written shot list and the per-shot image fan-out (the cost lever).
         Empty = the platform ceiling; clearing sends the key ABSENT, never 0. -->
    <FormField
      :label="t('generator.templates.editor.storyboard.maxShots')"
      :description="t('generator.templates.editor.storyboard.maxShotsHelp')"
      :disabled="submitting"
    >
      <NumberInput
        class="max-w-[10rem]"
        :model-value="maxShots"
        :min="STORYBOARD_MIN_SHOTS"
        :max="STORYBOARD_MAX_SHOTS"
        :step="1"
        size="sm"
        :placeholder="t('generator.templates.editor.storyboard.maxShotsPlaceholder', '', { max: STORYBOARD_MAX_SHOTS })"
        @update:model-value="setMaxShots"
      />
    </FormField>

    <!-- Optional FILTER chain (applied to every shot image) — shared with image_plan. -->
    <TemplateFilterChain
      :model-value="board.filters"
      :variables="variables"
      :if-blocks="ifBlocks"
      :ai-text="aiText"
      :submitting="submitting"
      @update:model-value="setFilters"
    />
  </div>
</template>
