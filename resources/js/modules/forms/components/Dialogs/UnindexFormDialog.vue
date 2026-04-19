<script setup lang="ts">
import { ref, computed } from 'vue'
import { useFormsStore } from '@/store/forms'
import { useI18n } from '@/composables/useI18n'
import Dialog from '@/components/ui/Dialog.vue'
import Button from '@/components/ui/Button.vue'
import Icon from '@/components/ui/Icon.vue'
import SwitchInput from '@/components/ui/inputs/SwitchInput.vue'

interface Props {
    modelValue: boolean
    formId: string | null
}

interface Emits {
    (e: 'update:modelValue', value: boolean): void
    (e: 'confirmed', backupIndexes: boolean): void
}

const props = defineProps<Props>()
const emit = defineEmits<Emits>()
const { t } = useI18n()

const formsStore = useFormsStore()
const backupIndexes = ref(true)

const form = computed(() => {
    if (!props.formId) return null
    return formsStore.getFormById(props.formId)
})

const handleClose = () => {
    emit('update:modelValue', false)
}

const handleConfirm = () => {
    emit('confirmed', backupIndexes.value)
}
</script>

<template>
    <Dialog 
        :model-value="modelValue" 
        @update:model-value="emit('update:modelValue', $event)"
        :title="t('forms.unindexFormTitle')"
        :description="t('forms.unindexFormDescription')"
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

        <!-- Warning -->
        <div class="flex gap-3 p-3 rounded-lg bg-amber-500/10 border border-amber-500/20 text-amber-600 dark:text-amber-500">
            <Icon name="alert-triangle" size="lg" class="shrink-0 mt-0.5" />
            <div class="flex-1 text-sm">
                <p class="font-medium mb-1">{{ t('forms.unindexWarningTitle') }}</p>
                <p class="text-xs opacity-90">{{ t('forms.unindexWarningDescription') }}</p>
            </div>
        </div>

        <!-- Backup option -->
        <div class="flex items-center justify-between mt-4 p-3 rounded-lg border border-border">
            <div class="flex-1">
                <p class="text-sm font-medium">{{ t('forms.backupIndexes') }}</p>
                <p class="text-xs text-muted-foreground">{{ t('forms.backupIndexesDescription') }}</p>
            </div>
            <SwitchInput v-model="backupIndexes" />
        </div>

        <template #footer>
            <Button variant="ghost" @click="handleClose">
                {{ t('common.cancel') }}
            </Button>
            <Button variant="destructive" @click="handleConfirm">
                <Icon name="database" size="sm" />
                {{ t('forms.unindexForm') }}
            </Button>
        </template>
    </Dialog>
</template>
