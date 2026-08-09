<script setup lang="ts">
// KnowledgeDraftCard — ONE proposal, as a document to read (spec §24.4 / DC3).
//
// A card, not a table row: a draft is a title + body + metadata + relations + a diff, and every row
// of a table carrying that would be a card in disguise.
//
// A SHADOW (an amendment) is the SAME card, not a second layout (DC4) — the reviewer is judging
// "what will exist", not "which CRUD verb this is". It carries the TARGET's title and slug (not an
// invented new one), a badge naming what it amends, and — when the target has moved since the
// composer read it — the stale-baseline warning BEFORE the accept button is pressed. Discovering
// the conflict after the click is one disappointment too late (§24.7).
//
// AN ACCEPTED CARD STAYS on the board, muted, with a link to the entry. Removing it would take away
// the user's sense of progress and their ability to check what has already gone through.
import { computed, ref } from 'vue';
import Surface from '../../../ui/layout/Surface.vue';
import Checkbox from '../../../ui/forms/Checkbox.vue';
import Badge from '../../../ui/primitives/Badge.vue';
import Button from '../../../ui/primitives/Button.vue';
import Text from '../../../ui/primitives/Text.vue';
import Alert from '../../../ui/feedback/Alert.vue';
import DescriptionList, { type DescriptionItem } from '../../../ui/data/DescriptionList.vue';
import MarkdownViewer from '../../../ui/editor/MarkdownViewer.vue';
import KnowledgeDraftDiffPanel from './KnowledgeDraftDiffPanel.vue';
import { useI18n } from '../../../app/i18n';
import type { ReportLine } from '../relations/graphOpsReport';
import type { KnowledgeBase, KnowledgeDraftDuplicate, KnowledgeDraftEntry } from '../types';

const props = withDefaults(
  defineProps<{
    draft: KnowledgeDraftEntry;
    base: KnowledgeBase | null;
    selected?: boolean;
    /** The duplicate warning for THIS draft, from the session's map (keyed by draft id). */
    duplicate?: KnowledgeDraftDuplicate | null;
    /** Already published in this session — the card goes quiet and offers the entry. */
    accepted?: boolean;
    /** A server refusal for this draft (24.7), already mapped to a sentence by the board. */
    error?: string | null;
    /** An acceptance CONFLICT: the target moved, so this proposal could not be applied. */
    conflicted?: boolean;
    busy?: boolean;
    /** A rebase is in flight for this card. */
    rebasing?: boolean;
    hasPreviousIteration?: boolean;
    /** Scroll target / highlight, when the relations panel pointed at this card. */
    highlighted?: boolean;
    /**
     * The run notes ABOUT THIS DRAFT, already mapped to sentences.
     *
     * Passed in rather than read here: notes arrive on the SESSION keyed by target slug, and a
     * card that went looking for its own would have to know the session's shape to find them.
     */
    notes?: ReportLine[];
  }>(),
  {
    selected: false,
    duplicate: null,
    accepted: false,
    error: null,
    conflicted: false,
    busy: false,
    rebasing: false,
    hasPreviousIteration: false,
    highlighted: false,
    notes: () => [],
  },
);

const emit = defineEmits<{
  (e: 'update:selected', value: boolean): void;
  (e: 'accept', status: 'approved' | 'draft'): void;
  (e: 'reject'): void;
  (e: 'refine'): void;
  /** Re-point this proposal at the target's current text. Costs no AI. */
  (e: 'rebase'): void;
  (e: 'open-duplicate', slug: string): void;
  (e: 'open-entry', slug: string): void;
}>();

const { t } = useI18n();

const isShadow = computed(() => !!props.draft.targets_entry);
/** A shadow shows the TARGET's identity, not an invented new one (DC4). */
const displayTitle = computed(() => props.draft.targets_entry?.title ?? props.draft.title);
const displaySlug = computed(() => props.draft.targets_entry?.slug ?? props.draft.slug);

const expanded = ref(false);
const showDiff = ref(false);

/**
 * The target moved after the composer read this proposal. Surfaced as a BADGE, before the accept
 * button is pressed — the server tells us on every fetch (`target_revision_stale`), so there is no
 * reason to let the user find out from a refusal.
 */
const staleBaseline = computed(() => isShadow.value && props.draft.target_revision_stale === true);

/** Open the diff already switched to the comparison that explains the conflict. */
function showConflictDiff(): void {
  showDiff.value = true;
}

const bodyId = computed(() => `draft-body-${props.draft.id}`);

/** The duplicate score as a whole percent — the number is always written out, never just a bar. */
const duplicatePercent = computed(() =>
  props.duplicate ? Math.round(props.duplicate.score * 100) : null,
);

/** Metadata rendered through the base's schema, so labels are what a human wrote. */
const metadataItems = computed<DescriptionItem[]>(() =>
  (props.base?.metadata_schema ?? [])
    .map((field) => ({
      key: field.key,
      label: field.label || field.key,
      value: presentValue(field.key),
    }))
    .filter((item) => item.value !== null),
);

function presentValue(key: string): string | null {
  const field = props.base?.metadata_schema?.find((f) => f.key === key);
  const raw = props.draft.metadata?.[key];
  if (raw == null || raw === '') return null;

  const one = (value: unknown): string => {
    if (typeof value === 'boolean') return value ? t('knowledge.metadata.yes') : t('knowledge.metadata.no');
    const text = String(value);
    const option = field?.descriptor?.options?.find((o) => o.key === text);
    return option?.label ?? text;
  };

  return Array.isArray(raw) ? raw.map(one).join(', ') || null : one(raw);
}
</script>

<template>
  <Surface
    as="li"
    bg="card"
    border
    elevation="sm"
    radius="lg"
    class="flex flex-col gap-next-3 p-next-4 transition-shadow"
    :class="[accepted ? 'opacity-60' : '', highlighted ? 'is-jump-target' : '']"
    :data-draft-id="draft.id"
  >
    <div class="flex min-w-0 items-start gap-next-3">
      <Checkbox
        v-if="!accepted"
        :model-value="selected"
        :aria-label="t('knowledge.compose.select', '', { title: displayTitle })"
        :disabled="busy"
        @update:model-value="(v: boolean) => emit('update:selected', v)"
      />

      <div class="flex min-w-0 flex-1 flex-col gap-next-1">
        <h3 class="min-w-0 truncate text-next-base font-next-semibold text-next-fg">{{ displayTitle }}</h3>
        <span class="truncate font-next-mono text-next-2xs text-next-muted-foreground">{{ displaySlug }}</span>
      </div>
    </div>

    <!-- The markers. Every one of them is a word, not just a colour. -->
    <div class="flex flex-wrap items-center gap-next-1_5">
      <Badge v-if="!isShadow" variant="neutral" tone="subtle" size="sm">
        {{ t('knowledge.compose.badgeNew') }}
      </Badge>
      <Badge v-else variant="modified" tone="subtle" icon="pencil" size="sm" data-shadow-badge>
        {{ t('knowledge.compose.badgeAmend', '', { title: draft.targets_entry?.title ?? '' }) }}
      </Badge>

      <!-- BEFORE the click, not after it: the entry this proposal rewrites has changed since it was
           generated, so what the diff shows is a comparison against text nobody has now. -->
      <Badge
        v-if="staleBaseline"
        variant="warning"
        tone="subtle"
        icon="alert-triangle"
        size="sm"
        :title="t('knowledge.compose.badgeStaleBaselineHint')"
        data-stale-baseline
      >
        {{ t('knowledge.compose.badgeStaleBaseline') }}
      </Badge>

      <Badge
        v-if="duplicate && duplicatePercent != null"
        variant="warning"
        tone="subtle"
        icon="alert-triangle"
        size="sm"
        data-duplicate-badge
      >
        {{ t('knowledge.compose.duplicate', '', { title: duplicate.title, percent: duplicatePercent }) }}
      </Badge>
      <Button
        v-if="duplicate"
        variant="link"
        size="xs"
        @click="emit('open-duplicate', duplicate.slug)"
      >
        {{ t('knowledge.compose.duplicateOpen') }}
      </Button>

      <Badge v-if="accepted" variant="success" tone="subtle" icon="check-circle" size="sm">
        {{ t('knowledge.compose.accepted') }}
      </Badge>
    </div>

    <!--
      HOW this amendment lands, said before the body is read. Without it the card shows the whole
      composed document and a reviewer has no way to tell which part is new — the result is
      correct and the ATTRIBUTION is missing, which on an append is most of the information.
    -->
    <Badge
      v-if="draft.amend_mode === 'append'"
      variant="modified"
      tone="subtle"
      icon="plus"
      size="sm"
      class="self-start"
      data-amend-mode
    >
      {{
        draft.amend_section
          ? t('knowledge.compose.amendAppendSection', '', { section: draft.amend_section })
          : t('knowledge.compose.amendAppendEnd')
      }}
    </Badge>

    <!--
      WHAT THE SERVER DID TO THIS PROPOSAL, on the proposal itself.
      These answer the question the card cannot: the body says WHAT will happen, and a reviewer who
      asked for a rewrite and sees an append has no other way to learn WHY. Placed here rather than
      in a run summary because a note that names an entry belongs beside that entry — a list at the
      bottom of the page is a list nobody connects back.

      `wikilinks_lost` is the one that gets a warning tone. It is genuine damage: removing a
      `[[wikilink]]` breaks the graph of OTHER entries while the rewritten sentence reads perfectly
      well, so it is exactly what an eye slides over. The rest are the server explaining a safe
      decision, and dressing those as alarms would teach people to stop reading them.
    -->
    <Alert
      v-for="note in notes"
      :key="note.code"
      :variant="note.code === 'wikilinks_lost' ? 'warning' : 'info'"
      size="sm"
      :data-note="note.code"
    >
      {{ note.title }}
      <template v-if="note.hint" #actions>
        <Text variant="caption" tone="muted">{{ note.hint }}</Text>
      </template>
    </Alert>

    <!-- The body, collapsed by default: a board of six fully-expanded drafts is unreadable. -->
    <div class="relative">
      <div
        :id="bodyId"
        class="min-w-0 overflow-hidden"
        :class="expanded ? '' : 'max-h-64'"
      >
        <!--
          `amended_body` — WHAT THE ENTRY WOULD SAY — never the raw `content` column.

          An APPEND shadow stores its addition ALONE, because that is what keeps it commutative
          with somebody else's concurrent edit. Rendering that column shows a card claiming the
          whole document becomes one sentence: not merely incomplete, but a confident statement of
          the wrong outcome, on the surface whose entire job is to say what accepting will do.

          The `??` fallback is load-bearing rather than defensive: the field is serialized only for
          shadows, so an ordinary new draft has no `amended_body` and its `content` IS the result.
        -->
        <MarkdownViewer :source="draft.amended_body ?? draft.content ?? ''" />
      </div>
      <div
        v-if="!expanded"
        class="pointer-events-none absolute inset-x-0 bottom-0 h-10 bg-gradient-to-t from-next-card to-transparent"
        aria-hidden="true"
      />
    </div>
    <Button
      variant="link"
      size="xs"
      class="self-start"
      :aria-expanded="expanded"
      :aria-controls="bodyId"
      @click="expanded = !expanded"
    >
      {{ expanded ? t('knowledge.compose.collapse') : t('knowledge.compose.expand') }}
    </Button>

    <DescriptionList
      v-if="metadataItems.length > 0"
      :items="metadataItems"
      layout="grid"
      :columns="2"
      size="sm"
    />

    <!-- A CONFLICT (§24.7): the target was edited in parallel, so this proposal was not applied.
         Deliberately NOT the editor's stale-write modal — that one guards a human's unsaved typing;
         here the work is machine-made and cheap to redo, so this is an in-card choice between two
         honest routes, with the PRICE of each stated. -->
    <Alert v-if="conflicted" variant="warning" size="sm" data-draft-conflict>
      <div class="flex flex-col gap-next-2">
        <span>{{ t('knowledge.compose.conflict.message', '', { title: draft.targets_entry?.title ?? '' }) }}</span>
        <div class="flex flex-wrap items-center gap-next-2">
          <Button
            size="sm"
            leading-icon="rotate-ccw"
            :loading="rebasing"
            data-conflict-rebase
            @click="emit('rebase'); showConflictDiff()"
          >
            {{ t('knowledge.compose.conflict.showDiff') }}
          </Button>
          <Text variant="caption" tone="muted">{{ t('knowledge.compose.conflict.freeHint') }}</Text>

          <Button
            variant="outline"
            size="sm"
            leading-icon="sparkles"
            :disabled="busy"
            data-conflict-refine
            @click="emit('refine')"
          >
            {{ t('knowledge.compose.conflict.refine') }}
          </Button>
          <Text variant="caption" tone="muted">{{ t('knowledge.compose.conflict.costHint') }}</Text>
        </div>
      </div>
    </Alert>

    <!-- A server refusal (24.7). The card KEEPS its content — the fix is a revision, not a retype.
         A conflict takes precedence: it carries its own two routes, so showing both would offer
         four buttons for one problem. -->
    <Alert v-else-if="error" variant="danger" size="sm" data-draft-error>
      <div class="flex flex-wrap items-center justify-between gap-next-2">
        <span>{{ error }}</span>
        <Button variant="outline" size="sm" leading-icon="sparkles" @click="emit('refine')">
          {{ t('knowledge.compose.refineThis') }}
        </Button>
      </div>
    </Alert>

    <KnowledgeDraftDiffPanel
      v-if="showDiff"
      :draft="draft"
      :has-previous-iteration="hasPreviousIteration"
      :rebasing="rebasing"
      @rebase="emit('rebase')"
    />

    <!-- Footer. An accepted card trades its actions for the way into the real entry. -->
    <div class="flex flex-wrap items-center gap-next-2 border-t border-next-border pt-next-3">
      <template v-if="accepted">
        <Button variant="link" size="sm" @click="emit('open-entry', draft.slug)">
          {{ t('knowledge.compose.acceptedOpen') }}
        </Button>
      </template>

      <template v-else>
        <Button size="sm" :loading="busy" :disabled="busy" @click="emit('accept', 'approved')">
          {{ t('knowledge.compose.accept') }}
        </Button>
        <Button variant="outline" size="sm" :disabled="busy" @click="emit('accept', 'draft')">
          {{ t('knowledge.compose.acceptDraft') }}
        </Button>

        <Button
          variant="ghost"
          size="sm"
          leading-icon="file-text"
          :aria-expanded="showDiff"
          :aria-label="`${t('knowledge.compose.diff')}: ${displayTitle}`"
          @click="showDiff = !showDiff"
        >
          {{ t('knowledge.compose.diff') }}
        </Button>

        <Button
          variant="ghost"
          size="icon-sm"
          leading-icon="sparkles"
          :disabled="busy"
          :aria-label="`${t('knowledge.compose.refineThis')}: ${displayTitle}`"
          @click="emit('refine')"
        />

        <Button
          variant="ghost"
          size="icon-xs"
          leading-icon="x"
          class="ms-auto"
          :disabled="busy"
          :aria-label="t('knowledge.compose.reject', '', { title: displayTitle })"
          @click="emit('reject')"
        />
      </template>
    </div>
  </Surface>
</template>

<style scoped>
/* The card the relations panel just pointed at. A RING, not a colour swap: the highlight has to
   survive greyscale, and it fades on its own so nothing has to remember to clear it. */
.is-jump-target {
  box-shadow: 0 0 0 2px var(--color-next-primary);
}
</style>
