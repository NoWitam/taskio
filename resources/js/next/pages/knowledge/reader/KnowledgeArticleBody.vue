<script setup lang="ts">
// KnowledgeArticleBody — the ARTICLE half of the reader: header badges, the rendered markdown with
// live wikilinks, heading anchors for deep links, and the hover/focus preview popover.
//
// The `<h1>` lives HERE, not in a PageHeader, and it is the entry's TITLE. That is the documented
// exception to ADR-0011 (sections title themselves): in a reader the real heading of the page is
// the article's heading, and inventing a second one would give the page two `<h1>`s.
//
// ─────────────────────────────────────────────────────────────────────────────
// Wikilinks
// ─────────────────────────────────────────────────────────────────────────────
// `MarkdownViewer` builds the anchors AFTER sanitization (see its `wikilinks` prop); this component
// supplies the resolver and owns every behaviour around them:
//   • ONE delegated click listener on the container — the anchors come out of `v-html`, so they
//     cannot be wrapped in components or given Vue listeners individually;
//   • Enter on a GHOST, which has no `href` and therefore no default activation;
//   • ONE always-mounted popover for previews, anchored to whichever anchor is hovered or focused.
//     `focusin` matters as much as `pointerover`: a keyboard user tabbing through links gets the
//     same preview a mouse user gets.
import { computed, onBeforeUnmount, nextTick, ref, watch } from 'vue';
import MarkdownViewer, { type WikilinkOptions } from '../../../ui/editor/MarkdownViewer.vue';
import KnowledgeEntryPreview from './KnowledgeEntryPreview.vue';
import Heading from '../../../ui/primitives/Heading.vue';
import Text from '../../../ui/primitives/Text.vue';
import Badge from '../../../ui/primitives/Badge.vue';
import Alert from '../../../ui/feedback/Alert.vue';
import Button from '../../../ui/primitives/Button.vue';
import StatusBadge from '../../../ui/data/StatusBadge.vue';
import CreatorBadge from '../../../ui/patterns/CreatorBadge.vue';
import { useAnchoredPosition } from '../../../app/composables/useAnchoredPosition';
import { useTheme } from '../../../app/lib/theme';
import { entryStatusMap, indexStatusMap } from '../statusMaps';
import { entryIndexLabel, entryIndexHint, entryIndexHintVariant } from '../entryMeta';
import { assignHeadingAnchors, jumpToAnchor } from './wikilinkAnchors';
import { formatDate } from '../baseMeta';
import { useI18n } from '../../../app/i18n';
import type { KnowledgeEntry, KnowledgeEntryListItem } from '../types';

const props = defineProps<{
  entry: KnowledgeEntry;
  /** Every entry of the base, by slug — the wikilink resolver and the preview both read this. */
  entriesBySlug: Map<string, KnowledgeEntryListItem>;
  /** Builds the reader href for a slug (owned by the view, which knows the route). */
  hrefFor: (slug: string) => string;
  /** Heading anchor to jump to on mount / on change (from `#h-…` or a search deep link). */
  jumpAnchor?: string | null;
  /** A retry is in flight (owned by the view, which makes the request). */
  retrying?: boolean;
}>();

const emit = defineEmits<{
  (e: 'navigate', slug: string): void;
  (e: 'create-ghost', slug: string): void;
  (e: 'retry-index'): void;
}>();

const { t } = useI18n();
const { isDark } = useTheme();

const statusMap = computed(() => entryStatusMap(t));
const indexMap = computed(() => indexStatusMap(t));

const articleRef = ref<HTMLElement | null>(null);

// --- Wikilink resolution ----------------------------------------------------
/**
 * Resolve a slug for the viewer. Returning null makes it a GHOST — which is why the reader must
 * hold the base's WHOLE entry list: resolving against a single loaded page would paint real entries
 * as red links purely because they sat on page 2.
 */
const wikilinkOptions = computed<WikilinkOptions>(() => ({
  resolve: (slug: string) => {
    const target = props.entriesBySlug.get(slug);
    return target ? { href: props.hrefFor(slug), title: target.title } : null;
  },
  ghostLabel: (label: string) => t('knowledge.reader.ghost.aria', '', { label }),
}));

// --- Delegated activation ---------------------------------------------------
function anchorFrom(target: EventTarget | null): HTMLElement | null {
  return (target as HTMLElement | null)?.closest?.('[data-wikilink]') ?? null;
}

function activate(anchor: HTMLElement): void {
  const slug = anchor.getAttribute('data-wikilink');
  if (!slug) return;
  closePreview();
  if (anchor.hasAttribute('data-ghost')) emit('create-ghost', slug);
  else emit('navigate', slug);
}

function onClick(event: MouseEvent): void {
  const anchor = anchorFrom(event.target);
  if (!anchor) return;
  // Let the browser handle modified clicks (new tab / new window) — the anchors carry real hrefs.
  if (event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) return;
  event.preventDefault();
  activate(anchor);
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key !== 'Enter') return;
  const anchor = anchorFrom(event.target);
  // A ghost has no href, so nothing would happen by default; a real link activates itself.
  if (!anchor || !anchor.hasAttribute('data-ghost')) return;
  event.preventDefault();
  activate(anchor);
}

// --- Preview popover --------------------------------------------------------
// ONE panel per article, teleported to <body>. Individual anchors cannot host components (they are
// produced by v-html), so the panel is shared and re-anchored on each hover/focus.
const OPEN_DELAY = 250;
const CLOSE_DELAY = 150;

const previewSlug = ref<string | null>(null);
const previewGhost = ref(false);
const previewAnchor = ref<HTMLElement | null>(null);
const panelRef = ref<HTMLElement | null>(null);
let openTimer: ReturnType<typeof setTimeout> | null = null;
let closeTimer: ReturnType<typeof setTimeout> | null = null;

const previewId = 'next-knowledge-preview';

const previewEntry = computed(() =>
  previewSlug.value ? (props.entriesBySlug.get(previewSlug.value) ?? null) : null,
);

const { style: anchorStyle, update: updatePosition } = useAnchoredPosition(previewAnchor, panelRef, {
  placement: () => 'top-start',
  gap: 8,
  flip: true,
});

function clearTimers(): void {
  if (openTimer) clearTimeout(openTimer);
  if (closeTimer) clearTimeout(closeTimer);
  openTimer = null;
  closeTimer = null;
}

function scheduleOpen(anchor: HTMLElement): void {
  clearTimers();
  openTimer = setTimeout(() => {
    const slug = anchor.getAttribute('data-wikilink');
    if (!slug) return;
    previewSlug.value = slug;
    previewGhost.value = anchor.hasAttribute('data-ghost');
    previewAnchor.value = anchor;
    // The anchor is described BY the panel while it is open — the association is what makes the
    // preview reachable to a screen reader rather than decorative.
    anchor.setAttribute('aria-describedby', previewId);
    void nextTick(() => {
      updatePosition();
      requestAnimationFrame(updatePosition);
    });
  }, OPEN_DELAY);
}

function scheduleClose(): void {
  clearTimers();
  closeTimer = setTimeout(closePreview, CLOSE_DELAY);
}

function closePreview(): void {
  clearTimers();
  previewAnchor.value?.removeAttribute('aria-describedby');
  previewSlug.value = null;
  previewGhost.value = false;
  previewAnchor.value = null;
}

function onPointerOver(event: PointerEvent): void {
  const anchor = anchorFrom(event.target);
  if (anchor) scheduleOpen(anchor);
}
function onPointerOut(event: PointerEvent): void {
  if (anchorFrom(event.target)) scheduleClose();
}
function onFocusIn(event: FocusEvent): void {
  const anchor = anchorFrom(event.target);
  if (anchor) scheduleOpen(anchor);
}
function onFocusOut(event: FocusEvent): void {
  if (anchorFrom(event.target)) scheduleClose();
}
function onPreviewEscape(event: KeyboardEvent): void {
  if (event.key === 'Escape' && previewSlug.value) closePreview();
}

// --- Heading anchors --------------------------------------------------------
let jumpTimer: ReturnType<typeof setTimeout> | null = null;

/**
 * Re-stamp heading ids after every render. The markdown is injected with `v-html`, so the heading
 * elements are REPLACED on each content change — ids assigned to the previous nodes are gone.
 */
watch(
  () => [props.entry.id, props.entry.content] as const,
  () => {
    void nextTick(() => {
      assignHeadingAnchors(articleRef.value);
      runJump();
    });
  },
  { immediate: true },
);

watch(() => props.jumpAnchor, () => void nextTick(runJump));

function runJump(): void {
  if (!props.jumpAnchor) return;
  if (jumpTimer) clearTimeout(jumpTimer);
  jumpTimer = jumpToAnchor(articleRef.value, props.jumpAnchor);
}

onBeforeUnmount(() => {
  clearTimers();
  if (jumpTimer) clearTimeout(jumpTimer);
});

// --- Header meta ------------------------------------------------------------
const indexLabel = computed(() =>
  entryIndexLabel(props.entry.index?.status, t, {
    indexed: props.entry.index?.indexed_chunks_count,
    total: props.entry.index?.chunks_count,
  }),
);
const indexHint = computed(() => entryIndexHint(props.entry.index?.status, t));
const indexHintVariant = computed(() => entryIndexHintVariant(props.entry.index?.status));
const chunkCount = computed(() => props.entry.index?.chunks_count ?? null);
/**
 * B6c — the retry affordance is gated by the SERVER's own verdict, not by a status this component
 * re-judges. It used to appear only on `failed`, which left the two states that most need it
 * (`partial`, `pending_budget`) with a dead-end message.
 */
const canRetryIndex = computed(() => props.entry.index?.can_retry === true);

/** Joined for display, empty string when there are none — the header line drops out entirely. */
const aliasList = computed(() => (props.entry.aliases ?? []).join(', '));
</script>

<template>
  <article class="flex min-w-0 flex-col gap-next-4">
    <!-- Article header: the page's single <h1> + the badges that say whether this entry is ready
         for AI. Announced politely, because the index state changes under the user. -->
    <header class="flex flex-col gap-next-2">
      <!-- `:level` is a NUMBER prop — the literal string form fails its type check. -->
      <Heading :level="1" size="xl" balance>{{ entry.title }}</Heading>

      <!-- The other names this entry answers to, kept DELIBERATELY quiet: they are a matching
           aid for the mention layer, not a second title, and a reader who never edits the entry
           does not need them competing with the heading. -->
      <Text v-if="aliasList" variant="caption" class="text-next-muted-foreground">
        {{ t('knowledge.reader.aliases', '', { names: aliasList }) }}
      </Text>

      <div class="flex flex-wrap items-center gap-next-2" aria-live="polite">
        <StatusBadge
          v-if="entry.status"
          :status="entry.status"
          :status-map="statusMap"
          size="sm"
        />
        <Badge v-if="entry.is_stale" variant="warning" tone="subtle" icon="alert-triangle" size="sm">
          {{ t('knowledge.reader.stale') }}
        </Badge>
        <StatusBadge
          v-if="entry.index?.status"
          :status="entry.index.status"
          :status-map="indexMap"
          :label="indexLabel"
          size="sm"
        />
        <span v-if="chunkCount != null" class="text-next-xs text-next-muted-foreground">
          {{ t('knowledge.reader.chunks', '', { count: chunkCount }) }}
        </span>
      </div>

      <div class="flex flex-wrap items-center gap-next-2 text-next-xs text-next-muted-foreground">
        <CreatorBadge :creator="entry.creator ?? null" size="xs" />
        <span v-if="entry.updated_at">
          <time :datetime="entry.updated_at">
            {{ t('knowledge.reader.updatedBy', '', { date: formatDate(entry.updated_at) }) }}
          </time>
        </span>
      </div>
    </header>

    <!-- Index state, spelled out. Mandatory copy: the user must never have to guess why semantic
         search does not cover this entry yet. -->
    <Alert v-if="indexHint" :variant="indexHintVariant" size="sm">
      {{ indexHint }}
      <template v-if="canRetryIndex" #actions>
        <Button
          variant="outline"
          size="xs"
          leading-icon="rotate-ccw"
          :loading="retrying"
          data-retry-index
          @click="emit('retry-index')"
        >
          {{ t('knowledge.index.retry') }}
        </Button>
      </template>
    </Alert>

    <!-- The body. `max-w-[72ch]` is the one arbitrary value in this module and it is deliberate:
         a measure, which no spacing token expresses. -->
    <div
      ref="articleRef"
      class="min-w-0 max-w-[72ch]"
      @click="onClick"
      @keydown="onKeydown"
      @pointerover="onPointerOver"
      @pointerout="onPointerOut"
      @focusin="onFocusIn"
      @focusout="onFocusOut"
      @keydown.escape="onPreviewEscape"
    >
      <MarkdownViewer
        :source="entry.content"
        :wikilinks="wikilinkOptions"
        :aria-label="t('knowledge.reader.articleLabel')"
      />
    </div>

    <!-- The shared preview panel. `role="tooltip"` and NOTHING focusable inside (the preview
         component's `actions` stays off), so it can never become a focus trap over the text. -->
    <Teleport to="body">
      <div v-if="previewSlug" class="next-root next-overlay-root" :class="isDark ? 'dark' : ''">
        <div
          :id="previewId"
          ref="panelRef"
          role="tooltip"
          class="fixed z-[var(--z-next-tooltip)] w-72 max-w-[min(92vw,20rem)] rounded-next-lg border border-next-border bg-next-popover p-next-3 text-next-popover-foreground shadow-next-lg"
          :style="{ top: `${anchorStyle.top}px`, left: `${anchorStyle.left}px` }"
        >
          <KnowledgeEntryPreview
            :entry="previewEntry"
            :slug="previewSlug"
            :ghost="previewGhost"
            :errored="!previewGhost && !previewEntry"
          />
        </div>
      </div>
    </Teleport>
  </article>
</template>
