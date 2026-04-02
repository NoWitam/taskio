<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useFormsStore } from '@/store/forms'
import { useI18n } from '@/composables/useI18n'
import Icon from '@/components/ui/Icon.vue'

const route = useRoute()
const formsStore = useFormsStore()
const { t } = useI18n()

const formId = computed(() => route.params.formId as string)
const form = computed(() => formsStore.getFormById(formId.value))

onMounted(async () => {
    if (!form.value) {
        await formsStore.fetchForm(formId.value)
    }
})
</script>

<template>
    <div class="h-full max-h-full min-h-0 flex flex-col gap-6 px-6 pt-6 overflow-hidden">
        <!-- Header -->
        <div class="mb-6">
            <h2 class="text-2xl font-bold">{{ t('forms.reports') }}</h2>
            <p class="text-sm text-muted-foreground mt-1">
                {{ t('forms.reportsDescription') }}
            </p>
        </div>

        <!-- Placeholder -->
        <div class="flex items-center justify-center flex-1">
            <div class="text-center">
                <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-muted flex items-center justify-center">
                    <Icon name="bar-chart-2" size="xl" class="text-muted-foreground" />
                </div>
                <h3 class="text-lg font-medium mb-1">{{ t('forms.reportsComingSoon') }}</h3>
                <p class="text-sm text-muted-foreground">{{ t('forms.reportsComingSoonDescription') }}</p>
            </div>
        </div>
    </div>
</template>
