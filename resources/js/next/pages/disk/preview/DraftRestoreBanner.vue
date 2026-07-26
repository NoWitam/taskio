<script setup lang="ts">
// DraftRestoreBanner — the "you have an unsaved draft" prompt shown on reopen, above a stage's editor.
//
// A thin composition of the design-system Alert (inline, role/aria + dark-mode handled there): it turns
// the draft's `updatedAt` into a localized relative time, and offers Restore (primary) / Discard (ghost).
// Always the `modified` variant — the PROJECT-WIDE "unsaved/draft" violet shared with the tile badge —
// so a draft reads as the same thing everywhere; when `stale` (the file changed since the draft was
// saved) a caveat line is added inside. Reused by BOTH the image and text stages.
import { computed } from 'vue';
import Alert from '../../../ui/feedback/Alert.vue';
import Button from '../../../ui/primitives/Button.vue';
import { useI18n } from '../../../app/i18n';

const props = defineProps<{
  /** The draft's `updated_at` (ISO). */
  updatedAt: string;
  /** The underlying file changed since the draft was saved. */
  stale: boolean;
}>();

const emit = defineEmits<{ (e: 'restore'): void; (e: 'discard'): void }>();

const { t, locale } = useI18n();

/** `updatedAt` as a coarse, locale-aware "N minutes ago". Static (a reopen snapshot, not a live clock). */
const relativeTime = computed(() => {
  const then = new Date(props.updatedAt).getTime();
  if (Number.isNaN(then)) return '';
  const rtf = new Intl.RelativeTimeFormat(locale.value, { numeric: 'auto' });
  const sec = Math.round((then - Date.now()) / 1000); // negative = in the past
  if (Math.abs(sec) < 60) return rtf.format(sec, 'second');
  const min = Math.round(sec / 60);
  if (Math.abs(min) < 60) return rtf.format(min, 'minute');
  const hr = Math.round(min / 60);
  if (Math.abs(hr) < 24) return rtf.format(hr, 'hour');
  return rtf.format(Math.round(hr / 24), 'day');
});

const title = computed(() =>
  t('disk.preview.draft.bannerTitle', 'You have unsaved changes — last edited {time}.', { time: relativeTime.value }),
);
</script>

<template>
  <Alert variant="modified" size="sm" :title="title">
    <p v-if="stale">{{ t('disk.preview.draft.staleWarning', 'The file has changed since this draft was saved.') }}</p>

    <template #actions>
      <Button variant="primary" size="sm" @click="emit('restore')">
        {{ t('disk.preview.draft.restore', 'Restore') }}
      </Button>
      <Button variant="ghost" size="sm" @click="emit('discard')">
        {{ t('disk.preview.draft.discard', 'Discard') }}
      </Button>
    </template>
  </Alert>
</template>
