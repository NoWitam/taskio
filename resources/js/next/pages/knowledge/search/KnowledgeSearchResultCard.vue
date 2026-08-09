<script setup lang="ts">
// KnowledgeSearchResultCard — one hit: the entry, where it lives, and WHY it matched.
//
// THE HIGHLIGHT IS BUILT FROM OFFSETS, never from server HTML. The backend deliberately returns a
// verbatim `snippet` plus `[start, length]` ranges into it, so this component slices the string and
// lets Vue escape each piece. Injecting markup from the server into user-authored prose would
// create a sanitisation seam for what is, in the end, a rendering convenience.
//
// The ellipses come from `truncated_before` / `truncated_after`, not from looking at the string:
// the snippet is an exact slice, so an entry that genuinely starts with "…" would otherwise be
// reported as cut, and a cut that lands on a word boundary as complete.
//
// The percentage is `matched_chunk.score` (a real cosine similarity). `rrf_score` is never shown —
// it is a fused RANK score, comparable only within one response, and rendering it as a percentage
// would invent a meaning it does not have.
import { computed } from 'vue';
import Surface from '../../../ui/layout/Surface.vue';
import Text from '../../../ui/primitives/Text.vue';
import Icon from '../../../ui/primitives/Icon.vue';
import Badge from '../../../ui/primitives/Badge.vue';
import Button from '../../../ui/primitives/Button.vue';
import Link from '../../../ui/primitives/Link.vue';
import Progress from '../../../ui/feedback/Progress.vue';
import StatusBadge from '../../../ui/data/StatusBadge.vue';
import { entryStatusMap } from '../statusMaps';
import { headingPathParts, highlightSegments, truncationMarkers, scorePercent } from './highlightSegments';
import { useI18n } from '../../../app/i18n';
import type { KnowledgeSearchResult } from '../types';

const props = withDefaults(
  defineProps<{
    result: KnowledgeSearchResult;
    /** Show which base the hit came from (the workspace-wide screen). */
    showBase?: boolean;
  }>(),
  { showBase: false },
);

const emit = defineEmits<{
  (e: 'open', result: KnowledgeSearchResult): void;
  (e: 'jump', result: KnowledgeSearchResult): void;
}>();

const { t } = useI18n();
const statusMap = computed(() => entryStatusMap(t));

const chunk = computed(() => props.result.matched_chunk);

/** Falls back to the list excerpt for a keyword-only hit that carried no chunk. */
const segments = computed(() =>
  chunk.value
    ? highlightSegments(chunk.value.snippet, chunk.value.highlights)
    : [{ text: props.result.excerpt, match: false }],
);

const markers = computed(() => truncationMarkers(chunk.value));

const percent = computed(() => scorePercent(chunk.value?.score));
const scoreLabel = computed(() =>
  percent.value == null ? null : t('knowledge.search.score', '', { percent: percent.value }),
);

/**
 * The chunk's heading trail — a LOCATION, not navigation, so it is not Breadcrumbs.
 *
 * `heading_path` arrives ALREADY JOINED (`"Cennik > Zwroty"`), so it is split into segments here.
 * Rendering the raw string would make `v-for` walk it character by character.
 */
const headingPath = computed(() => headingPathParts(chunk.value?.heading_path));

/** Jumping needs an offset to aim at; a keyword-only hit has none. */
const canJump = computed(() => chunk.value != null);
</script>

<template>
  <Surface
    as="li"
    bg="card"
    border
    radius="lg"
    elevation="sm"
    class="flex flex-col gap-next-2 p-next-4"
  >
    <div class="flex min-w-0 items-start gap-next-2">
      <Icon name="file-text" class="mt-next-1 shrink-0 text-next-sm text-next-muted-foreground" aria-hidden="true" />
      <!-- `plain`: the hit title reads as the card's heading and inherits the card's colour. -->
      <Link
        :href="`#${result.slug}`"
        variant="plain"
        class="min-w-0 flex-1 font-next-medium"
        @click.prevent="emit('open', result)"
      >
        {{ result.title }}
      </Link>
      <StatusBadge
        v-if="result.status"
        :status="result.status"
        :status-map="statusMap"
        size="sm"
        class="shrink-0"
      />
    </div>

    <!-- Base · heading path. Labelled so a screen reader knows this locates the passage. -->
    <div
      v-if="(showBase && result.base) || headingPath.length"
      class="flex min-w-0 flex-wrap items-center gap-next-1 text-next-xs text-next-muted-foreground"
      :aria-label="t('knowledge.search.headingPath')"
    >
      <Badge v-if="showBase && result.base" variant="neutral" tone="subtle" size="sm" icon="book-open">
        {{ result.base.name }}
      </Badge>
      <!-- `headingPath` is a SPLIT array; the wire value is one joined string, and iterating that
           directly would draw one crumb per character. -->
      <template v-for="(part, index) in headingPath" :key="index">
        <span v-if="index > 0 || (showBase && result.base)" aria-hidden="true">›</span>
        <span class="truncate" data-heading-crumb>{{ part }}</span>
      </template>
    </div>

    <!--
      The snippet. Each matched run is a real `<mark>` — not a tinted span, which is what it used
      to be. The comment said "<mark>-equivalent" and that was exactly the gap: a neutral span with
      a background tint carries the match in COLOUR ALONE. It vanishes in greyscale and does not
      exist for a screen reader, which is the reader most in need of being told which words matched.
      `TextDiffView` already does this properly with a glyph plus an `sr-only` prefix; this now
      carries the semantic element plus the same spoken marker. Nothing here is `v-html`.
    -->
    <Text variant="ui" class="min-w-0">
      <span v-if="markers.before" aria-hidden="true">{{ markers.before }}</span><!--
      --><template v-for="(segment, index) in segments" :key="index"><mark
          v-if="segment.match"
          class="rounded-next-xs bg-next-primary-subtle px-next-0_5 text-next-primary-subtle-foreground"
        ><span class="next-sr-only">{{ t('knowledge.search.matchStart') }} </span>{{ segment.text }}</mark><template v-else>{{ segment.text }}</template></template><!--
      --><span v-if="markers.after" aria-hidden="true">{{ markers.after }}</span>
    </Text>

    <div class="flex flex-wrap items-center gap-next-3">
      <div v-if="percent != null" class="flex min-w-0 flex-1 items-center gap-next-2">
        <div class="w-24 shrink-0">
          <Progress :value="percent" size="sm" tone="primary" :aria-label="scoreLabel ?? undefined" />
        </div>
        <Text variant="caption" tone="muted" class="shrink-0 tabular-nums">{{ scoreLabel }}</Text>
      </div>

      <!-- "This document matched in N places" is a relevance signal a human reads instantly. -->
      <Text v-if="result.matched_chunks_count > 1" variant="caption" tone="muted">
        {{ t('knowledge.search.matches', '', { count: result.matched_chunks_count }) }}
      </Text>

      <Button v-if="canJump" variant="link" size="xs" class="ms-auto" @click="emit('jump', result)">
        {{ t('knowledge.search.jump') }}
      </Button>
    </div>
  </Surface>
</template>

<style scoped>
/* Local copy, matching Link.vue / Spinner.vue — the design system has no global utility. */
.next-sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border-width: 0;
}
</style>
