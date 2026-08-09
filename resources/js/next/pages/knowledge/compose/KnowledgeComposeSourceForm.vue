<script setup lang="ts">
// KnowledgeComposeSourceForm — the one field the whole module now starts from (spec §24.2).
//
// The user writes what they know, unstructured; the composer splits it into entries. So this is a
// single large textarea with a placeholder that TEACHES what the agent needs, not a form with a
// title/slug/metadata triplet — those are the agent's output, not its input.
//
// THE CAP COMES FROM THE SERVER, not from a constant here: `limits.source_max_chars` arrives with
// the availability answer, so the counter and the start gate can never promise a length the POST
// then refuses.
//
// NO INVENTED COST NUMBER. Spec §24.2 asks for a per-operation estimate chip and says, in the same
// breath, that it must render ONLY if the server provides the figure — "a made-up number is worse
// than no number". The verified contract (`compose-availability`, the session resource) carries no
// per-operation estimate (K4 → no), so the chip is absent. What IS on the wire — the workspace's
// budget state — renders instead, because that number is real.
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import FormField from '../../../ui/forms/FormField.vue';
import Textarea from '../../../ui/forms/Textarea.vue';
import Surface from '../../../ui/layout/Surface.vue';
import Alert from '../../../ui/feedback/Alert.vue';
import Badge from '../../../ui/primitives/Badge.vue';
import Button from '../../../ui/primitives/Button.vue';
import Tooltip from '../../../ui/overlay/Tooltip.vue';
import { useI18n } from '../../../app/i18n';
import type { KnowledgeComposeAvailability } from '../types';

const props = withDefaults(
  defineProps<{
    availability: KnowledgeComposeAvailability | null;
    /** A red link's slug — the composer explains where the user came from and pre-writes a start. */
    seedSlug?: string | null;
    /** The entry being amended, when the user arrived from "propose a change with AI". */
    amendTitle?: string | null;
    /** The base has no entries yet — the composer says so, because it changes what to expect. */
    firstSession?: boolean;
    submitting?: boolean;
  }>(),
  { seedSlug: null, amendTitle: null, firstSession: false, submitting: false },
);

const emit = defineEmits<{
  (e: 'submit', sourceText: string): void;
  (e: 'cancel'): void;
}>();

const { t } = useI18n();

const source = defineModel<string>({ default: '' });
const textareaRef = ref<InstanceType<typeof Textarea> | null>(null);

/** The server's own bound. Falls back to the spec's 20 000 only until availability lands. */
const maxChars = computed(() => props.availability?.limits?.source_max_chars ?? 20000);

const tooLong = computed(() => source.value.length > maxChars.value);
const isEmpty = computed(() => source.value.trim() === '');
const canSubmit = computed(() => !isEmpty.value && !tooLong.value && !props.submitting);

/**
 * The workspace's budget state, as a chip — the honest half of §24.2's two-chip footer. Rendered
 * only when there is something to warn about; an "all fine" chip on every visit is noise.
 */
const budgetWarning = computed(() => {
  const budget = props.availability?.budget;
  if (!budget || budget.blocked) return null; // blocked never reaches this form (§24.8)
  if (!budget.warn_reached) return null;
  const used = budget.cost_used;
  const cap = budget.cost_cap;
  if (used == null || cap == null || cap <= 0) return t('knowledge.compose.budgetWarn');
  return t('knowledge.compose.budgetWarnPercent', '', { percent: Math.round((used / cap) * 100) });
});

/** A seeded composer opens with a sentence to finish and the cursor after it. */
onMounted(async () => {
  if (props.seedSlug && source.value === '') {
    source.value = t('knowledge.compose.seedPrefill', '', { title: props.seedSlug });
  }
  await nextTick();
  textareaRef.value?.focus?.();
});

// A late seed (the availability gate resolved after mount) still prefills — but never overwrites
// what the user has already typed.
watch(
  () => props.seedSlug,
  (slug) => {
    if (slug && source.value === '') {
      source.value = t('knowledge.compose.seedPrefill', '', { title: slug });
    }
  },
);

const placeholder = computed(() =>
  props.amendTitle
    ? t('knowledge.compose.amendPlaceholder')
    : t('knowledge.compose.sourcePlaceholder'),
);
</script>

<template>
  <Surface bg="card" border elevation="sm" radius="lg" class="flex flex-col gap-next-4 p-next-6">
    <!-- Where the user came from. Without this a prefilled field looks like someone else's text. -->
    <Alert v-if="seedSlug" variant="info" size="sm">{{ t('knowledge.compose.seedNotice') }}</Alert>
    <Alert v-if="amendTitle" variant="info" size="sm">
      {{ t('knowledge.compose.amendNotice', '', { title: amendTitle }) }}
    </Alert>
    <Alert v-if="firstSession" variant="info" size="sm">
      {{ t('knowledge.compose.firstSession') }}
    </Alert>

    <FormField
      :label="t('knowledge.compose.sourceLabel')"
      :description="t('knowledge.compose.sourceHint')"
      required
    >
      <Textarea
        ref="textareaRef"
        v-model="source"
        auto-grow
        :rows="12"
        counter
        :maxlength="maxChars"
        :placeholder="placeholder"
        :aria-invalid="tooLong"
        data-compose-source
      />
    </FormField>

    <div class="flex flex-wrap items-center justify-end gap-next-3">
      <Badge v-if="budgetWarning" variant="warning" tone="subtle" icon="wallet" size="sm" class="me-auto">
        {{ budgetWarning }}
      </Badge>

      <Button variant="ghost" :disabled="submitting" @click="emit('cancel')">
        {{ t('knowledge.compose.cancel') }}
      </Button>

      <!-- Over the cap: `aria-disabled` + a REASON, never a silently greyed-out button. -->
      <Tooltip v-if="tooLong" :label="t('knowledge.compose.startBlocked', '', { max: maxChars })">
        <Button leading-icon="sparkles" aria-disabled="true" data-compose-start>
          {{ t('knowledge.compose.start') }}
        </Button>
      </Tooltip>
      <Button
        v-else
        leading-icon="sparkles"
        :loading="submitting"
        :disabled="!canSubmit"
        data-compose-start
        @click="emit('submit', source)"
      >
        {{ t('knowledge.compose.start') }}
      </Button>
    </div>
  </Surface>
</template>
