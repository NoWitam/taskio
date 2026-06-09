<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useApprovalsStore } from '@/store/approvals'
import type { ApprovalStage } from '@/types/approvals'
import Icon from '@/components/ui/Icon.vue'
import Avatar from '@/components/ui/Avatar.vue'
import { useI18n } from '@/composables/useI18n'

const props = defineProps<{
    pipelineId: string
    currentStageOrder?: number
}>()

const store = useApprovalsStore()
const { t } = useI18n()

const stages = ref<ApprovalStage[]>([])

onMounted(async () => {
    const pipeline = store.pipelineById[props.pipelineId]
    if (pipeline) {
        stages.value = pipeline.stages
    } else {
        const fetched = await store.fetchPipeline(props.pipelineId)
        stages.value = fetched.stages
    }
})

function stageStatus(order: number) {
    if (props.currentStageOrder === undefined) return 'future'
    if (order < props.currentStageOrder) return 'done'
    if (order === props.currentStageOrder) return 'current'
    return 'future'
}
</script>

<template>
    <div class="flex items-center gap-2">
        <template v-for="(stage, idx) in stages" :key="stage.id">
            <!-- Connector line -->
            <div
                v-if="idx > 0"
                class="h-0.5 w-6 shrink-0"
                :class="stageStatus(stage.order) === 'done' ? 'bg-success' : 'bg-border'"
            />

            <!-- Stage node -->
            <div
                class="flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-medium whitespace-nowrap"
                :class="{
                    'bg-success/10 text-success': stageStatus(stage.order) === 'done',
                    'bg-primary/10 text-primary ring-2 ring-primary/30': stageStatus(stage.order) === 'current',
                    'bg-muted text-muted-foreground': stageStatus(stage.order) === 'future',
                }"
            >
                <Icon
                    v-if="stageStatus(stage.order) === 'done'"
                    name="check"
                    size="xs"
                />
                <Icon
                    v-else
                    :name="stage.icon ?? 'circle'"
                    size="xs"
                />
                {{ stage.name }}
            </div>
        </template>
    </div>
</template>
