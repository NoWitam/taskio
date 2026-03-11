<script setup lang="ts">
import { computed, ref } from 'vue';
import { NodeViewWrapper } from '@tiptap/vue-3';
import Sheet from '@/components/ui/patterns/Sheet.vue';
import Button from '@/components/ui/Button.vue';
import AiTextPanel from '../../panels/AiTextPanel.vue';
import type { AiTextNodeAttrs, EditorConfig, AiTextFeatureConfig, AiTextState, EditorFeaturesConfig } from '../../types/editor';

const props = defineProps<{
  editor: any;
  node: { attrs: AiTextNodeAttrs };
  updateAttributes: (attrs: AiTextNodeAttrs) => void;
  deleteNode: () => void;
}>();

const isPanelOpen = ref(false);
const panelRef = ref<InstanceType<typeof AiTextPanel> | null>(null);
const config = computed<EditorConfig | null>(() => props.editor?.storage?.markdownEditorConfig?.config || null);
const feature = computed<AiTextFeatureConfig | undefined>(() => config.value?.features.aiText);

const displayLabel = computed(() => feature.value?.personas?.find((persona) => persona.id === props.node.attrs.personaId)?.label || 'Tekst AI');

const promptConfig = computed<EditorConfig>(() => {
  const base = config.value ?? createFallbackConfig();
  return {
    ...base,
    features: {
      ...base.features,
      mentions: base.features.mentions ? { ...base.features.mentions, enabled: false } : undefined,
      aiText: base.features.aiText ? { ...base.features.aiText, enabled: false } : undefined,
    },
  };
});

function createFallbackConfig(): EditorConfig {
  const features: EditorFeaturesConfig = {
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
    ifBlock: { enabled: false },
    aiText: { enabled: true, labelsEnabled: false },
  };

  return { features };
}

function openPanel() {
  isPanelOpen.value = true;
}

function handleSave(state: AiTextState) {
  props.updateAttributes({ ...state });
  isPanelOpen.value = false;
}

function submitFromFooter() {
  panelRef.value?.submit();
}
</script>

<template>
  <NodeViewWrapper
    as="span"
    class="inline-flex items-center gap-2 rounded-full bg-primary/90 px-3 py-0.5 text-xs font-semibold tracking-wide text-white cursor-pointer"
    contenteditable="false"
    @click.stop="openPanel"
  >
    <span class="rounded-full bg-white/20 px-1 text-[10px]">AI</span>
    <span>{{ displayLabel }}</span>

    <Sheet
      v-model="isPanelOpen"
      title="Tekst AI"
      description="Skonfiguruj prompt i etykiety"
      width="xl"
    >
      <AiTextPanel
        ref="panelRef"
        :state="props.node.attrs"
        :feature="feature"
        :prompt-config="promptConfig"
        @save="handleSave"
      />
      <template #footer>
        <div class="flex justify-end border-t border-border/70 pt-4">
          <Button type="button" @click="submitFromFooter">
            Zapisz konfigurację
          </Button>
        </div>
      </template>
    </Sheet>
  </NodeViewWrapper>
</template>
