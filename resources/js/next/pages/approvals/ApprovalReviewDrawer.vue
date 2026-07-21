<script setup lang="ts">
// ApprovalReviewDrawer — the review surface for ONE approval process (next).
// Opened by `?review=<processId>` from the Queue; the page PREFETCHES the process
// detail + run history into the store, so this renders already populated (a
// fallback fetch covers a deep link / cache miss).
//
// Layout (a full-height, two-ZONE workspace — `evidence` on the left, `decision`
// on the right — so the form preview and the decision stop competing for one
// scroll column):
//   • Header     — entity identity + status badge + a horizontal STAGE STEPPER
//                  (done / current / locked at a glance) + close.
//   • Left pane  — "the submission" you're reviewing: a Form / Details toggle
//                  (read-only FormViewer + the entity's extra fields/description).
//                  Scrolls independently; it's reference material.
//   • Right pane — "the decision": a Decision / Comments toggle. Decision holds the
//                  vertical STAGE TIMELINE (criteria + prior decisions per stage)
//                  and a sticky decision CARD with a PROGRESSIVE note (collapsed →
//                  optional for approve, auto-expanded + required for reject) and
//                  the Approve / Reject actions. Comments hosts the shared CommentsPanel.
//
// Decision flow is unchanged: Approve / Reject shown ONLY for the current pending
// stage that is mine; reject needs a note (mirrors the backend `required_if`); on
// success → toast + close (the queue list already updated via optimistic removal);
// on 422 → "already decided" toast + refresh.
import { computed, nextTick, ref, watch } from 'vue';
import FormViewer from '../forms/FormViewer.vue';
import MarkdownViewer from '../../ui/editor/MarkdownViewer.vue';
import CommentsPanel from '../../ui/patterns/CommentsPanel.vue';
import ApprovalStageStepper from './ApprovalStageStepper.vue';
import ApprovalStageTimeline from './ApprovalStageTimeline.vue';
import Tabs, { type TabItem } from '../../ui/navigation/Tabs.vue';
import StatusBadge, { type StatusMap } from '../../ui/data/StatusBadge.vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import { ICONS, type IconName } from '../../ui/primitives/icons';
import Spinner from '../../ui/primitives/Spinner.vue';
import Alert from '../../ui/feedback/Alert.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import FormField from '../../ui/forms/FormField.vue';
import Textarea from '../../ui/forms/Textarea.vue';
import { useApprovalQueueStore, groupRunHistory, type RunHistoryGroup } from '../../app/stores/approvalQueue';
import { useToast } from '../../app/composables/useToast';
import { useI18n } from '../../app/i18n';
import { approvalStatusMap } from './approvalStatus';
import { entityDescriptionToMarkdown } from './entityDescription';
import { buildStageViews, type StageView } from './stageView';
import type { ApprovalProcess, DecisionError } from './queue-types';

const props = defineProps<{
  /** The pending process under review (`?review=<processId>`). */
  processId: string;
}>();

const emit = defineEmits<{
  (e: 'close'): void;
  /** A decision was recorded (the queue store already removed the item). */
  (e: 'decided', process: ApprovalProcess): void;
}>();

const { t } = useI18n();
const store = useApprovalQueueStore();
const toast = useToast();

const statusMap = computed<StatusMap>(() => approvalStatusMap(t));

// --- Process detail (from the prefetch cache, with a deep-link fallback) ---
const loading = ref(false);
const loadError = ref<string | null>(null);

const process = computed<ApprovalProcess | null>(() => store.processCache[props.processId] ?? null);
// The matching queue item carries the entity payload (form, extra_fields, …).
const queueItem = computed(() =>
  store.items.find((it) => it.process.id === props.processId) ?? null,
);
const entity = computed(() => queueItem.value?.entity ?? null);
// The entity description as MARKDOWN (the backend may send a ProseMirror doc).
const descriptionMarkdown = computed(() => entityDescriptionToMarkdown(entity.value?.description));

// extra_fields carry a LEGACY IconEnum value; map the ones the next registry ships
// (priority / deadline glyphs), else render no icon rather than a wrong one.
const FIELD_ICON_ALIASES: Record<string, IconName> = { 'minus-circle': 'minus' };
function fieldIcon(icon: string | null): IconName | null {
  if (!icon) return null;
  if (FIELD_ICON_ALIASES[icon]) return FIELD_ICON_ALIASES[icon];
  return icon in ICONS ? (icon as IconName) : null;
}

const runId = computed(() => process.value?.run_id ?? null);
const history = computed<ApprovalProcess[]>(() =>
  runId.value ? (store.runHistoryCache[runId.value] ?? []) : [],
);

// Fallback hydration (deep link / cache miss): fetch the process, then its run.
watch(
  () => props.processId,
  async (id) => {
    if (!id) return;
    if (process.value && runId.value && store.runHistoryCache[runId.value]) return;
    loading.value = true;
    loadError.value = null;
    try {
      const proc = process.value ?? (await store.fetchProcess(id));
      if (proc?.run_id && !store.runHistoryCache[proc.run_id]) {
        await store.fetchRunHistory(proc.run_id);
      }
      if (!proc) loadError.value = t('approvals.review.errors.loadFailed');
    } catch {
      loadError.value = t('approvals.review.errors.loadFailed');
    } finally {
      loading.value = false;
    }
  },
  { immediate: true },
);

// --- Stages (stepper + timeline share ONE view-model) ---------------------
const stages = computed(() => process.value?.pipeline?.stages ?? []);
const currentStage = computed(() => process.value?.stage ?? null);
const currentOrder = computed(() => currentStage.value?.order ?? null);

const historyGroups = computed<RunHistoryGroup[]>(() => groupRunHistory(history.value));
const stageViews = computed<StageView[]>(() =>
  buildStageViews(stages.value, historyGroups.value, currentOrder.value),
);
// A historical "unknown stage" bucket (nulled FK) → trailing timeline section.
const unknownGroup = computed<RunHistoryGroup | undefined>(() =>
  historyGroups.value.find((g) => g.stageId === null),
);

// --- Left pane (evidence): Form / Details toggle --------------------------
const hasForm = computed(() => !!entity.value?.form);
const leftTab = ref<'form' | 'details'>('form');
const leftTabs = computed<TabItem<'form' | 'details'>[]>(() => {
  const items: TabItem<'form' | 'details'>[] = [];
  if (hasForm.value) items.push({ value: 'form', label: t('approvals.review.evidence.form'), icon: 'file-text' });
  items.push({ value: 'details', label: t('approvals.review.evidence.details'), icon: 'list' });
  return items;
});
watch(hasForm, (has) => { leftTab.value = has ? 'form' : 'details'; }, { immediate: true });

// --- Right pane (decision): Decision / Comments toggle --------------------
const rightTab = ref<'decision' | 'comments'>('decision');
// CommentsPanel owns its list; it reports its loaded count so the tab can badge
// "there's discussion here" without the drawer refetching.
const commentsCount = ref(0);
const rightTabs = computed<TabItem<'decision' | 'comments'>[]>(() => {
  const items: TabItem<'decision' | 'comments'>[] = [
    { value: 'decision', label: t('approvals.review.tabs.decision'), icon: 'check-circle' },
  ];
  if (entity.value?.comments_url) {
    items.push({
      value: 'comments',
      label: t('approvals.review.tabs.comments'),
      icon: 'mail',
      badge: commentsCount.value || undefined,
    });
  }
  return items;
});

// --- Decision (only for MY current pending stage) -------------------------
const canDecide = computed(
  () =>
    !!process.value &&
    process.value.status === 'pending' &&
    process.value.approver_type === 'user',
);

// The note is PROGRESSIVE: collapsed by default (optional for an approval), and
// auto-expanded + required the moment a rejection is attempted (mirrors the
// backend `required_if`). `submittingKind` tracks which button is busy.
const note = ref('');
const noteError = ref<string | null>(null);
const noteOpen = ref(false);
const noteWrap = ref<HTMLElement | null>(null);
const submitting = ref(false);
const submittingKind = ref<'approved' | 'rejected' | null>(null);

function openNote(): void {
  noteOpen.value = true;
  nextTick(() => noteWrap.value?.querySelector('textarea')?.focus());
}

async function decide(decision: 'approved' | 'rejected'): Promise<void> {
  if (submitting.value) return;
  noteError.value = null;
  // A reject needs a non-blank note; an approval may carry one but doesn't require it.
  if (decision === 'rejected' && !note.value.trim()) {
    noteError.value = t('approvals.review.errors.noteRequired');
    openNote();
    return;
  }
  submitting.value = true;
  submittingKind.value = decision;
  try {
    const updated = await store.makeDecision(props.processId, {
      decision,
      note: note.value.trim() || null,
    });
    toast.success(
      decision === 'approved'
        ? t('approvals.review.toasts.approved')
        : t('approvals.review.toasts.rejected'),
    );
    emit('decided', updated);
    emit('close');
  } catch (err: unknown) {
    const structured = err as Partial<DecisionError>;
    if (structured?.kind === 'already_decided') {
      // The store already refetched the queue + count; just inform + close.
      toast.warning(t(structured.messageKey ?? 'approvals.review.errors.alreadyDecided'));
      emit('close');
    } else if (structured?.kind === 'note_required') {
      noteError.value = t(structured.messageKey ?? 'approvals.review.errors.noteRequired');
      openNote();
    } else {
      toast.danger(t('approvals.review.errors.decideFailed'));
    }
  } finally {
    submitting.value = false;
    submittingKind.value = null;
  }
}

function onApprove(): void {
  void decide('approved');
}
function onReject(): void {
  void decide('rejected');
}
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col">
    <!-- Loading (deep-link fallback). -->
    <div v-if="loading && !process" class="flex flex-1 items-center justify-center py-next-10">
      <Spinner size="lg" tone="muted" :label="t('approvals.review.loading')" />
    </div>

    <!-- Load error. -->
    <EmptyState
      v-else-if="loadError && !process"
      variant="error"
      :title="t('approvals.review.errors.title')"
      :description="loadError"
    >
      <template #action>
        <Button variant="outline" size="sm" @click="emit('close')">
          {{ t('common.close') }}
        </Button>
      </template>
    </EmptyState>

    <template v-else-if="process">
      <!-- Header: identity + status + stage stepper + close. -->
      <header class="flex flex-col gap-next-3 border-b border-next-border p-next-4">
        <div class="flex items-start justify-between gap-next-3">
          <div class="flex min-w-0 items-start gap-next-3">
            <span
              class="flex h-10 w-10 shrink-0 items-center justify-center rounded-next-lg bg-next-primary text-next-primary-foreground"
              aria-hidden="true"
            >
              <Icon name="inbox" class="text-next-lg" />
            </span>
            <div class="min-w-0">
              <p class="text-next-xs uppercase tracking-next-wide text-next-muted-foreground">
                {{ entity?.type_label ?? t('approvals.queue.card.unknownType') }}
              </p>
              <h2 class="truncate text-next-lg font-next-semibold text-next-fg">
                {{ entity?.name ?? t('approvals.queue.card.unknownEntity') }}
              </h2>
            </div>
          </div>
          <div class="flex shrink-0 items-center gap-next-2">
            <StatusBadge :status="process.status" :status-map="statusMap" />
            <Button
              variant="outline"
              size="icon-sm"
              leading-icon="x"
              :aria-label="t('common.close')"
              @click="emit('close')"
            />
          </div>
        </div>

        <ApprovalStageStepper
          v-if="stageViews.length"
          :items="stageViews"
          :aria-label="t('approvals.review.stepperLabel')"
          class="hidden next-md:flex"
        />
      </header>

      <!-- Body: evidence (left) + decision (right). Each pane scrolls itself. -->
      <div class="flex min-h-0 flex-1 overflow-hidden">
        <!-- Left pane: the submission under review. -->
        <section class="flex min-w-0 flex-[1.4] flex-col border-r border-next-border">
          <Tabs
            v-model="leftTab"
            :items="leftTabs"
            variant="pills"
            fill
            class="p-next-4"
            :aria-label="t('approvals.review.evidence.label')"
          >
            <template #panel-form>
              <div class="min-h-0 flex-1 overflow-y-auto">
                <div class="rounded-next-lg border border-next-border bg-next-card p-next-4">
                  <FormViewer
                    mode="preview"
                    :content="entity?.form?.content ?? []"
                    :initial-data="entity?.form?.submission ?? null"
                  />
                </div>
              </div>
            </template>

            <template #panel-details>
              <div class="min-h-0 flex-1 overflow-y-auto">
                <div
                  v-if="descriptionMarkdown || entity?.extra_fields?.length"
                  class="flex flex-col gap-next-4"
                >
                  <!-- Fields first: compact meta chips (icon + label + value). -->
                  <dl v-if="entity?.extra_fields?.length" class="flex flex-wrap gap-next-2">
                    <div
                      v-for="(field, i) in entity.extra_fields"
                      :key="i"
                      class="inline-flex items-center gap-next-2 rounded-next-md border border-next-border bg-next-card px-next-3 py-next-1_5"
                    >
                      <Icon
                        v-if="fieldIcon(field.icon)"
                        :name="fieldIcon(field.icon)!"
                        class="shrink-0 text-next-muted-foreground"
                      />
                      <dt class="text-next-xs text-next-muted-foreground">{{ field.label }}</dt>
                      <dd class="text-next-sm font-next-medium text-next-fg">{{ field.value }}</dd>
                    </div>
                  </dl>
                  <!-- Description below, rendered as markdown. -->
                  <MarkdownViewer
                    v-if="descriptionMarkdown"
                    :source="descriptionMarkdown"
                    :aria-label="t('approvals.review.evidence.details')"
                  />
                </div>
                <EmptyState
                  v-else
                  size="sm"
                  icon="list"
                  :title="t('approvals.review.evidence.noDetails')"
                />
              </div>
            </template>
          </Tabs>
        </section>

        <!-- Right pane: the decision. -->
        <section class="flex min-w-0 flex-1 flex-col bg-next-muted/30">
          <Tabs
            v-model="rightTab"
            :items="rightTabs"
            variant="pills"
            fill
            class="p-next-4"
            :aria-label="t('approvals.review.decisionLabel')"
          >
            <!-- Decision: stage timeline + sticky decision card. -->
            <template #panel-decision>
              <div class="flex min-h-0 flex-1 flex-col gap-next-3">
                <div class="min-h-0 flex-1 overflow-y-auto">
                  <ApprovalStageTimeline
                    :items="stageViews"
                    :status-map="statusMap"
                    :unknown-group="unknownGroup"
                  />
                </div>

                <!-- Decision actions (only for MY current pending stage). -->
                <div
                  v-if="canDecide"
                  class="shrink-0 rounded-next-lg border border-next-border bg-next-card p-next-3"
                >
                  <div v-if="noteOpen" ref="noteWrap" class="mb-next-3">
                    <FormField
                      :label="t('approvals.review.noteLabel')"
                      :description="t('approvals.review.noteHint')"
                      :error="noteError ?? undefined"
                    >
                      <Textarea
                        v-model="note"
                        :rows="3"
                        :maxlength="2500"
                        :placeholder="t('approvals.review.notePlaceholder')"
                        :aria-invalid="!!noteError"
                      />
                    </FormField>
                  </div>
                  <button
                    v-else
                    type="button"
                    class="mb-next-3 flex items-center gap-next-1_5 text-next-xs text-next-muted-foreground transition-colors hover:text-next-primary"
                    @click="openNote"
                  >
                    <Icon name="pencil" class="shrink-0" />
                    {{ t('approvals.review.addNote') }}
                    <span class="text-next-muted-foreground/70">{{ t('approvals.review.addNoteHint') }}</span>
                  </button>

                  <div class="flex items-center justify-end gap-next-2">
                    <Button
                      variant="danger"
                      leading-icon="x-circle"
                      :loading="submittingKind === 'rejected'"
                      :disabled="submitting"
                      @click="onReject"
                    >
                      {{ t('approvals.review.reject') }}
                    </Button>
                    <Button
                      variant="primary"
                      leading-icon="check-circle"
                      :loading="submittingKind === 'approved'"
                      :disabled="submitting"
                      @click="onApprove"
                    >
                      {{ t('approvals.review.approve') }}
                    </Button>
                  </div>
                </div>

                <!-- A non-pending or non-mine process: state-only note. -->
                <Alert v-else variant="info" size="sm" class="shrink-0">
                  {{ t('approvals.review.notDecidable') }}
                </Alert>
              </div>
            </template>

            <!-- Comments: kept mounted so the count + draft survive tab switches. -->
            <template v-if="entity?.comments_url" #panel-comments>
              <div class="flex min-h-0 flex-1 flex-col">
                <CommentsPanel
                  :comments-url="entity.comments_url"
                  class="min-h-0 flex-1"
                  @count="commentsCount = $event"
                />
              </div>
            </template>
          </Tabs>
        </section>
      </div>
    </template>
  </div>
</template>
