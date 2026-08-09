<script setup lang="ts">
// KnowledgeGraphCanvas — the picture. An SVG over a layout that was already computed.
//
// It owns exactly two things: the VIEWPORT (pan / zoom / fit, as a `viewBox`) and the DRAWING.
// It computes no positions (that is `knowledgeGraphLayout`, a pure function), fetches nothing, and
// makes no routing decisions — it emits what the user pointed at and the view decides.
//
// ── ACCESSIBILITY (D9) ───────────────────────────────────────────────────────
// The whole `<svg>` is `aria-hidden` and `focusable="false"`. This is deliberate and is NOT a
// shortcut: a graph is a spatial illustration, and no amount of ARIA turns "a dot up and to the
// left" into something a screen reader can convey. The SAME content — every node, its relation,
// its score, its status — is rendered as a real list next to it (KnowledgeGraphNeighbourList),
// which is the surface keyboard and AT users navigate. Zoom controls are real `<button>`s OUTSIDE
// the SVG, so nothing here is the only route to anything.
//
// ── COLOUR IS NEVER THE SIGNAL ───────────────────────────────────────────────
// Line PATTERN carries the edge kind (solid / dotted / solid-with-badge / dashed-to-hollow), and
// colour carries STATE (resting vs. touching the selection). Never the other way round, and never
// both at once — that is what makes the picture readable in dark mode, in greyscale, and to the
// ~8 % of men who cannot separate the two accent hues.
import { computed, ref, watch } from 'vue';
import { ICONS, type IconName } from '../../../ui/primitives/icons';
import { useI18n } from '../../../app/i18n';
import type { KnowledgeEntryStatus } from '../types';
import { predicateLabel } from '../relations/relationLabels';
import type { GraphEdgePlacement, GraphLayout, GraphNodePlacement } from './knowledgeGraphLayout';

const props = withDefaults(
  defineProps<{
    layout: GraphLayout;
    /** Placement id of the selected node (side panel is open on it). */
    selectedId?: string | null;
    /** Status → icon, taken from the module's shared `entryStatusMap` so the badge cannot drift. */
    statusIcons?: Partial<Record<KnowledgeEntryStatus, IconName>>;
    /**
     * Placement ids that are PROPOSALS, not existing entries (the composer's relations preview).
     * Drawn with a DOUBLED outline as well as the `modified` hue — the shape is the signal, so a
     * draft is still distinguishable in greyscale. Empty for the base graph, which has no drafts.
     */
    draftIds?: Set<string>;
    /**
     * Existing entries with a PENDING AMENDMENT against them (the composer's preview). Marked with
     * a GLYPH, not a hue — and a shadow draft is never a node or an edge of its own, so this
     * annotation is the only place the picture can say the entry is about to change.
     */
    amendedIds?: Set<string>;
    /** Accessible label for the wrapper (the SVG itself is hidden from AT). */
    label?: string;
    /** A re-centre is in flight: dim, do not unmount — the picture must not blink. */
    busy?: boolean;
  }>(),
  {
    selectedId: null,
    statusIcons: () => ({}),
    draftIds: () => new Set<string>(),
    amendedIds: () => new Set<string>(),
    label: undefined,
    busy: false,
  },
);

const emit = defineEmits<{
  /** A single click / tap on a node — select it (no navigation). */
  select: [string];
  /** A double click on a node — re-centre the graph there. */
  activate: [string];
}>();

const { t } = useI18n();

// --- Viewport ---------------------------------------------------------------
// One reactive rectangle in WORLD units. Zoom scales it around a point; pan translates it.

interface Box {
  x: number;
  y: number;
  w: number;
  h: number;
}

const box = ref<Box>(toBox(props.layout.fit));
const svgRef = ref<SVGSVGElement | null>(null);

function toBox(fit: GraphLayout['fit']): Box {
  return { x: fit.x, y: fit.y, w: fit.width, h: fit.height };
}

/** Zoom is expressed against the fit box, so `1×` always means "everything is on screen". */
const MIN_ZOOM = 0.5;
const MAX_ZOOM = 4;
const zoom = computed(() => props.layout.fit.width / box.value.w);

// A NEW layout (re-centre, depth change, filter change) resets the viewport — keeping the old pan
// would leave the user staring at empty canvas where the previous neighbourhood used to be.
watch(
  () => props.layout,
  (layout) => {
    box.value = toBox(layout.fit);
  },
);

function fit(): void {
  box.value = toBox(props.layout.fit);
}

/** Scale around a world point, clamped to the zoom range. */
function zoomBy(factor: number, anchor?: { x: number; y: number }): void {
  const current = zoom.value;
  const next = Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, current * factor));
  if (next === current) return;
  const scale = current / next; // > 1 zooms out
  const at = anchor ?? { x: box.value.x + box.value.w / 2, y: box.value.y + box.value.h / 2 };
  const w = box.value.w * scale;
  const h = box.value.h * scale;
  box.value = {
    // Keep the anchor pinned under the cursor: its relative position in the box is preserved.
    x: at.x - (at.x - box.value.x) * scale,
    y: at.y - (at.y - box.value.y) * scale,
    w,
    h,
  };
}

/** Client (px) → world (viewBox) coordinates. */
function toWorld(event: PointerEvent | WheelEvent): { x: number; y: number } {
  const rect = svgRef.value?.getBoundingClientRect();
  if (!rect || rect.width === 0 || rect.height === 0) return { x: 0, y: 0 };
  // `preserveAspectRatio="xMidYMid meet"` letterboxes: the drawn scale is the SMALLER of the two.
  const scale = Math.min(rect.width / box.value.w, rect.height / box.value.h);
  const drawnW = box.value.w * scale;
  const drawnH = box.value.h * scale;
  const offsetX = (rect.width - drawnW) / 2;
  const offsetY = (rect.height - drawnH) / 2;
  return {
    x: box.value.x + (event.clientX - rect.left - offsetX) / scale,
    y: box.value.y + (event.clientY - rect.top - offsetY) / scale,
  };
}

function onWheel(event: WheelEvent): void {
  event.preventDefault();
  zoomBy(event.deltaY < 0 ? 1.15 : 1 / 1.15, toWorld(event));
}

// --- Pan --------------------------------------------------------------------
const panning = ref(false);
let panFrom: { x: number; y: number } | null = null;
let panBox: Box | null = null;
/** A drag that happened to end on a node is a PAN, not a click on that node. */
let dragged = false;
const DRAG_SLOP = 4;

function onPointerDown(event: PointerEvent): void {
  if (event.button !== 0) return;
  panFrom = { x: event.clientX, y: event.clientY };
  panBox = { ...box.value };
  panning.value = true;
  dragged = false;
  (event.currentTarget as SVGSVGElement).setPointerCapture?.(event.pointerId);
}

function onPointerMove(event: PointerEvent): void {
  if (!panning.value || !panFrom || !panBox) return;
  const rect = svgRef.value?.getBoundingClientRect();
  if (!rect || rect.width === 0 || rect.height === 0) return;
  if (Math.abs(event.clientX - panFrom.x) > DRAG_SLOP || Math.abs(event.clientY - panFrom.y) > DRAG_SLOP) {
    dragged = true;
  }
  const scale = Math.min(rect.width / panBox.w, rect.height / panBox.h);
  box.value = {
    ...panBox,
    x: panBox.x - (event.clientX - panFrom.x) / scale,
    y: panBox.y - (event.clientY - panFrom.y) / scale,
  };
}

function onPointerUp(event: PointerEvent): void {
  panning.value = false;
  panFrom = null;
  panBox = null;
  (event.currentTarget as SVGSVGElement).releasePointerCapture?.(event.pointerId);
}

// --- Hover / selection ------------------------------------------------------
const hoveredId = ref<string | null>(null);

/** A click only selects when the pointer did not travel — otherwise the user was panning. */
function onNodeClick(id: string): void {
  if (dragged) return;
  emit('select', id);
}

/** Placement ids touching the selection or the pointer — what "colour = state" applies to. */
const activeIds = computed(() => {
  const ids = new Set<string>();
  if (props.selectedId) ids.add(props.selectedId);
  if (hoveredId.value) ids.add(hoveredId.value);
  return ids;
});

function edgeIsActive(edge: GraphEdgePlacement): boolean {
  return activeIds.value.has(edge.from) || activeIds.value.has(edge.to);
}

/**
 * How many relation edges may be labelled before the labels become noise.
 *
 * Past this, only the edge under the cursor or in the selection is labelled. At the 60-node cap a
 * dense base can draw far more relations than fit as text, and the result is a plate of overlapping
 * words that hides the graph it was meant to explain. The state is ANNOUNCED under the canvas
 * rather than applied silently — a picture that quietly stops labelling is a picture the reader
 * believes has no relations.
 */
const EDGE_LABEL_BUDGET = 20;

/** Relation labels are short by necessity; longer verbs are cut rather than allowed to overlap. */
const EDGE_LABEL_MAX_CHARS = 16;

const relationEdgeCount = computed(
  () => props.layout.edges.filter((edge) => edge.kind === 'relation').length,
);

/** True while the density rule is suppressing labels — the host renders the explanation. */
const edgeLabelsSuppressed = computed(() => relationEdgeCount.value > EDGE_LABEL_BUDGET);

/**
 * The text drawn on a relation edge, or null.
 *
 * A HISTORICAL relation carries its end year in the label ("member of (to 2026)"), so the fact
 * that it is over survives being read by someone who cannot tell the muted stroke from the
 * ordinary one. State never rests on colour alone.
 */
function edgeLabel(edge: GraphEdgePlacement): string | null {
  if (edge.kind !== 'relation') return null;
  if (edgeLabelsSuppressed.value && !edgeIsActive(edge)) return null;

  const verb = predicateLabel(edge.relationType, 'forward', edge.label);
  if (!verb) return null;

  const year = edge.historical && edge.validTo ? edge.validTo.slice(0, 4) : null;
  const suffix = year ? ` (${t('knowledge.relations.until', '', { date: year })})` : '';

  // TRUNCATE THE VERB, THEN APPEND THE YEAR — never the whole string.
  //
  // Truncating the joined text cut the year off first, because it is at the end: "is a member of
  // (until 2026)" became "is a member of …" and the one signal that the claim is OVER disappeared,
  // leaving an edge that reads as current and is merely greyer. The verb can be abbreviated; the
  // state cannot.
  const room = Math.max(4, EDGE_LABEL_MAX_CHARS - suffix.length);
  const head = verb.length > room ? `${verb.slice(0, room - 1)}…` : verb;

  return `${head}${suffix}`;
}

/** Backdrop width, from the same 6.2-units-per-character estimate the node labels use. */
function edgeLabelWidth(edge: GraphEdgePlacement): number {
  return (edgeLabel(edge)?.length ?? 0) * 6.2 + 6;
}

/**
 * The native tooltip on a similarity line: the percentage plus whatever the linker stored as
 * evidence, rendered as `key: value` pairs exactly like the reader's "why similar?" panel.
 *
 * The evidence is a pair of chunk ORDINALS — a citation address. Turning "chunk 3" into
 * "section Pricing" would need the entry's chunk headings, which are not in the graph response
 * and are not worth a request per hovered line, so the honest tooltip is the numbers the server
 * actually stored.
 */
function edgeTitle(edge: GraphEdgePlacement): string | null {
  // A MENTION names itself. Its evidence is `{char_start, char_length}` into the SOURCE entry's
  // content — offsets the graph payload has no text to resolve (nodes carry no content by design),
  // so the honest tooltip is the relation, not two raw numbers. The entry's own links panel, which
  // does hold the content, shows the mentioning words.
  if (edge.kind === 'mention') return t('knowledge.graph.edge.mention');
  if (edge.kind !== 'similarity') return null;

  const parts: string[] = [];
  if (edge.score != null) {
    parts.push(t('knowledge.similar.score', '', { percent: Math.round(edge.score * 100) }));
  }
  for (const [key, value] of Object.entries(edge.evidence ?? {})) {
    parts.push(`${key}: ${Array.isArray(value) ? value.join(', ') : String(value ?? '—')}`);
  }
  return parts.length > 0 ? parts.join(' · ') : null;
}

// --- Labels -----------------------------------------------------------------
//
// AN OCCUPANCY GRID, not a degree threshold.
//
// The old rule was "degree ≥ 3 gets a label", which has no equivalent in any tool that draws
// graphs — Logseq buckets the screen into 132×24 px cells and fills them by rank; Sigma keeps a
// LabelGrid with a deterministic tie-break. A topological threshold answers the wrong question:
// whether a name fits is a matter of SPACE, not of how well connected its entry is, and on a small
// base it hid half the labels precisely when there was room for every one of them.
//
// The property that falls out for free, with no special case in the code: a graph small enough for
// its labels to fit gets ALL of them, because the cells are simply empty.

const LABEL_MAX_CHARS = 24;
/** The backdrop's height in world units — the same number the `<rect>` below is drawn with. */
const LABEL_HEIGHT = 14;
/**
 * Grid cell, in WORLD units.
 *
 * World and not screen units, deliberately. Logseq and Sigma measure in pixels because their
 * labels stay a constant size while the graph scales underneath them; here the whole picture is
 * one SVG `viewBox`, so a label scales WITH the nodes and two labels that overlap at one zoom
 * overlap at every zoom. The occupancy question is therefore zoom-invariant, and reckoning it in
 * screen pixels would mean recomputing an answer that cannot change.
 *
 * The cell is a fraction of a typical label so a name spans several cells: fine enough that two
 * nodes a short distance apart do not share one, coarse enough to stay cheap at the 60-node cap.
 */
const LABEL_CELL_W = 40;
const LABEL_CELL_H = 18;

/** A label is always drawn for these, collision or not: the subject, and whatever is under the pointer. */
function isForced(node: GraphNodePlacement): boolean {
  return node.isCenter || activeIds.value.has(node.id);
}

/**
 * Which nodes get a label — a SPACE budget, recomputed when the layout or the selection changes.
 *
 * Candidates are ordered DETERMINISTICALLY: forced first, then by drawn degree, then by slug. The
 * slug tie-break is what makes this snapshot-testable — without it two equal-degree nodes would
 * race for the same cell and the winner would depend on array order.
 */
const labelledIds = computed<Set<string>>(() => {
  const taken = new Set<string>();
  const granted = new Set<string>();

  const candidates = [...props.layout.nodes].sort(
    (a, b) =>
      Number(isForced(b)) - Number(isForced(a)) ||
      b.degree - a.degree ||
      a.slug.localeCompare(b.slug),
  );

  for (const node of candidates) {
    const halfW = labelWidth(node) / 2;
    const top = node.y + node.r + 3;

    // Every cell this label's backdrop would touch.
    const cells: string[] = [];
    const c0 = Math.floor((node.x - halfW) / LABEL_CELL_W);
    const c1 = Math.floor((node.x + halfW) / LABEL_CELL_W);
    const r0 = Math.floor(top / LABEL_CELL_H);
    const r1 = Math.floor((top + LABEL_HEIGHT) / LABEL_CELL_H);
    for (let c = c0; c <= c1; c += 1) for (let r = r0; r <= r1; r += 1) cells.push(`${c}:${r}`);

    // A forced label draws anyway AND still claims its cells, so nothing else lands under it.
    if (!isForced(node) && cells.some((cell) => taken.has(cell))) continue;

    granted.add(node.id);
    for (const cell of cells) taken.add(cell);
  }

  return granted;
});

function labelVisible(node: GraphNodePlacement): boolean {
  return labelledIds.value.has(node.id);
}

function labelText(node: GraphNodePlacement): string {
  const text = node.title || node.slug;
  return text.length > LABEL_MAX_CHARS ? `${text.slice(0, LABEL_MAX_CHARS - 1)}…` : text;
}

/**
 * Backdrop width for a label. Approximated from the character count rather than measured: a
 * `getBBox()` per node per frame is a layout thrash, and the backdrop only has to be roughly the
 * right size to keep the text off the lines underneath it.
 */
function labelWidth(node: GraphNodePlacement): number {
  return labelText(node).length * 6.2 + 8;
}

// --- Glyphs -----------------------------------------------------------------
/** The status badge glyph (24×24 Lucide-style geometry from the shared registry). */
function statusGlyph(node: GraphNodePlacement): string | null {
  const icon = node.status ? props.statusIcons[node.status] : undefined;
  return icon ? ICONS[icon] : null;
}

function statusTransform(node: GraphNodePlacement): string {
  // Scale the 24-unit glyph down to ~11 world units and hang it off the node's upper right.
  const size = 11;
  const scale = size / 24;
  return `translate(${node.x + node.r * 0.55} ${node.y - node.r - size * 0.55}) scale(${scale})`;
}

/** The pencil that marks an entry with a pending amendment (the same glyph the badge uses). */
const pencilGlyph = ICONS.pencil;

/** Lower-LEFT, so it can never collide with the status glyph in the upper right. */
function amendedTransform(node: GraphNodePlacement): string {
  const size = 11;
  const scale = size / 24;
  return `translate(${node.x - node.r - size * 0.45} ${node.y + node.r * 0.4}) scale(${scale})`;
}

defineExpose({
  fit,
  zoomIn: () => zoomBy(1.25),
  zoomOut: () => zoomBy(1 / 1.25),
  zoom,
  // The host renders the "labels hidden" explanation under the canvas; the canvas owns the rule.
  edgeLabelsSuppressed,
});
</script>

<template>
  <div
    class="next-kg-canvas relative h-full w-full overflow-hidden rounded-next-lg border border-next-border bg-next-card"
    :class="busy ? 'is-busy' : ''"
  >
    <!-- The illustration. Hidden from assistive technology BY DESIGN — the neighbour list beside
         it carries the same content in a form a screen reader can actually walk (D9). -->
    <svg
      ref="svgRef"
      class="next-kg-svg h-full w-full touch-none select-none"
      :class="panning ? 'is-panning' : ''"
      :viewBox="`${box.x} ${box.y} ${box.w} ${box.h}`"
      preserveAspectRatio="xMidYMid meet"
      aria-hidden="true"
      focusable="false"
      @wheel="onWheel"
      @pointerdown="onPointerDown"
      @pointermove="onPointerMove"
      @pointerup="onPointerUp"
      @pointercancel="onPointerUp"
      @pointerleave="hoveredId = null"
    >
      <defs>
        <!-- One arrowhead, inheriting the line's colour through `context-stroke` where supported
             and falling back to the border token everywhere else. -->
        <marker
          id="next-kg-arrow"
          viewBox="0 0 8 8"
          refX="7"
          refY="4"
          markerWidth="5"
          markerHeight="5"
          orient="auto-start-reverse"
        >
          <path d="M0 0 L8 4 L0 8 z" class="next-kg-arrowhead" />
        </marker>
      </defs>

      <!-- EDGES first, so nodes sit on top of the lines that reach them. -->
      <g class="next-kg-edges">
        <g
          v-for="edge in layout.edges"
          :key="edge.id"
          :class="[edgeIsActive(edge) ? 'is-active' : '', edge.historical ? 'is-historical' : '']"
        >
          <!-- The score, and whatever the linker stored as its justification. Built from data the
               response already carries — a "why similar?" that cost another request would be a
               tooltip nobody can afford to hover. -->
          <title v-if="edgeTitle(edge)">{{ edgeTitle(edge) }}</title>
          <line
            :x1="edge.x1"
            :y1="edge.y1"
            :x2="edge.x2"
            :y2="edge.y2"
            class="next-kg-edge"
            :class="[
              `is-${edge.kind}`,
              edgeIsActive(edge) ? 'is-active' : '',
              edge.historical ? 'is-historical' : '',
            ]"
            :data-kind="edge.kind"
            :data-edge-id="edge.id"
            :style="{ strokeWidth: `${edge.width}` }"
            :marker-end="edge.directed ? 'url(#next-kg-arrow)' : undefined"
          />
          <!-- `manual`: a human drew this. A filled square at the midpoint says so without colour. -->
          <rect
            v-if="edge.kind === 'manual'"
            :x="edge.mx - 2.5"
            :y="edge.my - 2.5"
            width="5"
            height="5"
            class="next-kg-edge-badge"
            :data-kind="edge.kind"
          />

          <!--
            THE EDGE LABEL — the fifth layer is the only one that has one, and it is earned: the
            four older kinds each mean exactly one thing, so the line pattern says everything. A
            relation means one of fifteen things, and an unlabelled one says only "there is some
            relation here", which is not worth drawing.

            Always HORIZONTAL. Rotating text along the line looks tidier on a gentle slope and
            becomes unreadable on a steep one, and the reader cannot choose the angle.
          -->
          <template v-if="edgeLabel(edge)">
            <rect
              :x="edge.mx - edgeLabelWidth(edge) / 2"
              :y="edge.my - 6"
              :width="edgeLabelWidth(edge)"
              height="12"
              rx="2"
              class="next-kg-edge-label-bg"
            />
            <text :x="edge.mx" :y="edge.my" class="next-kg-edge-label">{{ edgeLabel(edge) }}</text>
          </template>
        </g>
      </g>

      <!-- NODES. -->
      <g class="next-kg-nodes">
        <g
          v-for="node in layout.nodes"
          :key="node.id"
          class="next-kg-node-group"
          :data-node-id="node.id"
          :data-kind="node.kind"
          :data-status="node.status ?? undefined"
          :data-stale="node.isStale ? 'true' : undefined"
          :data-selected="node.id === selectedId ? 'true' : undefined"
          @pointerenter="hoveredId = node.id"
          @click.stop="onNodeClick(node.id)"
          @dblclick.stop="emit('activate', node.id)"
        >
          <!-- Selection ring: SHAPE (a second, wider circle) as well as colour. -->
          <circle
            v-if="node.id === selectedId"
            :cx="node.x"
            :cy="node.y"
            :r="node.r + 5"
            class="next-kg-node-halo"
          />
          <!-- Stale: a dashed outer ring. A pattern, so "needs a refresh" survives greyscale. -->
          <circle
            v-if="node.isStale"
            :cx="node.x"
            :cy="node.y"
            :r="node.r + 2.5"
            class="next-kg-node-stale"
          />
          <!-- DRAFT: a second, concentric outline. A shape, not just the `modified` hue — the
               neighbour list says "Draft" in words for the same reason. -->
          <circle
            v-if="draftIds.has(node.id)"
            :cx="node.x"
            :cy="node.y"
            :r="node.r + 3"
            class="next-kg-node-draft-ring"
          />
          <circle
            :cx="node.x"
            :cy="node.y"
            :r="node.r"
            class="next-kg-node"
            :class="[
              node.kind === 'ghost' ? 'is-ghost' : 'is-entry',
              node.isCenter ? 'is-center' : '',
              node.id === selectedId ? 'is-selected' : '',
              draftIds.has(node.id) ? 'is-draft' : '',
            ]"
            :data-draft="draftIds.has(node.id) ? 'true' : undefined"
          />

          <!-- Editorial status as the SAME glyph the badges use everywhere else in the module. -->
          <g
            v-if="node.kind === 'entry' && statusGlyph(node)"
            class="next-kg-node-status"
            :transform="statusTransform(node)"
            v-html="statusGlyph(node)"
          />

          <!-- AMENDED: a pencil in the node's lower-left. A glyph rather than a tint, because the
               node already spends its fill and stroke on "draft" and "selected". -->
          <g
            v-if="amendedIds.has(node.id)"
            class="next-kg-node-amended"
            :transform="amendedTransform(node)"
            data-amended
            v-html="pencilGlyph"
          />

          <!-- Label + its backdrop, so text stays readable over the lines it crosses. -->
          <template v-if="labelVisible(node)">
            <rect
              :x="node.x - labelWidth(node) / 2"
              :y="node.y + node.r + 3"
              :width="labelWidth(node)"
              height="14"
              rx="3"
              class="next-kg-label-bg"
            />
            <text :x="node.x" :y="node.y + node.r + 13" class="next-kg-label">{{ labelText(node) }}</text>
          </template>
        </g>
      </g>
    </svg>
  </div>
</template>

<style scoped>
.next-kg-canvas.is-busy .next-kg-svg {
  opacity: 0.55;
  transition: opacity var(--duration-next-fast) var(--ease-next-standard);
}

.next-kg-svg {
  cursor: grab;
}
.next-kg-svg.is-panning {
  cursor: grabbing;
}

/* ── Edges: PATTERN = kind, COLOUR = state ─────────────────────────────────── */
.next-kg-edge {
  stroke: var(--color-next-border);
  stroke-linecap: round;
  fill: none;
}
.next-kg-edge.is-similarity {
  stroke-dasharray: 2 3;
}
/* `mention` — DASH-DOT. Deliberately not "a slightly different dotted": at canvas scale a reader
   separates line patterns by RHYTHM, not by gap size, so the fourth kind gets a rhythm of its own
   (long-short-long) rather than a fourth variation on the same dot. */
.next-kg-edge.is-mention {
  stroke-dasharray: 7 2 1.5 2;
}
.next-kg-edge.is-ghost {
  stroke-dasharray: 5 4;
  stroke: color-mix(in srgb, var(--color-next-danger) 55%, transparent);
}
/* `relation` — the only ASSERTED layer, and the heaviest line in the picture. It shares
   "solid" with `manual`, and what separates them is weight (2.5 from the layout, not from
   here), the arrowhead, and above all the LABEL, which `manual` never has. Colour is left to
   the state rules below, per the module rule: pattern carries KIND, colour carries STATE. */
.next-kg-edge.is-relation {
  stroke: var(--color-next-fg);
}
/* A relation that has ENDED is a claim about the past. It is muted AND its label carries the end
   year, so the state never rests on colour alone. */
.next-kg-edge.is-relation.is-historical {
  stroke: var(--color-next-muted-foreground);
  opacity: 0.5;
}
.next-kg-edge.is-active {
  stroke: var(--color-next-primary);
}
/* Selection wins over the historical mute — otherwise the edge a user just clicked stays grey. */
.next-kg-edge.is-relation.is-historical.is-active {
  stroke: var(--color-next-primary);
  opacity: 1;
}

/* ── Relation edge labels ──────────────────────────────────────────────────── */
.next-kg-edge-label {
  font-size: var(--text-next-2xs);
  fill: var(--color-next-fg);
  dominant-baseline: middle;
  text-anchor: middle;
  pointer-events: none;
}
.next-kg-edge-label-bg {
  fill: var(--color-next-bg);
}
.is-historical .next-kg-edge-label {
  fill: var(--color-next-muted-foreground);
}
.next-kg-arrowhead {
  fill: var(--color-next-border);
}
.next-kg-edge-badge {
  fill: var(--color-next-muted-foreground);
}

/* ── Nodes ─────────────────────────────────────────────────────────────────── */
.next-kg-node {
  fill: var(--color-next-primary-subtle);
  stroke: var(--color-next-border);
  stroke-width: 1;
  cursor: pointer;
}
.next-kg-node.is-center {
  stroke: var(--color-next-primary);
  stroke-width: 2;
}
.next-kg-node.is-selected {
  stroke: var(--color-next-primary);
  stroke-width: 2;
}
/* A ghost is HOLLOW and dashed — the "red link" of a wiki, in shape as well as in colour. */
.next-kg-node.is-ghost {
  fill: none;
  stroke: var(--color-next-danger);
  stroke-dasharray: 3 3;
}
.next-kg-node-halo {
  fill: none;
  stroke: var(--color-next-primary);
  stroke-width: 1;
  opacity: 0.55;
}
.next-kg-node-stale {
  fill: none;
  stroke: var(--color-next-warning);
  stroke-width: 1;
  stroke-dasharray: 2 2;
}
/* A PROPOSAL: doubled outline (the shape) in the `modified` hue (the colour). Either one alone
   would identify it; together they survive greyscale AND a quick glance. */
.next-kg-node.is-draft {
  fill: var(--color-next-modified-subtle);
  stroke: var(--color-next-modified);
}
.next-kg-node-draft-ring {
  fill: none;
  stroke: var(--color-next-modified);
  stroke-width: 1;
}
.next-kg-node-amended {
  fill: none;
  stroke: var(--color-next-modified);
  stroke-width: 2.5;
  stroke-linecap: round;
  stroke-linejoin: round;
  pointer-events: none;
}
.next-kg-node-status {
  fill: none;
  stroke: var(--color-next-muted-foreground);
  stroke-width: 2.5;
  stroke-linecap: round;
  stroke-linejoin: round;
  pointer-events: none;
}

/* ── Labels ────────────────────────────────────────────────────────────────── */
.next-kg-label {
  fill: var(--color-next-fg);
  font-size: var(--text-next-2xs);
  text-anchor: middle;
  pointer-events: none;
}
.next-kg-label-bg {
  fill: var(--color-next-bg);
  opacity: 0.78;
  pointer-events: none;
}
</style>
