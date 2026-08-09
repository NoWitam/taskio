<script setup lang="ts">
// KnowledgeEntryRail — the entry's relation panels: metadata, similar entries, outgoing/incoming
// links, red links, provenance, and the way into version history.
//
// ONE STRUCTURE FOR BOTH LAYOUTS. The spec sketched `Surface` panels in the wide rail and an
// Accordion underneath the article on narrow screens; this renders the Accordion in both places and
// only changes the COLUMN around it. The spec's own binding requirement was that the two layouts
// carry identical content from one component per panel — and the surest way to satisfy that is to
// have one markup path rather than two that must be kept in sync. It also buys `role="region"` +
// `aria-labelledby` per panel for free.
//
// NO LAZY FETCH for "Similar". The spec assumed it needed one; the contract says otherwise —
// similarity edges arrive inside the entry detail's `links` / `backlinks`, already loaded. An
// on-expand request would be a second call for data we hold.
import { computed, ref } from 'vue';
import Accordion from '../../../ui/disclosure/Accordion.vue';
import AccordionItem from '../../../ui/disclosure/AccordionItem.vue';
import DescriptionList, { type DescriptionItem } from '../../../ui/data/DescriptionList.vue';
import EmptyState from '../../../ui/data/EmptyState.vue';
import Skeleton from '../../../ui/data/Skeleton.vue';
import Alert from '../../../ui/feedback/Alert.vue';
import Badge from '../../../ui/primitives/Badge.vue';
import Button from '../../../ui/primitives/Button.vue';
import Link from '../../../ui/primitives/Link.vue';
import Text from '../../../ui/primitives/Text.vue';
import CreatorBadge from '../../../ui/patterns/CreatorBadge.vue';
import KnowledgeSimilarRow from './KnowledgeSimilarRow.vue';
import KnowledgeMentionRow from './KnowledgeMentionRow.vue';
import {
  similarNeighbours,
  mentionNeighbours,
  outgoingWikilinks,
  backlinkSources,
  ghostSlugs,
} from '../entryMeta';
import { formatDate } from '../baseMeta';
import { useI18n } from '../../../app/i18n';
import KnowledgeRelationRow from '../relations/KnowledgeRelationRow.vue';
import type { KnowledgeBase, KnowledgeEntry, KnowledgeRelation } from '../types';

const props = defineProps<{
  entry: KnowledgeEntry;
  base: KnowledgeBase | null;
  busyLink?: boolean;
  /** The entry's typed relations. Fetched by the PARENT — they are not part of the entry payload. */
  relations?: KnowledgeRelation[];
  relationsError?: string | null;
  /** The relations request is in flight — the panel shows its shape rather than jumping. */
  relationsLoading?: boolean;
  busyRelation?: boolean;
}>();

const emit = defineEmits<{
  (e: 'open-entry', slug: string): void;
  (e: 'create-ghost', slug: string): void;
  /**
   * Dismiss / undo a DERIVED edge. The `kind` rides along because the two kinds are dismissed
   * through the same store action but are not the same thing to a user — the confirmation copy
   * has to say which one was rejected.
   */
  (e: 'dismiss-link', linkId: string, kind: 'similarity' | 'mention'): void;
  (e: 'undo-link', linkId: string, kind: 'similarity' | 'mention'): void;
  /**
   * RELATIONS get their OWN verbs. They are deliberately not folded into `dismiss-link`: that
   * emit means "this guess is wrong", which is reversible and low-stakes, while ending a relation
   * is a claim about time and deleting one is irreversible. Sharing an emit would blur exactly the
   * boundary the delete dialog exists to teach.
   */
  /** Re-run the relations fetch after a failure — the whole fix when only the request broke. */
  (e: 'reload-relations'): void;
  (e: 'update:metadataDraft', value: Record<string, unknown>): void;
  (e: 'open-history'): void;
  (e: 'copy-slug'): void;
}>();

const { t } = useI18n();

const similar = computed(() => similarNeighbours(props.entry));
/** Mentions (B10) — kept separate from "Similar": one is what the text SAYS, the other is a guess. */
const mentions = computed(() => mentionNeighbours(props.entry));
const linksOut = computed(() => outgoingWikilinks(props.entry));
const linksIn = computed(() => backlinkSources(props.entry));
const ghosts = computed(() => ghostSlugs(props.entry));

const draft = computed({
  get: () => props.metadataDraft ?? {},
  set: (value) => emit('update:metadataDraft', value),
});

/** Metadata rendered read-only, values resolved through the schema (labels, not raw keys). */
const metadataItems = computed<DescriptionItem[]>(() =>
  (props.base?.metadata_schema ?? []).map((field) => ({
    key: field.key,
    label: field.label || field.key,
    value: presentValue(field.key),
  })),
);

function presentValue(key: string): string | null {
  const field = props.base?.metadata_schema?.find((f) => f.key === key);
  const raw = props.entry.metadata?.[key];
  if (raw == null || raw === '') return null;

  const one = (value: unknown): string => {
    if (typeof value === 'boolean') return value ? t('knowledge.metadata.yes') : t('knowledge.metadata.no');
    const text = String(value);
    // An enum stores a KEY; a human wrote the LABEL. Show what they wrote.
    const option = field?.descriptor?.options?.find((o) => o.key === text);
    return option?.label ?? text;
  };

  return Array.isArray(raw) ? raw.map(one).join(', ') || null : one(raw);
}

const provenanceItems = computed<DescriptionItem[]>(() => [
  { key: 'created', label: t('knowledge.provenance.createdAt'), value: formatDate(props.entry.created_at) },
  { key: 'updated', label: t('knowledge.provenance.updatedAt'), value: formatDate(props.entry.updated_at) },
  { key: 'slug', label: t('knowledge.provenance.slug'), value: props.entry.slug },
]);

/**
 * Which panels start open.
 *
 * `relations` joins `metadata` because it is a PRIMARY channel for reading an entry, not an
 * appendix — a collapsed panel of approved facts would rank them below the machine's guesses,
 * which is the exact inversion this panel's position was chosen to avoid. Red links stay open too,
 * because they are the only panel that is a call to action.
 */
const defaultOpen = computed(() =>
  ghosts.value.length > 0 ? ['metadata', 'relations', 'ghosts'] : ['metadata', 'relations'],
);

/**
 * Ended and retracted relations, split from the live ones.
 *
 * `is_active` is the SERVER's verdict rather than a date comparison here: a relation can be
 * `retracted` with no `valid_to` at all, and "was never true" is not the same claim as "stopped
 * being true", so re-deriving the split from dates would file one of them wrongly.
 */
const relationRows = computed(() => props.relations ?? []);
const activeRelations = computed(() => relationRows.value.filter((row) => row.is_active));
const endedRelations = computed(() => relationRows.value.filter((row) => !row.is_active));

const showEnded = ref(false);
</script>

<template>
  <Accordion type="multiple" :default-value="defaultOpen" class="flex flex-col gap-next-2">
    <!-- Metadata --------------------------------------------------------- -->
    <AccordionItem value="metadata" icon="braces" :title="t('knowledge.panels.metadata')">
      <div class="flex flex-col gap-next-3">
        <template>
          <EmptyState
            v-if="metadataItems.length === 0"
            size="sm"
            icon="braces"
            :title="t('knowledge.metadata.empty')"
          />
          <DescriptionList v-else :items="metadataItems" layout="grid" :columns="1" size="sm" />

        </template>
      </div>
    </AccordionItem>

    <!-- Relations -------------------------------------------------------- -->
    <!--
      DELIBERATELY ABOVE "Similar", against this file's own ordering convention.

      The panels below run derived → literal (similar → mentions → links-out → links-in), so the
      natural home for a new panel would be after `mentions`. It goes first instead, because the
      four panels under it are the MACHINE'S GUESSES and this one is a FACT a person approved. A
      reader should see what is known before what is suspected. The convention is real; this
      outranks it.
    -->
    <AccordionItem value="relations" icon="network" :title="t('knowledge.panels.relations')">
      <div class="flex flex-col gap-next-2">
        <!--
          The count lives in the BODY, not the header. `AccordionItem` takes no `count` prop and
          no `badge` prop, and adding a `#header` slot here would make this the one panel in the
          rail whose heading is built differently. The precedent in this file is the red-links
          panel, which puts its Badge in the body — followed rather than broken.
        -->
        <div class="flex items-center justify-between gap-next-2">
          <Badge v-if="relationRows.length > 0" variant="neutral" tone="subtle" icon="network" size="sm">
            {{ t('knowledge.relations.count', '', { count: relationRows.length }) }}
          </Badge>
          <span v-else />

        </div>

        <!--
          A failure WITH a way out. An alert alone left the reader at a dead end: nothing on screen
          suggested the fetch could simply be run again, so the only move was reloading the page.
          Both sibling panels (`KnowledgeDraftRelationsPanel`, `KnowledgeDraftDiffPanel`) pair the
          alert with a retry, which is what makes this the module's shape rather than an invention.
        -->
        <Alert v-if="relationsError" variant="danger" size="sm" data-relations-error>
          {{ t('knowledge.relations.loadError') }}
          <template #actions>
            <Button
              variant="outline"
              size="sm"
              leading-icon="rotate-ccw"
              data-relations-retry
              @click="emit('reload-relations')"
            >
              {{ t('knowledge.common.retry') }}
            </Button>
          </template>
        </Alert>

        <!--
          LOADING, in the shape of the rows that are coming. Without it the panel went straight from
          "this entry has no relations yet" to a populated list — telling the reader something
          false, briefly, every single time.
        -->
        <div
          v-else-if="relationsLoading"
          class="flex flex-col gap-next-2"
          role="status"
          :aria-label="t('knowledge.common.loadingLabel')"
          data-relations-loading
        >
          <div v-for="n in 3" :key="`rel-sk-${n}`" class="flex items-center gap-next-2">
            <Skeleton variant="text" :width="`${80 - n * 12}%`" />
          </div>
        </div>

        <!--
          Rendered even when EMPTY, like `similar` and `links-out`. A panel that disappears would
          teach that the module has no relations at all; "this entry has none" is information.
          The copy carries no hint of a defect, because there is none — most entries have none.
        -->
        <EmptyState
          v-else-if="activeRelations.length === 0 && endedRelations.length === 0"
          size="sm"
          icon="network"
          :title="t('knowledge.relations.panelEmpty')"
          :description="t('knowledge.relations.panelEmptyHint')"
        />

        <template v-else>
          <ul v-if="activeRelations.length > 0" class="flex flex-col gap-next-1">
            <KnowledgeRelationRow
              v-for="relation in activeRelations"
              :key="relation.id"
              :relation="relation"
              :viewpoint-entry-id="entry.id"
              :busy="busyRelation"
              @open="(slug: string) => emit('open-entry', slug)"
            />
          </ul>

          <!--
            Ended relations are HIDDEN by default and counted, never silently dropped. A panel
            that quietly omits half the history is a panel that misinforms; one that says "12
            ended" and offers them is a panel that is merely tidy.
          -->
          <template v-if="endedRelations.length > 0">
            <Button variant="link" size="sm" @click="showEnded = !showEnded">
              {{
                showEnded
                  ? t('knowledge.relations.hideEnded')
                  : t('knowledge.relations.showEnded', '', { count: endedRelations.length })
              }}
            </Button>

            <ul v-if="showEnded" class="flex flex-col gap-next-1">
              <KnowledgeRelationRow
                v-for="relation in endedRelations"
                :key="relation.id"
                :relation="relation"
                :viewpoint-entry-id="entry.id"
                :busy="busyRelation"
                @open="(slug: string) => emit('open-entry', slug)"
                    />
            </ul>
          </template>
        </template>
      </div>
    </AccordionItem>

    <!-- Similar ---------------------------------------------------------- -->
    <AccordionItem value="similar" icon="sparkles" :title="t('knowledge.panels.similar')">
      <!-- An unfinished index is NOT an empty state: the answer is "not yet", not "none". -->
      <Alert
        v-if="similar.length === 0 && entry.index?.status !== 'indexed'"
        variant="info"
        size="sm"
      >
        {{ t('knowledge.similar.indexing') }}
      </Alert>
      <EmptyState
        v-else-if="similar.length === 0"
        size="sm"
        icon="sparkles"
        :title="t('knowledge.similar.empty')"
      />
      <ul v-else class="flex flex-col gap-next-1">
        <KnowledgeSimilarRow
          v-for="row in similar"
          :key="row.linkId"
          :row="row"
          :busy="busyLink"
          @open="(slug: string) => emit('open-entry', slug)"
          @dismiss="(id: string) => emit('dismiss-link', id, 'similarity')"
          @undo="(id: string) => emit('undo-link', id, 'similarity')"
        />
      </ul>
    </AccordionItem>

    <!-- Mentions --------------------------------------------------------- -->
    <!-- Only when there are any: unlike "Similar", an empty mentions panel says nothing useful —
         a mention is found by name, so "none" just means nobody wrote the name. -->
    <AccordionItem
      v-if="mentions.length > 0"
      value="mentions"
      icon="at-sign"
      :title="t('knowledge.panels.mentions')"
    >
      <ul class="flex flex-col gap-next-1">
        <KnowledgeMentionRow
          v-for="row in mentions"
          :key="row.linkId"
          :row="row"
          :busy="busyLink"
          @open="(slug: string) => emit('open-entry', slug)"
          @dismiss="(id: string) => emit('dismiss-link', id, 'mention')"
          @undo="(id: string) => emit('undo-link', id, 'mention')"
        />
      </ul>
    </AccordionItem>

    <!-- Links out -------------------------------------------------------- -->
    <AccordionItem value="links-out" icon="link-2" :title="t('knowledge.panels.linksOut')">
      <Text v-if="linksOut.length === 0" variant="caption" tone="muted">
        {{ t('knowledge.links.outEmpty') }}
      </Text>
      <ul v-else class="flex flex-col gap-next-1">
        <li v-for="target in linksOut" :key="target.id">
          <!-- `plain`: the row IS the link — it owns its radius and its hover surface, and inherits
               the rail's text colour. -->
          <Link
            :href="`#${target.slug}`"
            variant="plain"
            class="block truncate rounded-next-md px-next-2 py-next-1 text-next-sm hover:bg-next-muted"
            @click.prevent="emit('open-entry', target.slug)"
          >
            {{ target.title }}
          </Link>
        </li>
      </ul>
    </AccordionItem>

    <!-- Links in --------------------------------------------------------- -->
    <AccordionItem value="links-in" icon="link" :title="t('knowledge.panels.linksIn')">
      <Text v-if="linksIn.length === 0" variant="caption" tone="muted">
        {{ t('knowledge.links.inEmpty') }}
      </Text>
      <ul v-else class="flex flex-col gap-next-1">
        <li v-for="source in linksIn" :key="source.id">
          <Link
            :href="`#${source.slug}`"
            variant="plain"
            class="block truncate rounded-next-md px-next-2 py-next-1 text-next-sm hover:bg-next-muted"
            @click.prevent="emit('open-entry', source.slug)"
          >
            {{ source.title }}
          </Link>
        </li>
      </ul>
    </AccordionItem>

    <!-- Red links: only rendered when there is something to do about them. -->
    <AccordionItem v-if="ghosts.length > 0" value="ghosts" icon="unlink" :title="t('knowledge.panels.ghosts')">
      <div class="flex flex-col gap-next-2">
        <Badge variant="danger" tone="subtle" icon="unlink" size="sm" class="self-start">
          {{ t('knowledge.ghosts.count', '', { count: ghosts.length }) }}
        </Badge>
        <ul class="flex flex-col gap-next-1_5">
          <li v-for="slug in ghosts" :key="slug" class="flex items-center gap-next-2">
            <span class="min-w-0 flex-1 truncate font-next-mono text-next-xs">{{ slug }}</span>
            <Button
              variant="outline"
              size="xs"
              leading-icon="plus"
              :aria-label="`${t('knowledge.ghosts.create')}: ${slug}`"
              @click="emit('create-ghost', slug)"
            >
              {{ t('knowledge.ghosts.create') }}
            </Button>
          </li>
        </ul>
      </div>
    </AccordionItem>

    <!-- Provenance ------------------------------------------------------- -->
    <AccordionItem value="provenance" icon="user" :title="t('knowledge.panels.provenance')">
      <div class="flex flex-col gap-next-2">
        <div class="flex items-center gap-next-2">
          <Text variant="caption" tone="muted">{{ t('knowledge.provenance.createdBy') }}</Text>
          <!-- CreatorBadge already resolves user | workflow_run | bot; no local switch. -->
          <CreatorBadge :creator="entry.creator ?? null" size="xs" />
        </div>
        <DescriptionList :items="provenanceItems" layout="horizontal" size="sm" />
        <Button
          variant="ghost"
          size="xs"
          leading-icon="copy"
          class="self-start"
          @click="emit('copy-slug')"
        >
          {{ t('knowledge.common.copy') }}
        </Button>
      </div>
    </AccordionItem>

    <!-- History ---------------------------------------------------------- -->
    <AccordionItem value="history" icon="clock" :title="t('knowledge.panels.history')">
      <Button variant="outline" size="xs" leading-icon="clock" @click="emit('open-history')">
        {{ t('knowledge.history.open') }}
      </Button>
    </AccordionItem>
  </Accordion>
</template>
