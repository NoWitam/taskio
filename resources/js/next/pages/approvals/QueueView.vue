<script setup lang="ts">
// QueueView — the Approvals → Queue page for the "next" frontend (Batch 2): a
// cursor-paginated list of the items waiting for MY decision. The server already
// filters to approver=ME / status=pending, so the queue has ZERO filterable
// params — there is NO FilterBar (and no Saved Views) here, unlike the Pipelines
// list. Just a card grid with infinite scroll, card-shaped skeletons, an
// EmptyState ("nothing to approve"), and an error state with retry.
//
// A card click PREFETCHES the process detail + the run history, THEN opens the
// review DRAWER (owned by ApprovalsModuleLayout) via the `?review=<processId>`
// query key — so the drawer renders already populated with no loading flash.
// The `?review` overlay key is preserved across navigations by the module layout.
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import PageHeader from '../../ui/patterns/PageHeader.vue';
import Alert from '../../ui/feedback/Alert.vue';
import Button from '../../ui/primitives/Button.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import EntityCard from '../../ui/patterns/EntityCard.vue';
import QueueItemCard from './QueueItemCard.vue';
import { useApprovalQueueStore } from '../../app/stores/approvalQueue';
import { useInfiniteScroll } from '../../app/composables/useInfiniteScroll';
import { useToast } from '../../app/composables/useToast';
import { useI18n } from '../../app/i18n';
import type { ApprovalQueueItem } from './queue-types';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const store = useApprovalQueueStore();
const toast = useToast();

// --- Fetch orchestration --------------------------------------------------
function refetch(): void {
  void store.fetchQueue({ reset: true });
}

// --- Infinite scroll (page-scroll → observe the viewport) -----------------
const { sentinelRef } = useInfiniteScroll({
  onLoadMore: () => store.loadMore(),
  canLoadMore: () => store.hasMore && !store.loading && !store.loadingMore && !store.errored,
});

// --- List view-state ------------------------------------------------------
const items = computed(() => store.items);
const initialLoading = computed(() => store.loading && items.value.length === 0);
const isEmpty = computed(
  () => !store.loading && !store.loadingMore && !store.errored && items.value.length === 0,
);
const skeletonKeys = Array.from({ length: 6 }, (_, i) => i);

// --- Open the review drawer (PREFETCH then navigate) ----------------------
const opening = ref<string | null>(null);
async function onReview(item: ApprovalQueueItem): Promise<void> {
  const processId = item.process.id;
  if (opening.value) return;
  opening.value = processId;
  try {
    // Prefetch the detail + run history so the drawer renders populated.
    await Promise.all([
      store.fetchProcess(processId),
      store.fetchRunHistory(item.process.run_id),
    ]);
    void router.push({ query: { ...route.query, review: processId } });
  } catch {
    toast.danger(t('approvals.errors.title'));
  } finally {
    opening.value = null;
  }
}

onMounted(() => {
  refetch();
});
</script>

<template>
  <div class="flex flex-col gap-next-6">
    <PageHeader
      :title="t('approvals.queue.title')"
      :description="t('approvals.queue.subtitle')"
      icon="inbox"
    />

    <div class="flex flex-col gap-next-4">
      <!-- Error (initial load failed) with retry. -->
      <EmptyState
        v-if="store.errored && items.length === 0"
        variant="error"
        :title="t('approvals.queue.errors.title')"
        :description="t('approvals.queue.errors.description')"
      >
        <template #action>
          <Button variant="outline" size="sm" leading-icon="rotate-ccw" @click="refetch">
            {{ t('approvals.errors.retry') }}
          </Button>
        </template>
      </EmptyState>

      <!-- Initial loading: several card-shaped skeletons (never a spinner). -->
      <div
        v-else-if="initialLoading"
        class="grid grid-cols-1 gap-next-4 next-sm:grid-cols-2 next-xl:grid-cols-3"
      >
        <EntityCard v-for="n in skeletonKeys" :key="`sk-${n}`" loading />
      </div>

      <!-- Empty: nothing waiting for my decision. -->
      <EmptyState
        v-else-if="isEmpty"
        icon="check-circle"
        :title="t('approvals.queue.empty.title')"
        :description="t('approvals.queue.empty.description')"
      />

      <!-- Success: the grid + (when appending) trailing skeletons + sentinel. -->
      <template v-else>
        <div class="grid grid-cols-1 gap-next-4 next-sm:grid-cols-2 next-xl:grid-cols-3">
          <QueueItemCard
            v-for="item in items"
            :key="item.process.id"
            :item="item"
            :opening="opening === item.process.id"
            @open="onReview"
          />

          <template v-if="store.loadingMore">
            <EntityCard v-for="n in 3" :key="`more-${n}`" loading />
          </template>
        </div>

        <!-- Inline "load more" error with retry (keeps the loaded grid visible). -->
        <Alert
          v-if="store.loadMoreErrored"
          variant="danger"
          size="sm"
        >
          <div class="flex items-center justify-between gap-next-2">
            <span>{{ t('approvals.queue.errors.description') }}</span>
            <Button variant="ghost" size="xs" @click="store.retryLoadMore()">
              {{ t('approvals.errors.retry') }}
            </Button>
          </div>
        </Alert>

        <!-- Infinite-scroll sentinel (paused while a failed append awaits retry). -->
        <div
          v-if="store.hasMore && !store.loadMoreErrored"
          ref="sentinelRef"
          aria-hidden="true"
          class="h-px w-full"
        />
      </template>
    </div>
  </div>
</template>
