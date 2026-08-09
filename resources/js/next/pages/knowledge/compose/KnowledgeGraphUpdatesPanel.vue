<script setup lang="ts">
// KnowledgeGraphUpdatesPanel — "Graph updates", the relation half of a composer review.
//
// ------------------------------------------------------------------------------------------------
// A PEER OF THE DRAFT BOARD, NOT A CARD IN IT.
//
// The obvious design is to hang each proposed relation off the draft card of the entity it is
// about. It does not work, and not marginally: a relation frequently joins TWO ENTRIES THAT
// ALREADY EXIST and belongs to no draft whatsoever. Filing some relations inside cards and leaving
// the rest homeless would split one review into two shapes and make bulk acceptance mean different
// things in each.
//
// NOTE THE NAME. `compose/KnowledgeDraftRelationsPanel.vue` already exists and shows the draft
// GRAPH PREVIEW — a different thing entirely. These two must not be merged.
//
// ------------------------------------------------------------------------------------------------
// THE SELECTION IS REAL, AND IT IS THE SERVER'S KEYS THAT MAKE IT SO.
//
// An earlier cut of this panel had no checkboxes on purpose: `accept` took `entry_ids` and nothing
// else, so a tick here could not have been honoured and would have manufactured a belief about
// what happened. The contract now carries `proposed_relations[].key` and takes `graph_op_keys[]`
// back, so the control controls.
//
// THE KEY IS THE SERVER'S, NEVER COMPUTED HERE. A refinement RENUMBERS the operations, which
// silently changes what a held preview means. A client-minted key would still look valid and would
// apply the wrong operation; the server's stops matching, and `accept` answers 422 — which this
// screen turns into "your preview is out of date, reload" rather than a raw error.
//
// THE THREE-VALUED CONTRACT, because two of these look alike and are not:
//   absent  → apply everything (what a client that never learned about selection keeps getting)
//   [a, b]  → exactly those; the rest come back as `skipped[].code = 'not_selected'`
//   []      → apply NONE. Deliberately different from absent.
//
// ------------------------------------------------------------------------------------------------
// WHAT THIS SCREEN OWES THE REVIEWER.
//
//   1. Every operation as a SENTENCE, never a tuple.
//   2. Grouping per ENTITY, because reviewing relations is checking facts about somebody.
//   3. The dependency on an unaccepted draft shown BEFORE the press, not discovered afterwards as
//      a `dependency_not_accepted` skip. Same fact; one is a warning, the other is a surprise.
//   4. What the server REFUSED, with reasons. Without it a reviewer cannot tell "the agent found
//      nothing" from "the agent found things and they were thrown away" — the same empty screen,
//      completely different news.
import { computed, ref, watch } from 'vue';
import Checkbox from '../../../ui/forms/Checkbox.vue';
import Alert from '../../../ui/feedback/Alert.vue';
import Accordion from '../../../ui/disclosure/Accordion.vue';
import AccordionItem from '../../../ui/disclosure/AccordionItem.vue';
import Badge from '../../../ui/primitives/Badge.vue';
import Button from '../../../ui/primitives/Button.vue';
import Icon from '../../../ui/primitives/Icon.vue';
import Text from '../../../ui/primitives/Text.vue';
import Tooltip from '../../../ui/overlay/Tooltip.vue';
import EmptyState from '../../../ui/data/EmptyState.vue';
import Surface from '../../../ui/layout/Surface.vue';
import Skeleton from '../../../ui/data/Skeleton.vue';
import KnowledgeRelationSentence from '../relations/KnowledgeRelationSentence.vue';
import KnowledgeEntityChooser from '../relations/KnowledgeEntityChooser.vue';
import { entryTypeLabel, propertyLabel } from '../relations/relationLabels';
import { opLabel, rejectLine, skipLine, warnLine } from '../relations/graphOpsReport';
import {
  blockingDrafts,
  draftTitle,
  groupProposals,
  isBlocked,
  mergePairs,
  proposalDomId,
  proposalKey,
  type ProposalUnit,
} from '../relations/relationProposals';
import { useI18n } from '../../../app/i18n';
import type {
  KnowledgeAmbiguousMention,
  KnowledgeDraftSkipped,
  KnowledgeGraphOpReport,
  KnowledgeProposedEntity,
  KnowledgeProposedRelation,
} from '../types';

const props = withDefaults(
  defineProps<{
    proposals: KnowledgeProposedRelation[];
    entities?: KnowledgeProposedEntity[];
    /** What the server refused, and its advisory half. Both come off the SESSION. */
    rejected?: KnowledgeGraphOpReport[];
    warnings?: KnowledgeGraphOpReport[];
    /** Names that matched several entries. Settled inline, per session. */
    ambiguous?: KnowledgeAmbiguousMention[];
    /**
     * Handles of drafts already published in this session.
     *
     * This is what releases a dependency block LIVE: `depends_on_draft` is frozen at generation
     * time, so without subtracting what has since been accepted a row would stay disabled with a
     * reason that stopped being true.
     */
    acceptedHandles?: string[];
    /** What the LAST accept actually did — held on screen rather than only in a toast. */
    result?: { relations: number; skipped: KnowledgeDraftSkipped[] } | null;
    /**
     * The proposals are still being fetched.
     *
     * Without this the section went straight from "the agent proposed no relations" to a full list
     * — announcing, briefly and confidently, the opposite of what was about to appear. The empty
     * state here is a real editorial claim ("that is normal for text without clear links"), which
     * makes showing it before the answer arrives worse than showing nothing.
     */
    loading?: boolean;
    /**
     * The graph half has already been written in this visit, so the bar stands down.
     *
     * Local to the visit on purpose — and it is a compromise, not a design. The session does not
     * publish `applied_ops`, so on a FRESH entry the client cannot know whether these operations
     * ran. It therefore offers the action again; applying is idempotent server-side (the applier
     * records every applied index and answers `already_applied`), so a redundant press is safe and
     * says so. Offering it once too often is the recoverable error; never offering it is what cost
     * a base its whole graph.
     */
    applied?: boolean;
    /** The server refused our keys: the run was refined under this open review. */
    stale?: boolean;
    /** A server refusal about the SELECTION itself, shown in its own words. */
    error?: string | null;
    busy?: boolean;
  }>(),
  {
    entities: () => [],
    rejected: () => [],
    warnings: () => [],
    ambiguous: () => [],
    acceptedHandles: () => [],
    result: null,
    loading: false,
    applied: false,
    stale: false,
    error: null,
    busy: false,
  },
);

const emit = defineEmits<{
  (e: 'show-relation', relationId: string): void;
  (e: 'reload'): void;
  /** Write the ticked operations — one call, no entries. */
  (e: 'apply'): void;
}>();

const { t } = useI18n();

const accepted = computed(() => new Set(props.acceptedHandles));
/**
 * The proposals as UNITS — a replacement pair collapsed into the one change it describes.
 *
 * Everything below (selection, grouping, counting) works on units, so a pair is one row, one
 * checkbox and one line of the count. It commits two keys, which is the only place the pairing is
 * visible at all.
 */
const units = computed(() => mergePairs(props.proposals));
const groups = computed(() =>
  groupProposals(
    units.value.map((unit) => unit.primary),
    props.entities,
  ),
);

/** The unit a primary belongs to — how a rendered row finds the keys it commits. */
function unitOf(proposal: KnowledgeProposedRelation) {
  return units.value.find((unit) => unit.key === proposal.key) ?? null;
}

/**
 * The ambiguity answers, keyed by the MENTION TEXT and held for the whole session.
 *
 * Session-scoped on purpose: the same name ("Anna") can appear in five proposals, and asking five
 * times would be asking the same question five times. Deciding once and applying it everywhere is
 * the promise the row's own "also applies to N" note makes, so the state has to live above the row.
 *
 * `null` is a real answer meaning "none of these" — distinct from "not answered yet", which is the
 * key being absent. A row with `null` stays out of the accept set without pretending to be
 * unanswered.
 */
const resolutions = ref<Map<string, string | null>>(new Map());

function resolveMention(text: string, slug: string | null): void {
  const next = new Map(resolutions.value);
  next.set(text, slug);
  resolutions.value = next;
}

/** How many OTHER proposals one answer settles — what the "also applies" note counts. */
function ambiguityScope(text: string): number {
  return (
    props.proposals.filter(
      (proposal) => proposal.from_title === text || proposal.to_title === text,
    ).length - 1
  );
}

/** The unsettled ambiguity touching this proposal, if any. */
function ambiguityFor(proposal: KnowledgeProposedRelation): KnowledgeAmbiguousMention | null {
  return (
    props.ambiguous.find(
      (item) =>
        (item.text === proposal.from_title || item.text === proposal.to_title) &&
        !resolutions.value.has(item.text),
    ) ?? null
  );
}

// --- Readiness and selection -------------------------------------------------

/** A row is pending when a draft it depends on has not been accepted, or a name is unsettled. */
function pending(proposal: KnowledgeProposedRelation): boolean {
  return isBlocked(proposal, accepted.value) || ambiguityFor(proposal) !== null;
}

/**
 * A UNIT is pending when either half is — a pair whose `end` waits on a draft cannot be applied at
 * all, and offering it would be offering the half-selection the server refuses.
 */
function unitPending(unit: ProposalUnit): boolean {
  return pending(unit.primary) || (unit.ended !== null && pending(unit.ended));
}

/** The units a reviewer may tick — identified by the PRIMARY's key. */
const selectableUnits = computed(() => units.value.filter((unit) => !unitPending(unit)));
const selectableKeys = computed(() => selectableUnits.value.map((unit) => unit.key));

/**
 * Ticked operations. Seeded to EVERYTHING selectable, and re-seeded whenever the run changes.
 *
 * Default-on rather than default-off: the reviewer's job here is to spot the one proposal that is
 * wrong, not to re-approve fifteen that are right, and an empty default turns a review into
 * data entry. Unticking is the meaningful act, which is why it is the one the copy explains.
 *
 * The re-seed is keyed on the KEYS, so a refinement (which renumbers everything) starts clean
 * instead of carrying ticks that now point at different operations.
 */
const selected = ref<Set<string>>(new Set());

watch(
  selectableKeys,
  (keys) => {
    selected.value = new Set(keys);
  },
  { immediate: true },
);

/**
 * The keys that go on the wire — FLATTENED from the ticked units.
 *
 * A pair contributes BOTH of its keys or neither. This is the single place the two-for-one exists,
 * and it is why the checkbox above it can be one control: the reviewer decides about a change, and
 * the request describes it in the operations the server happens to store it as.
 */
const selectedKeys = computed(() =>
  selectableUnits.value
    .filter((unit) => selected.value.has(unit.key))
    .flatMap((unit) => unit.keys),
);
/** Counted in UNITS, because that is what a reviewer decided about — a pair is one decision. */
const readyCount = computed(() => selectableUnits.value.length);
const selectedUnitCount = computed(
  () => selectableUnits.value.filter((unit) => selected.value.has(unit.key)).length,
);

function toggle(key: string, value: boolean): void {
  const next = new Set(selected.value);
  if (value) next.add(key);
  else next.delete(key);
  selected.value = next;
}

function toggleAll(value: boolean): void {
  selected.value = value ? new Set(selectableKeys.value) : new Set();
}

const allSelected = computed<boolean | 'indeterminate'>(() => {
  if (selectableKeys.value.length === 0) return false;
  if (selectedKeys.value.length === 0) return false;

  return selectedKeys.value.length === selectableKeys.value.length ? true : 'indeterminate';
});

/**
 * The keys of one GROUP, so its header checkbox acts on exactly its own rows.
 *
 * Group membership is read off the proposals rather than off the group object, which is what will
 * let a future `replaces` pairing (an `end` bound to the `create` that succeeds it) be ADDED as a
 * second grouping pass without touching selection at all: this only ever asks "which keys belong
 * to this bucket".
 */
function groupKeys(handle: string): string[] {
  return selectableUnits.value
    .filter((unit) => (unit.primary.from ?? "") === handle)
    .map((unit) => unit.key);
}

function groupState(handle: string): boolean | 'indeterminate' {
  const keys = groupKeys(handle);
  const hit = keys.filter((key) => selected.value.has(key)).length;
  if (hit === 0) return false;

  return hit === keys.length ? true : 'indeterminate';
}

function toggleGroup(handle: string, value: boolean): void {
  const next = new Set(selected.value);
  for (const key of groupKeys(handle)) {
    if (value) next.add(key);
    else next.delete(key);
  }
  selected.value = next;
}

/** How many ready operations the reviewer has turned OFF — the thing worth naming. */
const excludedCount = computed(() => selectableUnits.value.length - selectedUnitCount.value);

/**
 * What the view actually sends as `graph_op_keys` — and the reason it is not just `selectedKeys`.
 *
 * A run with NO graph operations must OMIT the field, not send `[]`. The two are different
 * statements: `[]` says "I considered the operations and want none of them", while omitting says
 * "I have nothing to say about operations". With an empty run they happen to have the same effect,
 * so the bug would be invisible — right up until somebody reads a request log, or the server grows
 * a rule that treats an explicit total refusal differently from silence.
 *
 * The distinction is decided HERE because this is the component that knows whether there was
 * anything to decide about.
 */
const opKeys = computed<string[] | undefined>(() =>
  props.proposals.length === 0 ? undefined : selectedKeys.value,
);

defineExpose({ selectedKeys, opKeys });

// --- The report sections ----------------------------------------------------

const rejectedLines = computed(() => props.rejected.map(rejectLine));
const skippedLines = computed(() => (props.result?.skipped ?? []).map(skipLine));
const warningLines = computed(() => props.warnings.map(warnLine));

/** The reason a row cannot be accepted, as a sentence naming the entry it waits for. */
function blockReason(proposal: KnowledgeProposedRelation): string | null {
  const blocking = blockingDrafts(proposal, accepted.value);
  if (blocking.length === 0) return null;

  return t('knowledge.relations.blockedByDraft', '', {
    title: draftTitle(blocking[0], props.entities),
  });
}
</script>

<template>
  <section class="flex flex-col gap-next-4" aria-labelledby="kg-updates-title">
    <div class="flex flex-wrap items-center gap-next-3">
      <div class="flex min-w-0 flex-col gap-next-0_5">
        <h2 id="kg-updates-title" class="text-next-lg font-next-semibold text-next-fg">
          {{ t('knowledge.relations.updatesTitle') }}
        </h2>
        <Text variant="caption" tone="muted">{{ t('knowledge.relations.updatesSubtitle') }}</Text>
      </div>

      <div v-if="proposals.length > 0" class="ms-auto flex flex-wrap items-center gap-next-3">
        <Badge variant="neutral" tone="subtle" icon="network" size="sm">
          {{ t('knowledge.relations.count', '', { count: proposals.length }) }}
        </Badge>

        <!-- How many are ready, and how many the reviewer has deliberately turned off. -->
        <Badge variant="success" tone="subtle" size="sm" data-ready-count>
          {{ t('knowledge.relations.readyCount', '', { count: selectedUnitCount }) }}
        </Badge>
        <Badge v-if="excludedCount > 0" variant="neutral" tone="subtle" size="sm" data-excluded-count>
          {{ t('knowledge.relations.excludedCount', '', { count: excludedCount }) }}
        </Badge>

        <Checkbox
          v-if="selectableKeys.length > 0"
          :model-value="allSelected"
          :aria-label="t('knowledge.relations.selectAll')"
          :disabled="busy"
          data-select-all
          @update:model-value="(v: boolean) => toggleAll(v)"
        />
      </div>
    </div>

    <!--
      Everything ticked off. Said plainly, because `[]` and "no selection made" are the SAME thing
      to this screen and opposite things to the server — and a reviewer who unticked all of them
      deserves to know the graph half will be skipped rather than silently applied.
    -->
    <Text
      v-if="selectableKeys.length > 0 && selectedKeys.length === 0"
      variant="caption"
      tone="muted"
      data-none-selected
    >
      {{ t('knowledge.relations.noneSelected') }}
    </Text>

    <!--
      THE OPERATIONS ARE WAITING, AND THEY SAY SO.

      This is the fix for a silent loss. Accepting drafts ONE CARD AT A TIME never ran the graph
      half — only the bulk button did — so a reviewer who worked through the board card by card
      published every entry and lost every relation, with the relations still ticked on screen and
      nothing anywhere saying they had not been written.

      A bar rather than folding the write into each card: the graph proposal is ONE transaction on
      the server, and splitting it across N card acceptances would undo the work of the round that
      made it a single call with `entry_ids: []`. So the deferral stays and stops being silent.
    -->
    <Surface
      v-if="!applied && selectedKeys.length > 0"
      bg="card"
      border
      radius="lg"
      class="flex flex-wrap items-center gap-next-3 px-next-4 py-next-3"
      data-apply-bar
    >
      <Icon name="network" class="shrink-0 text-next-primary" aria-hidden="true" />
      <span class="flex min-w-0 flex-1 flex-col">
        <span class="text-next-sm font-next-medium text-next-fg">
          {{ t('knowledge.relations.pendingTitle', '', { count: selectedUnitCount }) }}
        </span>
        <Text variant="caption" tone="muted">{{ t('knowledge.relations.pendingHint') }}</Text>
      </span>
      <Button size="sm" :loading="busy" data-apply-graph @click="emit('apply')">
        {{ t('knowledge.relations.pendingAction') }}
      </Button>
    </Surface>

    <!--
      THE PREVIEW WENT STALE. Not an error and not styled as one: the server refused the whole
      request rather than applying part of it, so nothing was written and nothing was lost. The
      run was simply refined under an open review, which renumbers the operations. The only useful
      response is to reload and choose again, so that is the only thing offered.
    -->
    <!--
      A refusal about the SELECTION, in the SERVER'S own words. It names which operations belong
      together, which is the only thing that would help — a generic "could not save" would leave
      the reviewer with a screen full of checkboxes and no idea which pair to fix.
    -->
    <Alert v-if="error" variant="danger" size="sm" data-selection-error>
      {{ error }}
    </Alert>

    <Alert v-if="stale" variant="warning" size="sm" data-selection-stale>
      {{ t('knowledge.relations.staleSelection') }}
      <template #actions>
        <Button variant="outline" size="sm" leading-icon="refresh" @click="emit('reload')">
          {{ t('knowledge.relations.staleSelectionAction') }}
        </Button>
      </template>
    </Alert>

    <!--
      LOADING, in the shape of the rows that are coming: a group heading and a few relation lines.
      Skeletons mimic the real element rather than standing in as a spinner.
    -->
    <div
      v-if="loading"
      class="flex flex-col gap-next-3"
      role="status"
      :aria-label="t('knowledge.common.loadingLabel')"
      data-updates-loading
    >
      <Skeleton variant="text" width="12rem" height="1.2rem" />
      <div v-for="n in 3" :key="`op-sk-${n}`" class="flex items-center gap-next-2 ps-next-4">
        <Skeleton variant="circle" diameter="1rem" />
        <Skeleton variant="text" :width="`${72 - n * 10}%`" />
      </div>
    </div>

    <!--
      No proposals at all. NOT a fault and worded without one: plenty of text has no clear links
      between people, organisations or events, and an empty result there is the correct answer.
      There is deliberately no "try again" action.
    -->
    <EmptyState
      v-else-if="proposals.length === 0 && rejected.length === 0"
      size="sm"
      icon="network"
      :title="t('knowledge.relations.emptyProposals')"
      :description="t('knowledge.relations.emptyProposalsHint')"
    />

    <!-- One group per ENTITY, expanded by default: this is content to read, not an archive. -->
    <Accordion
      v-if="groups.length > 0"
      type="multiple"
      :default-value="groups.map((group) => group.handle)"
      class="flex flex-col gap-next-2"
    >
      <AccordionItem
        v-for="group in groups"
        :key="group.handle"
        :value="group.handle"
        icon="network"
        :title="group.title || t('knowledge.relations.unknownEntity')"
        :data-group="group.handle"
      >
        <div class="flex flex-col gap-next-2">
          <div class="flex flex-wrap items-center gap-next-2">
            <Checkbox
              v-if="groupKeys(group.handle).length > 0"
              :model-value="groupState(group.handle)"
              :aria-label="t('knowledge.relations.selectGroup', '', { name: group.title })"
              :disabled="busy"
              :data-select-group="group.handle"
              @update:model-value="(v: boolean) => toggleGroup(group.handle, v)"
            />

            <Badge v-if="group.entryType" variant="neutral" tone="subtle" size="sm">
              {{ entryTypeLabel(group.entryType) }}
            </Badge>

            <!-- The subject is itself a proposal — so every relation under it waits on it. -->
            <Badge v-if="group.isDraft" variant="modified" tone="subtle" size="sm" data-draft-badge>
              {{ t('knowledge.relations.draftBadge') }}
            </Badge>

            <Badge variant="neutral" tone="subtle" size="sm">
              {{ t('knowledge.relations.groupCount', '', { count: group.proposals.length }) }}
            </Badge>
          </div>

          <ul class="flex flex-col gap-next-2">
            <li
              v-for="proposal in group.proposals"
              :key="proposalKey(proposal, proposals.indexOf(proposal))"
              class="flex flex-col gap-next-1 rounded-next-md px-next-2 py-next-1_5 hover:bg-next-muted/40"
              :data-proposal="proposalKey(proposal, proposals.indexOf(proposal))"
            >
              <div
                class="flex min-w-0 items-start gap-next-2"
                :class="pending(proposal) ? 'opacity-70' : ''"
                :aria-describedby="
                  blockReason(proposal)
                    ? proposalDomId(proposal, proposals.indexOf(proposal))
                    : undefined
                "
              >
                <!--
                  A WAITING row shows a status glyph instead of a control, and that substitution is
                  the point: a disabled checkbox invites a click that will never work, while a
                  clock says the row is fine and simply not its turn. The reason is on the badge
                  below and reachable through `aria-describedby` on this row.
                -->
                <Icon
                  v-if="pending(proposal)"
                  name="clock"
                  class="mt-next-0_5 shrink-0 text-next-muted-foreground"
                  aria-hidden="true"
                />
                <!--
                  ONE control per CHANGE, which is not always one operation. A replacement arrives
                  as an `end` plus a `create`, and the server refuses to apply half of it — so two
                  checkboxes would offer a combination that always 422s. `data-select-op` names the
                  primary; the tick commits both keys.
                -->
                <Checkbox
                  v-else
                  :model-value="selected.has(proposal.key)"
                  :disabled="busy"
                  :aria-label="
                    t('knowledge.relations.select', '', {
                      sentence: `${proposal.from_title ?? ''} ${proposal.to_title ?? ''}`.trim(),
                    })
                  "
                  :data-select-op="proposal.key"
                  :data-commits="unitOf(proposal)?.keys.join(' ')"
                  @update:model-value="(v: boolean) => toggle(proposal.key, v)"
                />

                <div class="flex min-w-0 flex-1 flex-col gap-next-1">
                  <div class="flex min-w-0 flex-wrap items-center gap-next-2">
                    <!-- A TENSE, and it is load-bearing: the review shows what WOULD happen. -->
                    <Badge variant="primary" tone="subtle" size="sm">
                      {{ opLabel(proposal.op ?? 'create') }}
                    </Badge>

                    <!--
                      An unsettled name renders AS THE CHOICE, in place of the entity. A separate
                      "to resolve" section would mean: read the relation, go elsewhere, pick a
                      person, come back, read it again.
                    -->
                    <KnowledgeEntityChooser
                      v-if="ambiguityFor(proposal)"
                      :mention="ambiguityFor(proposal) as KnowledgeAmbiguousMention"
                      :also-applies="ambiguityScope((ambiguityFor(proposal) as KnowledgeAmbiguousMention).text)"
                      :disabled="busy"
                      @resolve="
                        (slug: string | null) =>
                          resolveMention((ambiguityFor(proposal) as KnowledgeAmbiguousMention).text, slug)
                      "
                    />

                    <KnowledgeRelationSentence
                      v-else
                      :subject="{ title: proposal.from_title ?? '', entry_type: null }"
                      :object="{ title: proposal.to_title ?? '', entry_type: null }"
                      :relation-type="proposal.relation_type"
                      :valid-from="proposal.valid_from"
                      :valid-to="proposal.valid_to"
                      :muted="proposal.op === 'end'"
                      size="sm"
                    />
                  </div>

                  <!--
                    THE OTHER HALF OF A REPLACEMENT, rendered inside the same row rather than as a
                    row of its own. The change reads as one sentence — "…and this one ends" — which
                    is what it is; two rows would invite the reviewer to think they could keep one.
                  -->
                  <div
                    v-if="unitOf(proposal)?.ended"
                    class="flex min-w-0 flex-wrap items-center gap-next-2 ps-next-4"
                    :data-paired-with="unitOf(proposal)?.ended?.key"
                  >
                    <Badge variant="neutral" tone="subtle" size="sm">
                      {{ t('knowledge.relations.opEnd') }}
                    </Badge>
                    <KnowledgeRelationSentence
                      :subject="{ title: unitOf(proposal)?.ended?.from_title ?? '', entry_type: null }"
                      :object="{ title: unitOf(proposal)?.ended?.to_title ?? '', entry_type: null }"
                      :relation-type="unitOf(proposal)?.ended?.relation_type ?? null"
                      :valid-to="unitOf(proposal)?.ended?.valid_to ?? null"
                      muted
                      size="sm"
                    />
                  </div>

                  <!-- The agent's justification, under the statement rather than instead of it. -->
                  <Text v-if="proposal.description" variant="caption" tone="muted" :clamp="2">
                    {{ proposal.description }}
                  </Text>

                  <div class="flex flex-wrap items-center gap-next-1_5">
                    <Badge
                      v-for="(value, key) in proposal.properties ?? {}"
                      :key="key"
                      variant="neutral"
                      tone="subtle"
                      size="sm"
                    >
                      {{ propertyLabel(String(key)) }}: {{ value }}
                    </Badge>

                    <!--
                      THE BLOCK, said before the press. Discovering this as a
                      `dependency_not_accepted` skip afterwards is the same fact delivered as a
                      surprise, after the reviewer has already committed.
                    -->
                    <Tooltip
                      v-if="blockReason(proposal)"
                      :label="t('knowledge.relations.blockedByDraftHint')"
                    >
                      <Badge
                        :id="proposalDomId(proposal, proposals.indexOf(proposal))"
                        variant="neutral"
                        tone="subtle"
                        icon="clock"
                        size="sm"
                        data-blocked
                      >
                        {{ blockReason(proposal) }}
                      </Badge>
                    </Tooltip>
                  </div>
                </div>
              </div>
            </li>
          </ul>
        </div>
      </AccordionItem>
    </Accordion>

    <!--
      WHAT THE LAST ACCEPT ACTUALLY DID.
      Shown as INFORMATION in every part, including the skips. A 200 carrying four saved entries
      and two skipped operations is the ordinary result of approving a subset — and `updated` is
      called out separately because those entries were not created, they MOVED underneath the
      reviewer while they were reading.
    -->
    <div v-if="result" class="flex flex-col gap-next-2" data-accept-result>
      <div class="flex flex-wrap items-center gap-next-2">
        <Badge v-if="result.relations > 0" variant="success" tone="subtle" icon="network" size="sm">
          {{ t('knowledge.relations.acceptedRelations', '', { count: result.relations }) }}
        </Badge>
      </div>

      <Accordion v-if="skippedLines.length > 0" type="multiple" class="flex flex-col gap-next-2">
        <AccordionItem
          value="skipped"
          icon="info"
          :title="t('knowledge.relations.skippedTitle', '', { count: skippedLines.length })"
        >
          <div class="flex flex-col gap-next-2">
            <!-- Says plainly that this is not a failure. It is the single most misread result. -->
            <Text variant="caption" tone="muted">{{ t('knowledge.relations.skippedHint') }}</Text>
            <ul class="flex flex-col gap-next-1">
              <li
                v-for="(line, index) in skippedLines"
                :key="`${line.code}-${index}`"
                class="flex items-start gap-next-2 text-next-sm text-next-muted-foreground"
                :data-skip="line.code"
              >
                <Icon :name="line.icon" class="mt-next-0_5 shrink-0" aria-hidden="true" />
                <span class="min-w-0 flex-1">
                  {{ line.title }}
                  <template v-if="line.hint"> — {{ line.hint }}</template>
                </span>
              </li>
            </ul>
          </div>
        </AccordionItem>
      </Accordion>
    </div>

    <!--
      WHAT THE SERVER REFUSED. Collapsed, but always present when non-empty — this is a section of
      the review, not a footnote. Absent entirely when there is nothing: "nothing was refused" is
      not information worth a heading.
    -->
    <Accordion v-if="rejectedLines.length > 0" type="multiple" class="flex flex-col gap-next-2">
      <AccordionItem
        value="rejected"
        icon="x-circle"
        :title="t('knowledge.relations.rejectedTitle', '', { count: rejectedLines.length })"
      >
        <div class="flex flex-col gap-next-2">
          <Text variant="caption" tone="muted">{{ t('knowledge.relations.rejectedHint') }}</Text>
          <ul class="flex flex-col gap-next-1">
            <li
              v-for="(line, index) in rejectedLines"
              :key="`${line.code}-${index}`"
              class="flex items-start gap-next-2 text-next-sm text-next-muted-foreground"
              :data-reject="line.code"
            >
              <Icon :name="line.icon" class="mt-next-0_5 shrink-0" aria-hidden="true" />
              <span class="min-w-0 flex-1">{{ line.title }}</span>
              <!-- The duplicate case is the only one with somewhere to go. -->
              <Button
                v-if="line.existingRelationId"
                variant="link"
                size="xs"
                data-show-existing
                @click="emit('show-relation', line.existingRelationId)"
              >
                {{ t('knowledge.relations.showExisting') }}
              </Button>
            </li>
          </ul>
        </div>
      </AccordionItem>
    </Accordion>

    <!-- The ADVISORY half. Never blocks anything, and the copy says "unusual", never "wrong". -->
    <Accordion v-if="warningLines.length > 0" type="multiple" class="flex flex-col gap-next-2">
      <AccordionItem
        value="warnings"
        icon="alert-triangle"
        :title="t('knowledge.relations.warningsTitle', '', { count: warningLines.length })"
      >
        <ul class="flex flex-col gap-next-2">
          <li
            v-for="(line, index) in warningLines"
            :key="`${line.code}-${index}`"
            class="flex items-start gap-next-2 text-next-sm"
            :data-warning="line.code"
          >
            <Icon :name="line.icon" class="mt-next-0_5 shrink-0 text-next-warning" aria-hidden="true" />
            <span class="flex min-w-0 flex-1 flex-col">
              <span class="text-next-fg">{{ line.title }}</span>
              <Text v-if="line.hint" variant="caption" tone="muted">{{ line.hint }}</Text>
            </span>
          </li>
        </ul>
      </AccordionItem>
    </Accordion>
  </section>
</template>
