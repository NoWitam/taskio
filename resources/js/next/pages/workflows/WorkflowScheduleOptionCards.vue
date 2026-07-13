<script setup lang="ts" generic="T extends string = string">
// WorkflowScheduleOptionCards — the REV5 radio-group of self-configuring selection
// cards (§4.5.5). It REPLACES the per-tab `SegmentedControl` + below-controls pattern:
// each axis panel renders its sub-modes through this component, where the SELECTED card
// EXPANDS to reveal that sub-mode's inputs woven into a natural-language sentence.
//
// Anatomy (decision B) — each card splits into TWO parts inside ONE bordered container
// (visual continuity via the shared border + tint):
//   • a RADIO HEADER — a `button role="radio"` with `aria-checked` + a radio-dot + the
//     option TITLE. An UNSELECTED card shows the title ONLY (compact vertical list).
//   • an OPTIONAL BODY — a `role="group"` region that is a SIBLING of the header
//     (NEVER a child of the `role="radio"` button — a radio must not wrap interactive
//     controls). Rendered ONLY for the SELECTED card AND only when a `#body-<value>`
//     slot is provided; it holds the in-sentence inputs. Modes with no inputs render no
//     body. Because only the selected card has a body, the tab path is unambiguous:
//     Tab from the selected header lands on its first body input (pure DOM order).
//
// A11y (§4.5.13): the headers form ONE `role=radiogroup` — roving tabindex (only the
// selected header is a tab stop), arrow keys (↑/↓ AND ←/→) MOVE + SELECT (skipping
// disabled, wrapping), Home/End jump, Space/Enter (re)select. Disabled headers keep
// `aria-disabled` (+ native `disabled` so they drop out of the tab order) and are
// skipped by arrows; their EXPLANATION lives with the consumer (never a bare gray-out).
//
// It owns the card chrome + the radio a11y + the focus model — NOT axis logic (the
// panels keep their mutation logic and fill the per-option `#body-<value>` slot).
import { computed, nextTick, ref, useSlots } from 'vue';

export interface OptionCard<V extends string = string> {
  value: V;
  /** The option title — the header text (also the body region's aria-label). */
  title: string;
  disabled?: boolean;
}

const props = withDefaults(
  defineProps<{
    options: OptionCard<T>[];
    /** Accessible label for the whole radiogroup (the axis name). */
    ariaLabel?: string;
  }>(),
  {},
);

// Single-select: the model is the current sub-mode value (an axis always has a mode).
const model = defineModel<T>({ required: true });
const slots = useSlots();

const enabled = computed(() => props.options.filter((o) => !o.disabled));
const firstEnabled = computed<T | null>(() => enabled.value[0]?.value ?? null);

/** The active value, falling back to the first enabled option when unset/disabled. */
const active = computed<T | null>(() => {
  const v = model.value;
  if (v != null && props.options.some((o) => o.value === v && !o.disabled)) return v;
  return firstEnabled.value;
});

function isSelected(value: T): boolean {
  return value === active.value;
}

/** Whether the SELECTED card should render a body (a `#body-<value>` slot exists). */
function hasBody(value: T): boolean {
  return !!slots[`body-${value}`];
}

function select(value: T): void {
  const opt = props.options.find((o) => o.value === value);
  if (!opt || opt.disabled) return;
  if (value !== model.value) model.value = value;
}

// --- Refs + roving focus -----------------------------------------------------
const headerRefs = ref<Record<string, HTMLButtonElement | null>>({});
function setHeaderRef(el: HTMLButtonElement | null, value: string): void {
  headerRefs.value[value] = el;
}
function focusHeader(value: T): void {
  headerRefs.value[value]?.focus();
}

/** The next ENABLED option in `dir`, wrapping (disabled options are skipped). */
function neighbour(from: T | null, dir: 1 | -1): T | null {
  const list = enabled.value;
  if (list.length === 0) return null;
  const idx = from == null ? -1 : list.findIndex((o) => o.value === from);
  const start = idx === -1 ? 0 : idx;
  const next = (start + dir + list.length) % list.length;
  return list[next].value;
}

/** Arrows MOVE the selection (radio pattern) + focus the newly selected header. */
function move(dir: 1 | -1): void {
  const target = neighbour(active.value, dir);
  if (target == null) return;
  select(target);
  void nextTick(() => focusHeader(target));
}

function jump(edge: 'first' | 'last'): void {
  const list = enabled.value;
  const target = edge === 'first' ? list[0]?.value : list[list.length - 1]?.value;
  if (target == null) return;
  select(target);
  void nextTick(() => focusHeader(target));
}

function onKeydown(event: KeyboardEvent): void {
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
      jump('first');
      break;
    case 'End':
      event.preventDefault();
      jump('last');
      break;
    case ' ':
    case 'Enter':
      event.preventDefault();
      if (active.value != null) select(active.value);
      break;
  }
}

// --- Roving tabindex: only the active header is a tab stop -------------------
function headerTabIndex(opt: OptionCard<T>): number {
  if (opt.disabled) return -1;
  return opt.value === active.value ? 0 : -1;
}
</script>

<template>
  <div :role="'radiogroup'" :aria-label="ariaLabel" class="flex flex-col gap-next-2" @keydown="onKeydown">
    <div
      v-for="opt in options"
      :key="opt.value"
      class="rounded-next-lg border transition-colors duration-[var(--duration-next-fast)]"
      :class="[
        isSelected(opt.value)
          ? 'border-next-primary bg-next-primary-subtle'
          : 'border-next-border bg-next-card',
        opt.disabled ? 'opacity-60' : '',
      ]"
    >
      <!-- Radio header — the ONLY interactive part of the card (a radio must not wrap
           interactive controls; the body is a sibling below). -->
      <button
        :ref="(el) => setHeaderRef(el as HTMLButtonElement | null, opt.value)"
        type="button"
        role="radio"
        :aria-checked="isSelected(opt.value)"
        :aria-disabled="opt.disabled ? 'true' : undefined"
        :disabled="opt.disabled"
        :tabindex="headerTabIndex(opt)"
        class="flex w-full items-center gap-next-2 rounded-next-lg px-next-3 py-next-2_5 text-left text-next-sm outline-none focus-visible:ring-2 focus-visible:ring-next-ring"
        :class="[
          opt.disabled ? 'cursor-not-allowed' : 'cursor-pointer',
          isSelected(opt.value) ? 'font-next-medium text-next-fg' : 'text-next-fg',
        ]"
        @click="select(opt.value)"
      >
        <!-- Radio-dot indicator (decorative — the role + aria-checked carry the state). -->
        <span
          aria-hidden="true"
          class="flex h-4 w-4 shrink-0 items-center justify-center rounded-next-full border transition-colors"
          :class="
            isSelected(opt.value)
              ? 'border-next-primary bg-next-primary text-next-primary-foreground'
              : 'border-next-input bg-next-card'
          "
        >
          <span v-if="isSelected(opt.value)" class="h-1.5 w-1.5 rounded-next-full bg-next-primary-foreground" />
        </span>
        <span class="min-w-0">{{ opt.title }}</span>
      </button>

      <!-- Expanding body — SIBLING of the header, only for the SELECTED card that has a
           `#body-<value>` slot. The in-sentence inputs live here (§4.5.5). -->
      <div
        v-if="isSelected(opt.value) && hasBody(opt.value)"
        role="group"
        :aria-label="opt.title"
        class="px-next-3 pb-next-3 pt-next-1"
      >
        <slot :name="`body-${opt.value}`" />
      </div>
    </div>
  </div>
</template>
