<script setup lang="ts">
// ApprovalReviewDrawer — the large review surface for ONE approval process
// (next, Batch 2). Opened by `?review=<processId>` from the Queue; the page
// PREFETCHES the process detail + run history into the store, so this renders
// already populated (a fallback fetch covers a deep link / cache miss).
//
// Layout:
//   • Header  — entity type label + name + a pending/approved/rejected status
//               badge (icon + text, never color-only).
//   • Body    — entity description, the `extra_fields` as a labeled chip list, and
//               a read-only FORM PREVIEW (FormViewer in preview mode hydrated with
//               the entity form's content + submission) when a form is present.
//   • Stage tabs — one tab per pipeline stage; FUTURE stages (order > the current
//               stage's order) are DISABLED. Each tab shows the stage criteria
//               (description) + that stage's decision history, grouped from the
//               run history (tolerating a null stage → "unknown stage" bucket).
//   • Decision actions — Approve / Reject, shown ONLY for the current pending
//               stage that is mine. Reject reveals a REQUIRED note Textarea (its
//               error blocks submit). On success → toast + close (the queue list
//               already updated via the store's optimistic removal). On 422 →
//               toast "already decided" and refresh.
//
// A right-side rail hosts the comments panel (ApprovalComments) when the entity
// exposes `comments_url` (Batch 3); otherwise no rail is rendered.
import { computed, ref, watch } from 'vue';
import FormViewer from '../forms/FormViewer.vue';
import ApprovalComments from './ApprovalComments.vue';
import Tabs, { type TabItem } from '../../ui/navigation/Tabs.vue';
import StatusBadge, { type StatusMap } from '../../ui/data/StatusBadge.vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Spinner from '../../ui/primitives/Spinner.vue';
import Alert from '../../ui/feedback/Alert.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import FormField from '../../ui/forms/FormField.vue';
import Textarea from '../../ui/forms/Textarea.vue';
import { useApprovalQueueStore, groupRunHistory, type RunHistoryGroup } from '../../app/stores/approvalQueue';
import { useToast } from '../../app/composables/useToast';
import { useI18n } from '../../app/i18n';
import { approvalStatusMap, approverTypeIcon } from './approvalStatus';
import { entityDescriptionToText } from './entityDescription';
import type { ApprovalProcess, ApprovalProcessStatus, DecisionError } from './queue-types';

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
// The entity description as readable text (the backend may send a ProseMirror doc).
const descriptionText = computed(() => entityDescriptionToText(entity.value?.description));

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

// --- Stages + tabs --------------------------------------------------------
const stages = computed(() => process.value?.pipeline?.stages ?? []);
const currentStage = computed(() => process.value?.stage ?? null);
const currentOrder = computed(() => currentStage.value?.order ?? null);

// Group run history by stage (tolerating a null stage → "unknown" bucket).
const historyGroups = computed<RunHistoryGroup[]>(() => groupRunHistory(history.value));

function groupForStage(stageId: string): RunHistoryGroup | undefined {
  return historyGroups.value.find((g) => g.stageId === stageId);
}
const unknownGroup = computed<RunHistoryGroup | undefined>(() =>
  historyGroups.value.find((g) => g.stageId === null),
);

// One tab per pipeline stage; future stages (order beyond the current) disabled.
const tabItems = computed<TabItem<string>[]>(() => {
  const list: TabItem<string>[] = stages.value.map((s) => ({
    value: s.id,
    label: s.name,
    disabled: currentOrder.value != null && s.order > currentOrder.value,
  }));
  // A historical "unknown stage" bucket (nulled FK) gets its own trailing tab.
  if (unknownGroup.value) {
    list.push({ value: '__unknown__', label: t('approvals.review.unknownStage') });
  }
  return list;
});

const activeTab = ref<string | null>(null);
watch(
  currentStage,
  (stage) => {
    if (stage?.id) activeTab.value = stage.id;
  },
  { immediate: true },
);

function stageById(id: string) {
  return stages.value.find((s) => s.id === id) ?? null;
}

// --- Decision (only for MY current pending stage) -------------------------
const canDecide = computed(
  () =>
    !!process.value &&
    process.value.status === 'pending' &&
    process.value.approver_type === 'user',
);

const rejecting = ref(false);
const note = ref('');
const noteError = ref<string | null>(null);
const submitting = ref(false);

function startReject(): void {
  rejecting.value = true;
  noteError.value = null;
}
function cancelReject(): void {
  rejecting.value = false;
  note.value = '';
  noteError.value = null;
}

async function decide(decision: 'approved' | 'rejected'): Promise<void> {
  if (submitting.value) return;
  noteError.value = null;
  // Mirror the backend `required_if`: a reject needs a non-blank note.
  if (decision === 'rejected' && !note.value.trim()) {
    noteError.value = t('approvals.review.errors.noteRequired');
    return;
  }
  submitting.value = true;
  try {
    const updated = await store.makeDecision(props.processId, {
      decision,
      note: decision === 'rejected' ? note.value.trim() : null,
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
    } else {
      toast.danger(t('approvals.review.errors.decideFailed'));
    }
  } finally {
    submitting.value = false;
  }
}

function statusOf(status: ApprovalProcessStatus): ApprovalProcessStatus {
  return status;
}

// ISO timestamp → readable `dd.mm.yyyy HH:MM` (locale-agnostic numerals).
function formatDateTime(iso: string | null): string {
  if (!iso) return '';
  const m = iso.match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/);
  return m ? `${m[3]}.${m[2]}.${m[1]} ${m[4]}:${m[5]}` : iso;
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
      <!-- Header: entity type + name + status. -->
      <header class="flex items-start justify-between gap-next-3 border-b border-next-border p-next-4">
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
      </header>

      <!-- Body: two columns — review content + reserved comments rail. -->
      <div class="flex min-h-0 flex-1 gap-next-4 overflow-hidden p-next-4">
        <div class="flex min-w-0 flex-1 flex-col gap-next-5 overflow-y-auto">
          <!-- Entity description (plain text; the backend may send a doc object). -->
          <p v-if="descriptionText" class="whitespace-pre-line text-next-sm text-next-muted-foreground">
            {{ descriptionText }}
          </p>

          <!-- Extra fields (icon + label + value chips). -->
          <div
            v-if="entity?.extra_fields?.length"
            class="flex flex-wrap gap-next-2"
          >
            <span
              v-for="(field, i) in entity.extra_fields"
              :key="i"
              class="inline-flex items-center gap-next-1_5 rounded-next-md bg-next-muted px-next-2_5 py-next-1_5 text-next-xs"
            >
              <span class="font-next-medium text-next-muted-foreground">{{ field.label }}:</span>
              <span class="text-next-fg">{{ field.value }}</span>
            </span>
          </div>

          <!-- Form preview (read-only). -->
          <section v-if="entity?.form" class="flex flex-col gap-next-3">
            <h3 class="text-next-sm font-next-semibold text-next-fg">
              {{ t('approvals.review.formTitle') }}
            </h3>
            <div class="rounded-next-lg border border-next-border bg-next-card p-next-4">
              <FormViewer
                mode="preview"
                :content="entity.form.content"
                :initial-data="entity.form.submission"
              />
            </div>
          </section>

          <!-- Stage tabs: criteria + decision history per stage. -->
          <section v-if="tabItems.length" class="flex flex-col gap-next-3">
            <h3 class="text-next-sm font-next-semibold text-next-fg">
              {{ t('approvals.review.stagesTitle') }}
            </h3>
            <Tabs
              v-model="activeTab"
              :items="tabItems"
              :aria-label="t('approvals.review.stagesTitle')"
            >
              <template
                v-for="stage in stages"
                #[`panel-${stage.id}`]
                :key="stage.id"
              >
                <div class="flex flex-col gap-next-3 pt-next-1">
                  <!-- Stage criteria (description). -->
                  <p v-if="stage.description" class="text-next-sm text-next-muted-foreground">
                    {{ stage.description }}
                  </p>
                  <p v-else class="text-next-sm text-next-muted-foreground/70 italic">
                    {{ t('approvals.review.noCriteria') }}
                  </p>

                  <!-- Decision history for this stage. -->
                  <ul
                    v-if="groupForStage(stage.id)?.processes.length"
                    class="flex flex-col gap-next-2"
                  >
                    <li
                      v-for="proc in groupForStage(stage.id)!.processes"
                      :key="proc.id"
                      class="flex items-start gap-next-2 rounded-next-md border border-next-border p-next-3"
                    >
                      <Icon :name="approverTypeIcon(proc.approver_type)" class="mt-next-0_5 shrink-0 text-next-muted-foreground" />
                      <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-next-2">
                          <StatusBadge :status="statusOf(proc.status)" :status-map="statusMap" size="sm" />
                          <span class="truncate text-next-xs text-next-muted-foreground">
                            {{ proc.approver?.name ?? (proc.approver_type === 'ai' ? t('approvals.review.aiApprover') : '') }}
                          </span>
                          <span v-if="proc.decided_at" class="ml-auto shrink-0 text-next-xs text-next-muted-foreground">
                            {{ formatDateTime(proc.decided_at) }}
                          </span>
                        </div>
                        <p v-if="proc.note" class="mt-next-1 text-next-sm text-next-fg">{{ proc.note }}</p>
                      </div>
                    </li>
                  </ul>
                  <p v-else class="text-next-xs text-next-muted-foreground">
                    {{ t('approvals.review.noHistory') }}
                  </p>
                </div>
              </template>

              <!-- Historical "unknown stage" bucket (nulled FK). -->
              <template v-if="unknownGroup" #panel-__unknown__>
                <div class="flex flex-col gap-next-3 pt-next-1">
                  <p class="text-next-sm text-next-muted-foreground/70 italic">
                    {{ t('approvals.review.unknownStageHint') }}
                  </p>
                  <ul class="flex flex-col gap-next-2">
                    <li
                      v-for="proc in unknownGroup.processes"
                      :key="proc.id"
                      class="flex items-start gap-next-2 rounded-next-md border border-next-border p-next-3"
                    >
                      <Icon :name="approverTypeIcon(proc.approver_type)" class="mt-next-0_5 shrink-0 text-next-muted-foreground" />
                      <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-next-2">
                          <StatusBadge :status="statusOf(proc.status)" :status-map="statusMap" size="sm" />
                          <span v-if="proc.decided_at" class="ml-auto shrink-0 text-next-xs text-next-muted-foreground">
                            {{ formatDateTime(proc.decided_at) }}
                          </span>
                        </div>
                        <p v-if="proc.note" class="mt-next-1 text-next-sm text-next-fg">{{ proc.note }}</p>
                      </div>
                    </li>
                  </ul>
                </div>
              </template>
            </Tabs>
          </section>
        </div>

        <!-- Comments rail (Batch 3): a standalone, drawer-scoped panel. Rendered
             only when the entity exposes a comments URL; otherwise no rail. -->
        <aside
          v-if="entity?.comments_url"
          class="hidden w-80 shrink-0 flex-col overflow-hidden rounded-next-lg border border-next-border bg-next-card p-next-4 next-lg:flex"
          :aria-label="t('approvals.comments.title')"
        >
          <div class="mb-next-3 flex shrink-0 items-center gap-next-2 text-next-sm font-next-semibold text-next-fg">
            <Icon name="mail" class="shrink-0 text-next-muted-foreground" />
            {{ t('approvals.comments.title') }}
          </div>
          <ApprovalComments :comments-url="entity.comments_url" class="min-h-0 flex-1" />
        </aside>
      </div>

      <!-- Decision actions (only for MY current pending stage). -->
      <footer
        v-if="canDecide"
        class="flex flex-col gap-next-3 border-t border-next-border p-next-4"
      >
        <div v-if="rejecting" class="flex flex-col gap-next-2">
          <FormField
            :label="t('approvals.review.noteLabel')"
            :error="noteError ?? undefined"
            required
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

        <div class="flex items-center justify-end gap-next-2">
          <template v-if="rejecting">
            <Button variant="ghost" :disabled="submitting" @click="cancelReject">
              {{ t('common.cancel') }}
            </Button>
            <Button
              variant="danger"
              leading-icon="x-circle"
              :loading="submitting"
              @click="decide('rejected')"
            >
              {{ t('approvals.review.confirmReject') }}
            </Button>
          </template>
          <template v-else>
            <Button
              variant="outline"
              leading-icon="x-circle"
              :disabled="submitting"
              @click="startReject"
            >
              {{ t('approvals.review.reject') }}
            </Button>
            <Button
              variant="primary"
              leading-icon="check-circle"
              :loading="submitting"
              @click="decide('approved')"
            >
              {{ t('approvals.review.approve') }}
            </Button>
          </template>
        </div>
      </footer>

      <!-- A non-pending or non-mine process: state-only footer note. -->
      <footer
        v-else
        class="border-t border-next-border p-next-4"
      >
        <Alert variant="info" size="sm">
          {{ t('approvals.review.notDecidable') }}
        </Alert>
      </footer>
    </template>
  </div>
</template>
