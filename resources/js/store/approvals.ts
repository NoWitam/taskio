import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { api } from '@/lib/api';
import type { ApiMeta, ApiResponse } from '@/types';
import type {
    ApprovalPipeline,
    ApprovalPipelineListItem,
    ApprovalQueueItem,
    ApprovalProcess,
    ApprovalRunHistoryItem,
    StageInput,
} from '@/types/approvals';

// STORE

export const useApprovalsStore = defineStore('approvals', () => {
    // STATE
    const pipelines = ref<ApprovalPipelineListItem[]>([]);
    const pipelinesLoading = ref(false);
    const pipelinesCursor = ref<string | null>(null);
    const pipelinesHasMore = ref(true);

    const queueItems = ref<ApprovalQueueItem[]>([]);
    const queueLoading = ref(false);
    const queueCursor = ref<string | null>(null);
    const queueHasMore = ref(true);
    const queueTotal = ref<number | null>(null);

    const pipelineById = ref<Record<string, ApprovalPipeline>>({});

    // COMPUTED
    const queueCount = computed(() => queueTotal.value ?? queueItems.value.length);

    // ACTIONS — Pipelines

    async function fetchPipelines(params: { search?: string; resetCursor?: boolean } = {}) {
        if (params.resetCursor) {
            pipelinesCursor.value = null;
            pipelines.value = [];
            pipelinesHasMore.value = true;
        }

        if (!pipelinesHasMore.value) return;

        pipelinesLoading.value = true;

        try {
            const qs = new URLSearchParams();
            if (pipelinesCursor.value) qs.append('cursor', pipelinesCursor.value);
            if (params.search) qs.append('search', params.search);

            const query = qs.toString();
            const response: ApiResponse<ApprovalPipelineListItem[]> = await api.get(
                `/approval-pipelines${query ? `?${query}` : ''}`
            );

            pipelines.value = [...pipelines.value, ...response.data];
            pipelinesCursor.value = response.meta?.next_cursor ?? null;
            pipelinesHasMore.value = !!response.meta?.next_cursor;
        } finally {
            pipelinesLoading.value = false;
        }
    }

    async function fetchPipeline(id: string): Promise<ApprovalPipeline> {
        const response: ApiResponse<ApprovalPipeline> = await api.get(`/approval-pipelines/${id}`);
        pipelineById.value[id] = response.data;
        return response.data;
    }

    async function createPipeline(payload: {
        name: string;
        icon?: string | null;
        description?: string | null;
        stages: StageInput[];
    }): Promise<ApprovalPipeline> {
        const response: ApiResponse<ApprovalPipeline> = await api.post('/approval-pipelines', payload);
        const pipeline = response.data;
        pipelineById.value[pipeline.id] = pipeline;

        // Reset pipeline list to refetch
        pipelinesCursor.value = null;
        pipelines.value = [];
        pipelinesHasMore.value = true;

        return pipeline;
    }

    async function updatePipeline(
        id: string,
        payload: {
            name: string;
            icon?: string | null;
            description?: string | null;
            stages: StageInput[];
        }
    ): Promise<ApprovalPipeline> {
        const response: ApiResponse<ApprovalPipeline> = await api.put(`/approval-pipelines/${id}`, payload);
        const pipeline = response.data;
        pipelineById.value[id] = pipeline;

        // Update in list
        const idx = pipelines.value.findIndex((p) => p.id === id);
        if (idx !== -1) {
            pipelines.value[idx] = {
                ...pipelines.value[idx],
                name: pipeline.name,
                icon: pipeline.icon,
                description: pipeline.description,
                can_be_edited: pipeline.can_be_edited,
                can_be_deleted: pipeline.can_be_deleted,
            };
        }

        return pipeline;
    }

    async function deletePipeline(id: string): Promise<void> {
        await api.delete(`/approval-pipelines/${id}`);
        pipelines.value = pipelines.value.filter((p) => p.id !== id);
        delete pipelineById.value[id];
    }

    // ACTIONS — Queue

    async function fetchQueue(params: { resetCursor?: boolean } = {}) {
        if (params.resetCursor) {
            queueCursor.value = null;
            queueItems.value = [];
            queueHasMore.value = true;
        }

        if (!queueHasMore.value) return;

        queueLoading.value = true;

        try {
            const qs = new URLSearchParams();
            if (queueCursor.value) qs.append('cursor', queueCursor.value);

            const query = qs.toString();
            const response: ApiResponse<ApprovalQueueItem[]> = await api.get(
                `/approvals/queue${query ? `?${query}` : ''}`
            );

            queueItems.value = [...queueItems.value, ...response.data];
            queueCursor.value = response.meta?.next_cursor ?? null;
            queueHasMore.value = !!response.meta?.next_cursor;

            if (response.meta?.total !== undefined && response.meta.total !== null) {
                queueTotal.value = response.meta.total;
            }
        } finally {
            queueLoading.value = false;
        }
    }

    async function fetchQueueCount(): Promise<number> {
        const response: { count: number } = await api.get('/approvals/queue/count');
        queueTotal.value = response.count;
        return response.count;
    }

    async function makeDecision(
        processId: string,
        decision: 'approved' | 'rejected',
        note?: string | null
    ): Promise<ApprovalProcess> {
        const response: ApiResponse<ApprovalProcess> = await api.post(
            `/approvals/processes/${processId}/decide`,
            { decision, note }
        );

        // Remove from queue
        queueItems.value = queueItems.value.filter((item) => item.process.id !== processId);
        if (queueTotal.value !== null) {
            queueTotal.value = Math.max(0, queueTotal.value - 1);
        }

        return response.data;
    }

    async function fetchRunHistory(runId: string): Promise<ApprovalRunHistoryItem[]> {
        const response: ApiResponse<ApprovalRunHistoryItem[]> = await api.get(
            `/approvals/runs/${runId}`
        );
        return response.data;
    }

    return {
        // State
        pipelines,
        pipelinesLoading,
        pipelinesHasMore,
        queueItems,
        queueLoading,
        queueHasMore,
        queueTotal,
        pipelineById,
        // Computed
        queueCount,
        // Actions
        fetchPipelines,
        fetchPipeline,
        createPipeline,
        updatePipeline,
        deletePipeline,
        fetchQueue,
        fetchQueueCount,
        makeDecision,
        fetchRunHistory,
    };
});
