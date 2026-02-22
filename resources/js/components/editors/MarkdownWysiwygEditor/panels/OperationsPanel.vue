<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { useI18n } from '@/composables/useI18n';
import type { VariableOperation } from '../types';
import { getOperation, getOperationsForType, getResultType } from '../utils/operations';
import Button from '@/components/ui/Button.vue';
import Dialog from '@/components/ui/Dialog.vue';
import Icon from '@/components/ui/Icon.vue';
import SelectInput from '@/components/ui/inputs/SelectInput.vue';
import TextInput from '@/components/ui/inputs/TextInput.vue';
import Badge from '@/components/ui/Badge.vue';

const { t } = useI18n();

const props = defineProps<{
  variableName: string;
  variableType: string;
  currentOperations?: Array<{ op: string; args?: any[] }>;
}>();

const emit = defineEmits<{
  'save': [data: { operations: Array<{ op: string; args?: any[] }>; panelName: string }];
  'close': [];
}>();

const isOpen = ref(true);
const panelName = ref(props.variableName);
const isNameLocked = ref(false);
const operations = ref<Array<{ op: string; args?: any[] }>>(
  JSON.parse(JSON.stringify(props.currentOperations || []))
);

// Operations available for the current result type (after all operations so far)
const availableOperations = computed(() => getOperationsForType(resultType.value));

const operationOptions = computed(() => {
  return availableOperations.value.map(({ key, operation }) => ({
    value: key,  // Use short key (e.g., "add", "length")
    label: t(operation.name),
    description: t(operation.description),
    operation,
  }));
});

const selectedOperation = ref<string>('');
const operationArgs = ref<Record<string, string>>({});

const selectedOp = computed(() => {
  if (!selectedOperation.value || selectedOperation.value === '') return null;
  return getOperation(selectedOperation.value);
});

// Calculate return type for all operations
const resultType = computed(() => {
  return getResultType(props.variableType, operations.value);
});

const resultTypeIcon = computed(() => {
  const typeIcons: Record<string, string> = {
    text: 'list',
    number: 'equal',
    boolean: 'numeric',
    date: 'calendar',
  };
  return typeIcons[resultType.value] || 'variable';
});

// Watch isOpen to emit close
watch(isOpen, (newVal) => {
  if (!newVal) {
    emit('close');
  }
});

// Get icon for type
function getTypeIcon(type: string): string {
  const typeIcons: Record<string, string> = {
    text: 'list',
    number: 'equal',
    boolean: 'numeric',
    date: 'calendar',
  };
  return typeIcons[type] || 'variable';
}

// Get icon for input type
function getInputTypeIcon(argType: string): string {
  return getTypeIcon(argType);
}

// Add operation
function addOperation() {
  if (!selectedOperation.value || selectedOperation.value === '') return;
  
  // Collect args in defined order to maintain structure
  const argsArray = selectedOp.value?.args?.map(arg => operationArgs.value[arg.key] || '').filter(a => a.trim()) || [];
  
  operations.value.push({
    op: selectedOperation.value,
    args: argsArray.length > 0 ? argsArray : undefined,
  });
  
  selectedOperation.value = '';
  operationArgs.value = {};
}

// Remove last operation only
function removeLastOperation() {
  if (operations.value.length > 0) {
    operations.value.pop();
  }
}

// Save changes
function save() {
  emit('save', {
    operations: operations.value,
    panelName: panelName.value,
  });
  isOpen.value = false;
}

// Cancel
function cancel() {
  isOpen.value = false;
}

// Toggle lock
function toggleLock() {
  isNameLocked.value = !isNameLocked.value;
}
</script>

<template>
  <Dialog v-model="isOpen">
    <template #header>
      <div>
        <div class="p-5.5 flex items-center gap-4 flex-1 min-w-0 max-w-xl">
            <Icon :name="resultTypeIcon" size="sm" class="text-primary" />
            <TextInput
                v-model="panelName"
                :disabled="isNameLocked"
                :autofocus="false"
                class="flex-1"
            >
                <template #right>
                <button
                    @click="toggleLock"
                    :title="isNameLocked ? t('common.unlock') : t('common.lock')"
                    class="flex items-center justify-center text-muted-foreground hover:text-foreground transition-colors"
                >
                    <Icon :name="isNameLocked ? 'lock' : 'lock-open'" size="sm" />
                </button>
                </template>
            </TextInput>
        </div>
      </div>
    </template>

    <div class="operations-panel space-y-6 p-6">
      <!-- Input Type Info -->
      <div class="p-3 bg-muted rounded border border-border flex items-center justify-between gap-4">
        <div>
          <p class="text-xs text-muted-foreground mb-1">{{ t('common.inputType') }}:</p>
          <span class="font-medium text-sm">{{ props.variableName }}</span>
        </div>
        <div class="flex items-center gap-2 shrink-0">
          <Icon :name="getTypeIcon(props.variableType)" size="sm" class="text-primary" />
        </div>
      </div>

      <!-- Added Operations List -->
      <div>
        <h3 class="font-medium mb-3">{{ t('common.addedOperations') }}:</h3>
        <div v-if="operations.length === 0" class="text-sm text-muted-foreground p-4 bg-muted rounded border border-border text-center">
          {{ t('common.noOperations') }}
        </div>
        <div v-else class="space-y-2">
          <div
            v-for="(op, index) in operations"
            :key="index"
            class="p-4 bg-muted rounded border border-border"
          >
            <div class="flex items-start gap-3 mb-2">
              <!-- Left: Name + Icon -->
              <div class="flex-1">
                <div class="flex items-center gap-2 mb-1">
                  <span class="font-semibold">{{ t(getOperation(op.op)?.name || op.op) }}</span>
                  <Icon :name="getTypeIcon(getOperation(op.op)?.returnType || 'text')" size="xs" class="text-primary" />
                </div>
                <p class="text-sm text-muted-foreground">
                  {{ t(getOperation(op.op)?.description || '') }}
                </p>
              </div>
              <!-- Right: Delete button (only for last operation) -->
              <div v-if="index === operations.length - 1" class="shrink-0">
                <Button
                  variant="ghost"
                  size="sm"
                  @click="removeLastOperation"
                  :title="t('common.delete')"
                >
                  <Icon name="trash" size="xs" />
                </Button>
              </div>
            </div>

            <!-- Arguments Badges -->
            <div v-if="op.args && op.args.length > 0" class="flex flex-wrap gap-2 mt-2">
              <Badge v-for="(value, idx) in op.args" :key="idx" variant="secondary" class="text-xs flex items-center gap-1.5">
                <span class="font-medium">{{ getOperation(op.op)?.args?.[idx]?.key }}</span>
                <Icon 
                  v-if="getOperation(op.op)?.args?.[idx]" 
                  :name="getTypeIcon(getOperation(op.op).args[idx].type)" 
                  size="xs" 
                />
                {{ value }}
              </Badge>
            </div>
          </div>
        </div>
      </div>

      <!-- Add New Operation Section -->
      <div class="p-4 border border-border rounded-lg bg-card space-y-4">
        <h3 class="font-medium">{{ t('common.addOperation') }}:</h3>
        
        <!-- Operation Selection -->
        <div>
          <label class="text-sm font-medium mb-2 block">{{ t('common.operation') }}</label>
          <SelectInput
            v-model="selectedOperation"
            :options="operationOptions"
            :placeholder="t('common.selectOperation')"
          >
            <template #item="{ item }">
              <div class="flex w-full items-start justify-between gap-3">
                <!-- Left: Name + Args badges -->
                <div class="flex-1">
                  <div class="flex items-center gap-2 flex-wrap">
                    <span class="font-semibold">{{ item.label }}</span>
                    <!-- Badges for arguments -->
                    <template v-if="item.operation?.args && item.operation.args.length > 0">
                      <Badge v-for="arg in item.operation.args" :key="arg.key" variant="secondary" class="text-xs">
                        {{ t(arg.label) }}
                        <Icon :name="getInputTypeIcon(arg.type)" size="xs" class="ml-1" />
                      </Badge>
                    </template>
                  </div>
                  <!-- Description -->
                  <p class="text-xs text-muted-foreground mt-1">{{ item.description }}</p>
                </div>
                <!-- Right: Return type icon -->
                <Icon :name="getTypeIcon(item.operation?.returnType || 'text')" size="xs" class="text-primary shrink-0 mt-1" />
              </div>
            </template>
          </SelectInput>
        </div>

        <!-- Arguments for Selected Operation -->
        <div v-if="selectedOp?.args && selectedOp.args.length > 0" class="space-y-3">
          <div v-for="arg in selectedOp.args" :key="arg.key" class="space-y-1">
            <label class="text-sm font-medium flex items-center gap-1">
              {{ t(arg.label) }}
              <Icon :name="getTypeIcon(arg.type)" size="xs" class="text-muted-foreground" />
            </label>
            <p class="text-xs text-muted-foreground mb-1">{{ t(arg.description) }}</p>
            <TextInput
              v-model="operationArgs[arg.key]"
              :placeholder="t(arg.description)"
            />
          </div>
        </div>

        <!-- Add Button -->
        <Button
          @click="addOperation"
          :disabled="!selectedOperation"
          class="w-full"
        >
          <Icon name="plus" size="xs" />
          {{ t('common.addOperation') }}
        </Button>
      </div>
    </div>

    <template #footer>
      <Button variant="ghost" @click="cancel">
        {{ t('common.cancel') }}
      </Button>
      <Button @click="save">
        {{ t('common.save') }}
      </Button>
    </template>
  </Dialog>
</template>

<style scoped>
.operations-panel {
  max-height: 70vh;
  overflow-y: auto;
}
</style>
