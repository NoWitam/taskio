<script setup lang="ts">
// Radio — a single option inside a <RadioGroup>.
//
// Uses a real <input type="radio"> (visually hidden) for native semantics +
// form participation, with a styled dot. The surrounding RadioGroup drives
// selection, roving tabindex (only the selected/first radio has tabindex 0), and
// arrow-key navigation. A Radio can also be used standalone (uncontrolled
// fallback) but is intended for use within a group.
import { computed, onBeforeUnmount, onMounted } from 'vue';
import { useRadioGroup } from './radioGroup';

const props = withDefaults(
  defineProps<{
    /** The value this radio represents. */
    value: string;
    label?: string;
    description?: string;
    disabled?: boolean;
    size?: 'sm' | 'md';
  }>(),
  {
    disabled: false,
    size: 'md',
  },
);

const group = useRadioGroup();

if (import.meta.env?.DEV && !group) {
  // eslint-disable-next-line no-console
  console.warn('[next/Radio] <Radio> should be used inside a <RadioGroup>.');
}

let unregister: (() => void) | undefined;
onMounted(() => {
  unregister = group?.register(props.value);
});
onBeforeUnmount(() => unregister?.());

const checked = computed(() => group?.value.value === props.value);
const disabled = computed(() => props.disabled || (group?.disabled.value ?? false));
const invalid = computed(() => group?.invalid.value ?? false);
// Roving tabindex: only the tabbable radio (selected/first) is reachable by Tab.
const tabindex = computed(() => (group?.isTabbable(props.value) ? 0 : -1));

const descId = computed(() =>
  props.description ? `next-radio-${props.value}-desc` : undefined,
);

const DOT_SIZE = computed(() => (props.size === 'sm' ? 'h-4 w-4' : 'h-5 w-5'));

const ringClass = computed(() => {
  const base =
    'relative flex shrink-0 items-center justify-center rounded-next-full border transition-colors duration-[var(--duration-next-fast)]';
  const state = checked.value
    ? 'border-next-primary'
    : invalid.value
      ? 'border-next-danger'
      : 'border-next-input peer-hover:border-next-fg/40';
  const focus =
    'peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-next-ring';
  const dim = disabled.value ? 'opacity-60' : '';
  return `${base} ${state} ${focus} ${dim}`;
});

function onChange(): void {
  group?.select(props.value);
}
function onKeydown(event: KeyboardEvent): void {
  group?.onKeydown(event, props.value);
}
</script>

<template>
  <label
    class="flex items-start gap-next-2"
    :class="disabled ? 'cursor-not-allowed opacity-80' : 'cursor-pointer'"
  >
    <span class="inline-flex">
      <input
        type="radio"
        class="peer sr-only"
        :name="group?.name"
        :value="value"
        :checked="checked"
        :disabled="disabled"
        :tabindex="tabindex"
        :data-radio-value="value"
        :aria-describedby="descId"
        @change="onChange"
        @keydown="onKeydown"
      />
      <span :class="[ringClass, DOT_SIZE]">
        <span
          v-if="checked"
          class="rounded-next-full bg-next-primary"
          :class="size === 'sm' ? 'h-1.5 w-1.5' : 'h-2 w-2'"
        />
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
