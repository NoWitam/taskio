<script setup lang="ts">
// DayPopover — ONE day, in full: every occurrence, unfolded, with its badge.
//
// It is the escape hatch behind three affordances at once: the "+N more" counter, the
// whole cell in the compact (dot) presentation, and Enter on a focused day. It is also the
// ONLY keyboard route to an individual chip — the chips are deliberately not tab stops
// (forty-one extra stops would make crossing the grid a journey), so this list, which is a
// plain sequence of buttons, is where a keyboard user reaches them.
//
// A NOTE ON THE NAME, because it will look wrong next to the spec. The UX spec asks for an
// anchored `Popover`. `ui/overlay/Popover.vue` positions itself against a trigger it WRAPS
// (`useAnchoredPosition(triggerRef, …)`, with `triggerRef` on its own `inline-flex` root)
// and has no external-anchor prop. Wrapping a cell in it would put a non-`gridcell`
// element between `role="row"` and its cells — invalid ARIA in the one place on this
// screen where the roles are load-bearing — and an `inline-flex` div inside `grid-cols-7`
// would break the layout besides. So the day sheet is a `Modal`: same design system, same
// focus trap, same Esc, same overlay stack, and it works identically from a click, from
// the keyboard and on touch. The name is kept so it still matches the spec's file list.
//
// Everything a row shows comes from the occurrence itself. Nothing is fetched here.
import { computed } from 'vue';
import Modal from '../../ui/overlay/Modal.vue';
import Button from '../../ui/primitives/Button.vue';
import { useI18n } from '../../app/i18n';
import { fromIsoDate, fullDateLabel } from '../../ui/forms/date/dateCore';
import OccurrenceChip from './OccurrenceChip.vue';
import type { CalendarOccurrence, IsoDay } from './types';

const props = defineProps<{
  /** The day being shown, or null when the sheet is closed. */
  iso: IsoDay | null;
  /** The day's occurrences, UNFOLDED — this is the surface that shows the whole series. */
  occurrences: CalendarOccurrence[];
  timezone: string;
  locale: string;
  sourceLabelOf: (id: string) => string;
}>();

const emit = defineEmits<{
  (e: 'select', occurrence: CalendarOccurrence): void;
  (e: 'create', iso: IsoDay): void;
}>();

const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();

const dateLabel = computed(() => {
  const date = props.iso ? fromIsoDate(props.iso) : null;
  return date ? fullDateLabel(date, props.locale) : (props.iso ?? '');
});
</script>

<template>
  <Modal v-model:open="open" size="md" :aria-label="dateLabel">
    <template #title>
      <span class="capitalize">{{ dateLabel }}</span>
    </template>

    <div class="flex flex-col gap-next-3">
      <p class="text-next-xs text-next-muted-foreground">
        {{
          occurrences.length > 0
            ? t('calendar.day.count', '', { n: occurrences.length })
            : t('calendar.day.empty')
        }}
      </p>

      <!-- Scrolls rather than grows: a dense series legitimately runs to dozens of rows,
           and a sheet taller than the viewport would put its own close button off-screen. -->
      <ul v-if="occurrences.length" class="flex max-h-[24rem] flex-col gap-next-1 overflow-y-auto">
        <li v-for="occurrence in occurrences" :key="occurrence.id">
          <OccurrenceChip
            :occurrence="occurrence"
            variant="list"
            :timezone="timezone"
            :source-label="sourceLabelOf(occurrence.source)"
            @select="emit('select', $event)"
          />
        </li>
      </ul>
    </div>

    <template #footer>
      <Button v-if="iso" variant="outline" size="sm" leading-icon="plus" @click="emit('create', iso)">
        {{ t('calendar.day.createHere') }}
      </Button>
    </template>
  </Modal>
</template>
