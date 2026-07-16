<script setup lang="ts">
// Icon primitive for the "next" frontend.
//
// Renders an inline SVG from a small local name->path map (see `./icons.ts`).
// No icon-library dependency: each icon is hand-authored 24x24 stroke geometry
// (Lucide-style) drawn with `currentColor` so it inherits text color and adapts
// to dark-mode tokens automatically. Sizing is driven by font-size (`1em`), so
// an icon scales with the surrounding text utility (`text-next-lg`, …).
import { computed } from 'vue';
import { ICONS, type IconName } from './icons';

// Re-export the type so existing imports (`import Icon, { type IconName }`) keep
// working. (The runtime `ICON_NAMES` list lives in ./icons — import it there.)
export type { IconName };

const props = withDefaults(
  defineProps<{
    name: IconName;
    /** Accessible label. When omitted, the icon is decorative (aria-hidden). */
    label?: string;
    /** Stroke width in user units (24px viewBox). */
    strokeWidth?: number;
  }>(),
  { strokeWidth: 2 },
);

const inner = computed(() => ICONS[props.name] ?? '');
const decorative = computed(() => !props.label);
</script>

<template>
  <svg
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    :stroke-width="strokeWidth"
    stroke-linecap="round"
    stroke-linejoin="round"
    class="next-icon inline-block shrink-0"
    :aria-hidden="decorative ? 'true' : undefined"
    :role="decorative ? undefined : 'img'"
    :aria-label="label"
    v-html="inner"
  />
</template>

<style scoped>
.next-icon {
  width: 1em;
  height: 1em;
}
</style>
