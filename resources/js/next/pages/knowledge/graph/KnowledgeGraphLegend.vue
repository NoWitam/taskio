<script setup lang="ts">
// KnowledgeGraphLegend — what the four line patterns MEAN.
//
// Mandatory, never collapsible, always visible. The canvas distinguishes edge kinds by DASH
// PATTERN rather than by colour (colour is reserved for state: selected / hovered), and a dash
// pattern is a code — a code with no key is decoration. The legend is that key.
//
// Each row draws the real thing: a 24×2 sample rendered with the same classes the canvas uses, so
// the legend cannot drift from the picture it explains without the sample changing too.
import { computed } from 'vue';
import Text from '../../../ui/primitives/Text.vue';
import { useI18n } from '../../../app/i18n';
import { ICONS } from '../../../ui/primitives/icons';
import { LEGEND_EDGE_KINDS, type GraphEdgeKind } from './knowledgeGraphLayout';

defineProps<{
  /** Drawn counts per kind — the legend doubles as the census of what is on screen. */
  counts?: Partial<Record<GraphEdgeKind, number>>;
  /**
   * Add the NODE-SHAPE rows the composer's preview needs (a draft's doubled outline). Off in the
   * base graph, which draws no drafts — a legend row for something that cannot appear is noise.
   */
  draftShape?: boolean;
  /** Add the "amended" node row (a pencil glyph) — the composer's preview only. */
  amendedShape?: boolean;
}>();

const { t } = useI18n();

/** The same pencil the canvas draws on an amended node. */
const pencil = ICONS.pencil;

const rows = computed(() =>
  LEGEND_EDGE_KINDS.map((kind) => ({ kind, label: t(`knowledge.graph.edge.${kind}`) })),
);

/** Where the sample line stops, so it does not run underneath its own end marker. */
function lineEnd(kind: GraphEdgeKind): number {
  if (kind === 'ghost') return 16; // stops short of the hollow target circle
  if (kind === 'wikilink' || kind === 'mention' || kind === 'relation') return 19; // stops at the arrowhead
  return 24;
}
</script>

<template>
  <div class="flex flex-wrap items-center gap-next-3" :aria-label="t('knowledge.graph.legend')" role="group">
    <div v-for="row in rows" :key="row.kind" class="flex items-center gap-next-1_5">
      <svg
        width="24"
        height="8"
        viewBox="0 0 24 8"
        aria-hidden="true"
        focusable="false"
        class="shrink-0 overflow-visible"
      >
        <line
          x1="0"
          y1="4"
          :x2="lineEnd(row.kind)"
          y2="4"
          class="next-kg-legend-line"
          :class="`is-${row.kind}`"
          :data-kind="row.kind"
        />
        <!-- The ghost sample ends in the same hollow, dashed circle the canvas draws. -->
        <circle v-if="row.kind === 'ghost'" cx="20" cy="4" r="3" class="next-kg-legend-ghost" />
        <!-- The manual sample carries the same mid-line square badge. -->
        <rect v-else-if="row.kind === 'manual'" x="10.5" y="2.5" width="3" height="3" class="next-kg-legend-badge" />
        <!-- DIRECTED kinds get the arrowhead they are drawn with, so the legend does not quietly
             describe a relation as symmetric when the canvas draws it pointing. -->
        <path
          v-else-if="row.kind === 'wikilink' || row.kind === 'mention' || row.kind === 'relation'"
          d="M19 1.5 L24 4 L19 6.5 z"
          class="next-kg-legend-arrow"
          :class="row.kind === 'relation' ? 'is-relation' : ''"
        />
      </svg>

      <Text variant="caption" tone="muted">
        {{
          counts && counts[row.kind] != null
            ? t('knowledge.graph.edge.count', '', { label: row.label, count: counts[row.kind] as number })
            : row.label
        }}
      </Text>
    </div>

    <!-- NODE shapes, for the composer's preview: the doubled outline that marks a proposal. -->
    <div v-if="draftShape" class="flex items-center gap-next-1_5" data-legend-draft>
      <svg width="18" height="18" viewBox="0 0 18 18" aria-hidden="true" focusable="false" class="shrink-0">
        <circle cx="9" cy="9" r="8" class="next-kg-legend-draft-ring" />
        <circle cx="9" cy="9" r="5" class="next-kg-legend-draft" />
      </svg>
      <Text variant="caption" tone="muted">{{ t('knowledge.compose.nodeDraft') }}</Text>
    </div>

    <div v-if="amendedShape" class="flex items-center gap-next-1_5" data-legend-amended>
      <svg width="18" height="18" viewBox="0 0 18 18" aria-hidden="true" focusable="false" class="shrink-0">
        <circle cx="10" cy="9" r="5" class="next-kg-legend-entry" />
        <!-- The same pencil the canvas hangs off an amended node. -->
        <g transform="translate(0 7) scale(0.36)" class="next-kg-legend-amended" v-html="pencil" />
      </svg>
      <Text variant="caption" tone="muted">{{ t('knowledge.compose.nodeAmended') }}</Text>
    </div>
  </div>
</template>

<style scoped>
/* The SAME geometry the canvas uses (see KnowledgeGraphCanvas): pattern carries the TYPE, so a
   reader who cannot separate the colours still reads four different relations. */
.next-kg-legend-line {
  stroke: var(--color-next-muted-foreground);
  stroke-linecap: round;
}
.next-kg-legend-line.is-wikilink {
  stroke-width: 1.5;
}
.next-kg-legend-line.is-similarity {
  stroke-width: 1.5;
  stroke-dasharray: 2 3;
}
.next-kg-legend-line.is-mention {
  stroke-width: 1.5;
  stroke-dasharray: 7 2 1.5 2;
}
.next-kg-legend-line.is-manual {
  stroke-width: 2;
}
/* The heaviest line, and the ONLY one drawn in the foreground colour: `relation` is the one
   layer a human authored the meaning of. It shares "solid" with `manual`; what separates the two
   is weight, the arrowhead, and above all the LABEL, which `manual` never carries. The legend
   says so in words next to this sample. */
.next-kg-legend-line.is-relation {
  stroke-width: 2.5;
  stroke: var(--color-next-fg);
}
.next-kg-legend-line.is-ghost {
  stroke-width: 1.25;
  stroke-dasharray: 5 4;
}
.next-kg-legend-ghost {
  fill: none;
  stroke: var(--color-next-danger);
  stroke-width: 1.25;
  stroke-dasharray: 3 3;
}
.next-kg-legend-badge {
  fill: var(--color-next-muted-foreground);
}
.next-kg-legend-arrow {
  fill: var(--color-next-muted-foreground);
}
/* Matches the heavier, foreground-coloured relation line the sample sits on. */
.next-kg-legend-arrow.is-relation {
  fill: var(--color-next-fg);
}
/* The same doubled outline the canvas draws for a proposal. */
.next-kg-legend-draft {
  fill: var(--color-next-modified-subtle);
  stroke: var(--color-next-modified);
  stroke-width: 1;
}
.next-kg-legend-draft-ring {
  fill: none;
  stroke: var(--color-next-modified);
  stroke-width: 1;
}
.next-kg-legend-entry {
  fill: var(--color-next-primary-subtle);
  stroke: var(--color-next-border);
  stroke-width: 1;
}
.next-kg-legend-amended {
  fill: none;
  stroke: var(--color-next-modified);
  stroke-width: 2.5;
  stroke-linecap: round;
  stroke-linejoin: round;
}
</style>
