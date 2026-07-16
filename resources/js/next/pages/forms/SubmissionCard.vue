<script setup lang="ts">
// SubmissionCard — one row in a form's submissions list (next).
//
// Built on EntityCard: the submitter (creator name, or "Anonymous"), the created
// date, an approved/pending status badge (icon + text, never color-only), and the
// source. Clicking opens the submission detail. All strings via i18n.
//
// `selectable` mode (additive, non-breaking): when true the WHOLE card becomes a
// pick affordance — clicking still emits `select(submission)` (EntityCard renders
// the stretched action as a real, keyboard-activatable button with role/tabindex),
// but the kebab/action menu is suppressed. Default (false) = today's behavior.
import { computed } from 'vue';
import EntityCard, { type EntityMetaItem } from '../../ui/patterns/EntityCard.vue';
import StatusBadge, { type StatusDescriptor } from '../../ui/data/StatusBadge.vue';
import Button from '../../ui/primitives/Button.vue';
import DropdownMenu from '../../ui/overlay/DropdownMenu.vue';
import DropdownMenuItem from '../../ui/overlay/DropdownMenuItem.vue';
import CreatorBadge from '../../ui/patterns/CreatorBadge.vue';
import { creatorLabel } from '../../ui/patterns/creator';
import { useI18n } from '../../app/i18n';
import type { FormSubmission } from './types';

const props = defineProps<{
  submission: FormSubmission;
  /** Rendered in the Deleted tab → swaps delete for restore / permanent delete. */
  trashed?: boolean;
  /**
   * Selection mode: the whole card is a pick affordance and the kebab is hidden.
   * `@select` still carries the full submission; the parent resolves its id.
   */
  selectable?: boolean;
}>();
const emit = defineEmits<{
  (e: 'select', submission: FormSubmission): void;
  (e: 'delete', submission: FormSubmission): void;
  (e: 'restore', submission: FormSubmission): void;
  (e: 'force-delete', submission: FormSubmission): void;
}>();

const { t } = useI18n();

// The submitter identity — a user/bot name, an "Automatyzacja: X" automation label,
// or the "Anonymous" fallback for a null creator (an anonymous submission).
const title = computed(() =>
  creatorLabel(props.submission.creator, t, t('forms.submissions.anonymous')),
);

const badge = computed<StatusDescriptor>(() =>
  props.submission.is_approved
    ? { label: t('forms.submissions.approved'), variant: 'success', tone: 'subtle', icon: 'check-circle' }
    : { label: t('forms.submissions.pending'), variant: 'warning', tone: 'subtle', icon: 'clock' },
);

function formatDate(iso: string | null): string {
  if (!iso) return '';
  const m = iso.match(/^(\d{4})-(\d{2})-(\d{2})/);
  return m ? `${m[3]}.${m[2]}.${m[1]}` : iso;
}

const sourceLabel = computed(() => {
  const s = props.submission.source;
  if (s === 'task') return t('forms.submissions.sourceTask');
  return t('forms.submissions.sourceForm');
});

const meta = computed<EntityMetaItem[]>(() => [
  { icon: 'calendar', label: formatDate(props.submission.created_at) },
  { icon: 'inbox', label: sourceLabel.value },
]);
</script>

<template>
  <EntityCard :title="title" :meta="meta" @click="emit('select', submission)">
    <template #leading>
      <CreatorBadge :creator="submission.creator" glyph-only size="sm" />
    </template>
    <template #status>
      <StatusBadge :status="submission.is_approved ? 'approved' : 'pending'" :label="badge.label" :status-map="{ [submission.is_approved ? 'approved' : 'pending']: badge }" size="sm" />
    </template>

    <template v-if="!selectable" #actions>
      <DropdownMenu placement="bottom-end" :aria-label="t('forms.submissions.actions.menu')">
        <template #trigger="{ props: triggerProps }">
          <Button v-bind="triggerProps" variant="ghost" size="icon-sm" leading-icon="more-vertical" :aria-label="t('forms.submissions.actions.menu')" />
        </template>

        <template v-if="trashed">
          <DropdownMenuItem icon="rotate-ccw" :label="t('forms.submissions.actions.restore')" @select="emit('restore', submission)">
            {{ t('forms.submissions.actions.restore') }}
          </DropdownMenuItem>
          <DropdownMenuItem icon="trash" destructive :label="t('forms.submissions.actions.forceDelete')" @select="emit('force-delete', submission)">
            {{ t('forms.submissions.actions.forceDelete') }}
          </DropdownMenuItem>
        </template>
        <DropdownMenuItem v-else icon="trash" destructive :label="t('forms.submissions.actions.delete')" @select="emit('delete', submission)">
          {{ t('forms.submissions.actions.delete') }}
        </DropdownMenuItem>
      </DropdownMenu>
    </template>
  </EntityCard>
</template>
