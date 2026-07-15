<script setup lang="ts">
// WorkflowConditionGroup — one AND/OR group CARD in the condition tree (recursive).
//
// Renders the group's logic toggle + actions header, then its children: condition
// CHIPS (click → edit, ✕ → remove) and nested groups (this same component, one level
// deeper). Everything routes through the injected tree context (by uid); this
// component holds NO state. Limits (children ≤ 10, depth ≤ 5) disable the add actions
// with an explaining tooltip.
import { computed, inject } from 'vue';
import SegmentedControl, { type SegmentOption } from '../../ui/forms/SegmentedControl.vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Tooltip from '../../ui/overlay/Tooltip.vue';
import { useI18n } from '../../app/i18n';
import { variableIcon } from './workflowVariables';
import { CONDITION_TREE_KEY } from './conditionTreeContext';
import type { ConditionLogic } from './types';
import type { DraftCondition, DraftConditionGroup } from './workflowConditions';

const props = defineProps<{
  group: DraftConditionGroup;
  /** 1 for the root group; each nesting level adds one. */
  depth: number;
}>();

const { t } = useI18n();

const ctx = inject(CONDITION_TREE_KEY);
if (!ctx) throw new Error('WorkflowConditionGroup requires the condition tree context.');

const isRoot = computed(() => props.depth === 1);

// Compact toggle (user feedback): labels only — the ACTIVE option's meaning shows
// as one muted line NEXT TO the control instead of bulky in-card descriptions.
const logicOptions = computed<SegmentOption<ConditionLogic>[]>(() => [
  { value: 'and', label: t('workflows.condition.logic.and') },
  { value: 'or', label: t('workflows.condition.logic.or') },
]);

const logicHint = computed(() =>
  props.group.logic === 'and'
    ? t('workflows.condition.logic.andDescription')
    : t('workflows.condition.logic.orDescription'),
);

const groupAria = computed(() =>
  props.group.logic === 'and'
    ? t('workflows.condition.logic.andLabel')
    : t('workflows.condition.logic.orLabel'),
);

// --- Limits -----------------------------------------------------------------
const atChildLimit = computed(() => props.group.children.length >= ctx.limits.maxGroupChildren);
const canNest = computed(() => props.depth < ctx.limits.maxDepth);

const addConditionReason = computed(() =>
  atChildLimit.value
    ? t('workflows.condition.limitChildren', 'A group can hold up to {max} items.', { max: ctx.limits.maxGroupChildren })
    : '',
);
const addGroupReason = computed(() => {
  if (atChildLimit.value) {
    return t('workflows.condition.limitChildren', 'A group can hold up to {max} items.', { max: ctx.limits.maxGroupChildren });
  }
  if (!canNest.value) {
    return t('workflows.condition.limitDepth', 'Groups can be nested up to {max} levels deep.', { max: ctx.limits.maxDepth });
  }
  return '';
});

function onLogic(value: ConditionLogic | null): void {
  if (value) ctx.setLogic(props.group.uid, value);
}

// --- Chip helpers -----------------------------------------------------------
/** The full human sentence for a condition (chip aria-label). */
function chipSentence(condition: DraftCondition): string {
  const { fieldLabel, steps } = ctx.summarize(condition);
  const tail = steps
    .map((step) => (step.value ? `${step.label} ${step.value}` : step.label))
    .join(' → ');
  return tail ? `${fieldLabel} → ${tail}` : fieldLabel;
}
</script>

<template>
  <div
    class="flex flex-col gap-next-3 rounded-next-lg border p-next-3"
    :class="isRoot ? 'border-next-border bg-next-card' : 'border-next-border bg-next-muted'"
    role="group"
    :aria-label="groupAria"
  >
    <!-- Header: logic toggle + actions. -->
    <div class="flex flex-col gap-next-3 next-sm:flex-row next-sm:items-center next-sm:justify-between">
      <div class="flex min-w-0 flex-wrap items-center gap-next-3">
        <SegmentedControl
          :model-value="group.logic"
          :options="logicOptions"
          size="sm"
          :aria-label="t('workflows.condition.logic.groupLabel')"
          @update:model-value="(v) => onLogic(v as ConditionLogic | null)"
        />
        <span class="text-next-xs text-next-muted-foreground">{{ logicHint }}</span>
      </div>

      <div class="flex shrink-0 items-center gap-next-2">
        <Tooltip :label="addConditionReason" :disabled="!addConditionReason">
          <Button
            variant="outline"
            size="sm"
            leading-icon="plus"
            :disabled="atChildLimit"
            @click="ctx.addCondition(group.uid)"
          >
            {{ t('workflows.condition.addCondition') }}
          </Button>
        </Tooltip>
        <Tooltip :label="addGroupReason" :disabled="!addGroupReason">
          <Button
            variant="ghost"
            size="sm"
            leading-icon="git-branch"
            :disabled="atChildLimit || !canNest"
            @click="ctx.addGroup(group.uid)"
          >
            {{ t('workflows.condition.addGroup') }}
          </Button>
        </Tooltip>
        <Button
          v-if="!isRoot"
          variant="ghost"
          size="icon-sm"
          leading-icon="trash"
          :aria-label="t('workflows.condition.removeGroup')"
          @click="ctx.removeNode(group.uid)"
        />
      </div>
    </div>

    <!-- Empty group. -->
    <p
      v-if="group.children.length === 0"
      class="rounded-next-md border border-dashed border-next-border px-next-3 py-next-2 text-next-xs text-next-muted-foreground"
    >
      {{ t('workflows.condition.emptyGroup') }}
    </p>

    <!-- Children: condition chips + nested groups. -->
    <ul v-else class="flex flex-col gap-next-2">
      <li v-for="child in group.children" :key="child.uid">
        <!-- CONDITION chip -->
        <div
          v-if="child.kind === 'condition'"
          class="flex items-start gap-next-2 rounded-next-lg border border-next-border bg-next-card px-next-2_5 py-next-2"
        >
          <button
            type="button"
            class="flex min-w-0 flex-1 flex-wrap items-center gap-next-1_5 text-left"
            :aria-label="t('workflows.condition.chipEdit', 'Edit condition: {sentence}', { sentence: chipSentence(child) })"
            @click="ctx.editCondition(child.uid)"
          >
            <Icon :name="variableIcon(child.sourceType)" class="shrink-0 text-next-primary" />
            <span class="text-next-sm font-next-semibold text-next-fg">{{ ctx.summarize(child).fieldLabel }}</span>
            <template v-for="(step, i) in ctx.summarize(child).steps" :key="i">
              <Icon name="arrow-right" class="shrink-0 text-next-muted-foreground" aria-hidden="true" />
              <span class="text-next-sm text-next-fg">{{ step.label }}</span>
              <span v-if="step.value" class="text-next-sm font-next-medium text-next-primary">{{ step.value }}</span>
            </template>
          </button>
          <Button
            variant="ghost"
            size="icon-xs"
            leading-icon="x"
            class="shrink-0 text-next-muted-foreground hover:text-next-danger"
            :aria-label="t('workflows.condition.removeCondition')"
            @click="ctx.removeNode(child.uid)"
          />
        </div>

        <!-- Nested GROUP (recursion). -->
        <WorkflowConditionGroup v-else :group="child" :depth="depth + 1" />
      </li>
    </ul>
  </div>
</template>
