<script setup lang="ts">
// WorkflowStepListEditor — the ordered step editor (§4.6, the core).
//
// An `<ol>` of COLLAPSIBLE WorkflowStepCards with ▲▼ reorder, X-before-chevron trailing
// order, min 1 step, and (SF2) add-step SELECTION CARDS — one card per TYPE, consistent
// with the step-1 trigger cards — so each new step gets the type's correctly-shaped empty
// config + an auto-suggested unique key. Reuses the Approvals ordered-stage pattern (NOT
// drag-and-drop, NOT a canvas).
//
// COLLAPSE (SF2): this editor owns WHICH cards are open (`expandedUids`). A freshly-added
// card opens (and collapses the rest, so a long stack never stays fully expanded); a card
// that carries a validation / 422 error auto-opens so the user can fix it. Configured
// cards otherwise sit collapsed to a one-line summary row (see WorkflowStepCard).
//
// The parent owns the StepDraft array; this editor mutates it in place (via the pure
// `makeStepDraft` / `moveStep` / `removeStep` helpers) so the payload builder and the
// per-index 422 map read one source. Duplicate keys are surfaced on BOTH offending cards
// via `duplicateKeyUids`. A client MAX_STEPS ceiling (mirrors the backend max:50) disables
// the add cards before a 422 can happen.
//
// VARIABLES (5.1 / SF2): the drawer fetches the per-form catalog and passes it DOWN as
// `catalog` + the workflow's `triggerType`; the editor forwards both + the full step list
// + each card's index so every card builds its own position-scoped variable feed — the
// trigger SYSTEM variables (e.g. `trigger.scheduled_at`) are offered even with a null
// catalog (schedule / any form), keyed by the trigger type.
import { computed, ref, watch } from 'vue';
import Icon from '../../ui/primitives/Icon.vue';
import Tooltip from '../../ui/overlay/Tooltip.vue';
import WorkflowStepCard from './WorkflowStepCard.vue';
import { useI18n } from '../../app/i18n';
import { STEP_TYPES, stepIcon, stepLabel } from './workflowMeta';
import {
  duplicateKeyUids,
  makeStepDraft,
  moveStep,
  removeStep,
  MAX_STEPS,
  type StepDraft,
} from './workflowEditorModel';
import type { WorkflowCatalog, WorkflowStepType, WorkflowTriggerType } from './types';

const props = defineProps<{
  /** The ordered step drafts (owned by the parent; mutated in place). */
  steps: StepDraft[];
  /**
   * The fetched variable catalog for the selected form (or null when there is no
   * form — schedule / any-form trigger). Passed straight down to each card (§4.7).
   */
  catalog: WorkflowCatalog | null;
  /** The workflow's trigger type — feeds each card's trigger SYSTEM variables (SF2). */
  triggerType: WorkflowTriggerType | null;
  /** Server 422 errors keyed by `steps.<i>.<field>`. */
  errors: Record<string, string>;
}>();

const emit = defineEmits<{
  /** The parent applies the replacement array (reorder/remove return new arrays). */
  (e: 'update:steps', next: StepDraft[]): void;
  /**
   * Whether ANY step has a value-or-variable field type mismatch (bubbled from the
   * cards). The drawer routes this into its Save gate — the field + row badge surface
   * the specifics.
   */
  (e: 'type-errors', hasAny: boolean): void;
}>();

const { t } = useI18n();

// --- Value-or-variable field type errors (bubbled up per card) --------------
const typeErrorUids = ref<Set<string>>(new Set());

/** Commit the invalid-uid set (pruned to currently-present steps) + notify the drawer. */
function commitTypeErrors(set: Set<string>): void {
  const present = new Set(props.steps.map((s) => s.uid));
  const pruned = new Set([...set].filter((uid) => present.has(uid)));
  typeErrorUids.value = pruned;
  emit('type-errors', pruned.size > 0);
}

function onCardTypeError(uid: string, hasError: boolean): void {
  const next = new Set(typeErrorUids.value);
  if (hasError) next.add(uid);
  else next.delete(uid);
  commitTypeErrors(next);
}

// A removed step's card cannot emit `false`; re-prune whenever the step set changes.
watch(
  () => props.steps.map((s) => s.uid).join('|'),
  () => commitTypeErrors(typeErrorUids.value),
);

const dupes = computed(() => duplicateKeyUids(props.steps));

/** At the client ceiling — the add cards disable and explain why (§4.6). */
const atMax = computed(() => props.steps.length >= MAX_STEPS);

function existingKeys(): string[] {
  return props.steps.map((s) => s.key);
}

// --- Collapse state (this editor owns which cards are open) -----------------
// A fresh workflow's single step starts OPEN (it needs configuring); an edit with many
// steps starts fully collapsed (summaries only) so the "excess space" is gone.
const expandedUids = ref<Set<string>>(
  new Set(props.steps.length === 1 ? [props.steps[0].uid] : []),
);

function isExpanded(uid: string): boolean {
  return expandedUids.value.has(uid);
}
function toggle(uid: string): void {
  const next = new Set(expandedUids.value);
  if (next.has(uid)) next.delete(uid);
  else next.add(uid);
  expandedUids.value = next;
}

/** Whether card `index` currently carries a validation/422 error or a duplicate key. */
function cardHasError(index: number, uid: string): boolean {
  return Object.keys(cardErrors(index)).length > 0 || dupes.value.has(uid);
}

// Auto-open any card that carries an error, so the user lands on the fields to fix:
// a failed Dalej / a 422 (props.errors or a duplicate key) OR a value-or-variable field
// TYPE error (bubbled from the always-mounted cards → typeErrorUids). The latter is the
// finding-2/3 fix: an edited 2+-step workflow hydrates COLLAPSED, but the cards still
// report their saved-model type errors, so we surface the offending card on hydration.
function expandErroredCards(): void {
  const next = new Set(expandedUids.value);
  props.steps.forEach((s, i) => {
    if (cardHasError(i, s.uid) || typeErrorUids.value.has(s.uid)) next.add(s.uid);
  });
  expandedUids.value = next;
}

// props.errors: immediate so a drawer that jumped here on a 422 opens the erroring card.
watch(() => props.errors, expandErroredCards, { deep: true, immediate: true });
// typeErrorUids grows once the cards emit their initial saved-model gate (after mount),
// so re-run then to auto-open a card whose variable field does not satisfy its contract.
watch(typeErrorUids, expandErroredCards);

// --- Add / remove / reorder -------------------------------------------------
function addStep(type: WorkflowStepType): void {
  if (atMax.value) return;
  const draft = makeStepDraft(type, existingKeys());
  // Open the new card, collapse the rest — a long stack never stays fully expanded.
  expandedUids.value = new Set([draft.uid]);
  emit('update:steps', [...props.steps, draft]);
}
function onRemove(index: number): void {
  emit('update:steps', removeStep(props.steps, index));
}
function onMove(index: number, dir: -1 | 1): void {
  emit('update:steps', moveStep(props.steps, index, dir));
}

/** The per-card error subset, mapping `steps.<i>.key` / `steps.<i>.config.*` → `key` / `config.*`. */
function cardErrors(index: number): Record<string, string> {
  const prefix = `steps.${index}.`;
  const out: Record<string, string> = {};
  Object.entries(props.errors).forEach(([key, msg]) => {
    if (key.startsWith(prefix)) out[key.slice(prefix.length)] = msg;
  });
  return out;
}
</script>

<template>
  <section class="flex flex-col gap-next-3">
    <div>
      <h3 class="text-next-base font-next-semibold text-next-fg">{{ t('workflows.editor.sections.steps') }}</h3>
      <p class="mt-next-0_5 text-next-xs text-next-muted-foreground">{{ t('workflows.editor.sections.stepsHint') }}</p>
    </div>

    <!-- Top-level steps error (e.g. `steps` required / max). -->
    <p v-if="errors['steps']" class="text-next-xs text-next-danger" role="alert">{{ errors['steps'] }}</p>

    <ol class="flex flex-col gap-next-3">
      <WorkflowStepCard
        v-for="(step, index) in steps"
        :key="step.uid"
        :step="step"
        :index="index"
        :total="steps.length"
        :catalog="catalog"
        :trigger-type="triggerType"
        :steps="steps"
        :position="index"
        :errors="cardErrors(index)"
        :duplicate-key="dupes.has(step.uid)"
        :expanded="isExpanded(step.uid)"
        @remove="onRemove(index)"
        @move="(dir) => onMove(index, dir)"
        @toggle="toggle(step.uid)"
        @type-error="(has) => onCardTypeError(step.uid, has)"
      />
    </ol>

    <!-- Add-step: SELECTION CARDS (one per type), consistent with the step-1 trigger
         cards. Disabled + explained at the client ceiling (mirrors the backend max:50). -->
    <div class="flex flex-col gap-next-2">
      <p class="text-next-xs font-next-medium text-next-muted-foreground">{{ t('workflows.step.addStep') }}</p>
      <Tooltip :disabled="!atMax" :label="t('workflows.step.maxSteps')" placement="top">
        <div class="grid w-full grid-cols-1 gap-next-2 next-sm:grid-cols-2">
          <button
            v-for="type in STEP_TYPES"
            :key="type"
            type="button"
            :disabled="atMax"
            :aria-label="t('workflows.step.addStepOfType', '', { type: stepLabel(type, t) })"
            class="flex items-start gap-next-2_5 rounded-next-lg border border-next-border bg-next-card px-next-3 py-next-2_5 text-left outline-none transition-colors duration-[var(--duration-next-fast)] focus-visible:ring-2 focus-visible:ring-next-ring hover:border-next-primary/50 disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:border-next-border"
            @click="addStep(type)"
          >
            <Icon :name="stepIcon(type)" class="mt-px shrink-0 text-next-muted-foreground" />
            <span class="flex min-w-0 flex-col">
              <span class="text-next-sm font-next-medium text-next-fg">{{ stepLabel(type, t) }}</span>
              <span class="mt-next-0_5 text-next-xs text-next-muted-foreground">
                {{ t(`workflows.step.${type}.description`) }}
              </span>
            </span>
          </button>
        </div>
      </Tooltip>
    </div>
  </section>
</template>
