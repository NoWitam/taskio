<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import type { Form, FormElement, FormValidationError } from '@/types/forms'
import { useI18n } from '@/composables/useI18n'
import Button from '@/components/ui/Button.vue'
import Icon from '@/components/ui/Icon.vue'
import Card from '@/components/ui/Card.vue'
import InputRenderer from './InputRenderer.vue'

const props = withDefaults(defineProps<{
    form: Form
    mode: 'preview' | 'fill'
    initialData?: Record<string, any>
    autoSave?: boolean
    hideSubmitButton?: boolean
}>(), {
    mode: 'preview',
    initialData: () => ({}),
    autoSave: false,
    hideSubmitButton: false
})

const emit = defineEmits<{
    submit: [data: Record<string, any>]
}>()

const { t } = useI18n()

// Form data
const formData = ref<Record<string, any>>({})
const errors = ref<FormValidationError[]>([])
const submitting = ref(false)
const isInitializing = ref(true)

// Initialize form data - Only on mount for auto-save mode, otherwise watch for changes
if (props.autoSave) {
    // Auto-save mode: initialize once and never reset
    formData.value = props.initialData ? { ...props.initialData } : {}
    setTimeout(() => {
        isInitializing.value = false
    }, 100)
} else {
    // Regular mode: watch for initialData changes
    watch(() => props.initialData, (newData) => {
        isInitializing.value = true
        formData.value = newData ? { ...newData } : {}
        setTimeout(() => {
            isInitializing.value = false
        }, 100)
    }, { immediate: true, deep: true })
}

// Auto-save with debounce
let autoSaveTimeout: ReturnType<typeof setTimeout> | null = null
watch(formData, () => {
    if (props.autoSave && !isInitializing.value && props.mode === 'fill') {
        if (autoSaveTimeout) clearTimeout(autoSaveTimeout)
        autoSaveTimeout = setTimeout(() => {
            emit('submit', formData.value)
        }, 1000)
    }
}, { deep: true })

// Repeater instances tracking
const repeaterInstances = ref<Record<string, number>>({})

// Initialize repeater instances
const initializeRepeaters = () => {
    const processElements = (elements: FormElement[]) => {
        elements.forEach(el => {
            if (el.type === 'repeater') {
                const config = el.config as any
                if (!repeaterInstances.value[el.id]) {
                    repeaterInstances.value[el.id] = config.min || 1
                }
            }
            
            // Process nested elements
            if (el.type === 'section' || el.type === 'repeater') {
                const children = (el.config as any).children || []
                processElements(children)
            }
        })
    }
    
    if (props.form?.content && Array.isArray(props.form.content)) {
        processElements(props.form.content)
    }
}

initializeRepeaters()

// Validation
const validateForm = (): boolean => {
    errors.value = []
    
    const validateElement = (element: FormElement): void => {
        const config = element.config as any
        
        // Only validate input elements
        const inputTypes = ['short_text', 'long_text', 'select', 'number', 'date', 'time', 'url', 'checkbox', 'checklist', 'image']
        if (!inputTypes.includes(element.type)) return

        const value = formData.value[element.id]

        // Required validation
        if (config.required) {
            if (value === undefined || value === null || value === '') {
                errors.value.push({
                    elementId: element.id,
                    message: t('forms.validation.required', '', { field: config.label })
                })
            }
        }

        // Type-specific validation
        if (element.type === 'short_text' || element.type === 'long_text') {
            if (value && typeof value === 'string') {
                if (config.minLength && value.length < config.minLength) {
                    errors.value.push({
                        elementId: element.id,
                        message: t('forms.validation.minLength', '', { field: config.label, min: config.minLength })
                    })
                }
                if (config.maxLength && value.length > config.maxLength) {
                    errors.value.push({
                        elementId: element.id,
                        message: t('forms.validation.maxLength', '', { field: config.label, max: config.maxLength })
                    })
                }
            }
        }

        if (element.type === 'number') {
            if (value !== undefined && value !== null && value !== '') {
                const numValue = Number(value)
                if (config.min !== undefined && numValue < config.min) {
                    errors.value.push({
                        elementId: element.id,
                        message: t('forms.validation.minValue', '', { field: config.label, min: config.min })
                    })
                }
                if (config.max !== undefined && numValue > config.max) {
                    errors.value.push({
                        elementId: element.id,
                        message: t('forms.validation.maxValue', '', { field: config.label, max: config.max })
                    })
                }
            }
        }
    }

    const processElements = (elements: FormElement[]) => {
        elements.forEach(el => {
            validateElement(el)
            
            // Validate nested
            if (el.type === 'section' || el.type === 'repeater') {
                const children = (el.config as any).children || []
                processElements(children)
            }
            
            if (el.type === 'grid') {
                const columns = (el.config as any).columns || []
                columns.forEach((col: any) => {
                    if (col.element) processElements([col.element])
                })
            }
        })
    }

    if (props.form?.content && Array.isArray(props.form.content)) {
        processElements(props.form.content)
    }
    
    return errors.value.length === 0
}

// Submit
const handleSubmit = () => {
    if (props.mode !== 'fill') return

    if (!validateForm()) {
        return
    }

    submitting.value = true
    emit('submit', formData.value)
    
    // Reset submitting after emit (parent should handle loading)
    setTimeout(() => {
        submitting.value = false
    }, 100)
}

// Repeater actions
const addRepeaterInstance = (repeaterId: string) => {
    const element = findElement(repeaterId)
    if (element && element.type === 'repeater') {
        const config = element.config as any
        const current = repeaterInstances.value[repeaterId] || config.min
        if (current < config.max) {
            repeaterInstances.value[repeaterId] = current + 1
        }
    }
}

const removeRepeaterInstance = (repeaterId: string) => {
    const element = findElement(repeaterId)
    if (element && element.type === 'repeater') {
        const config = element.config as any
        const current = repeaterInstances.value[repeaterId] || config.min
        if (current > config.min) {
            repeaterInstances.value[repeaterId] = current - 1
        }
    }
}

const findElement = (id: string): FormElement | null => {
    const search = (elements: FormElement[]): FormElement | null => {
        for (const el of elements) {
            if (el.id === id) return el
            
            if (el.type === 'section' || el.type === 'repeater') {
                const children = (el.config as any).children || []
                const found = search(children)
                if (found) return found
            }
            
            if (el.type === 'grid') {
                const columns = (el.config as any).columns || []
                for (const col of columns) {
                    if (col.element) {
                        const found = search([col.element])
                        if (found) return found
                    }
                }
            }
        }
        return null
    }
    
    if (!props.form?.content || !Array.isArray(props.form.content)) {
        return null
    }
    return search(props.form.content)
}

// Get error for element
const getElementError = (elementId: string): string | undefined => {
    return errors.value.find(e => e.elementId === elementId)?.message
}

// Expose submit method for external trigger
defineExpose({
    submit: handleSubmit
})
</script>

<template>
    <div class="space-y-6">
        <!-- Form header -->
        <div v-if="mode === 'fill'" class="flex items-start gap-4">
            <div v-if="form.icon" class="mt-1">
                <Icon :name="form.icon" size="lg" class="text-primary" />
            </div>
            <div class="flex-1">
                <h2 class="text-2xl font-bold">{{ form.name }}</h2>
                <p v-if="form.description" class="mt-1 text-muted-foreground">
                    {{ form.description }}
                </p>
            </div>
        </div>

        <!-- Elements -->
        <div v-if="form.content && form.content.length > 0" class="space-y-4">
            <InputRenderer
                v-for="element in form.content"
                :key="element.id"
                :element="element"
                :mode="mode"
                :form-data="formData"
                :repeater-instances="repeaterInstances"
                :get-error="getElementError"
                @add-repeater="addRepeaterInstance"
                @remove-repeater="removeRepeaterInstance"
            />
        </div>
        
        <!-- Empty state -->
        <div v-else class="flex items-center justify-center py-12 text-center">
            <div>
                <Icon name="file-text" size="xl" class="text-muted-foreground mx-auto mb-2" />
                <p class="text-muted-foreground">{{ t('forms.emptyCanvas') }}</p>
            </div>
        </div>

        <!-- Submit button (only when not auto-saving and not hidden) -->
        <div v-if="mode === 'fill' && !autoSave && !hideSubmitButton" class="flex justify-end pt-4 border-t border-border">
            <Button
                size="lg"
                :loading="submitting"
                @click="handleSubmit"
            >
                <Icon name="check" />
                {{ t('forms.submitForm') }}
            </Button>
        </div>
    </div>
</template>
