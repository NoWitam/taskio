<script lang="ts">
// Platform detection for the `mod` key, computed once at module load. macOS maps
// `mod` → ⌘ (Command); every other platform → Ctrl. Kept module-level (not per
// instance) so every Kbd renders the same hint and tests can stay deterministic.
function detectIsMac(): boolean {
  if (typeof navigator === 'undefined') return false;
  const platform =
    // `userAgentData` is the modern source; fall back to the legacy `platform`.
    (navigator as unknown as { userAgentData?: { platform?: string } })
      .userAgentData?.platform ||
    navigator.platform ||
    navigator.userAgent ||
    '';
  return /mac|iphone|ipad|ipod/i.test(platform);
}

export const IS_MAC = detectIsMac();
</script>

<script setup lang="ts">
// Kbd — a keyboard key hint for the "next" frontend.
//
// Renders one or more keys as subtle, bordered "caps". Pass keys as the `keys`
// prop (`['mod', 'k']`) OR as the default slot (single key). Common key tokens
// are NORMALIZED to a short symbol/label: `mod` → ⌘ on macOS / Ctrl elsewhere,
// plus Enter (↵), Esc, Shift (⇧), Alt/Option, Tab (⇥), Space, and the arrow
// glyphs. Everything else renders upper-cased as-is.
//
// Small + inline by design (it scales with surrounding text). Used by the
// Command palette, tooltips, menu shortcuts, etc.
//
// A11y: each cap is decorative chrome around real text; the full combination is
// exposed via an `aria-label` (e.g. "Command K") so a screen reader announces a
// readable phrase, not the glyphs. The visible caps are `aria-hidden`.
import { computed } from 'vue';
import { useI18n } from '../../app/i18n';

const props = withDefaults(
  defineProps<{
    /** Keys to render, in order. When omitted, the default slot is used. */
    keys?: string[];
    /** Visual scale. `sm` is the inline default; `md` for standalone hints. */
    size?: 'sm' | 'md';
    /**
     * Accessible label override for the whole combination. When omitted, a
     * readable phrase is derived from the normalized key names.
     */
    ariaLabel?: string;
  }>(),
  { size: 'sm' },
);

const { t } = useI18n();

interface NormalizedKey {
  /** Glyph/short text shown inside the cap. */
  glyph: string;
  /** Spoken/readable name used to build the aria-label. */
  spoken: string;
}

/** Map a raw key token to a glyph + a readable spoken name (platform-aware). */
function normalize(raw: string): NormalizedKey {
  const key = raw.trim();
  const lower = key.toLowerCase();
  switch (lower) {
    case 'mod':
    case 'cmd':
    case 'command':
    case 'meta':
      return IS_MAC
        ? { glyph: '⌘', spoken: t('kbd.command', 'Command') }
        : { glyph: t('kbd.ctrl', 'Ctrl'), spoken: t('kbd.control', 'Control') };
    case 'ctrl':
    case 'control':
      return { glyph: t('kbd.ctrl', 'Ctrl'), spoken: t('kbd.control', 'Control') };
    case 'shift':
      return { glyph: '⇧', spoken: t('kbd.shift', 'Shift') };
    case 'alt':
    case 'option':
      return IS_MAC
        ? { glyph: '⌥', spoken: t('kbd.option', 'Option') }
        : { glyph: t('kbd.alt', 'Alt'), spoken: t('kbd.alt', 'Alt') };
    case 'enter':
    case 'return':
      return { glyph: '↵', spoken: t('kbd.enter', 'Enter') };
    case 'esc':
    case 'escape':
      return { glyph: t('kbd.esc', 'Esc'), spoken: t('kbd.escape', 'Escape') };
    case 'tab':
      return { glyph: '⇥', spoken: t('kbd.tab', 'Tab') };
    case 'space':
    case 'spacebar':
      return { glyph: '␣', spoken: t('kbd.space', 'Space') };
    case 'backspace':
    case 'delete':
    case 'del':
      return { glyph: '⌫', spoken: t('kbd.backspace', 'Backspace') };
    case 'up':
    case 'arrowup':
      return { glyph: '↑', spoken: t('kbd.arrowUp', 'Arrow up') };
    case 'down':
    case 'arrowdown':
      return { glyph: '↓', spoken: t('kbd.arrowDown', 'Arrow down') };
    case 'left':
    case 'arrowleft':
      return { glyph: '←', spoken: t('kbd.arrowLeft', 'Arrow left') };
    case 'right':
    case 'arrowright':
      return { glyph: '→', spoken: t('kbd.arrowRight', 'Arrow right') };
    default:
      // Single letters upper-case for legibility; longer tokens render as-is.
      return key.length === 1
        ? { glyph: key.toUpperCase(), spoken: key.toUpperCase() }
        : { glyph: key, spoken: key };
  }
}

const resolvedKeys = computed<string[]>(() => {
  if (props.keys && props.keys.length > 0) return props.keys;
  return [];
});

const normalized = computed<NormalizedKey[]>(() =>
  resolvedKeys.value.map(normalize),
);

const usesSlot = computed(() => normalized.value.length === 0);

const ariaLabel = computed(() => {
  if (props.ariaLabel) return props.ariaLabel;
  if (usesSlot.value) return undefined;
  return normalized.value.map((k) => k.spoken).join(' ');
});

const SIZE_CLASS: Record<'sm' | 'md', string> = {
  sm: 'min-w-[1.25rem] h-5 px-next-1 text-next-2xs',
  md: 'min-w-[1.5rem] h-6 px-next-1_5 text-next-xs',
};

const capClass = computed(() => [
  'next-kbd inline-flex items-center justify-center rounded-next-sm border border-next-border',
  'bg-next-muted text-next-muted-foreground font-next-medium leading-none align-middle',
  'shadow-next-xs select-none',
  SIZE_CLASS[props.size],
]);
</script>

<template>
  <span
    class="next-kbd-group inline-flex items-center gap-next-0_5 align-middle"
    :role="ariaLabel ? 'img' : undefined"
    :aria-label="ariaLabel"
  >
    <!-- Slot mode: a single cap wrapping arbitrary text. -->
    <kbd v-if="usesSlot" :class="capClass" aria-hidden="true"><slot /></kbd>

    <!-- Keys mode: one cap per normalized key. -->
    <template v-else>
      <kbd
        v-for="(k, i) in normalized"
        :key="i"
        :class="capClass"
        aria-hidden="true"
        >{{ k.glyph }}</kbd
      >
    </template>
  </span>
</template>
