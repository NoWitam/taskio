<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import type { VariableOperation } from '../types';
import { getAllOperations, getOperation } from '../utils/operations';
import Button from '@/components/ui/Button.vue';
import Dialog from '@/components/ui/Dialog.vue';
import Icon from '@/components/ui/Icon.vue';

const props = defineProps<{
  variableName: string;
  variableType: string;
  currentOperations?: Array<{ op: string; args?: any[] }>;
}>();

const emit = defineEmits<{
  'save': [operations: Array<{ op: string; args?: any[] }>];
  'close': [];
}>();

const isOpen = ref(true);
const operations = ref<Array<{ op: string; args?: any[] }>>(
  JSON.parse(JSON.stringify(props.currentOperations || []))
);

const availableOperations = computed(() => getAllOperations());

const selectedOperation = ref<string | null>(null);
const operationArgs = ref<string[]>([]);

const selectedOp = computed(() => {
  if (!selectedOperation.value) return null;
  return getOperation(selectedOperation.value);
});

// Obserwuj zmiany isOpen aby emitować close
watch(isOpen, (newVal) => {
  if (!newVal) {
    emit('close');
  }
});

// Dodaj operację
function addOperation() {
  if (!selectedOperation.value) return;
  
  operations.value.push({
    op: selectedOperation.value,
    args: operationArgs.value.filter(a => a.trim()).length > 0 
      ? operationArgs.value.filter(a => a.trim()) 
      : undefined,
  });
  
  selectedOperation.value = null;
  operationArgs.value = [];
}

// Usuń operację
function removeOperation(index: number) {
  operations.value.splice(index, 1);
}

// Zmień porządek (przesuń w górę)
function moveUp(index: number) {
  if (index === 0) return;
  [operations.value[index], operations.value[index - 1]] = [
    operations.value[index - 1],
    operations.value[index],
  ];
}

// Zmień porządek (przesuń w dół)
function moveDown(index: number) {
  if (index === operations.value.length - 1) return;
  [operations.value[index], operations.value[index + 1]] = [
    operations.value[index + 1],
    operations.value[index],
  ];
}

// Zapisz zmiany
function save() {
  emit('save', operations.value);
  isOpen.value = false;
}

// Anuluj
function cancel() {
  isOpen.value = false;
}

// Formatuj argumenty do wyświetlenia
function formatArgs(args?: any[]): string {
  if (!args || args.length === 0) return '';
  return `(${args.join(', ')})`;
}
</script>

<template>
  <Dialog v-model="isOpen">
    <div class="operations-panel p-6 w-full max-w-2xl">
      <div class="mb-6">
        <h2 class="text-lg font-semibold">
          Operacje zmiennej: <span class="text-primary">{{ variableName }}</span>
        </h2>
        <p class="text-sm text-muted-foreground">Typ: {{ variableType }}</p>
      </div>

      <!-- Lista obecnych operacji -->
      <div class="mb-6">
        <h3 class="font-medium mb-3">Dodane operacje:</h3>
        <div v-if="operations.length === 0" class="text-sm text-muted-foreground p-3 bg-muted rounded">
          Brak dodanych operacji
        </div>
        <div v-else class="space-y-2">
          <div
            v-for="(op, index) in operations"
            :key="index"
            class="flex items-center justify-between p-3 bg-muted rounded border border-border"
          >
            <div class="flex-1">
              <span class="font-medium">{{ op.op }}</span>
              <span class="text-sm text-muted-foreground">{{ formatArgs(op.args) }}</span>
            </div>
            <div class="flex gap-1">
              <Button
                v-if="index > 0"
                variant="ghost"
                size="sm"
                @click="moveUp(index)"
                title="Przesuń w górę"
              >
                <Icon name="chevron-up" size="xs" />
              </Button>
              <Button
                v-if="index < operations.length - 1"
                variant="ghost"
                size="sm"
                @click="moveDown(index)"
                title="Przesuń w dół"
              >
                <Icon name="chevron-down" size="xs" />
              </Button>
              <Button
                variant="ghost"
                size="sm"
                @click="removeOperation(index)"
              >
                <Icon name="trash" size="xs" />
              </Button>
            </div>
          </div>
        </div>
      </div>

      <!-- Dodaj nową operację -->
      <div class="mb-6 p-4 border border-border rounded-lg bg-card">
        <h3 class="font-medium mb-3">Dodaj operację:</h3>
        
        <div class="mb-4">
          <label class="text-sm font-medium mb-2 block">Operacja</label>
          <select
            v-model="selectedOperation"
            class="w-full px-3 py-2 border border-input rounded-md bg-background text-foreground"
          >
            <option value="">-- Wybierz operację --</option>
            <option v-for="op in availableOperations" :key="op.name" :value="op.name">
              {{ op.name }} - {{ op.description }}
            </option>
          </select>
        </div>

        <!-- Argumenty operacji -->
        <div v-if="selectedOp" class="mb-4 space-y-3">
          <div class="text-sm text-muted-foreground">
            {{ selectedOp.description }}
          </div>
          <!-- Dla operacji z argumentami -->
          <div v-if="['prefix', 'suffix', 'repeat', 'concat', 'truncate', 'slice', 'equals', 'gt', 'lt', 'gte', 'lte', 'includes', 'default'].includes(selectedOp.name)">
            <label class="text-sm font-medium mb-2 block">Argumenty:</label>
            <div class="space-y-2">
              <input
                v-for="(_, i) in (operationArgs.length > 0 ? operationArgs : [''])"
                :key="i"
                v-model="operationArgs[i]"
                type="text"
                :placeholder="`Argument ${i + 1}`"
                class="w-full px-3 py-2 border border-input rounded-md bg-background text-foreground text-sm"
              />
            </div>
          </div>
        </div>

        <Button
          @click="addOperation"
          :disabled="!selectedOperation"
          class="w-full"
        >
          <Icon name="plus" size="xs" />
          Dodaj operację
        </Button>
      </div>

      <!-- Akcje -->
      <div class="flex gap-3 justify-end">
        <Button variant="ghost" @click="cancel">
          Anuluj
        </Button>
        <Button @click="save">
          Zapisz operacje
        </Button>
      </div>
    </div>
  </Dialog>
</template>

<style scoped>
.operations-panel {
  max-height: 80vh;
  overflow-y: auto;
}
</style>
