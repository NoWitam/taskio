<script setup lang="ts">
// TextDiffView — a read-only, GitHub-style line/word diff between a BASELINE (`old`) and the text
// it became (`current`). Removed content reads red, added content green; unchanged context stays
// plain. The diff math is the pure `diffText` module (mirrored by `__tests__/textDiff.spec.ts`);
// this component only paints it with semantic tokens so light and dark both read well. When
// nothing changed it shows a centered empty state instead of an empty box.
//
// A DESIGN-SYSTEM component (it started life inside the Disk's text preview and moved here in
// B14). It knows nothing about WHAT it is comparing — a saved file against an unsaved buffer, a
// knowledge revision against the current entry, an AI draft against what it replaces. All three
// are "text, and the text it became", so all three get one implementation rather than three that
// drift. Its copy is therefore neutral (`textDiff.*`), not Disk-flavoured.
import { computed } from 'vue';
import { useI18n } from '../../app/i18n';
import { diffText, TEXT_DIFF_MAX_LINES, type DiffRow, type DiffSegment } from './textDiff';

const props = withDefaults(
  defineProps<{
    /** The baseline — what the text was. */
    old: string;
    /** What the text is now. */
    current: string;
    /**
     * Max height of the scroll region. The Disk's editor matches its textarea (`60vh`); a drawer
     * wants something smaller. A CSS length, since the value is a layout decision of the host.
     */
    maxHeight?: string;
    /** Copy for the "nothing changed" state, when the host can say something more specific. */
    emptyLabel?: string;
  }>(),
  { maxHeight: '60vh', emptyLabel: undefined },
);

const { t } = useI18n();

const unchanged = computed(() => props.old === props.current);
const rows = computed<DiffRow[]>(() => (unchanged.value ? [] : diffText(props.old, props.current)));

/**
 * Whether the engine fell back to a COARSE whole-block replace (its `MAX_LINES` guard, which stops
 * an O(n·m) DP from hanging the UI on a huge document).
 *
 * Read from the SAME CONDITION the engine applies, not inferred from the output: a one-line total
 * replacement produces the very same two-row result, so an output-shape guess told the user their
 * two-word document was "very long". (It did exactly that until a component test caught it.)
 */
const coarse = computed(
  () =>
    props.old.split('\n').length > TEXT_DIFF_MAX_LINES ||
    props.current.split('\n').length > TEXT_DIFF_MAX_LINES,
);

/** Line-level row tint (subtle) — the gutter sign carries the meaning, not colour alone. */
function rowClass(row: DiffRow): string {
  if (row.type === 'del') return 'bg-next-danger/10';
  if (row.type === 'add') return 'bg-next-success/10';
  return '';
}

/** The gutter glyph: a non-colour signal for removed (−) / added (+) / context (blank). */
function gutterSign(row: DiffRow): string {
  if (row.type === 'del') return '−';
  if (row.type === 'add') return '+';
  return '';
}

function gutterClass(row: DiffRow): string {
  if (row.type === 'del') return 'text-next-danger';
  if (row.type === 'add') return 'text-next-success';
  return 'text-next-muted-foreground';
}

/** Intra-line word highlight (stronger tint) layered over the row; equal segments stay plain. */
function segmentClass(seg: DiffSegment): string {
  if (seg.type === 'del') return 'rounded-next-xs bg-next-danger/30';
  if (seg.type === 'add') return 'rounded-next-xs bg-next-success/30';
  return '';
}
</script>

<template>
  <div class="rounded-next-lg border border-next-border bg-next-card">
    <!-- Empty state: the two texts are identical, so there is nothing to review. -->
    <div
      v-if="unchanged"
      class="flex items-center justify-center px-next-4 py-next-8 text-center text-next-sm text-next-muted-foreground"
      :style="{ minHeight: maxHeight === '60vh' ? '60vh' : undefined }"
    >
      {{ emptyLabel ?? t('textDiff.noChanges') }}
    </div>

    <!-- The diff: scrolls vertically within `maxHeight` and horizontally inside the block (code
         never wraps). `min-w-max` lets every row's tint span the full scrolled width. -->
    <div
      v-else
      class="overflow-auto rounded-next-lg"
      :style="{ maxHeight }"
      role="group"
      :aria-label="t('textDiff.label')"
    >
      <!-- The bound, stated. Sticky so it survives the scroll it is explaining. -->
      <p
        v-if="coarse"
        class="sticky top-0 z-10 border-b border-next-border bg-next-warning-subtle px-next-3 py-next-2 text-next-xs text-next-warning-subtle-foreground"
      >
        {{ t('textDiff.coarse') }}
      </p>

      <div class="min-w-max py-next-2 font-mono text-next-sm leading-relaxed text-next-fg">
        <div
          v-for="(row, i) in rows"
          :key="i"
          class="flex min-h-[1.5em]"
          :class="rowClass(row)"
        >
          <!-- The gutter is a VISUAL signal (colour-free, but visual): a screen reader gets the
               equivalent from the visually-hidden prefix below, not from this glyph. -->
          <span class="w-8 shrink-0 select-none text-center" :class="gutterClass(row)" aria-hidden="true">
            {{ gutterSign(row) }}
          </span>
          <span class="whitespace-pre pr-next-4">
            <!-- WITHOUT THIS a diff is read out as plain prose — every row sounds identical and a
                 listener cannot tell an addition from a removal, while deciding whether to accept
                 the change. Visually hidden, so nothing moves on screen. -->
            <span v-if="row.type !== 'equal'" class="sr-only"
              >{{ row.type === 'add' ? t('textDiff.rowAdded') : t('textDiff.rowRemoved') }}: </span
            >
            <template v-if="row.type === 'equal'">{{ row.text }}</template>
            <template v-else>
              <span v-for="(seg, s) in row.segments" :key="s" :class="segmentClass(seg)">{{ seg.text }}</span>
            </template>
          </span>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* Defined locally, matching this design system's convention (see Link.vue / Spinner.vue): the
   `next` stylesheet is scoped under `.next-root`, so components carry their own visually-hidden
   helper rather than relying on a global utility. */
.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}
</style>
