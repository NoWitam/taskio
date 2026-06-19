<script setup lang="ts">
// DropdownMenuItem — a single actionable row in a DropdownMenu (next frontend).
//
// Registers itself with the menu context for roving keyboard navigation and
// type-ahead. Renders `role="menuitem"`, supports a leading icon, a trailing
// hint/shortcut, a `disabled` state (skipped by keyboard nav, not activatable),
// and a `destructive` style for dangerous actions. Activation (click / Enter /
// Space) emits `select` and closes the menu via the context.
//
// A11y: the active item is highlighted via the menu's virtual focus
// (aria-activedescendant on the menu); items themselves are not tab stops.
import {
  computed,
  inject,
  onBeforeUnmount,
  onMounted,
  ref,
} from 'vue';
import Icon, { type IconName } from '../primitives/Icon.vue';
import { DROPDOWN_MENU_KEY } from './dropdownMenu';

const props = withDefaults(
  defineProps<{
    /** Leading icon name. */
    icon?: IconName;
    /** Trailing hint / keyboard shortcut text (e.g. "⌘K"). */
    shortcut?: string;
    disabled?: boolean;
    /** Danger styling for destructive actions (e.g. Delete). */
    destructive?: boolean;
    /** Accessible label for type-ahead when content isn't plain text. */
    label?: string;
  }>(),
  { disabled: false, destructive: false },
);

const emit = defineEmits<{ (e: 'select'): void }>();

const ctx = inject(DROPDOWN_MENU_KEY);
const el = ref<HTMLElement | null>(null);
const index = ref(-1);

const labelText = computed(() => props.label ?? el.value?.textContent?.trim() ?? '');

onMounted(() => {
  if (!ctx) return;
  index.value = ctx.register({
    el: () => el.value,
    getLabel: () => labelText.value,
    isDisabled: () => props.disabled,
  });
});

onBeforeUnmount(() => {
  if (ctx && index.value >= 0) ctx.unregister(index.value);
});

const isActive = computed(() => !!ctx && ctx.activeIndex() === index.value && !props.disabled);
const domId = computed(() => (ctx && index.value >= 0 ? ctx.itemId(index.value) : undefined));

function onClick(): void {
  if (props.disabled) return;
  emit('select');
  ctx?.activate(index.value);
}

function onMouseenter(): void {
  if (props.disabled || !ctx) return;
  ctx.setActive(index.value);
}

defineExpose({ el });
</script>

<template>
  <div
    :id="domId"
    ref="el"
    role="menuitem"
    :tabindex="-1"
    :aria-disabled="disabled ? 'true' : undefined"
    class="next-dropdown-item mx-next-1 flex cursor-pointer items-center gap-next-2 rounded-next-sm px-next-2 py-next-1_5 text-next-sm outline-none"
    :class="[
      disabled ? 'cursor-not-allowed opacity-50' : '',
      isActive && !destructive ? 'bg-next-accent text-next-accent-foreground' : '',
      isActive && destructive ? 'bg-next-danger-subtle text-next-danger-subtle-foreground' : '',
      !isActive && destructive ? 'text-next-danger' : '',
      !isActive && !destructive ? 'text-next-popover-foreground' : '',
    ]"
    @click="onClick"
    @mouseenter="onMouseenter"
  >
    <Icon v-if="icon" :name="icon" class="shrink-0" />
    <span class="min-w-0 flex-1 truncate"><slot /></span>
    <span
      v-if="shortcut"
      class="shrink-0 font-next-mono text-next-2xs text-next-muted-foreground"
      :class="isActive ? 'text-current opacity-80' : ''"
    >
      {{ shortcut }}
    </span>
  </div>
</template>
