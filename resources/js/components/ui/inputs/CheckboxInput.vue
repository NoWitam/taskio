<script setup lang="ts">
  import { computed, useSlots } from "vue";
  import { cn } from "@/lib/helpers";

  type CheckboxValue = string | number;

  const props = withDefaults(
    defineProps<{
      modelValue: boolean | CheckboxValue[];
      value?: CheckboxValue; // wymagane jeśli modelValue to tablica
      id?: string;
      label?: string;        // label "nad" polem (jak w Twoich inputach)
      text?: string;         // tekst obok checkboxa (alternatywnie slot default)
      disabled?: boolean;
      error?: string;
      hint?: string;
      name?: string;
      class?: string;
    }>(),
    { disabled: false }
  );

  const emit = defineEmits<{
    (e: "update:modelValue", value: boolean | CheckboxValue[]): void;
  }>();

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
  const hasInline = computed(() => !!slots.default || !!props.text);

  const isArrayModel = computed(() => Array.isArray(props.modelValue));

  const checked = computed(() => {
    if (Array.isArray(props.modelValue)) {
      if (props.value === undefined) return false;
      return props.modelValue.includes(props.value);
    }
    return !!props.modelValue;
  });

  function toggle(next: boolean) {
    if (Array.isArray(props.modelValue)) {
      const v = props.value;
      if (v === undefined) return; // brak value -> nie ma jak aktualizować tablicy
      const set = new Set(props.modelValue);
      if (next) set.add(v);
      else set.delete(v);
      emit("update:modelValue", Array.from(set));
    } else {
      emit("update:modelValue", next);
    }
  }

  function onChange(e: Event) {
    const next = (e.target as HTMLInputElement).checked;
    toggle(next);
  }
</script>

<template>
  <div class="space-y-1.5 flex flex-col">
    <label v-if="label" class="text-sm font-medium text-foreground text-left pl-0" :for="inputId">
      {{ label }}
    </label>

    <div class="flex items-start">
      <div v-if="hasLeft" class="mr-2 pointer-events-auto">
        <slot name="left" />
      </div>

      <!-- Clickable row -->
      <label
        :for="inputId"
        :class="cn(
          'group inline-flex items-start gap-3 cursor-pointer select-none',
          disabled && 'opacity-60 cursor-not-allowed',
          props.class
        )"
      >
        <input
          :id="inputId"
          class="sr-only"
          type="checkbox"
          :name="name"
          :disabled="disabled"
          :checked="checked"
          :aria-invalid="!!error || undefined"
          :aria-describedby="describedBy"
          @change="onChange"
        />

        <!-- Box -->
        <span
          :class="cn(
            'mt-0.5 inline-flex h-5 w-5 items-center justify-center rounded border bg-card transition',
            'border-border group-hover:border-border/80',
            'group-focus-within:outline-none group-focus-within:ring-2 group-focus-within:ring-primary/30 group-focus-within:border-primary/40',
            checked ? 'bg-primary border-primary' : 'bg-card',
            error && 'border-danger group-focus-within:ring-danger/25 group-focus-within:border-danger'
          )"
          aria-hidden="true"
        >
          <svg
            v-if="checked"
            viewBox="0 0 20 20"
            class="h-4 w-4 text-primary-foreground"
            fill="none"
            stroke="currentColor"
            stroke-width="2.5"
            stroke-linecap="round"
            stroke-linejoin="round"
          >
            <path d="M4 10.5l3.2 3.2L16 5.9" />
          </svg>
        </span>

        <!-- Inline text -->
        <div v-if="hasInline" class="text-sm text-foreground leading-5">
          <slot>
            {{ text }}
          </slot>
        </div>
      </label>

      <div v-if="hasRight" class="ml-2 pointer-events-auto">
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
