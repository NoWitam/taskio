<script setup lang="ts">
// PillGroupInput — a tag / token input for the "next" frontend.
//
// Type + Enter (and, optionally, comma) to add a pill; Backspace removes the
// last pill when the text field is empty; each pill is removable by mouse and
// keyboard.
//
// Like Textarea, it does NOT render through FieldShell (whose height is FIXED
// per size and would clip a multi-row pill area). Instead it MIRRORS FieldShell's
// exact visual language — the same bordered surface and the same "state line" (an
// inset ring ON the border at offset 0) for focus / error / success / dirty,
// driven by the same `--field-line` var + precedence. WIDTH stays fixed.
//
// Model = `string[]` (the tags). Dedupe + optional `validate` predicate + an
// optional `max` count are enforced on add. With `allowed` / `suggestions` an
// autocomplete dropdown (FieldPopover) offers matching values to pick.
//
// OVERFLOW MODES (no-grow):
//   • 'scroll' (default): the pill wrap area is given a fixed MAX-HEIGHT derived
//     from `maxRows` and scrolls VERTICALLY inside when the pills overflow, so the
//     control's height is bounded while still letting the user scroll all tags.
//     The inner scroll area is INSET from the border so scrolled pills never bleed
//     over the state line, and the colored ring is drawn on an overlay above the
//     content so it stays fully visible around the whole perimeter (Issue 4).
//   • 'collapse' (or `:collapse`): the control keeps a SINGLE fixed-height row at
//     the standard FieldShell height for its size. Pills that don't fit are hidden
//     and replaced by a single "+N" pill. Hovering/focusing "+N" shows the hidden
//     values; clicking/Enter opens an interactive panel to REMOVE them. Fit is
//     measured with a ResizeObserver and recomputed on resize / value changes.
import {
  computed,
  nextTick,
  onBeforeUnmount,
  onMounted,
  ref,
  watch,
} from 'vue';
import Icon from '../primitives/Icon.vue';
import Badge from '../primitives/Badge.vue';
import FieldPopover from './FieldPopover.vue';
import ChipOverflow from './ChipOverflow.vue';
import { useFormField, nextId } from './formField';
import { useChipOverflow } from '../../app/composables/useChipOverflow';
import { resolveFieldState, type ControlSize } from './fieldShell';
import { useI18n } from '../../app/i18n';

const { t } = useI18n();

const props = withDefaults(
  defineProps<{
    size?: ControlSize;
    placeholder?: string;
    disabled?: boolean;
    readonly?: boolean;
    /** Also commit a pill when the user types a comma. */
    addOnComma?: boolean;
    /** Max number of pills. Adding is blocked at the cap. */
    max?: number;
    /** Predicate: return false (or a string reason) to reject a value. */
    validate?: (value: string) => boolean | string;
    /** Restrict additions to this allow-list (also feeds suggestions). */
    allowed?: string[];
    /** Suggestion pool for the autocomplete dropdown (superset of `allowed`). */
    suggestions?: string[];
    /**
     * Overflow behaviour. `'scroll'` (default) caps the height to `maxRows` and
     * scrolls; `'collapse'` keeps a single fixed row and hides overflow behind a
     * `+N` pill. `:collapse` is shorthand for `overflow="collapse"`.
     */
    overflow?: 'scroll' | 'collapse';
    /** Shorthand for `overflow="collapse"`. */
    collapse?: boolean;
    /**
     * Visible rows of pills before the area scrolls (scroll mode only). Caps the
     * control height so it never grows unbounded. 1 = single-row scroll.
     */
    maxRows?: number;
    /** Trim + lowercase added values for normalization. */
    transform?: 'none' | 'lower' | 'trim';
    /** Standalone aria-invalid (FormField provides this otherwise). */
    ariaInvalid?: boolean;
    /** Standalone success styling (FormField provides this otherwise). */
    success?: boolean;
    /** Standalone dirty styling (FormField tracks this otherwise). */
    dirty?: boolean;
    id?: string;
    describedById?: string;
    ariaLabel?: string;
  }>(),
  {
    size: 'md',
    disabled: false,
    readonly: false,
    addOnComma: true,
    collapse: false,
    maxRows: 3,
    transform: 'trim',
    success: false,
    dirty: false,
  },
);

const emit = defineEmits<{
  (e: 'add', value: string): void;
  (e: 'remove', value: string): void;
  /** A value was rejected (with the reason). */
  (e: 'invalid', value: string, reason: string): void;
}>();

const model = defineModel<string[]>({ default: () => [] });

const field = useFormField();
const generatedId = nextId('next-pills');
const resolvedId = computed(() => props.id ?? field?.id.value ?? generatedId);
const listboxId = computed(() => `${resolvedId.value}-suggestions`);
const panelId = computed(() => `${resolvedId.value}-panel`);
const overflowPanelId = computed(() => `${resolvedId.value}-overflow`);
const resolvedDescribedBy = computed(
  () => props.describedById ?? field?.describedById.value,
);
const invalid = computed(() => props.ariaInvalid ?? field?.invalid.value ?? false);
const success = computed(() => props.success || (field?.valid.value ?? false));
const disabled = computed(() => props.disabled || (field?.disabled.value ?? false));
const readonly = computed(() => props.readonly || (field?.readonly.value ?? false));
const required = computed(() => field?.required.value ?? false);
const dirty = computed(() => props.dirty || (field?.dirty.value ?? false));

if (field?.registerValue) {
  const dispose = field.registerValue(() => model.value);
  onBeforeUnmount(dispose);
}

const pills = computed<string[]>(() => model.value ?? []);
const inert = computed(() => disabled.value || readonly.value);
const atMax = computed(() => props.max != null && pills.value.length >= props.max);

// Resolved overflow mode (explicit `overflow` wins, else the `collapse` flag).
const isCollapse = computed(
  () => props.overflow === 'collapse' || (props.overflow == null && props.collapse),
);

const draft = ref('');
const inputRef = ref<HTMLInputElement | null>(null);

// --- Normalization / validation -------------------------------------------
function normalize(raw: string): string {
  const trimmed = raw.trim();
  if (props.transform === 'lower') return trimmed.toLowerCase();
  // 'none' and 'trim' both end up trimmed (a leading/trailing-space tag is never
  // useful); 'none' simply skips the lowercase.
  return trimmed;
}

function rejectReason(value: string): string | null {
  if (!value) return 'empty';
  if (pills.value.includes(value)) return 'duplicate';
  if (atMax.value) return `max ${props.max}`;
  if (props.allowed && !props.allowed.includes(value)) return 'not allowed';
  if (props.validate) {
    const result = props.validate(value);
    if (result === false) return 'invalid';
    if (typeof result === 'string') return result;
  }
  return null;
}

function add(raw: string): boolean {
  const value = normalize(raw);
  const reason = rejectReason(value);
  if (reason) {
    if (value && reason !== 'duplicate') emit('invalid', value, reason);
    return false;
  }
  model.value = [...pills.value, value];
  emit('add', value);
  return true;
}

function remove(value: string): void {
  if (inert.value) return;
  model.value = pills.value.filter((p) => p !== value);
  emit('remove', value);
}

function commitDraft(): void {
  if (!draft.value.trim()) return;
  if (add(draft.value)) draft.value = '';
}

// --- Suggestions (autocomplete) -------------------------------------------
const open = ref(false);
const pool = computed<string[]>(() => props.suggestions ?? props.allowed ?? []);
const activeSuggestion = ref(-1);
const suggestions = computed<string[]>(() => {
  if (!pool.value.length) return [];
  const q = draft.value.trim().toLowerCase();
  return pool.value
    .filter((s) => !pills.value.includes(s))
    .filter((s) => !q || s.toLowerCase().includes(q))
    .slice(0, 8);
});
const hasSuggestions = computed(() => pool.value.length > 0);

watch(draft, () => {
  if (hasSuggestions.value) {
    open.value = !!suggestions.value.length;
    activeSuggestion.value = suggestions.value.length ? 0 : -1;
  }
});

function pickSuggestion(value: string): void {
  if (add(value)) {
    draft.value = '';
    open.value = false;
    nextTick(() => inputRef.value?.focus());
  }
}

// --- Keyboard --------------------------------------------------------------
// DELIBERATELY NOT in `useOverlayStack`: this is bound to the text input, so Escape only arrives
// while the input itself has DOM focus — and the suggestion list it closes is an inline dropdown of
// this very input, never a sibling of another overlay competing for the same press.
function onKeydown(e: KeyboardEvent): void {
  if (inert.value) return;
  switch (e.key) {
    case 'Enter':
      e.preventDefault();
      if (open.value && activeSuggestion.value >= 0 && suggestions.value[activeSuggestion.value]) {
        pickSuggestion(suggestions.value[activeSuggestion.value]);
      } else {
        commitDraft();
      }
      break;
    case ',':
      if (props.addOnComma) {
        e.preventDefault();
        commitDraft();
      }
      break;
    case 'Backspace':
      if (!draft.value && pills.value.length) {
        e.preventDefault();
        remove(pills.value[pills.value.length - 1]);
      }
      break;
    case 'ArrowDown':
      if (suggestions.value.length) {
        e.preventDefault();
        open.value = true;
        activeSuggestion.value = Math.min(
          suggestions.value.length - 1,
          activeSuggestion.value + 1,
        );
      }
      break;
    case 'ArrowUp':
      if (suggestions.value.length) {
        e.preventDefault();
        activeSuggestion.value = Math.max(0, activeSuggestion.value - 1);
      }
      break;
    case 'Escape':
      if (open.value) {
        e.preventDefault();
        e.stopPropagation();
        open.value = false;
      }
      break;
    default:
      break;
  }
}

function onPaste(e: ClipboardEvent): void {
  if (inert.value) return;
  const text = e.clipboardData?.getData('text') ?? '';
  if (!text.includes(',') && !text.includes('\n')) return;
  e.preventDefault();
  text
    .split(/[,\n]/)
    .map((t) => t.trim())
    .filter(Boolean)
    .forEach((t) => add(t));
}

function onBlur(): void {
  // Commit a pending draft on blur (common tag-input affordance).
  commitDraft();
}

function focusInput(): void {
  if (!inert.value) inputRef.value?.focus();
}

// No-grow: cap the pill area height to maxRows × an approximate row height. Each
// pill row is ~24px (Badge sm) + gap; we use 1.75rem per row + gaps.
const maxAreaHeight = computed(() => {
  const rowRem = 1.75;
  const gapRem = 0.375;
  return `${props.maxRows * rowRem + (props.maxRows - 1) * gapRem}rem`;
});
// Min/fixed height so an EMPTY field still matches the size scale (sm/md/lg).
const MIN_HEIGHT: Record<ControlSize, string> = {
  sm: '2rem', // h-8
  md: '2.5rem', // h-10
  lg: '3rem', // h-12
};
const minAreaHeight = computed(() => MIN_HEIGHT[props.size]);
const textSizeClass = computed(() =>
  props.size === 'lg' ? 'text-next-base' : 'text-next-sm',
);

// --- Collapse-mode fit measurement (shared composable) ---------------------
// The chip-overflow mechanics are SHARED with Select (multiple mode) via
// `useChipOverflow`: render ALL pills in a hidden measuring row, read widths, then
// fit as many WHOLE pills as physically fit into the track (reserving room for the
// text input + the "+N" pill), collapsing the rest. A ResizeObserver inside the
// composable recomputes on resize; we call `recompute()` on value / mode changes.
const rowRef = ref<HTMLElement | null>(null);
const measureRef = ref<HTMLElement | null>(null);

const { visibleCount, hiddenCount, recompute: recomputeFit } = useChipOverflow({
  trackRef: rowRef,
  measureRef,
  total: () => (isCollapse.value ? pills.value.length : 0),
  // Reserve room for the always-present text input that shares the row.
  reserved: () => 60,
  itemSelector: '[data-measure-chip]',
});

const visiblePills = computed(() =>
  isCollapse.value ? pills.value.slice(0, visibleCount.value) : pills.value,
);
const hiddenPills = computed(() =>
  isCollapse.value ? pills.value.slice(visibleCount.value) : [],
);

onMounted(() => {
  // Reset any stray horizontal scroll the browser may apply on focus (collapse
  // mode must never scroll horizontally — see resetRowScroll).
  resetRowScroll();
});
// Recompute whenever the pill set or the mode changes.
watch(
  () => [pills.value, isCollapse.value] as const,
  () => {
    recomputeFit();
    nextTick(resetRowScroll);
  },
  { deep: true },
);

// --- Collapse: no horizontal scroll ----------------------------------------
// Focusing the input (or a child) can make the browser scroll the clipped row
// horizontally, cutting off the first chip's left edge. We force scrollLeft back
// to 0 so the first chip is always fully visible; overflow is handled purely by
// the "+N" collapse, never by scrolling.
function resetRowScroll(): void {
  if (rowRef.value && rowRef.value.scrollLeft !== 0) rowRef.value.scrollLeft = 0;
}

// --- Overflow "+N" affordance (shared ChipOverflow component) ----------------
// The +N pill's tooltip + teleported interactive remove panel are SHARED with
// Select via <ChipOverflow> (one implementation, no duplication). It owns its own
// open/anchor/focus/Esc/outside-click mechanics; we just feed it the hidden
// labels and re-measure when it removes one.
function removeHidden(value: string): void {
  remove(value);
  nextTick(recomputeFit);
}

// --- FieldShell-mirrored state line ---------------------------------------
// Same resolution as FieldShell; we don't pass `focused` (CSS :focus-within
// drives the focus line). error/success keep their color even when focused.
const state = computed(() =>
  resolveFieldState({
    disabled: disabled.value,
    error: invalid.value,
    success: success.value,
    focused: false,
    dirty: dirty.value,
  }),
);
const lineVar = computed(() => {
  switch (state.value) {
    case 'error':
      return 'var(--color-next-danger)';
    case 'success':
      return 'var(--color-next-success)';
    case 'dirty':
      return 'var(--color-next-primary)';
    default:
      return 'var(--color-next-input)';
  }
});
const showRing = computed(
  () => state.value === 'error' || state.value === 'success',
);
</script>

<template>
  <FieldPopover
    v-model:open="open"
    :disabled="inert || !hasSuggestions"
    :panel-id="panelId"
    :aria-label="t('pillGroup.suggestionsLabel', 'Tag suggestions')"
  >
    <template #trigger>
      <!-- FieldShell-mirrored surface (height-flexible/capped or single fixed row).
           The colored state line is drawn as an OVERLAY pseudo-element ABOVE the
           scrolling content (see <style>), so scrolled pills can never bleed over
           the border and the ring stays fully visible around the whole perimeter,
           scrollbar included. The whole area is clickable to focus the input. -->
      <div
        class="next-pillgroup"
        :class="[
          textSizeClass,
          disabled ? 'is-disabled bg-next-muted cursor-not-allowed' : readonly ? 'is-readonly bg-next-muted/50' : 'bg-next-card',
          !disabled && !readonly && !showRing && state !== 'dirty' ? 'allow-hover' : '',
          showRing ? 'has-ring' : '',
          isCollapse ? 'is-collapse' : '',
          `state-${state}`,
        ]"
        :style="{ '--field-line-rest': lineVar }"
      >
        <!-- COLLAPSE: a single fixed-height row. It CLIPS (overflow:hidden) and
             must NEVER scroll horizontally — `resetRowScroll` snaps scrollLeft back
             to 0 if focus nudges it, so the first chip's left edge is always fully
             visible. Overflow is handled purely by the "+N" collapse. -->
        <div
          v-if="isCollapse"
          ref="rowRef"
          class="next-pillgroup__track next-pillgroup__track--collapse flex w-full items-center gap-next-1_5 overflow-hidden"
          :style="{ height: minAreaHeight }"
          @click="focusInput"
          @scroll="resetRowScroll"
        >
          <Badge
            v-for="pill in visiblePills"
            :key="pill"
            variant="neutral"
            size="sm"
            truncate
            :removable="!inert"
            :remove-label="t('pillGroup.remove', 'Remove {label}', { label: pill })"
            @remove="remove(pill)"
          >
            {{ pill }}
          </Badge>

          <!-- +N overflow pill (shared with Select): hover → tooltip of hidden
               values; click/Enter → teleported interactive remove panel. -->
          <ChipOverflow
            v-if="hiddenCount > 0"
            :items="hiddenPills"
            :count="hiddenCount"
            :removable="!inert"
            :panel-id="overflowPanelId"
            @remove="removeHidden"
          />

          <input
            :id="resolvedId"
            ref="inputRef"
            v-model="draft"
            type="text"
            role="combobox"
            class="h-6 min-w-[4ch] flex-1 border-0 bg-transparent text-current outline-none placeholder:text-next-muted-foreground disabled:cursor-not-allowed"
            :placeholder="pills.length ? '' : (placeholder ?? t('pillGroup.placeholder', 'Add a tag…'))"
            :disabled="inert"
            :readonly="readonly"
            :aria-expanded="open"
            :aria-controls="hasSuggestions ? listboxId : undefined"
            aria-haspopup="listbox"
            :aria-activedescendant="
              open && activeSuggestion >= 0 ? `${listboxId}-opt-${activeSuggestion}` : undefined
            "
            :aria-invalid="invalid ? 'true' : undefined"
            :aria-describedby="resolvedDescribedBy"
            :aria-required="required ? 'true' : undefined"
            :aria-label="ariaLabel ?? t('pillGroup.inputLabel', 'Add tags')"
            autocomplete="off"
            @keydown="onKeydown"
            @paste="onPaste"
            @blur="onBlur"
          />
        </div>

        <!-- SCROLL: row-wrapping, height-capped, scrollable region (default). -->
        <div
          v-else
          class="next-pillgroup__track flex w-full flex-wrap content-start items-center gap-next-1_5 overflow-y-auto"
          :style="{ maxHeight: maxAreaHeight, minHeight: minAreaHeight }"
          @click="focusInput"
        >
          <Badge
            v-for="pill in pills"
            :key="pill"
            variant="neutral"
            size="sm"
            truncate
            :removable="!inert"
            :remove-label="t('pillGroup.remove', 'Remove {label}', { label: pill })"
            @remove="remove(pill)"
          >
            {{ pill }}
          </Badge>

          <input
            :id="resolvedId"
            ref="inputRef"
            v-model="draft"
            type="text"
            role="combobox"
            class="h-6 min-w-[6ch] flex-1 border-0 bg-transparent text-current outline-none placeholder:text-next-muted-foreground disabled:cursor-not-allowed"
            :placeholder="pills.length ? '' : (placeholder ?? t('pillGroup.placeholder', 'Add a tag…'))"
            :disabled="inert"
            :readonly="readonly"
            :aria-expanded="open"
            :aria-controls="hasSuggestions ? listboxId : undefined"
            aria-haspopup="listbox"
            :aria-activedescendant="
              open && activeSuggestion >= 0 ? `${listboxId}-opt-${activeSuggestion}` : undefined
            "
            :aria-invalid="invalid ? 'true' : undefined"
            :aria-describedby="resolvedDescribedBy"
            :aria-required="required ? 'true' : undefined"
            :aria-label="ariaLabel ?? t('pillGroup.inputLabel', 'Add tags')"
            autocomplete="off"
            @keydown="onKeydown"
            @paste="onPaste"
            @blur="onBlur"
          />
        </div>

        <!-- Hidden measuring row (collapse mode): every pill at natural width so
             we can compute how many fit. Aria-hidden + off-flow; never visible. -->
        <div
          v-if="isCollapse"
          ref="measureRef"
          class="next-pillgroup__measure flex items-center gap-next-1_5"
          aria-hidden="true"
        >
          <Badge
            v-for="pill in pills"
            :key="pill"
            data-measure-chip
            variant="neutral"
            size="sm"
            truncate
          >
            {{ pill }}
          </Badge>
        </div>

      </div>
    </template>

    <!-- Autocomplete suggestion list. -->
    <template #default>
      <ul
        v-if="suggestions.length"
        :id="listboxId"
        role="listbox"
        class="max-h-56 min-w-[12rem] overflow-y-auto py-next-1"
      >
        <li
          v-for="(s, i) in suggestions"
          :id="`${listboxId}-opt-${i}`"
          :key="s"
          role="option"
          :aria-selected="i === activeSuggestion"
          class="mx-next-1 flex cursor-pointer items-center gap-next-2 rounded-next-sm px-next-2 py-next-1_5 text-next-sm"
          :class="i === activeSuggestion ? 'bg-next-accent text-next-accent-foreground' : ''"
          @mouseenter="activeSuggestion = i"
          @mousedown.prevent="pickSuggestion(s)"
        >
          <Icon name="plus" class="shrink-0 text-next-muted-foreground" />
          <span class="truncate">{{ s }}</span>
        </li>
      </ul>
    </template>
  </FieldPopover>
</template>

<style scoped>
/* Mirror of FieldShell's surface/state-line — height capped (no-grow), width
   fixed. Same `--field-line` var + precedence as FieldShell/Textarea.

   The box CLIPS its content to the rounded border (overflow:hidden) and the
   scrolling pill track is INSET by the border width, so scrolled pills never
   paint over the border/state line. The colored ring is drawn on a `::after`
   overlay ABOVE the content (pointer-events:none) so it stays fully visible all
   the way around — including the corner next to a vertical scrollbar (Issue 4). */
.next-pillgroup {
  position: relative;
  width: 100%;
  border-radius: var(--radius-next-md);
  /* `--field-line` is stylesheet-resolved from the inline `--field-line-rest` so
     the `:focus-within` rule below can override it (an inline `--field-line` would
     freeze the border at the resting color — same fix as FieldShell). */
  --field-line: var(--field-line-rest, var(--color-next-input));
  border: 1px solid var(--field-line);
  color: var(--color-next-fg);
  overflow: hidden;
  transition:
    border-color var(--duration-next-fast) var(--ease-next-standard),
    box-shadow var(--duration-next-fast) var(--ease-next-standard);
}

/* The scrolling/clickable track sits INSIDE the border with padding; a stable
   scrollbar gutter keeps the right inset reserved so the ring's right edge is
   never hidden under the scrollbar. */
.next-pillgroup__track {
  padding-inline: var(--spacing-next-2);
  padding-block: var(--spacing-next-1_5);
  scrollbar-gutter: stable;
}
.next-pillgroup.is-collapse .next-pillgroup__track {
  padding-block: 0;
  scrollbar-gutter: auto;
}

/* The state line as an overlay ABOVE content. For the signalling states it shows
   a 1.5px inset ring; the focus rule below switches it to the ring color. It is
   drawn on the border box so a scrollbar can't cover it. */
.next-pillgroup::after {
  content: '';
  position: absolute;
  inset: 0;
  border-radius: inherit;
  pointer-events: none;
  box-shadow: none;
  transition: box-shadow var(--duration-next-fast) var(--ease-next-standard);
}
.next-pillgroup.has-ring::after {
  box-shadow: inset 0 0 0 1.5px var(--field-line);
}

.next-pillgroup.is-disabled {
  opacity: 0.6;
}
.next-pillgroup.allow-hover:hover {
  --field-line: color-mix(in srgb, var(--color-next-fg) 30%, transparent);
}
/* Focus line wins over default/dirty; error/success keep their own color. The
   overlay ring (::after) renders above the scrolled pills. */
.next-pillgroup:not(.is-disabled):not(.is-readonly):not(.state-error):not(
    .state-success
  ):focus-within {
  --field-line: var(--color-next-ring);
}
.next-pillgroup:not(.is-disabled):not(.is-readonly):not(.state-error):not(
    .state-success
  ):focus-within::after {
  box-shadow: inset 0 0 0 1.5px var(--color-next-ring);
}

/* Off-flow measuring row: laid out but visually hidden and non-interactive. */
.next-pillgroup__measure {
  position: absolute;
  top: 0;
  left: 0;
  visibility: hidden;
  pointer-events: none;
  white-space: nowrap;
}
</style>
