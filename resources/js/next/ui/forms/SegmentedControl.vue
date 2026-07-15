<script setup lang="ts" generic="T extends string = string">
// SegmentedControl — SELECTION CARDS for the "next" frontend.
//
// A choice among a few options, rendered as a grid/wrap of CARDS. Each card shows
// a label, an optional icon, and an optional description, plus a visible
// selectability indicator: a RADIO dot in single-select, a CHECKBOX in the
// `multiple` mode. The selected card carries a primary border + a subtle tinted
// surface + a filled indicator + medium weight — color is never the only signal.
//
// Distinct from Tabs: Tabs switch PANELS (`role="tablist"`); a SegmentedControl is
// a single CHOICE among a few options. It does not own panels.
//
// A11y — SINGLE (default): `role="radiogroup"` + `role="radio"` per option with
// `aria-checked`; only the selected (or first enabled) option is a tab stop; ←/↑
// and →/↓ MOVE the selection (skipping disabled, wrapping); Home/End jump to the
// ends; Space/Enter (re)select the focused option.
//
// A11y — MULTIPLE (`multiple`): `role="group"` + `role="checkbox"` per option with
// `aria-checked`; arrows MOVE FOCUS only (no selection change); Space/Enter toggle
// the focused option; the tab stop is the first checked option, else the first
// enabled one. `allowNone` is a no-op in multi (an empty array is the natural rest).
//
// The visible radio/checkbox indicator is decorative (`aria-hidden`) — the button's
// role + aria-checked carry the semantics. `iconOnly` hides both the label text and
// the indicator (selection is carried by the whole card's border/tint); the label
// becomes the button's `aria-label`.
import { computed, nextTick, ref } from 'vue';
import Icon, { type IconName } from '../primitives/Icon.vue';
import { useI18n } from '../../app/i18n';

export interface SegmentOption<V extends string = string> {
  value: V;
  label: string;
  icon?: IconName;
  /** Optional secondary line under the label (muted, smaller). */
  description?: string;
  disabled?: boolean;
}

type SegmentedSize = 'sm' | 'md';

const props = withDefaults(
  defineProps<{
    options: SegmentOption<T>[];
    size?: SegmentedSize;
    /** Stretch each card to an equal share of the row (flex-1 in wrap mode). */
    equalWidth?: boolean;
    /** Hide labels + the indicator, show only icons (each option still needs an aria-label). */
    iconOnly?: boolean;
    disabled?: boolean;
    /**
     * SINGLE mode only: allow a truly unselected state (model = null → no card
     * highlighted). By default the active fallback is the first enabled option so a
     * selection is always shown. Pass `allow-none` when "nothing chosen" is valid
     * (e.g. a preset picker). Ignored in `multiple` mode (an empty array is natural).
     */
    allowNone?: boolean;
    /**
     * Render the cards in a CSS grid with this many columns. `columns=1` stacks the
     * cards VERTICALLY (top→bottom, full width). Without it the cards wrap in a
     * flex row (equal height; `equalWidth` → flex-1 so they share width).
     */
    columns?: number;
    /**
     * MULTI-SELECT: the model becomes a `T[]`; each card is a checkbox that toggles
     * its value in the array (array order follows `options`).
     */
    multiple?: boolean;
    /**
     * MULTI-SELECT only: prepend a "Select all" card that toggles every ENABLED
     * option at once. Its checkbox is tri-state: checked (all), `aria-checked=mixed`
     * with a minus glyph (some), empty (none). Values of DISABLED options already in
     * the model are preserved either way.
     */
    selectAll?: boolean;
    /** Override the "Select all" card's label (defaults to the i18n `segmented.selectAll`). */
    selectAllLabel?: string;
    /** Accessible label for the whole group (recommended). */
    ariaLabel?: string;
  }>(),
  {
    size: 'md',
    equalWidth: false,
    iconOnly: false,
    disabled: false,
    allowNone: false,
    multiple: false,
    selectAll: false,
  },
);

// The model is a single value (`T | null`) in default mode and a `T[]` in
// `multiple` mode. Runtime branching keeps a single defineModel for both shapes.
const model = defineModel<T | null | T[]>({ default: null });

const { t } = useI18n();

const groupId = `next-seg-${Math.random().toString(36).slice(2, 8)}`;

const enabled = computed(() => props.options.filter((o) => !o.disabled));
const firstEnabled = computed<T | null>(() => enabled.value[0]?.value ?? null);

// --- Selection state (single vs multiple) ----------------------------------
/** The current multi-select array (empty when unset / not an array). */
const selectedArray = computed<T[]>(() =>
  props.multiple && Array.isArray(model.value) ? (model.value as T[]) : [],
);

// SINGLE: the active value falls back to the first enabled option when the model is
// unset / points at a missing or disabled option (so a card is always highlighted),
// unless `allowNone` permits a genuinely empty state.
const active = computed<T | null>(() => {
  if (props.multiple) return null;
  const v = model.value as T | null;
  if (v != null && props.options.some((o) => o.value === v && !o.disabled)) return v;
  if (props.allowNone) return null;
  return firstEnabled.value;
});

/** Whether a given option reads as selected (single: is-active; multi: in the array). */
function isSelected(value: T): boolean {
  return props.multiple ? selectedArray.value.includes(value) : value === active.value;
}

function select(value: T): void {
  if (props.disabled) return;
  const opt = props.options.find((o) => o.value === value);
  if (!opt || opt.disabled) return;

  if (props.multiple) {
    // Toggle in the array, preserving `options` order on re-insert.
    const current = selectedArray.value;
    const next = current.includes(value)
      ? current.filter((v) => v !== value)
      : props.options.filter((o) => current.includes(o.value) || o.value === value).map((o) => o.value);
    model.value = next as T[];
    return;
  }
  model.value = value;
}

// --- Refs + roving focus ----------------------------------------------------
const optionRefs = ref<Record<string, HTMLButtonElement | null>>({});

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

/** SINGLE: arrows move the SELECTION (and focus). MULTI: arrows move FOCUS only. */
function move(dir: 1 | -1): void {
  const from = props.multiple ? focusedValue.value : active.value;
  const target = neighbour(from, dir);
  if (target == null) return;
  if (!props.multiple) select(target);
  focusedValue.value = target;
  nextTick(() => focusOption(target));
}

// MULTI mode tracks which option currently holds focus (single mode leans on `active`).
const focusedValue = ref<T | null>(null);

function onKeydown(event: KeyboardEvent): void {
  if (props.disabled) return;
  // The "Select all" card is a plain button OUTSIDE the roving pattern — let its
  // native Space/Enter activation through (arrows still move into the options).
  if ((event.target as HTMLElement | null)?.dataset?.segSelectAll !== undefined && (event.key === ' ' || event.key === 'Enter')) {
    return;
  }
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
        if (!props.multiple) select(firstEnabled.value);
        focusedValue.value = firstEnabled.value;
        nextTick(() => focusOption(firstEnabled.value!));
      }
      break;
    case 'End': {
      event.preventDefault();
      const last = enabled.value[enabled.value.length - 1];
      if (last) {
        if (!props.multiple) select(last.value);
        focusedValue.value = last.value;
        nextTick(() => focusOption(last.value));
      }
      break;
    }
    case ' ':
    case 'Enter': {
      event.preventDefault();
      // MULTI: toggle the focused card. SINGLE: (re)select the active one.
      const target = props.multiple ? focusedValue.value : active.value;
      if (target != null) select(target);
      break;
    }
  }
}

function onOptionFocus(value: T): void {
  focusedValue.value = value;
}

// --- "Select all" (multi-select only) ----------------------------------------
const showSelectAll = computed(() => props.multiple && props.selectAll && enabled.value.length > 0);
const allSelected = computed(
  () => enabled.value.length > 0 && enabled.value.every((o) => selectedArray.value.includes(o.value)),
);
const someSelected = computed(() => enabled.value.some((o) => selectedArray.value.includes(o.value)));
const selectAllChecked = computed<'true' | 'false' | 'mixed'>(() =>
  allSelected.value ? 'true' : someSelected.value ? 'mixed' : 'false',
);
const selectAllText = computed(() => props.selectAllLabel ?? t('segmented.selectAll', 'Select all'));

/** Toggle every ENABLED option; values of disabled options in the model are preserved. */
function toggleAll(): void {
  if (props.disabled || !props.multiple) return;
  const keepDisabled = selectedArray.value.filter(
    (v) => props.options.find((o) => o.value === v)?.disabled,
  );
  const next = allSelected.value
    ? keepDisabled
    : props.options.filter((o) => !o.disabled || keepDisabled.includes(o.value)).map((o) => o.value);
  model.value = next as T[];
}

// --- Layout -----------------------------------------------------------------
// `columns=1` is a deliberate VERTICAL stack (top→bottom cards, full width).
const isGrid = computed(() => (props.columns ?? 0) >= 1);

// --- Roles + tab stop -------------------------------------------------------
const groupRole = computed(() => (props.multiple ? 'group' : 'radiogroup'));
const optionRole = computed(() => (props.multiple ? 'checkbox' : 'radio'));

/** The single option that is a tab stop (roving tabindex). */
const tabStopValue = computed<T | null>(() => {
  if (props.multiple) {
    // First checked option, else the first enabled one.
    const firstChecked = props.options.find((o) => selectedArray.value.includes(o.value) && !o.disabled);
    return firstChecked?.value ?? firstEnabled.value;
  }
  return active.value ?? firstEnabled.value;
});

function optionTabIndex(value: T): number {
  if (props.disabled) return -1;
  const opt = props.options.find((o) => o.value === value);
  if (opt?.disabled) return -1;
  return value === tabStopValue.value ? 0 : -1;
}

// --- Styling ----------------------------------------------------------------
// Track: no surface of its own — the cards carry their borders. Just the layout gap.
const TRACK_GAP = 'gap-next-2';

// Card padding by size (sm = compact tiles, md = comfortable cards).
const CARD_PADDING: Record<SegmentedSize, string> = {
  sm: 'px-next-2 py-next-1_5',
  md: 'px-next-3 py-next-2_5',
};
// Card inner row gap + label text size by size.
const CARD_INNER: Record<SegmentedSize, string> = {
  sm: 'gap-next-2 text-next-xs',
  md: 'gap-next-2_5 text-next-sm',
};
// Indicator (radio/checkbox) box size by control size — mirrors Radio/Checkbox.
const INDICATOR_SIZE: Record<SegmentedSize, string> = {
  sm: 'h-4 w-4',
  md: 'h-5 w-5',
};
</script>

<template>
  <div
    :role="groupRole"
    :aria-label="ariaLabel"
    :aria-disabled="disabled ? 'true' : undefined"
    class="next-segmented"
    :class="[
      isGrid ? ['grid w-full', TRACK_GAP] : ['flex flex-wrap items-stretch', TRACK_GAP, equalWidth ? 'w-full' : ''],
      disabled ? 'opacity-60' : '',
    ]"
    :style="isGrid && columns ? { gridTemplateColumns: `repeat(${columns}, minmax(0, 1fr))` } : undefined"
    @keydown="onKeydown"
  >
    <!-- "Select all" (multi only): a leading tri-state card toggling every enabled
         option. A regular tab stop OUTSIDE the roving-arrow pattern; the dashed
         border keeps it visually distinct from the actual options. -->
    <button
      v-if="showSelectAll"
      type="button"
      role="checkbox"
      data-seg-select-all
      :aria-checked="selectAllChecked"
      :aria-disabled="disabled ? 'true' : undefined"
      :tabindex="disabled ? -1 : 0"
      :disabled="disabled"
      class="next-segmented__select-all flex items-start rounded-next-lg border border-dashed bg-next-card text-left outline-none transition-colors duration-[var(--duration-next-fast)] focus-visible:ring-2 focus-visible:ring-next-ring"
      :class="[
        CARD_PADDING[size],
        CARD_INNER[size],
        !isGrid ? [equalWidth ? 'flex-1' : '', 'min-w-0'] : 'min-w-0',
        disabled ? 'cursor-not-allowed' : 'cursor-pointer',
        allSelected
          ? 'border-next-primary bg-next-primary-subtle font-next-medium text-next-fg'
          : 'border-next-border text-next-fg hover:border-next-primary/50',
      ]"
      @click="toggleAll"
    >
      <span
        aria-hidden="true"
        class="mt-px flex shrink-0 items-center justify-center rounded-next-xs border transition-colors"
        :class="[
          INDICATOR_SIZE[size],
          selectAllChecked !== 'false'
            ? 'border-next-primary bg-next-primary text-next-primary-foreground'
            : 'border-next-input bg-next-card',
        ]"
      >
        <Icon v-if="selectAllChecked === 'true'" name="check" :stroke-width="3" class="text-next-xs" />
        <Icon v-else-if="selectAllChecked === 'mixed'" name="minus" :stroke-width="3" class="text-next-xs" />
      </span>
      <span class="min-w-0 truncate">{{ selectAllText }}</span>
    </button>

    <button
      v-for="opt in options"
      :key="opt.value"
      :ref="(el) => setOptionRef(el as HTMLButtonElement | null, opt.value)"
      type="button"
      :role="optionRole"
      :id="`${groupId}-${opt.value}`"
      :aria-checked="isSelected(opt.value)"
      :aria-label="iconOnly ? opt.label : undefined"
      :aria-disabled="opt.disabled || disabled ? 'true' : undefined"
      :tabindex="optionTabIndex(opt.value)"
      :disabled="opt.disabled || disabled"
      class="next-segmented__option flex items-start rounded-next-lg border bg-next-card text-left outline-none transition-colors duration-[var(--duration-next-fast)] focus-visible:ring-2 focus-visible:ring-next-ring"
      :class="[
        CARD_PADDING[size],
        CARD_INNER[size],
        !isGrid ? [equalWidth ? 'flex-1' : '', 'min-w-0'] : 'min-w-0',
        iconOnly ? 'items-center justify-center' : '',
        opt.disabled || disabled ? 'cursor-not-allowed' : 'cursor-pointer',
        isSelected(opt.value)
          ? 'border-next-primary bg-next-primary-subtle font-next-medium text-next-fg'
          : 'border-next-border text-next-fg hover:border-next-primary/50',
        opt.disabled ? 'opacity-50' : '',
      ]"
      @click="select(opt.value)"
      @focus="onOptionFocus(opt.value)"
    >
      <!-- Selectability indicator: radio dot (single) or checkbox (multiple).
           Decorative — the button role + aria-checked carry the semantics.
           Hidden entirely in icon-only mode (the card border/tint shows selection). -->
      <span
        v-if="!iconOnly"
        aria-hidden="true"
        class="mt-px flex shrink-0 items-center justify-center border transition-colors"
        :class="[
          INDICATOR_SIZE[size],
          multiple ? 'rounded-next-xs' : 'rounded-next-full',
          isSelected(opt.value)
            ? 'border-next-primary bg-next-primary text-next-primary-foreground'
            : 'border-next-input bg-next-card',
        ]"
      >
        <!-- multi: a check when selected; single: a filled dot when selected. -->
        <Icon
          v-if="multiple && isSelected(opt.value)"
          name="check"
          :stroke-width="3"
          class="text-next-xs"
        />
        <span
          v-else-if="!multiple && isSelected(opt.value)"
          class="rounded-next-full bg-next-primary-foreground"
          :class="size === 'sm' ? 'h-1.5 w-1.5' : 'h-2 w-2'"
        />
      </span>

      <Icon v-if="opt.icon" :name="opt.icon" class="shrink-0" :class="iconOnly ? '' : 'mt-px'" />

      <span v-if="!iconOnly" class="flex min-w-0 flex-col">
        <span class="truncate">{{ opt.label }}</span>
        <span
          v-if="opt.description"
          class="mt-next-0_5 text-next-xs font-next-normal text-next-muted-foreground"
        >
          {{ opt.description }}
        </span>
      </span>
    </button>
  </div>
</template>
