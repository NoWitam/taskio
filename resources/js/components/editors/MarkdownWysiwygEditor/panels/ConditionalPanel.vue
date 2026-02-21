<script setup lang="ts">
import Dialog from '@/components/ui/Dialog.vue';
import Button from '@/components/ui/Button.vue';
import SelectInput from '@/components/ui/inputs/SelectInput.vue';
import TextInput from '@/components/ui/inputs/TextInput.vue';
import { ref } from 'vue';
import type { VariableDef } from '../types';

const props = defineProps<{
  blockId: string;
  variables: VariableDef[];
}>();

const emit = defineEmits<{
  'close': [];
  'apply': [];
}>();

const isOpen = ref(true);
const selectedVarId = ref('');
const condition = ref('');
</script>

<template>
  <Dialog v-model="isOpen" title="Configure Conditional Block" description="Set up IF/FOR/SWITCH condition" @close="emit('close')">
    <div class="conditional-panel">
      <div class="form-group">
        <label for="var-select">Variable</label>
        <SelectInput
          id="var-select"
          v-model="selectedVarId"
          :options="variables.map((v) => ({ label: v.name, value: v.id }))"
          placeholder="Select variable"
        />
      </div>

      <div class="form-group">
        <label for="condition-input">Condition Expression</label>
        <TextInput
          id="condition-input"
          v-model="condition"
          placeholder="e.g., varId|op:equals('value')"
        />
      </div>

      <div class="info-box">
        <p>Use variable IDs with operations to build conditions.</p>
      </div>

      <div class="panel-actions">
        <Button variant="secondary" @click="isOpen = false; emit('close')">Cancel</Button>
        <Button variant="primary" @click="isOpen = false; emit('apply')">Apply</Button>
      </div>
    </div>
  </Dialog>
</template>

<style scoped>
.conditional-panel {
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
