<script setup lang="ts">
// SessionTurn — the thin "message row" of the session chat (a MessageBubble, deliberately minimal).
//
// It provides ONLY a role gutter + alignment + an accessible author line; the CONTENT is a normal
// Card / MarkdownViewer passed via the default slot, so we never invent a bespoke speech-bubble system
// (reviewer rejects tails/color-only turns). Assistant = the primary `sparkles` bubble (matches the
// brand mark); user = an Avatar; system = a muted glyph. Assistant / system align left, user aligns
// right (standard chat), but content max-width stays generous — results are artifacts, not chat text.
//
// A11y: each turn is an <article> with an accessible label; the author is conveyed as TEXT (never by
// alignment / color alone) and the gutter glyph is decorative.
import { computed } from 'vue';
import Icon from '../../../ui/primitives/Icon.vue';
import Avatar from '../../../ui/primitives/Avatar.vue';

const props = withDefaults(
  defineProps<{
    role?: 'assistant' | 'user' | 'system';
    /** The visible + screen-reader author line (e.g. "Generator" / "Ty"). */
    author?: string;
    /** The whole turn's accessible label (defaults to the author). */
    ariaLabel?: string;
  }>(),
  { role: 'assistant' },
);

const isUser = computed(() => props.role === 'user');
</script>

<template>
  <article class="flex gap-next-3" :class="isUser ? 'flex-row-reverse' : ''" :aria-label="ariaLabel ?? author">
    <!-- Role gutter (decorative). -->
    <span
      v-if="role === 'assistant'"
      class="flex h-8 w-8 shrink-0 items-center justify-center rounded-next-lg bg-next-primary text-next-primary-foreground"
      aria-hidden="true"
    >
      <Icon name="sparkles" />
    </span>
    <Avatar v-else-if="isUser" :name="author" size="sm" class="shrink-0" />
    <span
      v-else
      class="flex h-8 w-8 shrink-0 items-center justify-center rounded-next-lg bg-next-muted text-next-muted-foreground"
      aria-hidden="true"
    >
      <Icon name="info" />
    </span>

    <div class="flex min-w-0 flex-1 flex-col gap-next-1_5" :class="isUser ? 'items-end' : ''">
      <div v-if="author || $slots.meta" class="flex items-center gap-next-2 text-next-xs text-next-muted-foreground">
        <span v-if="author">{{ author }}</span>
        <slot name="meta" />
      </div>
      <div class="w-full" :class="isUser ? 'flex flex-col items-end' : ''">
        <slot />
      </div>
    </div>
  </article>
</template>
