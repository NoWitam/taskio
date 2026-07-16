<script setup lang="ts">
// Switch — an on/off toggle for the "next" frontend.
//
// Renders a real <button role="switch"> with aria-checked; Space/Enter toggle
// (native button activation). Optional leading/trailing label text that is also
// clickable (the whole row toggles). A `loading` state shows a spinner inside the
// thumb and blocks toggling (for pending async toggles), setting aria-busy.
// v-model is the boolean on/off state.
import { computed } from 'vue';
import Spinner from '../primitives/Spinner.vue';
import { useFormField, nextId } from './formField';

const props = withDefaults(
  defineProps<{
    /** Label rendered after the switch (default position). */
    label?: string;
    /** Put the label before the switch instead. */
    labelPosition?: 'leading' | 'trailing';
    disabled?: boolean;
    /** Pending async toggle: spinner in thumb, blocks input, aria-busy. */
    loading?: boolean;
    size?: 'sm' | 'md';
    ariaInvalid?: boolean;
    id?: string;
    describedById?: string;
    /** Accessible name when there's no visible label/FormField. */
    ariaLabel?: string;
  }>(),
  {
    labelPosition: 'trailing',
    disabled: false,
    loading: false,
    size: 'md',
  },
);

const model = defineModel<boolean>({ default: false });

const field = useFormField();
const resolvedId = computed(() => props.id ?? field?.id.value ?? generatedId);
const generatedId = nextId('next-switch');
const describedBy = computed(
  () => props.describedById ?? field?.describedById.value,
);
const disabled = computed(() => props.disabled || (field?.disabled.value ?? false));
const inert = computed(() => disabled.value || props.loading);

function toggle(): void {
  if (inert.value) return;
  model.value = !model.value;
}

const dims = computed(() =>
  props.size === 'sm'
    ? { track: 'h-5 w-9', thumb: 'h-4 w-4', travel: 'translate-x-4' }
    : { track: 'h-6 w-11', thumb: 'h-5 w-5', travel: 'translate-x-5' },
);

const trackClass = computed(() => {
  const base =
    'relative inline-flex shrink-0 items-center rounded-next-full border border-transparent transition-colors duration-[var(--duration-next-fast)] ease-[var(--ease-next-standard)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-next-ring';
  const on = model.value ? 'bg-next-primary' : 'bg-next-input';
  const dim = inert.value ? 'opacity-60 cursor-not-allowed' : 'cursor-pointer';
  return `${base} ${on} ${dim}`;
});
</script>

<template>
  <label
    class="inline-flex items-center gap-next-2"
    :class="[
      labelPosition === 'leading' ? 'flex-row-reverse' : 'flex-row',
      inert ? 'cursor-not-allowed' : 'cursor-pointer',
    ]"
  >
    <button
      :id="resolvedId"
      type="button"
      role="switch"
      :class="[trackClass, dims.track]"
      :aria-checked="model"
      :aria-busy="loading ? 'true' : undefined"
      :aria-invalid="ariaInvalid ? 'true' : undefined"
      :aria-describedby="describedBy"
      :aria-label="ariaLabel"
      :disabled="disabled"
      @click="toggle"
    >
      <span
        class="pointer-events-none flex items-center justify-center rounded-next-full bg-next-card shadow-next-sm transition-transform duration-[var(--duration-next-fast)] ease-[var(--ease-next-standard)]"
        :class="[dims.thumb, model ? dims.travel : 'translate-x-0.5']"
      >
        <Spinner v-if="loading" size="xs" tone="muted" decorative />
      </span>
    </button>

    <span v-if="label || $slots.default" class="text-next-sm font-next-medium text-next-fg">
      <slot>{{ label }}</slot>
    </span>
  </label>
</template>
