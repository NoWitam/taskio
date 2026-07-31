<script setup lang="ts">
// SessionResultCard — renders ONE produced PART as an assistant turn, BY KIND, plus its action row.
//
// Body (mirrors `TemplatePartPreview`'s per-kind switch):
//   • text_body / script  the produced markdown through the shared MarkdownViewer (a `failed` part shows
//                         a danger Alert with the localized error instead — other parts still render).
//   • image_plan          the PRODUCED image (R2 sub-stage 2c) via `SessionPartImage`, a `failed` danger
//                         Alert, or a defensive `deferred` placeholder.
//   • scene_plan          each scene's narration + its optional produced image / per-scene error (legacy).
//   • shot_list           the STRUCTURED script (video_script Phase B): a HOOK block, an ORDERED shots list
//                         (each: "Shot N" + on-screen VISUAL + spoken VOICEOVER + a seconds chip), a CTA
//                         block. `parse_ok:false` → the raw `text` (MarkdownViewer) + a subtle info Alert.
//   • storyboard          a list of SHOT cards (mirrors scene_plan, richer): each shot's produced image via
//                         `SessionPartImage` OR a per-shot danger Alert, plus a PER-SHOT action row —
//                         regenerate / refine / undo / save-to-disk — that emits the SAME events with the
//                         shot's `storyboard.<i>` partKey. Per-shot version + undo gating read
//                         `part_history[storyboard.<i>]`; per-shot busy = the page's in-flight op partKey.
//
// The action row is the compact-row pattern. Since R2 sub-stage 2d the per-part REFINE LOOP is LIVE
// (regenerate / refine / undo). A bare scene_plan / storyboard is NOT top-level refinable (its refine lives
// per shot); both are still whole-part regenerable. A shot_list IS refinable. Every generative affordance
// disables while the session is `generating`. The FE never interpolates — the server produced these parts.
//
// COLLAPSE (owner note #2): the card carries the same show/hide chevron as every other turn card, but starts
// EXPANDED — this is the artifact the user came for. Two rules make the collapse safe:
//   • the body is hidden with `v-show`, NEVER `v-if` — `SessionPartImage` owns an IntersectionObserver and a
//     blob object-URL per image, so unmounting would re-fetch every image on each collapse and would leave
//     `aria-controls` pointing at nothing;
//   • the whole-part action row lives in the card FOOTER and stays visible while collapsed, so collapsing
//     never buries the only entry to refine / regenerate / undo / save-to-Disk. (A storyboard's PER-SHOT
//     rows are inside the body — they are affordances ON a shot, meaningless without the shot in view.)
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import Card from '../../../ui/layout/Card.vue';
import Button from '../../../ui/primitives/Button.vue';
import Badge from '../../../ui/primitives/Badge.vue';
import Icon from '../../../ui/primitives/Icon.vue';
import Tooltip from '../../../ui/overlay/Tooltip.vue';
import Alert from '../../../ui/feedback/Alert.vue';
import Skeleton from '../../../ui/data/Skeleton.vue';
import MarkdownViewer from '../../../ui/editor/MarkdownViewer.vue';
import SessionPartImage from './SessionPartImage.vue';
import { nextId } from '../../../ui/forms/formField';
import { partKindIcon } from '../templateMeta';
import { SESSION_CAPABILITIES } from './sessionGating';
import { pngFileName, type SaveImageRequest } from './sessionImages';
import { useToast } from '../../../app/composables/useToast';
import { useI18n } from '../../../app/i18n';
import type { ContentTypePart } from '../types';
import type {
  PartHistoryEntry,
  SessionPartHistory,
  SessionPartResult,
  ShotListShot,
  StoryboardShot,
} from '../sessionTypes';

const props = defineProps<{
  part: ContentTypePart;
  result: SessionPartResult | undefined;
  /** The owning session id — the serve endpoint + the save request address it. */
  sessionId: string;
  /** The session name — seeds the default file name of a Save-to-Disk. */
  sessionName?: string;
  /** The session is mid-run — every generative affordance is disabled with an explanatory Tooltip. */
  generating?: boolean;
  /**
   * The workspace is over its monthly AI cap — proactively disable the SPEND affordances (regenerate / refine,
   * incl. per-shot) with a budget tooltip. Undo / copy / save-to-Disk are NOT AI spend, so they stay live.
   */
  blocked?: boolean;
  /** THIS part's (top-level) regenerate / refine is the run in flight — its Regenerate button spins. */
  busy?: boolean;
  /** This part's undo state from `session.part_history[partKey]` (absent = no prior version). */
  partHistory?: PartHistoryEntry;
  /**
   * The WHOLE per-part undo map (`session.part_history`) — the storyboard reads `[storyboard.<i>]` entries
   * for per-shot version + undo gating.
   */
  partHistoryMap?: SessionPartHistory;
  /**
   * The page's CURRENT in-flight op partKey (`storyboard.<i>` for a per-shot op) — only the acting shot
   * spins; every other shot's affordances stay disabled (the whole session is `generating` mid-op).
   */
  busyPartKey?: string | null;
  /**
   * This session froze a CHARACTER likeness — its images are drawn from the author's approved face.
   * Gates the per-shot "with character" marker AND the "bot appearance" repair action: without a
   * character, `features_character` is meaningless and the appearance page is not the fix.
   */
  hasCharacterImage?: boolean;
  /** The bot author's id — the "bot appearance" action deep-links into its visual module. */
  botAuthorId?: string | null;
}>();

const emit = defineEmits<{
  (e: 'save', request: SaveImageRequest): void;
  (e: 'regenerate', partKey: string): void;
  (e: 'refine', payload: { partKey: string; instruction: string }): void;
  (e: 'undo', partKey: string): void;
}>();

const { t } = useI18n();
const toast = useToast();
const router = useRouter();

const caps = SESSION_CAPABILITIES;

const isText = computed(() => props.part.kind === 'text_body' || props.part.kind === 'script');
const isImage = computed(() => props.part.kind === 'image_plan');
const isScene = computed(() => props.part.kind === 'scene_plan');
const isShotList = computed(() => props.part.kind === 'shot_list');
const isStoryboard = computed(() => props.part.kind === 'storyboard');

const status = computed(() => props.result?.status ?? 'deferred');
/**
 * A scene_plan / storyboard is NOT top-level refinable (their refine lives per scene / shot); text_body /
 * script / image_plan / shot_list are — but only once PRODUCED (`ok`), since refine revises the CURRENT
 * output (a failed / never-run part has nothing to revise).
 */
const isRefinable = computed(
  () => (isText.value || isImage.value || isShotList.value) && status.value === 'ok',
);
const failed = computed(() => status.value === 'failed');
const text = computed(() => (typeof props.result?.text === 'string' ? props.result.text : ''));
const producedImage = computed(() => props.result?.image ?? null);
const scenes = computed(() => props.result?.scenes ?? []);

// --- shot_list (structured) -------------------------------------------------
const hook = computed(() => (typeof props.result?.hook === 'string' ? props.result.hook : ''));
const cta = computed(() => (typeof props.result?.cta === 'string' ? props.result.cta : ''));
const parseOk = computed(() => props.result?.parse_ok !== false);
const shotListShots = computed<ShotListShot[]>(() =>
  isShotList.value && Array.isArray(props.result?.shots) ? (props.result?.shots as ShotListShot[]) : [],
);

// --- storyboard (per-shot images) -------------------------------------------
const storyboardShots = computed<StoryboardShot[]>(() =>
  isStoryboard.value && Array.isArray(props.result?.shots) ? (props.result?.shots as StoryboardShot[]) : [],
);

/** An image part that ran and produced an image (the branch that renders `SessionPartImage`). */
const hasImage = computed(() => status.value === 'ok' && producedImage.value != null);
/** The Save-to-Disk button is live only for a produced image (a top-level image part). */
const canSaveImage = computed(() => caps.saveToDisk && hasImage.value);

const partLabel = computed(() => t(`generator.templates.editor.partLabel.${props.part.kind}`, props.part.label));
/** The whole card's accessible name ("Element: <label> — wynik"). */
const cardLabel = computed(() => t('generator.sessions.result.aria', '', { label: partLabel.value }));

// --- Stale hint (Phase A cross-part coherence) ------------------------------
const isStale = computed(() => props.result?.stale === true);

// --- Collapse (owner note #2) ------------------------------------------------
// EXPANDED by default: a result is the artifact, not context. The body stays mounted (v-show) — see the
// file header for why that is not optional here.
const expanded = ref(true);
function toggleExpanded(): void {
  expanded.value = !expanded.value;
}
const bodyId = nextId('next-result');

/** First non-blank line of a text, with a leading markdown heading marker dropped. */
function firstLine(source: string): string {
  const line = source.split('\n').map((l) => l.trim()).find((l) => l.length > 0) ?? '';
  return line.replace(/^#{1,6}\s*/, '');
}

/**
 * The collapsed one-line teaser — built ONLY from what the card already holds (no extra request, no image
 * thumbnail): the error, the hook / shot count, the first line of the produced text, or an "image ready"
 * marker. Rendered as plain text, like every other model-derived value in this view.
 */
const collapsedTeaser = computed<string>(() => {
  if (failed.value) return props.result?.error || t('generator.sessions.result.failed');
  if (isShotList.value && parseOk.value) {
    if (hook.value) return hook.value;
    if (shotListShots.value.length) {
      return t('generator.sessions.result.previewShots', '', { count: shotListShots.value.length });
    }
  }
  if (isStoryboard.value) {
    return storyboardShots.value.length
      ? t('generator.sessions.result.previewShots', '', { count: storyboardShots.value.length })
      : t('generator.sessions.result.storyboardEmpty');
  }
  if (isScene.value) {
    const narration = scenes.value[0]?.narration;
    if (typeof narration === 'string' && narration.trim()) return firstLine(narration);
  }
  if (text.value.trim()) return firstLine(text.value);
  if (hasImage.value) return t('generator.sessions.result.previewImage');
  return t('generator.sessions.result.previewEmpty');
});

// --- Version display ("Wersja N") + undo gating (R2 sub-stage 2d) -----------
const version = computed(() => props.result?.version ?? null);
const hasHistory = computed(() => (props.partHistory?.undo_depth ?? 0) > 0);
/** Show the version marker once the part is past its first version OR it has a prior version to undo to. */
const showVersion = computed(() => caps.history && ((version.value ?? 0) > 1 || hasHistory.value));
const versionLabel = computed(() => t('generator.sessions.result.version', '', { n: version.value ?? 1 }));
const canUndo = computed(() => caps.undo && !props.generating && !props.busy && !!props.partHistory?.can_undo);

// --- Live-affordance gating + tooltips --------------------------------------
const busyTooltip = computed(() => t('generator.sessions.busy'));
const blockedTooltip = computed(() => t('generator.sessions.budget.affordanceBlocked'));
const canRegenerate = computed(() => caps.regeneratePart && !props.generating && !props.busy && !props.blocked);
const regenerateLabel = computed(() =>
  isStoryboard.value
    ? t('generator.sessions.result.regenerateStoryboard')
    : failed.value
      ? t('generator.sessions.result.retry')
      : t('generator.sessions.regenerate'),
);
const regenerateTooltip = computed(() =>
  props.generating ? busyTooltip.value : props.blocked ? blockedTooltip.value : regenerateLabel.value,
);
const canRefine = computed(() => caps.composerSend && !props.generating && !props.busy && !props.blocked);
const refineTooltip = computed(() =>
  props.generating ? busyTooltip.value : props.blocked ? blockedTooltip.value : t('generator.sessions.result.refine'),
);
const undoTooltip = computed(() =>
  props.generating
    ? busyTooltip.value
    : props.partHistory?.can_undo
      ? t('generator.sessions.result.undo')
      : t('generator.sessions.result.undoUnavailable'),
);

/** Localized "Scene N" heading. */
function sceneTitle(index: number): string {
  return t('generator.templates.editor.scene.title', '', { n: index + 1 });
}
/** Localized "Shot N" heading (shot_list + storyboard). */
function shotTitle(index: number): string {
  return t('generator.sessions.result.shot', '', { n: index + 1 });
}
/** Build a default file name for a save, seeded from the session name (or the part label). */
function defaultName(labelSuffix: string): string {
  const base = props.sessionName ? `${props.sessionName} — ${labelSuffix}` : labelSuffix;
  return pngFileName(base);
}

// --- Copy (pure client — text_body / script / shot_list flattened text) ------
const copyable = computed(() => (isText.value || isShotList.value) && !!text.value);
async function copyText(): Promise<void> {
  if (!text.value) return;
  try {
    await navigator.clipboard?.writeText(text.value);
    toast.success(t('generator.sessions.result.copied'));
  } catch {
    toast.danger(t('generator.sessions.toasts.error'));
  }
}

// --- Regenerate / Undo (2d) — bubble up; the page claims + polls / reverts ---
function regenerate(): void {
  if (!canRegenerate.value) return;
  emit('regenerate', props.part.key);
}
function undo(): void {
  if (!canUndo.value) return;
  emit('undo', props.part.key);
}

// --- Refine (2d) — a keyed inline composer (top-level part OR a storyboard shot) --------------------
const refineKey = ref<string | null>(null);
const refineDraft = ref('');
/** Whether a given key's op is the one currently in flight (top-level: `busy`; a shot: `busyPartKey`). */
function isBusyFor(key: string): boolean {
  return key === props.part.key ? !!props.busy : props.busyPartKey === key;
}
function canRefineKey(key: string): boolean {
  return caps.composerSend && !props.generating && !isBusyFor(key) && !props.blocked;
}
function toggleRefine(key: string): void {
  if (!canRefineKey(key)) return;
  refineKey.value = refineKey.value === key ? null : key;
  if (refineKey.value === null) refineDraft.value = '';
}
function submitRefine(key: string): void {
  if (!canRefineKey(key)) return;
  const instruction = refineDraft.value.trim();
  if (!instruction) return;
  emit('refine', { partKey: key, instruction });
  refineDraft.value = '';
  refineKey.value = null;
}
function onRefineKeydown(event: KeyboardEvent, key: string): void {
  if (event.key === 'Enter' && !event.shiftKey) {
    event.preventDefault();
    submitRefine(key);
  }
}

// --- Save-to-Disk (2c) — bubble the request up to the page's single dialog ---
function saveImage(): void {
  if (!canSaveImage.value) return;
  emit('save', { partKey: props.part.key, name: defaultName(partLabel.value) });
}
function saveScene(index: number): void {
  const scene = scenes.value[index];
  if (!caps.saveToDisk || scene?.image_status !== 'ok' || !scene.part_key) return;
  emit('save', { partKey: scene.part_key, name: defaultName(sceneTitle(index)) });
}

// --- Storyboard per-shot ops (ride the SAME per-part op contract) ------------
/** A shot's op key (`storyboard.<i>`) — works for ok AND failed shots (a failed shot is regenerable). */
function shotKey(shot: StoryboardShot): string {
  return `${props.part.key}.${shot.index}`;
}
function shotHistory(shot: StoryboardShot): PartHistoryEntry | undefined {
  return props.partHistoryMap?.[shotKey(shot)];
}
function showShotVersion(shot: StoryboardShot): boolean {
  return caps.history && ((shot.image?.version ?? 0) > 1 || (shotHistory(shot)?.undo_depth ?? 0) > 0);
}
function shotVersionLabel(shot: StoryboardShot): string {
  return t('generator.sessions.result.version', '', { n: shot.image?.version ?? 1 });
}
function canShotRegenerate(shot: StoryboardShot): boolean {
  return caps.regeneratePart && !props.generating && !isBusyFor(shotKey(shot)) && !props.blocked;
}
function canShotUndo(shot: StoryboardShot): boolean {
  return caps.undo && !props.generating && !isBusyFor(shotKey(shot)) && !!shotHistory(shot)?.can_undo;
}
function shotUndoTooltip(shot: StoryboardShot): string {
  if (props.generating) return busyTooltip.value;
  return shotHistory(shot)?.can_undo
    ? t('generator.sessions.result.undo')
    : t('generator.sessions.result.undoUnavailable');
}
function regenerateShot(shot: StoryboardShot): void {
  if (!canShotRegenerate(shot)) return;
  emit('regenerate', shotKey(shot));
}
function undoShot(shot: StoryboardShot): void {
  if (!canShotUndo(shot)) return;
  emit('undo', shotKey(shot));
}
function saveShot(shot: StoryboardShot): void {
  if (!caps.saveToDisk || shot.image_status !== 'ok' || !shot.part_key) return;
  emit('save', { partKey: shot.part_key, name: defaultName(shotTitle(shot.index)) });
}

// --- Character signals (R2 sub-stage 3) -------------------------------------
/**
 * Whether a shot is drawn FROM the session's frozen character. Both halves must hold: the session has a
 * likeness at all, and the model flagged this particular beat as showing the creator. Absent = false, so
 * a run without a character (and a shot list written before the flag existed) shows nothing.
 */
function featuresCharacter(shot: { features_character?: boolean }): boolean {
  return props.hasCharacterImage === true && shot.features_character === true;
}

/**
 * A shot whose IMAGE failed while it was supposed to show the character. The extra repair action is
 * gated on the CONTEXT, not on the error text: the provider's wording is not a contract, and a
 * character shot that failed for any reason is still worth checking the appearance for.
 */
function canOpenAppearance(shot: StoryboardShot): boolean {
  return !!props.botAuthorId && featuresCharacter(shot) && shot.image_status === 'failed';
}

/**
 * A failed frame's message. When the server classified the failure as MODERATION we say so in our own
 * words — the generic "the image could not be produced" actively misleads, because the fix is the
 * character's description or wardrobe, not the plan. Absent code → the server's own prose.
 */
function shotImageError(shot: StoryboardShot): string {
  // NOTE the key: per-SHOT codes ride as `image_error_code` (namespaced like `image_error`), while a
  // whole-part failure carries `error_code` — reading the part key here would silently kill the promotion.
  if (shot.image_error_code === 'image_safety') return t('generator.sessions.character.safetyFailed');
  return shot.image_error || t('generator.sessions.result.imageFailed');
}

/** The same promotion for a whole-part failure (an image_plan / storyboard part). */
const partError = computed(() => {
  if (props.result?.error_code === 'image_safety') return t('generator.sessions.character.safetyFailed');
  return props.result?.error || t('generator.sessions.result.imageFailed');
});

/** Deep-link into the bot's "Wygląd" module, where the description + wardrobe live. */
function openAppearance(): void {
  if (!props.botAuthorId) return;
  void router.push({ name: 'next.bots', query: { bot: props.botAuthorId, botModule: 'visual' } });
}
</script>

<template>
  <Card variant="default" :aria-label="cardLabel" role="article" :body-collapsed="!expanded">
    <template #header>
      <div class="flex min-w-0 flex-1 flex-wrap items-center gap-next-2">
        <Icon :name="partKindIcon(part.kind)" class="shrink-0 text-next-muted-foreground" aria-hidden="true" />
        <h3 class="min-w-0 truncate text-next-sm font-next-medium text-next-muted-foreground">{{ partLabel }}</h3>
        <!-- Version marker (never color-only: an icon + text Badge). -->
        <Badge v-if="showVersion" variant="neutral" tone="subtle" size="sm" icon="clock" class="shrink-0">
          {{ versionLabel }}
        </Badge>
        <!-- Stale hint (Phase A): an icon + text Badge, never color alone. -->
        <Tooltip v-if="isStale" :label="t('generator.sessions.result.staleHint')">
          <Badge variant="warning" tone="subtle" size="sm" icon="alert-triangle" class="shrink-0">
            {{ t('generator.sessions.result.stale') }}
          </Badge>
        </Tooltip>
        <!-- Collapsed teaser: one clipped line of what the card is hiding. -->
        <span
          v-if="!expanded"
          class="min-w-0 flex-1 truncate text-next-xs text-next-muted-foreground"
        >
          {{ collapsedTeaser }}
        </span>
      </div>
    </template>

    <template #headerActions>
      <Button
        variant="ghost"
        size="sm"
        :leading-icon="expanded ? 'chevron-up' : 'chevron-down'"
        :aria-expanded="expanded ? 'true' : 'false'"
        :aria-controls="bodyId"
        @click="toggleExpanded"
      >
        {{ expanded ? t('generator.sessions.toggle.collapse') : t('generator.sessions.toggle.expand') }}
      </Button>
    </template>

    <!-- Body by kind. ONE v-show wrapper: hidden, never unmounted (images + aria-controls). -->
    <div v-show="expanded" :id="bodyId">
      <!-- text_body / script -->
      <template v-if="isText">
        <Alert v-if="failed" variant="danger" size="sm">
          {{ result?.error || t('generator.sessions.result.failed') }}
        </Alert>
        <MarkdownViewer v-else :source="text" :aria-label="partLabel" />
      </template>

      <!-- image_plan -->
      <div v-else-if="isImage" class="flex flex-col gap-next-2">
        <!-- A moderation refusal is promoted over the generic prose (see `partError`). -->
        <Alert v-if="failed" variant="danger" size="sm">
          {{ partError }}
        </Alert>
        <SessionPartImage
          v-else-if="hasImage"
          :session-id="sessionId"
          :part-key="part.key"
          :image="producedImage"
          :alt="t('generator.sessions.result.imageAlt', '', { label: partLabel })"
        />
        <!-- Defensive fallback: a `deferred` image should not occur post-2c. -->
        <div
          v-else
          class="flex min-h-[8rem] flex-col items-center justify-center gap-next-2 rounded-next-lg border border-next-border bg-next-muted/40 p-next-6 text-center text-next-muted-foreground"
        >
          <Icon name="image" class="text-next-2xl" aria-hidden="true" />
          <p class="text-next-sm">{{ t('generator.sessions.result.deferred') }}</p>
        </div>
      </div>

      <!-- scene_plan (legacy snapshot) -->
      <div v-else-if="isScene" class="flex flex-col gap-next-2">
        <div
          v-if="scenes.length === 0"
          class="flex min-h-[8rem] flex-col items-center justify-center gap-next-2 rounded-next-lg border border-next-border bg-next-muted/40 p-next-6 text-center text-next-muted-foreground"
        >
          <Icon name="list-ordered" class="text-next-2xl" aria-hidden="true" />
          <p class="text-next-sm">{{ t('generator.sessions.result.deferred') }}</p>
        </div>
        <div
          v-for="(scene, index) in scenes"
          :key="index"
          class="flex flex-col gap-next-2 rounded-next-lg border border-next-border bg-next-card p-next-3"
        >
          <span class="text-next-xs font-next-medium text-next-muted-foreground">{{ sceneTitle(index) }}</span>
          <MarkdownViewer :source="scene.narration" :aria-label="t('generator.templates.editor.scene.narration')" />
          <SessionPartImage
            v-if="scene.image_status === 'ok' && scene.part_key"
            :session-id="sessionId"
            :part-key="scene.part_key"
            :image="scene.image"
            :alt="t('generator.sessions.result.sceneImageAlt', '', { n: index + 1 })"
          />
          <!-- Same moderation promotion as the shot/part arms: a scene image CAN be character-drawn
               (directedImagePlan hands scenes the same reference bytes), so its refusal deserves the
               same actionable wording instead of the generic prose. -->
          <Alert v-else-if="scene.image_status === 'failed'" variant="danger" size="sm">
            {{ scene.image_error_code === 'image_safety'
              ? t('generator.sessions.character.safetyFailed')
              : (scene.image_error || t('generator.sessions.result.imageFailed')) }}
          </Alert>
          <div v-if="scene.image_status === 'ok' && scene.part_key" class="flex">
            <Button
              variant="ghost"
              size="sm"
              leading-icon="folder"
              :disabled="!caps.saveToDisk"
              @click="saveScene(index)"
            >
              {{ t('generator.sessions.result.saveToDisk') }}
            </Button>
          </div>
        </div>
      </div>

      <!-- shot_list (structured script) -->
      <div v-else-if="isShotList" class="flex flex-col gap-next-3">
        <Alert v-if="failed" variant="danger" size="sm">
          {{ result?.error || t('generator.sessions.result.failed') }}
        </Alert>

        <!-- parse_ok:false — the model returned non-JSON; show the raw text + a subtle note. -->
        <template v-else-if="!parseOk">
          <Alert variant="info" size="sm">{{ t('generator.sessions.result.parseFallback') }}</Alert>
          <MarkdownViewer :source="text" :aria-label="partLabel" />
        </template>

        <!-- Structured: HOOK → ordered SHOTS → CTA. -->
        <template v-else>
          <div v-if="hook" class="flex flex-col gap-next-1 rounded-next-lg border border-next-border bg-next-card p-next-3">
            <span class="text-next-2xs font-next-semibold uppercase tracking-next-wide text-next-muted-foreground">
              {{ t('generator.sessions.result.hook') }}
            </span>
            <p class="text-next-sm text-next-fg">{{ hook }}</p>
          </div>

          <ol v-if="shotListShots.length > 0" class="flex flex-col gap-next-2">
            <li
              v-for="(shot, index) in shotListShots"
              :key="index"
              class="flex flex-col gap-next-1_5 rounded-next-lg border border-next-border bg-next-card p-next-3"
            >
              <div class="flex flex-wrap items-center gap-next-2">
                <span class="text-next-xs font-next-semibold text-next-fg">{{ shotTitle(index) }}</span>
                <Badge v-if="shot.seconds > 0" variant="neutral" tone="subtle" size="sm" icon="clock">
                  {{ t('generator.sessions.result.seconds', '', { n: shot.seconds }) }}
                </Badge>
                <!-- The shot list already knows which beats show the creator — say so here too, so the
                     storyboard's markers are not a surprise. -->
                <Badge
                  v-if="featuresCharacter(shot)"
                  variant="neutral"
                  tone="subtle"
                  size="sm"
                  icon="user"
                  :title="t('generator.sessions.character.shotBadgeTitle')"
                >
                  {{ t('generator.sessions.character.shotBadge') }}
                </Badge>
              </div>
              <div v-if="shot.visual" class="flex flex-col gap-next-0_5">
                <span class="text-next-2xs font-next-medium uppercase tracking-next-wide text-next-muted-foreground">
                  {{ t('generator.sessions.result.visual') }}
                </span>
                <p class="text-next-sm text-next-fg">{{ shot.visual }}</p>
              </div>
              <div v-if="shot.voiceover" class="flex flex-col gap-next-0_5">
                <span class="text-next-2xs font-next-medium uppercase tracking-next-wide text-next-muted-foreground">
                  {{ t('generator.sessions.result.voiceover') }}
                </span>
                <p class="text-next-sm text-next-muted-foreground">{{ shot.voiceover }}</p>
              </div>
            </li>
          </ol>

          <div v-if="cta" class="flex flex-col gap-next-1 rounded-next-lg border border-next-border bg-next-card p-next-3">
            <span class="text-next-2xs font-next-semibold uppercase tracking-next-wide text-next-muted-foreground">
              {{ t('generator.sessions.result.cta') }}
            </span>
            <p class="text-next-sm text-next-fg">{{ cta }}</p>
          </div>
        </template>
      </div>

      <!-- storyboard (per-shot images + per-shot ops) -->
      <div v-else-if="isStoryboard" class="flex flex-col gap-next-3">
        <Alert v-if="failed" variant="danger" size="sm">
          {{ partError }}
        </Alert>

        <div
          v-else-if="storyboardShots.length === 0"
          class="flex min-h-[8rem] flex-col items-center justify-center gap-next-2 rounded-next-lg border border-next-border bg-next-muted/40 p-next-6 text-center text-next-muted-foreground"
        >
          <Icon name="layout-dashboard" class="text-next-2xl" aria-hidden="true" />
          <p class="text-next-sm">{{ t('generator.sessions.result.storyboardEmpty') }}</p>
        </div>

        <div
          v-for="shot in storyboardShots"
          v-else
          :key="shot.index"
          class="flex flex-col gap-next-2 rounded-next-lg border border-next-border bg-next-card p-next-3"
        >
          <div class="flex flex-wrap items-center gap-next-2">
            <span class="text-next-xs font-next-semibold text-next-fg">{{ shotTitle(shot.index) }}</span>
            <Badge v-if="shot.seconds > 0" variant="neutral" tone="subtle" size="sm" icon="clock">
              {{ t('generator.sessions.result.seconds', '', { n: shot.seconds }) }}
            </Badge>
            <Badge v-if="showShotVersion(shot)" variant="neutral" tone="subtle" size="sm" icon="clock">
              {{ shotVersionLabel(shot) }}
            </Badge>
            <!-- This beat is drawn from the session's frozen character. -->
            <Badge
              v-if="featuresCharacter(shot)"
              variant="neutral"
              tone="subtle"
              size="sm"
              icon="user"
              :title="t('generator.sessions.character.shotBadgeTitle')"
            >
              {{ t('generator.sessions.character.shotBadge') }}
            </Badge>
          </div>

          <!-- The beat (small, muted). -->
          <p v-if="shot.visual" class="text-next-sm text-next-fg">{{ shot.visual }}</p>
          <p v-if="shot.voiceover" class="text-next-xs text-next-muted-foreground">{{ shot.voiceover }}</p>

          <!-- Produced image, the frame still being rendered, or a per-shot error. The frames of a
               storyboard are rendered by SEPARATE queue jobs, so a session fetched mid-run legitimately
               carries `pending` / `rendering` shots — without this arm they were an empty hole. -->
          <SessionPartImage
            v-if="shot.image_status === 'ok' && shot.part_key"
            :session-id="sessionId"
            :part-key="shot.part_key"
            :image="shot.image"
            :alt="t('generator.sessions.result.shotImageAlt', '', { n: shot.index + 1 })"
          />
          <div
            v-else-if="shot.image_status === 'pending' || shot.image_status === 'rendering'"
            class="flex flex-col items-center justify-center gap-next-2 overflow-hidden rounded-next-lg border border-next-border bg-next-muted/40 p-next-1"
            data-test="shot-frame-pending"
            role="status"
          >
            <Skeleton variant="rect" width="100%" height="12rem" radius="md" />
            <span class="pb-next-1 text-next-xs text-next-muted-foreground">
              {{ t('generator.sessions.result.framePending') }}
            </span>
          </div>
          <Alert v-else-if="shot.image_status === 'failed'" variant="danger" size="sm">
            <div class="flex flex-col gap-next-2">
              <span>{{ shotImageError(shot) }}</span>
              <!-- A character shot that failed: the fix usually lives in the bot's appearance (its
                   description / wardrobe decide whether the provider hands the image over). -->
              <div v-if="canOpenAppearance(shot)">
                <Button
                  variant="ghost"
                  size="sm"
                  leading-icon="palette"
                  :title="t('generator.sessions.character.openAppearanceTitle')"
                  @click="openAppearance"
                >
                  {{ t('generator.sessions.character.openAppearance') }}
                </Button>
              </div>
            </div>
          </Alert>

          <!-- Per-shot action row (rides the SAME per-part op contract with `storyboard.<i>`). -->
          <div class="flex flex-col gap-next-2">
            <div class="flex flex-wrap items-center gap-next-1">
              <Tooltip :label="generating ? busyTooltip : blocked ? blockedTooltip : regenerateLabel">
                <Button
                  variant="ghost"
                  size="icon-sm"
                  leading-icon="rotate-ccw"
                  :loading="isBusyFor(shotKey(shot))"
                  :disabled="!canShotRegenerate(shot)"
                  :aria-label="t('generator.sessions.regenerate')"
                  @click="regenerateShot(shot)"
                />
              </Tooltip>
              <!-- Refine (AI image-edit) revises the CURRENT image — offered only for a produced (`ok`)
                   shot, mirroring the top-level `isRefinable` rule and the per-shot Save gate. A failed
                   shot has no image to refine (regenerate retries it instead). -->
              <Tooltip
                v-if="shot.image_status === 'ok'"
                :label="generating ? busyTooltip : blocked ? blockedTooltip : t('generator.sessions.result.refine')"
              >
                <Button
                  variant="ghost"
                  size="icon-sm"
                  leading-icon="pencil"
                  :disabled="!canRefineKey(shotKey(shot))"
                  :aria-label="t('generator.sessions.result.refine')"
                  :aria-expanded="refineKey === shotKey(shot) ? 'true' : 'false'"
                  @click="toggleRefine(shotKey(shot))"
                />
              </Tooltip>
              <Tooltip :label="shotUndoTooltip(shot)">
                <Button
                  variant="ghost"
                  size="icon-sm"
                  leading-icon="undo"
                  :disabled="!canShotUndo(shot)"
                  :aria-label="t('generator.sessions.result.undo')"
                  @click="undoShot(shot)"
                />
              </Tooltip>
              <Tooltip v-if="shot.image_status === 'ok'" :label="t('generator.sessions.result.saveToDisk')">
                <Button
                  variant="ghost"
                  size="sm"
                  leading-icon="folder"
                  :disabled="!caps.saveToDisk"
                  @click="saveShot(shot)"
                >
                  {{ t('generator.sessions.result.saveToDisk') }}
                </Button>
              </Tooltip>
            </div>

            <!-- Inline per-shot refine composer (an AI edit instruction). -->
            <div
              v-if="refineKey === shotKey(shot)"
              class="flex flex-col gap-next-2 rounded-next-lg border border-next-border bg-next-muted/30 p-next-3"
            >
              <label :for="`refine-${shotKey(shot)}`" class="text-next-xs font-next-medium text-next-muted-foreground">
                {{ t('generator.sessions.result.refineLabel') }}
              </label>
              <textarea
                :id="`refine-${shotKey(shot)}`"
                v-model="refineDraft"
                rows="2"
                :disabled="!canRefineKey(shotKey(shot))"
                :placeholder="t('generator.sessions.result.refinePlaceholder')"
                :aria-label="t('generator.sessions.result.refineLabel')"
                class="w-full resize-none rounded-next-md border border-next-input bg-next-bg px-next-3 py-next-2 text-next-sm text-next-fg placeholder:text-next-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-next-ring disabled:cursor-not-allowed disabled:opacity-60"
                @keydown="(e) => onRefineKeydown(e, shotKey(shot))"
              />
              <div class="flex items-center justify-end gap-next-2">
                <Button variant="ghost" size="sm" @click="toggleRefine(shotKey(shot))">
                  {{ t('common.cancel', 'Cancel') }}
                </Button>
                <Button
                  variant="secondary"
                  size="sm"
                  leading-icon="arrow-up"
                  :disabled="!canRefineKey(shotKey(shot)) || !refineDraft.trim()"
                  @click="submitRefine(shotKey(shot))"
                >
                  {{ t('generator.sessions.result.refine') }}
                </Button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Action row + inline refine composer (2d). Storyboard shows only whole-part regenerate/undo (its
         refine/save live PER SHOT above); other kinds show the full row. -->
    <template #footer>
      <div class="flex w-full flex-col gap-next-2">
        <div class="flex flex-wrap items-center gap-next-1">
          <!-- Copy (text_body / script / shot_list flattened text). -->
          <Tooltip v-if="isText || isShotList" :label="t('generator.sessions.result.copy')">
            <Button
              variant="ghost"
              size="icon-sm"
              leading-icon="copy"
              :disabled="!caps.copy || !copyable"
              :aria-label="t('generator.sessions.result.copy')"
              @click="copyText"
            />
          </Tooltip>

          <!-- Regenerate (whole part) — for storyboard this is a clearly-labeled "regenerate all shots". -->
          <Tooltip :label="regenerateTooltip">
            <Button
              v-if="isStoryboard"
              variant="ghost"
              size="sm"
              leading-icon="rotate-ccw"
              :loading="busy"
              :disabled="!canRegenerate"
              @click="regenerate"
            >
              {{ regenerateLabel }}
            </Button>
            <Button
              v-else
              variant="ghost"
              size="icon-sm"
              leading-icon="rotate-ccw"
              :loading="busy"
              :disabled="!canRegenerate"
              :aria-label="regenerateLabel"
              @click="regenerate"
            />
          </Tooltip>

          <!-- Refine (text_body / script / image_plan / shot_list only). -->
          <Tooltip v-if="isRefinable" :label="refineTooltip">
            <Button
              variant="ghost"
              size="icon-sm"
              leading-icon="pencil"
              :disabled="!canRefine"
              :aria-label="t('generator.sessions.result.refine')"
              :aria-expanded="refineKey === part.key ? 'true' : 'false'"
              @click="toggleRefine(part.key)"
            />
          </Tooltip>

          <!-- Undo (whole part — gated on can_undo). -->
          <Tooltip :label="undoTooltip">
            <Button
              variant="ghost"
              size="icon-sm"
              leading-icon="undo"
              :disabled="!canUndo"
              :aria-label="t('generator.sessions.result.undo')"
              @click="undo"
            />
          </Tooltip>

          <!-- Save to Disk (top-level image_plan only). -->
          <Tooltip v-if="isImage" :label="t('generator.sessions.result.saveToDisk')">
            <Button
              variant="ghost"
              size="sm"
              leading-icon="folder"
              :disabled="!canSaveImage"
              @click="saveImage"
            >
              {{ t('generator.sessions.result.saveToDisk') }}
            </Button>
          </Tooltip>
        </div>

        <!-- Inline refine composer for the TOP-LEVEL part (revealed by "Dopracuj"). -->
        <div
          v-if="refineKey === part.key && isRefinable"
          class="flex flex-col gap-next-2 rounded-next-lg border border-next-border bg-next-muted/30 p-next-3"
        >
          <label :for="`refine-${part.key}`" class="text-next-xs font-next-medium text-next-muted-foreground">
            {{ t('generator.sessions.result.refineLabel') }}
          </label>
          <textarea
            :id="`refine-${part.key}`"
            v-model="refineDraft"
            rows="2"
            :disabled="!canRefine"
            :placeholder="t('generator.sessions.result.refinePlaceholder')"
            :aria-label="t('generator.sessions.result.refineLabel')"
            class="w-full resize-none rounded-next-md border border-next-input bg-next-bg px-next-3 py-next-2 text-next-sm text-next-fg placeholder:text-next-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-next-ring disabled:cursor-not-allowed disabled:opacity-60"
            @keydown="(e) => onRefineKeydown(e, part.key)"
          />
          <div class="flex items-center justify-end gap-next-2">
            <Button variant="ghost" size="sm" @click="toggleRefine(part.key)">
              {{ t('common.cancel', 'Cancel') }}
            </Button>
            <Button
              variant="secondary"
              size="sm"
              leading-icon="arrow-up"
              :disabled="!canRefine || !refineDraft.trim()"
              @click="submitRefine(part.key)"
            >
              {{ t('generator.sessions.result.refine') }}
            </Button>
          </div>
        </div>
      </div>
    </template>
  </Card>
</template>
