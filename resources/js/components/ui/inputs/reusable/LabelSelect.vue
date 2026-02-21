<script setup lang="ts">
import InnerLabelSelect from "@/modules/labels/components/LabelSelect.vue";
import { computed } from "vue";
import { useI18n } from "@/composables/useI18n";

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
    placeholder: "",
  }
);

const emit = defineEmits<{
  (e: "update:modelValue", value: string[]): void;
  (e: "update:operator", value: "AND" | "OR"): void;
}>();

const { t } = useI18n();

const defaultPlaceholder = computed(() => 
  props.placeholder || t('tasks.selectLabels')
);
</script>

<template>
  <InnerLabelSelect
    v-bind="{ ...props, placeholder: defaultPlaceholder }"
    @update:modelValue="(v) => emit('update:modelValue', v)"
    @update:operator="(v) => emit('update:operator', v)"
  />
</template>
