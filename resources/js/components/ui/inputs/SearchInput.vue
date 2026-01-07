<script setup lang="ts">
  import { computed, ref } from "vue";
  import { cn } from "@/lib/helpers";

  const props = withDefaults(
    defineProps<{
      modelValue: string;
      id?: string;
      placeholder?: string;
      disabled?: boolean;
      debounceMs?: number;
      class?: string;
    }>(),
    { placeholder: "Search...", disabled: false, debounceMs: 300 }
  );

  const emit = defineEmits<{
    (e: "update:modelValue", value: string): void;
    (e: "search", value: string): void;
  }>();

  const inputId = props.id ?? `search_${Math.random().toString(16).slice(2)}`;
  const isFocused = ref(false);
  let debounceTimer: ReturnType<typeof setTimeout> | null = null;

  const handleInput = (e: Event) => {
    const value = (e.target as HTMLInputElement).value;
    emit("update:modelValue", value);

    // Debounced search
    if (debounceTimer) clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
      emit("search", value);
    }, props.debounceMs);
  };

  const clearSearch = () => {
    emit("update:modelValue", "");
    emit("search", "");
  };
</script>

<template>
  <div class="relative w-full" :class="class">
    <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
      <svg class="w-4 h-4 text-muted-foreground" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
      </svg>
    </div>

    <input
      :id="inputId"
      :value="modelValue"
      type="text"
      :placeholder="placeholder"
      :disabled="disabled"
      @input="handleInput"
      @focus="isFocused = true"
      @blur="isFocused = false"
      class="w-full pl-10 pr-10 h-10 rounded-lg border border-border bg-background text-foreground placeholder-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/30 disabled:opacity-50 disabled:cursor-not-allowed transition"
      :aria-disabled="disabled"
    />

    <button
      v-if="modelValue"
      @click="clearSearch"
      type="button"
      class="absolute inset-y-0 right-0 flex items-center pr-3 text-muted-foreground hover:text-foreground transition"
      :title="`Clear search`"
      :aria-label="`Clear search`"
    >
      <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
        <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
      </svg>
    </button>
  </div>
</template>
