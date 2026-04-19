<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useFormsStore } from '@/store/forms'
import FormsInnerSidebar from '../components/FormsInnerSidebar.vue'

const route = useRoute()
const router = useRouter()
const formsStore = useFormsStore()

// Selected form from route
const selectedFormId = computed(() => route.params.formId as string | undefined)
const selectedForm = computed(() => {
    if (!selectedFormId.value) return null
    return formsStore.getFormById(selectedFormId.value)
})

// Fetch form details when navigating to a specific form
watch(selectedFormId, async (formId) => {
    if (formId && !formsStore.getFormById(formId)) {
        try {
            await formsStore.fetchForm(formId)
        } catch (err) {
            console.error('Failed to fetch form:', err)
            // Navigate back to list if form not found
            router.push({ name: 'forms.list' })
        }
    }
}, { immediate: true })
</script>

<template>
    <div class="h-full max-h-full min-h-0 flex overflow-hidden">
        <!-- Internal Sidebar -->
        <FormsInnerSidebar 
            :selected-form="selectedForm"
            class="shrink-0"
        />

        <!-- Main Content Area -->
        <div class="flex-1 min-w-0">
            <RouterView />
        </div>
    </div>
</template>
