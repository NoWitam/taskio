<script setup lang="ts">
// KnowledgeGraphNeighbourList — the graph, as something you can actually READ and OPERATE.
//
// The canvas is `aria-hidden` by design (see KnowledgeGraphCanvas). This list is not a fallback
// or a courtesy: it is the primary surface for keyboard and screen-reader users, and on a narrow
// screen it is what everyone sees first. It therefore carries the SAME information as a dot —
// title, relation IN WORDS, score, editorial status, staleness — and drives the SAME state.
//
// The keyboard model is `ui/variables/VariableBrowser.vue`'s, deliberately unchanged:
//   • exactly ONE tab stop (the container), with a VIRTUAL cursor via `aria-activedescendant`;
//     DOM focus never hops between rows, so Tab always leaves the widget in one press,
//   • ↑/↓ move (clamped), Home/End jump, printable characters type-ahead with a 600 ms buffer,
//   • `scrollIntoView({ block: 'nearest' })` looked up BY ID, never by a template ref.
//
// Enter RE-CENTRES (the keyboard equivalent of double-clicking a dot) and Space SELECTS (opens
// the side panel without navigating) — the same split the mouse has, so neither input method can
// do something the other cannot.
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import Text from '../../../ui/primitives/Text.vue';
import Badge from '../../../ui/primitives/Badge.vue';
import Button from '../../../ui/primitives/Button.vue';
import Icon from '../../../ui/primitives/Icon.vue';
import StatusBadge, { type StatusMap } from '../../../ui/data/StatusBadge.vue';
import Skeleton from '../../../ui/data/Skeleton.vue';
import { useI18n } from '../../../app/i18n';
import KnowledgeRelationSentence from '../relations/KnowledgeRelationSentence.vue';
import { entryTypeLabel } from '../relations/relationLabels';
import { groupNeighbours, type GraphNeighbour, type GraphNeighbourGroup } from './graphNeighbours';

const props = withDefaults(
  defineProps<{
    rows: GraphNeighbour[];
    /**
     * The title of the FOCUS entry — the subject of every relation sentence in this list.
     *
     * It has to be passed in: a row knows its neighbour, not the entry the neighbourhood is about,
     * and a sentence with no subject would read "[blank] is a member of Acme".
     */
    focusTitle?: string;
    /** Placement id of the selected node — mirrored from the canvas, `aria-selected` here. */
    selectedId?: string | null;
    /** Editorial-status descriptors (the module's shared `entryStatusMap`). */
    statusMap: StatusMap;
    loading?: boolean;
    /** Accessible name of the listbox. */
    label?: string;
    /**
     * Rows that are PROPOSALS (the composer's relations preview). Said in WORDS here — the canvas
     * carries the same fact as a doubled outline, and this list is the surface that must not rely
     * on a shape at all.
     */
    draftIds?: Set<string>;
    /**
     * Existing entries with a PENDING AMENDMENT. Said in WORDS here, with a way to reach the
     * proposal — the canvas can only draw a glyph, and a glyph is not a route.
     */
    amendedIds?: Set<string>;
  }>(),
  {
    selectedId: null,
    loading: false,
    label: undefined,
    draftIds: () => new Set<string>(),
    amendedIds: () => new Set<string>(),
  },
);

const emit = defineEmits<{
  /** Cursor landed on a row (mouse or keyboard) — open the side panel on it. */
  select: [string];
  /** Enter / double click — re-centre the graph on this row. */
  activate: [string];
  /** Escape — drop the selection. */
  clear: [];
  /** "Show me the amendment" — the proposal is a card on the board, not another page. */
  'open-amendment': [string];
}>();

const { t } = useI18n();

const rootRef = ref<HTMLElement | null>(null);
const bodyRef = ref<HTMLElement | null>(null);
const baseId = `next-kg-list-${Math.random().toString(36).slice(2, 8)}`;

/** Selector-safe row id — a slug is `[a-z0-9-]`, but a ghost id carries a colon. */
function rowId(id: string): string {
  return `${baseId}-row-${id.replace(/[^\w-]/g, '_')}`;
}

const groups = computed(() => groupNeighbours(props.rows));
/** The flat walk order — what ↑/↓ and type-ahead move through, groups included. */
const flat = computed(() => groups.value.flatMap((bucket) => bucket.rows));

const activeId = ref<string | null>(null);

// Keep the cursor real: a feed change (re-centre, filter) that removes the active row re-seeds it
// on the selection, then on the first row — never on nothing.
watch(
  [flat, () => props.selectedId],
  () => {
    if (activeId.value && flat.value.some((row) => row.id === activeId.value)) return;
    const fallback = props.selectedId && flat.value.some((row) => row.id === props.selectedId)
      ? props.selectedId
      : (flat.value[0]?.id ?? null);
    activeId.value = fallback;
  },
  { immediate: true },
);

const activeDescendantId = computed(() => (activeId.value ? rowId(activeId.value) : undefined));

function setActive(id: string): void {
  activeId.value = id;
  nextTick(scrollActiveIntoView);
}

function scrollActiveIntoView(): void {
  const id = activeDescendantId.value;
  const row = id ? rootRef.value?.querySelector<HTMLElement>(`#${id}`) : null;
  row?.scrollIntoView?.({ block: 'nearest' });
}

function move(delta: number): void {
  const list = flat.value;
  if (list.length === 0) return;
  const from = list.findIndex((row) => row.id === activeId.value);
  const next = Math.max(0, Math.min((from < 0 ? 0 : from) + delta, list.length - 1));
  setActive(list[next].id);
}

let typeAheadBuffer = '';
let typeAheadTimer: ReturnType<typeof setTimeout> | undefined;

function typeAhead(char: string): void {
  typeAheadBuffer += char.toLowerCase();
  if (typeAheadTimer) clearTimeout(typeAheadTimer);
  typeAheadTimer = setTimeout(() => (typeAheadBuffer = ''), 600);

  const list = flat.value;
  if (list.length === 0) return;
  const start = Math.max(0, list.findIndex((row) => row.id === activeId.value));
  for (let step = 1; step <= list.length; step += 1) {
    const row = list[(start + step) % list.length]; // wraps
    if ((row.title || row.slug).toLowerCase().startsWith(typeAheadBuffer)) {
      setActive(row.id);
      return;
    }
  }
}

onBeforeUnmount(() => {
  if (typeAheadTimer) clearTimeout(typeAheadTimer);
});

function onKeydown(event: KeyboardEvent): void {
  if (event.metaKey || event.ctrlKey || event.altKey) return;

  switch (event.key) {
    case 'ArrowDown':
      event.preventDefault();
      move(1);
      return;
    case 'ArrowUp':
      event.preventDefault();
      move(-1);
      return;
    case 'Home':
      event.preventDefault();
      if (flat.value.length) setActive(flat.value[0].id);
      return;
    case 'End':
      event.preventDefault();
      if (flat.value.length) setActive(flat.value[flat.value.length - 1].id);
      return;
    case 'Enter':
      event.preventDefault();
      if (activeId.value) emit('activate', activeId.value);
      return;
    case ' ':
      event.preventDefault();
      if (activeId.value) emit('select', activeId.value);
      return;
    case 'Escape':
      event.preventDefault();
      emit('clear');
      return;
    default:
      if (event.key.length === 1) {
        event.preventDefault();
        typeAhead(event.key);
      }
  }
}

function onRowClick(row: GraphNeighbour): void {
  setActive(row.id);
  emit('select', row.id);
}

/** The relation, IN WORDS — the list never leaves a relation to a dash pattern. */
function groupLabel(group: GraphNeighbourGroup): string {
  // `entry` is the no-focus bucket (the overview lists every node, not a relation).
  return group === 'entry'
    ? t('knowledge.graph.group.entry')
    : t(`knowledge.graph.edge.${group}`);
}

function scorePercent(score: number | null): number | null {
  return score == null ? null : Math.round(score * 100);
}

function focus(): void {
  bodyRef.value?.focus({ preventScroll: true });
  nextTick(scrollActiveIntoView);
}

defineExpose({ focus });
</script>

<template>
  <div ref="rootRef" class="flex min-h-0 min-w-0 flex-col gap-next-2">
    <!-- Loading: rows shaped like real rows, several of them. -->
    <div
      v-if="loading"
      class="flex flex-col gap-next-2"
      role="status"
      :aria-label="t('knowledge.graph.loadingLabel')"
    >
      <div v-for="row in 5" :key="`s-${row}`" class="flex items-center gap-next-2 px-next-2 py-next-1_5">
        <Skeleton variant="circle" diameter="0.75rem" />
        <Skeleton variant="text" :width="`${70 - row * 6}%`" />
      </div>
    </div>

    <p v-else-if="rows.length === 0" class="px-next-2 py-next-4 text-next-sm text-next-muted-foreground">
      {{ t('knowledge.graph.neighboursEmpty') }}
    </p>

    <!-- The single tab stop. The cursor is VIRTUAL: `aria-activedescendant` names the row and DOM
         focus stays here, so Tab leaves the list in one press however long it is. -->
    <div
      v-else
      ref="bodyRef"
      tabindex="0"
      role="listbox"
      class="next-kg-list min-h-0 flex-1 overflow-y-auto outline-none"
      :aria-label="label ?? t('knowledge.graph.neighboursLabel')"
      :aria-activedescendant="activeDescendantId"
      data-graph-neighbours
      @keydown="onKeydown"
    >
      <div
        v-for="bucket in groups"
        :key="bucket.group"
        role="group"
        :aria-label="groupLabel(bucket.group)"
        class="mb-next-2 last:mb-0"
      >
        <Text variant="caption" tone="muted" class="block px-next-2 py-next-1 font-next-medium">
          {{ t('knowledge.graph.edge.count', '', { label: groupLabel(bucket.group), count: bucket.rows.length }) }}
        </Text>

        <div
          v-for="row in bucket.rows"
          :id="rowId(row.id)"
          :key="row.rowId"
          role="option"
          :aria-selected="row.id === selectedId"
          class="next-kg-row"
          :class="[row.id === activeId ? 'is-active' : '', row.isGhost ? 'is-ghost' : '']"
          :data-row-id="row.id"
          @mouseenter="setActive(row.id)"
          @click="onRowClick(row)"
          @dblclick="emit('activate', row.id)"
        >
          <Icon
            :name="row.isGhost ? 'unlink' : 'file-text'"
            class="shrink-0 text-next-sm"
            :class="row.isGhost ? 'text-next-danger' : 'text-next-muted-foreground'"
            aria-hidden="true"
          />

          <!--
            A RELATION row says the whole statement, not just the neighbour's name. The canvas is
            `aria-hidden`, and the relation layer is the only one that carries meaning in an edge
            LABEL rather than in a line pattern — so if the sentence did not appear here, that
            meaning would be content available to sighted users alone. The verb is read from the
            focus entry's side, which is what `row.direction` carries.
          -->
          <KnowledgeRelationSentence
            v-if="row.group === 'relation'"
            class="min-w-0 flex-1"
            :subject="{ title: focusTitle ?? '', entry_type: null }"
            :object="{ title: row.title || row.slug, entry_type: row.entryType }"
            :relation-type="row.relationType"
            :label="row.label"
            :inverse-label="row.inverseLabel"
            :symmetric="row.symmetric"
            :direction="row.direction"
            :valid-from="row.validFrom"
            :valid-to="row.validTo"
            :muted="row.historical"
            size="sm"
          />
          <span v-else class="min-w-0 flex-1 truncate">{{ row.title || row.slug }}</span>

          <!-- The neighbour's KIND, as text. Never on the node — seven categories are unreadable
               at 12px, and shape is already spoken for by ghost / draft / amended. -->
          <Badge v-if="row.entryType" variant="neutral" tone="subtle" size="sm" data-entry-type>
            {{ entryTypeLabel(row.entryType) }}
          </Badge>

          <!-- State in WORDS: an ended relation must not depend on a muted stroke to be legible. -->
          <Badge
            v-if="row.group === 'relation'"
            :variant="row.historical ? 'neutral' : 'success'"
            tone="subtle"
            :icon="row.historical ? 'clock' : undefined"
            size="sm"
            data-relation-state
          >
            {{
              row.historical
                ? t('knowledge.relations.ended', '', { year: (row.validTo ?? '').slice(0, 4) })
                : t('knowledge.relations.active')
            }}
          </Badge>

          <!-- "This one does not exist yet", in a word. -->
          <Badge v-if="draftIds.has(row.id)" variant="modified" tone="subtle" size="sm" data-draft-badge>
            {{ t('knowledge.compose.nodeDraft') }}
          </Badge>

          <!-- "This one is about to change" — with the way to the proposal that changes it. The
               canvas can only draw a pencil; a list row can say it and take you there. -->
          <Badge
            v-if="amendedIds.has(row.id)"
            variant="modified"
            tone="subtle"
            icon="pencil"
            size="sm"
            data-amended-badge
          >
            {{ t('knowledge.compose.nodeAmended') }}
          </Badge>
          <Button
            v-if="amendedIds.has(row.id)"
            variant="link"
            size="xs"
            data-open-amendment
            :aria-label="`${t('knowledge.compose.openAmendment')}: ${row.title || row.slug}`"
            @click.stop="emit('open-amendment', row.id)"
          >
            {{ t('knowledge.compose.openAmendment') }}
          </Button>

          <!-- Everything a dot encodes visually, spelled out. -->
          <Text v-if="scorePercent(row.score) != null" variant="caption" tone="muted" class="shrink-0 tabular-nums">
            {{ t('knowledge.similar.score', '', { percent: scorePercent(row.score) as number }) }}
          </Text>

          <Badge v-if="row.isGhost" variant="danger" tone="subtle" size="sm">
            {{ t('knowledge.graph.ghostSources', '', { count: row.ghostCount }) }}
          </Badge>

          <StatusBadge
            v-else-if="row.status"
            :status="row.status"
            :status-map="statusMap"
            size="sm"
            class="shrink-0"
          />

          <Badge v-if="row.isStale" variant="warning" tone="subtle" icon="alert-triangle" size="sm">
            {{ t('knowledge.reader.stale') }}
          </Badge>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.next-kg-list {
  max-height: 28rem;
  padding: var(--spacing-next-1);
}

.next-kg-row {
  display: flex;
  align-items: center;
  gap: var(--spacing-next-2);
  padding-block: var(--spacing-next-1_5);
  padding-inline: var(--spacing-next-2);
  border-radius: var(--radius-next-sm);
  font-size: var(--text-next-sm);
  color: var(--color-next-fg);
  cursor: pointer;
  user-select: none;
}
/* The virtual cursor — the same accent wash VariableBrowser uses, so the two feel identical. */
.next-kg-row.is-active {
  background-color: var(--color-next-accent);
  color: var(--color-next-accent-foreground);
}
.next-kg-row[aria-selected='true']:not(.is-active) {
  background-color: color-mix(in srgb, var(--color-next-accent) 55%, transparent);
}
</style>
