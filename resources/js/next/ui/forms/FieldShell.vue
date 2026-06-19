<script setup lang="ts">
// FieldShell — the bordered "box" that every text-like "next" control renders
// inside. It owns three things and nothing else:
//
//  1. the BORDER + background surface (rounded, token-driven),
//  2. the STATE LINE — a colored line drawn ON the border itself (offset 0). It
//     is an INSET ring (`box-shadow: inset 0 0 0 1px`) layered over the border so
//     it sits exactly where the border is, never an outward `outline-offset` ring.
//     focus = primary/ring color, error = danger, success = success, dirty = a
//     subtler primary accent. error+focus share the SAME shape, different color.
//  3. ADORNMENTS — `#leading` / `#trailing` slots rendered INSIDE the border,
//     vertically centered, that never overlap the control's text.
//
// The default slot holds ONE or SEVERAL bare controls laid out in a row sharing a
// single border + a single state line (e.g. a split field, divided with thin
// rules). Content NEVER changes the shell's width or height: the height is fixed
// per size; overflowing text in a control truncates with an ellipsis.
//
// State precedence: disabled > error > success > focus > dirty > default.
// `readonly` is orthogonal (only changes the fill/cursor). Focus is detected via
// `:focus-within` so the inner focusable element stays the focus target and we
// avoid a double ring.
import { computed } from 'vue';
import {
  FIELD_SIZE,
  resolveFieldState,
  type ControlSize,
  type FieldState,
} from './fieldShell';

const props = withDefaults(
  defineProps<{
    size?: ControlSize;
    /** Force/clear focus styling. When omitted, `:focus-within` drives it (CSS). */
    focused?: boolean;
    disabled?: boolean;
    readonly?: boolean;
    error?: boolean;
    success?: boolean;
    /** Subtle "changed, not yet validated" accent. */
    dirty?: boolean;
    /**
     * Lay the default slot out as evenly-divided segments with thin internal
     * rules between them (split field). Otherwise it's a single flex row.
     */
    segmented?: boolean;
  }>(),
  {
    size: 'md',
    disabled: false,
    readonly: false,
    error: false,
    success: false,
    dirty: false,
    segmented: false,
  },
);

// Resolve the single colored state (precedence handled centrally). When `focused`
// is not passed we let CSS `:focus-within` add the focus line on top of the
// non-focus resolved state, so keyboard focus always wins visually without JS.
const state = computed<FieldState>(() =>
  resolveFieldState({
    disabled: props.disabled,
    error: props.error,
    success: props.success,
    focused: props.focused ?? false,
    dirty: props.dirty,
  }),
);

// Map the resolved state -> the line color token used by both the border-color
// and the inset ring. `default`/`dirty` keep the neutral input border; the inset
// ring only paints for the signalling states.
const lineVar = computed(() => {
  switch (state.value) {
    case 'error':
      return 'var(--color-next-danger)';
    case 'success':
      return 'var(--color-next-success)';
    case 'focus':
      return 'var(--color-next-ring)';
    case 'dirty':
      return 'var(--color-next-primary)';
    default:
      return 'var(--color-next-input)';
  }
});

// Whether a 1px inset ring should be painted (the "state line"). For default the
// plain border is enough; dirty gets a subtle accent via border-color only (no
// ring) so it reads as quieter than a validated/focused field.
const showRing = computed(
  () =>
    state.value === 'error' ||
    state.value === 'success' ||
    state.value === 'focus',
);

const surfaceClass = computed(() => {
  if (props.disabled) return 'bg-next-muted cursor-not-allowed';
  if (props.readonly) return 'bg-next-muted/50';
  return 'bg-next-card';
});

// Hover deepens the neutral border, but only when no state line owns the color.
const allowHover = computed(
  () =>
    !props.disabled &&
    !props.readonly &&
    !showRing.value &&
    state.value !== 'dirty',
);
</script>

<template>
  <div
    class="next-field-shell"
    :class="[
      FIELD_SIZE[size],
      surfaceClass,
      segmented ? 'next-field-shell--segmented' : '',
      disabled ? 'is-disabled' : '',
      readonly ? 'is-readonly' : '',
      allowHover ? 'allow-hover' : '',
      showRing ? 'has-ring' : '',
      // The non-focus state still maps to a focus-within line in CSS below.
      `state-${state}`,
    ]"
    :style="{ '--field-line-rest': lineVar }"
  >
    <!-- Leading adornment(s): icons, units, buttons — inside the border. -->
    <span
      v-if="$slots.leading"
      class="next-field-shell__adornment next-field-shell__adornment--leading"
    >
      <slot name="leading" />
    </span>

    <!-- Control region: one or several bare controls sharing one border/line. -->
    <div class="next-field-shell__body">
      <slot />
    </div>

    <!-- Trailing adornment(s): clear, password toggle, spinner, stepper, unit. -->
    <span
      v-if="$slots.trailing"
      class="next-field-shell__adornment next-field-shell__adornment--trailing"
    >
      <slot name="trailing" />
    </span>
  </div>
</template>

<style scoped>
/* The box: rounded surface + 1px border whose color is the state line var. The
   "state line" for the signalling states is an INSET ring drawn at offset 0 so it
   coincides exactly with the border. We deliberately avoid `outline` /
   `outline-offset` so nothing sits outside the border. */
.next-field-shell {
  position: relative;
  display: flex;
  align-items: stretch;
  width: 100%;
  border-radius: var(--radius-next-md);
  /* `--field-line` is the ACTIVE line color. It defaults to the resting color the
     component sets inline as `--field-line-rest` (neutral / dirty / error /
     success / forced-focus). We intentionally do NOT set `--field-line` inline:
     an inline custom property would beat the `:focus-within` rule below in the
     cascade, freezing the line at the resting color (the previous bug — the
     box-shadow ring painted the focus color, but `--field-line` stayed neutral so
     `border-color` and the segmented dividers, which read `--field-line`, never
     followed). Keeping `--field-line` stylesheet-resolved lets focus override it. */
  --field-line: var(--field-line-rest, var(--color-next-input));
  border: 1px solid var(--field-line);
  color: var(--color-next-fg);
  transition:
    border-color var(--duration-next-fast) var(--ease-next-standard),
    box-shadow var(--duration-next-fast) var(--ease-next-standard);
}

/* The inset ring = the visible "state line", exactly on the border (offset 0).
   1.5px so it reads clearly while staying on the border line, in the same color
   as the border. error+focus+success all share this shape — only --field-line
   differs. */
.next-field-shell.has-ring {
  box-shadow: inset 0 0 0 1.5px var(--field-line);
}

/* Keyboard/active focus is detected here so the inner focusable element is the
   focus target (no double ring) and focus visually wins over default/dirty.
   error/success keep their own color even while focused (their border-color +
   ring already point at danger/success and we DON'T override --field-line). */
.next-field-shell:not(.is-disabled):not(.is-readonly):not(.state-error):not(
    .state-success
  ):focus-within {
  --field-line: var(--color-next-ring);
  box-shadow: inset 0 0 0 1.5px var(--color-next-ring);
}

.next-field-shell.is-disabled {
  opacity: 0.6;
}

/* Hover deepens the neutral border when no signalling/focus line owns the color. */
.next-field-shell.allow-hover:hover {
  --field-line: color-mix(in srgb, var(--color-next-fg) 30%, transparent);
}

/* Adornments: vertically centered, do not shrink, separated from the text via
   the control's own padding; sit inside the rounded border. */
.next-field-shell__adornment {
  display: inline-flex;
  align-items: center;
  flex-shrink: 0;
  color: var(--color-next-muted-foreground);
}
.next-field-shell__adornment--leading {
  padding-inline-start: var(--spacing-next-3);
}
.next-field-shell__adornment--trailing {
  padding-inline-end: var(--spacing-next-2);
}

/* The control region. Min-width:0 lets a flex child truncate instead of forcing
   the shell wider; overflow hidden clips the rounded corners of inner segments. */
.next-field-shell__body {
  display: flex;
  align-items: stretch;
  flex: 1 1 auto;
  min-width: 0;
  overflow: hidden;
  border-radius: inherit;
}

/* Segmented (split-field) layout: evenly weight each direct child of the body and
   draw a thin internal rule between segments. Each control sets its own padding.
   The internal dividers follow `--field-divider`. At rest it is the quiet neutral
   border; for the signalling states AND on focus-within it adopts `--field-line`,
   which now (see above) correctly resolves to the ring/danger/success/dirty color
   because `--field-line` is stylesheet-resolved rather than pinned inline. So the
   dividers track the outer state line exactly: default → neutral, dirty → primary,
   focus → ring, error → danger, success → success. */
.next-field-shell--segmented {
  --field-divider: var(--color-next-border);
  /* The divider WIDTH tracks the SAME state logic as the color (Issue 1a): at
     rest it is a quiet 1px neutral rule; whenever a state line is painted (the
     1.5px inset ring) — error/success/dirty/focus — the divider grows to 1.5px in
     the state color so it visually matches the outer border thickness in state. */
  --field-divider-width: 1px;
}
/* When a signalling state line is painted, or on focus-within, the dividers
   adopt the same `--field-line` color AND thickness as the outer border/state
   line (1.5px, matching the inset ring). */
.next-field-shell--segmented.has-ring,
.next-field-shell--segmented.state-dirty,
.next-field-shell--segmented:not(.is-disabled):not(.is-readonly):focus-within {
  --field-divider: var(--field-line);
  --field-divider-width: 1.5px;
}
.next-field-shell--segmented .next-field-shell__body :deep(> *) {
  flex: 1 1 0%;
  min-width: 0;
  /* Issue 1b — flush rectangular segment edges: a rounded inner control would
     leave the divider looking bent at the shared edge. The outer rounded corners
     stay on the shell (the body clips to `border-radius: inherit`). */
  border-radius: 0;
}
.next-field-shell--segmented .next-field-shell__body :deep(> * + *) {
  border-inline-start: var(--field-divider-width) solid var(--field-divider);
  transition:
    border-color var(--duration-next-fast) var(--ease-next-standard),
    border-width var(--duration-next-fast) var(--ease-next-standard);
}

/* Issue 1b — crooked dividers: the global `:focus-visible` rule
   (.next-root …:focus-visible) paints a 2px OUTLINE + adds `border-radius` to ANY
   focused control. On a bare control INSIDE a segment that outline/radius bleeds
   over the shared segment edge and makes the divider look bent. The shell already
   signals focus via its state line + the divider color/width above, so we suppress
   the inner control's own focus-visible outline/radius (the segment, and anything
   focusable nested in it) so segment boundaries stay flush rectangles. */
.next-field-shell--segmented .next-field-shell__body :deep(> *:focus-visible),
.next-field-shell--segmented .next-field-shell__body :deep(> * :focus-visible) {
  outline: none;
  border-radius: 0;
}
</style>
