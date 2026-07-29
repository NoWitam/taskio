<script setup lang="ts">
// SessionBudgetBanner — the BLOCKED panel shown when a generate/refine is refused by the AI budget, or the
// workspace is already over its cap (R2 sub-stage 4).
//
// Surfaced from a TYPED budget signal, never re-derived: the chat view sets `active` when a run rejects with
// the budget error ({@see isBudgetError}) OR the summary is `blocked`. A clear message + when the window
// resets + a ROLE-appropriate CTA: an owner links to the AI-usage page to raise the limit; a member is told
// to contact the workspace owner. Owner-only affordance is driven by `canManage` (server-authoritative), NOT
// a client role guess. Uses Alert (danger → role="alert"); the icon + text carry the meaning (not color).
import { computed } from 'vue';
import Alert from '../../../ui/feedback/Alert.vue';
import Button from '../../../ui/primitives/Button.vue';
import { useI18n } from '../../../app/i18n';
import type { AiUsageSummary } from '../../../app/stores/aiUsage';

const props = defineProps<{
  /** The shared usage summary (for the reset date + the owner CTA gate). */
  summary: AiUsageSummary | null;
  /** Owner-only affordance gate — the SERVER `can_manage` flag, never a client guess. */
  canManage: boolean;
  /** Dismiss the transient run-refusal banner (the persistent `summary.blocked` case is not dismissible). */
  dismissible?: boolean;
}>();

const emit = defineEmits<{ (e: 'dismiss'): void; (e: 'manage'): void }>();

const { t } = useI18n();

const resetsAt = computed(() => {
  const iso = props.summary?.period?.resets_at;
  if (!iso) return '';
  const parsed = new Date(iso);
  return Number.isNaN(parsed.getTime())
    ? ''
    : new Intl.DateTimeFormat(undefined, { year: 'numeric', month: 'short', day: 'numeric' }).format(parsed);
});
</script>

<template>
  <Alert
    variant="danger"
    :title="t('generator.sessions.budget.blockedTitle')"
    :dismissible="dismissible"
    @dismiss="emit('dismiss')"
  >
    <p>{{ t('generator.sessions.budget.blockedMessage') }}</p>
    <p v-if="resetsAt" class="mt-next-1 text-next-xs opacity-90">
      {{ t('generator.sessions.budget.resetsOn', '', { date: resetsAt }) }}
    </p>

    <template #actions>
      <!-- Owner → raise the limit on the AI-usage page. Member → a read-only instruction (no 403 control). -->
      <Button
        v-if="canManage"
        variant="outline"
        size="sm"
        leading-icon="wallet"
        @click="emit('manage')"
      >
        {{ t('generator.sessions.budget.raiseLimit') }}
      </Button>
      <span v-else class="text-next-xs opacity-90">
        {{ t('generator.sessions.budget.contactOwner') }}
      </span>
    </template>
  </Alert>
</template>
