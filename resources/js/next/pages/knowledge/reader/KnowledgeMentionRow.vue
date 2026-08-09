<script setup lang="ts">
// KnowledgeMentionRow — one MENTION edge: the target's name appears in someone's text.
//
// Sibling of KnowledgeSimilarRow, and deliberately NOT the same component: a mention has no score
// to draw a bar from, and it has something similarity does not — a DIRECTION and the actual words.
// Sharing one row would mean a component whose every field is conditional on a kind flag.
//
// Two rules it exists to enforce:
//
// 1. THE DIRECTION IS WRITTEN OUT. "Mentions" and "mentioned by" are different facts, and the pair
//    can carry both as two independent edges. A row that only said "mention" would let a reader
//    conclude a relationship is mutual when it is one-way.
// 2. DISMISSIBILITY IS THE SERVER'S CALL. `can_be_dismissed` comes from the link resource; nothing
//    here re-derives it from `source`, so a future edge kind needs no change in this file.
import { computed } from 'vue';
import Text from '../../../ui/primitives/Text.vue';
import Button from '../../../ui/primitives/Button.vue';
import Badge from '../../../ui/primitives/Badge.vue';
import Link from '../../../ui/primitives/Link.vue';
import { useI18n } from '../../../app/i18n';
import type { MentionNeighbour } from '../entryMeta';

const props = defineProps<{ row: MentionNeighbour; busy?: boolean }>();

const emit = defineEmits<{
  (e: 'open', slug: string): void;
  (e: 'dismiss', linkId: string): void;
  (e: 'undo', linkId: string): void;
}>();

const { t } = useI18n();

const directionLabel = computed(() =>
  t(props.row.direction === 'outgoing' ? 'knowledge.mentions.outgoing' : 'knowledge.mentions.incoming'),
);
</script>

<template>
  <li class="flex flex-col gap-next-1 rounded-next-md px-next-2 py-next-2 hover:bg-next-muted/50">
    <div class="flex min-w-0 items-center gap-next-2">
      <!-- `plain`: the row's colour is its STATE (struck through + muted once dismissed), so the link
           must inherit it rather than paint itself primary. -->
      <Link
        :href="`#${row.target.slug}`"
        variant="plain"
        class="min-w-0 flex-1 truncate text-next-sm"
        :class="row.dismissed ? 'text-next-muted-foreground line-through' : ''"
        @click.prevent="emit('open', row.target.slug)"
      >
        {{ row.target.title }}
      </Link>

      <!-- The direction, in words — never left to an arrow glyph alone. -->
      <Badge variant="neutral" tone="subtle" size="sm" class="shrink-0">{{ directionLabel }}</Badge>


      <Button
        v-if="row.canDismiss && !row.dismissed"
        variant="ghost"
        size="icon-xs"
        leading-icon="x"
        :disabled="busy"
        :aria-label="t('knowledge.mentions.dismiss', '', { title: row.target.title })"
        @click="emit('dismiss', row.linkId)"
      />
      <Button
        v-else-if="row.canDismiss"
        variant="ghost"
        size="icon-xs"
        leading-icon="rotate-ccw"
        :disabled="busy"
        :aria-label="t('knowledge.mentions.restore', '', { title: row.target.title })"
        @click="emit('undo', row.linkId)"
      />
    </div>

    <!-- The mentioning words, quoted. Only an OUTGOING mention can show them: the offsets index
         the source entry's content, and for an incoming one that body is not loaded here. -->
    <Text
      v-if="row.text && !row.dismissed"
      variant="caption"
      tone="muted"
      class="min-w-0 break-words"
      :clamp="2"
    >
      <!-- Quotation marks come from i18n: „…” is Polish, and English wants "…". Baked into the
           template they rendered Polish punctuation to every reader of the English UI. -->
      {{ t('knowledge.mentions.quote', '', { text: row.text }) }}
    </Text>
  </li>
</template>
