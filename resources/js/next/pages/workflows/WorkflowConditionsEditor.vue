<script setup lang="ts">
// WorkflowConditionsEditor — the B3 condition TREE builder (§4.8, rebuilt).
//
// REPLACES the flat Field/Operator/Value rows with a TREE of AND/OR groups whose
// leaves are CONDITIONS (a form field run through an operations pipeline that ends on
// a boolean). This host owns:
//   • the tree v-model (a `DraftConditionGroup`),
//   • the recursive group renderer (WorkflowConditionGroup) via a provide/inject
//     context so nested groups call back by uid,
//   • the condition Modal (add / edit a leaf),
//   • the FORM GATE (no form ⇒ the needsForm Alert, no tree) and the 422 read-out.
//
// Clear-on-form-change + the save gate (isTreeComplete) live in the DRAWER; this
// component only renders the given tree + catalog. The empty tree is valid ("always
// runs"). The operations catalog is the backend DESCRIPTORS merged with the FE labels
// (resolveOperationCatalog) — computed here for locale reactivity.
import { computed, provide, ref } from 'vue';
import Alert from '../../ui/feedback/Alert.vue';
import WorkflowConditionGroup from './WorkflowConditionGroup.vue';
import WorkflowConditionModal from './WorkflowConditionModal.vue';
import { useI18n } from '../../app/i18n';
import { CONDITION_TREE_KEY, type ConditionTreeContext } from './conditionTreeContext';
import {
  CONDITION_LIMITS,
  addConditionToGroup,
  addGroupToGroup,
  conditionSummary,
  emptyConditionTree,
  findNode,
  makeCondition,
  removeNode,
  resolveOperationCatalog,
  setGroupLogic,
  updateCondition,
  type ConditionDraftPayload,
  type DraftCondition,
  type DraftConditionGroup,
} from './workflowConditions';
import type { CatalogField, ConditionLogic, WorkflowCatalog } from './types';

const props = withDefaults(
  defineProps<{
    /** The catalog (fields for the source picker + operations for the pipeline). */
    catalog?: WorkflowCatalog | null;
    /** Whether a form is selected (drives the gate). */
    formSelected?: boolean;
    /** Server 422 errors keyed by `conditions.*`. */
    errors?: Record<string, string>;
  }>(),
  { catalog: null, formSelected: false, errors: () => ({}) },
);

const model = defineModel<DraftConditionGroup>({ default: () => emptyConditionTree() });

const { t } = useI18n();

const gated = computed(() => !props.formSelected);
const fields = computed<CatalogField[]>(() => props.catalog?.fields ?? []);
const operations = computed(() => resolveOperationCatalog(props.catalog));

// The first conditions.* server error (the section shows one aggregate message).
const sectionError = computed<string | null>(() => {
  const key = Object.keys(props.errors).find((k) => k === 'conditions' || k.startsWith('conditions.'));
  return key ? props.errors[key] : null;
});

// --- Modal orchestration ----------------------------------------------------
const modalOpen = ref(false);
const editingUid = ref<string | null>(null);
const targetGroupUid = ref<string | null>(null);

const editingCondition = computed<DraftCondition | null>(() => {
  if (!editingUid.value) return null;
  const node = findNode(model.value, editingUid.value);
  return node && node.kind === 'condition' ? node : null;
});

function openAddCondition(groupUid: string): void {
  editingUid.value = null;
  targetGroupUid.value = groupUid;
  modalOpen.value = true;
}

function openEditCondition(uid: string): void {
  targetGroupUid.value = null;
  editingUid.value = uid;
  modalOpen.value = true;
}

function onModalSave(payload: ConditionDraftPayload): void {
  if (editingUid.value) {
    model.value = updateCondition(model.value, editingUid.value, payload);
  } else if (targetGroupUid.value) {
    model.value = addConditionToGroup(model.value, targetGroupUid.value, makeCondition(payload));
  }
  editingUid.value = null;
  targetGroupUid.value = null;
}

// --- Tree mutations ---------------------------------------------------------
function onAddGroup(groupUid: string): void {
  model.value = addGroupToGroup(model.value, groupUid);
}
function onRemoveNode(uid: string): void {
  model.value = removeNode(model.value, uid);
}
function onSetLogic(groupUid: string, logic: ConditionLogic): void {
  model.value = setGroupLogic(model.value, groupUid, logic);
}

// --- Provide the recursion context ------------------------------------------
const context: ConditionTreeContext = {
  fields: () => fields.value,
  operations: () => operations.value,
  errors: () => props.errors,
  addCondition: openAddCondition,
  addGroup: onAddGroup,
  editCondition: openEditCondition,
  removeNode: onRemoveNode,
  setLogic: onSetLogic,
  summarize: (condition) => conditionSummary(condition, fields.value, operations.value),
  limits: { maxDepth: CONDITION_LIMITS.maxDepth, maxGroupChildren: CONDITION_LIMITS.maxGroupChildren },
};
provide(CONDITION_TREE_KEY, context);
</script>

<template>
  <section class="flex flex-col gap-next-3">
    <div>
      <h3 class="text-next-base font-next-semibold text-next-fg">{{ t('workflows.editor.sections.conditions') }}</h3>
      <p class="mt-next-0_5 text-next-xs text-next-muted-foreground">{{ t('workflows.editor.sections.conditionsHint') }}</p>
    </div>

    <!-- Form-gated disabled state (§4.4a / §4.8) — no tree until a form is picked. -->
    <Alert v-if="gated" variant="info" size="sm">
      {{ t('workflows.condition.needsForm') }}
    </Alert>

    <template v-else>
      <!-- Server 422 aggregate for the whole section. -->
      <Alert v-if="sectionError" variant="danger" size="sm">
        {{ sectionError }}
      </Alert>

      <!-- The tree — the root group holds the add actions + all nested nodes. An
           empty root reads as "always runs" via the group's own empty note. -->
      <WorkflowConditionGroup :group="model" :depth="1" />

      <!-- Missing-path hint (one subtle note per section, §4.8). -->
      <p class="text-next-xs text-next-muted-foreground">
        {{ t('workflows.condition.missingPathHint') }}
      </p>
    </template>

    <!-- Condition editor Modal (add / edit a leaf). Mounted regardless of the tree so
         the transition is stable; it seeds from `editingCondition` on open. -->
    <WorkflowConditionModal
      v-model:open="modalOpen"
      :condition="editingCondition"
      :fields="fields"
      :catalog="operations"
      @save="onModalSave"
    />
  </section>
</template>
