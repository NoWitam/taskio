<script setup lang="ts">
    import { computed, useSlots } from "vue";
    import { cn } from "@/lib/helpers";

    const props = withDefaults(
      defineProps<{
          modelValue: string;
          id?: string;
          label?: string;
          placeholder?: string;
          type?: "text" | "email" | "password" | "search" | "url";
          disabled?: boolean;
          error?: string;
          hint?: string;
          name?: string;
          autocomplete?: string;
          class?: string;
      }>(),
      { type: "text", disabled: false }
    );

    const emit = defineEmits<{ (e: "update:modelValue", value: string): void }>();

    const inputId = props.id ?? `in_${Math.random().toString(16).slice(2)}`;
    const describedBy = computed(() => {
      const ids: string[] = [];
      if (props.hint) ids.push(`${inputId}_hint`);
      if (props.error) ids.push(`${inputId}_err`);
      return ids.length ? ids.join(" ") : undefined;
    });

    const slots = useSlots();
    const hasLeft = computed(() => !!slots.left);
    const hasRight = computed(() => !!slots.right);
</script>

<template>
  <div class="space-y-1.5 flex flex-col">
    <label v-if="label" class="text-sm font-medium text-foreground text-left pl-0" :for="inputId">
      {{ label }}
    </label>

    <div class="relative">
      <div v-if="hasLeft" class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-auto">
        <slot name="left" />
      </div>

      <input
        :id="inputId"
        :value="modelValue"
        :type="type"
        :name="name"
        :autocomplete="autocomplete"
        :placeholder="placeholder"
        :disabled="disabled"
        :aria-invalid="!!error || undefined"
        :aria-describedby="describedBy"
        :class="cn(
          'h-10 w-full rounded-lg border bg-card px-3 text-sm text-foreground placeholder:text-muted-foreground/70',
          hasLeft && 'pl-10',
          hasRight && 'pr-10',
          'border-border hover:border-border/80',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/30 focus-visible:border-primary/40',
          disabled && 'opacity-60 cursor-not-allowed',
          error && 'border-danger focus-visible:ring-danger/25 focus-visible:border-danger',
          props.class
        )"
        @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
      />

      <div v-if="hasRight" class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-auto">
        <slot name="right" />
      </div>
    </div>

    <p v-if="hint" :id="`${inputId}_hint`" class="text-xs text-muted-foreground text-left pl-0">
      {{ hint }}
    </p>
    <p v-if="error" :id="`${inputId}_err`" class="text-xs text-danger text-left pl-0">
      {{ error }}
    </p>
  </div>
</template>
