<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useApprovalsStore } from '@/store/approvals'
import { useInfiniteScroll } from '@/composables/useInfiniteScroll'
import { useI18n } from '@/composables/useI18n'
import { useToast } from '@/composables/useToast'
import Icon from '@/components/ui/Icon.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import QueueItemCard from '../components/QueueItemCard.vue'
import ApprovalReviewDialog from '../components/ApprovalReviewDialog.vue'
import type { ApprovalQueueItem } from '@/types/approvals'

const store = useApprovalsStore()
const { t } = useI18n()
const { toast } = useToast()

const selectedItem = ref<ApprovalQueueItem | null>(null)
const showReview = ref(false)
const deciding = ref(false)

const { triggerElement } = useInfiniteScroll(loadMore, {
    rootMargin: '400px',
    threshold: 0,
})

function loadMore() {
    if (!store.queueLoading && store.queueHasMore) {
        store.fetchQueue()
    }
}

function openReview(item: ApprovalQueueItem) {
    selectedItem.value = item
    showReview.value = true
}

async function handleDecision(decision: 'approved' | 'rejected', note?: string | null) {
    if (!selectedItem.value) return

    deciding.value = true
    try {
        await store.makeDecision(selectedItem.value.process.id, decision, note)
        toast({ type: 'success', message: t(`approvals.messages.${decision}`) })

        // Remove from queue
        store.queueItems = store.queueItems.filter(
            (i) => i.process.id !== selectedItem.value!.process.id
        )
        if (store.queueTotal !== null) {
            store.queueTotal = Math.max(0, store.queueTotal - 1)
        }

        showReview.value = false
        selectedItem.value = null
    } catch {
        toast({ type: 'error', message: t('common.error') })
    } finally {
        deciding.value = false
    }
}

onMounted(() => {
    store.fetchQueue({ resetCursor: true })
})
</script>

<template>
    <div class="h-full max-h-full min-h-0 flex flex-col gap-4 overflow-hidden">
        <!-- Loading skeletons -->
        <div v-if="store.queueLoading && store.queueItems.length === 0" class="flex-1 overflow-y-auto space-y-3">
            <Skeleton v-for="i in 4" :key="i" class="h-24 rounded-lg" />
        </div>

        <!-- Empty state -->
        <div
            v-else-if="!store.queueLoading && store.queueItems.length === 0"
            class="flex-1 flex flex-col items-center justify-center gap-3 text-muted-foreground"
        >
            <Icon name="check-circle" size="xl" />
            <p class="text-lg font-medium">{{ t('approvals.messages.queue_empty') }}</p>
            <p class="text-sm">{{ t('approvals.messages.queue_empty_description') }}</p>
        </div>

        <!-- Queue items -->
        <div v-else class="flex-1 min-h-0 overflow-y-auto space-y-3 pb-6">
            <QueueItemCard
                v-for="item in store.queueItems"
                :key="item.process.id"
                :item="item"
                @click="openReview(item)"
            />
            <div
                v-if="store.queueHasMore"
                :ref="(el) => { if (el) triggerElement = el as HTMLElement }"
                class="h-20 -mt-16"
            />
            <div v-if="store.queueLoading && store.queueItems.length > 0" class="flex justify-center py-4">
                <Skeleton class="h-24 w-full rounded-lg" />
            </div>
        </div>

        <!-- Review Dialog -->
        <ApprovalReviewDialog
            v-model="showReview"
            :item="selectedItem"
            :loading="deciding"
            @decide="handleDecision"
        />
    </div>
</template>
