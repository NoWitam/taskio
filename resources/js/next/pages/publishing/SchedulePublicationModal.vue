<script setup lang="ts">
// SchedulePublicationModal — arming, re-arming, changing the time, and "publish now" (§7.4).
//
// ═════════════════════════════════════════════════════════════════════════════════════════
// TWO CALLS, BECAUSE A MOVE INTO THE SAME STATUS IS NOT AN EDGE
// ═════════════════════════════════════════════════════════════════════════════════════════
//   • `draft | failed | blocked` → `POST …/schedule {scheduled_at}`;
//   • already `scheduled`        → `PUT …` with the WHOLE ROW and a new moment, because
//     `scheduled → scheduled` is not in the transition table and the state machine would
//     answer `publication_transition_not_allowed`.
//
// ═════════════════════════════════════════════════════════════════════════════════════════
// AND `POST …/schedule` HAS TWO OUTCOMES, BOTH 200
// ═════════════════════════════════════════════════════════════════════════════════════════
// With an approval pipeline attached and no approval yet, the server does NOT arm: it parks
// the moment in `arm_on_approval_at`, opens a review, and answers 200 with the row still a
// `draft` and `is_in_approval: true`. "Scheduled for Friday" would then be a promise nobody
// made. The outcome is therefore read off the RESPONSE (`scheduleOutcomeOf`), never assumed
// from what this screen believed before the click.
import { computed, ref } from 'vue';
import Modal from '../../ui/overlay/Modal.vue';
import Button from '../../ui/primitives/Button.vue';
import Alert from '../../ui/feedback/Alert.vue';
import FormField from '../../ui/forms/FormField.vue';
import DateTimePicker from '../../ui/forms/DateTimePicker.vue';
import { usePublishingStore } from '../../app/stores/publishing';
import { useToast } from '../../app/composables/useToast';
import { useConfirm } from '../../app/composables/useConfirm';
import { useI18n } from '../../app/i18n';
import {
  armAffordance,
  intendedMomentOf,
  scheduleOutcomeOf,
} from './publicationActions';
import { buildPublicationPayload } from './publicationPayload';
import {
  fieldErrorsOf,
  isLostRace,
  serverMessageOf,
  transitionRefusalOf,
} from './publishingErrors';
import { formatInstant, instantToLocalInput, nowAsLocalInput } from './publishingTime';
import type { Publication } from './types';

const props = defineProps<{
  publication: Publication;
  /** The workspace's clock; the moment below is read on it, not on the browser's. */
  timezone: string | null;
}>();

const emit = defineEmits<{
  (e: 'close'): void;
  (e: 'done', publication: Publication): void;
}>();

const { t, currentLocale } = useI18n();
const store = usePublishingStore();
const toast = useToast();
const confirm = useConfirm();

const open = ref(true);
const submitting = ref(false);
const fieldError = ref<string | null>(null);
/** A refusal that belongs IN the modal, with the server's own sentence. */
const refusalMessage = ref<string | null>(null);
const refusalIsLostRace = ref(false);

const moment = ref<string | null>(
  instantToLocalInput(intendedMomentOf(props.publication), props.timezone),
);

const affordance = computed(() => armAffordance(props.publication));
const isChangeTime = computed(() => affordance.value.kind === 'changeTime');
const isSubmitForReview = computed(() => affordance.value.kind === 'submitForReview');

const modalTitle = computed(() => {
  if (isChangeTime.value) return t('publishing.schedule.titleChange');
  if (isSubmitForReview.value) return t('publishing.schedule.titleSubmit');
  return affordance.value.kind === 'reschedule'
    ? t('publishing.schedule.titleAgain')
    : t('publishing.schedule.title');
});

const confirmLabel = computed(() => {
  if (isChangeTime.value) return t('publishing.schedule.confirmChange');
  if (isSubmitForReview.value) return t('publishing.schedule.confirmSubmit');
  return t('publishing.schedule.confirm');
});

/**
 * ONE deliberate branch of the copy on `publishes_publicly`. A rehearsal is not irreversible
 * and saying it is would train people to ignore the sentence that matters.
 */
const consequence = computed(() => {
  if (isSubmitForReview.value) return t('publishing.schedule.consequenceReview');
  return props.publication.publishes_publicly
    ? t('publishing.schedule.consequencePublic')
    : t('publishing.schedule.consequenceRehearsal');
});

/** "Publish now" has no meaning for a row whose time is only being adjusted. */
const showPublishNow = computed(() => !isChangeTime.value && !isSubmitForReview.value);

const momentHint = computed(() =>
  props.timezone
    ? t('publishing.editor.momentZone', '', { tz: props.timezone })
    : t('publishing.editor.momentZoneUnknown'),
);

function close(): void {
  open.value = false;
  emit('close');
}

function reset(): void {
  fieldError.value = null;
  refusalMessage.value = null;
  refusalIsLostRace.value = false;
}

/** Refresh the row so the screen can decide again from the truth (a lost race, a 403). */
async function refresh(): Promise<void> {
  try {
    const fresh = await store.fetchPublication(props.publication.id);
    if (fresh) emit('done', fresh);
  } catch {
    toast.danger(t('publishing.toasts.actionError'));
  }
  close();
}

async function submit(at: string | null): Promise<void> {
  if (!at) {
    // `scheduled_at` is REQUIRED here (unlike on create): arming with no moment would have
    // to invent one, and "now" is the single most consequential default a form could pick.
    fieldError.value = t('common.required', 'Required');
    return;
  }

  reset();
  submitting.value = true;
  try {
    let result: Publication;
    if (isChangeTime.value) {
      // The WHOLE row plus the new moment — see publicationPayload for what omitting a key
      // would silently do.
      result = await store.updatePublication(
        props.publication.id,
        buildPublicationPayload({
          title: props.publication.title,
          body: props.publication.body ?? '',
          platform: props.publication.platform,
          connectionId: props.publication.platform_connection_id,
          moment: at,
          media: props.publication.media,
          options: props.publication.options ?? {},
        }),
      );
      toast.success(t('publishing.toasts.timeChanged'));
    } else {
      result = await store.schedulePublication(props.publication.id, at);

      // What actually happened, read off the response.
      const outcome = scheduleOutcomeOf(result);
      const when = formatInstant(intendedMomentOf(result), props.timezone, currentLocale.value);
      if (outcome === 'submittedForReview') {
        toast.success(
          when
            ? t('publishing.toasts.submitted', '', { moment: when })
            : t('publishing.toasts.submittedNoMoment'),
        );
      } else {
        toast.success(t('publishing.toasts.scheduled', '', { moment: when }));
      }
    }

    emit('done', result);
    close();
  } catch (err) {
    const refusal = transitionRefusalOf(err);
    if (refusal) {
      // The server's sentence, verbatim. This screen chooses only the VESSEL.
      refusalMessage.value = refusal.message;
      refusalIsLostRace.value = isLostRace(refusal);
      return;
    }

    const errors = fieldErrorsOf(err);
    if (errors.scheduled_at) {
      // `scheduled_in_the_past` and friends: at the field, modal stays open.
      fieldError.value = errors.scheduled_at;
      return;
    }

    toast.danger(serverMessageOf(err) ?? t('publishing.toasts.actionError'));
    close();
  } finally {
    submitting.value = false;
  }
}

/**
 * "Publish now" sends the current moment. The server's 60-second tolerance is what makes
 * that legal. Always confirmed, because it is the one button here whose effect starts
 * within the minute.
 */
async function onPublishNow(): Promise<void> {
  const ok = await confirm({
    title: t('publishing.schedule.publishNowConfirmTitle'),
    message: t('publishing.schedule.publishNowConfirmBody'),
    confirmLabel: t('publishing.schedule.publishNow'),
    cancelLabel: t('common.cancel'),
    variant: 'danger',
  });
  if (!ok) return;
  await submit(nowAsLocalInput(props.timezone));
}
</script>

<template>
  <!-- While the request is in flight Escape and the scrim are BLOCKED: this is the one
       moment where dismissing the dialog would leave somebody without an answer to "did
       something just start going out?". -->
  <Modal
    v-model:open="open"
    size="sm"
    :close-on-esc="!submitting"
    :close-on-scrim="!submitting"
    :aria-label="modalTitle"
    @update:open="(value) => !value && emit('close')"
  >
    <template #title>{{ modalTitle }}</template>

    <div class="flex flex-col gap-next-4">
      <FormField
        :label="t('publishing.schedule.title')"
        hide-label
        :description="momentHint"
        :error="fieldError ?? undefined"
      >
        <!-- Focus starts here. Enter in the field does NOT submit — arming by accident is
             too cheap otherwise; only the button arms. -->
        <DateTimePicker v-model="moment" @keydown.enter.prevent />
      </FormField>

      <p class="text-next-sm text-next-muted-foreground">{{ consequence }}</p>

      <!-- A refusal, in the server's words. `lost_race` gets one action and NO automatic
           reload: the person has to read that their click changed nothing. -->
      <Alert v-if="refusalMessage" :variant="refusalIsLostRace ? 'info' : 'danger'" size="sm">
        {{ refusalMessage }}
        <template v-if="refusalIsLostRace" #actions>
          <Button variant="outline" size="sm" leading-icon="rotate-ccw" @click="refresh">
            {{ t('publishing.actions.refresh') }}
          </Button>
        </template>
      </Alert>
    </div>

    <template #footer>
      <!-- Below `next-md` the buttons stack with the CONSEQUENTIAL one on top: the thumb
           reaches furthest at the bottom, and that is not where arming should sit. -->
      <div class="flex w-full flex-col-reverse gap-next-2 next-md:flex-row next-md:justify-end">
        <Button variant="ghost" :disabled="submitting" @click="close">
          {{ t('common.cancel') }}
        </Button>
        <Button
          v-if="showPublishNow"
          variant="secondary"
          :disabled="submitting"
          @click="onPublishNow"
        >
          {{ t('publishing.schedule.publishNow') }}
        </Button>
        <Button variant="primary" :loading="submitting" @click="submit(moment)">
          {{ confirmLabel }}
        </Button>
      </div>
    </template>
  </Modal>
</template>
