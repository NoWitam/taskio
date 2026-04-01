<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import type { Form } from '@/types/forms'
import { useFormsStore } from '@/store/forms'
import { useI18n } from '@/composables/useI18n'
import Dialog from '@/components/ui/Dialog.vue'
import Button from '@/components/ui/Button.vue'
import Icon from '@/components/ui/Icon.vue'
import TextInput from '@/components/ui/inputs/TextInput.vue'
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue'
import EmptyState from '@/components/ui/tables/EmptyState.vue'
import Card from '@/components/ui/Card.vue'

const props = defineProps<{
    modelValue: boolean
}>()

const emit = defineEmits<{
    'update:modelValue': [value: boolean]
    select: [form: Form]
}>()

const open = computed({
    get: () => props.modelValue,
    set: (v) => emit('update:modelValue', v)
})

const formsStore = useFormsStore()
const { t } = useI18n()

const search = ref('')
const selectedForm = ref<Form | null>(null)

const loading = computed(() => formsStore.loading.list || false)
const forms = computed(() => {
    // Filter out anonymous forms
    let filtered = formsStore.forms.filter(f => !f.is_anonymous)
    
    if (search.value) {
        const query = search.value.toLowerCase()
        filtered = filtered.filter(f => 
            f.name.toLowerCase().includes(query) ||
            f.description?.toLowerCase().includes(query)
        )
    }
    
    return filtered
})

const handleSelect = () => {
    if (selectedForm.value) {
        emit('select', selectedForm.value)
        open.value = false
    }
}

onMounted(() => {
    if (formsStore.forms.length === 0) {
        formsStore.fetchForms({})
    }
})
</script>

<template>
    <Dialog v-model="open" width="lg" :title="t('forms.selectForm')">
        <div class="space-y-4">
            <!-- Search -->
            <TextInput
                v-model="search"
                :placeholder="t('forms.searchPlaceholder')"
            >
                <template #left>
                    <Icon name="search" class="text-muted-foreground" />
                </template>
            </TextInput>

            <!-- Loading -->
            <div v-if="loading" class="flex justify-center py-8">
                <LoadingSpinner />
            </div>

            <!-- Empty state -->
            <EmptyState
                v-else-if="forms.length === 0"
                icon="file-text"
                :title="t('forms.noFormsAvailable')"
                :description="t('forms.noFormsAvailableDescription')"
            />

            <!-- Forms list -->
            <div v-else class="space-y-2 max-h-96 overflow-y-auto">
                <div
                    v-for="form in forms"
                    :key="form.id"
                    class="p-4 cursor-pointer transition-all border border-border rounded-lg"
                    :class="{
                        'border-primary bg-primary/5': selectedForm?.id === form.id,
                        'hover:border-border/80 hover:shadow-sm': selectedForm?.id !== form.id
                    }"
                    @click="selectedForm = form"
                >
                    <div class="flex items-start gap-3">
                        <div class="shrink-0">
                            <div class="w-10 h-10 rounded-lg bg-primary/10 flex items-center justify-center">
                                <Icon :name="form.icon || 'file-text'" class="text-primary" />
                            </div>
                        </div>

                        <div class="flex-1 min-w-0">
                            <h4 class="font-semibold truncate">{{ form.name }}</h4>
                            <p v-if="form.description" class="mt-1 text-sm text-muted-foreground line-clamp-2">
                                {{ form.description }}
                            </p>

                            <div class="mt-2 flex items-center gap-2 text-xs text-muted-foreground">
                                <Icon name="file-check" size="xs" />
                                <span>{{ form.submissions_count || 0 }} {{ t('forms.submissions') }}</span>
                            </div>
                        </div>

                        <div v-if="selectedForm?.id === form.id" class="shrink-0 text-primary">
                            <Icon name="check-circle" />
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <template #footer>
            <Button variant="secondary" @click="open = false">
                {{ t('common.cancel') }}
            </Button>
            <Button
                variant="primary"
                :disabled="!selectedForm"
                @click="handleSelect"
            >
                <Icon name="check" size="sm" />
                {{ t('common.select') }}
            </Button>
        </template>
    </Dialog>
</template>
