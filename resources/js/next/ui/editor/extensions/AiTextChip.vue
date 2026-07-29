<script setup lang="ts">
// AiTextChip — inline atomic NodeView for an `aiText` node: a chip marking
// AI-generated / AI-insertable text. Shows "AI <persona|label summary>" as one
// selectable, deletable unit; clicking opens the Modal edit panel (persona +
// nested-MarkdownEditor prompt + labels).
import { computed, ref } from 'vue';
import { NodeViewWrapper } from '@tiptap/vue-3';
import type { Editor } from '@tiptap/vue-3';
import Icon from '../../primitives/Icon.vue';
import AiTextPanel from './AiTextPanel.vue';
import { useI18n } from '../../../app/i18n';
import type {
  AiLabelOption,
  AiPersona,
  AiTextNodeAttrs,
  VariableFeatureConfig,
  VariableStorage,
} from './types';

const props = defineProps<{
  editor: Editor;
  node: { attrs: AiTextNodeAttrs };
  updateAttributes: (attrs: Partial<AiTextNodeAttrs>) => void;
  deleteNode: () => void;
  selected?: boolean;
}>();

const { t } = useI18n();

const open = ref(false);

const aiStorage = computed(
  () =>
    (props.editor.storage?.aiText as {
      personas?: AiPersona[];
      labelsEnabled?: boolean;
      labelsCatalog?: AiLabelOption[];
    }) ?? {},
);
const personas = computed(() => aiStorage.value.personas ?? []);
const labelsEnabled = computed(() => aiStorage.value.labelsEnabled ?? false);
const labelsCatalog = computed(() => aiStorage.value.labelsCatalog ?? []);

// Nested prompt editor feature config (re-enable variables / if-blocks inside). Forward the variable
// extension's LIVE getters (getSource/getDefinitions/getCatalog) — NOT the FROZEN `definitions` array —
// so the nested prompt's `{` suggestion sees the host's CURRENT feed (e.g. template SLOTS that arrive
// async from the catalog), exactly like the parent body editor. Reading the frozen array left the prompt
// showing only the variables that existed at editor-creation time (the workspace globals).
const variableStorage = computed(
  () => props.editor.storage?.variable as VariableStorage | undefined,
);
const nestedVariables = computed<VariableFeatureConfig | undefined>(() => {
  const s = variableStorage.value;
  if (!s) return undefined;
  return {
    variables: s.getDefinitions?.() ?? s.definitions ?? [],
    operationsCatalog: s.getCatalog?.() ?? s.catalog ?? [],
    source: s.getSource,
    catalog: s.getCatalog,
    argVariableField: s.argVariableField,
  };
});
const nestedIfBlocks = computed(() => Boolean(props.editor.storage?.ifBlock));

const summary = computed(() => {
  const personaId = props.node.attrs.personaId;
  if (personaId) {
    const p = personas.value.find((x) => x.id === personaId);
    if (p) return p.label;
  }
  const labels = props.node.attrs.labels ?? [];
  if (labels.length) {
    const opt = labelsCatalog.value.find((l) => l.id === labels[0]);
    return opt?.name ?? labels[0];
  }
  const prompt = (props.node.attrs.prompt ?? '').trim();
  return prompt
    ? prompt.slice(0, 18) + (prompt.length > 18 ? '…' : '')
    : t('editor.aiText.chipFallback', 'AI text');
});

function onSave(attrs: AiTextNodeAttrs): void {
  props.updateAttributes(attrs);
  open.value = false;
}
function onRemove(): void {
  open.value = false;
  props.deleteNode();
}
function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Enter' || event.key === ' ') {
    event.preventDefault();
    open.value = true;
  }
}
</script>

<template>
  <NodeViewWrapper as="span" class="next-ai-chip-wrap" contenteditable="false" data-ai-text>
    <button
      type="button"
      class="next-ai-chip"
      :class="selected ? 'is-selected' : ''"
      :aria-expanded="open"
      aria-haspopup="dialog"
      @click="open = true"
      @keydown="onKeydown"
    >
      <Icon name="sparkles" class="next-ai-chip__icon" aria-hidden="true" />
      <span class="next-ai-chip__badge" aria-hidden="true">AI</span>
      <span class="next-ai-chip__label">{{ summary }}</span>
    </button>

    <AiTextPanel
      v-model:open="open"
      :state="node.attrs"
      :personas="personas"
      :labels-enabled="labelsEnabled"
      :labels-catalog="labelsCatalog"
      :variables="nestedVariables"
      :if-blocks="nestedIfBlocks"
      @save="onSave"
      @remove="onRemove"
    />
  </NodeViewWrapper>
</template>

<style scoped>
.next-ai-chip-wrap {
  display: inline-block;
  vertical-align: baseline;
}
.next-ai-chip {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  padding: 0.05rem 0.5rem;
  border-radius: var(--radius-next-full, 9999px);
  background-color: var(--color-next-info-subtle);
  color: var(--color-next-info-subtle-foreground);
  font-weight: var(--font-weight-next-medium);
  font-size: 0.9em;
  line-height: 1.4;
  white-space: nowrap;
  cursor: pointer;
}
.next-ai-chip.is-selected {
  box-shadow: 0 0 0 2px var(--color-next-ring);
}
.next-ai-chip__icon {
  font-size: 0.85em;
}
.next-ai-chip__badge {
  font-size: 0.72em;
  font-weight: var(--font-weight-next-semibold);
  letter-spacing: 0.04em;
  background-color: color-mix(in srgb, currentColor 16%, transparent);
  border-radius: var(--radius-next-sm);
  padding: 0 0.25rem;
}
</style>
