<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import Dialog from '@/components/ui/Dialog.vue'
import Icon from '@/components/ui/Icon.vue'
import Badge from '@/components/ui/Badge.vue'
import Button from '@/components/ui/Button.vue'
import Tabs from '@/components/ui/Tabs.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import TextareaInput from '@/components/ui/inputs/TextareaInput.vue'
import CommentPanel from '@/components/CommentPanel.vue'
import FormViewer from '@/modules/forms/components/FormViewer/FormViewer.vue'
import { useApprovalsStore } from '@/store/approvals'
import { useI18n } from '@/composables/useI18n'
import type { ApprovalQueueItem, ApprovalRunHistoryItem } from '@/types/approvals'
import type { Form } from '@/types/forms'

const props = defineProps<{
    modelValue: boolean
    item: ApprovalQueueItem | null
    loading?: boolean
}>()

const emit = defineEmits<{
    'update:modelValue': [value: boolean]
    decide: [decision: 'approved' | 'rejected', note?: string | null]
}>()

const store = useApprovalsStore()
const { t } = useI18n()

const note = ref('')
const showRejectNote = ref(false)
const runHistory = ref<ApprovalRunHistoryItem[]>([])
const historyLoading = ref(false)
const activeStageTab = ref<string>('')

// Pipeline stages (fetched on open)
const pipelineStages = computed(() => {
    if (!props.item?.pipeline) return []
    const pipeline = store.pipelineById[props.item.pipeline.id]
    return pipeline?.stages ?? []
})

// Build tabs from pipeline stages
const stageTabs = computed(() => {
    const currentOrder = props.item?.stage?.order ?? 0
    return pipelineStages.value.map((stage) => ({
        id: stage.id,
        label: stage.name,
        icon: stage.icon ?? 'circle',
        disabled: stage.order > currentOrder,
    }))
})

// History items grouped by stage
const historyByStage = computed(() => {
    const map: Record<string, ApprovalRunHistoryItem[]> = {}
    for (const item of runHistory.value) {
        const stageId = item.stage?.id ?? 'unknown'
        if (!map[stageId]) map[stageId] = []
        map[stageId].push(item)
    }
    return map
})

// Current stage info
const activeStageInfo = computed(() => {
    return pipelineStages.value.find((s) => s.id === activeStageTab.value) ?? null
})

const isCurrentStage = computed(() => {
    return activeStageTab.value === props.item?.stage?.id
})

// Build a Form-like object for FormViewer preview
const previewForm = computed<Form | null>(() => {
    const form = props.item?.entity?.form
    if (!form) return null
    return {
        id: form.id,
        name: form.name,
        icon: null,
        description: null,
        content: form.content ?? [],
        is_anonymous: false,
        enabled_at: null,
        is_enabled: false,
        indexed_at: null,
        is_indexed: false,
        is_indexing: false,
        content_version: 0,
        content_updated_at: null,
        can_be_edited: false,
        can_be_filled: false,
        can_be_enabled: false,
        can_be_disabled: false,
        can_be_indexed: false,
        can_be_unindexed: false,
        can_restore_index: false,
        has_index_backup: false,
        is_draft: false,
        available_filters: [],
        reporting_mode: 'basic',
        created_at: '',
        updated_at: '',
    } as Form
})

const formSubmissionData = computed(() => {
    return props.item?.entity?.form?.submission ?? {}
})

const hasComments = computed(() => {
    return !!props.item?.entity?.comments_url
})

// Entity type for CommentPanel
const entityType = computed(() => props.item?.entity?.type ?? 'task')
const entityId = computed(() => props.item?.entity?.id ?? '')

// Load data when dialog opens
watch(() => props.modelValue, async (open) => {
    if (!open || !props.item) return

    note.value = ''
    showRejectNote.value = false
    activeStageTab.value = props.item.stage?.id ?? ''

    // Fetch pipeline stages
    if (props.item.pipeline) {
        if (!store.pipelineById[props.item.pipeline.id]) {
            await store.fetchPipeline(props.item.pipeline.id)
        }
    }

    // Fetch run history
    historyLoading.value = true
    try {
        runHistory.value = await store.fetchRunHistory(props.item.process.run_id)
    } finally {
        historyLoading.value = false
    }
})

function approve() {
    emit('decide', 'approved', note.value || null)
}

function startReject() {
    showRejectNote.value = true
}

function confirmReject() {
    emit('decide', 'rejected', note.value || null)
    showRejectNote.value = false
    note.value = ''
}

function cancelReject() {
    showRejectNote.value = false
    note.value = ''
}

function close() {
    showRejectNote.value = false
    note.value = ''
    emit('update:modelValue', false)
}

function statusTone(status: string) {
    return status === 'approved' ? 'success' : status === 'rejected' ? 'danger' : 'warning'
}

function formatDate(dateStr: string | null) {
    if (!dateStr) return ''
    return new Date(dateStr).toLocaleString('pl-PL', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    })
}
</script>

<template>
    <Dialog
        :model-value="modelValue"
        width="xl"
        height="h-[90vh]"
        @update:model-value="close"
    >
        <template #header>
            <div class="flex items-center gap-4 p-6">
                <div class="shrink-0 w-12 h-12 rounded-lg flex items-center justify-center bg-primary/10 text-primary">
                    <Icon :name="item?.entity?.type_icon ?? 'file'" size="md" />
                </div>
                <div class="flex-1 min-w-0">
                    <span class="text-xs text-muted-foreground">{{ item?.entity?.type_label }}</span>
                    <h2 class="text-lg font-semibold truncate">{{ item?.entity?.name ?? '—' }}</h2>
                </div>
            </div>
        </template>

        <div v-if="item" class="flex h-full min-h-0 -m-6">
            <!-- Main content -->
            <div class="flex-1 min-w-0 overflow-y-auto p-6 space-y-6">
                <!-- Entity description -->
                <p v-if="item.entity?.description" class="text-sm text-muted-foreground">
                    {{ item.entity.description }}
                </p>

                <!-- Extra fields -->
                <div v-if="item.entity?.extra_fields?.length" class="flex flex-wrap gap-3">
                    <div
                        v-for="field in item.entity.extra_fields"
                        :key="field.label"
                        class="flex items-center gap-2 rounded-lg bg-muted px-3 py-1.5 text-sm"
                    >
                        <Icon :name="field.icon" size="xs" class="text-muted-foreground" />
                        <span class="text-muted-foreground">{{ field.label }}:</span>
                        <span class="font-medium">{{ field.value }}</span>
                    </div>
                </div>

                <!-- Form preview -->
                <div v-if="previewForm" class="rounded-lg border border-border p-4">
                    <FormViewer
                        :form="previewForm"
                        mode="preview"
                        :initial-data="formSubmissionData"
                    />
                </div>

                <!-- Stage tabs -->
                <div v-if="stageTabs.length > 0" class="space-y-4">
                    <Tabs v-model="activeStageTab" :tabs="stageTabs" />

                    <!-- Tab content -->
                    <div v-if="activeStageInfo" class="space-y-4">
                        <!-- Stage criteria -->
                        <div v-if="activeStageInfo.description" class="rounded-lg bg-muted/50 p-4">
                            <h4 class="text-xs font-medium text-muted-foreground mb-1">
                                {{ t('approvals.labels.stage_criteria') }}
                            </h4>
                            <p class="text-sm">{{ activeStageInfo.description }}</p>
                        </div>

                        <!-- Decision history for this stage -->
                        <div v-if="historyLoading" class="space-y-2">
                            <Skeleton class="h-16 rounded-lg" />
                        </div>
                        <div
                            v-else-if="historyByStage[activeStageTab]?.length"
                            class="space-y-2"
                        >
                            <div
                                v-for="entry in historyByStage[activeStageTab]"
                                :key="entry.id"
                                class="flex items-start gap-3 rounded-lg border border-border p-3"
                            >
                                <Icon
                                    :name="entry.status === 'approved' ? 'check-circle' : entry.status === 'rejected' ? 'x-circle' : 'clock'"
                                    size="sm"
                                    :class="{
                                        'text-success': entry.status === 'approved',
                                        'text-danger': entry.status === 'rejected',
                                        'text-warning': entry.status === 'pending',
                                    }"
                                />
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2">
                                        <Badge :tone="statusTone(entry.status)">
                                            {{ t(`approvals.status.${entry.status}`) }}
                                        </Badge>
                                        <span class="text-xs text-muted-foreground">
                                            {{ entry.approver_type === 'ai' ? 'AI' : entry.approver?.name ?? '—' }}
                                        </span>
                                        <span v-if="entry.decided_at" class="text-xs text-muted-foreground ml-auto">
                                            {{ formatDate(entry.decided_at) }}
                                        </span>
                                    </div>
                                    <p v-if="entry.note" class="text-sm text-muted-foreground mt-1">
                                        {{ entry.note }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Action area (only for current pending stage) -->
                        <div v-if="isCurrentStage" class="space-y-4 pt-2">
                            <!-- Reject note area -->
                            <div v-if="showRejectNote" class="space-y-3 rounded-lg border border-danger/30 bg-danger/5 p-4">
                                <TextareaInput
                                    v-model="note"
                                    :label="t('approvals.labels.rejection_note')"
                                    :placeholder="t('approvals.labels.rejection_note_placeholder')"
                                    :rows="3"
                                />
                                <div class="flex items-center gap-2 justify-end">
                                    <Button variant="ghost" size="sm" @click="cancelReject">
                                        {{ t('common.cancel') }}
                                    </Button>
                                    <Button variant="danger" size="sm" :disabled="!note.trim()" :loading="loading" @click="confirmReject">
                                        {{ t('approvals.actions.reject') }}
                                    </Button>
                                </div>
                            </div>

                            <!-- Approval note (optional) + action buttons -->
                            <div v-else class="space-y-3">
                                <TextareaInput
                                    v-model="note"
                                    :label="t('approvals.labels.note')"
                                    :placeholder="t('approvals.labels.note_placeholder')"
                                    :rows="2"
                                />
                                <div class="flex items-center gap-3 justify-end">
                                    <Button variant="danger" :loading="loading" @click="startReject">
                                        <Icon name="x" size="sm" />
                                        {{ t('approvals.actions.reject') }}
                                    </Button>
                                    <Button variant="success" :loading="loading" @click="approve">
                                        <Icon name="check" size="sm" />
                                        {{ t('approvals.actions.approve') }}
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Comments panel (right side) -->
            <CommentPanel
                v-if="hasComments && entityId"
                :entity-id="entityId"
                :entity-type="entityType"
            />
        </div>
    </Dialog>
</template>
