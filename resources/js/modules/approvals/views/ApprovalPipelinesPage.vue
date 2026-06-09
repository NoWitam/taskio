<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useApprovalsStore } from '@/store/approvals'
import { useInfiniteScroll } from '@/composables/useInfiniteScroll'
import { useI18n } from '@/composables/useI18n'
import { useToast } from '@/composables/useToast'
import Icon from '@/components/ui/Icon.vue'
import Button from '@/components/ui/Button.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import PipelineCard from '../components/PipelineCard.vue'
import CreatePipelineDialog from '../components/Dialogs/CreatePipelineDialog.vue'
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'

const store = useApprovalsStore()
const { t } = useI18n()
const { toast } = useToast()

const search = ref('')
const showCreate = ref(false)
const editingPipelineId = ref<string | null>(null)
const deletingPipelineId = ref<string | null>(null)
const showDeleteConfirm = ref(false)

const { triggerElement } = useInfiniteScroll(loadMore, {
    rootMargin: '400px',
    threshold: 0,
})

function loadMore() {
    if (!store.pipelinesLoading && store.pipelinesHasMore) {
        store.fetchPipelines({ search: search.value || undefined })
    }
}

function handleSearch() {
    store.fetchPipelines({ search: search.value || undefined, resetCursor: true })
}

function openCreate() {
    editingPipelineId.value = null
    showCreate.value = true
}

function openEdit(id: string) {
    editingPipelineId.value = id
    showCreate.value = true
}

function confirmDelete(id: string) {
    deletingPipelineId.value = id
    showDeleteConfirm.value = true
}

async function handleDelete() {
    if (!deletingPipelineId.value) return
    try {
        await store.deletePipeline(deletingPipelineId.value)
        toast({ type: 'success', message: t('approvals.messages.pipeline_deleted') })
    } catch {
        toast({ type: 'error', message: t('common.error') })
    } finally {
        showDeleteConfirm.value = false
        deletingPipelineId.value = null
    }
}

function handleSaved() {
    showCreate.value = false
    editingPipelineId.value = null
    store.fetchPipelines({ resetCursor: true, search: search.value || undefined })
}

onMounted(() => {
    store.fetchPipelines({ resetCursor: true })
})
</script>

<template>
    <div class="h-full max-h-full min-h-0 flex flex-col gap-4 overflow-hidden">
        <!-- Toolbar -->
        <div class="shrink-0 flex items-center gap-4">
            <input
                v-model="search"
                type="text"
                :placeholder="t('common.search')"
                class="w-full max-w-sm rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                @input="handleSearch"
            />
            <div class="flex-1" />
            <Button variant="primary" @click="openCreate">
                <Icon name="plus" size="sm" />
                {{ t('approvals.actions.create_pipeline') }}
            </Button>
        </div>

        <!-- Loading skeletons -->
        <div
            v-if="store.pipelinesLoading && store.pipelines.length === 0"
            class="flex-1 overflow-y-auto grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 content-start"
        >
            <Skeleton v-for="i in 6" :key="i" class="h-36 rounded-lg" />
        </div>

        <!-- Empty state -->
        <div
            v-else-if="!store.pipelinesLoading && store.pipelines.length === 0"
            class="flex-1 flex flex-col items-center justify-center gap-3 text-muted-foreground"
        >
            <Icon name="workflow" size="xl" />
            <p class="text-lg font-medium">{{ t('approvals.messages.no_pipelines') }}</p>
            <p class="text-sm">{{ t('approvals.messages.no_pipelines_description') }}</p>
            <Button variant="primary" @click="openCreate">
                <Icon name="plus" size="sm" />
                {{ t('approvals.actions.create_pipeline') }}
            </Button>
        </div>

        <!-- Pipeline grid -->
        <div v-else class="flex-1 min-h-0 overflow-y-auto pb-6">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <PipelineCard
                    v-for="pipeline in store.pipelines"
                    :key="pipeline.id"
                    :pipeline="pipeline"
                    @edit="openEdit(pipeline.id)"
                    @delete="confirmDelete(pipeline.id)"
                />
                <div
                    v-if="store.pipelinesHasMore"
                    :ref="(el) => { if (el) triggerElement = el as HTMLElement }"
                    class="col-span-full h-20 -mt-16"
                />
            </div>
        </div>

        <!-- Create / Edit Dialog -->
        <CreatePipelineDialog
            v-model="showCreate"
            :pipeline-id="editingPipelineId"
            @saved="handleSaved"
        />

        <!-- Delete Confirm -->
        <ConfirmDialog
            v-model="showDeleteConfirm"
            :title="t('approvals.actions.delete_pipeline')"
            :description="t('approvals.messages.delete_pipeline_confirm')"
            variant="danger"
            @confirm="handleDelete"
        />
    </div>
</template>
