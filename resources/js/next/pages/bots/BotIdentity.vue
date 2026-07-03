<script setup lang="ts">
// BotIdentity — the shared bot identity glyph + name (next, Batch 2).
//
// A bot is NOT a user: it has no avatar, so it renders as a `sparkles` glyph in a
// primary-subtle circle (the same family as the Bots module icon) followed by the
// bot name and, optionally, a small "Bot" badge. Used everywhere a polymorphic
// identity surfaces — the task assignee (card / board / detail header) and bot
// comment authors. Distinct from a user Avatar so the two are never confused at a
// glance.
//
// Sizes mirror Avatar's xs/sm/md so it can sit inline beside user avatars. i18n +
// a11y: the glyph is decorative (aria-hidden); the name carries the meaning, and
// the badge text is localized.
import { computed } from 'vue';
import Icon from '../../ui/primitives/Icon.vue';
import Badge from '../../ui/primitives/Badge.vue';
import { useI18n } from '../../app/i18n';

const props = withDefaults(
  defineProps<{
    /** The bot's display name (null tolerated — shows a placeholder). */
    name?: string | null;
    size?: 'xs' | 'sm' | 'md';
    /** Show the small "Bot" badge next to the name. */
    showBadge?: boolean;
    /** Render only the glyph (no name / badge) — e.g. dense card footers. */
    glyphOnly?: boolean;
  }>(),
  { size: 'sm', showBadge: false, glyphOnly: false },
);

const { t } = useI18n();

// Match Avatar's pixel geometry so the glyph aligns with user avatars in a row.
const glyphClass = computed(() => {
  switch (props.size) {
    case 'xs':
      return 'h-5 w-5 text-next-xs';
    case 'md':
      return 'h-9 w-9 text-next-base';
    default:
      return 'h-7 w-7 text-next-sm';
  }
});

const displayName = computed(() => props.name?.trim() || t('bots.identity.unknown', 'Bot'));
const ariaLabel = computed(() =>
  t('bots.identity.aria', 'Bot: {name}', { name: displayName.value }),
);
</script>

<template>
  <span class="inline-flex min-w-0 items-center gap-next-2" :aria-label="ariaLabel">
    <span
      class="flex shrink-0 items-center justify-center rounded-next-full bg-next-primary-subtle text-next-primary-subtle-foreground"
      :class="glyphClass"
      aria-hidden="true"
    >
      <Icon name="sparkles" />
    </span>
    <template v-if="!glyphOnly">
      <span class="min-w-0 truncate text-next-sm text-next-fg">{{ displayName }}</span>
      <Badge v-if="showBadge" variant="primary" tone="subtle" size="sm" icon="sparkles">
        {{ t('bots.identity.badge', 'Bot') }}
      </Badge>
    </template>
  </span>
</template>
