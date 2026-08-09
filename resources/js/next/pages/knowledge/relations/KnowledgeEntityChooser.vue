<script setup lang="ts">
// KnowledgeEntityChooser — "which Anna did you mean?", asked WHERE THE ANSWER GOES.
//
// The obvious layout is a "needs your attention" section above the review. It costs the reviewer a
// round trip per question: read the relation, go up, pick a person, come back, read the relation
// again to check it now says the right thing. Instead the unsettled name renders AS THE CHOICE, in
// the place the entity would have occupied — the question and its context are the same glance.
//
// THE CANDIDATES NEED THEIR CONTEXT. Two entries titled "Anna Kowalska" are indistinguishable by
// title, and a picker offering two identical labels is asking the reviewer to guess. The server
// sends a `context` snippet for exactly this, and rendering the options without it would turn a
// decision into a coin flip while looking like a real choice.
//
// "None of these" is a real answer, not a cancel. It means the composer found something the base
// does not contain — and the row then goes unsaved rather than inventing an entity nobody
// approved, which would slip a new page past the review gate.
import { computed, ref } from 'vue';
import Badge from '../../../ui/primitives/Badge.vue';
import Select from '../../../ui/forms/Select.vue';
import Text from '../../../ui/primitives/Text.vue';
import { entryTypeLabel } from './relationLabels';
import { useI18n } from '../../../app/i18n';
import type { KnowledgeAmbiguousMention } from '../types';

const props = withDefaults(
  defineProps<{
    mention: KnowledgeAmbiguousMention;
    /** How many OTHER proposals this one answer settles. */
    alsoApplies?: number;
    disabled?: boolean;
  }>(),
  { alsoApplies: 0, disabled: false },
);

const emit = defineEmits<{
  /** The chosen slug, or `null` for "none of these" — a decision either way. */
  (e: 'resolve', slug: string | null): void;
}>();

const { t } = useI18n();

const NONE = '__none__';

const picked = ref<string>('');

const options = computed(() => [
  ...props.mention.candidates.map((candidate) => ({
    value: candidate.slug,
    label: candidate.title,
    type: candidate.entry_type,
  })),
  { value: NONE, label: t('knowledge.relations.ambiguity.none'), type: null },
]);

function onPick(value: string): void {
  picked.value = value;
  emit('resolve', value === NONE ? null : value);
}

/** A real `<label>`, not a placeholder — a placeholder disappears the moment you interact. */
const label = computed(() =>
  t('knowledge.relations.ambiguity.label', '', { handle: props.mention.text }),
);
</script>

<template>
  <span class="inline-flex min-w-0 flex-col gap-next-0_5">
    <Select
      :model-value="picked"
      size="sm"
      :options="options"
      :aria-label="label"
      :placeholder="t('knowledge.relations.ambiguity.placeholder', '', { handle: mention.text })"
      :disabled="disabled"
      data-entity-chooser
      @update:model-value="(v: string) => onPick(v)"
    >
      <!--
        Title + kind + the DISTINGUISHING SNIPPET. Without the third, two entries with the same
        name are one option repeated twice.
      -->
      <template #option="{ option }">
        <span class="flex min-w-0 flex-col gap-next-0_5">
          <span class="flex items-center gap-next-1_5">
            <span class="truncate">{{ option.label }}</span>
            <Badge v-if="option.type" variant="neutral" tone="subtle" size="sm">
              {{ entryTypeLabel(option.type) }}
            </Badge>
          </span>
        </span>
      </template>
    </Select>

    <!-- The context the composer saw. The reason this is a choice rather than a guess. -->
    <Text v-if="mention.context" variant="caption" tone="muted" :clamp="1">
      {{ mention.context }}
    </Text>

    <!--
      The promise the session-wide answer makes, stated. Deciding once and silently applying it to
      four other rows would be correct behaviour that looks like a bug.
    -->
    <Text v-if="alsoApplies > 0" variant="caption" tone="muted" data-ambiguity-scope>
      {{ t('knowledge.relations.ambiguity.scope', '', { count: alsoApplies }) }}
    </Text>
  </span>
</template>
