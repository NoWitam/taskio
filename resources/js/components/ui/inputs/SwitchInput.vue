<script setup lang="ts">
    import { computed, useSlots } from "vue";
    import { cn } from "@/lib/helpers";

    const props = withDefaults(
    defineProps<{
        modelValue: boolean;
        id?: string;
        label?: string;
        disabled?: boolean;
        error?: string;
        hint?: string;
        name?: string;
        class?: string;
    }>(),
    { modelValue: false, disabled: false }
    );

    const emit = defineEmits<{ (e: "update:modelValue", value: boolean): void }>();

    const inputId = props.id ?? `sw_${Math.random().toString(16).slice(2)}`;
    const describedBy = computed(() => {
    const ids: string[] = [];
    if (props.hint) ids.push(`${inputId}_hint`);
    if (props.error) ids.push(`${inputId}_err`);
    return ids.length ? ids.join(" ") : undefined;
    });

    const slots = useSlots();
    const hasLeft = computed(() => !!slots.left);
    const hasRight = computed(() => !!slots.right);

    const trackClasses = (checked: boolean) =>
    cn(
        'relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full transition-colors focus-visible:outline-none items-center',
        checked ? 'bg-primary' : 'bg-muted border border-border',
        props.disabled && 'opacity-60 cursor-not-allowed',
        props.class
    );

    const thumbClasses = (checked: boolean) =>
    cn(
        'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white dark:bg-card shadow ring-0 transition-transform',
        checked ? 'translate-x-5' : 'translate-x-0'
    );

    const toggle = () => {
    if (props.disabled) return;
    emit('update:modelValue', !props.modelValue);
    };
</script>

<template>
  <div class="space-y-1.5 flex flex-col">
    <div class="flex flex-col items-start space-y-2">
      <label v-if="label" class="text-sm font-medium text-foreground text-left pl-0" :for="inputId">
        {{ label }}
      </label>

      <div class="flex items-center">
        <div v-if="hasLeft" class="mr-2 pointer-events-auto">
          <slot name="left" />
        </div>

        <input
          :id="inputId"
          class="sr-only"
          type="checkbox"
          :name="name"
          :checked="modelValue"
          :disabled="disabled"
          :aria-invalid="!!error || undefined"
          :aria-describedby="describedBy"
          role="switch"
          :aria-checked="modelValue"
          @change="emit('update:modelValue', ($event.target as HTMLInputElement).checked)"
        />

        <label :for="inputId" :class="trackClasses(modelValue)" tabindex="0" @keydown.space.prevent="toggle" @keydown.enter.prevent="toggle">
          <span :class="thumbClasses(modelValue)"></span>
        </label>

        <div v-if="hasRight" class="ml-2 pointer-events-auto">
          <slot name="right" />
        </div>
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
