<script setup lang="ts">
// AiBudgetBanner — the BLOCKED panel shown when an AI operation is refused by the workspace budget,
// or the workspace is already over its cap.
//
// Promoted from `pages/generator/session/SessionBudgetBanner.vue` in B15a (spec §24.8 / R23): the
// Generator, the Bots and the Knowledge composer all spend against the same cap and all need this
// exact panel, and `pages/knowledge/**` may not import from `pages/generator/**`. A copy would mean
// two wordings for one event. Behaviour is unchanged — this is a move, not a rewrite.
//
// Surfaced from a TYPED budget signal, never re-derived: a host sets `active` when an operation
// rejects with the budget error ({@see app/lib/aiBudget}) OR the summary is `blocked`. A clear
// message + when the window resets + a ROLE-appropriate CTA: an owner links to the AI-usage page to
// raise the limit; a member is told to contact the workspace owner. The owner-only affordance is
// driven by `canManage` (server-authoritative), NOT a client role guess.
//
// ROUTE-AGNOSTIC by design: it emits `manage` and lets the host navigate, so it can live in the
// design system without knowing any module's route names. Uses Alert (danger → `role="alert"`); the
// icon + text carry the meaning, not the colour.
import { computed } from 'vue';
import Alert from '../feedback/Alert.vue';
import Button from '../primitives/Button.vue';
import { useI18n } from '../../app/i18n';
import type { AiUsageSummary } from '../../app/stores/aiUsage';

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
    :title="t('aiBudget.blockedTitle')"
    :dismissible="dismissible"
    @dismiss="emit('dismiss')"
  >
    <p>{{ t('aiBudget.blockedMessage') }}</p>
    <p v-if="resetsAt" class="mt-next-1 text-next-xs opacity-90">
      {{ t('aiBudget.resetsOn', '', { date: resetsAt }) }}
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
        {{ t('aiBudget.raiseLimit') }}
      </Button>
      <span v-else class="text-next-xs opacity-90">
        {{ t('aiBudget.contactOwner') }}
      </span>
    </template>
  </Alert>
</template>
