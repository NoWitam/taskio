<script setup lang="ts">
// KnowledgeSimilarRow — one machine-proposed "these two look related" edge.
//
// Two rules this row exists to enforce:
//
// 1. THE PERCENTAGE IS ALWAYS TEXT. The Progress bar is decoration on top of a number that is
//    written out, because a bar alone encodes a value in length + color only. The number shown is
//    `matched score` — a real cosine similarity — never the fused `rrf_score`, which the backend
//    states is a rank score and not a percentage.
//
// 2. A DISMISSED ROW STAYS. Dismissal is a reversible stamp, so the row remains visible, struck
//    through, carrying its own undo. A row that vanished would leave the undo attached to nothing
//    and make a low-stakes, reversible act feel destructive — which is also why this is a toast +
//    undo affordance rather than a confirmation dialog.
import { computed, ref } from 'vue';
import Text from '../../../ui/primitives/Text.vue';
import Button from '../../../ui/primitives/Button.vue';
import Link from '../../../ui/primitives/Link.vue';
import Progress from '../../../ui/feedback/Progress.vue';
import Surface from '../../../ui/layout/Surface.vue';
import { scorePercent } from '../search/highlightSegments';
import { useI18n } from '../../../app/i18n';
import type { SimilarNeighbour } from '../entryMeta';

const props = defineProps<{ row: SimilarNeighbour; busy?: boolean }>();

const emit = defineEmits<{
  (e: 'open', slug: string): void;
  (e: 'dismiss', linkId: string): void;
  (e: 'undo', linkId: string): void;
}>();

const { t } = useI18n();

const percent = computed(() => scorePercent(props.row.score));
const scoreLabel = computed(() =>
  percent.value == null ? null : t('knowledge.similar.score', '', { percent: percent.value }),
);

const showEvidence = ref(false);

/**
 * The evidence, flattened to label/value pairs. The backend stores chunk ORDINALS (a citation
 * address), and whatever else it decides to add later shows up here automatically — the UI renders
 * what it is given and invents no field names.
 */
const evidenceRows = computed(() =>
  Object.entries(props.row.evidence ?? {}).map(([key, value]) => ({
    key,
    value: Array.isArray(value) ? value.join(', ') : String(value ?? '—'),
  })),
);
</script>

<template>
  <li class="flex flex-col gap-next-1_5 rounded-next-md px-next-2 py-next-2 hover:bg-next-muted/50">
    <div class="flex min-w-0 items-center gap-next-2">
      <!-- `plain`: a dismissed row stays visible and says so in its own colour — the link inherits it
           instead of forcing the primary tone over the struck-through state. -->
      <Link
        :href="`#${row.target.slug}`"
        variant="plain"
        class="min-w-0 flex-1 truncate text-next-sm"
        :class="row.dismissed ? 'text-next-muted-foreground line-through' : ''"
        @click.prevent="emit('open', row.target.slug)"
      >
        {{ row.target.title }}
      </Link>

      <!--
        The TRANSLATED label, visibly. This component already built `scoreLabel` and then used it
        only for an `aria-label`, printing a bare `{{ percent }}%` on screen — so the sighted
        reading and the spoken one were different strings, and the search card (which renders the
        translated one) disagreed with this row about how a score is written.
      -->
      <Text v-if="scoreLabel" variant="caption" tone="muted" class="shrink-0 tabular-nums">
        {{ scoreLabel }}
      </Text>


      <!-- Dismiss / undo: the same slot, so the control never moves between states. -->
      <Button
        v-if="!row.dismissed"
        variant="ghost"
        size="icon-xs"
        leading-icon="x"
        :disabled="busy"
        :aria-label="t('knowledge.similar.dismiss', '', { title: row.target.title })"
        @click="emit('dismiss', row.linkId)"
      />
      <Button
        v-else
        variant="ghost"
        size="icon-xs"
        leading-icon="rotate-ccw"
        :disabled="busy"
        :aria-label="t('knowledge.similar.restore', '', { title: row.target.title })"
        @click="emit('undo', row.linkId)"
      />
    </div>

    <Progress
      v-if="percent != null && !row.dismissed"
      :value="percent"
      size="sm"
      tone="primary"
      :aria-label="scoreLabel ?? undefined"
    />

    <div v-if="evidenceRows.length > 0 && !row.dismissed">
      <Button
        variant="link"
        size="xs"
        :aria-expanded="showEvidence"
        @click="showEvidence = !showEvidence"
      >
        {{ t('knowledge.similar.why') }}
      </Button>

      <Surface v-if="showEvidence" bg="muted" radius="md" class="mt-next-1 flex flex-col gap-next-1 p-next-2">
        <div v-for="item in evidenceRows" :key="item.key" class="flex gap-next-2 text-next-xs">
          <span class="shrink-0 text-next-muted-foreground">{{ item.key }}</span>
          <span class="min-w-0 break-words">{{ item.value }}</span>
        </div>
      </Surface>
    </div>
  </li>
</template>
