<script setup lang="ts">
// Live design-tokens documentation.
//
// Reads token VALUES straight from the CSS custom properties defined in
// resources/css/next.css (via getComputedStyle on probe elements), so this page
// is always in sync with the stylesheet — it never hardcodes a hex/hsl. Color
// tokens show a light + dark preview pair (resolved from `.next-root` and
// `.next-root.dark` probes) to prove the dark-mode override scheme.
import { onMounted, ref } from 'vue';

interface TokenRow {
  /** The custom property name, e.g. `--color-next-bg`. */
  varName: string;
  /** Friendly label, e.g. `bg`. */
  label: string;
  /** Resolved value in light mode. */
  light: string;
  /** Resolved value in dark mode. */
  dark: string;
}

// --- Token name catalogs (names only; values resolved live) -----------------

const COLOR_TOKENS: string[] = [
  'primary',
  'primary-foreground',
  'primary-hover',
  'primary-active',
  'primary-subtle',
  'primary-subtle-foreground',
  'bg',
  'fg',
  'card',
  'card-foreground',
  'popover',
  'popover-foreground',
  'border',
  'input',
  'ring',
  'muted',
  'muted-foreground',
  'accent',
  'accent-foreground',
  'overlay',
  'success',
  'success-foreground',
  'success-subtle',
  'success-subtle-foreground',
  'warning',
  'warning-foreground',
  'warning-subtle',
  'warning-subtle-foreground',
  'danger',
  'danger-foreground',
  'danger-subtle',
  'danger-subtle-foreground',
  'info',
  'info-foreground',
  'info-subtle',
  'info-subtle-foreground',
];

const NEUTRAL_STEPS = ['0', '50', '100', '200', '300', '400', '500', '600', '700', '800', '900', '950'];

const SPACING_STEPS = [
  '0', 'px', '0_5', '1', '1_5', '2', '2_5', '3', '4', '5', '6', '8', '10', '12', '16', '20', '24',
];

const RADIUS_STEPS = ['none', 'xs', 'sm', 'md', 'lg', 'xl', '2xl', 'full'];

const TEXT_STEPS = ['2xs', 'xs', 'sm', 'base', 'lg', 'xl', '2xl', '3xl', '4xl'];

const SHADOW_STEPS = ['xs', 'sm', 'md', 'lg', 'xl', 'focus'];

const Z_STEPS = [
  'base', 'raised', 'dropdown', 'sticky', 'overlay', 'modal', 'popover', 'toast', 'tooltip',
];

const DURATION_STEPS = ['instant', 'fast', 'normal', 'slow'];
const EASE_STEPS = ['standard', 'emphasized', 'exit'];

// --- Resolved state ---------------------------------------------------------

const colors = ref<TokenRow[]>([]);
const neutrals = ref<TokenRow[]>([]);
const spacing = ref<{ label: string; value: string }[]>([]);
const radii = ref<{ label: string; value: string }[]>([]);
const typeScale = ref<{ label: string; size: string; line: string }[]>([]);
const shadows = ref<{ label: string; value: string }[]>([]);
const zIndex = ref<{ label: string; value: string }[]>([]);
const durations = ref<{ label: string; value: string }[]>([]);
const eases = ref<{ label: string; value: string }[]>([]);

function read(el: Element, prop: string): string {
  return getComputedStyle(el).getPropertyValue(prop).trim();
}

onMounted(() => {
  // Two probes mounted in the template: one light, one dark.
  const lightProbe = document.getElementById('token-probe-light');
  const darkProbe = document.getElementById('token-probe-dark');
  if (!lightProbe || !darkProbe) return;

  colors.value = COLOR_TOKENS.map((label) => {
    const varName = `--color-next-${label}`;
    return {
      varName,
      label,
      light: read(lightProbe, varName),
      dark: read(darkProbe, varName),
    };
  });

  neutrals.value = NEUTRAL_STEPS.map((step) => {
    const varName = `--color-next-neutral-${step}`;
    return {
      varName,
      label: step,
      light: read(lightProbe, varName),
      dark: read(darkProbe, varName),
    };
  });

  spacing.value = SPACING_STEPS.map((step) => ({
    label: step.replace('_', '.'),
    value: read(lightProbe, `--spacing-next-${step}`),
  }));

  radii.value = RADIUS_STEPS.map((step) => ({
    label: step,
    value: read(lightProbe, `--radius-next-${step}`),
  }));

  typeScale.value = TEXT_STEPS.map((step) => ({
    label: step,
    size: read(lightProbe, `--text-next-${step}`),
    line: read(lightProbe, `--text-next-${step}--line-height`),
  }));

  shadows.value = SHADOW_STEPS.map((step) => ({
    label: step,
    value: read(lightProbe, `--shadow-next-${step}`),
  }));

  zIndex.value = Z_STEPS.map((step) => ({
    label: step,
    value: read(lightProbe, `--z-next-${step}`),
  }));

  durations.value = DURATION_STEPS.map((step) => ({
    label: step,
    value: read(lightProbe, `--duration-next-${step}`),
  }));

  eases.value = EASE_STEPS.map((step) => ({
    label: step,
    value: read(lightProbe, `--ease-next-${step}`),
  }));
});
</script>

<template>
  <article class="flex max-w-4xl flex-col gap-next-10">
    <!-- Off-screen probes so we can resolve BOTH light and dark token values
         regardless of the gallery's current theme. -->
    <div aria-hidden="true" class="pointer-events-none absolute -left-[9999px] -top-[9999px]">
      <div id="token-probe-light" class="next-root"></div>
      <div id="token-probe-dark" class="next-root dark"></div>
    </div>

    <header class="flex flex-col gap-next-2">
      <!-- Explicit weight: Tailwind preflight resets h1 to font-weight 400. -->
      <h1 class="text-next-3xl font-next-semibold">Design tokens</h1>
      <p class="text-next-base text-next-muted-foreground">
        Resolved live from <code class="font-next-mono text-next-sm">resources/css/next.css</code>.
        Every value below is read from a CSS custom property at runtime, so this
        page can never drift from the stylesheet. Color tokens show their light
        and dark resolved values side by side.
      </p>
    </header>

    <!-- Semantic colors -->
    <section class="flex flex-col gap-next-4">
      <h2 class="text-next-xl">Semantic colors</h2>
      <div class="grid grid-cols-1 gap-next-3 next-md:grid-cols-2">
        <div
          v-for="c in colors"
          :key="c.varName"
          class="flex items-center gap-next-3 rounded-next-lg border border-next-border bg-next-card p-next-3"
        >
          <div class="flex shrink-0 overflow-hidden rounded-next-md border border-next-border">
            <span class="block h-10 w-10" :style="{ background: c.light }" title="light" />
            <span class="block h-10 w-10" :style="{ background: c.dark }" title="dark" />
          </div>
          <div class="min-w-0">
            <p class="truncate text-next-sm font-next-medium">{{ c.label }}</p>
            <p class="truncate font-next-mono text-next-xs text-next-muted-foreground">
              {{ c.light }}
            </p>
            <p class="truncate font-next-mono text-next-xs text-next-muted-foreground">
              dark: {{ c.dark }}
            </p>
          </div>
        </div>
      </div>
    </section>

    <!-- Neutral ramp -->
    <section class="flex flex-col gap-next-4">
      <h2 class="text-next-xl">Neutral ramp</h2>
      <p class="text-next-sm text-next-muted-foreground">
        Used to derive semantics — never referenced directly in components.
      </p>
      <div class="grid grid-cols-2 gap-next-2 next-sm:grid-cols-4 next-md:grid-cols-6">
        <div
          v-for="n in neutrals"
          :key="n.varName"
          class="overflow-hidden rounded-next-md border border-next-border"
        >
          <div class="flex h-12">
            <span class="block flex-1" :style="{ background: n.light }" title="light" />
            <span class="block flex-1" :style="{ background: n.dark }" title="dark" />
          </div>
          <div class="bg-next-card px-next-2 py-next-1">
            <p class="text-next-xs font-next-medium">{{ n.label }}</p>
          </div>
        </div>
      </div>
    </section>

    <!-- Spacing -->
    <section class="flex flex-col gap-next-4">
      <h2 class="text-next-xl">Spacing</h2>
      <ul class="flex flex-col gap-next-2">
        <li v-for="s in spacing" :key="s.label" class="flex items-center gap-next-3">
          <span class="w-12 shrink-0 font-next-mono text-next-xs text-next-muted-foreground">
            {{ s.label }}
          </span>
          <span class="h-3 rounded-next-xs bg-next-primary" :style="{ width: s.value }" />
          <span class="font-next-mono text-next-xs text-next-muted-foreground">{{ s.value }}</span>
        </li>
      </ul>
    </section>

    <!-- Radius -->
    <section class="flex flex-col gap-next-4">
      <h2 class="text-next-xl">Radius</h2>
      <div class="grid grid-cols-2 gap-next-3 next-sm:grid-cols-4">
        <div v-for="r in radii" :key="r.label" class="flex flex-col items-center gap-next-2">
          <span
            class="h-16 w-16 border border-next-primary bg-next-primary-subtle"
            :style="{ borderRadius: r.value }"
          />
          <p class="text-next-sm font-next-medium">{{ r.label }}</p>
          <p class="font-next-mono text-next-xs text-next-muted-foreground">{{ r.value }}</p>
        </div>
      </div>
    </section>

    <!-- Type scale -->
    <section class="flex flex-col gap-next-4">
      <h2 class="text-next-xl">Type scale</h2>
      <ul class="flex flex-col gap-next-3">
        <li v-for="t in typeScale" :key="t.label" class="flex items-baseline gap-next-4">
          <span class="w-12 shrink-0 font-next-mono text-next-xs text-next-muted-foreground">
            {{ t.label }}
          </span>
          <span
            class="truncate text-next-fg"
            :style="{ fontSize: t.size, lineHeight: t.line }"
          >
            The quick brown fox
          </span>
          <span class="ml-auto shrink-0 font-next-mono text-next-xs text-next-muted-foreground">
            {{ t.size }}
          </span>
        </li>
      </ul>
    </section>

    <!-- Shadows -->
    <section class="flex flex-col gap-next-4">
      <h2 class="text-next-xl">Shadows</h2>
      <div class="grid grid-cols-2 gap-next-6 next-sm:grid-cols-3 next-md:grid-cols-6">
        <div v-for="sh in shadows" :key="sh.label" class="flex flex-col items-center gap-next-2">
          <span
            class="h-16 w-16 rounded-next-lg bg-next-card"
            :style="{ boxShadow: sh.value }"
          />
          <p class="text-next-sm font-next-medium">{{ sh.label }}</p>
        </div>
      </div>
    </section>

    <!-- Z-index -->
    <section class="flex flex-col gap-next-4">
      <h2 class="text-next-xl">Z-index layers</h2>
      <ul class="flex flex-col gap-next-px overflow-hidden rounded-next-lg border border-next-border">
        <li
          v-for="z in zIndex"
          :key="z.label"
          class="flex items-center justify-between bg-next-card px-next-3 py-next-2"
        >
          <span class="text-next-sm font-next-medium">{{ z.label }}</span>
          <span class="font-next-mono text-next-xs text-next-muted-foreground">{{ z.value }}</span>
        </li>
      </ul>
    </section>

    <!-- Motion -->
    <section class="flex flex-col gap-next-4">
      <h2 class="text-next-xl">Motion</h2>
      <div class="grid grid-cols-1 gap-next-6 next-md:grid-cols-2">
        <div>
          <h3 class="mb-next-2 text-next-base">Durations</h3>
          <ul class="flex flex-col gap-next-2">
            <li v-for="d in durations" :key="d.label" class="flex items-center justify-between">
              <span class="text-next-sm">{{ d.label }}</span>
              <span class="font-next-mono text-next-xs text-next-muted-foreground">{{ d.value }}</span>
            </li>
          </ul>
        </div>
        <div>
          <h3 class="mb-next-2 text-next-base">Easing</h3>
          <ul class="flex flex-col gap-next-2">
            <li v-for="e in eases" :key="e.label" class="flex items-center justify-between gap-next-3">
              <span class="text-next-sm">{{ e.label }}</span>
              <span class="truncate font-next-mono text-next-xs text-next-muted-foreground">
                {{ e.value }}
              </span>
            </li>
          </ul>
        </div>
      </div>
    </section>
  </article>
</template>
