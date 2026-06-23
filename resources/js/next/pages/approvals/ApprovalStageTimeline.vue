<script setup lang="ts">
// ApprovalStageTimeline — the vertical stage list inside the review drawer's
// decision pane. Replaces the old horizontal stage Tabs: each stage is a block
// showing its status, criteria, and decision history; the CURRENT stage is
// highlighted, FUTURE stages collapse to a locked hint. A trailing "earlier
// decisions" block surfaces history whose stage no longer exists (nulled FK).
import Icon, { type IconName } from '../../ui/primitives/Icon.vue';
import StatusBadge, { type StatusMap } from '../../ui/data/StatusBadge.vue';
import { useI18n } from '../../app/i18n';
import { approverTypeIcon } from './approvalStatus';
import type { StageView } from './stageView';
import type { ApprovalProcess } from './queue-types';
import type { RunHistoryGroup } from '../../app/stores/approvalQueue';

defineProps<{
  items: StageView[];
  statusMap: StatusMap;
  /** Historical decisions whose stage no longer exists (nulled FK), if any. */
  unknownGroup?: RunHistoryGroup;
}>();

const { t } = useI18n();

function nodeClass(view: StageView): string {
  if (view.state === 'current') return 'bg-next-primary text-next-primary-foreground';
  if (view.outcome === 'rejected') return 'bg-next-danger text-next-danger-foreground';
  if (view.state === 'done' || view.outcome === 'approved') {
    return 'bg-next-success text-next-success-foreground';
  }
  return 'bg-next-muted text-next-muted-foreground';
}

function nodeIcon(view: StageView): IconName {
  if (view.state === 'current') return 'clock';
  if (view.outcome === 'rejected') return 'x';
  if (view.state === 'done' || view.outcome === 'approved') return 'check';
  return 'lock';
}

function approverName(proc: ApprovalProcess): string {
  return proc.approver?.name ?? (proc.approver_type === 'ai' ? t('approvals.review.aiApprover') : '');
}

// ISO timestamp → readable `dd.mm.yyyy HH:MM` (locale-agnostic numerals; matches
// the rest of the review drawer).
function formatDateTime(iso: string | null): string {
  if (!iso) return '';
  const m = iso.match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/);
  return m ? `${m[3]}.${m[2]}.${m[1]} ${m[4]}:${m[5]}` : iso;
}
</script>

<template>
  <ol class="flex flex-col gap-next-2">
    <li
      v-for="view in items"
      :key="view.stage.id"
      :title="view.state === 'upcoming' ? t('approvals.review.stageLocked') : undefined"
      :class="[
        'rounded-next-md border',
        view.state === 'upcoming' ? 'px-next-3 py-next-2' : 'p-next-3',
        view.state === 'current'
          ? 'border-next-primary bg-next-primary-subtle/40'
          : view.state === 'upcoming'
            ? 'border-next-border/60'
            : 'border-next-border',
      ]"
    >
      <div class="flex items-center gap-next-2">
        <span
          :class="['flex h-5 w-5 shrink-0 items-center justify-center rounded-next-full', nodeClass(view)]"
          aria-hidden="true"
        >
          <Icon :name="nodeIcon(view)" class="text-[0.7rem]" />
        </span>
        <span
          :class="[
            'min-w-0 flex-1 truncate text-next-sm',
            view.state === 'upcoming' ? 'text-next-muted-foreground' : 'font-next-medium text-next-fg',
          ]"
        >
          {{ view.stage.name }}
        </span>
        <span
          v-if="view.state === 'current'"
          class="shrink-0 text-next-xs font-next-medium text-next-warning"
        >
          {{ t('approvals.review.yourTurn') }}
        </span>
      </div>

      <!-- Current / past stage: criteria + decisions. Future/locked stages stay a
           single slim row (the header stepper already conveys what's ahead). -->
      <template v-if="view.state !== 'upcoming'">
        <p v-if="view.stage.description" class="mt-next-2 text-next-sm text-next-muted-foreground">
          {{ view.stage.description }}
        </p>
        <p v-else class="mt-next-2 text-next-xs italic text-next-muted-foreground/70">
          {{ t('approvals.review.noCriteria') }}
        </p>

        <ul v-if="view.processes.length" class="mt-next-2 flex flex-col gap-next-2">
          <li
            v-for="proc in view.processes"
            :key="proc.id"
            class="flex items-start gap-next-2 rounded-next-md bg-next-muted/40 p-next-2"
          >
            <Icon :name="approverTypeIcon(proc.approver_type)" class="mt-next-0_5 shrink-0 text-next-muted-foreground" />
            <div class="min-w-0 flex-1">
              <div class="flex flex-wrap items-center gap-next-2">
                <StatusBadge :status="proc.status" :status-map="statusMap" size="sm" />
                <span class="truncate text-next-xs text-next-muted-foreground">{{ approverName(proc) }}</span>
                <span v-if="proc.decided_at" class="ml-auto shrink-0 text-next-xs text-next-muted-foreground">
                  {{ formatDateTime(proc.decided_at) }}
                </span>
              </div>
              <p v-if="proc.note" class="mt-next-1 whitespace-pre-line text-next-sm text-next-fg">
                {{ proc.note }}
              </p>
            </div>
          </li>
        </ul>
        <p v-else-if="view.state === 'current'" class="mt-next-2 text-next-xs text-next-muted-foreground">
          {{ t('approvals.review.awaiting') }}
        </p>
        <p v-else class="mt-next-2 text-next-xs text-next-muted-foreground">
          {{ t('approvals.review.noHistory') }}
        </p>
      </template>
    </li>

    <!-- Historical "unknown stage" bucket (nulled FK). -->
    <li v-if="unknownGroup" class="rounded-next-md border border-next-border p-next-3">
      <div class="flex items-center gap-next-2">
        <span
          class="flex h-5 w-5 shrink-0 items-center justify-center rounded-next-full bg-next-muted text-next-muted-foreground"
          aria-hidden="true"
        >
          <Icon name="list" class="text-[0.7rem]" />
        </span>
        <span class="min-w-0 flex-1 truncate text-next-sm font-next-medium text-next-fg">
          {{ t('approvals.review.unknownStage') }}
        </span>
      </div>
      <p class="mt-next-2 text-next-xs italic text-next-muted-foreground/70">
        {{ t('approvals.review.unknownStageHint') }}
      </p>
      <ul class="mt-next-2 flex flex-col gap-next-2">
        <li
          v-for="proc in unknownGroup.processes"
          :key="proc.id"
          class="flex items-start gap-next-2 rounded-next-md bg-next-muted/40 p-next-2"
        >
          <Icon :name="approverTypeIcon(proc.approver_type)" class="mt-next-0_5 shrink-0 text-next-muted-foreground" />
          <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-next-2">
              <StatusBadge :status="proc.status" :status-map="statusMap" size="sm" />
              <span class="truncate text-next-xs text-next-muted-foreground">{{ approverName(proc) }}</span>
              <span v-if="proc.decided_at" class="ml-auto shrink-0 text-next-xs text-next-muted-foreground">
                {{ formatDateTime(proc.decided_at) }}
              </span>
            </div>
            <p v-if="proc.note" class="mt-next-1 whitespace-pre-line text-next-sm text-next-fg">
              {{ proc.note }}
            </p>
          </div>
        </li>
      </ul>
    </li>
  </ol>
</template>
