<script setup lang="ts">
// IfBranchView — NodeView for an `ifBranch`. The header is non-editable (kind
// label + condition summary + boolean-valid status icon + controls); the body is
// a REAL editor region via `NodeViewContent` (contentDOM), so the user edits
// branch text inline with the full editor (marks, mentions, variables, ai, even
// nested if-blocks).
//
// Controls:
//   • edit condition (IF / ELSE-IF) → opens IfConditionPanel (Modal),
//   • remove branch (not for the sole IF),
//   • block-level add ELSE-IF / add ELSE live in the PARENT IfBlockView footer,
//     but per-branch removal happens here via getPos + a parent transaction.
import { computed, ref } from 'vue';
import { NodeViewWrapper, NodeViewContent } from '@tiptap/vue-3';
import type { Editor } from '@tiptap/vue-3';
import type { Node as PMNode } from '@tiptap/pm/model';
import Badge from '../../primitives/Badge.vue';
import Button from '../../primitives/Button.vue';
import Icon from '../../primitives/Icon.vue';
import IfConditionPanel from './IfConditionPanel.vue';
import { useI18n } from '../../../app/i18n';
import { getVariableIconName } from './operationHelpers';
import { variableFeedTree, variableSourceFeed } from './variableFeed';
import { findNodeByPath } from '../../variables/variableTree';
import type {
  IfBranchKind,
  IfBranchNodeAttrs,
  IfConditionState,
  VariableStorage,
} from './types';

const props = defineProps<{
  editor: Editor;
  node: { attrs: IfBranchNodeAttrs };
  updateAttributes: (attrs: Partial<IfBranchNodeAttrs>) => void;
  getPos: () => number;
}>();

const { t } = useI18n();

const kindLabel = computed<Record<IfBranchKind, string>>(() => ({
  if: t('editor.ifBlock.kindIf', 'IF'),
  'else-if': t('editor.ifBlock.kindElseIf', 'ELSE IF'),
  else: t('editor.ifBlock.kindElse', 'ELSE'),
}));

const kind = computed(() => props.node.attrs.kind);
const condition = computed<IfConditionState | null>(() => props.node.attrs.condition ?? null);

// Host variable feature config (definitions + catalog) from the variable extension's
// storage so condition summaries + the panel resolve correctly. Read through the LIVE
// storage readers (see VariableChip) so a host feed that arrives async / changes later
// reaches this branch header and its condition panel.
const variableStorage = computed(
  () => (props.editor.storage?.variable as Partial<VariableStorage> | undefined) ?? {},
);
const definitions = computed(
  () => variableStorage.value.getDefinitions?.() ?? variableStorage.value.definitions ?? [],
);
const catalog = computed(
  () => variableStorage.value.getCatalog?.() ?? variableStorage.value.catalog ?? [],
);

// The SHARED-model feed behind this editor: the host's live `VariableSourceVar[]` when it has one,
// else its flat definitions promoted back (see ./variableFeed) — the SAME resolution the chip + the
// `{` popup use, so the condition panel browses one tree. It is ALSO the pool an op ARGUMENT inside
// the condition's pipeline may reference.
const sourceVars = computed(() =>
  variableSourceFeed(variableStorage.value.getSource?.(), definitions.value),
);
/** The offered TREE the condition panel's source picker browses. */
const nodes = computed(() => variableFeedTree(sourceVars.value, definitions.value));

/** The host-injected value-or-variable control for ONE pipeline argument (may be absent). */
const argVariableField = computed(() => variableStorage.value.argVariableField);

/** The referenced variable, resolved from the TREE (falls back to the raw id for a stale ref). */
const referencedNode = computed(() => findNodeByPath(nodes.value, condition.value?.variableId));

const conditionVarName = computed(() => {
  const id = condition.value?.variableId;
  if (!id) return null;
  return referencedNode.value?.label ?? id;
});

// Boolean validity (mirrors legacy isBranchValid): ELSE is always valid; others
// require a condition variable whose pipeline resolves to boolean.
const isValid = computed(() => {
  if (kind.value === 'else') return true;
  const cond = condition.value;
  if (!cond?.variableId) return false;
  return cond.resultType === 'boolean';
});

// --- condition panel --------------------------------------------------------
const conditionOpen = ref(false);
function saveCondition(next: IfConditionState): void {
  props.updateAttributes({ condition: next });
}

// --- remove this branch (operate on the parent ifBlock) ---------------------
function findParentIfBlock(): { node: PMNode; pos: number } | null {
  const $pos = props.editor.state.doc.resolve(props.getPos());
  for (let d = $pos.depth; d > 0; d -= 1) {
    if ($pos.node(d).type.name === 'ifBlock') {
      return { node: $pos.node(d), pos: $pos.before(d) };
    }
  }
  return null;
}

const isSoleIf = computed(() => kind.value === 'if');

function removeBranch(): void {
  if (kind.value === 'if') return;
  const pos = props.getPos();
  const node = props.editor.state.doc.nodeAt(pos);
  if (!node) return;
  props.editor
    .chain()
    .focus()
    .deleteRange({ from: pos, to: pos + node.nodeSize })
    .run();
}

void findParentIfBlock;
</script>

<template>
  <NodeViewWrapper class="next-ifbranch" :data-kind="kind">
    <div class="next-ifbranch__head" contenteditable="false">
      <Badge variant="primary" tone="subtle" size="sm">{{ kindLabel[kind] }}</Badge>

      <template v-if="kind !== 'else'">
        <button type="button" class="next-ifbranch__cond" @click="conditionOpen = true">
          <Icon
            v-if="condition?.variableId"
            :name="getVariableIconName(referencedNode?.type ?? 'boolean')"
          />
          <span v-if="conditionVarName">{{ conditionVarName }}</span>
          <span v-else class="text-next-muted-foreground">{{ t('editor.ifBlock.setCondition', 'Set condition…') }}</span>
          <Badge
            v-if="condition?.pipeline?.length"
            variant="neutral"
            size="sm"
          >{{ t('editor.ifBlock.operationsCount', '{count} op.', { count: condition.pipeline.length }) }}</Badge>
        </button>
        <Icon
          :name="isValid ? 'check-circle' : 'alert-triangle'"
          :class="isValid ? 'text-next-success' : 'text-next-warning'"
          :label="isValid ? t('editor.ifBlock.conditionValid', 'Condition is valid') : t('editor.ifBlock.conditionInvalid', 'Condition is invalid (must be a boolean)')"
        />
        <Button size="xs" variant="ghost" type="button" leading-icon="settings" @click="conditionOpen = true">
          {{ t('editor.ifBlock.condition', 'Condition') }}
        </Button>
      </template>

      <span class="grow" />

      <Button
        v-if="!isSoleIf"
        size="icon"
        variant="ghost"
        type="button"
        :aria-label="t('editor.ifBlock.removeSection', 'Remove section')"
        @click="removeBranch"
      >
        <Icon name="x" />
      </Button>
    </div>

    <NodeViewContent class="next-ifbranch__body" />

    <IfConditionPanel
      v-if="kind !== 'else'"
      v-model:open="conditionOpen"
      :condition="condition"
      :nodes="nodes"
      :catalog="catalog"
      :arg-variables="sourceVars"
      :arg-variable-field="argVariableField"
      @save="saveCondition"
    />
  </NodeViewWrapper>
</template>

<style scoped>
.next-ifbranch {
  background-color: var(--color-next-card);
  border: 1px solid var(--color-next-border);
  border-radius: var(--radius-next-md);
  margin-top: var(--spacing-next-1_5);
}
.next-ifbranch:first-child {
  margin-top: 0;
}
.next-ifbranch__head {
  display: flex;
  align-items: center;
  gap: var(--spacing-next-2);
  padding: var(--spacing-next-1_5) var(--spacing-next-2);
  border-bottom: 1px solid var(--color-next-border);
  user-select: none;
}
.next-ifbranch__cond {
  display: inline-flex;
  align-items: center;
  gap: var(--spacing-next-1);
  font-size: var(--text-next-xs);
  color: var(--color-next-fg);
  border-radius: var(--radius-next-sm);
  padding: var(--spacing-next-0_5) var(--spacing-next-1);
  cursor: pointer;
}
.next-ifbranch__cond:hover {
  background-color: var(--color-next-accent);
}
.next-ifbranch__body {
  padding: var(--spacing-next-2) var(--spacing-next-3);
  min-height: 2.5rem;
}
.grow {
  flex: 1 1 auto;
}
</style>
