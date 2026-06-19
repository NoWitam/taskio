<script setup lang="ts">
// Checkbox — boolean (or indeterminate) control for the "next" frontend.
//
// Uses a real <input type="checkbox"> (visually hidden via `sr-only` + peer) so
// native semantics, Space-to-toggle, and form participation come for free; a
// styled box renders the check / indeterminate dash. The native input's
// `indeterminate` is a DOM property (not an attribute), set via a ref/watch, and
// mirrored to aria-checked="mixed".
//
// v-model is the boolean checked state. `indeterminate` is a separate prop
// (visual + a11y); checking the box clears it (the consumer updates the prop).
// Inline label + optional description; standalone error styling. Works inside a
// FormField (disabled/invalid/describedby) or standalone.
import { computed, ref, watch } from 'vue';
import Icon from '../primitives/Icon.vue';
import { useFormField, nextId } from './formField';

const props = withDefaults(
  defineProps<{
    /** Visible inline label. */
    label?: string;
    /** Secondary line under the label. */
    description?: string;
    indeterminate?: boolean;
    disabled?: boolean;
    ariaInvalid?: boolean;
    id?: string;
    describedById?: string;
    name?: string;
    value?: string;
    size?: 'sm' | 'md';
  }>(),
  {
    indeterminate: false,
    disabled: false,
    size: 'md',
  },
);

const model = defineModel<boolean>({ default: false });

const field = useFormField();
const resolvedId = computed(() => props.id ?? field?.id.value ?? generatedId);
const generatedId = nextId('next-checkbox');
const descId = computed(() =>
  props.description ? `${resolvedId.value}-desc` : undefined,
);
const describedBy = computed(() => {
  const ids = [
    descId.value,
    props.describedById ?? field?.describedById.value,
  ].filter(Boolean);
  return ids.length ? ids.join(' ') : undefined;
});
const invalid = computed(() => props.ariaInvalid ?? field?.invalid.value ?? false);
const disabled = computed(() => props.disabled || (field?.disabled.value ?? false));

const inputRef = ref<HTMLInputElement | null>(null);
watch(
  () => props.indeterminate,
  (v) => {
    if (inputRef.value) inputRef.value.indeterminate = v;
  },
  { immediate: true },
);
watch(inputRef, (el) => {
  if (el) el.indeterminate = props.indeterminate;
});

const ariaChecked = computed(() =>
  props.indeterminate ? 'mixed' : model.value ? 'true' : 'false',
);

const BOX_SIZE = computed(() => (props.size === 'sm' ? 'h-4 w-4 text-next-xs' : 'h-5 w-5 text-next-sm'));

// Box visuals: filled when checked OR indeterminate.
const filled = computed(() => model.value || props.indeterminate);
const boxClass = computed(() => {
  const base =
    'relative flex shrink-0 items-center justify-center rounded-next-xs border transition-colors duration-[var(--duration-next-fast)]';
  const state = filled.value
    ? 'bg-next-primary border-next-primary text-next-primary-foreground'
    : invalid.value
      ? 'bg-next-card border-next-danger'
      : 'bg-next-card border-next-input peer-hover:border-next-fg/40';
  // Focus ring follows the (hidden) input via peer-focus-visible.
  const focus =
    'peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-next-ring';
  const dim = disabled.value ? 'opacity-60' : '';
  return `${base} ${state} ${focus} ${dim}`;
});
</script>

<template>
  <label
    class="flex items-start gap-next-2"
    :class="disabled ? 'cursor-not-allowed opacity-80' : 'cursor-pointer'"
  >
    <span class="inline-flex">
      <input
        :id="resolvedId"
        ref="inputRef"
        v-model="model"
        type="checkbox"
        class="peer sr-only"
        :disabled="disabled"
        :name="name"
        :value="value"
        :aria-checked="ariaChecked"
        :aria-invalid="invalid ? 'true' : undefined"
        :aria-describedby="describedBy"
      />
      <span :class="[boxClass, BOX_SIZE]">
        <Icon v-if="indeterminate" name="minus" :stroke-width="3" />
        <Icon v-else-if="model" name="check" :stroke-width="3" />
      </span>
    </span>

    <span v-if="label || description || $slots.default" class="flex flex-col gap-next-0_5">
      <span class="text-next-sm font-next-medium text-next-fg leading-next-snug">
        <slot>{{ label }}</slot>
      </span>
      <span v-if="description" :id="descId" class="text-next-xs text-next-muted-foreground">
        {{ description }}
      </span>
    </span>
  </label>
</template>

<style scoped>
.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}
</style>
