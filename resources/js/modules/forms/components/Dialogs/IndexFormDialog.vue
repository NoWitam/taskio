<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useFormsStore, type CompatibilityInfo } from '@/store/forms'
import { useI18n } from '@/composables/useI18n'
import Dialog from '@/components/ui/Dialog.vue'
import Button from '@/components/ui/Button.vue'
import Icon from '@/components/ui/Icon.vue'

interface Props {
    modelValue: boolean
    formId: string | null
}

interface Emits {
    (e: 'update:modelValue', value: boolean): void
    (e: 'confirmed'): void
}

const props = defineProps<Props>()
const emit = defineEmits<Emits>()
const { t } = useI18n()

const formsStore = useFormsStore()
const compatibilityInfo = ref<CompatibilityInfo | null>(null)
const loadingCompatibility = ref(false)

const form = computed(() => {
    if (!props.formId) return null
    return formsStore.getFormById(props.formId)
})

const hasIncompatible = computed(() => {
    return compatibilityInfo.value && compatibilityInfo.value.incompatible_count > 0
})

// Fetch compatibility info when dialog opens
watch(() => props.modelValue, async (isOpen) => {
    if (isOpen && props.formId) {
        loadingCompatibility.value = true
        compatibilityInfo.value = null
        try {
            compatibilityInfo.value = await formsStore.fetchCompatibilityInfo(props.formId)
        } catch {
            // Silently fail — dialog still works without compatibility info
        } finally {
            loadingCompatibility.value = false
        }
    }
})

const formatDate = (dateStr: string) => {
    return new Date(dateStr).toLocaleDateString()
}

const handleClose = () => {
    emit('update:modelValue', false)
}

const handleConfirm = () => {
    emit('confirmed')
}
</script>

<template>
    <Dialog 
        :model-value="modelValue" 
        @update:model-value="emit('update:modelValue', $event)"
        :title="t('forms.indexFormTitle')"
        :description="t('forms.indexFormDescription')"
        width="md"
    >
        <!-- Form Preview -->
        <div v-if="form" class="my-4 p-4 rounded-lg border border-border bg-muted/50">
            <div class="flex items-center gap-3">
                <div class="shrink-0 w-12 h-12 rounded-lg bg-primary/10 text-primary flex items-center justify-center">
                    <Icon :name="form.icon || 'file-text'" size="xl" />
                </div>
                <div class="flex-1 min-w-0">
                    <h4 class="font-medium">{{ form.name }}</h4>
                    <p v-if="form.description" class="text-sm text-muted-foreground mt-1">
                        {{ form.description }}
                    </p>
                </div>
            </div>
        </div>

        <!-- Info -->
        <div class="flex gap-3 p-3 rounded-lg bg-blue-500/10 border border-blue-500/20 text-blue-600 dark:text-blue-500">
            <Icon name="database" size="lg" class="shrink-0 mt-0.5" />
            <div class="flex-1 text-sm">
                <p class="font-medium mb-1">{{ t('forms.indexInfoTitle') }}</p>
                <p class="text-xs opacity-90">{{ t('forms.indexInfoDescription') }}</p>
            </div>
        </div>

        <!-- Compatibility Info -->
        <div class="mt-4">
            <!-- Loading state -->
            <div v-if="loadingCompatibility" class="flex items-center gap-2 p-3 rounded-lg border border-border text-sm text-muted-foreground">
                <Icon name="loader-2" size="sm" class="animate-spin" />
                {{ t('forms.loadingCompatibility') }}
            </div>

            <!-- All compatible -->
            <div v-else-if="compatibilityInfo && !hasIncompatible" class="flex gap-3 p-3 rounded-lg bg-green-500/10 border border-green-500/20 text-green-600 dark:text-green-500">
                <Icon name="check-circle" size="lg" class="shrink-0 mt-0.5" />
                <div class="flex-1 text-sm">
                    <p>{{ t('forms.allSubmissionsCompatible') }}</p>
                    <p class="text-xs opacity-75 mt-1">
                        {{ t('forms.totalSubmissions', { count: compatibilityInfo.total_submissions }) }}
                    </p>
                </div>
            </div>

            <!-- Has incompatible submissions -->
            <div v-else-if="compatibilityInfo && hasIncompatible" class="space-y-3">
                <div class="flex gap-3 p-3 rounded-lg bg-amber-500/10 border border-amber-500/20 text-amber-600 dark:text-amber-500">
                    <Icon name="alert-triangle" size="lg" class="shrink-0 mt-0.5" />
                    <div class="flex-1 text-sm">
                        <p class="font-medium mb-1">{{ t('forms.compatibilityWarningTitle') }}</p>
                        <p class="text-xs opacity-90">{{ t('forms.compatibilityWarningDescription') }}</p>
                    </div>
                </div>

                <!-- Stats -->
                <div class="flex gap-4 text-sm">
                    <span class="flex items-center gap-1.5 text-green-600 dark:text-green-500">
                        <span class="w-2 h-2 rounded-full bg-green-600 dark:bg-green-500"></span>
                        {{ t('forms.compatibleCount', { count: compatibilityInfo.compatible_count }) }}
                    </span>
                    <span class="flex items-center gap-1.5 text-amber-600 dark:text-amber-500">
                        <span class="w-2 h-2 rounded-full bg-amber-600 dark:bg-amber-500"></span>
                        {{ t('forms.incompatibleCount', { count: compatibilityInfo.incompatible_count }) }}
                    </span>
                </div>

                <!-- Incompatible periods -->
                <div v-if="compatibilityInfo.incompatible_periods.length > 0" class="space-y-2">
                    <div 
                        v-for="(period, idx) in compatibilityInfo.incompatible_periods" 
                        :key="idx"
                        class="flex items-center justify-between p-2 rounded border border-border text-xs text-muted-foreground"
                    >
                        <span>
                            {{ t('forms.periodVersion', { version: period.version }) }}
                            &middot;
                            {{ formatDate(period.period_from) }} – {{ formatDate(period.period_to) }}
                        </span>
                        <span class="font-medium">
                            {{ t('forms.periodSubmissions', { count: period.submissions_count }) }}
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <template #footer>
            <Button variant="ghost" @click="handleClose">
                {{ t('common.cancel') }}
            </Button>
            <Button variant="primary" @click="handleConfirm">
                <Icon name="database" size="sm" />
                {{ t('forms.indexForm') }}
            </Button>
        </template>
    </Dialog>
</template>
