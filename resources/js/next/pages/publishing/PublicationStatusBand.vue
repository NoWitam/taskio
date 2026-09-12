<script setup lang="ts">
// PublicationStatusBand — the full-width state band on the detail screen (§7.2).
//
// SEVEN SCREENS, NOT ONE SCREEN WITH SEVEN BADGES. Irreversibility rises along a
// publication's life and the interface has to show WHERE ON THAT LINE somebody is standing:
// a draft permits everything; `scheduled` has a clock running; `publishing` no longer
// belongs to anyone on this screen; `published` is a fact in the world. So each status gets
// its own heading, its own sentence, its own action set — and, where an action is missing,
// a sentence saying why. An absent button somebody has to ask about is worse than an absent
// button that explains itself.
//
// `needs_reconcile` is deliberately NOT handled here: it gets a panel of its own
// (`ReconcilePanel.vue`), because "we do not know" is not a variation on a status — it is
// the one state the whole module exists to keep people from clicking through.
import { computed } from 'vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import { useI18n } from '../../app/i18n';
import { failureSentenceKey, statusBandClass, statusBandRole, statusIcon } from './publishingMeta';
import { armAffordance } from './publicationActions';
import { formatInstant } from './publishingTime';
import type { PlatformConnection, Publication } from './types';

const props = defineProps<{
  publication: Publication;
  timezone: string | null;
  connections?: PlatformConnection[] | null;
  /** The attached pipeline's name, when it is known — for the review line. */
  pipelineName?: string | null;
}>();

const emit = defineEmits<{
  (e: 'schedule'): void;
  (e: 'edit'): void;
  (e: 'delete'): void;
  (e: 'connections'): void;
  (e: 'approval'): void;
}>();

const { t, currentLocale } = useI18n();

const at = (iso: string | null): string => formatInstant(iso, props.timezone, currentLocale.value);
const arm = computed(() => armAffordance(props.publication, props.connections));

const heading = computed(() => {
  const p = props.publication;
  switch (p.status) {
    case 'scheduled':
      return t('publishing.detail.band.scheduled.title', '', { moment: at(p.scheduled_at) });
    case 'publishing':
      return t('publishing.detail.band.publishing.title');
    case 'published':
      return t('publishing.detail.band.published.title', '', { moment: at(p.published_at) });
    case 'failed':
      return t('publishing.detail.band.failed.title');
    case 'blocked':
      return t('publishing.detail.band.blocked.title');
    default:
      return t('publishing.detail.band.draft.title');
  }
});

const sentence = computed(() => {
  const p = props.publication;
  switch (p.status) {
    case 'scheduled':
      return props.timezone
        ? t('publishing.detail.band.scheduled.bodyZone', '', { tz: props.timezone })
        : t('publishing.detail.band.scheduled.body');
    case 'publishing':
      return p.last_attempt_at
        ? t('publishing.detail.band.publishing.body', '', { moment: at(p.last_attempt_at) })
        : t('publishing.detail.band.publishing.bodyNoMoment');
    case 'published':
      return t('publishing.detail.band.published.body');
    // `failed` and `blocked` say WHY, in the translated failure/hold sentence — the status
    // word alone ("Failed") answers nothing a person can act on.
    case 'failed':
    case 'blocked':
      return p.failure_code
        ? t(failureSentenceKey(p.failure_code), '', { code: p.failure_code })
        : '';
    default:
      return t('publishing.detail.band.draft.body');
  }
});

/** The second line: what is NOT possible here, and why. */
const absenceNote = computed(() => {
  const p = props.publication;
  if (p.status === 'scheduled') return t('publishing.detail.band.scheduled.stopHint');
  if (p.status === 'published') return t('publishing.detail.band.published.noEdit');
  return '';
});

/**
 * A live review is a SENTENCE here, not a rule.
 *
 * The freeze itself belongs to the policy: `can_be_edited` / `can_be_scheduled` already
 * compose `isInApproval()`, so the affordances go quiet by themselves. A second, frontend
 * copy of that rule would be a second authorization model — and the second one is the one
 * that drifts. All this screen adds is the explanation.
 */
const reviewLine = computed(() => {
  if (!props.publication.is_in_approval) return '';
  return props.pipelineName
    ? t('publishing.detail.band.review', '', { pipeline: props.pipelineName })
    : t('publishing.detail.band.reviewGeneric');
});

/**
 * The guard behind the arming button. `aria-disabled` is an announcement, not a behaviour:
 * the click still arrives, so the refusal has to be enforced here as well as painted.
 */
function onArm(): void {
  if (!arm.value.enabled) return;
  emit('schedule');
}

/** A refused review leaves a `draft` — the only place that fact is visible. */
const rejectedLine = computed(() =>
  props.publication.approval_state === 'rejected' && !props.publication.is_in_approval
    ? t('publishing.detail.band.rejected')
    : '',
);
</script>

<template>
  <section
    :role="statusBandRole(publication.status)"
    class="flex flex-col gap-next-3 rounded-next-xl p-next-4 next-md:flex-row next-md:items-start next-md:gap-next-4"
    :class="statusBandClass(publication.status)"
  >
    <Icon
      :name="statusIcon(publication.status)"
      class="shrink-0 text-next-xl"
      :class="publication.status === 'publishing' ? 'motion-safe:animate-spin' : ''"
      aria-hidden="true"
    />

    <div class="min-w-0 flex-1 space-y-next-1">
      <h2 class="text-next-base font-next-semibold">{{ heading }}</h2>
      <p v-if="sentence" class="text-next-sm">{{ sentence }}</p>
      <p v-if="absenceNote" class="text-next-xs opacity-80">{{ absenceNote }}</p>

      <p v-if="reviewLine" class="text-next-sm font-next-medium">
        {{ reviewLine }}
        <Button variant="ghost" size="xs" @click="emit('approval')">
          {{ t('publishing.detail.band.reviewLink') }}
        </Button>
      </p>
      <p v-if="rejectedLine" class="text-next-sm font-next-medium">{{ rejectedLine }}</p>
    </div>

    <!-- Below `next-md` the actions drop UNDER the sentence and go full width. -->
    <div class="flex shrink-0 flex-col gap-next-2 next-md:flex-row next-md:items-center">
      <!-- `published` leads with the one road to the artifact itself. -->
      <Button
        v-if="publication.remote_url"
        variant="primary"
        size="sm"
        leading-icon="external-link"
        :href="publication.remote_url"
        target="_blank"
      >
        {{ t('publishing.actions.openOnPlatform') }}
      </Button>

      <!-- `blocked` leads with the CAUSE, which is somewhere else entirely. -->
      <Button
        v-if="publication.status === 'blocked'"
        variant="primary"
        size="sm"
        leading-icon="link-2"
        @click="emit('connections')"
      >
        {{ t('publishing.actions.fixConnection') }}
      </Button>

      <!-- Arming, named for what it does. Disabled WITH ITS REASON beside it, never bare —
           and the refusal is VISIBLE: `aria-disabled` alone is a promise to assistive
           technology that the pointer never sees, so the button looked live, lit up under
           the cursor, and did nothing when clicked. The house inert look (`Button`'s own
           `INERT_CLASS`) plus the guard in `onArm()` say the same thing to everybody. -->
      <Button
        v-if="arm.kind !== 'none'"
        :variant="publication.status === 'blocked' ? 'outline' : 'primary'"
        size="sm"
        :leading-icon="arm.kind === 'changeTime' ? 'clock' : 'send'"
        :aria-disabled="!arm.enabled || undefined"
        :class="!arm.enabled ? 'opacity-60 cursor-not-allowed' : ''"
        @click="onArm"
      >
        {{ t(`publishing.actions.${arm.kind}`) }}
      </Button>
      <p v-if="arm.blockedBy" class="max-w-56 text-next-xs opacity-90">
        {{ t(`publishing.blocked.${arm.blockedBy}`) }}
      </p>

      <Button
        v-if="publication.can_be_edited"
        variant="outline"
        size="sm"
        leading-icon="pencil"
        @click="emit('edit')"
      >
        {{ t('publishing.actions.edit') }}
      </Button>

      <Button
        v-if="publication.can_be_deleted"
        variant="ghost"
        size="sm"
        leading-icon="trash"
        @click="emit('delete')"
      >
        {{
          publication.status === 'published'
            ? t('publishing.actions.deleteRecord')
            : t('publishing.actions.delete')
        }}
      </Button>
    </div>
  </section>
</template>
