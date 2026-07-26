<script setup lang="ts">
// TextDiffView — the TEXT editor's read-only "Review changes" mode: a GitHub-style line/word diff
// between the SAVED file (`old`) and the CURRENT buffer (`current`). Removed content reads red,
// added content green; unchanged context stays plain. The diff math is the pure `diffText` module
// (mirrored by textDiff.spec.ts); this component only paints it with semantic tokens so light and
// dark both read well. When nothing changed it shows a centered empty state instead of an empty box.
import { computed } from 'vue';
import { useI18n } from '../../../app/i18n';
import { diffText, type DiffRow, type DiffSegment } from './textDiff';

const props = defineProps<{
  /** The saved file's content — the diff baseline. */
  old: string;
  /** The current (possibly AI-edited / unsaved) buffer. */
  current: string;
}>();

const { t } = useI18n();

const unchanged = computed(() => props.old === props.current);
const rows = computed<DiffRow[]>(() => (unchanged.value ? [] : diffText(props.old, props.current)));

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
    <!-- Empty state: the buffer matches the saved file, so there is nothing to review. -->
    <div
      v-if="unchanged"
      class="flex h-[60vh] items-center justify-center px-next-4 text-center text-next-sm text-next-muted-foreground"
    >
      {{ t('disk.preview.text.noChanges', 'No changes') }}
    </div>

    <!-- The diff: scrolls vertically like the textarea (~60vh) and horizontally inside the block
         (code never wraps). `min-w-max` lets every row's tint span the full scrolled width. -->
    <div
      v-else
      class="max-h-[60vh] overflow-auto rounded-next-lg"
      role="group"
      :aria-label="t('disk.preview.text.review', 'Review changes')"
    >
      <div class="min-w-max py-next-2 font-mono text-next-sm leading-relaxed text-next-fg">
        <div
          v-for="(row, i) in rows"
          :key="i"
          class="flex min-h-[1.5em]"
          :class="rowClass(row)"
        >
          <span class="w-8 shrink-0 select-none text-center" :class="gutterClass(row)" aria-hidden="true">
            {{ gutterSign(row) }}
          </span>
          <span class="whitespace-pre pr-next-4">
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
