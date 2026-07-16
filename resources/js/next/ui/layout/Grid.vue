<script setup lang="ts">
// Grid primitive for the "next" frontend.
//
// A small, token-driven responsive CSS grid. `cols` is either a single column
// count or a per-breakpoint object ({ base, sm, md, lg, xl }). The column counts
// are published as CSS custom properties and consumed by scoped media queries
// keyed off the `--breakpoint-next-*` tokens, so the responsive behavior stays
// on the design-system breakpoints (no dynamic Tailwind class generation).
//
// `gap` reuses the spacing scale by token key (matching `--spacing-next-*`).
// For an item that should span multiple columns, set its inline style to
// `grid-column: span N` (documented in the gallery) — kept out of the API to
// keep the surface small.
import { computed } from 'vue';

type SpacingKey =
  | '0'
  | '1'
  | '2'
  | '3'
  | '4'
  | '5'
  | '6'
  | '8'
  | '10'
  | '12';

type ResponsiveCols = {
  base?: number;
  sm?: number;
  md?: number;
  lg?: number;
  xl?: number;
};

const props = withDefaults(
  defineProps<{
    /** Column count: a single number or per-breakpoint object. */
    cols?: number | ResponsiveCols;
    /** Gap between cells — a `--spacing-next-*` token key. */
    gap?: SpacingKey;
    /** Rendered element. */
    as?: string;
  }>(),
  {
    // `cols` is intentionally left without a `withDefaults` default: a primitive
    // default (`1`) makes the SFC macro collapse the `number | ResponsiveCols`
    // union to `number`. The `resolved` computed below treats `undefined` as 1.
    gap: '4',
    as: 'div',
  },
);

const GAP_CLASS: Record<SpacingKey, string> = {
  '0': 'gap-next-0',
  '1': 'gap-next-1',
  '2': 'gap-next-2',
  '3': 'gap-next-3',
  '4': 'gap-next-4',
  '5': 'gap-next-5',
  '6': 'gap-next-6',
  '8': 'gap-next-8',
  '10': 'gap-next-10',
  '12': 'gap-next-12',
};

// Normalize `cols` into a {base,sm,md,lg,xl} shape. A bare number fills `base`
// and cascades up (every breakpoint inherits unless overridden).
const resolved = computed<Required<Pick<ResponsiveCols, 'base'>> & ResponsiveCols>(() => {
  if (typeof props.cols === 'number') return { base: props.cols };
  return { base: props.cols.base ?? 1, ...props.cols };
});

// Publish each defined breakpoint's column count as a custom property. Scoped
// CSS below reads them inside the matching media query.
const styleVars = computed<Record<string, string>>(() => {
  const r = resolved.value;
  const vars: Record<string, string> = { '--next-grid-cols': String(r.base) };
  if (r.sm !== undefined) vars['--next-grid-cols-sm'] = String(r.sm);
  if (r.md !== undefined) vars['--next-grid-cols-md'] = String(r.md);
  if (r.lg !== undefined) vars['--next-grid-cols-lg'] = String(r.lg);
  if (r.xl !== undefined) vars['--next-grid-cols-xl'] = String(r.xl);
  return vars;
});

const classes = computed(() => ['next-grid grid', GAP_CLASS[props.gap]]);
</script>

<template>
  <component :is="as" :class="classes" :style="styleVars">
    <slot />
  </component>
</template>

<style scoped>
/* Base column count, then progressively override at each design-system
   breakpoint that the caller supplied. Counts fall through (cascade up) because
   each query only sets its own variable when present and the property defaults
   to the previous resolved value via the var fallback chain. */
.next-grid {
  grid-template-columns: repeat(var(--next-grid-cols, 1), minmax(0, 1fr));
}

@media (min-width: 40rem) {
  .next-grid {
    grid-template-columns: repeat(
      var(--next-grid-cols-sm, var(--next-grid-cols, 1)),
      minmax(0, 1fr)
    );
  }
}

@media (min-width: 48rem) {
  .next-grid {
    grid-template-columns: repeat(
      var(--next-grid-cols-md, var(--next-grid-cols-sm, var(--next-grid-cols, 1))),
      minmax(0, 1fr)
    );
  }
}

@media (min-width: 64rem) {
  .next-grid {
    grid-template-columns: repeat(
      var(
        --next-grid-cols-lg,
        var(--next-grid-cols-md, var(--next-grid-cols-sm, var(--next-grid-cols, 1)))
      ),
      minmax(0, 1fr)
    );
  }
}

@media (min-width: 80rem) {
  .next-grid {
    grid-template-columns: repeat(
      var(
        --next-grid-cols-xl,
        var(
          --next-grid-cols-lg,
          var(--next-grid-cols-md, var(--next-grid-cols-sm, var(--next-grid-cols, 1)))
        )
      ),
      minmax(0, 1fr)
    );
  }
}
</style>
