<script setup lang="ts">
import { useI18n } from '@/composables/useI18n'
import type { FormReport } from '@/types/forms'
import Dialog from '@/components/ui/Dialog.vue'
import Button from '@/components/ui/Button.vue'
import Icon from '@/components/ui/Icon.vue'
import SimpleMDViewer from '@/components/SimpleMDViewer.vue'

interface Props {
    modelValue: boolean
    report: FormReport
    content: string
}

interface Emits {
    (e: 'update:modelValue', value: boolean): void
}

const props = defineProps<Props>()
const emit = defineEmits<Emits>()
const { t } = useI18n()

const handleClose = () => {
    emit('update:modelValue', false)
}

const handleDownload = () => {
    const blob = new Blob([props.content], { type: 'text/markdown' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = props.report.file?.name || `raport_${props.report.name}.md`
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)
    URL.revokeObjectURL(url)
}
</script>

<template>
    <Dialog 
        :model-value="modelValue" 
        @update:model-value="emit('update:modelValue', $event)"
        :title="report.name"
        width="2xl"
        height="max-h-[90vh]"
    >
        <!-- Metadata -->
        <div class="border-b border-border pb-6 mb-6">
            <div class="flex flex-wrap items-start justify-between gap-6">
                <!-- Creator -->
                <div class="flex items-start gap-3">
                    <Icon name="user" size="sm" class="mt-0.5 text-muted-foreground" />
                    <div>
                        <div class="text-xs font-medium text-muted-foreground">{{ t('taskDetails.createdBy') }}</div>
                        <div class="text-sm">{{ report.creator?.name || t('taskDetails.noValue') }}</div>
                    </div>
                </div>

                <!-- Date range -->
                <div class="flex items-start gap-3">
                    <Icon name="calendar" size="sm" class="mt-0.5 text-muted-foreground" />
                    <div>
                        <div class="text-xs font-medium text-muted-foreground">{{ t('forms.dateRange') }}</div>
                        <div class="text-sm">{{ report.date_range }}</div>
                    </div>
                </div>

                <!-- Sources -->
                <div class="flex items-start gap-3">
                    <Icon name="folder" size="sm" class="mt-0.5 text-muted-foreground" />
                    <div>
                        <div class="text-xs font-medium text-muted-foreground">{{ t('forms.sources') }}</div>
                        <div class="flex gap-1 mt-1">
                            <span 
                                v-for="(source, index) in report.sources_formatted" 
                                :key="index"
                                class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-muted text-xs"
                            >
                                {{ source }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Guidelines (if present) -->
                <div v-if="report.guidelines" class="w-full flex items-start gap-3">
                    <Icon name="info-circle" size="sm" class="mt-0.5 text-muted-foreground" />
                    <div class="flex-1">
                        <div class="text-xs font-medium text-muted-foreground">{{ t('forms.guidelines') }}</div>
                        <div class="text-sm mt-1">{{ report.guidelines }}</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Report Content (Markdown) -->
        <div class="overflow-y-auto max-h-[60vh]">
            <SimpleMDViewer :content="content" />
        </div>

        <template #footer>
            <div class="flex justify-between items-center w-full">
                <Button variant="secondary" @click="handleDownload">
                    <Icon name="download" />
                    {{ t('forms.downloadReport') }}
                </Button>
                <Button variant="primary" @click="handleClose">
                    {{ t('common.close') }}
                </Button>
            </div>
        </template>
    </Dialog>
</template>
