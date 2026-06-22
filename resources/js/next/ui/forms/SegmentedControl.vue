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
    /**
     * Allow a truly unselected state (model = null → no thumb shown). By default
     * the thumb always falls back to the first enabled option so it is never hidden.
     * Pass `allow-none` when the control should appear with no selection
     * (e.g. a preset picker where "none chosen" is a valid state).
     */
    allowNone?: boolean;
    /**
     * Render options in a CSS grid with this many columns instead of a single
     * flex row. Use when options don't fit on one line (e.g. columns=2 for a
     * 2×N grid). In grid mode the sliding thumb is replaced by a per-button
     * card background on the active option.
     */
    columns?: number;
    /** Accessible label for the whole group (recommended). */
    ariaLabel?: string;
  }>(),
  {
    size: 'md',
    equalWidth: false,
    iconOnly: false,
    disabled: false,
    allowNone: false,
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
  // With allowNone, a null model means "nothing selected" — no thumb fallback.
  if (props.allowNone) return null;
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

// `columns` switches to a CSS-grid layout. In grid mode the 1-D sliding thumb
// can't track multi-row positions, so it is suppressed; the active button gets
// a card background directly. The thumb stays fully functional in row mode.
const isGrid = computed(() => (props.columns ?? 0) > 1);

// --- Sliding thumb (row mode only) ------------------------------------------
const thumb = ref<{ left: number; width: number; visible: boolean }>({
  left: 0,
  width: 0,
  visible: false,
});

function updateThumb(): void {
  if (isGrid.value) {
    thumb.value = { left: 0, width: 0, visible: false };
    return;
  }
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
  [active, () => props.options, () => props.size, () => props.iconOnly, isGrid],
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
// Grid-mode track: no fixed height (rows auto-size), keep the same padding/gap.
const SIZE_TRACK_GRID: Record<SegmentedSize, string> = {
  sm: 'p-next-0_5 gap-next-0_5',
  md: 'p-next-1 gap-next-1',
};
// Option sizes for row mode (no vertical padding — height comes from the track).
const SIZE_OPTION: Record<SegmentedSize, string> = {
  sm: 'px-next-2 text-next-xs gap-next-1',
  md: 'px-next-3 text-next-sm gap-next-1_5',
};
// Option sizes for grid mode (explicit vertical padding so buttons have height).
const SIZE_OPTION_GRID: Record<SegmentedSize, string> = {
  sm: 'px-next-2 py-next-1_5 text-next-xs gap-next-1',
  md: 'px-next-3 py-next-2 text-next-sm gap-next-1_5',
};

function optionTabIndex(value: T): number {
  if (props.disabled) return -1;
  const opt = props.options.find((o) => o.value === value);
  if (opt?.disabled) return -1;
  // Roving tabindex: active option is the tab stop; when nothing is active
  // (allowNone + null model), fall back to the first enabled option.
  const tabStop = active.value ?? firstEnabled.value;
  return value === tabStop ? 0 : -1;
}
</script>

<template>
  <div
    ref="trackRef"
    role="radiogroup"
    :aria-label="ariaLabel"
    :aria-disabled="disabled ? 'true' : undefined"
    class="next-segmented relative rounded-next-lg border border-next-border bg-next-muted"
    :class="[
      isGrid
        ? ['grid w-full', SIZE_TRACK_GRID[size]]
        : ['inline-flex items-stretch align-middle', SIZE_TRACK[size], equalWidth ? 'w-full' : ''],
      disabled ? 'opacity-60' : '',
    ]"
    :style="isGrid && columns ? { gridTemplateColumns: `repeat(${columns}, 1fr)` } : undefined"
    @keydown="onKeydown"
  >
    <!-- Sliding thumb (row-mode only): a card-tinted surface that slides behind
         the active option. Hidden in grid mode — the button carries its own bg. -->
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
      class="next-segmented__option relative z-[1] inline-flex items-center justify-center whitespace-nowrap rounded-next-md outline-none transition-colors duration-[var(--duration-next-fast)] focus-visible:ring-2 focus-visible:ring-next-ring"
      :class="[
        isGrid ? SIZE_OPTION_GRID[size] : [SIZE_OPTION[size], 'shrink-0', equalWidth ? 'flex-1' : ''],
        opt.disabled || disabled ? 'cursor-not-allowed' : 'cursor-pointer',
        opt.value === active
          ? isGrid
            ? 'bg-next-card font-next-medium text-next-fg shadow-next-xs'
            : 'font-next-medium text-next-fg'
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
