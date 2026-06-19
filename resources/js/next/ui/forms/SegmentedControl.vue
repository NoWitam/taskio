<script setup lang="ts" generic="T extends string = string">
// SegmentedControl — a compact, mutually-exclusive single-select toggle for the
// "next" frontend.
//
// Distinct from Tabs: Tabs switch PANELS (`role="tablist"`); a SegmentedControl
// is a single CHOICE among a few options (`role="radiogroup"`), used for view /
// filter toggles like List/Board or All/Active/Done. It does not own panels.
//
// Each option is rendered as a radio (roving tabindex, arrow-key navigation).
// The selected segment is highlighted by a token-tinted "thumb" that slides
// behind the active option (a single absolutely-positioned element animated via
// the motion tokens; reduced motion is handled globally).
//
// A11y: `role="radiogroup"` + `role="radio"` per option with `aria-checked`;
// only the selected (or first enabled) option is a tab stop; ←/↑ and →/↓ move
// the selection (skipping disabled, wrapping); Home/End jump to the ends;
// Space/Enter (re)select the focused option. Color is never the only signal —
// the selected option also gets a card surface + shadow + medium weight.
import { computed, nextTick, ref, watch } from 'vue';
import Icon, { type IconName } from '../primitives/Icon.vue';

export interface SegmentOption<V extends string = string> {
  value: V;
  label: string;
  icon?: IconName;
  disabled?: boolean;
}

type SegmentedSize = 'sm' | 'md';

const props = withDefaults(
  defineProps<{
    options: SegmentOption<T>[];
    size?: SegmentedSize;
    /** Stretch each option to an equal share of the track width. */
    equalWidth?: boolean;
    /** Hide labels, show only icons (each option still needs an aria-label). */
    iconOnly?: boolean;
    disabled?: boolean;
    /** Accessible label for the whole group (recommended). */
    ariaLabel?: string;
  }>(),
  {
    size: 'md',
    equalWidth: false,
    iconOnly: false,
    disabled: false,
  },
);

const model = defineModel<T | null>({ default: null });

const groupId = `next-seg-${Math.random().toString(36).slice(2, 8)}`;

const enabled = computed(() => props.options.filter((o) => !o.disabled));
const firstEnabled = computed<T | null>(() => enabled.value[0]?.value ?? null);

// The active value falls back to the first enabled option when the model is
// unset / points at a missing or disabled option (so a thumb always has a home).
const active = computed<T | null>(() => {
  const v = model.value;
  if (v != null && props.options.some((o) => o.value === v && !o.disabled)) return v;
  return firstEnabled.value;
});

function select(value: T): void {
  if (props.disabled) return;
  const opt = props.options.find((o) => o.value === value);
  if (!opt || opt.disabled) return;
  model.value = value;
}

// --- Refs + roving focus ----------------------------------------------------
const optionRefs = ref<Record<string, HTMLButtonElement | null>>({});
const trackRef = ref<HTMLElement | null>(null);

function setOptionRef(el: HTMLButtonElement | null, value: string): void {
  optionRefs.value[value] = el;
}

function focusOption(value: T): void {
  optionRefs.value[value]?.focus();
}

function neighbour(from: T | null, dir: 1 | -1): T | null {
  const list = enabled.value;
  if (list.length === 0) return null;
  const idx = from == null ? -1 : list.findIndex((o) => o.value === from);
  const start = idx === -1 ? 0 : idx;
  const next = (start + dir + list.length) % list.length;
  return list[next].value;
}

function move(dir: 1 | -1): void {
  const target = neighbour(active.value, dir);
  if (target == null) return;
  select(target);
  nextTick(() => focusOption(target));
}

function onKeydown(event: KeyboardEvent): void {
  if (props.disabled) return;
  switch (event.key) {
    case 'ArrowRight':
    case 'ArrowDown':
      event.preventDefault();
      move(1);
      break;
    case 'ArrowLeft':
    case 'ArrowUp':
      event.preventDefault();
      move(-1);
      break;
    case 'Home':
      event.preventDefault();
      if (firstEnabled.value != null) {
        select(firstEnabled.value);
        nextTick(() => focusOption(firstEnabled.value!));
      }
      break;
    case 'End': {
      event.preventDefault();
      const last = enabled.value[enabled.value.length - 1];
      if (last) {
        select(last.value);
        nextTick(() => focusOption(last.value));
      }
      break;
    }
    case ' ':
    case 'Enter':
      event.preventDefault();
      if (active.value != null) select(active.value);
      break;
  }
}

// --- Sliding thumb ----------------------------------------------------------
// The thumb is positioned over the active option by measuring its DOM rect
// relative to the track. Recomputed on selection, option changes, and resize.
const thumb = ref<{ left: number; width: number; visible: boolean }>({
  left: 0,
  width: 0,
  visible: false,
});

function updateThumb(): void {
  const track = trackRef.value;
  const value = active.value;
  if (!track || value == null) {
    thumb.value = { left: 0, width: 0, visible: false };
    return;
  }
  const el = optionRefs.value[value];
  if (!el) {
    thumb.value = { ...thumb.value, visible: false };
    return;
  }
  thumb.value = {
    left: el.offsetLeft,
    width: el.offsetWidth,
    visible: true,
  };
}

let ro: ResizeObserver | undefined;
watch(
  [active, () => props.options, () => props.size, () => props.iconOnly],
  () => nextTick(updateThumb),
  { deep: true, immediate: true },
);

watch(trackRef, (el) => {
  ro?.disconnect();
  if (el && typeof ResizeObserver !== 'undefined') {
    ro = new ResizeObserver(() => updateThumb());
    ro.observe(el);
  }
  nextTick(updateThumb);
});

// --- Styling ----------------------------------------------------------------
const SIZE_TRACK: Record<SegmentedSize, string> = {
  sm: 'h-8 p-next-0_5 gap-next-0_5',
  md: 'h-10 p-next-1 gap-next-1',
};
const SIZE_OPTION: Record<SegmentedSize, string> = {
  sm: 'px-next-2 text-next-xs gap-next-1',
  md: 'px-next-3 text-next-sm gap-next-1_5',
};

function optionTabIndex(value: T): number {
  if (props.disabled) return -1;
  const opt = props.options.find((o) => o.value === value);
  if (opt?.disabled) return -1;
  // Roving tabindex: only the active option is a tab stop.
  return value === active.value ? 0 : -1;
}
</script>

<template>
  <div
    ref="trackRef"
    role="radiogroup"
    :aria-label="ariaLabel"
    :aria-disabled="disabled ? 'true' : undefined"
    class="next-segmented relative inline-flex items-stretch rounded-next-lg border border-next-border bg-next-muted align-middle"
    :class="[SIZE_TRACK[size], equalWidth ? 'w-full' : '', disabled ? 'opacity-60' : '']"
    @keydown="onKeydown"
  >
    <!-- Sliding thumb (decorative): a card-tinted surface behind the active option. -->
    <span
      v-show="thumb.visible"
      class="next-segmented__thumb pointer-events-none absolute top-0 bottom-0 my-[var(--spacing-next-0_5)] rounded-next-md bg-next-card shadow-next-xs transition-[transform,width] duration-[var(--duration-next-fast)] ease-[var(--ease-next-standard)]"
      :style="{
        width: `${thumb.width}px`,
        transform: `translateX(${thumb.left}px)`,
      }"
      aria-hidden="true"
    />

    <button
      v-for="opt in options"
      :key="opt.value"
      :ref="(el) => setOptionRef(el as HTMLButtonElement | null, opt.value)"
      type="button"
      role="radio"
      :id="`${groupId}-${opt.value}`"
      :aria-checked="opt.value === active"
      :aria-label="iconOnly ? opt.label : undefined"
      :aria-disabled="opt.disabled || disabled ? 'true' : undefined"
      :tabindex="optionTabIndex(opt.value)"
      :disabled="opt.disabled || disabled"
      class="next-segmented__option relative z-[1] inline-flex shrink-0 items-center justify-center whitespace-nowrap rounded-next-md outline-none transition-colors duration-[var(--duration-next-fast)] focus-visible:ring-2 focus-visible:ring-next-ring"
      :class="[
        SIZE_OPTION[size],
        equalWidth ? 'flex-1' : '',
        opt.disabled || disabled ? 'cursor-not-allowed' : 'cursor-pointer',
        opt.value === active
          ? 'font-next-medium text-next-fg'
          : 'text-next-muted-foreground hover:text-next-fg',
        opt.disabled ? 'opacity-50' : '',
      ]"
      @click="select(opt.value)"
    >
      <Icon v-if="opt.icon" :name="opt.icon" class="shrink-0" />
      <span v-if="!iconOnly">{{ opt.label }}</span>
    </button>
  </div>
</template>
