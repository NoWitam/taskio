<script setup lang="ts">
// IfBlockView — NodeView for the `ifBlock` CONTAINER. A bordered, token-styled
// shell with a header (title + delete) and a `NodeViewContent` region that hosts
// the `ifBranch` children (each branch owns its own editable body + condition).
// A non-editable footer adds ELSE-IF (respecting maxElseIf) / ELSE (max one).
import { computed } from 'vue';
import { NodeViewWrapper, NodeViewContent } from '@tiptap/vue-3';
import type { Editor } from '@tiptap/vue-3';
import Button from '../../primitives/Button.vue';
import Icon from '../../primitives/Icon.vue';
import { useI18n } from '../../../app/i18n';
import { generateId, type IfBranchKind } from './types';

const props = defineProps<{
  editor: Editor;
  node: { attrs: { id: string }; childCount: number; child: (i: number) => { attrs: { kind: IfBranchKind } } };
  getPos: () => number;
  deleteNode: () => void;
  selected?: boolean;
}>();

const { t } = useI18n();

const storage = computed(
  () =>
    (props.editor.storage?.ifBlock as { maxElseIf?: number; maxDepth?: number }) ?? {},
);
const maxElseIf = computed(() => storage.value.maxElseIf ?? Number.POSITIVE_INFINITY);

// Read the live branch kinds from the node so the add-controls react to edits.
const kinds = computed<IfBranchKind[]>(() => {
  const out: IfBranchKind[] = [];
  for (let i = 0; i < props.node.childCount; i += 1) {
    out.push(props.node.child(i).attrs.kind);
  }
  return out;
});
const elseIfCount = computed(() => kinds.value.filter((k) => k === 'else-if').length);
const hasElse = computed(() => kinds.value.includes('else'));
const canAddElseIf = computed(() => elseIfCount.value < maxElseIf.value);
const canAddElse = computed(() => !hasElse.value);

function branchNode(kind: IfBranchKind) {
  return {
    type: 'ifBranch',
    attrs: {
      id: generateId(kind === 'else' ? 'else' : kind === 'else-if' ? 'elseif' : 'if'),
      kind,
      condition: kind === 'else' ? null : { variableId: '', pipeline: [], resultType: 'boolean' },
    },
    content: [{ type: 'paragraph' }],
  };
}

// Insert position for a new branch: ELSE goes at the very end; ELSE-IF goes
// before any ELSE branch.
function insertBranch(kind: IfBranchKind): void {
  const blockPos = props.getPos();
  const blockNode = props.editor.state.doc.nodeAt(blockPos);
  if (!blockNode) return;
  // Compute the absolute position to insert at (inside the ifBlock content).
  let insertAt = blockPos + blockNode.nodeSize - 1; // just before block close
  if (kind === 'else-if' && hasElse.value) {
    // Walk children to find the ELSE branch start.
    let offset = blockPos + 1;
    for (let i = 0; i < blockNode.childCount; i += 1) {
      const child = blockNode.child(i);
      if (child.attrs.kind === 'else') {
        insertAt = offset;
        break;
      }
      offset += child.nodeSize;
    }
  }
  props.editor.chain().focus().insertContentAt(insertAt, branchNode(kind)).run();
}
</script>

<template>
  <NodeViewWrapper class="next-ifblock" :class="selected ? 'is-selected' : ''">
    <div class="next-ifblock__head" contenteditable="false">
      <span class="next-ifblock__title">
        <Icon name="git-branch" aria-hidden="true" />
        {{ t('editor.ifBlock.title', 'Conditional block') }}
      </span>
      <Button
        size="icon"
        variant="ghost"
        type="button"
        :aria-label="t('editor.ifBlock.delete', 'Delete conditional block')"
        @click="deleteNode()"
      >
        <Icon name="trash" />
      </Button>
    </div>

    <NodeViewContent class="next-ifblock__branches" />

    <div class="next-ifblock__actions" contenteditable="false">
      <Button
        size="xs"
        variant="ghost"
        type="button"
        leading-icon="plus"
        :disabled="!canAddElseIf"
        @click="insertBranch('else-if')"
      >
        {{ t('editor.ifBlock.addElseIf', 'Else if') }}
      </Button>
      <Button
        size="xs"
        variant="ghost"
        type="button"
        leading-icon="plus"
        :disabled="!canAddElse"
        @click="insertBranch('else')"
      >
        {{ t('editor.ifBlock.addElse', 'Else') }}
      </Button>
    </div>
  </NodeViewWrapper>
</template>

<style scoped>
.next-ifblock {
  display: block;
  border: 1px solid var(--color-next-primary);
  border-radius: var(--radius-next-lg);
  background-color: var(--color-next-primary-subtle);
  padding: var(--spacing-next-2);
}
.next-ifblock.is-selected {
  box-shadow: 0 0 0 2px var(--color-next-ring);
}
.next-ifblock__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 var(--spacing-next-1) var(--spacing-next-2);
  user-select: none;
}
.next-ifblock__title {
  display: inline-flex;
  align-items: center;
  gap: var(--spacing-next-1_5);
  font-size: var(--text-next-xs);
  font-weight: var(--font-weight-next-semibold);
  color: var(--color-next-primary);
  text-transform: uppercase;
  letter-spacing: 0.04em;
}
.next-ifblock__actions {
  display: flex;
  gap: var(--spacing-next-1);
  padding-top: var(--spacing-next-2);
  user-select: none;
}
</style>
