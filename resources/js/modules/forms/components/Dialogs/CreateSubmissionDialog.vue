<script setup lang="ts">
import { ref, watch } from 'vue'
import { useFormsStore } from '@/store/forms'
import { useI18n } from '@/composables/useI18n'
import { useToast } from '@/composables/useToast'
import type { Form } from '@/types/forms'
import Dialog from '@/components/ui/Dialog.vue'
import Button from '@/components/ui/Button.vue'
import Icon from '@/components/ui/Icon.vue'
import FormViewer from '../FormViewer/FormViewer.vue'

interface Props {
    modelValue: boolean
    form: Form
}

interface Emits {
    (e: 'update:modelValue', value: boolean): void
    (e: 'created', submissionId: string): void
}

const props = defineProps<Props>()
const emit = defineEmits<Emits>()
const { t } = useI18n()
const toast = useToast()
const formsStore = useFormsStore()

const formViewerRef = ref<InstanceType<typeof FormViewer> | null>(null)
const submissionData = ref<Record<string, any>>({})
const isSubmitting = ref(false)

// Reset when dialog opens/closes
watch(() => props.modelValue, (open) => {
    if (!open) {
        submissionData.value = {}
    }
})

const handleClose = () => {
    emit('update:modelValue', false)
}

const handleSubmitClick = () => {
    // Trigger submit through ref
    formViewerRef.value?.submit()
}

const handleSubmit = async (data: Record<string, any>) => {
    isSubmitting.value = true

    try {
        const submission = await formsStore.createSubmission({
            form_id: props.form.id,
            submittable_type: 'form',
            submittable_id: props.form.id,
            data: data
        })

        toast.push({
            tone: 'success',
            title: t('common.success'),
            message: t('forms.submissionCreated'),
            timeoutMs: 3500
        })

        emit('created', submission.id)
        handleClose()
    } catch (err: any) {
        toast.push({
            tone: 'danger',
            title: t('common.error'),
            message: err.response?.data?.message || t('forms.createSubmissionError'),
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
        :title="t('forms.createSubmission')"
        :description="t('forms.createSubmissionDescription')"
        width="xl"
        height="max-h-[90vh]"
    >
        <div class="my-4 overflow-y-auto max-h-[60vh]">
            <FormViewer
                ref="formViewerRef"
                :form="form"
                mode="fill"
                :initial-data="submissionData"
                :hide-submit-button="true"
                @submit="handleSubmit"
            />
        </div>

        <template #footer>
            <div class="flex justify-end gap-2">
                <Button variant="secondary" @click="handleClose" :disabled="isSubmitting">
                    {{ t('common.cancel') }}
                </Button>
                <Button variant="primary" @click="handleSubmitClick" :loading="isSubmitting">
                    <Icon name="check" />
                    {{ t('forms.submitForm') }}
                </Button>
            </div>
        </template>
    </Dialog>
</template>
