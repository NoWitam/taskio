<script setup lang="ts">
// CreatorBadge — the shared polymorphic "creator" identity glyph + name (Phase 3).
//
// The backend `creator` field is a discriminated union (user | workflow_run | bot)
// that may also be null / omitted. This component owns the single switch so the
// detail/identity sites don't each re-implement it:
//   • user         → a real Avatar (image → initials → user glyph) + the name.
//   • workflow_run → a `workflow` glyph in an info-subtle circle + a localized
//                    "Automatyzacja: <workflow>" label (generic when no name).
//   • bot          → a `sparkles` glyph in a primary-subtle circle + the bot name
//                    (matches BotIdentity so a bot is never confused with a user).
//   • null/omitted → a generic user glyph + a neutral placeholder (never blank).
//
// The glyph circles mirror Avatar's xs/sm/md pixel geometry so a bot/automation
// glyph aligns with user avatars in a row. Text/label composition + the icon come
// from the shared `creator` helpers (single source of truth, unit-testable).
//
// a11y: the glyph is decorative (aria-hidden); the visible name carries meaning.
import { computed } from 'vue';
import Icon from '../primitives/Icon.vue';
import Avatar from '../primitives/Avatar.vue';
import { useI18n } from '../../app/i18n';
import { creatorLabel, type Creator } from './creator';

const props = withDefaults(
  defineProps<{
    /** The polymorphic creator (null/undefined → the placeholder identity). */
    creator?: Creator | null;
    /** Glyph/avatar size; the label inherits the surrounding text size. */
    size?: 'xs' | 'sm' | 'md';
    /** Render only the leading glyph/avatar (e.g. a card's leading column). */
    glyphOnly?: boolean;
    /** Placeholder TEXT for a null/omitted creator (default → "System"). */
    fallback?: string;
  }>(),
  { size: 'sm', glyphOnly: false },
);

const { t } = useI18n();

const isBot = computed(() => props.creator?.type === 'bot');
const isAutomation = computed(() => props.creator?.type === 'workflow_run');

const label = computed(() => creatorLabel(props.creator, t, props.fallback));

// Match Avatar's pixel geometry so the non-user glyphs line up with user avatars.
const glyphClass = computed(() => {
  switch (props.size) {
    case 'xs':
      return 'h-6 w-6 text-next-xs';
    case 'md':
      return 'h-10 w-10 text-next-base';
    default:
      return 'h-8 w-8 text-next-sm';
  }
});

// The user avatar src (only the user variant carries one).
const avatarSrc = computed(() =>
  props.creator && props.creator.type === 'user' ? props.creator.avatar ?? undefined : undefined,
);
const avatarName = computed(() =>
  props.creator && (props.creator.type === 'user' || props.creator.type === 'bot')
    ? props.creator.name
    : undefined,
);

// When rendering the GLYPH ONLY, the bot/automation circles are decorative, so the
// wrapper carries the accessible name. A user's Avatar self-labels (role=img), so
// we leave the wrapper unlabeled there to avoid a nested/duplicate label.
const glyphAria = computed(() => {
  if (!props.glyphOnly) return undefined;
  if (isBot.value) return t('bots.identity.aria', 'Bot: {name}', { name: label.value });
  if (isAutomation.value) return label.value;
  return undefined;
});
</script>

<template>
  <span class="inline-flex min-w-0 items-center gap-next-2" :aria-label="glyphAria">
    <!-- Bot → sparkles circle (primary-subtle), matching BotIdentity. -->
    <span
      v-if="isBot"
      class="flex shrink-0 items-center justify-center rounded-next-full bg-next-primary-subtle text-next-primary-subtle-foreground"
      :class="glyphClass"
      aria-hidden="true"
    >
      <Icon name="sparkles" />
    </span>
    <!-- Automation (workflow_run) → workflow glyph in an info-subtle circle. -->
    <span
      v-else-if="isAutomation"
      class="flex shrink-0 items-center justify-center rounded-next-full bg-next-info-subtle text-next-info-subtle-foreground"
      :class="glyphClass"
      aria-hidden="true"
    >
      <Icon name="workflow" />
    </span>
    <!-- User (or null placeholder) → a real Avatar (image → initials → user glyph). -->
    <Avatar
      v-else
      :name="avatarName"
      :src="avatarSrc"
      :size="size"
      class="shrink-0"
    />

    <span
      v-if="!glyphOnly"
      class="min-w-0 truncate text-next-fg"
    >{{ label }}</span>
  </span>
</template>
