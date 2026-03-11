<script setup lang="ts">
import { computed, ref } from 'vue';
import { NodeViewWrapper } from '@tiptap/vue-3';
import Sheet from '@/components/ui/patterns/Sheet.vue';
import Button from '@/components/ui/Button.vue';
import Icon from '@/components/ui/Icon.vue';
import VariablePanel from '../../panels/VariablePanel.vue';
import { getVariableIconName, getVariableIconLabel } from '../../utils/variableIcons';
import type { VariableNodeAttrs, EditorConfig, VariableFeatureConfig, VariableDefinition, VariableState } from '../../types/editor';

const props = defineProps<{
  editor: any;
  node: { attrs: VariableNodeAttrs };
  updateAttributes: (attrs: VariableNodeAttrs) => void;
  deleteNode: () => void;
}>();

const isPanelOpen = ref(false);
const panelRef = ref<InstanceType<typeof VariablePanel> | null>(null);
const config = computed<EditorConfig | null>(() => props.editor?.storage?.markdownEditorConfig?.config || null);
const feature = computed<VariableFeatureConfig | undefined>(() => config.value?.features.variables);
const definition = computed<VariableDefinition | undefined>(() => feature.value?.variables.find((variable) => variable.id === props.node.attrs.id));

const chipLabel = computed(() => props.node.attrs.name || definition.value?.name || props.node.attrs.id);

function openPanel() {
  if (!feature.value?.enabled) return;
  isPanelOpen.value = true;
}

function handleSave(state: VariableState) {
  props.updateAttributes({ ...state });
  isPanelOpen.value = false;
}

function handleRemove() {
  isPanelOpen.value = false;
  props.deleteNode();
}

function submitFromFooter() {
  panelRef.value?.submit();
}

function removeFromFooter() {
  panelRef.value?.requestRemove();
}
</script>

<template>
  <NodeViewWrapper
    as="span"
    class="inline-flex items-center gap-2 rounded-full bg-primary px-2 py-0.5 text-xs font-semibold uppercase tracking-wide text-white cursor-pointer select-none"
    contenteditable="false"
    @click.stop="openPanel"
  >
    <span class="rounded-full bg-white/20 px-1 font-bold">{ }</span>
    <span>{{ chipLabel }}</span>
    <span
      class="rounded-full bg-white/20 p-1 text-white"
      :title="getVariableIconLabel(props.node.attrs.resultType)"
    >
      <Icon :name="getVariableIconName(props.node.attrs.resultType)" size="sm" />
      <span class="sr-only">{{ getVariableIconLabel(props.node.attrs.resultType) }}</span>
    </span>

    <Sheet
      v-model="isPanelOpen"
      title="Konfiguracja zmiennej"
      description="Zarządzaj pipeline operacji"
      width="lg"
    >
      <VariablePanel
        ref="panelRef"
        :state="props.node.attrs"
        :definition="definition"
        :feature="feature"
        @save="handleSave"
        @remove="handleRemove"
      />
      <template #footer>
        <div class="flex items-center justify-between border-t border-border/70 pt-4">
          <Button variant="ghost" type="button" @click="removeFromFooter">
            Usuń zmienną
          </Button>
          <Button type="button" @click="submitFromFooter">
            Zapisz zmiany
          </Button>
        </div>
      </template>
    </Sheet>
  </NodeViewWrapper>
</template>
