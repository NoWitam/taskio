<script setup lang="ts">
import { ref, reactive, computed, watch } from 'vue'
import type { FormElement } from '@/types/forms'
import { useFormsStore } from '@/store/forms'
import { useI18n } from '@/composables/useI18n'
import { useToast } from '@/composables/useToast'
import Dialog from '@/components/ui/Dialog.vue'
import Button from '@/components/ui/Button.vue'
import Icon from '@/components/ui/Icon.vue'
import TextInput from '@/components/ui/inputs/TextInput.vue'
import TextareaInput from '@/components/ui/inputs/TextareaInput.vue'
import IconInput from '@/components/ui/inputs/IconInput.vue'
import FormBuilder from '../FormBuilder/FormBuilder.vue'
import FormViewer from '../FormViewer/FormViewer.vue'

const props = defineProps<{
    modelValue: boolean
    formId?: string
    anonymous?: boolean
}>()

const emit = defineEmits<{
    'update:modelValue': [value: boolean]
    created: [formId: string]
    updated: [formId: string]
}>()

const isEditMode = computed(() => !!props.formId)

const open = computed({
    get: () => props.modelValue,
    set: (v) => {
        if (!v) {
            // Reset on close
            currentStep.value = props.anonymous ? 2 : 1
            resetForm()
        }
        emit('update:modelValue', v)
    }
})

const formsStore = useFormsStore()
const toast = useToast()
const { t } = useI18n()

const currentStep = ref(props.anonymous ? 2 : 1)
const submitting = ref(false)
const loading = ref(false)
const showPreview = ref(false)
const builderRef = ref<InstanceType<typeof FormBuilder> | null>(null)
const existingFormContent = ref<FormElement[]>([])
const previewElements = ref<FormElement[]>([])

const form = reactive({
    name: '',
    icon: 'file-text',
    description: '',
})

const errors = reactive<Record<string, string>>({})

const resetForm = () => {
    form.name = props.anonymous ? 'Formularz zadania' : ''
    form.icon = 'file-text'
    form.description = ''
    existingFormContent.value = []
    previewElements.value = []
    Object.keys(errors).forEach(key => delete errors[key])
}

const loadForm = async () => {
    if (!props.formId) return
    
    loading.value = true
    try {
        const existingForm = await formsStore.fetchForm(props.formId)
        form.name = existingForm.name
        form.icon = existingForm.icon || 'file-text'
        form.description = existingForm.description || ''
        existingFormContent.value = existingForm.content || []
        previewElements.value = existingForm.content || []
    } catch (err: any) {
        toast.push({
            tone: 'danger',
            title: t('common.error'),
            message: err.response?.data?.message || t('forms.loadError'),
            timeoutMs: 4500
        })
        open.value = false
    } finally {
        loading.value = false
    }
}

// Load form when dialog opens in edit mode
watch(() => [props.modelValue, props.formId], ([isOpen, formId]) => {
    if (isOpen && formId) {
        loadForm()
    }
}, { immediate: true })

const goToStep2 = () => {
    // Clear errors
    Object.keys(errors).forEach(key => delete errors[key])

    // Validate
    if (!form.name.trim()) {
        errors.name = t('forms.validation.nameRequired')
        return
    }

    showPreview.value = false
    currentStep.value = 2
}

const goToStep1 = () => {
    showPreview.value = false
    currentStep.value = 1
}

const togglePreview = () => {
    if (!showPreview.value) {
        // Switching to preview - cache current elements
        previewElements.value = builderRef.value?.getElements() || existingFormContent.value
    }
    showPreview.value = !showPreview.value
}

const handleSubmit = async () => {
    submitting.value = true

    try {
        const content = builderRef.value?.getElements() || []
        
        if (isEditMode.value && props.formId) {
            // Update existing form
            await formsStore.updateForm(props.formId, {
                name: form.name,
                icon: form.icon || null,
                description: form.description || null,
                content: content,
            })

            toast.push({
                tone: 'success',
                title: t('common.success'),
                message: t('forms.formUpdated'),
                timeoutMs: 3500
            })

            emit('updated', props.formId)
        } else {
            // Create new form
            const newForm = await formsStore.createForm({
                name: form.name,
                icon: form.icon || null,
                description: form.description || null,
                content: content,
                is_anonymous: props.anonymous || false,
            })

            toast.push({
                tone: 'success',
                title: t('common.success'),
                message: t('forms.formCreated'),
                timeoutMs: 3500
            })

            emit('created', newForm.id)
        }

        open.value = false
    } catch (err: any) {
        if (err.response?.data?.errors) {
            Object.assign(errors, err.response.data.errors)
        } else {
            toast.push({
                tone: 'danger',
                title: t('common.error'),
                message: err.response?.data?.message || (isEditMode.value ? t('forms.updateError') : t('forms.createError')),
                timeoutMs: 4500
            })
        }
    } finally {
        submitting.value = false
    }
}

</script>

<template>
    <Dialog v-model="open" width="5xl" :title="isEditMode ? t('forms.editForm') : (anonymous ? t('forms.createAnonymousForm') : t('forms.createForm'))">
        <!-- Step Header -->
        <div v-if="!anonymous" class="flex items-center justify-center gap-8 mb-6 pb-4 border-b border-border">
            <!-- Step 1 -->
            <button
                type="button"
                :class="[
                    'flex items-center gap-2 px-4 py-2 rounded-lg transition-colors',
                    currentStep === 1 
                        ? 'bg-primary text-primary-foreground' 
                        : currentStep > 1 
                            ? 'text-foreground cursor-pointer hover:bg-secondary' 
                            : 'text-muted-foreground'
                ]"
                @click="currentStep > 1 && goToStep1()"
                :disabled="currentStep === 1"
            >
                <Icon :name="currentStep > 1 ? 'check-circle' : 'file-text'" size="sm" />
                <span class="text-sm font-medium">{{ t('forms.basicInfo') }}</span>
            </button>

            <!-- Divider -->
            <div class="w-12 h-0.5" :class="currentStep === 2 ? 'bg-primary' : 'bg-border'"></div>

            <!-- Step 2 -->
            <button
                type="button"
                :class="[
                    'flex items-center gap-2 px-4 py-2 rounded-lg transition-colors',
                    currentStep === 2 
                        ? 'bg-primary text-primary-foreground' 
                        : 'text-muted-foreground'
                ]"
                disabled
            >
                <Icon name="layout-grid" size="sm" />
                <span class="text-sm font-medium">{{ t('forms.formContent') }}</span>
            </button>
        </div>

        <!-- Step 1: Basic Info -->
        <div v-if="currentStep === 1 && !anonymous" class="grid grid-cols-6">
            <div></div>
            <form @submit.prevent="goToStep2" class="space-y-4 col-span-4">
                <div class="grid grid-cols-4 space-x-4">
                    <div class="col-span-3">
                        <TextInput
                            v-model="form.name"
                            :label="t('common.name')"
                            :placeholder="t('forms.namePlaceholder')"
                            :error="errors.name"
                            required
                            autofocus
                        />
                    </div>

                    <IconInput
                        v-model="form.icon"
                        :label="t('common.icon')"
                    />
                </div>

                <TextareaInput
                    v-model="form.description"
                    :label="t('common.description')"
                    :placeholder="t('forms.descriptionPlaceholder')"
                    :rows="4"
                />
            </form>
            <div></div>
        </div>

        <!-- Step 2: Form Builder -->
        <div v-else-if="currentStep === 2" class="h-150">
            <!-- Builder mode -->
            <FormBuilder
                v-show="!showPreview"
                ref="builderRef"
                :initial-elements="isEditMode ? existingFormContent : []"
                embedded
            />
            
            <!-- Preview mode -->
            <div v-show="showPreview" class="h-full overflow-y-auto p-6">
                <div class="max-w-4xl mx-auto">
                    <FormViewer
                        v-if="form.name"
                        :form="{
                            id: formId || 'preview',
                            name: form.name,
                            icon: form.icon,
                            description: form.description,
                            content: previewElements,
                            is_anonymous: false,
                            enabled_at: null,
                            is_enabled: false,
                            indexed_at: null,
                            is_indexed: false,
                            content_version: 0,
                            is_draft: true,
                            can_be_edited: true,
                            can_be_filled: false,
                            can_be_enabled: false,
                            can_be_disabled: false,
                            can_be_indexed: false,
                            can_be_unindexed: false,
                            can_restore_index: false,
                            has_index_backup: false,
                            is_indexing: false,
                            content_updated_at: null,
                            available_filters: ['search', 'date_range', 'source', 'creator', 'approval_status'],
                            reporting_mode: 'basic' as const,
                            submissions_count: 0,
                            created_at: new Date().toISOString(),
                            updated_at: new Date().toISOString(),
                        }"
                        mode="preview"
                    />
                </div>
            </div>
        </div>

        <template #footer>
            <!-- Step 1 Footer -->
            <div v-if="currentStep === 1" class="flex justify-end gap-2">
                <Button variant="secondary" @click="open = false">
                    {{ t('common.cancel') }}
                </Button>
                <Button variant="primary" @click="goToStep2">
                    {{ t('common.next') }}
                    <Icon name="chevron-right" />
                </Button>
            </div>

            <!-- Step 2 Footer -->
            <div v-else-if="currentStep === 2" class="flex justify-between gap-2">
                <Button v-if="!anonymous" variant="secondary" @click="goToStep1">
                    <Icon name="arrow-left" />
                    {{ t('common.back') }}
                </Button>
                <div v-else></div>
                
                <div class="flex gap-2">
                    <Button variant="secondary" @click="togglePreview">
                        <Icon :name="showPreview ? 'pencil' : 'eye'" />
                        {{ showPreview ? t('forms.edit') : t('forms.preview') }}
                    </Button>
                    
                    <Button
                        variant="primary"
                        :loading="submitting"
                        @click="handleSubmit"
                    >
                        <Icon name="check" />
                        {{ isEditMode ? t('common.update') : t('common.create') }}
                    </Button>
                </div>
            </div>
        </template>
    </Dialog>
</template>
