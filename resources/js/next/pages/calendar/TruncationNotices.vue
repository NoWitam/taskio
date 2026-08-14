<script setup lang="ts">
// TruncationNotices — "this view is not showing you everything", said three different ways.
//
// The backend split loss into three STRUCTURALLY different kinds precisely so the
// interface would stop making one statement about three different facts. One sentence
// covering all three would be untrue in two cases out of three:
//
//   window_trimmed  — the far end of the range was cut. What you see is COMPLETE up to a
//                     point, and empty after it because it was trimmed, not because it is
//                     empty.
//   item_densified  — you are looking at a SAMPLE of a series, not the series. And the
//                     sample is filled forwards from an anchor, so a minute-cadence
//                     automation spends its whole budget in a few hours of one day and
//                     leaves the rest of the window blank. The empty days after it are the
//                     most misleading thing on the screen, which is why the second
//                     sentence of that message is required, not decorative.
//   items_dropped   — whole items are ABSENT. Not truncated: absent. This is the only kind
//                     at which a user can make a WRONG DECISION by trusting an empty
//                     square, so it alone gets the alarming glyph.
//
// `meta.truncated` decides only whether this section EXISTS. It never contributes a word:
// the text always comes from the per-(source, kind) rows.
//
// AND THE COUNTS. `omitted_occurrences` / `affected_items` are `int | null`, and `null`
// means UNKNOWN — the merge rule poisons a sum with an unknown addend on purpose, rather
// than reporting a known part as a whole. So an unknown count selects a DIFFERENT SENTENCE,
// never the number zero. `count ?? 0` here would print "0 occurrences did not fit", which
// is data-shaped and false.
import { computed } from 'vue';
import Alert from '../../ui/feedback/Alert.vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import { useI18n } from '../../app/i18n';
import { truncationCount, truncationIcon, truncationKey } from './calendarMeta';
import type { CalendarTruncation } from './types';

const props = defineProps<{
  truncations: CalendarTruncation[];
  /** Source id → its server-translated name. Never a hardcoded label. */
  sourceLabelOf: (id: string) => string;
}>();

const emit = defineEmits<{
  /** "Narrow the filters" — scroll to the bar and focus the source filter. */
  (e: 'narrow-filters'): void;
  /** "Search by name" — focus the search input. */
  (e: 'search-by-name'): void;
}>();

const { t } = useI18n();

interface NoticeRow {
  /** Stable per (source, kind) so the alert does not re-key (and re-announce) on refresh. */
  key: string;
  kind: string;
  icon: ReturnType<typeof truncationIcon>;
  text: string;
  actionLabel: string | null;
  action: 'narrow' | 'search' | null;
}

const rows = computed<NoticeRow[]>(() =>
  props.truncations
    .map((truncation): NoticeRow | null => {
      const count = truncationCount(
        truncation.kind,
        truncation.omitted_occurrences,
        truncation.affected_items,
      );
      const key = truncationKey(truncation.kind, count);
      // An unknown KIND cannot be worded honestly, so it is not worded at all. A future
      // kind will surface as a missing row rather than as a sentence about the wrong thing.
      if (!key) return null;

      const params: Record<string, string | number> = { source: props.sourceLabelOf(truncation.source) };
      if (count != null) params.n = count;

      const isDropped = truncation.kind === 'items_dropped';
      return {
        key: `${truncation.source}|${truncation.kind}`,
        kind: truncation.kind,
        icon: truncationIcon(truncation.kind),
        text: t(key, '', params),
        actionLabel: isDropped
          ? t('calendar.truncation.action.searchByName')
          : t('calendar.truncation.action.narrowFilters'),
        action: isDropped ? 'search' : 'narrow',
      };
    })
    .filter((row): row is NoticeRow => row !== null),
);
</script>

<template>
  <!-- ONE alert, one row per (source, kind). Warning tone → `role="alert"` from Alert
       itself, announced once when the window settles. -->
  <Alert v-if="rows.length" variant="warning" size="sm" :title="t('calendar.truncation.title')">
    <ul class="flex flex-col gap-next-2">
      <li v-for="row in rows" :key="row.key" class="flex flex-wrap items-start gap-next-2">
        <Icon :name="row.icon" class="mt-px shrink-0" aria-hidden="true" />
        <span class="min-w-0 flex-1">{{ row.text }}</span>
        <Button
          v-if="row.action"
          variant="ghost"
          size="xs"
          class="shrink-0"
          @click="row.action === 'search' ? emit('search-by-name') : emit('narrow-filters')"
        >
          {{ row.actionLabel }}
        </Button>
      </li>
    </ul>
  </Alert>
</template>
