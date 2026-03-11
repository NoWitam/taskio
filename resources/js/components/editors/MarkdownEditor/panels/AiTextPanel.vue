<script setup lang="ts">
import { defineAsyncComponent, ref, watch, computed } from 'vue';
import SelectInput from '@/components/ui/inputs/SelectInput.vue';
import LabelSelect from '@/components/ui/inputs/reusable/LabelSelect.vue';
import Badge from '@/components/ui/Badge.vue';
import type { AiTextState, AiTextFeatureConfig, EditorConfig } from '../types/editor';

const MarkdownEditor = defineAsyncComponent(() => import('../MarkdownEditor.vue'));

const props = defineProps<{
  state: AiTextState;
  feature?: AiTextFeatureConfig;
  promptConfig: EditorConfig;
}>();

const emit = defineEmits<{
  (e: 'save', value: AiTextState): void;
}>();

const localState = ref<AiTextState>({ ...props.state });

watch(
  () => props.state,
  (value) => {
    localState.value = { ...value, labels: [...value.labels] };
  },
  { deep: true }
);

const personaOptions = computed(() =>
  (props.feature?.personas || []).map((persona) => ({ label: persona.label, value: persona.id }))
);

const labelOptions = computed(() => props.feature?.labelsCatalog || []);

function handleSave() {
  emit('save', { ...localState.value });
}

defineExpose({
  submit: handleSave,
});
</script>

<template>
  <div class="space-y-6">
    <div class="space-y-2">
      <label class="text-xs font-semibold uppercase tracking-wide text-foreground/70">Postać</label>
      <SelectInput
        v-model="localState.personaId"
        :options="personaOptions"
        placeholder="Wybierz personę"
        clearable
      />
      <p class="text-xs text-muted-foreground">Persony pomagają dobrać styl wypowiedzi AI.</p>
    </div>

    <div class="space-y-2">
      <label class="text-xs font-semibold uppercase tracking-wide text-foreground/70">Prompt</label>
      <MarkdownEditor
        v-model="localState.prompt"
        :config="props.promptConfig"
        placeholder="Opisz co AI ma wygenerować..."
        class="border border-border rounded-xl"
      />
      <p class="text-xs text-muted-foreground">
        Możesz używać pełnego Markdownu, zmiennych oraz bloków IF, aby budować złożone instrukcje.
      </p>
    </div>

    <div v-if="props.feature?.labelsEnabled" class="space-y-2">
      <label class="text-xs font-semibold uppercase tracking-wide text-foreground/70">Etykiety wiedzy</label>
      <LabelSelect
        v-model="localState.labels"
        :addable="false"
        :placeholder="'Wybierz etykiety'"
      />
      <div v-if="labelOptions.length" class="flex flex-wrap gap-2">
        <Badge v-for="label in labelOptions" :key="label.id" tone="neutral">
          {{ label.name }}
        </Badge>
      </div>
    </div>

  </div>
</template>
