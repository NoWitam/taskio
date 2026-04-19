<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useFormsStore } from '@/store/forms'
import { useI18n } from '@/composables/useI18n'
import Icon from '@/components/ui/Icon.vue'
import Button from '@/components/ui/Button.vue'
import FormViewer from '../components/FormViewer/FormViewer.vue'

const route = useRoute()
const router = useRouter()
const formsStore = useFormsStore()
const { t } = useI18n()

const formId = computed(() => route.params.formId as string)
const form = computed(() => formsStore.getFormById(formId.value))
const loading = ref(false)

const loadForm = async () => {
    if (!formId.value) return
    
    loading.value = true
    try {
        await formsStore.fetchForm(formId.value)
    } finally {
        loading.value = false
    }
}

onMounted(async () => {
    if (!form.value || !form.value.content) {
        await loadForm()
    }
})

// Watch for form ID changes
watch(formId, async (newId) => {
    if (newId && (!form.value || !form.value.content)) {
        await loadForm()
    }
})
</script>

<template>
    <div class="h-full max-h-full min-h-0 flex flex-col gap-6 px-6 pt-6 overflow-hidden">
        <!-- Loading State -->
        <div v-if="loading" class="flex items-center justify-center h-64">
            <Icon name="loader-2" size="xl" class="animate-spin text-muted-foreground" />
        </div>

        <!-- Form Preview -->
        <div v-else-if="form" class="flex-1 min-h-0 overflow-y-auto">
            <!-- Header -->
            <div class="mb-6">
                <h2 class="text-2xl font-bold">{{ t('forms.preview') }}</h2>
                <p class="text-sm text-muted-foreground mt-1">
                    {{ t('forms.previewDescription') }}
                </p>
            </div>

            <!-- Form Viewer -->
            <div class="max-w-3xl mx-auto pb-8">
                <FormViewer
                    :form="form"
                    mode="preview"
                />
            </div>
        </div>

        <!-- Error State -->
        <div v-else class="flex items-center justify-center h-64">
            <div class="text-center">
                <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-muted flex items-center justify-center">
                    <Icon name="alert-triangle" size="xl" class="text-muted-foreground" />
                </div>
                <h3 class="text-lg font-medium mb-1">{{ t('forms.formNotFound') }}</h3>
                <p class="text-sm text-muted-foreground">{{ t('forms.formNotFoundDescription') }}</p>
            </div>
        </div>
    </div>
</template>
