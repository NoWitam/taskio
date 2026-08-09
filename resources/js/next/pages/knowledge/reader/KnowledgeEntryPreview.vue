<script setup lang="ts">
// KnowledgeEntryPreview — the ONE "entry at a glance" card.
//
// Deliberately a single component with two hosts, because they show the same thing and two copies
// would drift within a week (spec R4):
//   1. the reader's hover/focus POPOVER over a wikilink — rendered with `actions` OFF, so it is a
//      pure hint containing no interactive elements (a tooltip the user can focus INTO is a trap);
//   2. B5b's GRAPH side panel — same card with `actions` ON, which reveals the `#actions` slot
//      under the body ("Open entry", "Centre here", "Create this entry" on a ghost, …).
//
// It renders CONTENT ONLY: no positioning, no teleport, no fetching. The host owns all three. That
// is what lets a popover and a docked panel share it.
//
// STATES, all four, because a preview that only handles the happy path is where hover UIs rot:
//   loading → skeleton shaped like the real card (title line + two body lines)
//   ghost   → "this entry does not exist yet" + the invitation to create it
//   error   → one quiet line, and NO retry button: this is a hint, and a hint that demands
//             interaction has stopped being a hint
//   ready   → title · status badge · stale flag · first paragraph
import { computed } from 'vue';
import Text from '../../../ui/primitives/Text.vue';
import Badge from '../../../ui/primitives/Badge.vue';
import Icon from '../../../ui/primitives/Icon.vue';
import StatusBadge from '../../../ui/data/StatusBadge.vue';
import Skeleton from '../../../ui/data/Skeleton.vue';
import { entryStatusMap } from '../statusMaps';
import { entryExcerpt } from '../entryMeta';
import { useI18n } from '../../../app/i18n';
import type { KnowledgeEntry, KnowledgeEntryListItem } from '../types';

const props = withDefaults(
  defineProps<{
    /** The entry to describe. Null while loading, or when this is a ghost. */
    entry?: KnowledgeEntryListItem | KnowledgeEntry | null;
    /** The slug that was asked for — the only handle a ghost has. */
    slug?: string;
    /** The target does not exist: render the "red link" invitation instead of a body. */
    ghost?: boolean;
    loading?: boolean;
    /** The lookup failed. Rendered as one line, never as a retry affordance. */
    errored?: boolean;
    /**
     * Reveal the `#actions` slot. OFF for the hover popover (a tooltip must contain nothing
     * focusable); ON for the graph's docked panel, which is a real, reachable surface.
     */
    actions?: boolean;
  }>(),
  { entry: null, slug: '', ghost: false, loading: false, errored: false, actions: false },
);

const { t } = useI18n();
const statusMap = computed(() => entryStatusMap(t));

const excerpt = computed(() => (props.entry ? entryExcerpt(props.entry) : ''));
</script>

<template>
  <div class="flex min-w-0 flex-col gap-next-2">
    <!-- Loading: the shape of the real card, not a spinner. -->
    <template v-if="loading">
      <div class="flex flex-col gap-next-2" role="status" :aria-label="t('knowledge.common.loadingLabel')">
        <Skeleton variant="text" width="60%" />
        <Skeleton variant="text" width="100%" />
        <Skeleton variant="text" width="85%" />
      </div>
    </template>

    <!-- Ghost: an invitation, not an error. -->
    <template v-else-if="ghost">
      <div class="flex items-center gap-next-2">
        <Icon name="unlink" class="shrink-0 text-next-sm text-next-danger" aria-hidden="true" />
        <Text variant="ui" class="font-next-medium">{{ t('knowledge.reader.ghost.previewTitle') }}</Text>
      </div>
      <Text variant="caption" tone="muted">{{ t('knowledge.reader.ghost.previewHint') }}</Text>
      <Text v-if="slug" variant="caption" tone="muted" class="font-next-mono">{{ slug }}</Text>
    </template>

    <!-- Error: one line. No retry — see the header. -->
    <template v-else-if="errored || !entry">
      <Text variant="caption" tone="muted">{{ t('knowledge.reader.preview.error') }}</Text>
    </template>

    <!-- Ready. -->
    <template v-else>
      <div class="flex min-w-0 items-start gap-next-2">
        <Icon name="file-text" class="mt-next-0_5 shrink-0 text-next-sm text-next-muted-foreground" aria-hidden="true" />
        <Text variant="ui" class="min-w-0 flex-1 font-next-medium" :clamp="2">{{ entry.title }}</Text>
      </div>

      <div class="flex flex-wrap items-center gap-next-1_5">
        <StatusBadge
          v-if="entry.status"
          :status="entry.status"
          :status-map="statusMap"
          size="sm"
        />
        <Badge v-if="entry.is_stale" variant="warning" tone="subtle" icon="alert-triangle" size="sm">
          {{ t('knowledge.reader.stale') }}
        </Badge>
      </div>

      <Text v-if="excerpt" variant="caption" tone="muted" :clamp="4">{{ excerpt }}</Text>
    </template>

    <!-- Only a host that opted in gets interactive content (the graph's side panel, B5b).
         Shared by the READY and the GHOST states, because the graph panel needs to act on both:
         a ghost's whole point is the "create this entry" invitation, and burying that behind a
         state that has no actions would make a red link in the graph a dead end. Loading and
         error stay actionless — a skeleton with buttons is a lie, and this card is a hint. -->
    <div
      v-if="actions && $slots.actions && !loading && (ghost || (!!entry && !errored))"
      class="flex flex-wrap items-center gap-next-2 pt-next-1"
    >
      <slot name="actions" />
    </div>
  </div>
</template>
