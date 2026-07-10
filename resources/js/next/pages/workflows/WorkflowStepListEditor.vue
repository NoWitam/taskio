<script setup lang="ts">
// WorkflowStepListEditor — the ordered step editor (§4.6, the core).
//
// An `<ol>` of WorkflowStepCards with ▲▼ reorder, X-before-chevron trailing order,
// min 1 step, and an add-step DropdownMenu that picks the TYPE up front so each new
// card gets the type's correctly-shaped empty config + an auto-suggested unique key.
// Reuses the Approvals ordered-stage pattern (NOT drag-and-drop, NOT a canvas).
//
// The parent owns the StepDraft array; this editor mutates it in place (via the pure
// `makeStepDraft` / `moveStep` / `removeStep` helpers) so the payload builder and the
// per-index 422 map read one source. Duplicate keys are surfaced on BOTH offending
// cards via `duplicateKeyUids`.
//
// VARIABLES (5.1): the drawer (B7d) fetches the per-form catalog and passes it DOWN
// as `catalog`; the editor forwards it + the full step list + each card's index so
// every card can build its own position-scoped variable feed (§4.7).
import { computed } from 'vue';
import DropdownMenu from '../../ui/overlay/DropdownMenu.vue';
import DropdownMenuItem from '../../ui/overlay/DropdownMenuItem.vue';
import Button from '../../ui/primitives/Button.vue';
import WorkflowStepCard from './WorkflowStepCard.vue';
import { useI18n } from '../../app/i18n';
import { STEP_TYPES, stepIcon, stepLabel } from './workflowMeta';
import {
  duplicateKeyUids,
  makeStepDraft,
  moveStep,
  removeStep,
  type StepDraft,
} from './workflowEditorModel';
import type { WorkflowCatalog, WorkflowStepType } from './types';

const props = defineProps<{
  /** The ordered step drafts (owned by the parent; mutated in place). */
  steps: StepDraft[];
  /**
   * The fetched variable catalog for the selected form (or null when there is no
   * form — schedule trigger). Passed straight down to each card (§4.7).
   */
  catalog: WorkflowCatalog | null;
  /** Server 422 errors keyed by `steps.<i>.<field>`. */
  errors: Record<string, string>;
}>();

const emit = defineEmits<{
  /** The parent applies the replacement array (reorder/remove return new arrays). */
  (e: 'update:steps', next: StepDraft[]): void;
}>();

const { t } = useI18n();

const dupes = computed(() => duplicateKeyUids(props.steps));

function existingKeys(): string[] {
  return props.steps.map((s) => s.key);
}

function addStep(type: WorkflowStepType): void {
  emit('update:steps', [...props.steps, makeStepDraft(type, existingKeys())]);
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
    <div class="flex items-baseline justify-between gap-next-3">
      <div>
        <h3 class="text-next-base font-next-semibold text-next-fg">{{ t('workflows.editor.sections.steps') }}</h3>
        <p class="mt-next-0_5 text-next-xs text-next-muted-foreground">{{ t('workflows.editor.sections.stepsHint') }}</p>
      </div>
      <!-- Add-step: pick the TYPE first so the new card is correctly shaped. -->
      <DropdownMenu :aria-label="t('workflows.step.addStep')">
        <template #trigger="{ props: triggerProps }">
          <Button variant="outline" size="sm" leading-icon="plus" v-bind="triggerProps">
            {{ t('workflows.step.addStep') }}
          </Button>
        </template>
        <DropdownMenuItem
          v-for="type in STEP_TYPES"
          :key="type"
          :icon="stepIcon(type)"
          :label="stepLabel(type, t)"
          @select="addStep(type)"
        >
          {{ stepLabel(type, t) }}
        </DropdownMenuItem>
      </DropdownMenu>
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
        :steps="steps"
        :position="index"
        :errors="cardErrors(index)"
        :duplicate-key="dupes.has(step.uid)"
        @remove="onRemove(index)"
        @move="(dir) => onMove(index, dir)"
      />
    </ol>
  </section>
</template>
