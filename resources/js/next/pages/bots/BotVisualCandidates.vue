<script setup lang="ts">
// BotVisualCandidates — the bot's LIKENESS STRIP: the generated iterations, which one is approved, and
// the two curation actions on each (enlarge / delete).
//
// The strip is a CHOICE, not an archive: the module keeps at most `max` (6) images and a further
// generation permanently evicts the OLDEST unapproved one, so the tile that is next in line says so
// (a `title` plus an sr-only line — never colour alone). Order is the SERVER's: oldest first.
//
// Interaction shape, and why it is not a radiogroup: selecting a likeness POSTs an approval, so an
// arrow-key "selection" would spend a request per keypress. Each tile is therefore an ordinary button
// in a list — full-tile click APPROVES (or, for the already-approved one, ENLARGES) — with a small,
// ALWAYS-VISIBLE action cluster (enlarge / delete) rather than hover-only affordances, which would be
// unreachable by keyboard and invisible on touch.
//
// The approved tile carries THREE non-colour signals: a success ring, a "Zatwierdzony" badge, and its
// own aria-label. Deleting it is refused server-side (the bot would silently lose its likeness), so the
// control is disabled WITH the reason rather than hidden.
//
// Focus: after a delete, focus lands on the tile that took its place (or the previous one); when the
// strip empties the panel is told to focus "Generate", so a keyboard user is never dropped on <body>.
import { computed, nextTick, ref, watch } from 'vue';
import Badge from '../../ui/primitives/Badge.vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Skeleton from '../../ui/data/Skeleton.vue';
import Modal from '../../ui/overlay/Modal.vue';
import BotVisualImage from './BotVisualImage.vue';
import { useI18n } from '../../app/i18n';

const props = withDefaults(
  defineProps<{
    /** The candidate file ids, in the SERVER's order (oldest first). */
    candidates: string[];
    /** The APPROVED likeness's file id, or null. */
    canonicalFileId: string | null;
    /** The bot's name — the images' alt text names it. */
    botName: string;
    /** How many the module keeps (a further generation evicts the oldest unapproved one). */
    max?: number;
    /** A generation is in flight → a skeleton tile holds the place its result will take. */
    pending?: boolean;
    /** The file id of an in-flight approve / delete (that tile's control spins). */
    busyFileId?: string | null;
    /** Curation is unavailable (e.g. an unsaved bot) — the strip is read-only. */
    disabled?: boolean;
  }>(),
  { max: 6, pending: false, busyFileId: null, disabled: false },
);

const emit = defineEmits<{
  (e: 'approve', fileId: string): void;
  (e: 'remove', fileId: string): void;
  /** The strip just emptied — the panel moves focus to the generate button. */
  (e: 'focus-generate'): void;
}>();

const { t } = useI18n();

const count = computed(() => props.candidates.length);
const isEmpty = computed(() => count.value === 0 && !props.pending);

function isCanonical(fileId: string): boolean {
  return props.canonicalFileId === fileId;
}

/**
 * The tile a further generation would EVICT: the first unapproved one, and only once the strip is full
 * (below the cap nothing is at risk, so the warning would be noise).
 */
const evictableIndex = computed(() => {
  if (count.value < props.max) return -1;
  return props.candidates.findIndex((id) => !isCanonical(id));
});

function isEvictable(index: number): boolean {
  return index === evictableIndex.value;
}

function tileLabel(fileId: string, index: number): string {
  const params = { n: index + 1, count: count.value };
  return isCanonical(fileId)
    ? t('bots.editor.visual.candidates.canonicalItemLabel', '', params)
    : t('bots.editor.visual.candidates.itemLabel', '', params);
}

function imageAlt(index: number): string {
  return t('bots.editor.visual.candidates.imageAlt', '', { name: props.botName, n: index + 1 });
}

/** Why deleting is refused, or undefined when it is allowed (the label doubles as the disabled reason). */
function removeReason(fileId: string): string | undefined {
  return isCanonical(fileId) ? t('bots.editor.visual.candidates.removeCanonical') : undefined;
}

/**
 * The tile's hover hint. The eviction warning OUTRANKS the action hint: losing an image permanently is
 * worth more than restating what a click does.
 */
function tileTitle(fileId: string, index: number): string {
  if (isEvictable(index)) return t('bots.editor.visual.candidates.oldestHint');
  return isCanonical(fileId)
    ? t('bots.editor.visual.candidates.preview')
    : t('bots.editor.visual.candidates.approve');
}

// --- Tile refs (focus management) -------------------------------------------
const tileRefs = ref<Record<string, HTMLElement | null>>({});
function setTileRef(el: unknown, fileId: string): void {
  tileRefs.value[fileId] = (el as { $el?: HTMLElement } | HTMLElement | null) as HTMLElement | null;
}

/** The index whose tile was just asked to be deleted — where focus should land once it is gone. */
const removalIndex = ref<number | null>(null);

function onTileClick(fileId: string, index: number): void {
  if (props.disabled || props.busyFileId) return;
  if (isCanonical(fileId)) {
    openPreview(index);
    return;
  }
  emit('approve', fileId);
}

function onRemove(fileId: string, index: number): void {
  if (props.disabled || props.busyFileId || isCanonical(fileId)) return;
  removalIndex.value = index;
  emit('remove', fileId);
}

// After the parent's delete resolves the list shrinks: move focus to whatever now occupies that slot
// (or the last tile), and hand focus back to the panel when nothing is left.
watch(count, (next, previous) => {
  if (removalIndex.value === null || next >= previous) return;
  const target = Math.min(removalIndex.value, next - 1);
  removalIndex.value = null;
  if (next === 0) {
    emit('focus-generate');
    return;
  }
  void nextTick(() => {
    const id = props.candidates[target];
    tileRefs.value[id]?.focus?.();
  });
});

// --- Preview modal ----------------------------------------------------------
// The Modal's focus trap restores focus to whatever opened it, so the enlarge button gets it back.
const previewIndex = ref<number | null>(null);
const previewOpen = computed<boolean>({
  get: () => previewIndex.value !== null,
  set: (open) => {
    if (!open) previewIndex.value = null;
  },
});
const previewFileId = computed(() =>
  previewIndex.value === null ? null : (props.candidates[previewIndex.value] ?? null),
);

function openPreview(index: number): void {
  previewIndex.value = index;
}
</script>

<template>
  <div class="flex flex-col gap-next-2">
    <!-- Empty: a light in-panel prompt, NOT an EmptyState (too heavy inside a module panel). -->
    <div
      v-if="isEmpty"
      class="rounded-next-lg border border-dashed border-next-border bg-next-muted/40 p-next-6 text-center"
    >
      <Icon name="palette" class="mx-auto mb-next-2 text-next-muted-foreground" aria-hidden="true" />
      <p class="text-next-sm text-next-fg">{{ t('bots.editor.visual.candidates.empty') }}</p>
      <p class="text-next-xs text-next-muted-foreground">{{ t('bots.editor.visual.candidates.emptyHint') }}</p>
    </div>

    <ul v-else class="grid grid-cols-2 gap-next-2 next-sm:grid-cols-3">
      <li
        v-for="(fileId, index) in candidates"
        :key="fileId"
        class="relative aspect-square overflow-hidden rounded-next-md border border-next-border bg-next-muted/40"
        :class="isCanonical(fileId) ? 'ring-2 ring-next-success ring-offset-2 ring-offset-next-card' : ''"
      >
        <button
          :ref="(el) => setTileRef(el, fileId)"
          type="button"
          class="absolute inset-0 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-next-ring"
          :disabled="disabled || !!busyFileId"
          :aria-label="tileLabel(fileId, index)"
          :title="tileTitle(fileId, index)"
          @click="onTileClick(fileId, index)"
        >
          <BotVisualImage :file-id="fileId" :alt="imageAlt(index)" fit="cover" lazy />
        </button>

        <Badge
          v-if="isCanonical(fileId)"
          class="pointer-events-none absolute left-next-1 top-next-1"
          variant="success"
          tone="subtle"
          size="sm"
          icon="check-circle"
        >
          {{ t('bots.editor.visual.candidates.canonicalBadge') }}
        </Badge>

        <!-- The eviction warning as TEXT for assistive tech (the title covers pointer users). -->
        <span v-if="isEvictable(index)" class="sr-only">
          {{ t('bots.editor.visual.candidates.oldestHint') }}
        </span>

        <!-- Always-visible action cluster (never hover-only). -->
        <div
          class="absolute bottom-next-1 right-next-1 flex items-center gap-next-0_5 rounded-next-sm bg-next-card/80 p-next-0_5 backdrop-blur-sm"
        >
          <Button
            variant="ghost"
            size="icon-xs"
            leading-icon="eye"
            :aria-label="t('bots.editor.visual.candidates.preview')"
            :title="t('bots.editor.visual.candidates.preview')"
            @click="openPreview(index)"
          />
          <Button
            variant="ghost"
            size="icon-xs"
            leading-icon="trash"
            :disabled="disabled || isCanonical(fileId) || !!busyFileId"
            :loading="busyFileId === fileId"
            :aria-label="removeReason(fileId) ?? t('bots.editor.visual.candidates.remove', '', { n: index + 1 })"
            :title="removeReason(fileId) ?? t('bots.editor.visual.candidates.remove', '', { n: index + 1 })"
            @click="onRemove(fileId, index)"
          />
        </div>
      </li>

      <!-- The in-flight generation's place: a tile-shaped skeleton, same geometry as a real tile. -->
      <li
        v-if="pending"
        class="relative aspect-square overflow-hidden rounded-next-md border border-dashed border-next-border bg-next-muted/40"
        data-test="candidate-pending"
      >
        <Skeleton variant="rect" width="100%" height="100%" radius="md" />
      </li>
    </ul>

    <!-- Enlarged view of one likeness. -->
    <Modal v-model:open="previewOpen" size="lg" :aria-label="t('bots.editor.visual.candidates.preview')">
      <template #title>
        {{ t('bots.editor.visual.candidates.previewTitle', '', { n: (previewIndex ?? 0) + 1 }) }}
      </template>
      <div v-if="previewFileId" class="flex max-h-[60vh] items-center justify-center">
        <BotVisualImage :file-id="previewFileId" :alt="imageAlt(previewIndex ?? 0)" fit="contain" />
      </div>
    </Modal>
  </div>
</template>
