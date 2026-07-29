<script setup lang="ts">
// AiTextPanel — the Modal edit panel for an `aiText` node (legacy AiTextPanel
// parity): persona Select (from the feature config), the prompt edited via a
// NESTED MarkdownEditor (same features as the parent, so the prompt can contain
// directives + if-blocks — recursively serialized per FORMAT), and a labels
// multi-select (from labelsCatalog, when labelsEnabled). Save updates node attrs.
import { computed, defineAsyncComponent, ref, watch } from 'vue';
import Modal from '../../overlay/Modal.vue';
import Button from '../../primitives/Button.vue';
import Select from '../../forms/Select.vue';
import { generateId, type AiTextNodeAttrs } from './types';
import { useI18n } from '../../../app/i18n';
import type { AiLabelOption, AiPersona, VariableFeatureConfig } from './types';

// Async import avoids a circular dependency (MarkdownEditor → aiText → panel).
const MarkdownEditor = defineAsyncComponent(() => import('../MarkdownEditor.vue'));

const props = defineProps<{
  state: AiTextNodeAttrs;
  personas: AiPersona[];
  labelsEnabled: boolean;
  labelsCatalog: AiLabelOption[];
  /**
   * Feature config to re-enable inside the nested prompt editor. A full {@link VariableFeatureConfig}
   * so the LIVE `source()`/`catalog()` getters (not just the frozen arrays) reach the nested editor —
   * that is what lets the prompt's `{` suggestion offer the host's current feed, e.g. template SLOTS.
   */
  variables?: VariableFeatureConfig;
  ifBlocks?: boolean | { maxElseIf?: number; maxDepth?: number };
}>();

const emit = defineEmits<{
  (e: 'save', attrs: AiTextNodeAttrs): void;
  (e: 'remove'): void;
}>();

const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();

const personaId = ref<string | null>(null);
const prompt = ref('');
const labels = ref<string[]>([]);

watch(
  open,
  (isOpen) => {
    if (!isOpen) return;
    personaId.value = props.state.personaId ?? null;
    prompt.value = props.state.prompt ?? '';
    labels.value = [...(props.state.labels ?? [])];
  },
  { immediate: true },
);

const personaOptions = computed(() =>
  props.personas.map((p) => ({ value: p.id, label: p.label })),
);
const labelOptions = computed(() =>
  props.labelsCatalog.map((l) => ({ value: l.id, label: l.name })),
);

function save(): void {
  emit('save', {
    id: props.state.id || generateId('ai'),
    personaId: personaId.value,
    prompt: prompt.value,
    labels: labels.value,
  });
  open.value = false;
}
</script>

<template>
  <Modal v-model:open="open" size="xl" :aria-label="t('editor.aiText.editTitle', 'Edit AI text')">
    <template #title>{{ t('editor.aiText.editTitle', 'Edit AI text') }}</template>

    <div class="flex flex-col gap-next-5">
      <div class="flex flex-col gap-next-1_5">
        <label class="text-next-sm font-next-medium text-next-fg">{{ t('editor.aiText.persona', 'Persona') }}</label>
        <Select
          v-model="personaId"
          :options="personaOptions"
          :placeholder="t('editor.aiText.selectPersona', 'Select a persona')"
          :aria-label="t('editor.aiText.persona', 'Persona')"
        />
        <p class="text-next-xs text-next-muted-foreground">{{ t('editor.aiText.personaHint', 'Personas help shape the AI’s tone of voice.') }}</p>
      </div>

      <div class="flex flex-col gap-next-1_5">
        <label class="text-next-sm font-next-medium text-next-fg">{{ t('editor.aiText.prompt', 'Prompt') }}</label>
        <MarkdownEditor
          v-model="prompt"
          :placeholder="t('editor.aiText.promptPlaceholder', 'Describe what the AI should generate…')"
          :variables="variables"
          :if-blocks="ifBlocks"
          min-height="6rem"
        />
        <p class="text-next-xs text-next-muted-foreground">
          {{ t('editor.aiText.promptHint', 'You can use full Markdown, variables and IF blocks.') }}
        </p>
      </div>

      <div v-if="labelsEnabled" class="flex flex-col gap-next-1_5">
        <label class="text-next-sm font-next-medium text-next-fg">{{ t('editor.aiText.knowledgeLabels', 'Knowledge labels') }}</label>
        <Select
          v-model:values="labels"
          multiple
          :options="labelOptions"
          :placeholder="t('editor.aiText.selectLabels', 'Select labels')"
          :aria-label="t('editor.aiText.knowledgeLabels', 'Knowledge labels')"
        />
      </div>
    </div>

    <template #footer>
      <Button variant="ghost" type="button" @click="emit('remove')">{{ t('editor.aiText.remove', 'Delete') }}</Button>
      <span class="flex-1" />
      <Button variant="outline" type="button" @click="open = false">{{ t('editor.aiText.cancel', 'Cancel') }}</Button>
      <Button variant="primary" type="button" @click="save">{{ t('editor.aiText.save', 'Save') }}</Button>
    </template>
  </Modal>
</template>
