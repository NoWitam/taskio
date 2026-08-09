<script setup lang="ts">
// KnowledgeRelationSentence — ONE component, every surface.
//
// A relation is a SENTENCE. Nobody reads `(lukasz-barszcz, member_of, acme)`, and the raw
// predicate id appears nowhere in the UI. This component is the single truth about how that
// sentence looks, used by the rail panel, the graph neighbour list, the editor's live preview and
// the composer's review. If the phrasing lived in each of those, they would drift apart within two
// batches and the same fact would read four ways.
//
// ------------------------------------------------------------------------------------------------
// TWO LAYERS, DELIBERATELY DIFFERENT.
//
//   VISUAL     a dense, scannable line: bold ends, the verb as a Badge, an arrow for direction.
//   ACCESSIBLE the same statement as prose, on `aria-label` of the whole line.
//
// The arrow and the Badge are `aria-hidden`, because a screen reader announcing "Anna member of
// arrow right Acme" is worse than useless. Both layers are built from the SAME i18n template and
// the same resolved labels, so they cannot say different things.
//
// The direction is DATA. A symmetric verb (`knows`, `opposes`) words identically both ways and is
// drawn without an arrow — read off `symmetric`, never guessed by comparing label strings.
import { computed } from 'vue';
import Badge from '../../../ui/primitives/Badge.vue';
import Icon from '../../../ui/primitives/Icon.vue';
import { useI18n } from '../../../app/i18n';
import { relationPredicate, type RelationDirection } from './relationLabels';
import type { KnowledgeRelationEnd, KnowledgeRelationTypeId } from '../types';

const { t } = useI18n();

/**
 * The minimum a sentence needs. NOT `KnowledgeRelation` — the composer's review renders proposals
 * that have no id, no state and handles instead of entries, and the editor renders a preview of
 * something that does not exist at all. Typing this to the persisted shape would have forced both
 * to fake fields, which is how a "canonical" component stops being used by half its callers.
 */
const props = withDefaults(
  defineProps<{
    subject: Pick<KnowledgeRelationEnd, 'title' | 'entry_type'> | null;
    object: Pick<KnowledgeRelationEnd, 'title' | 'entry_type'> | null;
    relationType: KnowledgeRelationTypeId | string | null;
    /** The server's own wording, used only when this build does not know the verb. */
    label?: string | null;
    inverseLabel?: string | null;
    symmetric?: boolean;
    /** Which way to read it. `inverse` swaps nothing here — the CALLER passes ends in order. */
    direction?: RelationDirection;
    validFrom?: string | null;
    validTo?: string | null;
    /** Rendered muted, for the "will end" / "will be replaced" readings in the review. */
    muted?: boolean;
    size?: 'sm' | 'md';
  }>(),
  {
    label: null,
    inverseLabel: null,
    symmetric: false,
    direction: 'forward',
    validFrom: null,
    validTo: null,
    muted: false,
    size: 'md',
  },
);

const predicate = computed(() =>
  relationPredicate(
    {
      relation_type: props.relationType as KnowledgeRelationTypeId | null,
      label: props.label,
      inverse_label: props.inverseLabel,
      symmetric: props.symmetric,
    },
    props.direction,
  ),
);

/**
 * A missing end is rendered as an em dash rather than as an empty gap.
 *
 * It happens for real: the graph sends relation edges whose ends are ids, and a review proposal can
 * point at a draft that has no title yet. A blank there reads as a rendering fault; a dash reads as
 * "this end is not named", which is what it is.
 */
const subjectText = computed(() => props.subject?.title || '—');
const objectText = computed(() => props.object?.title || '—');

/**
 * Dates are shown to the MONTH in the visible line ("01.2024") but carry the full ISO date in
 * `datetime`, so the machine-readable value stays exact while the human-readable one stays short.
 */
function shortDate(iso: string | null): string {
  if (!iso) return '';
  const [year, month] = iso.split('-');

  return month ? `${month}.${year}` : year;
}

const fromText = computed(() =>
  props.validFrom ? t('knowledge.relations.from', '', { date: shortDate(props.validFrom) }) : '',
);
const untilText = computed(() =>
  props.validTo ? t('knowledge.relations.until', '', { date: shortDate(props.validTo) }) : '',
);

/**
 * The accessible sentence — prose, no arrows, no brackets.
 *
 * Three templates rather than string concatenation, because where the date clause sits in a
 * sentence is a per-language decision and gluing ", from X" onto the end assumes English/Polish
 * word order for every future locale.
 */
const sentence = computed(() => {
  const parts = {
    subject: subjectText.value,
    predicate: predicate.value,
    object: objectText.value,
  };

  if (props.validTo) {
    return t('knowledge.relations.sentenceEnded', '', {
      ...parts,
      until: shortDate(props.validTo),
    });
  }

  if (props.validFrom) {
    return t('knowledge.relations.sentenceDated', '', {
      ...parts,
      from: shortDate(props.validFrom),
    });
  }

  return t('knowledge.relations.sentence', '', parts);
});

/** Exposed so a host can put the same prose on its own controls ("Discard relation: {sentence}"). */
defineExpose({ sentence });

const textSize = computed(() => (props.size === 'sm' ? 'text-next-xs' : 'text-next-sm'));
</script>

<template>
  <span class="inline-flex min-w-0 items-center">
    <!--
      THE ACCESSIBLE LAYER: the statement as prose, in the a11y tree and nowhere on screen.
      Deliberately NOT `aria-label` on the wrapper — a bare <span> is a generic with no role, and
      an accessible name on a generic is ignored by most screen readers. Visually-hidden text is
      the pattern that actually gets announced, and it is what Link/Spinner already use here.
    -->
    <span class="next-sr-only">{{ sentence }}</span>

    <!--
      THE VISUAL LAYER, hidden from the a11y tree as a whole. A reader wants the statement, not
      five fragments of it, and the arrow would be read out as "arrow right" mid-sentence.
    -->
    <span
      class="inline-flex min-w-0 flex-wrap items-center gap-next-1_5"
      :class="[textSize, muted ? 'text-next-muted-foreground' : '']"
      aria-hidden="true"
    >
    <span class="truncate font-next-medium" aria-hidden="true">{{ subjectText }}</span>

    <Badge
      variant="primary"
      tone="subtle"
      size="sm"
      truncate
      aria-hidden="true"
      class="shrink-0"
    >
      {{ predicate }}
      <!--
        No arrow on a symmetric verb: "knows" carries no direction, and an arrowhead would assert
        one that the stored row does not mean. The stored order of a symmetric relation is
        canonical (smaller id first), not editorial.
      -->
      <Icon v-if="!symmetric" name="arrow-right" class="ml-next-0_5 text-[0.85em]" />
    </Badge>

    <span class="truncate font-next-medium" aria-hidden="true">{{ objectText }}</span>

    <!--
      The validity clause. `<time datetime>` carries the FULL ISO date while the text shows the
      month — the exact value stays machine-readable even though the line stays short.
    -->
    <span
      v-if="fromText || untilText"
      class="shrink-0 text-next-xs text-next-muted-foreground"
      aria-hidden="true"
    >
      <span aria-hidden="true">·</span>
      <time v-if="fromText" :datetime="validFrom ?? undefined"> {{ fromText }}</time>
      <time v-if="untilText" :datetime="validTo ?? undefined"> {{ untilText }}</time>
    </span>
    </span>
  </span>
</template>

<style scoped>
/* Local copy, matching Link.vue / Spinner.vue — the design system has no global utility. */
.next-sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border-width: 0;
}
</style>
