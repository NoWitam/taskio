<script setup lang="ts">
// PublicationApprovalPanel — the detail's Approval tab (§11). READ-ONLY, always.
//
// Decisions are made in the Approvals module and nowhere else. That is an invariant of the
// existing implementation and Publishing has no reason to break it.
//
// ═════════════════════════════════════════════════════════════════════════════════════════
// WHAT THIS PANEL CAN HONESTLY SHOW, AND WHAT IT CANNOT — A CONTRACT GAP, NOT A SHORTCUT
// ═════════════════════════════════════════════════════════════════════════════════════════
// `TaskResource` carries `approval_pipeline` (eager, with stages), `pending_approval_process`
// and `approval_run_id`, which is how the Task drawer draws a stepper with a LIVE stage, a
// per-stage decision history, notes and times.
//
// `PublicationResource` carries three scalars: `approval_pipeline_id`, `is_in_approval` and
// `approval_state`. There is no run id, so `GET /approvals/runs/{runId}` cannot be called,
// and `groupRunHistory` has nothing to group. Per-stage statuses therefore CANNOT be
// derived — and inventing them would be worse than omitting them: a stage rendered
// "upcoming" when an approver already passed it is a confident false statement about who
// decided what.
//
// So this panel shows what the contract does support — the path, the approver per stage, and
// the publication's own overall review state — and says plainly where the decisions live.
// `buildApprovalTabModel` is still the source of ORDER and of the per-stage approver shape,
// so the day the resource grows a run id this panel gains history without being rewritten.
// The gap is reported in the batch report.
import { computed, ref, watch } from 'vue';
import Alert from '../../ui/feedback/Alert.vue';
import Badge from '../../ui/primitives/Badge.vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Avatar from '../../ui/primitives/Avatar.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import Skeleton from '../../ui/data/Skeleton.vue';
import { useRouter } from 'vue-router';
import { useApprovalPipelinesStore } from '../../app/stores/approvalPipelines';
import { buildApprovalTabModel } from '../tasks/approvalTabModel';
import { resolveApprover, approverTypeIcon } from '../approvals/approver';
import { resolvePipelineIcon } from '../../ui/forms/pipelineIcon';
import { useI18n } from '../../app/i18n';
import type { ApprovalPipeline } from '../approvals/types';
import type { Publication } from './types';

const props = defineProps<{ publication: Publication }>();

const { t } = useI18n();
const router = useRouter();
const pipelines = useApprovalPipelinesStore();

const pipeline = ref<ApprovalPipeline | null>(null);
const loading = ref(false);
const failed = ref(false);

watch(
  () => props.publication.approval_pipeline_id,
  async (id) => {
    pipeline.value = null;
    failed.value = false;
    if (!id) return;
    loading.value = true;
    try {
      pipeline.value = await pipelines.fetchPipeline(id);
    } catch {
      failed.value = true;
    } finally {
      loading.value = false;
    }
  },
  { immediate: true },
);

/**
 * Stage ORDER and the per-stage approver shape, from the same builder the Task tab uses.
 * `pending` is null and the history is empty because the contract supplies neither — see the
 * file docblock. The resulting `status` field is deliberately NOT rendered.
 */
const steps = computed(() => buildApprovalTabModel(pipeline.value?.stages ?? [], null, []).steps);

/** The publication's own overall review state — the only thing that IS in the contract. */
const overall = computed(() => {
  if (props.publication.is_in_approval) {
    return { text: t('publishing.approval.inApproval'), variant: 'info' as const, icon: 'clock' as const };
  }
  if (props.publication.approval_state === 'approved') {
    return { text: t('publishing.approval.approved'), variant: 'success' as const, icon: 'check-circle' as const };
  }
  if (props.publication.approval_state === 'rejected') {
    return { text: t('publishing.approval.rejected'), variant: 'danger' as const, icon: 'x-circle' as const };
  }
  return null;
});

function goToApprovals(): void {
  void router.push({ name: 'next.approvals.queue' });
}
</script>

<template>
  <div class="flex flex-col gap-next-4">
    <!-- No pipeline at all: a statement, not a promise of something later. -->
    <EmptyState
      v-if="!publication.approval_pipeline_id"
      variant="default"
      icon="git-branch"
      :title="t('publishing.approval.noPipeline')"
      :description="t('publishing.approval.noPipelineHint')"
    />

    <template v-else>
      <div v-if="loading" class="flex flex-col gap-next-2">
        <Skeleton variant="text" width="40%" :label="t('common.loading')" />
        <Skeleton variant="rect" height="3rem" :count="3" />
      </div>

      <Alert v-else-if="failed" variant="danger" size="sm">
        {{ t('publishing.approval.loadError') }}
      </Alert>

      <template v-else>
        <header class="flex items-center gap-next-2">
          <Icon :name="resolvePipelineIcon(pipeline?.icon)" class="text-next-lg" aria-hidden="true" />
          <h2 class="min-w-0 flex-1 truncate text-next-base font-next-semibold text-next-fg">
            {{ pipeline?.name ?? t('publishing.approval.pipelineTitle') }}
          </h2>
          <Badge v-if="overall" :variant="overall.variant" tone="subtle" size="sm" :icon="overall.icon">
            {{ overall.text }}
          </Badge>
        </header>

        <!-- A pipeline is attached but nobody is deciding: the state a person is most
             likely to misread as "already approved". -->
        <Alert
          v-if="!publication.is_in_approval && publication.approval_state === null"
          variant="info"
          size="sm"
        >
          {{ t('publishing.approval.notInApproval') }}
        </Alert>

        <!-- The PATH. No per-stage verdicts are claimed — see the file docblock. -->
        <ol class="flex flex-col gap-next-2">
          <li
            v-for="(step, index) in steps"
            :key="step.id"
            class="flex items-start gap-next-3 rounded-next-lg border border-next-border bg-next-card p-next-3"
          >
            <span
              class="flex h-7 w-7 shrink-0 items-center justify-center rounded-next-full bg-next-muted text-next-xs font-next-medium tabular-nums text-next-muted-foreground"
              aria-hidden="true"
            >
              {{ index + 1 }}
            </span>
            <div class="min-w-0 flex-1">
              <p class="truncate text-next-sm font-next-medium text-next-fg">{{ step.name }}</p>
              <p v-if="step.description" class="truncate text-next-xs text-next-muted-foreground">
                {{ step.description }}
              </p>

              <p class="mt-next-1 flex items-center gap-next-1 text-next-xs text-next-muted-foreground">
                <template v-if="resolveApprover(step)">
                  <Avatar
                    v-if="!resolveApprover(step)!.isBot"
                    :name="resolveApprover(step)!.name ?? ''"
                    :src="resolveApprover(step)!.avatar"
                    size="xs"
                  />
                  <Icon
                    v-else
                    :name="approverTypeIcon(step.approver_type)"
                    class="text-next-xs"
                    aria-hidden="true"
                  />
                  <span class="truncate">{{ resolveApprover(step)!.name ?? '' }}</span>
                </template>
                <template v-else>
                  <Icon
                    :name="approverTypeIcon(step.approver_type)"
                    class="text-next-xs"
                    aria-hidden="true"
                  />
                  <span>{{ t('publishing.approval.approver') }}</span>
                </template>
              </p>
            </div>
          </li>
        </ol>

        <!-- Where the decisions — and their notes and times — actually live. -->
        <div class="flex flex-wrap items-center justify-between gap-next-2">
          <p class="text-next-xs text-next-muted-foreground">
            {{ t('publishing.approval.decisionsElsewhere') }}
          </p>
          <Button variant="outline" size="sm" leading-icon="git-branch" @click="goToApprovals">
            {{ t('publishing.approval.goToApprovals') }}
          </Button>
        </div>
      </template>
    </template>
  </div>
</template>
