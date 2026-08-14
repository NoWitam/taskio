<script setup lang="ts">
// ColorInput — pick a color, for the "next" frontend.
//
// The TRIGGER renders THROUGH FieldShell (so it shares the exact border + the
// "state line" with the rest of the control family, reads the surrounding
// FormField validation/dirty context, and obeys the no-grow rule). It shows a
// swatch of the current value + the hex string (or a placeholder).
//
// The popover (FieldPopover) is a BESPOKE picker — no color-library dependency:
//   1. a saturation/value SQUARE (drag to choose S + V for the current hue),
//   2. a HUE slider below it,
//   3. a palette of preset swatches (selected gets a check + ring),
//   4. a hex text input (validates #rgb / #rrggbb), and an optional clear.
//
// Model = an uppercase `#rrggbb` hex string, or `null` when cleared.
//
// COLOR-TOKEN EXCEPTION: this control legitimately renders USER-CHOSEN arbitrary
// colors as inline `background` styles (the swatch, the SV square, the hue
// track, the preset buttons). That is DATA the user is editing, not app theming
// — so inline color here is allowed by the design rules. Everything that is
// chrome (borders, text, focus ring, panel surface) still uses semantic tokens.
//
// ALPHA: deferred. The model is an opaque #rrggbb. Adding an alpha channel would
// require an alpha slider + an rgba() emit shape; out of scope for parity here.
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import Icon from '../primitives/Icon.vue';
import FieldShell from './FieldShell.vue';
import FieldPopover from './FieldPopover.vue';
import { useFormField, nextId } from './formField';
import { FIELD_PADDING_X, type ControlSize } from './fieldShell';
import { useI18n } from '../../app/i18n';

const { t } = useI18n();

const props = withDefaults(
  defineProps<{
    size?: ControlSize;
    placeholder?: string;
    disabled?: boolean;
    readonly?: boolean;
    /** Show a clear (✕) affordance when there's a value. */
    clearable?: boolean;
    /** Override the preset swatch palette (each an `#rgb`/`#rrggbb` hex). */
    swatches?: string[];
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
    // Absence must stay `undefined` (no Boolean cast to `false`) so it defers
    // to the surrounding FormField — see `formField.ts`.
    ariaInvalid: undefined,
    size: 'md',
    disabled: false,
    readonly: false,
    clearable: true,
    success: false,
    dirty: false,
  },
);

// Model: an uppercase #rrggbb hex string, or null when there's no selection.
const model = defineModel<string | null>({ default: null });

const field = useFormField();
const generatedId = nextId('next-color');
const resolvedId = computed(() => props.id ?? field?.id.value ?? generatedId);
const panelId = computed(() => `${resolvedId.value}-panel`);
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

// --- Preset palette -------------------------------------------------------
// A tasteful default spread (a neutral, then a saturated wheel + a few tints).
const DEFAULT_SWATCHES = [
  '#0F172A', '#64748B', '#EF4444', '#F97316', '#F59E0B', '#EAB308',
  '#84CC16', '#22C55E', '#10B981', '#14B8A6', '#06B6D4', '#3B82F6',
  '#6366F1', '#8B5CF6', '#A855F7', '#D946EF', '#EC4899', '#F43F5E',
];
const presets = computed(() => props.swatches ?? DEFAULT_SWATCHES);

// --- Hex / RGB / HSV math (self-contained, no deps) -----------------------
const HEX_RE = /^#?([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/;

function clamp(n: number, lo = 0, hi = 255): number {
  return Math.min(hi, Math.max(lo, Math.round(n)));
}
function normalizeHex(input: string): string | null {
  const raw = input.trim();
  if (!HEX_RE.test(raw)) return null;
  let s = raw.replace('#', '');
  if (s.length === 3) s = s.split('').map((c) => c + c).join('');
  return `#${s.toUpperCase()}`;
}
function hexToRgb(hex: string): { r: number; g: number; b: number } {
  const s = hex.replace('#', '');
  return {
    r: parseInt(s.slice(0, 2), 16),
    g: parseInt(s.slice(2, 4), 16),
    b: parseInt(s.slice(4, 6), 16),
  };
}
function rgbToHex(r: number, g: number, b: number): string {
  return `#${[r, g, b]
    .map((x) => clamp(x).toString(16).padStart(2, '0'))
    .join('')
    .toUpperCase()}`;
}
function rgbToHsv(r: number, g: number, b: number): { h: number; s: number; v: number } {
  const rn = r / 255;
  const gn = g / 255;
  const bn = b / 255;
  const max = Math.max(rn, gn, bn);
  const min = Math.min(rn, gn, bn);
  const d = max - min;
  let h = 0;
  if (d !== 0) {
    if (max === rn) h = ((gn - bn) / d) % 6;
    else if (max === gn) h = (bn - rn) / d + 2;
    else h = (rn - gn) / d + 4;
  }
  h = Math.round((h * 60 + 360) % 360);
  const s = max === 0 ? 0 : d / max;
  return { h, s, v: max };
}
function hsvToRgb(h: number, s: number, v: number): { r: number; g: number; b: number } {
  const c = v * s;
  const x = c * (1 - Math.abs(((h / 60) % 2) - 1));
  const m = v - c;
  let r = 0;
  let g = 0;
  let b = 0;
  if (h < 60) { r = c; g = x; }
  else if (h < 120) { r = x; g = c; }
  else if (h < 180) { g = c; b = x; }
  else if (h < 240) { g = x; b = c; }
  else if (h < 300) { r = x; b = c; }
  else { r = c; b = x; }
  return { r: clamp((r + m) * 255), g: clamp((g + m) * 255), b: clamp((b + m) * 255) };
}

// --- Internal HSV state (the editing surface) -----------------------------
// We keep HSV internally because hue is undefined for pure grays; deriving it
// from the model each edit would make the hue slider jump.
const hue = ref(0); // 0..360
const sat = ref(0); // 0..1
const val = ref(0); // 0..1

// Current hex computed from HSV.
const currentHex = computed(() => {
  const { r, g, b } = hsvToRgb(hue.value, sat.value, val.value);
  return rgbToHex(r, g, b);
});

// Sync internal HSV from the model when it changes externally (avoid feedback
// loops: only adopt when the model's hex differs from what HSV currently emits).
watch(
  model,
  (value) => {
    if (!value) return;
    const norm = normalizeHex(value);
    if (!norm || norm === currentHex.value) return;
    const { r, g, b } = hexToRgb(norm);
    const hsv = rgbToHsv(r, g, b);
    // Preserve a meaningful hue when the color is gray (s/v collapse hue to 0).
    if (hsv.s !== 0) hue.value = hsv.h;
    sat.value = hsv.s;
    val.value = hsv.v;
  },
  { immediate: true },
);

function commit(): void {
  model.value = currentHex.value;
}

// --- Hex text input -------------------------------------------------------
const hexDraft = ref('');
const hexInvalid = ref(false);
// While the user is typing in the hex field we don't sync the draft FROM the
// model (the commit below would otherwise re-uppercase/reformat mid-edit and
// jump the caret). The draft re-syncs to the model on blur and on external
// changes (drag, presets) while the hex field is unfocused.
const hexFocused = ref(false);
watch(
  model,
  (value) => {
    if (hexFocused.value) return;
    hexDraft.value = value ?? '';
    hexInvalid.value = false;
  },
  { immediate: true },
);
function onHexInput(value: string): void {
  hexDraft.value = value;
  const norm = normalizeHex(value);
  if (norm) {
    hexInvalid.value = false;
    model.value = norm;
  } else {
    hexInvalid.value = value.trim().length > 0;
  }
}
function onHexBlur(): void {
  hexFocused.value = false;
  // Snap the draft back to the canonical model value (or clear an invalid one).
  hexDraft.value = model.value ?? '';
  hexInvalid.value = false;
}

// --- SV square drag --------------------------------------------------------
const svRef = ref<HTMLElement | null>(null);
function svFromPointer(clientX: number, clientY: number): void {
  const el = svRef.value;
  if (!el) return;
  const rect = el.getBoundingClientRect();
  const x = Math.max(0, Math.min(rect.width, clientX - rect.left));
  const y = Math.max(0, Math.min(rect.height, clientY - rect.top));
  sat.value = rect.width ? x / rect.width : 0;
  val.value = rect.height ? 1 - y / rect.height : 0;
  commit();
}
function onSvPointerDown(e: PointerEvent): void {
  if (disabled.value || readonly.value) return;
  (e.target as HTMLElement).setPointerCapture?.(e.pointerId);
  svFromPointer(e.clientX, e.clientY);
}
function onSvPointerMove(e: PointerEvent): void {
  if (e.buttons !== 1) return;
  svFromPointer(e.clientX, e.clientY);
}
// Keyboard on the SV handle: arrows nudge S/V.
function onSvKeydown(e: KeyboardEvent): void {
  const step = e.shiftKey ? 0.1 : 0.02;
  let handled = true;
  switch (e.key) {
    case 'ArrowLeft': sat.value = Math.max(0, sat.value - step); break;
    case 'ArrowRight': sat.value = Math.min(1, sat.value + step); break;
    case 'ArrowUp': val.value = Math.min(1, val.value + step); break;
    case 'ArrowDown': val.value = Math.max(0, val.value - step); break;
    default: handled = false;
  }
  if (handled) {
    e.preventDefault();
    commit();
  }
}

// --- Hue slider ------------------------------------------------------------
const hueRef = ref<HTMLElement | null>(null);
function hueFromPointer(clientX: number): void {
  const el = hueRef.value;
  if (!el) return;
  const rect = el.getBoundingClientRect();
  const x = Math.max(0, Math.min(rect.width, clientX - rect.left));
  hue.value = Math.round((rect.width ? x / rect.width : 0) * 360);
  commit();
}
function onHuePointerDown(e: PointerEvent): void {
  if (disabled.value || readonly.value) return;
  (e.target as HTMLElement).setPointerCapture?.(e.pointerId);
  hueFromPointer(e.clientX);
}
function onHuePointerMove(e: PointerEvent): void {
  if (e.buttons !== 1) return;
  hueFromPointer(e.clientX);
}
function onHueKeydown(e: KeyboardEvent): void {
  const step = e.shiftKey ? 10 : 2;
  let handled = true;
  switch (e.key) {
    case 'ArrowLeft':
    case 'ArrowDown': hue.value = (hue.value - step + 360) % 360; break;
    case 'ArrowRight':
    case 'ArrowUp': hue.value = (hue.value + step) % 360; break;
    case 'Home': hue.value = 0; break;
    case 'End': hue.value = 360; break;
    default: handled = false;
  }
  if (handled) {
    e.preventDefault();
    commit();
  }
}

// --- Presets / clear -------------------------------------------------------
function choosePreset(hex: string): void {
  const norm = normalizeHex(hex);
  if (norm) model.value = norm;
}
function isPresetSelected(hex: string): boolean {
  const norm = normalizeHex(hex);
  return !!norm && norm === (model.value ? normalizeHex(model.value) : null);
}
function clear(closePanel?: () => void): void {
  if (disabled.value || readonly.value) return;
  model.value = null;
  closePanel?.();
}

const hasValue = computed(() => !!model.value && !!normalizeHex(model.value));
const displayValue = computed(() => (hasValue.value ? normalizeHex(model.value!) : null));

// Hue color for the SV square background tint.
const hueColor = computed(() => `hsl(${hue.value} 100% 50%)`);

const triggerPadding = computed(() => FIELD_PADDING_X[props.size]);
</script>

<template>
  <FieldPopover
    :disabled="disabled || readonly"
    :panel-id="panelId"
    :aria-label="t('colorInput.triggerLabel', 'Choose a color')"
  >
    <template #trigger="{ open, toggle }">
      <FieldShell
        :size="size"
        :disabled="disabled"
        :readonly="readonly"
        :error="invalid"
        :success="success"
        :dirty="dirty"
        :focused="open || undefined"
      >
        <button
          :id="resolvedId"
          type="button"
          class="flex h-full w-full min-w-0 flex-1 items-center gap-next-2 text-left outline-none disabled:cursor-not-allowed"
          :class="triggerPadding"
          :disabled="disabled"
          aria-haspopup="dialog"
          :aria-expanded="open"
          :aria-controls="panelId"
          :aria-invalid="invalid ? 'true' : undefined"
          :aria-describedby="resolvedDescribedBy"
          :aria-required="required ? 'true' : undefined"
          :aria-readonly="readonly ? 'true' : undefined"
          :aria-label="ariaLabel ?? (hasValue ? t('colorInput.valueLabel', 'Color {value}', { value: displayValue! }) : t('colorInput.emptyLabel', 'No color selected'))"
          @click="toggle"
        >
          <!-- Swatch: inline arbitrary color = DATA, allowed by the rules. -->
          <span
            class="h-5 w-5 shrink-0 rounded-next-sm border border-next-border"
            :class="hasValue ? '' : 'next-color-empty'"
            :style="hasValue ? { background: displayValue! } : undefined"
            aria-hidden="true"
          />
          <span v-if="hasValue" class="truncate font-next-mono text-next-xs">
            {{ displayValue }}
          </span>
          <span v-else class="truncate text-next-muted-foreground">
            {{ placeholder ?? t('colorInput.placeholder', 'Select a color…') }}
          </span>
        </button>

        <!-- Reserve the clear box whenever clearable so the trigger content never
             shifts as the value comes/goes; toggle the button's visibility only. -->
        <template v-if="clearable && !disabled && !readonly" #trailing>
          <span class="flex h-5 w-5 items-center justify-center">
            <button
              type="button"
              class="flex items-center rounded-next-sm p-next-0_5 text-next-muted-foreground hover:text-next-fg"
              :class="hasValue ? '' : 'invisible'"
              :aria-hidden="hasValue ? undefined : 'true'"
              :aria-label="t('colorInput.clear', 'Clear color')"
              tabindex="-1"
              @click.stop="clear()"
            >
              <Icon name="x" />
            </button>
          </span>
        </template>
      </FieldShell>
    </template>

    <template #default="{ closePanel }">
      <div class="flex w-[18rem] flex-col gap-next-3 p-next-3">
        <!-- Saturation / value square (drag or arrow-key the handle). -->
        <div
          ref="svRef"
          class="next-color-sv relative h-36 w-full cursor-crosshair touch-none overflow-hidden rounded-next-sm"
          :style="{ background: hueColor }"
          role="application"
          :aria-label="t('colorInput.saturation', 'Saturation and brightness')"
          @pointerdown="onSvPointerDown"
          @pointermove="onSvPointerMove"
        >
          <!-- White (left→right) then black (bottom→top) overlays = SV gradient. -->
          <span class="pointer-events-none absolute inset-0 next-color-sv-white" aria-hidden="true" />
          <span class="pointer-events-none absolute inset-0 next-color-sv-black" aria-hidden="true" />
          <button
            type="button"
            class="absolute h-4 w-4 -translate-x-1/2 -translate-y-1/2 rounded-next-full border-2 border-white shadow-next-sm outline-none focus-visible:ring-2 focus-visible:ring-next-ring"
            :style="{ left: `${sat * 100}%`, top: `${(1 - val) * 100}%` }"
            :aria-label="t('colorInput.saturationValue', 'Saturation {s}%, brightness {v}%', { s: Math.round(sat * 100), v: Math.round(val * 100) })"
            @keydown="onSvKeydown"
            @click.stop
          />
        </div>

        <!-- Hue slider. -->
        <div
          ref="hueRef"
          class="next-color-hue relative h-3 w-full cursor-ew-resize touch-none rounded-next-full"
          @pointerdown="onHuePointerDown"
          @pointermove="onHuePointerMove"
        >
          <button
            type="button"
            role="slider"
            class="absolute top-1/2 h-5 w-2 -translate-x-1/2 -translate-y-1/2 rounded-next-full border-2 border-white shadow-next-sm outline-none focus-visible:ring-2 focus-visible:ring-next-ring"
            :style="{ left: `${(hue / 360) * 100}%`, background: hueColor }"
            :aria-valuemin="0"
            :aria-valuemax="360"
            :aria-valuenow="hue"
            :aria-label="t('colorInput.hue', 'Hue')"
            @keydown="onHueKeydown"
            @click.stop
          />
        </div>

        <!-- Hex input. -->
        <div class="flex items-center gap-next-2">
          <span
            class="h-7 w-7 shrink-0 rounded-next-sm border border-next-border"
            :style="hasValue ? { background: displayValue! } : undefined"
            :class="hasValue ? '' : 'next-color-empty'"
            aria-hidden="true"
          />
          <div class="relative flex-1">
            <input
              :value="hexDraft"
              type="text"
              inputmode="text"
              spellcheck="false"
              autocapitalize="characters"
              maxlength="7"
              placeholder="#RRGGBB"
              :aria-label="t('colorInput.hexLabel', 'Hex color value')"
              :aria-invalid="hexInvalid ? 'true' : undefined"
              class="h-9 w-full rounded-next-sm border bg-next-card px-next-2 font-next-mono text-next-sm text-next-fg outline-none placeholder:text-next-muted-foreground focus-visible:ring-2 focus-visible:ring-next-ring/30"
              :class="hexInvalid ? 'border-next-danger' : 'border-next-input focus-visible:border-next-ring'"
              @focus="hexFocused = true"
              @blur="onHexBlur"
              @input="onHexInput(($event.target as HTMLInputElement).value)"
            />
          </div>
        </div>
        <p v-if="hexInvalid" class="text-next-xs text-next-danger" role="alert">
          {{ t('colorInput.hexInvalid', 'Enter a valid #rgb or #rrggbb hex.') }}
        </p>

        <!-- Preset palette. -->
        <div class="flex flex-col gap-next-1_5">
          <span class="text-next-2xs font-next-semibold uppercase tracking-[var(--tracking-next-wide)] text-next-muted-foreground">
            {{ t('colorInput.presets', 'Presets') }}
          </span>
          <div class="grid grid-cols-9 gap-next-1_5" role="group" :aria-label="t('colorInput.presetColors', 'Preset colors')">
            <button
              v-for="hex in presets"
              :key="hex"
              type="button"
              class="relative flex h-6 w-6 items-center justify-center rounded-next-sm border border-next-border outline-none transition-transform hover:scale-110 focus-visible:ring-2 focus-visible:ring-next-ring"
              :class="isPresetSelected(hex) ? 'ring-2 ring-next-ring ring-offset-1 ring-offset-next-popover' : ''"
              :style="{ background: hex }"
              :aria-label="hex"
              :aria-pressed="isPresetSelected(hex)"
              @click="choosePreset(hex)"
            >
              <Icon
                v-if="isPresetSelected(hex)"
                name="check"
                :stroke-width="3"
                class="text-[0.7rem] text-white drop-shadow"
              />
            </button>
          </div>
        </div>

        <!-- Footer: clear + done. -->
        <div class="flex items-center justify-end gap-next-2">
          <button
            v-if="clearable"
            type="button"
            class="rounded-next-sm px-next-2 py-next-1 text-next-xs font-next-medium text-next-muted-foreground hover:text-next-fg"
            @click="clear(closePanel)"
          >
            {{ t('common.clear', 'Clear') }}
          </button>
          <button
            type="button"
            class="rounded-next-sm bg-next-primary px-next-3 py-next-1 text-next-xs font-next-medium text-next-primary-foreground hover:bg-next-primary-hover"
            @click="closePanel()"
          >
            {{ t('colorInput.done', 'Done') }}
          </button>
        </div>
      </div>
    </template>
  </FieldPopover>
</template>

<style scoped>
/* Empty swatch: a subtle diagonal "no value" hatch, token-driven. */
.next-color-empty {
  background-image: linear-gradient(
    135deg,
    transparent 45%,
    var(--color-next-danger) 45%,
    var(--color-next-danger) 55%,
    transparent 55%
  );
  background-color: var(--color-next-muted);
}

/* SV square gradients. These render a value the user is editing (the chosen
   hue), not app theming — inline/fixed color here is intentional. */
.next-color-sv-white {
  background: linear-gradient(to right, #fff, transparent);
}
.next-color-sv-black {
  background: linear-gradient(to top, #000, transparent);
}

/* The hue track is a fixed spectrum; it is data chrome for color editing. */
.next-color-hue {
  background: linear-gradient(
    to right,
    #f00 0%,
    #ff0 17%,
    #0f0 33%,
    #0ff 50%,
    #00f 67%,
    #f0f 83%,
    #f00 100%
  );
}
</style>
