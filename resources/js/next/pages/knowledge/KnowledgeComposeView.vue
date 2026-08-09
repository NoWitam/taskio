<script setup lang="ts">
// KnowledgeComposeView — the AI composer: source → generation → board (spec §24).
//
// After the AI-only pivot this is how entries come into existence. The user writes one block of
// unstructured text; an agent turns it into 1..N drafts; the user reviews, revises and accepts.
//
// ── IT SETTLES ON AN EVENT, NEVER A POLL ─────────────────────────────────────
// A standing user requirement in this project (recorded in `useSessionSettle.ts` and restated as
// spec DC1/R15): generation settles on the websocket push. `useComposeSettle` is the clone that
// does it for this module. Every wait ends in exactly one fetch — on the event, on the safety
// window, or (no Reverb) after one delayed retry that then offers a manual "Refresh".
//
// ── AVAILABILITY IS CHECKED BEFORE THE FORM RENDERS ──────────────────────────
// DC9. An over-cap or switched-off workspace gets an explanation instead of a field it can type
// into and a button that then 429s. The "New entry" affordances elsewhere stay ENABLED and lead
// here (D15) — the explanation belongs at the destination.
//
// ── AMENDMENTS: TWO ROUTES, ONE OF WHICH COSTS ───────────────────────────────
// A shadow draft rewrites an entry that already exists, so it can fail for a reason the reviewer
// did not cause: someone edited the target underneath it. That is surfaced BEFORE the accept
// button (`target_revision_stale` arrives on every fetch), and a refusal offers exactly two ways
// out — rebase, which only re-points the comparison and spends nothing, or revise, which is
// another agent run. Both say which is which, because this is the one place in the module where a
// UI choice has a money consequence.
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { onBeforeRouteLeave, useRoute, useRouter } from 'vue-router';
import PageHeader from '../../ui/patterns/PageHeader.vue';
import Surface from '../../ui/layout/Surface.vue';
import Alert from '../../ui/feedback/Alert.vue';
import Button from '../../ui/primitives/Button.vue';
import Text from '../../ui/primitives/Text.vue';
import Skeleton from '../../ui/data/Skeleton.vue';
import Icon from '../../ui/primitives/Icon.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import KnowledgeComposeSourceForm from './compose/KnowledgeComposeSourceForm.vue';
import KnowledgeComposeUnavailable from './compose/KnowledgeComposeUnavailable.vue';
import KnowledgeDraftBoard from './compose/KnowledgeDraftBoard.vue';
import KnowledgeRefineBar from './compose/KnowledgeRefineBar.vue';
import KnowledgeGraphUpdatesPanel from './compose/KnowledgeGraphUpdatesPanel.vue';
import KnowledgeFactChecklist from './compose/KnowledgeFactChecklist.vue';
import { noteLine, noteSlug, type ReportLine } from './relations/graphOpsReport';
import { composeLocation } from './composeSeed';
import { useComposeSettle } from './compose/useComposeSettle';
import { useKnowledgeStore } from '../../app/stores/knowledge';
import { useAiUsageStore } from '../../app/stores/aiUsage';
import { useToast } from '../../app/composables/useToast';
import { useConfirm } from '../../app/composables/useConfirm';
import { isBudgetError } from '../../app/lib/aiBudget';
import { useI18n } from '../../app/i18n';
import {
  KNOWLEDGE_CONTEXT_ALREADY_EXPANDED,
  type KnowledgeDraftEntry,
  type KnowledgeDraftSkipped,
  type KnowledgeProposedEntity,
  type KnowledgeProposedRelation,
} from './types';

const route = useRoute();
const router = useRouter();
const { t } = useI18n();
const store = useKnowledgeStore();
const aiUsage = useAiUsageStore();
const toast = useToast();
const confirm = useConfirm();
const settle = useComposeSettle();

const baseId = computed(() => String(route.params.baseId ?? ''));
const sessionId = computed(() => (route.params.session ? String(route.params.session) : null));
const base = computed(() => (store.openBase?.id === baseId.value ? store.openBase : null));

/** `?seed=<slug>` — a red link the user came from. `?amend=<entryId>` — "propose a change". */
const seedSlug = computed(() => (route.query.seed ? String(route.query.seed) : null));
/**
 * The human spelling of the seed, when the caller had one.
 *
 * `seed` is normalised to a slug server-side, so a name like "Kasia" would otherwise reach the new
 * entry as "kasia". Carried separately rather than inferred from the slug, because un-slugging is
 * guesswork and a person's name is the wrong place to guess.
 */
const seedTitle = computed(() => (route.query.seedTitle ? String(route.query.seedTitle) : null));
const amendId = computed(() => (route.query.amend ? String(route.query.amend) : null));

const source = ref('');
const refinePrompt = ref('');
const refineBarRef = ref<InstanceType<typeof KnowledgeRefineBar> | null>(null);

const starting = ref(false);
const refining = ref(false);
const busyId = ref<string | null>(null);
const bulkBusy = ref(false);
const bulkProgress = ref<{ done: number; total: number } | null>(null);
/** Ids published in THIS visit — the cards stay, muted, so progress is visible. */
const acceptedIds = ref<Set<string>>(new Set());
/** Per-draft server refusals (24.7), already turned into sentences. */
const draftErrors = ref<Record<string, string>>({});
/** Drafts whose acceptance CONFLICTED — the target moved. They get the two-route panel. */
const conflictedIds = ref<Set<string>>(new Set());
const rebasingId = ref<string | null>(null);
const expandingContext = ref(false);
/** The socket went quiet: offer ONE manual refresh, never a loop. */
const stalled = ref(false);

// --- Availability (before anything renders) ---------------------------------
watch(
  baseId,
  (id) => {
    if (id) void store.fetchComposeAvailability(id);
  },
  { immediate: true },
);

const availability = computed(() => store.composeAvailability);
const blocked = computed(() => availability.value != null && !availability.value.can_compose);

// --- The session ------------------------------------------------------------
watch(
  sessionId,
  (id) => {
    if (!id) {
      store.resetComposeSession();
      return;
    }
    void openSession(id);
  },
  { immediate: true },
);

/** Load a session and, if it is still running, wait for the push that says it is done. */
async function openSession(id: string): Promise<void> {
  const loaded = await store.fetchDraftSession(id);
  if (loaded && loaded.status === 'generating') await waitFor(id);
}

const session = computed(() => store.session);
const drafts = computed<KnowledgeDraftEntry[]>(() => session.value?.drafts ?? []);

// --- The graph half of the review ------------------------------------------
//
// Fetched from the RELATIONS endpoint rather than read off `session.graph_ops.graph_updates`, and
// the difference matters: `graph_ops` names both ends by HANDLE (`E1`), which no reviewer can
// read, while this list carries the titles resolved and the draft dependency spelled out. The
// refusals and warnings still come off the session, because they are the server's own record.
const proposedRelations = ref<KnowledgeProposedRelation[]>([]);
const proposedEntities = ref<KnowledgeProposedEntity[]>([]);
/** The relations request is in flight — the panel shows its shape instead of an empty verdict. */
const proposalsLoading = ref(false);
const boardRef = ref<InstanceType<typeof KnowledgeDraftBoard> | null>(null);

/**
 * "Go to the entry that covered this fact" — a scroll, not a route.
 *
 * A draft is not a page yet: it has no entry to navigate to, and a shadow's slug is a reserved
 * address that would 404. So this reuses the board's own scroll-and-ring, the same move the
 * relations panel makes when it points at a card.
 */
function focusDraft(draftId: string): void {
  boardRef.value?.focusDraft(draftId);
}

/**
 * The graph half has been written in THIS visit.
 *
 * Not persisted, because the session does not publish `applied_ops` — on a fresh entry the client
 * cannot tell an applied proposal from a waiting one, so it offers the action again. That is the
 * safe direction: applying is idempotent server-side and a redundant press comes back as
 * `already_applied`, while the alternative is what just happened to a whole base's graph.
 */
const graphApplied = ref(false);

/**
 * Write the graph half on its own — the escape from a silent loss.
 *
 * Accepting drafts card by card never reached the second phase (only the bulk button did), so a
 * reviewer could publish every entry and lose every relation while the relations sat ticked on
 * screen. `entry_ids: []` is the same single call the bulk path makes, so nothing about the
 * transaction changes; what changes is that it is now reachable.
 */
async function onApplyGraph(): Promise<void> {
  const keys = updatesPanelRef.value?.selectedKeys ?? [];
  if (keys.length === 0) return;

  bulkBusy.value = true;
  const ok = await publish([], 'approved', keys);
  bulkBusy.value = false;
  if (ok) graphApplied.value = true;
}
const updatesPanelRef = ref<InstanceType<typeof KnowledgeGraphUpdatesPanel> | null>(null);

/**
 * The held preview no longer matches the run — the server refused a key it does not know.
 *
 * A refinement RENUMBERS the operations, so a review left open across one is describing something
 * else. The server answers 422 rather than applying what it can, which is the right refusal: the
 * alternative is honouring half of an approval and dropping the rest in silence. Here it becomes
 * an offer to reload, not a red error, because nothing went wrong — the page simply got old.
 */
const selectionStale = ref(false);

/** The server refused a half-selected pair — its own sentence, shown verbatim. */
const selectionError = ref<string | null>(null);

async function reloadSession(): Promise<void> {
  const id = session.value?.id;
  if (!id) return;
  selectionStale.value = false;
  selectionError.value = null;
  await store.fetchDraftSession(id);
}

/**
 * The `wikilinks_lost` notes, reduced to `target slug => the links a rewrite would drop`.
 *
 * These deserve to be on the CARD rather than in a run-notes list, and it is not a matter of
 * taste: a dropped `[[wikilink]]` breaks the graph of OTHER entries, while the rewritten sentence
 * reads perfectly well without it. It is damage with no local symptom, which makes it exactly the
 * thing a reviewer's eye slides over unless it is placed on the proposal that causes it.
 *
 * The server allows the rewrite through rather than refusing it, because nothing can tell a
 * deliberate removal from a careless one — so the judgement is the reviewer's, and this is how
 * they are asked for it.
 */
/**
 * The run notes, split by whether they are ABOUT AN ENTRY or about the run.
 *
 * These are the only messages that answer "why did I get something other than what I asked for?".
 * A reviewer who requested a rewrite and received an append can read the card all day and never
 * learn that the entry was simply too long to show the composer in full — the card is honest about
 * WHAT will happen and silent about WHY, and silence there reads as "the model ignored me".
 *
 * Notes naming a `slug` go to that entry's card, because a note attached to the proposal it
 * describes is one a reader connects; the same sentence at the bottom of a long page is one they
 * do not. Everything else describes the run and belongs to the run.
 */
const notesBySlug = computed<Record<string, ReportLine[]>>(() => {
  const found: Record<string, ReportLine[]> = {};

  for (const note of session.value?.notes ?? []) {
    const slug = noteSlug(note);
    if (!slug) continue;
    (found[slug] ??= []).push(noteLine(note));
  }

  return found;
});

/** Notes with no entry to attach to — they describe the whole pass. */
/**
 * Whether the run said ANYTHING about facts.
 *
 * The checklist renders on a note alone, not only on a non-empty list: `facts_unavailable` and
 * `facts_truncated` are both about a list that is absent or short, and hiding the panel in those
 * cases would answer "were any facts checked?" with silence — the same silence this whole feature
 * exists to end.
 */
/** Notes the CHECKLIST renders in context, so the generic list must not repeat them. */
const FACT_NOTES = ['facts_truncated', 'facts_unavailable'];

/**
 * Rendered by their own banner rather than as a code in the notes list, and excluded from it for
 * the same reason: one situation described twice makes the reviewer reconcile two accounts of it.
 */
const OWN_BANNER_NOTES = ['protagonist_without_entry'];

/**
 * SOMEBODY THE MATERIAL KEEPS TALKING ABOUT HAS NO ENTRY OF THEIR OWN.
 *
 * The dominant defect in measurement, and it does not look like one defect from below. A source
 * about a woman it only ever calls "influencerka" produced seven entries — every city she visited,
 * the man she met, the contest she ran — and none for her. Everything downstream then broke in ways
 * that read as separate problems: the cities became the travellers, three relations were refused as
 * place-to-place, the incident had no subject to hang an edge on, and `[[influencerka]]` went red.
 * One cause, four symptoms, none of which points back at it.
 *
 * Worth knowing how sure this is: the server compares slugified titles and aliases, so an entry
 * covering the subject under a different name will trip it. That is why the copy is an OBSERVATION
 * to check rather than a verdict — "the material talks about X and no entry carries that name",
 * not "an entry is missing".
 */
const missingProtagonists = computed(() =>
  (session.value?.notes ?? [])
    .filter((note) => note.code === 'protagonist_without_entry')
    .map((note) => ({
      title: typeof note.title === 'string' ? note.title : '',
      description: typeof note.description === 'string' ? note.description : '',
    }))
    .filter((item) => item.title !== ''),
);
const factsNoteCodes = computed(() =>
  (session.value?.notes ?? []).filter((note) => FACT_NOTES.includes(note.code)),
);

/**
 * The run notes MINUS the fact codes — those are rendered by the checklist, in context.
 *
 * Saying the same thing twice, once as a bare code and once beside the fact it names, would make
 * the reviewer reconcile two lists that describe one situation.
 */
const runNotes = computed<ReportLine[]>(() =>
  (session.value?.notes ?? [])
    .filter(
      (note) =>
        noteSlug(note) === null &&
        !FACT_NOTES.includes(note.code) &&
        !OWN_BANNER_NOTES.includes(note.code),
    )
    .map(noteLine),
);

/**
 * Names the run could not place, and entries it was never shown.
 *
 * Both describe what the composer did NOT do, and both are invisible by nature: an absent proposal
 * looks exactly like a considered decision not to propose one. That is the reading these sections
 * exist to prevent —
 *
 *   unresolved  the model said "I cannot tell who this is". Nothing was proposed for the name.
 *               Without the list, a reviewer concludes the model judged the person unimportant,
 *               which is the opposite of what it reported.
 *   omitted     entries that matched but did not fit the context ceiling. The model never saw
 *               them, so silence about them is a fact about the BUDGET, not an opinion.
 *
 * `omitted` sits with `resolution_degraded` because it is the same class of caveat: both change
 * how everything below should be read, which is why they are above the board rather than under it.
 */
const unresolvedMentions = computed(() => session.value?.graph_ops?.unresolved ?? []);
const omittedTitles = computed(() => session.value?.resolution?.omitted ?? []);

/**
 * Open the composer seeded with an unrecognised name.
 *
 * The mention is a display name ("Kasia"), not a slug, and that is fine: `seed` is normalised
 * server-side with `Str::slug()`, and the title is carried separately so the new entry keeps its
 * capitalisation rather than being reborn as "kasia".
 */
function createFromMention(mention: string): void {
  if (!baseId.value) return;
  void router.push(composeLocation(baseId.value, mention, mention));
}

/**
 * What the last accept actually did to the graph — held on screen, not only in a toast.
 *
 * A toast that says "2 skipped" and then disappears is a fact the reviewer cannot go back and
 * read. The counts and the reasons stay under the panel until the next accept replaces them.
 */
const lastResult = ref<{
  relations: number;
  skipped: KnowledgeDraftSkipped[];
} | null>(null);

watch(
  () => [session.value?.id, session.value?.updated_at] as const,
  async ([id]) => {
    if (!id) {
      proposedRelations.value = [];
      proposedEntities.value = [];
      proposalsLoading.value = false;

      return;
    }
    proposalsLoading.value = true;
    try {
      const data = await store.fetchDraftRelations(id);
      proposedRelations.value = data.proposed_relations ?? [];
      proposedEntities.value = data.proposed_entities ?? [];
      // A new set of operations is a new decision: whatever was applied belonged to the old one.
      graphApplied.value = false;
    } catch {
      // The graph preview failing must not take the draft board down with it: the drafts are the
      // thing being reviewed, and this section is an explanation of what else will happen.
      proposedRelations.value = [];
      proposedEntities.value = [];
    } finally {
      proposalsLoading.value = false;
    }
  },
  { immediate: true },
);

/**
 * The HANDLES of drafts already published in this session.
 *
 * This is what releases a dependency block live. `depends_on_draft` names composer handles while
 * `acceptedIds` holds entry ids, so the two are joined through `proposed_entities` — a draft whose
 * entry id has been accepted stops blocking, and the row unlocks under the reviewer's hand instead
 * of staying greyed with a reason that has stopped being true.
 */
const acceptedHandles = computed(() =>
  proposedEntities.value
    .filter((entity) => {
      const draft = drafts.value.find((row) => row.title === entity.title);

      // NO DRAFT ON THE BOARD MEANS NOTHING IS BEING WAITED FOR.
      //
      // This used to answer `false` — "still blocked" — and it was the second half of the silent
      // loss. Publishing a draft removes it from the board, so once every entry was accepted the
      // board went empty and EVERY relation that named a new entity stayed blocked forever: no
      // selectable operation, no count, and nothing offering to write them. The proposals were
      // fine and permanently out of reach.
      //
      // A draft that is not on the board is published or refused. Either way it is not pending,
      // and the write path re-checks the ends anyway — an entity that genuinely is not there comes
      // back as a `dependency_not_accepted` skip, reported rather than assumed.
      return draft ? acceptedIds.value.has(draft.id) : true;
    })
    .map((entity) => entity.handle),
);
const generating = computed(() => session.value?.status === 'generating');
const failed = computed(() => session.value?.status === 'failed');

/** The board has iterated at least once, so "previous draft" has something to compare with. */
const hasPreviousIteration = computed(() => (session.value?.prompt_history?.length ?? 0) > 0);

/**
 * Wait for the run to settle. ONE call, no loop — see `useComposeSettle`. A non-settled outcome is
 * the socket giving up, which surfaces as a refresh affordance rather than silence.
 */
async function waitFor(id: string): Promise<void> {
  stalled.value = false;
  const outcome = await settle.waitForSettle(id);
  if (!outcome.settled) stalled.value = true;
  // Every run spends budget, so the meter is refreshed on completion — not only on refusal, or the
  // budget signal keeps showing a stale figure until the page is reloaded.
  void aiUsage.fetchAiUsage();
  void store.fetchComposeAvailability(baseId.value);
}

// --- Starting ---------------------------------------------------------------
async function onStart(text: string): Promise<void> {
  if (!baseId.value) return;
  starting.value = true;
  try {
    const created = await store.startDraftSession(baseId.value, {
      source_text: text,
      ...(seedSlug.value ? { seed_slug: seedSlug.value } : {}),
      ...(seedTitle.value ? { seed_title: seedTitle.value } : {}),
    });
    // The session id belongs in the URL: a refresh, a deep link and a back-navigation all have to
    // find the same board.
    await router.replace({
      name: 'next.knowledge.base.compose',
      params: { baseId: baseId.value, session: created.id },
      query: route.query,
    });
    await waitFor(created.id);
  } catch (err: unknown) {
    if (isBudgetError(err)) {
      // A typed budget refusal is a STATE, not an error: re-ask availability so the screen swaps
      // to the explanation with its reset date.
      await store.fetchComposeAvailability(baseId.value);
    } else {
      toast.danger(t('knowledge.compose.startError'));
    }
  } finally {
    starting.value = false;
  }
}

// --- Refining ---------------------------------------------------------------
async function onRefine(instruction: string): Promise<void> {
  const id = session.value?.id;
  if (!id) return;
  refining.value = true;
  try {
    const updated = await store.refineDraftSession(id, instruction);
    refinePrompt.value = '';
    if (updated.status === 'generating') await waitFor(id);
  } catch (err: unknown) {
    if (isBudgetError(err)) await store.fetchComposeAvailability(baseId.value);
    else toast.danger(t('knowledge.compose.refineError'));
  } finally {
    refining.value = false;
  }
}

/** "Revise this entry" — prefill a SCOPED instruction and put the caret after it. */
function onRefineDraft(draft: KnowledgeDraftEntry): void {
  refinePrompt.value = t('knowledge.compose.refineScoped', '', {
    title: draft.targets_entry?.title ?? draft.title,
  });
  refineBarRef.value?.focusEnd?.();
}

// --- Accepting --------------------------------------------------------------
/** Map a server refusal onto the card's own sentence; the SERVER's message wins when it has one. */
function acceptErrorOf(err: unknown): string {
  const data = (err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })
    ?.response?.data;
  const errors = data?.errors ?? {};

  for (const [field, messages] of Object.entries(errors)) {
    const first = Array.isArray(messages) ? messages[0] : null;
    if (!first) continue;
    if (field.startsWith('metadata.')) return `${field}: ${first}`;
    return first;
  }
  return data?.message ?? t('knowledge.compose.error.generic');
}

/** Accept ONE draft. A high overlap confirms first, naming both entries (DC7). */
async function onAccept(draft: KnowledgeDraftEntry, status: 'approved' | 'draft'): Promise<void> {
  const duplicate = session.value?.duplicates?.[draft.id] ?? null;
  const percent = duplicate ? Math.round(duplicate.score * 100) : 0;

  if (duplicate && percent >= 90) {
    const ok = await confirm({
      title: t('knowledge.compose.duplicateConfirm.title'),
      message: t('knowledge.compose.duplicateConfirm.message', '', {
        draft: draft.title,
        existing: duplicate.title,
        percent,
      }),
      confirmLabel: t('knowledge.compose.accept'),
      cancelLabel: t('common.cancel'),
    });
    if (!ok) return;
  }

  busyId.value = draft.id;
  // Accepting ONE card is about that card: the graph half is deferred, never carried along.
  await publish([draft.id], status, deferredGraphKeys());
  busyId.value = null;
}

/**
 * The bulk action: explicit, counted, confirmed, and it stops at the first refusal.
 *
 * TWO PHASES, and the split matters. Drafts publish ONE AT A TIME, because each is an independent
 * proposal and a conflict on one must not take the others down. The graph half is a DESCRIPTION OF
 * A CHANGE — the server applies it in a single transaction for the same reason — so it goes once,
 * at the end, after the entries its operations may depend on exist.
 *
 * The draft loop therefore sends an EMPTY key list rather than omitting the field: omitting means
 * "apply everything", so a loop of five drafts would have applied the graph half on the first pass
 * and reported it as `already_applied` four times. Empty means "none", which is what each of those
 * five calls actually wants.
 */
async function onAcceptSelected(ids: string[]): Promise<void> {
  const opKeys = updatesPanelRef.value?.opKeys;
  const graphKeys = opKeys ?? [];

  // NOTHING TO DO only when BOTH halves are empty. A graph-only acceptance ("Anna left Acme in
  // July" — two existing entries, no new entry) is the commonest incremental update there is, and
  // returning early on `ids.length === 0` made it unreachable from the UI entirely.
  if (ids.length === 0 && graphKeys.length === 0) return;

  const ok = await confirm({
    title: t('knowledge.compose.acceptSelectedConfirm.title'),
    message: t('knowledge.compose.acceptSelectedConfirm.message', '', {
      count: ids.length + graphKeys.length,
    }),
    confirmLabel: t('knowledge.compose.accept'),
    cancelLabel: t('common.cancel'),
  });
  if (!ok) return;

  bulkBusy.value = true;
  const total = ids.length + (graphKeys.length > 0 ? 1 : 0);
  bulkProgress.value = { done: 0, total };

  let stopped = false;
  for (const id of ids) {
    const failedHere = !(await publish([id], 'approved', deferredGraphKeys()));
    bulkProgress.value = { done: (bulkProgress.value?.done ?? 0) + 1, total };
    // Stop on the first refusal: the details are on that card, and pushing on would bury them.
    if (failedHere) {
      stopped = true;
      break;
    }
  }

  // The graph half, once, with no entries — every draft it needed is already published.
  if (!stopped && graphKeys.length > 0) {
    await publish([], 'approved', graphKeys);
    bulkProgress.value = { done: (bulkProgress.value?.done ?? 0) + 1, total };
  }

  bulkBusy.value = false;
  bulkProgress.value = null;
}

/**
 * What a DRAFT-ONLY call should say about the graph half — and the two answers are not the same.
 *
 *   undefined  the run proposed no operations. There is nothing to have an opinion about, so the
 *              field is OMITTED and the request looks exactly as it did before selection existed.
 *   []         operations exist and this call is deliberately not applying them; they go in one
 *              call of their own afterwards. Empty means "none", which is what this call wants.
 *
 * Collapsing the two would send `[]` on every ordinary run and quietly turn "I have nothing to say"
 * into "I refuse everything" — indistinguishable here, and not indistinguishable to a server that
 * treats an explicit total refusal as a decision.
 */
function deferredGraphKeys(): string[] | undefined {
  return updatesPanelRef.value?.opKeys === undefined ? undefined : [];
}

/**
 * One accept call. Returns whether everything asked for actually published.
 *
 * `opKeys` is passed IN rather than read from the panel here, so a caller can say which half of
 * the acceptance this call is about — and so the `catch` can report against keys that cannot have
 * changed under it mid-request.
 */
async function publish(
  ids: string[],
  status: 'approved' | 'draft',
  opKeys?: string[],
): Promise<boolean> {
  const sessionRef = session.value;
  if (!sessionRef) return false;

  try {
    // OMITTING the argument is a distinct act from passing an empty list, so it is written as one.
    // `undefined` handed to a 4-argument call still occupies the slot, and the whole point of the
    // three-valued contract is that "I have nothing to say" and "I want none of them" travel
    // differently.
    const result =
      opKeys === undefined
        ? await store.acceptDrafts(sessionRef.id, ids, status)
        : await store.acceptDrafts(sessionRef.id, ids, status, opKeys);

    const next = new Set(acceptedIds.value);
    for (const entry of result.accepted) next.add(entry.id);
    acceptedIds.value = next;

    for (const id of ids) {
      if (next.has(id)) delete draftErrors.value[id];
    }
    draftErrors.value = { ...draftErrors.value };

    // A CONFLICT is a shadow whose target was edited in parallel. Its card grows the two-route
    // panel (rebase — free; revise — costs) instead of an error line, and the drafts that DID
    // publish are still reported as the successes they are: partial acceptance is the normal case,
    // and telling a reviewer that none of their five went through because one target moved would
    // be a lie the response itself contradicts.
    if (result.conflicts.length > 0) {
      const next = new Set(conflictedIds.value);
      for (const conflict of result.conflicts) next.add(conflict.entry_id);
      conflictedIds.value = next;
      // The stale flags moved server-side; refetch so the badges match what just happened.
      await store.fetchDraftSession(sessionRef.id);
    }

    // THE GRAPH HALF OF THE RESULT, reported as information rather than as damage.
    //
    // `skipped` is the ordinary outcome of accepting a subset — an operation whose other end was
    // not in this batch simply did not run — so it is an INFO toast and never a red one. A 200
    // with four saved and two skipped is a normal Tuesday, and colouring it as a failure would
    // teach reviewers to fear their own partial approvals.
    //
    // THERE IS NO `updated` LIST, and its absence is correct rather than a gap. It was always
    // empty — the applier set a key nothing wrote — and filling it would have duplicated
    // `accepted`: publishing an amendment returns the LIVE TARGET and destroys the shadow, so the
    // row that changed is already in `accepted` for every amendment. The concept moved too: the
    // graph half no longer writes entry content at all, now that `wiki_updates` against existing
    // entries become reviewable shadow drafts.
    lastResult.value = {
      relations: result.relations?.length ?? 0,
      skipped: result.skipped ?? [],
    };

    // NEW ENTRY or CHANGED ONE — told apart by whether the draft was a SHADOW, not by the
    // response. `accepted` carries the target's id for an amendment, so it cannot be matched back
    // to the draft that produced it; what we do know is which cards we just sent.
    if (result.accepted.length > 0) {
      const sent = drafts.value.filter((draft) => ids.includes(draft.id));
      const amended = sent.filter((draft) => !!draft.targets_entry).length;
      // …and only when the amendment actually LANDED. A shadow that conflicted changed nothing,
      // so saying "change saved to the existing entry" would report a write that did not happen —
      // next to a card showing the conflict that stopped it.
      const allAmendments =
        sent.length > 0 && amended === sent.length && (result.conflicts?.length ?? 0) === 0;

      toast.success(
        allAmendments
          ? t("knowledge.compose.acceptedAmendmentToast")
          : t("knowledge.compose.acceptedToast"),
      );
    }
    if ((result.relations?.length ?? 0) > 0) {
      toast.info(
        t('knowledge.relations.acceptedRelations', '', { count: result.relations.length }),
      );
    }
    if ((result.skipped?.length ?? 0) > 0) {
      toast.info(t('knowledge.relations.skippedTitle', '', { count: result.skipped.length }));
    }

    return result.conflicts.length === 0;
  } catch (err: unknown) {
    // A key the server does not recognise means the PREVIEW is stale, not that the reviewer did
    // anything wrong — a refinement renumbered the operations under an open review. Nothing was
    // written (the whole request is refused), so this is an offer to reload rather than a failure
    // to report against the drafts, which are perfectly fine.
    // A refusal about the SELECTION. Nothing was written — the server rejects the whole request
    // rather than applying what it can — so neither branch reports against the drafts.
    if (opKeyErrors(err).length > 0) {
      if (await previewMoved(sessionRef.id, opKeys)) {
        // The run was refined under an open review and the operations were renumbered.
        selectionStale.value = true;
      } else {
        // A HALF-SELECTED PAIR. The panel merges the two halves into one control precisely so this
        // cannot happen, so reaching here means something drifted — a contract change, a bug.
        // Shown in the server's own words because they name WHICH operations belong together,
        // which is the only thing that would help. Defensive rather than dead code: an interface
        // that fails gracefully only while its own invariant holds reports bugs as mysteries.
        selectionError.value = selectionMessageOf(err);
      }

      return false;
    }

    const message = acceptErrorOf(err);
    for (const id of ids) draftErrors.value = { ...draftErrors.value, [id]: message };
    return false;
  }
}

/** The messages a 422 attached to `graph_op_keys`, if any. */
function opKeyErrors(err: unknown): string[] {
  const response = (err as { response?: { status?: number; data?: { errors?: Record<string, unknown> } } })
    ?.response;
  if (response?.status !== 422) return [];
  const messages = response?.data?.errors?.graph_op_keys;

  return Array.isArray(messages) ? messages.filter((m): m is string => typeof m === 'string') : [];
}

/**
 * Told apart STRUCTURALLY, never by reading the message — by asking the SERVER what it holds now.
 *
 * Two different refusals land on the same field: `unknown_op_key` ("your preview is old", wants a
 * reload) and `inseparable_ops` ("these two go together", wants the sentence shown). Matching the
 * server's prose to tell them apart would break on the next wording change and in the other
 * language, which this module never depends on.
 *
 * THE EARLIER VERSION OF THIS CHECK WAS WRONG, and wrong in a way that made the stale banner
 * unreachable. It compared the keys we sent against the preview WE were holding — but we only ever
 * send keys from that preview, so the answer was always "all known" and the stale branch could not
 * fire. The one scenario it exists for is precisely the one it could not detect: two tabs, or two
 * reviewers, on one session. Tab A holds a preview; tab B refines; the run is renumbered; tab A
 * presses accept with keys that are perfectly valid LOCALLY and unknown to the server.
 *
 * So the comparison is made against a FRESH read. Costing one request on the error path is the
 * right trade: it happens only when a write was already refused, and the reader needs that fresh
 * preview anyway to choose again.
 */
async function previewMoved(sessionId: string, sent: string[] | undefined): Promise<boolean> {
  if (!sent || sent.length === 0) return false;

  try {
    const fresh = await store.fetchDraftRelations(sessionId);
    const known = new Set((fresh.proposed_relations ?? []).map((proposal) => proposal.key));

    return sent.some((key) => !known.has(key));
  } catch {
    // The refetch itself failed, so nothing can be concluded about the keys. Reporting a stale
    // preview here would tell the user to reload something we could not read either.
    return false;
  }
}

/** The server's own sentence about the selection — it names WHICH operations belong together. */
function selectionMessageOf(err: unknown): string {
  return opKeyErrors(err)[0] ?? t('knowledge.common.saveError');
}

// --- Rebase + expand context -------------------------------------------------
/**
 * Re-point a proposal at its target's CURRENT text. Free — no model call — and the copy says so,
 * because the alternative route (revise) is not, and a reviewer choosing between them deserves to
 * know which one spends.
 */
async function onRebase(draft: KnowledgeDraftEntry): Promise<void> {
  const sessionRef = session.value;
  if (!sessionRef) return;

  rebasingId.value = draft.id;
  try {
    await store.rebaseDraft(sessionRef.id, draft.id);
    // Refetch the session, not just the draft: `target_revision_stale` is what drives the badge on
    // the card AND the note inside the diff, and both have to agree after this.
    await store.fetchDraftSession(sessionRef.id);

    const next = new Set(conflictedIds.value);
    next.delete(draft.id);
    conflictedIds.value = next;

    toast.success(t('knowledge.compose.conflict.rebased'));
  } catch {
    toast.danger(t('knowledge.common.saveError'));
  } finally {
    rebasingId.value = null;
  }
}

/**
 * Widen the evidence for the NEXT run. This SPENDS one metered embedding pass, which is why it is
 * an explicit button with its price written next to it rather than something a revision does
 * quietly on the user's behalf.
 */
async function onExpandContext(): Promise<void> {
  const sessionRef = session.value;
  if (!sessionRef || expandingContext.value) return;

  expandingContext.value = true;
  try {
    await store.expandDraftContext(sessionRef.id);
    toast.success(t('knowledge.compose.expandContextDone'));
    void aiUsage.fetchAiUsage();
  } catch (err: unknown) {
    // ALREADY WIDENED — the other reviewer got there first (or this tab was looking at a stale
    // session). Nothing was spent and the state the user wanted is the state that exists, so this
    // is a success with a different explanation, not an error. Refetching is what turns the button
    // off here too: `context_expanded_at` is the thing that disables it.
    if (alreadyExpanded(err)) {
      await store.fetchDraftSession(sessionRef.id);
      toast.info(t('knowledge.compose.expandContextAlready'));
      return;
    }
    // A budget refusal is a STATE with a reset date, not a failure to retry: re-ask availability
    // so the screen swaps to the explanation instead of showing a red toast that suggests a bug.
    if (isBudgetError(err)) await store.fetchComposeAvailability(baseId.value);
    else toast.danger(t('knowledge.compose.expandContextError'));
  } finally {
    expandingContext.value = false;
  }
}

/** The 422 that says the context was already widened — a state conflict, not a failure. */
function alreadyExpanded(err: unknown): boolean {
  const data = (err as { response?: { data?: { code?: unknown } } })?.response?.data;
  return data?.code === KNOWLEDGE_CONTEXT_ALREADY_EXPANDED;
}

// --- Rejecting --------------------------------------------------------------
/** Reversible and low-stakes → a toast, not a dialog (D13). */
async function onReject(draft: KnowledgeDraftEntry): Promise<void> {
  busyId.value = draft.id;
  try {
    await store.rejectDraft(draft.id);
    if (session.value) await store.fetchDraftSession(session.value.id);
    toast.success(t('knowledge.compose.rejected'));
  } catch {
    toast.danger(t('knowledge.common.saveError'));
  } finally {
    busyId.value = null;
  }
}

// --- Navigation -------------------------------------------------------------
function openEntry(slug: string): void {
  void router.push({ name: 'next.knowledge.base.reader', params: { baseId: baseId.value, slug } });
}

function backToReader(): void {
  void router.push({ name: 'next.knowledge.base.reader', params: { baseId: baseId.value } });
}

function goToUsage(): void {
  void router.push({ name: 'next.settings.aiUsage' });
}

/** Start over: the composer with no session. */
function startOver(): void {
  void router.replace({ name: 'next.knowledge.base.compose', params: { baseId: baseId.value } });
}

// --- Guarded exit -----------------------------------------------------------
/** Drafts that are neither accepted nor discarded — leaving throws them away. */
const unresolvedCount = computed(
  () => drafts.value.filter((d) => !acceptedIds.value.has(d.id)).length,
);

onBeforeRouteLeave(async () => {
  if (unresolvedCount.value === 0) return true;
  return await confirm({
    title: t('knowledge.compose.leave.title'),
    message: t('knowledge.compose.leave.message', '', { count: unresolvedCount.value }),
    confirmLabel: t('knowledge.compose.leave.confirm'),
    cancelLabel: t('common.cancel'),
    variant: 'danger',
  });
});

onBeforeUnmount(() => {
  settle.dispose();
  store.resetComposeSession();
});

// --- View state -------------------------------------------------------------
const readyAnnouncement = computed(() =>
  session.value?.status === 'ready'
    ? t('knowledge.compose.ready', '', { count: drafts.value.length })
    : '',
);
</script>

<template>
  <div class="flex min-h-0 flex-col gap-next-4">
    <PageHeader
      :title="t('knowledge.compose.title')"
      :description="t('knowledge.compose.subtitle')"
      icon="sparkles"
    />

    <!-- DC9: the gate answers BEFORE the form exists. -->
    <KnowledgeComposeUnavailable
      v-if="blocked"
      :reason="availability?.reason ?? null"
      :resets-at="availability?.budget?.period?.resets_at ?? null"
      :summary="aiUsage.summary"
      @manage="goToUsage"
      @back="backToReader"
    />

    <template v-else>
      <!-- The session the URL points at is gone. Its source text was never persisted, and saying so
           is kinder than a spinner that resolves to nothing. -->
      <EmptyState
        v-if="store.sessionMissing"
        icon="sparkles"
        :title="t('knowledge.compose.expired.title')"
        :description="t('knowledge.compose.expired.description')"
      >
        <template #action>
          <Button size="sm" leading-icon="sparkles" @click="startOver">
            {{ t('knowledge.compose.expired.action') }}
          </Button>
        </template>
      </EmptyState>

      <!-- Phase 1: the one field everything starts from. -->
      <KnowledgeComposeSourceForm
        v-else-if="!sessionId"
        v-model="source"
        :availability="availability"
        :seed-slug="seedSlug"
        :amend-title="amendId ? (base?.name ?? null) : null"
        :first-session="(base?.entries_count ?? 0) === 0"
        :submitting="starting"
        @submit="onStart"
        @cancel="backToReader"
      />

      <template v-else>
        <!-- The source, collapsed to a line once the run has started. -->
        <Surface
          v-if="session"
          bg="card"
          border
          radius="lg"
          class="flex flex-wrap items-center gap-next-2 px-next-4 py-next-2"
        >
          <Text variant="caption" tone="muted" :clamp="1" class="min-w-0 flex-1">
            {{ session.source_text }}
          </Text>
        </Surface>

        <!-- Phase 2: generating. Skeletons shaped like the cards, never a spinner. -->
        <template v-if="generating">
          <Alert variant="info" size="sm">{{ t('knowledge.compose.generating') }}</Alert>

          <div
            class="flex flex-col gap-next-4"
            role="status"
            :aria-label="t('knowledge.compose.generatingLabel')"
          >
            <Surface
              v-for="n in 3"
              :key="`sk-${n}`"
              bg="card"
              border
              radius="lg"
              class="flex flex-col gap-next-3 p-next-4"
            >
              <Skeleton variant="text" width="45%" height="1.25rem" />
              <div class="flex gap-next-2">
                <Skeleton variant="rect" width="5rem" height="1.25rem" radius="full" />
                <Skeleton variant="rect" width="5rem" height="1.25rem" radius="full" />
              </div>
              <Skeleton variant="text" width="95%" />
              <Skeleton variant="text" width="100%" />
              <Skeleton variant="text" width="88%" />
              <Skeleton variant="text" width="60%" />
              <Skeleton variant="text" width="40%" />
            </Surface>
          </div>
        </template>

        <!-- The socket said nothing. ONE manual refresh — never a poll loop. -->
        <Alert v-else-if="stalled" variant="warning" size="sm">
          <div class="flex flex-wrap items-center justify-between gap-next-2">
            <span>{{ t('knowledge.compose.stalled') }}</span>
            <Button
              variant="outline"
              size="sm"
              leading-icon="rotate-ccw"
              @click="sessionId && store.fetchDraftSession(sessionId)"
            >
              {{ t('knowledge.compose.refresh') }}
            </Button>
          </div>
        </Alert>

        <!-- Failed: the server's own reason, mapped to copy — never "something went wrong". -->
        <EmptyState
          v-if="failed"
          variant="error"
          :title="t('knowledge.compose.failed.title')"
          :description="
            t(
              `knowledge.compose.failed.reason.${session?.failure_reason ?? 'provider'}`,
              t('knowledge.compose.failed.reason.provider'),
            )
          "
        >
          <template #action>
            <Button size="sm" leading-icon="rotate-ccw" @click="onStart(session?.source_text ?? '')">
              {{ t('knowledge.compose.failed.retry') }}
            </Button>
          </template>
          <template #secondary>
            <Button variant="link" size="sm" @click="startOver">
              {{ t('knowledge.compose.failed.editSource') }}
            </Button>
          </template>
        </EmptyState>

        <!-- Phase 3: the board. -->
        <template v-else-if="session && !generating">
          <!-- A blind user cannot see the skeletons disappear; this is how they learn it finished. -->
          <p class="sr-only" aria-live="polite">{{ readyAnnouncement }}</p>

          <!--
            WHAT HAPPENED TO THIS RUN, for the notes that name no entry.
            Above the board rather than below it: `resolution_degraded` changes how every card
            underneath should be read ("not found" may mean "not looked for properly"), and a
            caveat placed after the thing it qualifies is a caveat read too late.
          -->
          <Surface
            v-if="runNotes.length > 0 || unresolvedMentions.length > 0 || omittedTitles.length > 0"
            bg="card"
            border
            radius="lg"
            class="flex flex-col gap-next-3 p-next-4"
            data-run-notes
          >
            <Text variant="ui" class="font-next-medium">
              {{ t('knowledge.compose.notesTitle', '', { count: runNotes.length }) }}
            </Text>
            <ul v-if="runNotes.length > 0" class="flex flex-col gap-next-2">
              <li
                v-for="note in runNotes"
                :key="note.code"
                class="flex items-start gap-next-2 text-next-sm"
                :data-note="note.code"
              >
                <Icon :name="note.icon" class="mt-next-0_5 shrink-0 text-next-muted-foreground" aria-hidden="true" />
                <span class="flex min-w-0 flex-col">
                  <span class="text-next-fg">{{ note.title }}</span>
                  <Text v-if="note.hint" variant="caption" tone="muted">{{ note.hint }}</Text>
                </span>
              </li>
            </ul>

            <!--
              ENTRIES THE COMPOSER NEVER SAW. Filed with the run notes because it is the same class
              of caveat as `resolution_degraded`: it changes how everything below should be read.
              Without it, "the agent proposed nothing about our refund policy" is indistinguishable
              from "the agent was never shown our refund policy", and only one of those is an
              opinion worth acting on.
            -->
            <div
              v-if="omittedTitles.length > 0"
              class="flex items-start gap-next-2 text-next-sm"
              data-omitted
            >
              <Icon name="info" class="mt-next-0_5 shrink-0 text-next-muted-foreground" aria-hidden="true" />
              <span class="flex min-w-0 flex-col">
                <span class="text-next-fg">
                  {{ t('knowledge.compose.omitted', '', { titles: omittedTitles.join(', ') }) }}
                </span>
                <Text variant="caption" tone="muted">{{ t('knowledge.compose.omittedHint') }}</Text>
              </span>
            </div>

            <!--
              NAMES THE RUN COULD NOT PLACE. Nothing was proposed for these — no entry, no relation
              — and an absent proposal is indistinguishable from a considered decision not to make
              one. A reviewer reading silence concludes the agent judged the person unimportant,
              which is the opposite of what it actually reported.
            -->
            <div v-if="unresolvedMentions.length > 0" class="flex flex-col gap-next-2" data-unresolved>
              <div class="flex items-start gap-next-2 text-next-sm">
                <Icon name="help-circle" class="mt-next-0_5 shrink-0 text-next-muted-foreground" aria-hidden="true" />
                <span class="flex min-w-0 flex-col">
                  <span class="text-next-fg">
                    {{ t('knowledge.compose.unresolvedTitle', '', { count: unresolvedMentions.length }) }}
                  </span>
                  <Text variant="caption" tone="muted">
                    {{ t('knowledge.compose.unresolvedHint') }}
                  </Text>
                </span>
              </div>

              <ul class="flex flex-col gap-next-1 ps-next-6">
                <li
                  v-for="item in unresolvedMentions"
                  :key="item.mention"
                  class="flex flex-wrap items-center gap-next-2 text-next-sm"
                  :data-unresolved-mention="item.mention"
                >
                  <span class="font-next-medium text-next-fg">{{ item.mention }}</span>
                  <Text v-if="item.note" variant="caption" tone="muted">{{ item.note }}</Text>
                  <!--
                    The way OUT of the dead end, using the seeding mechanism red links already
                    have. Reporting a gap without offering the one action that closes it would
                    leave the reviewer to retype the name somewhere else.
                  -->
                  <Button
                    variant="link"
                    size="xs"
                    :data-create-from-mention="item.mention"
                    @click="createFromMention(item.mention)"
                  >
                    {{ t('knowledge.compose.unresolvedCreate', '', { name: item.mention }) }}
                  </Button>
                </li>
              </ul>
            </div>
          </Surface>

          <!--
            THE CHECKLIST GOES ABOVE THE BOARD, and the position is the design.

            It is what the reviewer reads the cards AGAINST, so it has to be read BEFORE them:
            below the board it would arrive as a verdict on work already approved, and beside it —
            in a column — it would be the first thing collapsed on a narrow screen, which is
            exactly the reader who most needs to be told what to look for.

            It sits with the run-notes summary for the same reason `resolution_degraded` does:
            both change how everything underneath should be read.
          -->
          <!--
            THE LOUDEST THING ON THE SCREEN WHEN IT APPEARS, and above the checklist because it is
            the one ACTIONABLE observation here — the facts below are reading material.

            It goes first for the same reason the run notes do: it changes how everything under it
            should be read. A reviewer who approves eight entries without noticing that their
            subject has none has approved a base whose graph cannot work, and they will meet the
            consequence four separate times without ever meeting the cause.

            Rare, so nothing protrudes when the run is fine.
          -->
          <Alert
            v-for="person in missingProtagonists"
            :key="person.title"
            variant="warning"
            :data-missing-protagonist="person.title"
          >
            {{ t('knowledge.compose.facts.protagonistTitle', '', { title: person.title }) }}
            <template #actions>
              <Text variant="caption" tone="muted">
                {{ t('knowledge.compose.facts.protagonistHint') }}
              </Text>
            </template>
          </Alert>

          <KnowledgeFactChecklist
            v-if="session.facts?.length || factsNoteCodes.length > 0"
            :facts="session.facts ?? []"
            :notes="session.notes ?? []"
            @open="focusDraft"
          />

          <KnowledgeDraftBoard
            ref="boardRef"
            :session-id="session.id"
            :drafts="drafts"
            :base="base"
            :duplicates="session.duplicates"
            :notes="notesBySlug"
            :graph-op-count="updatesPanelRef?.selectedKeys?.length ?? 0"
            :accepted-ids="acceptedIds"
            :errors="draftErrors"
            :conflicted-ids="conflictedIds"
            :rebasing-id="rebasingId"
            :context-expanded="!!session.context_expanded_at"
            :expanding-context="expandingContext"
            :busy-id="busyId"
            :bulk-busy="bulkBusy"
            :bulk-progress="bulkProgress"
            :has-previous-iteration="hasPreviousIteration"
            @accept="onAccept"
            @accept-selected="onAcceptSelected"
            @reject="onReject"
            @refine-draft="onRefineDraft"
            @rebase="onRebase"
            @expand-context="onExpandContext"
            @open-entry="openEntry"
          />

          <!--
            The GRAPH half of the review, a PEER of the board above rather than a card inside it: a
            relation often joins two entries that already exist and belongs to no draft at all.
          -->
          <KnowledgeGraphUpdatesPanel
            :proposals="proposedRelations"
            :entities="proposedEntities"
            :rejected="session.graph_ops?.rejected ?? []"
            :warnings="session.graph_ops?.warnings ?? []"
            :ambiguous="session.resolution?.ambiguous ?? []"
            :accepted-handles="acceptedHandles"
            :busy="bulkBusy"
            :loading="proposalsLoading"
            ref="updatesPanelRef"
            :result="lastResult"
            :applied="graphApplied"
            :stale="selectionStale"
            :error="selectionError"
            @show-relation="openEntry"
            @reload="reloadSession"
            @apply="onApplyGraph"
          />

          <KnowledgeRefineBar
            ref="refineBarRef"
            v-model="refinePrompt"
            :history="session.prompt_history"
            :max-chars="availability?.limits?.prompt_max_chars ?? 2000"
            :busy="refining || generating"
            @refine="onRefine"
          />
        </template>
      </template>
    </template>
  </div>
</template>

<style scoped>
.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}
</style>
