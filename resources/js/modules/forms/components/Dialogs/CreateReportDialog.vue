<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useFormsStore } from '@/store/forms'
import { useI18n } from '@/composables/useI18n'
import { useToast } from '@/composables/useToast'
import type { Form } from '@/types/forms'
import Dialog from '@/components/ui/Dialog.vue'
import Button from '@/components/ui/Button.vue'
import Icon from '@/components/ui/Icon.vue'
import TextInput from '@/components/ui/inputs/TextInput.vue'
import TextareaInput from '@/components/ui/inputs/TextareaInput.vue'
import DateInput from '@/components/ui/inputs/DateInput.vue'
import SelectInput from '@/components/ui/inputs/SelectInput.vue'

interface Props {
    modelValue: boolean
    form: Form
}

interface Emits {
    (e: 'update:modelValue', value: boolean): void
    (e: 'created', reportId: string): void
}

const props = defineProps<Props>()
const emit = defineEmits<Emits>()
const { t } = useI18n()
const toast = useToast()
const formsStore = useFormsStore()

// Form state
const name = ref('')
const guidelines = ref('')
const sources = ref<string[]>(['task', 'form'])
const submissionsFrom = ref('')
const submissionsTo = ref('')
const isSubmitting = ref(false)

// Source options
const sourceOptions = computed(() => [
    { label: t('forms.manual'), value: 'form', icon: 'file-text' },
    { label: t('forms.task'), value: 'task', icon: 'check-square' }
])

// Min/max dates based on form
const minDate = computed(() => {
    if (!props.form?.created_at) return undefined
    return new Date(props.form.created_at).toISOString().split('T')[0]
})

const maxDate = computed(() => {
    return new Date().toISOString().split('T')[0]
})

// Reset when dialog opens
watch(() => props.modelValue, (open) => {
    if (open) {
        name.value = ''
        guidelines.value = ''
        sources.value = ['task', 'form']
        
        // Set default dates
        if (props.form?.created_at) {
            submissionsFrom.value = new Date(props.form.created_at).toISOString().split('T')[0]
        }
        submissionsTo.value = new Date().toISOString().split('T')[0]
    }
})

const handleClose = () => {
    emit('update:modelValue', false)
}

const handleSubmit = async () => {
    // Validation
    if (!name.value.trim()) {
        toast.push({
            tone: 'danger',
            title: t('common.error'),
            message: t('forms.reportNameRequired'),
            timeoutMs: 3500
        })
        return
    }

    if (submissionsFrom.value && submissionsTo.value && submissionsFrom.value > submissionsTo.value) {
        toast.push({
            tone: 'danger',
            title: t('common.error'),
            message: t('forms.invalidDateRange'),
            timeoutMs: 3500
        })
        return
    }

    isSubmitting.value = true

    try {
        const report = await formsStore.createReport({
            form_id: props.form.id,
            name: name.value.trim(),
            guidelines: guidelines.value.trim() || null,
            sources: sources.value.length > 0 ? sources.value : undefined,
            submissions_from: submissionsFrom.value || null,
            submissions_to: submissionsTo.value || null
        })

        toast.push({
            tone: 'success',
            title: t('common.success'),
            message: t('forms.reportCreated'),
            timeoutMs: 3500
        })

        emit('created', report.id)
        handleClose()
    } catch (err: any) {
        toast.push({
            tone: 'danger',
            title: t('common.error'),
            message: err.response?.data?.message || t('forms.createReportError'),
            timeoutMs: 4500
        })
    } finally {
        isSubmitting.value = false
    }
}
</script>

<template>
    <Dialog 
        :model-value="modelValue" 
        @update:model-value="emit('update:modelValue', $event)"
        :title="t('forms.createReport')"
        :description="t('forms.createReportDescription')"
        width="lg"
    >
        <div class="space-y-4 my-4">
            <!-- Name -->
            <div>
                <label class="block text-sm font-medium mb-1.5">
                    {{ t('forms.reportName') }}
                    <span class="text-destructive">*</span>
                </label>
                <TextInput
                    v-model="name"
                    :placeholder="t('forms.reportNamePlaceholder')"
                    autofocus
                />
            </div>

            <!-- Guidelines -->
            <div>
                <label class="block text-sm font-medium mb-1.5">
                    {{ t('forms.guidelines') }}
                    <span class="text-muted-foreground text-xs font-normal ml-1">
                        ({{ t('common.optional') }})
                    </span>
                </label>
                <TextareaInput
                    v-model="guidelines"
                    :placeholder="t('forms.guidelinesPlaceholder')"
                    rows="4"
                />
            </div>

            <!-- Sources -->
            <div>
                <label class="block text-sm font-medium mb-1.5">
                    {{ t('forms.sources') }}
                </label>
                <SelectInput
                    v-model="sources"
                    :options="sourceOptions"
                    :multiple="true"
                    :placeholder="t('forms.selectSources')"
                >
                    <template #item="{ item }">
                        <Icon :name="item.icon" size="sm" />
                        <span class="truncate">{{ item.label }}</span>
                    </template>
                </SelectInput>
            </div>

            <!-- Date Range -->
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium mb-1.5">
                        {{ t('forms.dateFrom') }}
                    </label>
                    <DateInput
                        v-model="submissionsFrom"
                        :max="submissionsTo || maxDate"
                        :placeholder="t('forms.selectDate')"
                    >
                        <template #left>
                            <span class="text-muted-foreground">
                                <Icon name="calendar" />
                            </span>
                        </template>
                    </DateInput>
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1.5">
                        {{ t('forms.dateTo') }}
                    </label>
                    <DateInput
                        v-model="submissionsTo"
                        :min="submissionsFrom || minDate"
                        :max="maxDate"
                        :placeholder="t('forms.selectDate')"
                    >
                        <template #left>
                            <span class="text-muted-foreground">
                                <Icon name="calendar" />
                            </span>
                        </template>
                    </DateInput>
                </div>
            </div>
        </div>

        <template #footer>
            <div class="flex justify-end gap-2">
                <Button variant="secondary" @click="handleClose" :disabled="isSubmitting">
                    {{ t('common.cancel') }}
                </Button>
                <Button variant="primary" @click="handleSubmit" :loading="isSubmitting">
                    <Icon name="check" />
                    {{ t('forms.createReport') }}
                </Button>
            </div>
        </template>
    </Dialog>
</template>
