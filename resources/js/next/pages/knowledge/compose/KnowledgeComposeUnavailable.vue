<script setup lang="ts">
// KnowledgeComposeUnavailable — the composer cannot run, and WHY (spec §24.8 / DC9).
//
// Rendered INSTEAD of the source form, decided BEFORE the form is asked for. The alternative — a
// live form whose Start button 429s — spends the user's typing before telling them it was never
// going to work.
//
// The "New entry" buttons elsewhere stay ENABLED and lead here (D15): an explanation at the
// destination beats a greyed-out control with no reason. That is why this screen carries the whole
// story — the cause, when it lifts, and the fact that everything else still works.
import { computed } from 'vue';
import Alert from '../../../ui/feedback/Alert.vue';
import AiBudgetBanner from '../../../ui/patterns/AiBudgetBanner.vue';
import Button from '../../../ui/primitives/Button.vue';
import Text from '../../../ui/primitives/Text.vue';
import { useI18n } from '../../../app/i18n';
import type { AiUsageSummary } from '../../../app/stores/aiUsage';
import type { KnowledgeComposeBlockReason } from '../types';

const props = defineProps<{
  reason: KnowledgeComposeBlockReason;
  /** The renewal date, from the availability payload's own budget period. */
  resetsAt?: string | null;
  /** The shared summary, when the host has it — drives the promoted budget banner's owner CTA. */
  summary?: AiUsageSummary | null;
}>();

const emit = defineEmits<{
  (e: 'manage'): void;
  (e: 'back'): void;
}>();

const { t } = useI18n();

const isBudget = computed(() => props.reason === 'ai_budget_exceeded');

/** The renewal date, formatted — or '' when the server did not give one (never a guess). */
const resetsAtLabel = computed(() => {
  if (!props.resetsAt) return '';
  const parsed = new Date(props.resetsAt);
  return Number.isNaN(parsed.getTime())
    ? ''
    : new Intl.DateTimeFormat(undefined, { year: 'numeric', month: 'short', day: 'numeric' }).format(parsed);
});

/**
 * The cause, in the user's terms. `disabled` is the kill switch. The budget case is handled by the
 * promoted banner (it owns the reset date + the role-appropriate CTA), so this line only runs for
 * the causes the banner does not describe.
 */
const message = computed(() => {
  if (props.reason === 'disabled') return t('knowledge.compose.unavailable.disabled');
  if (isBudget.value) {
    return resetsAtLabel.value
      ? t('knowledge.compose.unavailable.budget', '', { date: resetsAtLabel.value })
      : t('knowledge.compose.unavailable.budgetNoDate');
  }
  return t('knowledge.compose.unavailable.generic');
});
</script>

<template>
  <div class="flex flex-col gap-next-3" data-compose-unavailable>
    <!-- The budget case reuses the promoted banner: it already splits the CTA by role (an owner
         raises the limit; a member is told who can) instead of showing a control that would 403. -->
    <AiBudgetBanner
      v-if="isBudget && summary"
      :summary="summary"
      :can-manage="summary.can_manage"
      @manage="emit('manage')"
    />

    <Alert v-else variant="warning" :title="t('knowledge.compose.unavailable.title')">
      <p>{{ message }}</p>
    </Alert>

    <!-- Always: what still works. A composer that is down is not a module that is down, and a user
         who does not know that concludes the whole feature is broken. -->
    <div class="flex flex-wrap items-center gap-next-3">
      <Text variant="caption" tone="muted">{{ t('knowledge.compose.unavailable.editingWorks') }}</Text>
      <Button variant="link" size="sm" @click="emit('back')">
        {{ t('knowledge.compose.unavailable.backToReader') }}
      </Button>
      <Button
        v-if="isBudget && !summary"
        variant="outline"
        size="sm"
        leading-icon="wallet"
        @click="emit('manage')"
      >
        {{ t('knowledge.compose.unavailable.usage') }}
      </Button>
    </div>
  </div>
</template>
