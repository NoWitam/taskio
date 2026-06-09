<script setup lang="ts">
import { ref, onMounted, computed, watch } from 'vue'
import { useApprovalsStore } from '@/store/approvals'
import { useI18n } from '@/composables/useI18n'
import Icon from '@/components/ui/Icon.vue'
import type { ApprovalPipelineListItem } from '@/types/approvals'

const props = defineProps<{
    modelValue: string | null
    error?: string
    placeholder?: string
}>()

const emit = defineEmits<{
    'update:modelValue': [value: string | null]
}>()

const store = useApprovalsStore()
const { t } = useI18n()

const open = ref(false)
const search = ref('')

onMounted(() => {
    if (store.pipelines.length === 0) {
        store.fetchPipelines({ resetCursor: true })
    }
})

const filteredPipelines = computed(() => {
    if (!search.value) return store.pipelines
    const q = search.value.toLowerCase()
    return store.pipelines.filter((p) => p.name.toLowerCase().includes(q))
})

const selectedPipeline = computed(() => {
    if (!props.modelValue) return null
    return store.pipelines.find((p) => p.id === props.modelValue) ?? null
})

function select(pipeline: ApprovalPipelineListItem | null) {
    emit('update:modelValue', pipeline?.id ?? null)
    open.value = false
    search.value = ''
}

function toggle() {
    open.value = !open.value
}
</script>

<template>
    <div class="relative">
        <!-- Trigger -->
        <button
            type="button"
            class="w-full flex items-center gap-2 rounded-lg border bg-background px-3 py-2 text-sm text-left transition-colors hover:bg-muted/50"
            :class="error ? 'border-danger' : 'border-border'"
            @click="toggle"
        >
            <Icon :name="selectedPipeline?.icon ?? 'workflow'" size="sm" class="text-muted-foreground shrink-0" />
            <span v-if="selectedPipeline" class="flex-1 truncate">{{ selectedPipeline.name }}</span>
            <span v-else class="flex-1 truncate text-muted-foreground">
                {{ placeholder || t('approvals.labels.select_pipeline') }}
            </span>
            <button
                v-if="selectedPipeline"
                type="button"
                class="shrink-0 text-muted-foreground hover:text-foreground"
                @click.stop="select(null)"
            >
                <Icon name="x" size="xs" />
            </button>
            <Icon name="chevron-down" size="xs" class="text-muted-foreground shrink-0" />
        </button>

        <p v-if="error" class="text-xs text-danger mt-1">{{ error }}</p>

        <!-- Dropdown -->
        <div
            v-if="open"
            class="absolute z-50 mt-1 w-full rounded-lg border border-border bg-popover shadow-lg max-h-60 overflow-hidden flex flex-col"
        >
            <div class="p-2 border-b border-border">
                <input
                    v-model="search"
                    type="text"
                    :placeholder="t('common.search')"
                    class="w-full rounded-md border border-border bg-background px-2 py-1.5 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
                    @click.stop
                />
            </div>
            <div class="flex-1 overflow-y-auto">
                <button
                    v-for="pipeline in filteredPipelines"
                    :key="pipeline.id"
                    type="button"
                    class="w-full flex items-center gap-2 px-3 py-2 text-sm hover:bg-muted transition-colors text-left"
                    :class="{ 'bg-muted': pipeline.id === modelValue }"
                    @click="select(pipeline)"
                >
                    <Icon :name="pipeline.icon ?? 'workflow'" size="xs" class="text-muted-foreground shrink-0" />
                    <span class="flex-1 truncate">{{ pipeline.name }}</span>
                    <span class="text-xs text-muted-foreground">
                        {{ pipeline.stages_count }} {{ t('approvals.labels.stages') }}
                    </span>
                </button>
                <div v-if="filteredPipelines.length === 0" class="px-3 py-4 text-sm text-muted-foreground text-center">
                    {{ t('approvals.messages.no_pipelines') }}
                </div>
            </div>
        </div>
    </div>
</template>
