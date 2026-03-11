<script setup lang="ts">
import { computed, ref } from 'vue';
import { NodeViewWrapper } from '@tiptap/vue-3';
import Sheet from '@/components/ui/patterns/Sheet.vue';
import Button from '@/components/ui/Button.vue';
import IfBlockPanel from '../../panels/IfBlockPanel.vue';
import type {
  EditorConfig,
  IfBlockNodeAttrs,
  IfBranchState,
  IfBlockState,
  IfBlockFeatureConfig,
  VariableFeatureConfig,
} from '../../types/editor';

const props = defineProps<{
  editor: any;
  node: { attrs: IfBlockNodeAttrs };
  updateAttributes: (attrs: IfBlockNodeAttrs) => void;
  deleteNode: () => void;
}>();

const isDrawerOpen = ref(false);
const panelRef = ref<InstanceType<typeof IfBlockPanel> | null>(null);
const branches = computed(() => props.node.attrs.branches || []);
const config = computed<EditorConfig | null>(() => props.editor?.storage?.markdownEditorConfig?.config || null);
const editorConfig = computed<EditorConfig>(() => config.value ?? createFallbackConfig());
const feature = computed<IfBlockFeatureConfig | undefined>(() => editorConfig.value.features.ifBlock);
const variableFeature = computed<VariableFeatureConfig | undefined>(() => editorConfig.value.features.variables);

function openDrawer() {
  isDrawerOpen.value = true;
}

function handleSave(state: IfBlockState) {
  props.updateAttributes({ ...state });
  isDrawerOpen.value = false;
}

function submitFromFooter() {
  panelRef.value?.submit();
}

function handleRemoveBlock() {
  isDrawerOpen.value = false;
  props.deleteNode();
}

function createFallbackConfig(): EditorConfig {
  return {
    features: {
      markdown: {
        headings: [1, 2, 3],
        links: true,
        lists: true,
        bold: true,
        italic: true,
        underline: false,
      },
      mentions: { enabled: false, users: [] },
      variables: { enabled: false, variables: [], operationsCatalog: [] },
      ifBlock: { enabled: true },
      aiText: { enabled: false, labelsEnabled: false },
    },
  };
}
</script>

<template>
  <NodeViewWrapper
    class="block rounded-xl border border-primary/50 bg-primary/5 p-4 text-left text-sm text-foreground cursor-pointer"
    contenteditable="false"
    @click.stop="openDrawer"
  >
    <p class="font-semibold text-primary flex items-center gap-2">
      IF block
      <span class="text-xs text-primary/80">({{ branches.length }} section{{ branches.length === 1 ? '' : 's' }})</span>
    </p>
    <ul class="mt-2 space-y-1 text-xs text-primary/90">
      <li v-for="branch in branches" :key="branch.id" class="flex items-center gap-2">
        <span class="rounded-full bg-primary/10 px-2 py-0.5 font-semibold uppercase">{{ branch.kind }}</span>
        <span class="text-primary/70" v-if="branch.condition">{{ branch.condition.variableId }}</span>
      </li>
    </ul>

    <Sheet
      v-model="isDrawerOpen"
      title="Blok IF"
      description="Konfiguruj sekcje, zmienne warunków oraz treść każdej gałęzi."
      width="xl"
    >
      <IfBlockPanel
        ref="panelRef"
        :state="props.node.attrs"
        :feature="feature"
        :editor-config="editorConfig"
        :variable-feature="variableFeature"
        @save="handleSave"
      />
      <template #footer>
        <div class="flex items-center justify-between border-t border-border/70 pt-4">
          <Button variant="ghost" type="button" @click="handleRemoveBlock">Usuń blok</Button>
          <Button type="button" @click="submitFromFooter">Zapisz blok</Button>
        </div>
      </template>
    </Sheet>
  </NodeViewWrapper>
</template>
