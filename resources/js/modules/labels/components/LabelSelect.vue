<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { useDebounceFn } from '@/composables/useDebounce';
import { useLabelsStore } from '@/store/labels';
import type { Label } from '@/types';

import SelectInput from '@/components/ui/inputs/SelectInput.vue';
import TextInput from '@/components/ui/inputs/TextInput.vue';
import Badge from '@/components/ui/Badge.vue';
import Icon from '@/components/ui/Icon.vue';
import Button from '@/components/ui/Button.vue';
import Tabs from '@/components/ui/Tabs.vue';
import Dialog from '@/components/ui/Dialog.vue';

import CreateLabelForm from '@/modules/labels/components/forms/CreateLabelForm.vue';

const props = withDefaults(
  defineProps<{
    modelValue: string[];
    operator?: 'AND' | 'OR' | null;
    addable?: boolean;
    label?: string;
    placeholder?: string;
    error?: string;
    hint?: string;
    disabled?: boolean;
    class?: string;
  }>(),
  {
    modelValue: () => [],
    operator: null,
    addable: false,
    placeholder: 'Wybierz etykiety...',
  }
);

const emit = defineEmits<{
  (e: 'update:modelValue', value: string[]): void;
  (e: 'update:operator', value: 'AND' | 'OR'): void;
}>();

const selectedLabels = computed({
  get: () => props.modelValue,
  set: (value) => emit('update:modelValue', value),
});

const currentOperator = ref<'AND' | 'OR'>(props.operator || 'OR');

function setOperator(value: 'AND' | 'OR') {
  currentOperator.value = value;
  if (props.operator !== null) {
    emit('update:operator', value);
  }
}

const operatorTabs = [
  { id: 'OR', label: 'LUB' },
  { id: 'AND', label: 'ORAZ' },
];

const operatorHelp = computed(() =>
  currentOperator.value === 'OR'
    ? 'Pokaż zadania z przynajmniej jedną z wybranych etykiet.'
    : 'Pokaż zadania, które mają wszystkie wybrane etykiety.'
);

const labelsStore = useLabelsStore();
const selectRef = ref<any>(null);

function resolveSelectedLabel(value: any) {
  const id = value === undefined || value === null ? '' : String(value);
  if (!id) return null;

  const l: any = labelsStore.labelsById?.[id];
  if (!l) return null;

  return {
    ...l,
    label: l.name,
    value: l.id,
    id: l.id,
    text: l.name,
    color: l.color ?? null,
    icon: l.icon ?? null,
  };
}

watch(
  () => labelsStore.labelsVersion,
  async () => {
    await selectRef.value?.refreshItems?.();
  }
);

const debouncedSetQuery = useDebounceFn((setter: (search: string) => void, search: string) => setter(search));

async function loadLabels(cursor?: string, search?: string) {
  const res = await labelsStore.fetchLabels({
    cursor: cursor ?? null,
    search: search || undefined,
  });

  return {
    data: (res.data || []).map((label: Label) => ({
      label: label.name,
      value: label.id,
      color: label.color,
      icon: (label as any).icon ?? null,
      ...label,
    })),
    next: res.meta?.next_cursor ?? undefined,
  };
}

const createDialogOpen = ref(false);

function openCreateDialog() {
  // Close the select dropdown before opening the dialog.
  try {
    selectRef.value?.menuOpen && (selectRef.value.menuOpen.value = false);
  } catch (e) {
    // noop
  }

  createDialogOpen.value = true;
}

async function handleCreated(label: Label) {
  const createdId = String((label as any)?.id ?? '').trim();
  if (createdId) {
    // Odśwież listę etykiet w selekcie, aby nowa etykieta była dostępna
    await selectRef.value?.refreshItems?.();
    
    const next = Array.isArray(selectedLabels.value) ? [...selectedLabels.value] : [];
    if (!next.includes(createdId)) next.push(createdId);
    selectedLabels.value = next;
  }

  createDialogOpen.value = false;
}
</script>

<template>
  <SelectInput
    ref="selectRef"
    v-model="selectedLabels"
    :label="label"
    :placeholder="placeholder"
    :error="error"
    :hint="hint"
    :disabled="disabled"
    :class="class"
    :loader="loadLabels"
    :multiple="true"
    :clearable="true"
    :resolveSelected="resolveSelectedLabel"
    option-label-key="name"
    option-value-key="id"
  >
    <template #left>
      <span class="text-muted-foreground">
        <Icon name="label" size="md" />
      </span>
    </template>

    <template #panel-top="{ query, setQuery }">
      <div class="p-2 border-b border-border flex flex-col gap-2">
        <div v-if="addable || operator !== null" class="w-full">
          <div v-if="operator !== null" class="text-sm font-semibold text-muted-foreground mb-1">
            Łączenie etykiet w filtrze
          </div>

          <div class="flex justify-between items-center gap-2">
            <div v-if="operator !== null" class="shrink min-h-0">
              <Tabs
                class="min-w-0"
                :tabs="operatorTabs"
                :modelValue="currentOperator"
                @update:modelValue="(v) => setOperator(v as 'AND' | 'OR')"
              />
            </div>

            <div class="flex-1 flex justify-center">
              <Button
                v-if="addable"
                type="button"
                variant="primary"
                size="sm"
                @click="openCreateDialog"
                :class="operator === null ? 'w-full' : ''"
              >
                <Icon name="plus" size="sm" />
                <span>Nowa</span>
              </Button>
            </div>
          </div>

          <div v-if="operator !== null" class="mt-1 text-xs leading-snug text-muted-foreground">
            {{ operatorHelp }}
          </div>
        </div>

        <TextInput
          :modelValue="query"
          @update:modelValue="(v) => debouncedSetQuery(setQuery, v as string)"
          placeholder="Szukaj etykiet..."
          type="search"
          class="h-11"
        >
          <template #left>
            <span class="text-muted-foreground">
              <Icon name="search" />
            </span>
          </template>
        </TextInput>
      </div>
    </template>

    <template #item="{ item }">
      <div class="flex-1">
        <Badge :tone="item.color ? 'custom' : 'neutral'" :color="item.color" class="font-medium">
          <Icon v-if="item.icon" :name="item.icon" size="xs" />
          {{ item.name }}
        </Badge>
      </div>
    </template>
          :resolveSelected="resolveSelectedLabel"

    <template #selected="{ item, remove }">
      <template v-if="labelsStore.labelsById[String(item.id ?? item.value)]">
        <Badge
          :tone="labelsStore.labelsById[String(item.id ?? item.value)].color ? 'custom' : 'neutral'"
          :color="labelsStore.labelsById[String(item.id ?? item.value)].color"
          class="font-medium gap-2"
        >
          <Icon
            v-if="labelsStore.labelsById[String(item.id ?? item.value)].icon"
            :name="labelsStore.labelsById[String(item.id ?? item.value)].icon"
            size="xs"
          />
          <span class="truncate">{{ labelsStore.labelsById[String(item.id ?? item.value)].name }}</span>
          <button type="button" class="text-muted-foreground hover:text-foreground" @click.stop="remove">✕</button>
        </Badge>
      </template>

      <Badge v-else :tone="item.color ? 'custom' : 'neutral'" :color="item.color" class="font-medium gap-2">
        <Icon v-if="item.icon" :name="item.icon" size="xs" />
        <span class="truncate">{{ item.name }}</span>
        <button type="button" class="text-muted-foreground hover:text-foreground" @click.stop="remove">✕</button>
      </Badge>
    </template>
  </SelectInput>

  <Dialog v-model="createDialogOpen" title="Nowa etykieta" description="Utwórz nową etykietę." width="sm">
    <CreateLabelForm @created="handleCreated" @cancel="createDialogOpen = false" />
  </Dialog>
</template>
