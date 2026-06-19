<script setup lang="ts">
// RadioGroup — the container + coordinator for a set of <Radio> children.
//
// role="radiogroup" with an accessible name (from FormField's label, or
// `ariaLabel`). Owns the selected value (v-model), and implements roving
// tabindex: exactly one radio is in the tab order — the selected one, or the
// first enabled one when nothing is selected. Arrow keys move selection AND
// focus (per the radiogroup pattern); Home/End jump to the first/last enabled
// radio. Layout is vertical (default) or horizontal.
import { computed, ref } from 'vue';
import { useFormField } from './formField';
import { provideRadioGroup } from './radioGroup';

let groupSeq = 0;

const props = withDefaults(
  defineProps<{
    /** Shared input `name`. Auto-generated when omitted. */
    name?: string;
    orientation?: 'vertical' | 'horizontal';
    disabled?: boolean;
    ariaInvalid?: boolean;
    describedById?: string;
    /** Accessible name when not wrapped in a FormField with a label. */
    ariaLabel?: string;
  }>(),
  {
    orientation: 'vertical',
    disabled: false,
  },
);

const model = defineModel<string | null>({ default: null });

const field = useFormField();
const name = props.name ?? `next-radio-group-${(groupSeq += 1)}`;
const disabled = computed(() => props.disabled || (field?.disabled.value ?? false));
const invalid = computed(() => props.ariaInvalid ?? field?.invalid.value ?? false);
const describedById = computed(
  () => props.describedById ?? field?.describedById.value,
);

// Registration order = DOM order, used for arrow nav + roving tabindex.
const order = ref<string[]>([]);
function register(value: string): () => void {
  order.value.push(value);
  return () => {
    order.value = order.value.filter((v) => v !== value);
  };
}

function select(value: string): void {
  if (disabled.value) return;
  model.value = value;
}

// The single tabbable radio: the selected one, else the first registered.
function isTabbable(value: string): boolean {
  if (model.value != null) return model.value === value;
  return order.value[0] === value;
}

function focusValue(value: string): void {
  const el = rootRef.value?.querySelector<HTMLElement>(
    `[data-radio-value="${CSS.escape(value)}"]`,
  );
  el?.focus();
}

function onKeydown(event: KeyboardEvent, value: string): void {
  if (disabled.value) return;
  const items = order.value;
  const idx = items.indexOf(value);
  if (idx === -1) return;

  let target = -1;
  switch (event.key) {
    case 'ArrowDown':
    case 'ArrowRight':
      target = (idx + 1) % items.length;
      break;
    case 'ArrowUp':
    case 'ArrowLeft':
      target = (idx - 1 + items.length) % items.length;
      break;
    case 'Home':
      target = 0;
      break;
    case 'End':
      target = items.length - 1;
      break;
    default:
      return;
  }
  event.preventDefault();
  const next = items[target];
  select(next);
  focusValue(next);
}

const rootRef = ref<HTMLElement | null>(null);

provideRadioGroup({
  name,
  value: model,
  disabled,
  invalid,
  describedById,
  register,
  select,
  isTabbable,
  onKeydown,
});
</script>

<template>
  <div
    ref="rootRef"
    role="radiogroup"
    :aria-label="ariaLabel"
    :aria-invalid="invalid ? 'true' : undefined"
    :aria-describedby="describedById"
    class="flex gap-next-3"
    :class="orientation === 'horizontal' ? 'flex-row flex-wrap items-center' : 'flex-col'"
  >
    <slot />
  </div>
</template>
