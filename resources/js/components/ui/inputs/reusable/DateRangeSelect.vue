<script setup lang="ts">
import InnerDateRangeSelect from "@/components/ui/inputs/DateRangeSelect.vue";
import type { DateRangeValue } from "@/components/ui/inputs/DateRangeSelect.vue";

export type { DateRangePreset, DateRangeValue } from "@/components/ui/inputs/DateRangeSelect.vue";

const props = withDefaults(
  defineProps<{
    modelValue: DateRangeValue | null;
    id?: string;
    label?: string;
    disabled?: boolean;
    error?: string;
    hint?: string;
    class?: string;
    clearable?: boolean;
  }>(),
  {
    clearable: true,
  }
);

const emit = defineEmits<{ (e: "update:modelValue", value: DateRangeValue): void }>();
</script>

<template>
  <InnerDateRangeSelect v-bind="props" @update:modelValue="(v) => emit('update:modelValue', v)" />
</template>
