<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useFormsStore } from '@/store/forms'
import { useI18n } from '@/composables/useI18n'
import { useToast } from '@/composables/useToast'
import { useInfiniteScroll } from '@/composables/useInfiniteScroll'
import PageHeader from '@/components/ui/patterns/PageHeader.vue'
import Button from '@/components/ui/Button.vue'
import Icon from '@/components/ui/Icon.vue'
import TextInput from '@/components/ui/inputs/TextInput.vue'
import Tabs from '@/components/ui/Tabs.vue'
import Dialog from '@/components/ui/Dialog.vue'
import SwitchInput from '@/components/ui/inputs/SwitchInput.vue'
import FormCard from '../components/FormCard.vue'
import FormCardSkeleton from '../components/FormCardSkeleton.vue'
import CreateFormDialog from '../components/Dialogs/CreateFormDialog.vue'
import EnableFormDialog from '../components/Dialogs/EnableFormDialog.vue'
import FormViewer from '../components/FormViewer/FormViewer.vue'

const router = useRouter()
const route = useRoute()
const formsStore = useFormsStore()
const toast = useToast()
const { t } = useI18n()

// State
const showCreateDialog = ref(false)
const showEditDialog = ref(false)
const showPreviewDialog = ref(false)
const previewFormId = ref<string | null>(null)
const loadingPreview = ref(false)
const editFormId = ref<string | null>(null)
const showEnableDialog = ref(false)
const formToEnable = ref<string | null>(null)
const activeTab = ref<'all' | 'trash'>('all')
const showEnabled = ref(false)
const showDisabled = ref(false)
const isInitializing = ref(true)
const filters = ref<{
    search: string
    trashed: boolean
    enabled?: boolean
}>({
    search: '',
    trashed: false
})

const tabs = computed(() => [
    { id: 'all', label: t('forms.allForms'), icon: 'file-text' },
    { id: 'trash', label: t('forms.trash'), icon: 'trash' }
])

const loading = computed(() => formsStore.loading.list || false)
const hasMore = computed(() => formsStore.hasMore.list || false)

// Fetch forms
const fetchForms = (resetCursor = true) => {
    // Determine enabled filter based on switches
    let enabledFilter: boolean | undefined = undefined
    if (showEnabled.value && !showDisabled.value) {
        enabledFilter = true
    } else if (!showEnabled.value && showDisabled.value) {
        enabledFilter = false
    }
    // If both or neither are selected, don't filter by enabled status
    
    filters.value.enabled = enabledFilter
    
    formsStore.fetchForms({
        search: filters.value.search || undefined,
        trashed: filters.value.trashed,
        enabled: filters.value.enabled
    }, resetCursor)
}

// Watch tab changes to update trashed filter
watch(activeTab, (newTab) => {
    filters.value.trashed = newTab === 'trash'
    if (!isInitializing.value) {
        updateURLParams()
        fetchForms()
    }
})

// Watch search input with debounce
let searchTimeout: ReturnType<typeof setTimeout> | null = null
watch(() => filters.value.search, (newSearch) => {
    if (searchTimeout) clearTimeout(searchTimeout)
    searchTimeout = setTimeout(() => {
        if (!isInitializing.value) {
            updateURLParams()
            fetchForms()
        }
    }, 500)
})

// Watch enabled switch - auto-disable disabled when enabled is turned on
watch(showEnabled, (newVal) => {
    if (newVal && showDisabled.value) {
        showDisabled.value = false
    } else if (!isInitializing.value) {
        updateURLParams()
        fetchForms()
    }
})

// Watch disabled switch - auto-disable enabled when disabled is turned on
watch(showDisabled, (newVal) => {
    if (newVal && showEnabled.value) {
        showEnabled.value = false
    } else if (!isInitializing.value) {
        updateURLParams()
        fetchForms()
    }
})

// Watch route query changes (back/forward navigation)
watch(() => route.query, () => {
    if (!isInitializing.value) {
        initFromURL()
        fetchForms()
    }
})

// Load more (infinite scroll)
const loadMore = () => {
    if (!loading.value && hasMore.value) {
        fetchForms(false)
    }
}

// Setup infinite scroll
const { triggerElement } = useInfiniteScroll(loadMore, {
    rootMargin: '400px',
    threshold: 0
})

// Handle create
const handleFormCreated = (formId: string) => {
    showCreateDialog.value = false
    fetchForms()
}

// Handle view
const handleView = async (formId: string) => {
    try {
        loadingPreview.value = true
        previewFormId.value = formId
        // Fetch full form data including content
        await formsStore.fetchForm(formId)
        showPreviewDialog.value = true
    } catch (err: any) {
        toast.push({
            tone: 'danger',
            title: t('common.error'),
            message: err.response?.data?.message || t('forms.loadError'),
            timeoutMs: 4500
        })
    } finally {
        loadingPreview.value = false
    }
}

// Handle select (navigate to form)
const handleSelect = (formId: string) => {
    router.push({
        name: 'forms.detail.submissions',
        params: { formId }
    })
}

// Handle edit
const handleEdit = (formId: string) => {
    editFormId.value = formId
    showEditDialog.value = true
}

// Handle form updated
const handleFormUpdated = () => {
    showEditDialog.value = false
    editFormId.value = null
    fetchForms()
}

// Handle enable request
const handleEnableRequest = (formId: string) => {
    formToEnable.value = formId
    showEnableDialog.value = true
}

// Handle enable confirmed
const handleEnableConfirmed = async () => {
    if (!formToEnable.value) return

    try {
        await formsStore.enableForm(formToEnable.value)
        toast.push({
            tone: 'success',
            title: t('common.success'),
            message: t('forms.formEnabled'),
            timeoutMs: 3500
        })
        showEnableDialog.value = false
        formToEnable.value = null
    } catch (err: any) {
        toast.push({
            tone: 'danger',
            title: t('common.error'),
            message: err.response?.data?.message || t('forms.enableError'),
            timeoutMs: 4500
        })
    }
}

// Handle delete
const handleDelete = async (formId: string) => {
    const form = formsStore.getFormById(formId)
    const formName = form?.name || 'ten formularz'
    if (!confirm(`Czy na pewno chcesz usunąć formularz "${formName}"?`)) return

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

// Handle force delete
const handleForceDelete = async (formId: string) => {
    const form = formsStore.getFormById(formId)
    const formName = form?.name || 'ten formularz'
    if (!confirm(`Czy na pewno chcesz TRWALE usunąć formularz "${formName}"? Ta akcja jest nieodwracalna.`)) return

    try {
        await formsStore.forceDeleteForm(formId)
        toast.push({
            tone: 'success',
            title: t('common.success'),
            message: t('forms.formPermanentlyDeleted'),
            timeoutMs: 3500
        })
    } catch (err: any) {
        toast.push({
            tone: 'danger',
            title: t('common.error'),
            message: err.response?.data?.message || t('forms.forceDeleteError'),
            timeoutMs: 4500
        })
    }
}

// Sync filters with URL query params
const updateURLParams = () => {
    const query: Record<string, string> = {}
    
    if (filters.value.search) {
        query.search = filters.value.search
    }
    
    if (activeTab.value === 'trash') {
        query.tab = 'trash'
    }
    
    if (showEnabled.value) {
        query.enabled = 'true'
    } else if (showDisabled.value) {
        query.enabled = 'false'
    }
    
    router.replace({ query })
}

// Initialize filters from URL
const initFromURL = () => {
    const query = route.query
    
    // Initialize search
    if (query.search && typeof query.search === 'string') {
        filters.value.search = query.search
    }
    
    // Initialize tab
    if (query.tab === 'trash') {
        activeTab.value = 'trash'
        filters.value.trashed = true
    }
    
    // Initialize enabled/disabled filters
    if (query.enabled === 'true') {
        showEnabled.value = true
        showDisabled.value = false
    } else if (query.enabled === 'false') {
        showEnabled.value = false
        showDisabled.value = true
    }
}

// Initial load
onMounted(() => {
    initFromURL()
    isInitializing.value = false
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

        <!-- Tabs -->
        <Tabs :tabs="tabs" v-model="activeTab" />

        <!-- All Forms Tab -->
        <div v-if="activeTab === 'all'" class="flex-1 min-h-0 flex flex-col gap-4">
                <!-- Filters -->
                <div class="grid grid-cols-12 gap-4">
                    <div class="col-span-7">
                        <TextInput
                            v-model="filters.search"
                            :placeholder="t('forms.searchPlaceholder')"
                        >
                            <template #left>
                                <span class="text-muted-foreground">
                                    <Icon name="search" />
                                </span>
                            </template>
                        </TextInput>
                    </div>
                    <div class="col-span-5">
                        <div class="flex items-center justify-end gap-3">
                            <div class="flex items-center gap-2 px-3 py-2 rounded-lg bg-background border border-border">
                                <span class="text-sm text-foreground font-medium">
                                    {{ t('forms.enabled') }}
                                </span>
                                <SwitchInput
                                    v-model="showEnabled"
                                />
                            </div>
                            <div class="flex items-center gap-2 px-3 py-2 rounded-lg bg-background border border-border">
                                <span class="text-sm text-foreground font-medium">
                                    {{ t('forms.disabled') }}
                                </span>
                                <SwitchInput
                                    v-model="showDisabled"
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Forms Grid -->
                <div class="flex-1 min-h-0 overflow-y-auto">
                    <!-- Initial loading state -->
                    <div v-if="loading && formsStore.publicForms.length === 0" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <FormCardSkeleton v-for="i in 6" :key="`skeleton-initial-${i}`" />
                    </div>

                    <!-- Empty state -->
                    <div v-else-if="formsStore.publicForms.length === 0" class="flex items-center justify-center h-64">
                        <div class="text-center">
                            <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-muted flex items-center justify-center">
                                <Icon name="file-text" size="xl" class="text-muted-foreground" />
                            </div>
                            <h3 class="text-lg font-medium mb-1">{{ t('forms.noForms') }}</h3>
                            <p class="text-sm text-muted-foreground mb-4">{{ t('forms.noFormsDescription') }}</p>
                            <Button variant="primary" @click="showCreateDialog = true">
                                <Icon name="plus" size="sm" />
                                {{ t('forms.createFirstForm') }}
                            </Button>
                        </div>
                    </div>

                    <!-- Forms list -->
                    <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 pb-4">
                        <FormCard
                            v-for="form in formsStore.publicForms"
                            :key="form.id"
                            :form="form"
                            @view="handleView"
                            @select="handleSelect"
                            @edit="handleEdit"
                            @enable="handleEnableRequest"
                            @delete="handleDelete"
                        />
                        
                        <!-- Loading more skeletons -->
                        <template v-if="hasMore">
                            <FormCardSkeleton v-for="i in 3" :key="`skeleton-more-${i}`" />
                            <!-- Intersection observer trigger element -->
                            <div :ref="(el) => { if (el) triggerElement = el as HTMLElement }" class="col-span-full h-20 -mt-16"></div>
                        </template>
                    </div>
                </div>
        </div>

        <!-- Trash Tab -->
        <div v-if="activeTab === 'trash'" class="flex-1 min-h-0 flex flex-col gap-4">
                <!-- Warning Alert -->
                <div class="flex items-start gap-3 p-4 rounded-lg border border-amber-500/20 bg-amber-500/10">
                    <div class="shrink-0 text-amber-600 dark:text-amber-500">
                        <Icon name="alert-triangle" size="lg" />
                    </div>
                    <div class="flex-1 min-w-0">
                        <h4 class="font-medium text-sm mb-1 text-amber-900 dark:text-amber-100">
                            {{ t('forms.formsTrashWarningTitle') }}
                        </h4>
                        <p class="text-sm text-amber-800 dark:text-amber-200">
                            {{ t('forms.formsTrashWarningMessage') }}
                        </p>
                    </div>
                </div>

                <!-- Trash Forms Grid -->
                <div class="flex-1 min-h-0 overflow-y-auto">
                    <!-- Initial loading state -->
                    <div v-if="loading && formsStore.forms.length === 0" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <FormCardSkeleton v-for="i in 6" :key="`skeleton-trash-initial-${i}`" />
                    </div>

                    <!-- Empty state -->
                    <div v-else-if="formsStore.forms.length === 0" class="flex items-center justify-center h-64">
                        <div class="text-center">
                            <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-muted flex items-center justify-center">
                                <Icon name="trash" size="xl" class="text-muted-foreground" />
                            </div>
                            <h3 class="text-lg font-medium mb-1">{{ t('forms.noTrashedForms') }}</h3>
                            <p class="text-sm text-muted-foreground">{{ t('forms.noTrashedFormsDescription') }}</p>
                        </div>
                    </div>

                    <!-- Trash list -->
                    <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 pb-4">
                        <FormCard
                            v-for="form in formsStore.forms"
                            :key="form.id"
                            :form="form"
                            :is-trashed="true"
                            @restore="handleRestore"
                            @force-delete="handleForceDelete"
                        />
                        
                        <!-- Loading more skeletons for trash -->
                        <template v-if="hasMore">
                            <FormCardSkeleton v-for="i in 3" :key="`skeleton-trash-more-${i}`" />
                            <!-- Intersection observer trigger element -->
                            <div :ref="(el) => { if (el) triggerElement = el as HTMLElement }" class="col-span-full h-20 -mt-16"></div>
                        </template>
                    </div>
                </div>
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

        <!-- Enable Dialog -->
        <EnableFormDialog
            v-model="showEnableDialog"
            :form-id="formToEnable"
            @confirmed="handleEnableConfirmed"
        />

        <!-- Preview Dialog -->
        <Dialog
            v-if="previewFormId"
            v-model="showPreviewDialog"
            :title="formsStore.getFormById(previewFormId)?.name || t('forms.preview')"
            width="xl"
        >
            <div v-if="loadingPreview" class="flex items-center justify-center py-12">
                <Icon name="loader-2" class="animate-spin text-muted-foreground" size="lg" />
            </div>
            <div v-else-if="formsStore.getFormById(previewFormId)" class="overflow-y-auto max-h-[70vh]">
                <FormViewer
                    :form="formsStore.getFormById(previewFormId)!"
                    mode="preview"
                />
            </div>
            <div v-else class="flex items-center justify-center py-12 text-muted-foreground">
                <p>{{ t('forms.formNotFoundDescription') }}</p>
            </div>
        </Dialog>
    </div>
</template>
