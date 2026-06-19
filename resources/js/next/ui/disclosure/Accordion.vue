<script setup lang="ts">
// Accordion — a set of collapsible disclosure sections for the "next" frontend.
//
// `type="single"` keeps at most one item open (model is `string | null`);
// `type="multiple"` allows any number (model is `string[]`). Controlled when a
// parent binds `v-model`, otherwise self-managed (uncontrolled) seeded from
// `defaultValue`. Children are <AccordionItem> components that register with this
// parent via provide/inject for open state + roving keyboard navigation.
//
// A11y: keyboard nav between item HEADERS lives here (the parent owns the header
// registry): ↑/↓ move, Home/End jump; Enter/Space toggle + the open/close
// chevron live on each item. See AccordionItem for the per-item ARIA wiring.
import { computed, ref, watch } from 'vue';
import { provideAccordion } from './accordionContext';

type AccordionType = 'single' | 'multiple';

const props = withDefaults(
  defineProps<{
    type?: AccordionType;
    /** Initial open value(s) when uncontrolled. */
    defaultValue?: string | string[] | null;
  }>(),
  {
    type: 'single',
  },
);

// One model name for both shapes; the type is `string | null` (single) or
// `string[]` (multiple). Consumers bind the matching shape.
const model = defineModel<string | string[] | null>({ default: undefined });

// Uncontrolled fallback state, seeded from defaultValue.
const internal = ref<string | string[] | null>(
  props.defaultValue ?? (props.type === 'multiple' ? [] : null),
);

const isControlled = computed(() => model.value !== undefined);

const openValues = computed<string[]>(() => {
  const v = isControlled.value ? model.value : internal.value;
  if (v == null) return [];
  return Array.isArray(v) ? v : [v];
});

function setState(next: string | string[] | null): void {
  if (isControlled.value) model.value = next;
  else internal.value = next;
}

function isOpen(value: string): boolean {
  return openValues.value.includes(value);
}

function toggle(value: string): void {
  if (props.type === 'multiple') {
    const set = new Set(openValues.value);
    if (set.has(value)) set.delete(value);
    else set.add(value);
    setState(Array.from(set));
  } else {
    setState(isOpen(value) ? null : value);
  }
}

// --- Header registry for roving keyboard navigation -------------------------
const order = ref<string[]>([]);
const headers = new Map<string, { value: string; el: import('vue').Ref<HTMLElement | null> }>();

function register(value: string, el: import('vue').Ref<HTMLElement | null>): () => void {
  headers.set(value, { value, el });
  if (!order.value.includes(value)) order.value = [...order.value, value];
  return () => {
    headers.delete(value);
    order.value = order.value.filter((v) => v !== value);
  };
}

function focusByValue(value: string | undefined): void {
  if (!value) return;
  headers.get(value)?.el.value?.focus();
}

function focusMove(value: string, dir: 'next' | 'prev' | 'first' | 'last'): void {
  const list = order.value;
  if (list.length === 0) return;
  const idx = list.indexOf(value);
  let target: string | undefined;
  switch (dir) {
    case 'next':
      target = list[(idx + 1) % list.length];
      break;
    case 'prev':
      target = list[(idx - 1 + list.length) % list.length];
      break;
    case 'first':
      target = list[0];
      break;
    case 'last':
      target = list[list.length - 1];
      break;
  }
  focusByValue(target);
}

provideAccordion({ isOpen, toggle, register, focusMove });

// Keep the internal shape sane if `type` flips at runtime.
watch(
  () => props.type,
  (t) => {
    if (isControlled.value) return;
    if (t === 'multiple' && !Array.isArray(internal.value)) {
      internal.value = internal.value ? [internal.value] : [];
    } else if (t === 'single' && Array.isArray(internal.value)) {
      internal.value = internal.value[0] ?? null;
    }
  },
);
</script>

<template>
  <div class="next-accordion divide-y divide-next-border overflow-hidden rounded-next-lg border border-next-border">
    <slot />
  </div>
</template>
