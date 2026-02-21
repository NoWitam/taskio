<script setup lang="ts">
import Dialog from '@/components/ui/Dialog.vue';
import Button from '@/components/ui/Button.vue';
import TextareaInput from '@/components/ui/inputs/TextareaInput.vue';
import SelectInput from '@/components/ui/inputs/SelectInput.vue';
import { ref } from 'vue';
import type { AiBot, KnowledgeTag } from '../types';

const props = defineProps<{
  aiId: string;
  aiBots: AiBot[];
  knowledgeTags: KnowledgeTag[];
  maxNesting: number;
}>();

const emit = defineEmits<{
  'close': [];
  'apply': [];
}>();

const isOpen = ref(true);
const selectedBotId = ref('');
const prompt = ref('');
const selectedTags = ref<string[]>([]);
</script>

<template>
  <Dialog v-model="isOpen" title="Configure AI Block" description="Set up the AI block with bot, prompt, and tags" @close="emit('close')">
    <div class="ai-panel">
      <div class="form-group">
        <label for="bot-select">Bot</label>
        <SelectInput
          id="bot-select"
          v-model="selectedBotId"
          :options="aiBots.map((bot) => ({ label: bot.name, value: bot.id }))"
          placeholder="Select AI Bot"
        />
      </div>

      <div class="form-group">
        <label for="prompt-textarea">Prompt</label>
        <TextareaInput
          id="prompt-textarea"
          v-model="prompt"
          placeholder="Enter your prompt..."
          :rows="6"
        />
      </div>

      <div class="form-group">
        <label>Tags (max {{ knowledgeTags.length }})</label>
        <div class="tags-list">
          <div
            v-for="tag in knowledgeTags"
            :key="tag.id"
            class="tag-item"
            :class="{ active: selectedTags.includes(tag.id) }"
            @click="
              selectedTags.includes(tag.id)
                ? (selectedTags = selectedTags.filter((t) => t !== tag.id))
                : (selectedTags = [...selectedTags, tag.id])
            "
          >
            {{ tag.name }}
          </div>
        </div>
      </div>

      <div class="info-box">
        <p>Max nesting level: {{ maxNesting }}</p>
      </div>

      <div class="panel-actions">
        <Button variant="secondary" @click="isOpen = false; emit('close')">Cancel</Button>
        <Button variant="primary" @click="isOpen = false; emit('apply')">Apply</Button>
      </div>
    </div>
  </Dialog>
</template>

<style scoped>
.ai-panel {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding: 1rem;
}

.form-group {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.form-group label {
  font-size: 0.875rem;
  font-weight: 500;
  color: var(--color-foreground);
}

.tags-list {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.tag-item {
  padding: 0.375rem 0.75rem;
  border: 1px solid var(--color-border);
  border-radius: 0.25rem;
  background-color: var(--color-background);
  cursor: pointer;
  transition: all 0.15s;
  font-size: 0.875rem;
}

.tag-item:hover {
  border-color: var(--color-primary);
}

.tag-item.active {
  background-color: var(--color-primary);
  color: var(--color-primary-foreground);
  border-color: var(--color-primary);
}

.info-box {
  padding: 0.75rem;
  background-color: var(--color-muted);
  border-radius: 0.5rem;
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
}

.panel-actions {
  display: flex;
  gap: 0.5rem;
  justify-content: flex-end;
}
</style>
