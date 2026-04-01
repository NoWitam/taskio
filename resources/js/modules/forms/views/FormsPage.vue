<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useFormsStore } from '@/store/forms'
import { useI18n } from '@/composables/useI18n'
import { useToast } from '@/composables/useToast'
import PageHeader from '@/components/ui/patterns/PageHeader.vue'
import Button from '@/components/ui/Button.vue'
import Icon from '@/components/ui/Icon.vue'
import TextInput from '@/components/ui/inputs/TextInput.vue'
import CheckboxInput from '@/components/ui/inputs/CheckboxInput.vue'
import FormsList from '../components/FormsList.vue'
import CreateFormDialog from '../components/Dialogs/CreateFormDialog.vue'

const router = useRouter()
const formsStore = useFormsStore()
const toast = useToast()
const { t } = useI18n()

// State
const showCreateDialog = ref(false)
const showEditDialog = ref(false)
const editFormId = ref<string | null>(null)
const filters = ref({
    search: ''
})

const loading = computed(() => formsStore.loading.list || false)
const forms = computed(() => formsStore.publicForms)
const hasMore = computed(() => formsStore.hasMore.list || false)

// Fetch forms
const fetchForms = (resetCursor = true) => {
    formsStore.fetchForms({
        search: filters.value.search || undefined
    }, resetCursor)
}

// Load more (infinite scroll)
const loadMore = () => {
    if (!loading.value && hasMore.value) {
        fetchForms(false)
    }
}

// Handle create
const handleFormCreated = (formId: string) => {
    showCreateDialog.value = false
    // Form already built in dialog, just refresh list
    fetchForms()
}

// Handle edit
const handleEdit = (formId: string) => {
    editFormId.value = formId
    showEditDialog.value = true
}

// Handle update
const handleFormUpdated = (formId: string) => {
    showEditDialog.value = false
    editFormId.value = null
    // Refresh list
    fetchForms()
}

// Handle delete
const handleDelete = async (formId: string) => {
    if (!confirm(t('forms.confirmDelete'))) return

    try {
        await formsStore.deleteForm(formId)
        toast.push({
            tone: 'success',
            title: t('common.success'),
            message: t('forms.formDeleted'),
            timeoutMs: 3500
        })
    } catch (err: any) {
        toast.push({
            tone: 'danger',
            title: t('common.error'),
            message: err.response?.data?.message || t('forms.deleteError'),
            timeoutMs: 4500
        })
    }
}

// Handle restore
const handleRestore = async (formId: string) => {
    try {
        await formsStore.restoreForm(formId)
        toast.push({
            tone: 'success',
            title: t('common.success'),
            message: t('forms.formRestored'),
            timeoutMs: 3500
        })
        fetchForms()
    } catch (err: any) {
        toast.push({
            tone: 'danger',
            title: t('common.error'),
            message: err.response?.data?.message || t('forms.restoreError'),
            timeoutMs: 4500
        })
    }
}

// Initial load
onMounted(() => {
    fetchForms()
})
</script>

<template>
    <div class="h-full max-h-full min-h-0 flex flex-col gap-6 px-6 pt-6 overflow-hidden">
        <PageHeader
            :title="t('forms.moduleName')"
            :description="t('forms.moduleDescription')"
        >
            <template #icon>
                <span class="text-primary">
                    <Icon size="lg" name="file-text" />
                </span>
            </template>

            <template #actions>
                <Button variant="primary" @click="showCreateDialog = true">
                    <Icon name="plus" size="sm" />
                    {{ t('forms.createForm') }}
                </Button>
            </template>
        </PageHeader>

        <!-- Filters -->
        <div class="grid grid-cols-12 gap-4">
            <div class="col-span-12">
                <TextInput
                    v-model="filters.search"
                    :placeholder="t('forms.searchPlaceholder')"
                    @input="fetchForms"
                >
                    <template #left>
                        <span class="text-muted-foreground">
                            <Icon name="search" />
                        </span>
                    </template>
                </TextInput>
            </div>
        </div>

        <!-- Forms List -->
        <div class="flex-1 min-h-0 overflow-y-auto">
            <FormsList
                :forms="forms"
                :loading="loading"
                :has-more="hasMore"
                @load-more="loadMore"
                @edit="handleEdit"
                @delete="handleDelete"
                @restore="handleRestore"
            />
        </div>

        <!-- Create Dialog -->
        <CreateFormDialog
            v-model="showCreateDialog"
            @created="handleFormCreated"
        />

        <!-- Edit Dialog -->
        <CreateFormDialog
            v-model="showEditDialog"
            :form-id="editFormId || undefined"
            @updated="handleFormUpdated"
        />
    </div>
</template>
