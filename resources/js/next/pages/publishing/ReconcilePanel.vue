<script setup lang="ts">
// ReconcilePanel — `needs_reconcile`, the most important screen in this module (§8).
//
// ═════════════════════════════════════════════════════════════════════════════════════════
// WHY THIS IS A PANEL AND NOT ANOTHER BADGE
// ═════════════════════════════════════════════════════════════════════════════════════════
// Every other status answers "what happened". This one answers: WE DO NOT KNOW, AND NOBODY
// HERE DOES. The publication may already be in the world. The product reflex — a big "Retry"
// — is exactly the act the entire module exists to forbid: a second post that nothing
// written in this application can withdraw.
//
// So there is ONE action, it is called "Check the platform", and directly under it stands a
// sentence saying what it does NOT do. That sentence is half the button's value.
//
// ═════════════════════════════════════════════════════════════════════════════════════════
// THREE OUTCOMES, ALL 200 — AND ONE OF THEM IS NOT A FAILURE
// ═════════════════════════════════════════════════════════════════════════════════════════
//   • `published`       — found. The screen redraws as a published publication.
//   • `failed`          — proven absent. GOOD NEWS: scheduling is legal again. `info`, never
//                         `danger`.
//   • `needs_reconcile` — unchanged. Nothing was learned. The request SUCCEEDED, the state is
//                         correct, and showing red here would teach that checking "breaks",
//                         when it is the one act worth repeating from this state.
//
// Forbidden by the same reasoning: attempt counters ("try 3 of 5"), progress bars, any
// escalation. The contract says plainly that a row may stay here forever and that this is an
// HONEST state for something nobody can answer.
import { computed, onBeforeUnmount, ref } from 'vue';
import Alert from '../../ui/feedback/Alert.vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Accordion from '../../ui/disclosure/Accordion.vue';
import AccordionItem from '../../ui/disclosure/AccordionItem.vue';
import { usePublishingStore } from '../../app/stores/publishing';
import { useToast } from '../../app/composables/useToast';
import { useI18n } from '../../app/i18n';
import { failureSentenceKey, staleAfterMinutes } from './publishingMeta';
import {
  isLostRace,
  serverMessageOf,
  statusOf,
  throttleSecondsOf,
  transitionRefusalOf,
} from './publishingErrors';
import { formatInstant } from './publishingTime';
import type { Publication } from './types';

const props = defineProps<{ publication: Publication; timezone: string | null }>();
const emit = defineEmits<{ (e: 'updated', publication: Publication): void }>();

const { t, currentLocale } = useI18n();
const store = usePublishingStore();
const toast = useToast();

const checking = ref(false);
/** The third outcome: nothing was learned. Polite, and NOT an error. */
const unresolved = ref(false);
/** A `lost_race`: the screen is out of date, not broken. Server prose, one action. */
const lostRaceMessage = ref<string | null>(null);
/** Seconds left on the throttle, or 0. */
const cooldown = ref(0);
let cooldownTimer: ReturnType<typeof setInterval> | null = null;

onBeforeUnmount(() => {
  if (cooldownTimer) clearInterval(cooldownTimer);
});

/** The one sentence that says what happened, from the three codes that land here. */
const reason = computed(() => {
  const code = props.publication.failure_code;
  return code ? t(failureSentenceKey(code), '', { code }) : '';
});

/**
 * The facts line. `failure_context` keys OUTSIDE the whitelist never render: the column also
 * carries things like `{exception: 'Illuminate\\…'}`, and an exception class name is not a
 * message for a person. A missing `last_attempt_at` drops its clause entirely rather than
 * printing a dash — "—" would be an answer where there is none.
 */
const facts = computed(() => {
  const p = props.publication;
  const parts: string[] = [];
  if (p.last_attempt_at) {
    parts.push(
      t('publishing.reconcile.facts.claimed', '', {
        moment: formatInstant(p.last_attempt_at, props.timezone, currentLocale.value),
      }),
    );
  }
  const minutes = staleAfterMinutes(p.failure_context);
  if (minutes !== null) parts.push(t('publishing.reconcile.facts.stale', '', { minutes }));
  if (p.attempts > 0) parts.push(t('publishing.reconcile.facts.attempts', '', { count: p.attempts }));
  return parts;
});

const buttonLabel = computed(() =>
  cooldown.value > 0
    ? t('publishing.reconcile.throttled.countdown', '', { seconds: cooldown.value })
    : t('publishing.reconcile.action'),
);

/**
 * The countdown is announced every TEN seconds, not every one: a polite live region that
 * speaks once a second is a region nobody can listen past.
 */
const announcedCooldown = computed(() =>
  cooldown.value > 0 && cooldown.value % 10 === 0 ? buttonLabel.value : '',
);

function startCooldown(seconds: number): void {
  cooldown.value = seconds;
  if (cooldownTimer) clearInterval(cooldownTimer);
  cooldownTimer = setInterval(() => {
    cooldown.value -= 1;
    if (cooldown.value <= 0 && cooldownTimer) {
      clearInterval(cooldownTimer);
      cooldownTimer = null;
      // The button comes back by itself — no reload, no second click to "unlock" it.
      cooldown.value = 0;
    }
  }, 1000);
}

async function check(): Promise<void> {
  if (checking.value || cooldown.value > 0) return;
  checking.value = true;
  unresolved.value = false;
  lostRaceMessage.value = null;

  try {
    const result = await store.reconcilePublication(props.publication.id);
    emit('updated', result);

    if (result.status === 'published') {
      toast.success(t('publishing.toasts.reconciledFound'));
    } else if (result.status === 'failed') {
      // Proven absent. `info`, because this is the good news of the three.
      toast.info(t('publishing.toasts.reconciledAbsent'));
    } else {
      // Unchanged: nothing was learned. NO TOAST — the message belongs in the panel, where
      // the person is already looking, and it is not an error.
      unresolved.value = true;
    }
  } catch (err) {
    const status = statusOf(err);

    if (status === 429) {
      startCooldown(throttleSecondsOf(err));
      return;
    }

    const refusal = transitionRefusalOf(err);
    if (refusal && isLostRace(refusal)) {
      lostRaceMessage.value = refusal.message;
      return;
    }

    if (status === 403) {
      // Should not happen — the button is gated on `can_be_reconciled`. A quiet refetch
      // first; if the flag still says yes, the drift between flag and policy is a real
      // defect and has to stay visible.
      const fresh = await store.fetchPublication(props.publication.id).catch(() => null);
      if (fresh) emit('updated', fresh);
      if (fresh?.can_be_reconciled) {
        toast.danger(serverMessageOf(err) ?? t('publishing.toasts.actionError'));
      }
      return;
    }

    toast.danger(serverMessageOf(err) ?? t('publishing.toasts.actionError'));
  } finally {
    checking.value = false;
  }
}

/**
 * The "Refresh" beside a lost race. It has to say when IT fails too: this is the state where
 * a person is deciding whether a post exists, and a Refresh that quietly does nothing would
 * read as "nothing changed" — the one answer this panel must never give by accident.
 */
async function refresh(): Promise<void> {
  try {
    const fresh = await store.fetchPublication(props.publication.id);
    if (fresh) emit('updated', fresh);
  } catch (err) {
    toast.danger(serverMessageOf(err) ?? t('publishing.toasts.actionError'));
  }
}
</script>

<template>
  <!-- The border is NOT decoration: in dark mode `danger-subtle` sits close to `card`, and
       without it this panel would stop being a separate surface exactly where it matters. -->
  <section
    role="alert"
    class="flex flex-col gap-next-4 rounded-next-xl border border-next-danger/30 bg-next-danger-subtle p-next-6 text-next-danger-subtle-foreground"
  >
    <div class="flex items-start gap-next-3">
      <!-- `help-circle`, NOT `alert-triangle`. The colour says "urgent"; the glyph says
           "nobody knows". They are different facts and both are true here. -->
      <Icon name="help-circle" class="shrink-0 text-next-2xl" aria-hidden="true" />
      <div class="min-w-0 space-y-next-2">
        <h2 class="text-next-xl font-next-semibold">{{ t('publishing.reconcile.title') }}</h2>
        <p v-if="reason" class="text-next-sm">{{ reason }}</p>
        <p v-if="facts.length" class="text-next-xs opacity-90">
          {{ facts.join(' · ') }}
        </p>
      </div>
    </div>

    <!-- The action and the sentence that says what it does not do. -->
    <div class="rounded-next-lg border border-next-danger/20 bg-next-card/40 p-next-4">
      <template v-if="publication.can_be_reconciled">
        <!-- Throttled: dimmed with the house inert look and guarded in `check()`, but still
             hit-testable and focusable — the countdown IS the reason, and a control the
             pointer cannot reach is a reason nobody reads. (`pointer-events-none` is what
             Button's docblock forbids, for exactly that.) -->
        <Button
          variant="primary"
          leading-icon="search"
          :loading="checking"
          :aria-disabled="cooldown > 0 || undefined"
          :class="cooldown > 0 ? 'opacity-60 cursor-not-allowed' : ''"
          @click="check"
        >
          <span :class="cooldown > 0 ? 'tabular-nums' : ''">{{ buttonLabel }}</span>
        </Button>
        <p class="mt-next-2 text-next-sm">{{ t('publishing.reconcile.actionExplains') }}</p>
      </template>
      <!-- Never a greyed button with no reason: say who CAN do this. -->
      <p v-else class="text-next-sm">{{ t('publishing.reconcile.notAllowed') }}</p>
    </div>

    <!-- The throttle, explained honestly — the real reason the limit is this low. -->
    <Alert v-if="cooldown > 0" variant="warning" size="sm">
      {{ t('publishing.reconcile.throttled.body') }}
    </Alert>
    <p aria-live="polite" class="sr-only">{{ announcedCooldown }}</p>

    <!-- Outcome three. `role="status"` (polite) and `info`: the request succeeded, the state
         is correct, and nothing was published. -->
    <Alert v-if="unresolved" variant="info" size="sm" role="status">
      <p class="font-next-medium">{{ t('publishing.reconcile.unresolved.title') }}</p>
      <p>{{ t('publishing.reconcile.unresolved.body') }}</p>
    </Alert>

    <!-- Out of date, not broken. The server's own sentence, and ONE action — no automatic
         reload, because the person has to read that their click changed nothing. -->
    <Alert v-if="lostRaceMessage" variant="info" size="sm">
      {{ lostRaceMessage }}
      <template #actions>
        <Button variant="outline" size="sm" leading-icon="rotate-ccw" @click="refresh">
          {{ t('publishing.actions.refresh') }}
        </Button>
      </template>
    </Alert>

    <p class="text-next-xs opacity-90">{{ t('publishing.reconcile.automatic') }}</p>

    <!-- A missing action somebody has to ask about is worse than one that explains itself.
         Collapsed by default; the three absent buttons are each named inside. -->
    <Accordion type="single">
      <AccordionItem value="why" :title="t('publishing.reconcile.whyNoRetry.question')">
        <p class="whitespace-pre-line text-next-sm">
          {{ t('publishing.reconcile.whyNoRetry.body') }}
        </p>
      </AccordionItem>
    </Accordion>
  </section>
</template>
