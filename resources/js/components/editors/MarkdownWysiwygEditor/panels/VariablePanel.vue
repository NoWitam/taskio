<script setup lang="ts">
import Dialog from '@/components/ui/Dialog.vue';
import Button from '@/components/ui/Button.vue';
import TextareaInput from '@/components/ui/inputs/TextareaInput.vue';
import SelectInput from '@/components/ui/inputs/SelectInput.vue';
import { ref } from 'vue';
import type { VariableDef } from '../types';

const props = defineProps<{
  variableId: string;
  variables: VariableDef[];
}>();

const emit = defineEmits<{
  'close': [];
  'apply': [];
}>();

const isOpen = ref(true);
</script>

<template>
  <Dialog v-model="isOpen" title="Configure Variable Operations" description="Add operations to transform this variable's value" @close="emit('close')">
    <div class="variable-panel">
      <p class="variable-id">Variable: <strong>{{ variableId }}</strong></p>

      <div class="operations-list">
        <p class="text-sm text-muted-foreground">Operations will be added here.</p>
      </div>

      <div class="panel-actions">
        <Button variant="secondary" @click="isOpen = false; emit('close')">Cancel</Button>
        <Button variant="primary" @click="isOpen = false; emit('apply')">Apply</Button>
      </div>
    </div>
  </Dialog>
</template>

<style scoped>
.variable-panel {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding: 1rem;
}

.variable-id {
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
}

.operations-list {
  border: 1px solid var(--color-border);
  border-radius: 0.5rem;
  padding: 1rem;
  background-color: var(--color-muted);
  min-height: 100px;
}

.panel-actions {
  display: flex;
  gap: 0.5rem;
  justify-content: flex-end;
}

:deep(.text-sm) {
  font-size: 0.875rem;
}

:deep(.text-muted-foreground) {
  color: var(--color-muted-foreground);
}
</style>
