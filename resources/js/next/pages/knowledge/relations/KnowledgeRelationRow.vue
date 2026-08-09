<script setup lang="ts">
// KnowledgeRelationRow — one relation as the rail lists it.
//
// The row is read FROM THE ENTRY YOU ARE LOOKING AT. Standing on Acme, the statement about Anna
// reads "has member Anna", not "Anna is a member of" with the arrow turned around: an inverse
// wording is grammar the server already knows, and the direction is decided by data
// (`from_entry_id` / `to_entry_id`) rather than by which end happens to be loaded.
//
// READ-ONLY. A relation is asserted by the composer and approved by a person; editing, ending and
// deleting one are authoring, and authoring left this module. The row keeps the one affordance that
// is navigation rather than authorship — opening the entry at the far end.
import { computed } from 'vue';
import Badge from '../../../ui/primitives/Badge.vue';
import Button from '../../../ui/primitives/Button.vue';
import Text from '../../../ui/primitives/Text.vue';
import Tooltip from '../../../ui/overlay/Tooltip.vue';
import KnowledgeRelationSentence from './KnowledgeRelationSentence.vue';
import { directionFrom, entryTypeLabel, propertyLabel, relationEnds } from './relationLabels';
import { useI18n } from '../../../app/i18n';
import type { KnowledgeRelation } from '../types';

const props = defineProps<{
  relation: KnowledgeRelation;
  /** The entry whose page this is — decides which way the sentence reads. */
  viewpointEntryId: string;
  busy?: boolean;
}>();

const emit = defineEmits<{
  (e: 'open', slug: string): void;
}>();

const { t } = useI18n();

const direction = computed(() => directionFrom(props.relation, props.viewpointEntryId));
const ends = computed(() => relationEnds(props.relation, direction.value));

/** The far end — the one worth navigating to. The near end is the page you are already on. */
const target = computed(() => ends.value.object);

/**
 * The state, said in WORDS.
 *
 * Not a colour and not an opacity: a relation that ended is a different claim from one that holds,
 * and "ended 2024" has to survive being read by someone who cannot see the difference between a
 * muted row and an ordinary one. `retracted` gets its own word too — "this was never true" is not
 * a weaker version of "this stopped being true".
 */
const stateBadge = computed(() => {
  if (props.relation.state === 'retracted') {
    return { variant: 'danger' as const, icon: 'x-circle', text: t('knowledge.relations.retracted') };
  }
  if (props.relation.is_active) {
    return { variant: 'success' as const, icon: undefined, text: t('knowledge.relations.active') };
  }

  return {
    variant: 'neutral' as const,
    icon: 'clock',
    text: t('knowledge.relations.ended', '', {
      year: props.relation.valid_to?.slice(0, 4) ?? '',
    }),
  };
});

/** The properties row: `role: CTO`. Capped, because a row is not a record view. */
/**
 * The properties row: "Role: CTO". The KEY is translated — the set is closed and small (each verb
 * declares zero or one), so a raw `role` on screen was the one label a reader had to translate for
 * themselves. Capped because a row is not a record view.
 */
const propertyPairs = computed(() =>
  Object.entries(props.relation.properties ?? {})
    .filter(([, value]) => value != null && value !== '')
    .slice(0, 4)
    .map(([key, value]) => `${propertyLabel(key)}: ${value}`),
);

/**
 * The prose form of the statement, rebuilt here for the icon buttons' accessible names.
 *
 * "Edit" on its own is meaningless in a list of six relations; "Edit relation: Anna is a member of
 * Acme" is a control somebody can actually pick out. Built from the same helpers the sentence uses,
 * so the two never disagree.
 */
const sentenceText = computed(() => {
  const subject = ends.value.subject?.title ?? '';
  const object = ends.value.object?.title ?? '';

  return `${subject} ${object}`.trim();
});
</script>

<template>
  <li class="group/relation flex flex-col gap-next-1 rounded-next-md px-next-2 py-next-1_5 hover:bg-next-muted/40">
    <div class="flex min-w-0 items-start justify-between gap-next-2">
      <div class="flex min-w-0 flex-1 flex-col gap-next-1">
        <KnowledgeRelationSentence
          :subject="ends.subject"
          :object="ends.object"
          :relation-type="relation.relation_type"
          :label="relation.label"
          :inverse-label="relation.inverse_label"
          :symmetric="relation.symmetric"
          :direction="direction"
          :valid-from="relation.valid_from"
          :valid-to="relation.valid_to"
          :muted="!relation.is_active"
          size="sm"
        />

        <div class="flex flex-wrap items-center gap-next-1_5">
          <Badge :variant="stateBadge.variant" tone="subtle" :icon="stateBadge.icon" size="sm">
            {{ stateBadge.text }}
          </Badge>

          <!-- The far end's kind, as TEXT. Never drawn on the graph node — see G9. -->
          <Badge v-if="target?.entry_type" variant="neutral" tone="subtle" size="sm">
            {{ entryTypeLabel(target.entry_type) }}
          </Badge>

          <Badge v-for="pair in propertyPairs" :key="pair" variant="neutral" tone="subtle" size="sm">
            {{ pair }}
          </Badge>
        </div>

        <!-- The author's justification, for whoever reads this next. Secondary to the statement. -->
        <Text v-if="relation.description" variant="caption" tone="muted" :clamp="2">
          {{ relation.description }}
        </Text>
      </div>

      <!--
        Actions are ALWAYS in the DOM and revealed on hover / focus-within, never `v-if`-ed on
        hover: a control that does not exist until a pointer arrives is a control a keyboard can
        never reach.
      -->
      <div
        class="flex shrink-0 items-center gap-next-0_5 opacity-0 transition-opacity group-hover/relation:opacity-100 group-focus-within/relation:opacity-100"
      >
        <Tooltip v-if="target" :label="t('knowledge.relations.open')">
          <Button
            variant="ghost"
            size="icon-xs"
            icon="arrow-right"
            :aria-label="`${t('knowledge.relations.open')}: ${target.title}`"
            @click="emit('open', target.slug)"
          />
        </Tooltip>

</div>
    </div>
  </li>
</template>
