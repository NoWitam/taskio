<script setup lang="ts">
// KnowledgeFactChecklist — what the material says happened, beside what was proposed.
//
// ------------------------------------------------------------------------------------------------
// WHY THIS EXISTS, AND WHY IT CANNOT BE AUTOMATED.
//
// Dramatic facts were disappearing out of the source text — a drinking incident, a wave of abuse —
// and nothing anywhere said so. The reviewer read eight plausible entries, found nothing wrong with
// any of them, and approved a base that had quietly lost the part that mattered.
//
// No check can catch that on its own. A model that writes up the departure date and omits the
// incident reports its coverage perfectly honestly: it did cover what it chose to cover. The
// omission is only visible to somebody holding the original text, which makes the PERSON the check
// — and a person cannot be the check without something to read.
//
// So this is a READING AID, never a verdict. It says what the material contained; whether an entry
// did it justice is a judgement it does not make and must not appear to make.
//
// ------------------------------------------------------------------------------------------------
// READING MATERIAL, NOT A VERDICT ON COVERAGE.
//
// This list used to flag the facts no draft had claimed — sorted to the top, tinted, badged "not
// found in any entry". Measurement killed it: the model ignores the field it was supposed to fill,
// so the claims came back empty for EVERY fact and the panel shouted "nothing was covered" over
// entries that demonstrably covered half the material. A warning that fires nine times out of nine
// is not a warning; it teaches people to stop reading the panel, and it would have taken the list's
// real value down with it.
//
// So the facts are now presented plainly, IN THE MATERIAL'S OWN ORDER, and the panel judges
// nothing. Its value never depended on the verdict: it is what surfaced the drinking incident the
// entries had silently dropped, and it does that by being readable beside the source.
//
// WHERE A FACT LANDED is now on the wire, and `covered_by` is a LIST for a reason that matters: an
// episode between two people belongs in BOTH their chronicles, so "Łukasz przeprosił influencerkę"
// is one fact two entries rightly cover. Rendering only the first would make a correct answer look
// partial and send the reviewer hunting for a gap that is not there.
//
// WHAT IT STILL DOES NOT CLAIM: that a covered fact was covered WELL. The claim is the run's own
// report — an entry recording the date and omitting what happened reports coverage honestly — so a
// row without a warning says "the run did not report this missing", never "this was handled". That
// distinction is the whole reason a person is reading the list at all.
import { computed } from 'vue';
import Surface from '../../../ui/layout/Surface.vue';
import Badge from '../../../ui/primitives/Badge.vue';
import Icon from '../../../ui/primitives/Icon.vue';
import Button from '../../../ui/primitives/Button.vue';
import Text from '../../../ui/primitives/Text.vue';
import EmptyState from '../../../ui/data/EmptyState.vue';
import { useI18n } from '../../../app/i18n';
import type { KnowledgeFact, KnowledgeRunNote } from '../types';

const props = withDefaults(
  defineProps<{
    facts?: KnowledgeFact[];
    /** The run notes — the only place that says which facts went unclaimed. */
    notes?: KnowledgeRunNote[];
  }>(),
  { facts: () => [], notes: () => [] },
);

const emit = defineEmits<{
  /** Point at the draft that claimed this fact — a card on this board, not a page. */
  (e: 'open', draftId: string): void;
}>();

const { t } = useI18n();

/**
 * The reading could not run at all — a different thing from a text with no facts in it, and the
 * only one of the three edge states that is about the TOOL rather than about the material.
 */
const unavailable = computed(() => props.notes.some((note) => note.code === 'facts_unavailable'));

/** The reading returned more than the cap allows, so this list is the first N. */
const truncatedMax = computed<number | null>(() => {
  const note = props.notes.find((item) => item.code === 'facts_truncated');
  if (!note) return null;

  return typeof note.max === 'number' ? note.max : null;
});

/**
 * THE MATERIAL'S OWN ORDER, untouched.
 *
 * Nothing is floated, because nothing here is ranked any more — and the source's sequence is the
 * one the reviewer is reading against, so any reordering makes the comparison harder rather than
 * easier.
 */
const ordered = computed(() => props.facts);
</script>

<template>
  <Surface bg="card" border radius="lg" class="flex flex-col gap-next-3 p-next-4" data-fact-checklist>
    <div class="flex flex-wrap items-center gap-next-2">
      <Icon name="check-circle" class="shrink-0 text-next-muted-foreground" aria-hidden="true" />
      <h2 class="text-next-base font-next-semibold text-next-fg">
        {{ t('knowledge.compose.facts.title') }}
      </h2>

    </div>

    <Text variant="caption" tone="muted">{{ t('knowledge.compose.facts.subtitle') }}</Text>

    <!--
      THE READING DID NOT RUN. A statement about the tool, said calmly: nothing is wrong with the
      material and nothing was lost, there is simply no checklist this time. Rendering it as a
      failure would teach the reviewer to distrust a run that is fine.
    -->
    <Text v-if="unavailable" variant="caption" tone="muted" data-facts-unavailable>
      {{ t('knowledge.compose.facts.unavailable') }}
    </Text>

    <!--
      THE MATERIAL HAD NO FACTS. Also not a fault — plenty of source text is definitional rather
      than eventful — and worded so it cannot be read as one.
    -->
    <EmptyState
      v-else-if="ordered.length === 0"
      size="sm"
      icon="file-text"
      :title="t('knowledge.compose.facts.empty')"
      :description="t('knowledge.compose.facts.emptyHint')"
      data-facts-empty
    />

    <ul v-else class="flex flex-col gap-next-1">
      <li
        v-for="fact in ordered"
        :key="fact.id"
        class="flex min-w-0 items-start gap-next-2 rounded-next-md px-next-2 py-next-1_5"
        :data-fact="fact.id"
      >
        <Icon name="circle" class="mt-next-0_5 shrink-0 text-next-muted-foreground" aria-hidden="true" />

        <span class="flex min-w-0 flex-1 flex-col gap-next-0_5">
          <span class="text-next-sm text-next-fg">{{ fact.text }}</span>

          <span class="flex flex-wrap items-center gap-next-1_5">
            <!--
              THE SOURCE'S OWN WORDING, never reformatted. The reviewer is comparing this against
              the text they wrote; a date this screen had already interpreted would be one more
              thing to take on trust instead of the thing they can check.
            -->
            <Badge v-if="fact.date" variant="neutral" tone="subtle" size="sm" data-fact-date>
              {{ fact.date }}
            </Badge>
            <Badge
              v-for="subject in fact.subjects"
              :key="subject"
              variant="neutral"
              tone="subtle"
              size="sm"
            >
              {{ subject }}
            </Badge>

          </span>
        </span>

        <!--
          WHERE IT LANDED — every entry that claimed it, each one a way to go and read it.
          Rendered in the column that was left free for exactly this, so the row did not have to
          be rebuilt. Absent on older sessions, where it renders as nothing: nobody asked those
          runs to record a claim, so silence there is accurate rather than a gap.
        -->
        <span
          v-if="(fact.covered_by?.length ?? 0) > 0"
          class="flex shrink-0 flex-wrap items-center gap-next-1"
          :data-covered-by="fact.id"
        >
          <Text variant="caption" tone="muted">{{ t('knowledge.compose.facts.coveredBy') }}</Text>
          <Button
            v-for="claim in fact.covered_by"
            :key="claim.id"
            variant="link"
            size="xs"
            :data-claim="claim.id"
            :aria-label="t('knowledge.compose.facts.openClaim', '', { title: claim.title })"
            @click="emit('open', claim.id)"
          >
            {{ claim.title }}
          </Button>
        </span>
      </li>
    </ul>

    <!--
      THE LIST WAS CUT, and by the TOOL rather than by the author. Said explicitly, because a
      reviewer who counts thirty and stops is a reviewer who thinks they have seen everything.
    -->
    <Text v-if="truncatedMax !== null" variant="caption" tone="muted" data-facts-truncated>
      {{ t('knowledge.compose.facts.truncated', '', { max: truncatedMax }) }}
    </Text>
  </Surface>
</template>
