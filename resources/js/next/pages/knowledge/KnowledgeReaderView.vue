<script setup lang="ts">
// KnowledgeReaderView — the wiki reader: contents · article · relation panels.
//
// ONE SURFACE, not two. The spec's "entry card" and "reader" are the same screen (D10): a separate
// route rendering the same article would drift from this one inside a week. Everything an entry
// card promised lives in the rail beside the article.
//
// It does NOT use PageHeader. The page's `<h1>` is the entry's title, rendered by the article
// itself — that is the documented exception to ADR-0011, and a PageHeader here would create a
// second `<h1>` describing the section. What replaces it is a slim action bar, the same pattern
// WorkflowDetailView uses.
//
// WHY THE WHOLE ENTRY LIST IS LOADED (`drainAll`): the contents panel needs the full `position`
// order for prev/next, and — more importantly — the wikilink resolver decides GHOST vs real from
// this list. Resolving against a single 25-row page would paint existing entries as red links
// purely because they sat on page 2, inviting the user to create duplicates.
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Surface from '../../ui/layout/Surface.vue';
import Button from '../../ui/primitives/Button.vue';
import SegmentedControl from '../../ui/forms/SegmentedControl.vue';
import DropdownMenu from '../../ui/overlay/DropdownMenu.vue';
import DropdownMenuItem from '../../ui/overlay/DropdownMenuItem.vue';
import Drawer from '../../ui/overlay/Drawer.vue';
import Tooltip from '../../ui/overlay/Tooltip.vue';
import Skeleton from '../../ui/data/Skeleton.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import KnowledgeTocPanel from './reader/KnowledgeTocPanel.vue';
import KnowledgeArticleBody from './reader/KnowledgeArticleBody.vue';
import KnowledgeEntryRail from './reader/KnowledgeEntryRail.vue';
import KnowledgeVersionsDrawer from './KnowledgeVersionsDrawer.vue';
import { useKnowledgeStore } from '../../app/stores/knowledge';
import { useToast } from '../../app/composables/useToast';
import { useConfirm } from '../../app/composables/useConfirm';
import { byPosition } from './entryMeta';
import { composeLocation } from './composeSeed';
import { anchorForOffset } from './reader/wikilinkAnchors';
import { useI18n } from '../../app/i18n';

import type { KnowledgeEntryType, KnowledgeRelation } from './types';

const route = useRoute();
const router = useRouter();
const { t } = useI18n();
const store = useKnowledgeStore();
const toast = useToast();
const confirm = useConfirm();

const baseId = computed(() => String(route.params.baseId ?? ''));
const slug = computed(() => (route.params.slug ? String(route.params.slug) : null));
const base = computed(() => (store.openBase?.id === baseId.value ? store.openBase : null));

const ordered = computed(() => byPosition(store.entries));

// --- Loading the base's entries --------------------------------------------
watch(
  baseId,
  (id) => {
    if (id) void store.fetchEntries(id, {}, { reset: true, drainAll: true });
  },
  { immediate: true },
);

onBeforeUnmount(() => {
  store.resetEntries();
  store.resetEntry();
});

// --- Resolving the open entry ----------------------------------------------
/**
 * Slug → id → detail. The list row is not enough: the reader needs `content`, `links` and
 * `backlinks`, which only the single-entry route carries.
 */
const listRow = computed(() =>
  slug.value ? (store.entriesBySlug.get(slug.value) ?? null) : null,
);

watch(
  () => [listRow.value?.id, store.entriesLoading] as const,
  ([id, listLoading]) => {
    if (listLoading) return;
    if (!id) {
      store.resetEntry();
      return;
    }
    if (store.entry?.id !== id) void store.fetchEntry(id);
  },
  { immediate: true },
);

/** With no slug, open the first entry — a reader with nothing open is a blank stare. */
watch(
  () => [store.entriesLoading, slug.value, ordered.value.length] as const,
  ([listLoading, current, count]) => {
    if (listLoading || current || count === 0) return;
    void router.replace(readerLocation(ordered.value[0].slug));
  },
  { immediate: true },
);

const entry = computed(() => store.entry);

// --- Navigation -------------------------------------------------------------
function readerLocation(target: string) {
  return {
    name: 'next.knowledge.base.reader',
    params: { baseId: baseId.value, slug: target },
    query: route.query,
  };
}

function openEntry(target: string): void {
  void router.push(readerLocation(target));
}

/**
 * A red link is an invitation — to the COMPOSER, seeded with the slug (spec §25.4). Entries are no
 * longer written by hand, so this is the only place the invitation can lead. The target is built by
 * the shared `composeSeed` helper, because this exact function used to exist twice (R17).
 */
function createGhost(ghostSlug: string): void {
  void router.push(composeLocation(baseId.value, ghostSlug));
}

const currentIndex = computed(() => ordered.value.findIndex((e) => e.slug === slug.value));
const prevEntry = computed(() => (currentIndex.value > 0 ? ordered.value[currentIndex.value - 1] : null));
const nextEntry = computed(() =>
  currentIndex.value >= 0 && currentIndex.value < ordered.value.length - 1
    ? ordered.value[currentIndex.value + 1]
    : null,
);

// --- Deep link to a passage -------------------------------------------------
/**
 * `#h-<anchor>` jumps straight to a heading; `?offset=<n>` is what a search result sends — a
 * character offset into the entry's own content, which is resolved to the heading that OWNS it.
 * Mapping an offset onto rendered DOM is not possible in general, so landing at the top of the
 * right section is the honest answer.
 */
const jumpAnchor = computed<string | null>(() => {
  const hash = route.hash?.replace(/^#/, '');
  if (hash) return hash;
  const offset = Number(route.query.offset);
  if (!Number.isFinite(offset) || !entry.value?.content) return null;
  return anchorForOffset(entry.value.content, offset);
});

// --- Contents drawer (below next-lg) ---------------------------------------
const tocOpen = ref(false);

function onSelectFromToc(target: string): void {
  tocOpen.value = false;
  openEntry(target);
}

// --- Mode switch ------------------------------------------------------------
const mode = ref<'reader' | 'table'>('reader');
const modeOptions = computed(() => [
  { value: 'reader' as const, label: t('knowledge.reader.mode.reader'), icon: 'book-open' as const },
  { value: 'table' as const, label: t('knowledge.reader.mode.table'), icon: 'table' as const },
]);
watch(mode, (value) => {
  if (value === 'table') {
    void router.push({ name: 'next.knowledge.base.table', params: { baseId: baseId.value } });
  }
});

// --- Reorder ----------------------------------------------------------------

/**
 * Move one entry by one slot. The endpoint takes the EXACT id set of the base, so a single swap and
 * a full sort cost the same request — which is why the whole order is sent rather than a delta.
 */

// --- Metadata editing -------------------------------------------------------
const metadataDraft = ref<Record<string, unknown>>({});
const metadataErrors = ref<Record<string, string> | null>(null);
const savingMetadata = ref(false);


function fieldErrorsOf(err: unknown): Record<string, string> | null {
  const errors = (err as { response?: { data?: { errors?: Record<string, string[]> } } })?.response?.data?.errors;
  if (!errors) return null;
  const flat: Record<string, string> = {};
  for (const [key, messages] of Object.entries(errors)) {
    if (Array.isArray(messages) && messages.length) flat[key] = messages[0];
  }
  return flat;
}

// Leaving the entry drops an unsaved metadata draft — it belongs to that entry alone.
watch(() => entry.value?.id, () => {
  metadataErrors.value = null;
});

// --- Typed relations --------------------------------------------------------
//
// A SEPARATE request, because relations are not part of the entry payload: they are statements
// about two entries and both ends carry them, so they were never going to hang off one entry's
// resource. `include_historical` is on from the start here — the panel does its own hiding, and
// asking twice (once without, once with) to reveal a list the user already has a count of would be
// a round trip to display data we could simply have fetched.
const busyRelation = ref(false);

watch(
  () => entry.value?.id,
  (id) => {
    if (id) void store.fetchRelations(id, true);
    else store.resetRelations();
  },
  { immediate: true },
);

/**
 * DELETE — the only irreversible action in the relation surface, and the only place a user can
 * learn how it differs from ending.
 *
 * The dialog does not merely warn: it names the correct alternative and says what that alternative
 * preserves. Somebody reaching for "delete" to UPDATE a fact is the failure mode this copy exists
 * to catch, and it is the one mistake here that destroys history.
 */

// --- Derived-edge dismiss / undo (similarity + mention) ---------------------
const busyLink = ref(false);

/** One endpoint pair for both kinds; only the CONFIRMATION differs, because the kinds do. */
type DerivedKind = 'similarity' | 'mention';

async function onDismissLink(linkId: string, kind: DerivedKind): Promise<void> {
  busyLink.value = true;
  try {
    await store.dismissLink(linkId);
    // Reversible + low stakes → toast with an undo, never a modal. The row also stays on screen.
    toast.success(t(kind === 'mention' ? 'knowledge.mentions.dismissed' : 'knowledge.similar.dismissed'), {
      action: { label: t('knowledge.common.undo'), onClick: () => void onUndoLink(linkId, kind) },
    });
  } catch {
    toast.danger(t('knowledge.common.saveError'));
  } finally {
    busyLink.value = false;
  }
}

async function onUndoLink(linkId: string, _kind: DerivedKind): Promise<void> {
  busyLink.value = true;
  try {
    await store.undismissLink(linkId);
  } catch {
    toast.danger(t('knowledge.common.saveError'));
  } finally {
    busyLink.value = false;
  }
}

// --- Re-index (B6c) ---------------------------------------------------------
const retryingIndex = ref(false);

/**
 * Re-queue indexing for the open entry.
 *
 * Rendered from the RESPONSE, never from a refetch: the endpoint answers with the entry already
 * moved to `pending` (and `can_retry` false), and a follow-up GET would race the worker — which
 * could show the state going backwards, or bring back the `failed` badge the user just cleared.
 */
async function onRetryIndex(): Promise<void> {
  const current = entry.value;
  if (!current || retryingIndex.value) return;

  retryingIndex.value = true;
  try {
    await store.retryIndex(current.id);
    toast.success(t('knowledge.index.retryQueued'));
  } catch (err: unknown) {
    // The server's own refusal (`errors.index`) — it names the state that disqualified the entry,
    // which is more useful than a generic failure line.
    const messages = (err as { response?: { data?: { errors?: Record<string, string[]> } } })
      ?.response?.data?.errors?.index;
    toast.danger(Array.isArray(messages) && messages.length ? messages[0] : t('knowledge.common.saveError'));
  } finally {
    retryingIndex.value = false;
  }
}

// --- History ----------------------------------------------------------------
const historyOpen = ref(false);

// --- Slug copy --------------------------------------------------------------
async function copySlug(): Promise<void> {
  const value = entry.value?.slug;
  if (!value) return;
  try {
    await navigator.clipboard.writeText(value);
    toast.success(t('knowledge.provenance.slugCopied'));
  } catch {
    toast.danger(t('knowledge.common.copyFailed'));
  }
}

// --- Trash ------------------------------------------------------------------

// --- Keyboard shortcuts -----------------------------------------------------
/** `[` / `]` walk the contents. Suppressed while typing, or the user cannot write a bracket. */
function isTyping(target: EventTarget | null): boolean {
  const el = target as HTMLElement | null;
  if (!el) return false;
  const tag = el.tagName;
  return (
    tag === 'INPUT' ||
    tag === 'TEXTAREA' ||
    tag === 'SELECT' ||
    el.isContentEditable ||
    !!el.closest?.('.next-md-content')
  );
}

function onKeydown(event: KeyboardEvent): void {
  if (event.metaKey || event.ctrlKey || event.altKey || isTyping(event.target)) return;
  if (event.key === '[' && prevEntry.value) {
    event.preventDefault();
    openEntry(prevEntry.value.slug);
  } else if (event.key === ']' && nextEntry.value) {
    event.preventDefault();
    openEntry(nextEntry.value.slug);
  }
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));

// --- View state -------------------------------------------------------------
const listLoading = computed(() => store.entriesLoading && store.entries.length === 0);
const baseIsEmpty = computed(() => !store.entriesLoading && store.entries.length === 0);

/** "Write the first entry" — the composer, unseeded. */
function newEntry(): void {
  void router.push(composeLocation(baseId.value));
}
</script>

<template>
  <div class="flex min-h-0 flex-col gap-next-4">
    <!-- Slim action bar (replaces PageHeader — see the header comment). -->
    <Surface
      bg="card"
      border
      radius="lg"
      class="sticky top-0 z-[var(--z-next-sticky)] flex items-center gap-next-2 px-next-4 py-next-2"
    >
      <Button
        variant="ghost"
        size="icon-sm"
        leading-icon="menu"
        class="next-lg:hidden"
        :aria-label="t('knowledge.reader.toc.open')"
        @click="tocOpen = true"
      />

      <SegmentedControl
        v-model="mode"
        :options="modeOptions"
        size="sm"
        :aria-label="t('knowledge.reader.mode.label')"
      />

      <div class="flex-1" />

      <!-- Ends of the list are explained, not silently dead. -->
      <Tooltip :label="prevEntry ? t('knowledge.reader.prev') : t('knowledge.reader.prevDisabled')">
        <Button
          variant="ghost"
          size="icon-sm"
          leading-icon="chevron-left"
          :aria-disabled="!prevEntry"
          :aria-label="t('knowledge.reader.prev')"
          @click="prevEntry && openEntry(prevEntry.slug)"
        />
      </Tooltip>
      <Tooltip :label="nextEntry ? t('knowledge.reader.next') : t('knowledge.reader.nextDisabled')">
        <Button
          variant="ghost"
          size="icon-sm"
          leading-icon="chevron-right"
          :aria-disabled="!nextEntry"
          :aria-label="t('knowledge.reader.next')"
          @click="nextEntry && openEntry(nextEntry.slug)"
        />
      </Tooltip>

      <DropdownMenu v-if="entry" placement="bottom-end" :aria-label="t('knowledge.entries.actions.more', '', { title: entry.title })">
        <template #trigger="{ props: triggerProps }">
          <Button
            v-bind="triggerProps"
            variant="ghost"
            size="icon-sm"
            leading-icon="more-vertical"
            :aria-label="t('knowledge.entries.actions.more', '', { title: entry.title })"
          />
        </template>
        <DropdownMenuItem icon="clock" :label="t('knowledge.reader.menu.history')" @select="historyOpen = true">
          {{ t('knowledge.reader.menu.history') }}
        </DropdownMenuItem>
        <DropdownMenuItem
          icon="trash"
          destructive
          :disabled="!entry.can_be_deleted"
          :label="t('knowledge.reader.menu.trash')"
        >
          {{ t('knowledge.reader.menu.trash') }}
        </DropdownMenuItem>
      </DropdownMenu>
    </Surface>

    <!-- Three columns: contents · article · relations. -->
    <div class="flex min-h-0 gap-next-4">
      <div class="hidden w-[17rem] shrink-0 next-lg:flex next-lg:flex-col">
        <KnowledgeTocPanel
          :base="base"
          :entries="store.entries"
          :active-slug="slug"
          :loading="listLoading"
          :error="store.entriesError"

          :truncated="store.entriesTruncated"
          @select="openEntry"

          @reload="reloadEntries"
        />
      </div>

      <div class="flex min-w-0 flex-1 flex-col gap-next-4">
        <!-- Article loading: a title line, badge lines, and paragraphs — the shape of an article. -->
        <div
          v-if="listLoading || store.entryLoading"
          class="flex max-w-[72ch] flex-col gap-next-3"
          role="status"
          :aria-label="t('knowledge.common.loadingLabel')"
        >
          <Skeleton variant="text" width="55%" height="1.8rem" />
          <div class="flex gap-next-2">
            <Skeleton variant="text" width="7rem" />
            <Skeleton variant="text" width="7rem" />
          </div>
          <div v-for="p in 3" :key="`p-${p}`" class="flex flex-col gap-next-1">
            <Skeleton variant="text" width="95%" />
            <Skeleton variant="text" width="100%" />
            <Skeleton variant="text" width="88%" />
            <Skeleton variant="text" width="62%" />
          </div>
        </div>

        <!-- The base has no entries at all: the one state that invites writing. -->
        <EmptyState
          v-else-if="baseIsEmpty"
          icon="file-text"
          :title="t('knowledge.reader.empty.title')"
          :description="t('knowledge.reader.empty.description')"
        >
          <template #action>
            <Button size="sm" leading-icon="plus" @click="newEntry">
              {{ t('knowledge.reader.empty.action') }}
            </Button>
          </template>
        </EmptyState>

        <EmptyState
          v-else-if="store.entryError"
          variant="error"
          :title="t('knowledge.common.loadError')"
        >
          <template #action>
            <Button
              variant="outline"
              size="sm"
              leading-icon="rotate-ccw"
              @click="listRow && store.fetchEntry(listRow.id)"
            >
              {{ t('knowledge.common.retry') }}
            </Button>
          </template>
        </EmptyState>

        <!-- The slug in the URL does not exist in this base (renamed or deleted entry). -->
        <EmptyState
          v-else-if="slug && !listRow"
          variant="search"
          :title="t('knowledge.reader.notFound.title')"
          :description="t('knowledge.reader.notFound.description')"
        >
          <template #action>
            <Button variant="outline" size="sm" leading-icon="plus" @click="createGhost(slug)">
              {{ t('knowledge.ghosts.create') }}
            </Button>
          </template>
        </EmptyState>

        <KnowledgeArticleBody
          v-else-if="entry"
          :entry="entry"
          :entries-by-slug="store.entriesBySlug"
          :href-for="(s: string) => `/next/knowledge/${baseId}/reader/${s}`"
          :jump-anchor="jumpAnchor"
          :retrying="retryingIndex"
          @navigate="openEntry"
          @create-ghost="createGhost"
          @retry-index="onRetryIndex"
        />

        <!-- Below next-xl the relation panels sit under the article, with identical content. -->
        <div v-if="entry" class="next-xl:hidden">
          <KnowledgeEntryRail
            :entry="entry"
            :base="base"
            :metadata-draft="metadataDraft"
            :metadata-errors="metadataErrors"
            :saving-metadata="savingMetadata"
            :busy-link="busyLink"
            :relations="store.relations"
            :relations-error="store.relationsError"

            :relations-loading="store.relationsLoading"
            @open-entry="openEntry"
            @create-ghost="createGhost"
            @dismiss-link="onDismissLink"
            @undo-link="onUndoLink"



            @reload-relations="reloadRelations"
            @update:metadata-draft="(v: Record<string, unknown>) => (metadataDraft = v)"
            @open-history="historyOpen = true"
            @copy-slug="copySlug"
          />
        </div>
      </div>

      <div v-if="entry" class="hidden w-80 shrink-0 next-xl:flex next-xl:flex-col">
        <KnowledgeEntryRail
          :entry="entry"
          :base="base"
          :metadata-draft="metadataDraft"
          :metadata-errors="metadataErrors"
          :saving-metadata="savingMetadata"
          :busy-link="busyLink"
          :relations="store.relations"
          :relations-error="store.relationsError"

          :relations-loading="store.relationsLoading"
          @open-entry="openEntry"
          @create-ghost="createGhost"
          @dismiss-link="onDismissLink"
          @undo-link="onUndoLink"



          @reload-relations="reloadRelations"
          @update:metadata-draft="(v: Record<string, unknown>) => (metadataDraft = v)"
          @open-history="historyOpen = true"
          @copy-slug="copySlug"
        />
      </div>
    </div>

    <!-- Contents as a drawer below next-lg. -->
    <Drawer v-model:open="tocOpen" side="left" size="sm" :aria-label="t('knowledge.reader.toc.title')">
      <template #title>{{ t('knowledge.reader.toc.title') }}</template>
      <KnowledgeTocPanel
        :base="base"
        :entries="store.entries"
        :active-slug="slug"
        :loading="listLoading"
        :error="store.entriesError"

        :truncated="store.entriesTruncated"
        @select="onSelectFromToc"

        @reload="reloadEntries"
      />
    </Drawer>

    <KnowledgeVersionsDrawer v-model:open="historyOpen" :entry="entry" />

  </div>
</template>
