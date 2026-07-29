<script setup lang="ts">
// BotAuthorChip — the "Authored by {bot}" chip for a DELEGATED generation session (R2 sub-stage 3).
//
// ADDITIVE identity: a delegated session still shows the human as owner/creator (via CreatorBadge); THIS chip
// is the EXTRA marker that a bot authored the content. It mirrors CreatorBadge's bot styling — the `sparkles`
// glyph in a primary-subtle circle (so a bot is never confused with a user) — but prefixes the name with a
// localized "Authored by" so its meaning is explicit and never carried by color alone (icon + text).
//
// a11y: the glyph is decorative (aria-hidden); the visible "Authored by {name}" text carries the meaning.
import { computed } from 'vue';
import Icon from '../../../ui/primitives/Icon.vue';
import { useI18n } from '../../../app/i18n';
import type { BotAuthor } from '../sessionTypes';

const props = withDefaults(
  defineProps<{
    /** The snapshotted bot author ({id,name,icon}). */
    author: BotAuthor;
    /** Glyph size; the label inherits the surrounding text size. */
    size?: 'xs' | 'sm';
  }>(),
  { size: 'sm' },
);

const { t } = useI18n();

// Match Avatar/CreatorBadge pixel geometry so the glyph lines up with a user avatar in a row.
const glyphClass = computed(() => (props.size === 'xs' ? 'h-6 w-6 text-next-xs' : 'h-8 w-8 text-next-sm'));

const label = computed(() =>
  t('generator.sessions.delegate.authoredBy', 'Authored by {name}', { name: props.author.name }),
);
</script>

<template>
  <span class="inline-flex min-w-0 items-center gap-next-1_5" :title="label">
    <span
      class="flex shrink-0 items-center justify-center rounded-next-full bg-next-primary-subtle text-next-primary-subtle-foreground"
      :class="glyphClass"
      aria-hidden="true"
    >
      <Icon name="sparkles" />
    </span>
    <span class="min-w-0 truncate text-next-primary">{{ label }}</span>
  </span>
</template>
