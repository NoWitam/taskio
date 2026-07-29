<script setup lang="ts">
// TemplatePartPreview — renders ONE part's server preview result, BY KIND (D8):
//   • text_body / script  the resolved markdown through the shared MarkdownViewer (inert `[AI: …]`
//                         placeholders are already in the server text),
//   • image_plan          a PLAN card (base + ordered chain) via ImagePlanPreviewCard,
//   • scene_plan          a per-scene summary (resolved narration + the scene's optional image plan).
// The FE never re-implements interpolation — this only shapes the server's per-part output.
import { computed } from 'vue';
import MarkdownViewer from '../../ui/editor/MarkdownViewer.vue';
import Icon from '../../ui/primitives/Icon.vue';
import ImagePlanPreviewCard from './ImagePlanPreviewCard.vue';
import { partKindIcon } from './templateMeta';
import { useI18n } from '../../app/i18n';
import type {
  ContentTypePart,
  ImagePlanPreview,
  PartPreview,
  ScenePlanPreview,
  ShotListPartPreview,
  StoryboardPreview,
  TextPartPreview,
} from './types';

const props = defineProps<{
  part: ContentTypePart;
  result: PartPreview | undefined;
}>();

const { t } = useI18n();

const rendered = computed(() => (props.result as TextPartPreview | undefined)?.rendered ?? '');
const imagePlan = computed(() => (props.result as ImagePlanPreview | undefined)?.plan ?? null);
const scenes = computed(() => (props.result as ScenePlanPreview | undefined)?.plan?.scenes ?? []);
const brief = computed(() => (props.result as ShotListPartPreview | undefined)?.brief ?? '');
const storyboard = computed(() => (props.result as StoryboardPreview | undefined)?.plan ?? null);
</script>

<template>
  <section class="flex flex-col gap-next-2">
    <div class="flex items-center gap-next-2">
      <Icon :name="partKindIcon(part.kind)" class="text-next-muted-foreground" aria-hidden="true" />
      <span class="text-next-xs font-next-medium text-next-muted-foreground">
        {{ t(`generator.templates.editor.partLabel.${part.kind}`, part.label) }}
      </span>
    </div>

    <!-- text_body / script -->
    <div
      v-if="part.kind === 'text_body' || part.kind === 'script'"
      class="rounded-next-lg border border-next-border bg-next-card p-next-4"
    >
      <MarkdownViewer :source="rendered" :aria-label="part.label" />
    </div>

    <!-- image_plan -->
    <ImagePlanPreviewCard v-else-if="part.kind === 'image_plan'" :plan="imagePlan" />

    <!-- scene_plan -->
    <div v-else-if="part.kind === 'scene_plan'" class="flex flex-col gap-next-2">
      <div
        v-for="(scene, index) in scenes"
        :key="index"
        class="flex flex-col gap-next-2 rounded-next-lg border border-next-border bg-next-card p-next-3"
      >
        <span class="text-next-xs font-next-medium text-next-muted-foreground">
          {{ t('generator.templates.editor.scene.title', '', { n: index + 1 }) }}
        </span>
        <MarkdownViewer :source="scene.narration" :aria-label="t('generator.templates.editor.scene.narration')" />
        <ImagePlanPreviewCard v-if="scene.image" :plan="scene.image" />
      </div>
      <p v-if="scenes.length === 0" class="text-next-xs text-next-muted-foreground">
        {{ t('generator.templates.editor.scene.emptyPreview') }}
      </p>
    </div>

    <!-- shot_list — the resolved creative brief (the shots themselves are produced only in a real run). -->
    <div v-else-if="part.kind === 'shot_list'" class="flex flex-col gap-next-2">
      <div class="rounded-next-lg border border-next-border bg-next-card p-next-4">
        <MarkdownViewer :source="brief" :aria-label="t('generator.templates.editor.shotList.briefLabel')" />
      </div>
      <p class="text-next-xs text-next-muted-foreground">{{ t('generator.templates.editor.shotList.previewNote') }}</p>
    </div>

    <!-- storyboard — the resolved style + the per-shot filter chain (no image executed). -->
    <div v-else-if="part.kind === 'storyboard'" class="flex flex-col gap-next-2 rounded-next-md border border-next-border bg-next-muted/20 p-next-3">
      <div v-if="storyboard?.style" class="flex flex-col gap-next-1">
        <span class="text-next-xs font-next-medium text-next-muted-foreground">{{ t('generator.templates.editor.storyboard.styleLabel') }}</span>
        <span class="truncate text-next-sm text-next-fg">{{ storyboard.style }}</span>
      </div>
      <ol v-if="storyboard && storyboard.filters.length > 0" class="flex flex-col gap-next-1">
        <li
          v-for="(filter, index) in storyboard.filters"
          :key="index"
          class="flex items-center gap-next-1 text-next-xs text-next-muted-foreground"
        >
          <span class="inline-flex h-4 w-4 items-center justify-center rounded-next-full bg-next-muted text-[0.625rem] text-next-fg">{{ index + 1 }}</span>
          <Icon :name="filter.kind === 'ai_edit' ? 'sparkles' : 'image'" class="shrink-0" aria-hidden="true" />
          <span class="truncate">
            <template v-if="filter.kind === 'ai_edit'">
              {{ t('generator.templates.editor.imagePlan.aiEditStep') }}<template v-if="filter.prompt"> — {{ filter.prompt }}</template>
            </template>
            <template v-else>{{ t(`generator.templates.editor.pixelOp.${filter.op}`, filter.label) }}</template>
          </span>
        </li>
      </ol>
      <!-- The authored shot cap, when the recipe tightened it (absent = the platform ceiling applies). -->
      <div
        v-if="storyboard?.max_shots != null"
        class="flex items-center gap-next-1 text-next-xs text-next-muted-foreground"
      >
        <Icon name="film" class="shrink-0" aria-hidden="true" />
        <span>{{ t('generator.templates.editor.storyboard.maxShotsPreview', '', { count: storyboard.max_shots }) }}</span>
      </div>
      <p class="text-next-xs text-next-muted-foreground">{{ t('generator.templates.editor.storyboard.previewNote') }}</p>
    </div>
  </section>
</template>
