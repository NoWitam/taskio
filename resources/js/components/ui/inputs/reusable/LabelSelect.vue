<script setup lang="ts">
import InnerLabelSelect from "@/modules/labels/components/LabelSelect.vue";

const props = withDefaults(
  defineProps<{
    modelValue: string[];
    operator?: "AND" | "OR" | null;
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
    placeholder: "Wybierz etykiety...",
  }
);

const emit = defineEmits<{
  (e: "update:modelValue", value: string[]): void;
  (e: "update:operator", value: "AND" | "OR"): void;
}>();
</script>

<template>
  <InnerLabelSelect
    v-bind="props"
    @update:modelValue="(v) => emit('update:modelValue', v)"
    @update:operator="(v) => emit('update:operator', v)"
  />
</template>
