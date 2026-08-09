<script setup lang="ts">
// FinalPostBody — the assembled "Gotowy post" artifact + the Save-to-Disk action, as ONE Card.
//
// This is the single source of truth for "which version of the post is current, and how do I save it":
// it renders every produced part's authoritative CURRENT version (text → heading + MarkdownViewer;
// image → the produced image via `SessionPartImage`; scene → each narration + its produced image). The
// SAME component is rendered in the docked FinalPostPane (≥ next-xl) AND as the pinned in-conversation
// "Gotowy post" turn (below next-xl), so the two are byte-identical (owner decision #1).
//
// Save-to-Disk (R2 sub-stage 2c) promotes a PRODUCED image to the Disk. Only images are savable (the
// backend re-reads the produced PNG); a text-only post has nothing to save, so the split button disables.
// The primary segment saves the first produced image; when there are several, the menu lists each. The
// request bubbles UP (`save`) — the page owns the ONE save dialog.
import { computed } from 'vue';
import Card from '../../../ui/layout/Card.vue';
import Button, { type ButtonMenuItem } from '../../../ui/primitives/Button.vue';
import Icon from '../../../ui/primitives/Icon.vue';
import Skeleton from '../../../ui/data/Skeleton.vue';
import MarkdownViewer from '../../../ui/editor/MarkdownViewer.vue';
import SessionPartImage from './SessionPartImage.vue';
import { SESSION_CAPABILITIES } from './sessionGating';
import { collectSavableImages, pngFileName, type SaveImageRequest, type SavableImage } from './sessionImages';
import { useI18n } from '../../../app/i18n';
import type { ContentTypePart } from '../types';
import type {
  SessionPartResult,
  SessionResults,
  SessionScene,
  SessionStatus,
  StoryboardShot,
} from '../sessionTypes';

const props = defineProps<{
  parts: ContentTypePart[];
  results: SessionResults | null;
  status: SessionStatus;
  /** The owning session id — the serve endpoint + save request address it. */
  sessionId: string;
  /** The session name — seeds the default file name of a Save-to-Disk. */
  sessionName?: string;
  /**
   * The session draws its images from a frozen CHARACTER likeness. In this compact artifact the marker
   * is a text suffix on the shot heading ("Ujęcie 3 · z postacią"), not a badge — the assembled post is
   * a reading surface, and a row of chips would compete with the content it is presenting.
   */
  hasCharacterImage?: boolean;
}>();

const emit = defineEmits<{ (e: 'save', request: SaveImageRequest): void }>();

const { t } = useI18n();
const caps = SESSION_CAPABILITIES;

const isReady = computed(() => props.status === 'ready' && props.results != null);

/** The parts that actually produced something, paired with their result, in declared order. */
const blocks = computed(() =>
  props.parts
    .map((part) => ({ part, result: props.results?.[part.key] }))
    .filter((b) => b.result != null),
);

function partLabel(part: ContentTypePart): string {
  return t(`generator.templates.editor.partLabel.${part.kind}`, part.label);
}
function isText(part: ContentTypePart): boolean {
  return part.kind === 'text_body' || part.kind === 'script';
}

/**
 * The produced scenes of a `scene_plan` result. Guarded the same way its sibling `SessionResultCard`
 * guards the same wire fields: a contract field the UI ITERATES is only iterated once it really is an
 * array. A malformed / string payload renders nothing instead of one row per character.
 */
function scenesOf(result: SessionPartResult | undefined): SessionScene[] {
  const scenes = result?.scenes;
  return Array.isArray(scenes) ? scenes : [];
}

/**
 * The produced shots of a `storyboard` result. `shots` is a union on the wire (`ShotListShot[]` for a
 * shot_list, `StoryboardShot[]` for a storyboard) — the CALLER's part kind is what narrows it, exactly
 * as in `SessionResultCard`; the array check is the runtime half of that narrowing.
 */
function storyboardShotsOf(result: SessionPartResult | undefined): StoryboardShot[] {
  const shots = result?.shots;
  return Array.isArray(shots) ? (shots as StoryboardShot[]) : [];
}

/** "Ujęcie 3" — plus "· z postacią" when this beat is drawn from the session's frozen character. */
function shotHeading(shot: StoryboardShot): string {
  const title = t('generator.sessions.result.shot', '', { n: shot.index + 1 });
  const features = props.hasCharacterImage === true && shot.features_character === true;
  return features ? `${title} · ${t('generator.sessions.character.shotSuffix')}` : title;
}

// --- Save-to-Disk (2c) ------------------------------------------------------
const savableImages = computed<SavableImage[]>(() =>
  collectSavableImages(props.parts, props.results, partLabel),
);
const hasSavable = computed(() => savableImages.value.length > 0);
const canSave = computed(() => caps.saveToDisk && hasSavable.value);

/** Default file name for a savable image, seeded from the session name. */
function nameFor(image: SavableImage): string {
  const base = props.sessionName ? `${props.sessionName} — ${image.label}` : image.label;
  return pngFileName(base);
}
function requestSave(image: SavableImage | undefined): void {
  if (!caps.saveToDisk || !image) return;
  emit('save', { partKey: image.partKey, name: nameFor(image) });
}

/** When there is more than one produced image, the split menu lets the user pick which to save. */
const saveMenu = computed<ButtonMenuItem[]>(() =>
  savableImages.value.length > 1
    ? savableImages.value.map((image, index) => ({
        value: String(index),
        label: t('generator.sessions.result.saveImageNamed', '', { label: image.label }),
        icon: 'folder',
      }))
    : [],
);
</script>

<template>
  <Card variant="default" class="min-h-0">
    <template #header>
      <div class="flex min-w-0 flex-1 items-center gap-next-2">
        <Icon name="check-circle" class="shrink-0 text-next-primary" aria-hidden="true" />
        <h3 class="min-w-0 truncate text-next-sm font-next-semibold text-next-fg">
          {{ t('generator.sessions.final.title') }}
        </h3>
      </div>
    </template>

    <!-- Empty: not generated yet. -->
    <div v-if="!isReady" class="flex flex-col items-center gap-next-2 py-next-6 text-center">
      <Icon name="sparkles" class="text-next-2xl text-next-muted-foreground" aria-hidden="true" />
      <p class="text-next-sm text-next-muted-foreground">{{ t('generator.sessions.final.empty') }}</p>
    </div>

    <!-- Assembled parts (current version of each). -->
    <div v-else class="flex flex-col gap-next-4">
      <section v-for="block in blocks" :key="block.part.key" class="flex flex-col gap-next-2">
        <h4 class="text-next-2xs font-next-semibold uppercase tracking-next-wide text-next-muted-foreground">
          {{ partLabel(block.part) }}
        </h4>

        <!-- text -->
        <template v-if="isText(block.part)">
          <MarkdownViewer
            v-if="block.result?.status === 'ok'"
            :source="block.result?.text"
            :aria-label="partLabel(block.part)"
          />
          <p v-else-if="block.result?.status === 'failed'" class="text-next-sm text-next-danger">
            {{ block.result?.error || t('generator.sessions.result.failed') }}
          </p>
        </template>

        <!-- image -->
        <template v-else-if="block.part.kind === 'image_plan'">
          <SessionPartImage
            v-if="block.result?.status === 'ok' && block.result?.image"
            :session-id="sessionId"
            :part-key="block.part.key"
            :image="block.result.image"
            :alt="t('generator.sessions.result.imageAlt', '', { label: partLabel(block.part) })"
          />
          <p v-else-if="block.result?.status === 'failed'" class="text-next-sm text-next-danger">
            {{ block.result?.error || t('generator.sessions.result.imageFailed') }}
          </p>
          <div
            v-else
            class="flex items-center gap-next-2 rounded-next-md bg-next-muted/40 px-next-3 py-next-2 text-next-xs text-next-muted-foreground"
          >
            <Icon name="image" class="shrink-0" aria-hidden="true" />
            {{ t('generator.sessions.result.deferred') }}
          </div>
        </template>

        <!-- scene -->
        <template v-else-if="block.part.kind === 'scene_plan'">
          <div
            v-for="(scene, index) in scenesOf(block.result)"
            :key="index"
            class="flex flex-col gap-next-1_5 rounded-next-md border border-next-border bg-next-card p-next-3"
          >
            <span class="text-next-2xs font-next-medium text-next-muted-foreground">
              {{ t('generator.templates.editor.scene.title', '', { n: index + 1 }) }}
            </span>
            <MarkdownViewer :source="scene.narration" :aria-label="t('generator.templates.editor.scene.narration')" />
            <SessionPartImage
              v-if="scene.image_status === 'ok' && scene.part_key"
              :session-id="sessionId"
              :part-key="scene.part_key"
              :image="scene.image"
              :alt="t('generator.sessions.result.sceneImageAlt', '', { n: index + 1 })"
            />
            <p v-else-if="scene.image_status === 'failed'" class="text-next-sm text-next-danger">
              {{ scene.image_error || t('generator.sessions.result.imageFailed') }}
            </p>
          </div>
        </template>

        <!-- shot_list — the flattened readable script (hook / shots / cta). -->
        <template v-else-if="block.part.kind === 'shot_list'">
          <MarkdownViewer
            v-if="block.result?.status === 'ok'"
            :source="block.result?.text"
            :aria-label="partLabel(block.part)"
          />
          <p v-else-if="block.result?.status === 'failed'" class="text-next-sm text-next-danger">
            {{ block.result?.error || t('generator.sessions.result.failed') }}
          </p>
        </template>

        <!-- storyboard — one produced image per shot. -->
        <template v-else-if="block.part.kind === 'storyboard'">
          <div
            v-for="(shot, index) in storyboardShotsOf(block.result)"
            :key="index"
            class="flex flex-col gap-next-1_5 rounded-next-md border border-next-border bg-next-card p-next-3"
          >
            <span class="text-next-2xs font-next-medium text-next-muted-foreground">
              {{ shotHeading(shot) }}
            </span>
            <p v-if="shot.visual" class="text-next-xs text-next-muted-foreground">{{ shot.visual }}</p>
            <SessionPartImage
              v-if="shot.image_status === 'ok' && shot.part_key"
              :session-id="sessionId"
              :part-key="shot.part_key"
              :image="shot.image"
              :alt="t('generator.sessions.result.shotImageAlt', '', { n: shot.index + 1 })"
            />
            <!-- A storyboard frame still being rendered by its own queue job (a reload mid-run reads
                 the session with partial results) — a placeholder of the same geometry, not a hole. -->
            <div
              v-else-if="shot.image_status === 'pending' || shot.image_status === 'rendering'"
              class="flex flex-col items-center gap-next-1 overflow-hidden rounded-next-lg border border-next-border bg-next-muted/40 p-next-1"
              data-test="final-frame-pending"
              role="status"
            >
              <Skeleton variant="rect" width="100%" height="10rem" radius="md" />
              <span class="pb-next-1 text-next-xs text-next-muted-foreground">
                {{ t('generator.sessions.result.framePending') }}
              </span>
            </div>
            <p v-else-if="shot.image_status === 'failed'" class="text-next-sm text-next-danger">
              {{ shot.image_error || t('generator.sessions.result.imageFailed') }}
            </p>
          </div>
        </template>
      </section>
    </div>

    <template v-if="isReady" #footer>
      <div class="flex w-full flex-col gap-next-3">
        <div class="flex items-start gap-next-2 rounded-next-md bg-next-muted/60 px-next-3 py-next-2 text-next-xs text-next-muted-foreground">
          <Icon name="info" class="mt-px shrink-0" aria-hidden="true" />
          <span>{{ hasSavable ? t('generator.sessions.final.saveHint') : t('generator.sessions.final.saveHintNone') }}</span>
        </div>
        <Button
          variant="primary"
          full-width
          leading-icon="folder"
          :disabled="!canSave"
          :menu-items="saveMenu"
          :menu-aria-label="t('generator.sessions.result.saveToDisk')"
          @click="requestSave(savableImages[0])"
          @menu-select="(value) => requestSave(savableImages[Number(value)])"
        >
          {{ t('generator.sessions.result.saveToDisk') }}
        </Button>
      </div>
    </template>
  </Card>
</template>
