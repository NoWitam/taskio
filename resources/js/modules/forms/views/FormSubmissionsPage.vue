<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useFormsStore } from '@/store/forms'
import { useI18n } from '@/composables/useI18n'
import { useToast } from '@/composables/useToast'
import { useInfiniteScroll } from '@/composables/useInfiniteScroll'
import type { SubmissionFilters } from '@/store/forms'
import Button from '@/components/ui/Button.vue'
import Icon from '@/components/ui/Icon.vue'
import TextInput from '@/components/ui/inputs/TextInput.vue'
import SelectInput from '@/components/ui/inputs/SelectInput.vue'
import DateInput from '@/components/ui/inputs/DateInput.vue'
import Tabs from '@/components/ui/Tabs.vue'
import Dialog from '@/components/ui/Dialog.vue'
import SubmissionCard from '../components/SubmissionCard.vue'
import SubmissionCardSkeleton from '../components/SubmissionCardSkeleton.vue'
import CreateSubmissionDialog from '../components/Dialogs/CreateSubmissionDialog.vue'
import FormViewer from '../components/FormViewer/FormViewer.vue'

const router = useRouter()
const route = useRoute()
const formsStore = useFormsStore()
const toast = useToast()
const { t } = useI18n()

const formId = computed(() => route.params.formId as string)
const form = computed(() => formsStore.getFormById(formId.value))

// State
const showCreateDialog = ref(false)
const showPreviewDialog = ref(false)
const previewSubmissionId = ref<string | null>(null)
const activeTab = ref<'all' | 'trash'>('all')
const isInitializing = ref(true)

// Filters
const filters = ref<SubmissionFilters>({
    search: '',
    sources: [],
    indexed: null,
    trashed: false,
    date_from: null,
    date_to: null,
    sort: 'newest'
})

// Source options for SelectInput
const sourceOptions = computed(() => [
    { label: t('forms.manual'), value: 'form', icon: 'file-text' },
    { label: t('forms.task'), value: 'task', icon: 'check-square' }
])

// Sort options
const sortOptions = computed(() => [
    { label: t('forms.newest'), value: 'newest' },
    { label: t('forms.oldest'), value: 'oldest' }
])

// Indexed filter options (only shown when form is indexed)
const indexedOptions = computed(() => [
    { label: t('forms.indexed'), value: 'true' },
    { label: t('forms.unindexed'), value: 'false' }
])

const tabs = computed(() => [
    { id: 'all', label: t('forms.allSubmissions'), icon: 'inbox' },
    { id: 'trash', label: t('forms.trash'), icon: 'trash' }
])

const loading = computed(() => formsStore.loading[`submissions_${formId.value}`] || false)
const hasMore = computed(() => formsStore.hasMore[`submissions_${formId.value}`] || false)
const submissions = computed(() => formsStore.submissions[formId.value] || [])

// Get preview submission
const previewSubmission = computed(() => {
    if (!previewSubmissionId.value) return null
    return submissions.value.find(s => s.id === previewSubmissionId.value)
})

// Fetch submissions with current filters
const fetchSubmissions = (resetCursor = true) => {
    formsStore.fetchSubmissions(formId.value, {
        search: filters.value.search || undefined,
        sources: filters.value.sources && filters.value.sources.length > 0 
            ? filters.value.sources 
            : undefined,
        indexed: filters.value.indexed || undefined,
        trashed: filters.value.trashed,
        date_from: filters.value.date_from || undefined,
        date_to: filters.value.date_to || undefined,
        sort: filters.value.sort
    }, resetCursor)
}

// Update URL params based on current filters
const updateURLParams = () => {
    const query: Record<string, string> = {}
    
    if (filters.value.search) {
        query.search = filters.value.search
    }
    
    if (activeTab.value === 'trash') {
        query.tab = 'trash'
    }
    
    if (filters.value.sources && filters.value.sources.length > 0) {
        query.sources = filters.value.sources.join(',')
    }

    if (filters.value.indexed) {
        query.indexed = filters.value.indexed
    }
    
    if (filters.value.date_from) {
        query.date_from = filters.value.date_from
    }
    
    if (filters.value.date_to) {
        query.date_to = filters.value.date_to
    }
    
    if (filters.value.sort && filters.value.sort !== 'newest') {
        query.sort = filters.value.sort
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
    
    // Initialize sources
    if (query.sources && typeof query.sources === 'string') {
        filters.value.sources = query.sources.split(',').filter(s => s === 'task' || s === 'form')
    }

    // Initialize indexed filter
    if (query.indexed === 'true' || query.indexed === 'false') {
        filters.value.indexed = query.indexed
    }
    
    // Initialize date range
    if (query.date_from && typeof query.date_from === 'string') {
        filters.value.date_from = query.date_from
    }
    
    if (query.date_to && typeof query.date_to === 'string') {
        filters.value.date_to = query.date_to
    }
    
    // Initialize sort
    if (query.sort === 'oldest') {
        filters.value.sort = 'oldest'
    }
}

// Watch tab changes
watch(activeTab, (newTab) => {
    filters.value.trashed = newTab === 'trash'
    if (!isInitializing.value) {
        updateURLParams()
        fetchSubmissions()
    }
})

// Watch search with debounce
let searchTimeout: ReturnType<typeof setTimeout> | null = null
watch(() => filters.value.search, () => {
    if (searchTimeout) clearTimeout(searchTimeout)
    searchTimeout = setTimeout(() => {
        if (!isInitializing.value) {
            updateURLParams()
            fetchSubmissions()
        }
    }, 500)
})

// Watch sources filter
watch(() => filters.value.sources, () => {
    if (!isInitializing.value) {
        updateURLParams()
        fetchSubmissions()
    }
}, { deep: true })

// Watch indexed filter
watch(() => filters.value.indexed, () => {
    if (!isInitializing.value) {
        updateURLParams()
        fetchSubmissions()
    }
})

// Watch date_from
watch(() => filters.value.date_from, () => {
    if (!isInitializing.value) {
        updateURLParams()
        fetchSubmissions()
    }
})

// Watch date_to
watch(() => filters.value.date_to, () => {
    if (!isInitializing.value) {
        updateURLParams()
        fetchSubmissions()
    }
})

// Watch sort
watch(() => filters.value.sort, () => {
    if (!isInitializing.value) {
        updateURLParams()
        fetchSubmissions()
    }
})

// Watch route query (browser back/forward)
watch(() => route.query, () => {
    if (!isInitializing.value) {
        initFromURL()
        fetchSubmissions()
    }
})

// Load more
const loadMore = () => {
    if (!loading.value && hasMore.value) {
        fetchSubmissions(false)
    }
}

// Setup infinite scroll
const { triggerElement } = useInfiniteScroll(loadMore, {
    rootMargin: '400px',
    threshold: 0
})

// Handle create
const handleSubmissionCreated = () => {
    showCreateDialog.value = false
    fetchSubmissions()
}

// Handle view
const handleView = (submissionId: string) => {
    previewSubmissionId.value = submissionId
    showPreviewDialog.value = true
}

// Handle delete
const handleDelete = async (submissionId: string) => {
    if (!confirm('Czy na pewno chcesz usunąć to wypełnienie?')) return

    try {
        await formsStore.deleteSubmission(submissionId, formId.value)
        toast.push({
            tone: 'success',
            title: t('common.success'),
            message: t('forms.submissionDeleted'),
            timeoutMs: 3500
        })
    } catch (err: any) {
        toast.push({
            tone: 'danger',
            title: t('common.error'),
            message: err.response?.data?.message || t('forms.deleteSubmissionError'),
            timeoutMs: 4500
        })
    }
}

// Handle restore
const handleRestore = async (submissionId: string) => {
    try {
        await formsStore.restoreSubmission(submissionId, formId.value)
        toast.push({
            tone: 'success',
            title: t('common.success'),
            message: t('forms.submissionRestored'),
            timeoutMs: 3500
        })
        fetchSubmissions()
    } catch (err: any) {
        toast.push({
            tone: 'danger',
            title: t('common.error'),
            message: err.response?.data?.message || t('forms.restoreSubmissionError'),
            timeoutMs: 4500
        })
    }
}

// Handle force delete
const handleForceDelete = async (submissionId: string) => {
    if (!confirm('Czy na pewno chcesz TRWALE usunąć to wypełnienie? Ta akcja jest nieodwracalna.')) return

    try {
        await formsStore.forceDeleteSubmission(submissionId, formId.value)
        toast.push({
            tone: 'success',
            title: t('common.success'),
            message: t('forms.submissionPermanentlyDeleted'),
            timeoutMs: 3500
        })
    } catch (err: any) {
        toast.push({
            tone: 'danger',
            title: t('common.error'),
            message: err.response?.data?.message || t('forms.forceDeleteSubmissionError'),
            timeoutMs: 4500
        })
    }
}

// Initial load
onMounted(() => {
    initFromURL()
    isInitializing.value = false
    fetchSubmissions()
})
</script>

<template>
    <div class="h-full max-h-full min-h-0 flex flex-col gap-6 px-6 pt-6 overflow-hidden">
        <!-- Header -->
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-2xl font-bold">{{ t('forms.submissions') }}</h2>
                <p class="text-sm text-muted-foreground mt-1">
                    {{ t('forms.submissionsDescription') }}
                </p>
            </div>
            <Button 
                v-if="form?.is_enabled" 
                variant="primary" 
                @click="showCreateDialog = true"
            >
                <Icon name="plus" size="sm" />
                {{ t('forms.createSubmission') }}
            </Button>
        </div>

        <!-- Tabs -->
        <Tabs :tabs="tabs" v-model="activeTab" />

        <!-- All Submissions Tab -->
        <div v-if="activeTab === 'all'" class="flex-1 min-h-0 flex flex-col gap-4">
            <!-- Filters -->
            <div class="grid grid-cols-12 gap-3">
                <!-- Search -->
                <div :class="form?.is_indexed ? 'col-span-3' : 'col-span-4'">
                    <TextInput
                        v-model="filters.search"
                        :placeholder="t('forms.searchSubmissionsPlaceholder')"
                    >
                        <template #left>
                            <span class="text-muted-foreground">
                                <Icon name="search" />
                            </span>
                        </template>
                    </TextInput>
                </div>

                <!-- Sources MultiSelect -->
                <div :class="form?.is_indexed ? 'col-span-2' : 'col-span-3'">
                    <SelectInput
                        v-model="filters.sources"
                        :options="sourceOptions"
                        :placeholder="t('forms.allSources')"
                        :multiple="true"
                        :clearable="true"
                    >
                        <template #left>
                            <span class="text-muted-foreground">
                                <Icon name="folder" />
                            </span>
                        </template>
                        <template #item="{ item }">
                            <Icon :name="item.icon" size="sm" />
                            <span class="truncate">{{ item.label }}</span>
                        </template>
                    </SelectInput>
                </div>

                <!-- Indexed Filter (only when form is indexed) -->
                <div v-if="form?.is_indexed" class="col-span-2">
                    <SelectInput
                        v-model="filters.indexed"
                        :options="indexedOptions"
                        :placeholder="t('forms.allIndexStatuses')"
                        :clearable="true"
                    >
                        <template #left>
                            <span class="text-muted-foreground">
                                <Icon name="database" />
                            </span>
                        </template>
                    </SelectInput>
                </div>

                <!-- Date From -->
                <div class="col-span-2">
                    <DateInput
                        v-model="filters.date_from"
                        :placeholder="t('forms.dateFrom')"
                        :clearable="true"
                    >
                        <template #left>
                            <span class="text-muted-foreground">
                                <Icon name="calendar" />
                            </span>
                        </template>
                    </DateInput>
                </div>

                <!-- Date To -->
                <div class="col-span-2">
                    <DateInput
                        v-model="filters.date_to"
                        :placeholder="t('forms.dateTo')"
                        :clearable="true"
                    >
                        <template #left>
                            <span class="text-muted-foreground">
                                <Icon name="calendar" />
                            </span>
                        </template>
                    </DateInput>
                </div>

                <!-- Sort -->
                <div class="col-span-1">
                    <SelectInput
                        v-model="filters.sort"
                        :options="sortOptions"
                        :placeholder="t('forms.sort')"
                    >
                        <template #left>
                            <span class="text-muted-foreground">
                                <Icon name="sliders-horizontal" />
                            </span>
                        </template>
                    </SelectInput>
                </div>
            </div>

            <!-- Submissions Grid -->
            <div class="flex-1 min-h-0 overflow-y-auto">
                <!-- Loading skeleton -->
                <div v-if="loading && submissions.length === 0" class="grid grid-cols-3 gap-4">
                    <SubmissionCardSkeleton v-for="i in 6" :key="i" />
                </div>

                <!-- Empty state -->
                <div v-else-if="submissions.length === 0" class="flex items-center justify-center h-full">
                    <div class="text-center">
                        <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-muted flex items-center justify-center">
                            <Icon name="inbox" size="xl" class="text-muted-foreground" />
                        </div>
                        <h3 class="text-lg font-medium mb-1">{{ t('forms.noSubmissions') }}</h3>
                        <p class="text-sm text-muted-foreground mb-4">{{ t('forms.noSubmissionsDescription') }}</p>
                        <Button 
                            v-if="form?.is_enabled" 
                            variant="primary" 
                            @click="showCreateDialog = true"
                        >
                            <Icon name="plus" size="sm" />
                            {{ t('forms.createFirstSubmission') }}
                        </Button>
                    </div>
                </div>

                <!-- Grid with submissions -->
                <div v-else>
                    <div class="grid grid-cols-3 gap-4 pb-4">
                        <SubmissionCard
                            v-for="submission in submissions"
                            :key="submission.id"
                            :submission="submission"
                            :form="form"
                            @view="handleView"
                            @delete="handleDelete"
                        />
                    </div>

                    <!-- Loading more skeletons -->
                    <div v-if="hasMore" class="grid grid-cols-3 gap-4">
                        <SubmissionCardSkeleton v-for="i in 3" :key="`skeleton-${i}`" />
                    </div>

                    <!-- Infinite scroll trigger -->
                    <div ref="triggerElement" class="h-1"></div>
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
                        {{ t('forms.trashWarningTitle') }}
                    </h4>
                    <p class="text-sm text-amber-800 dark:text-amber-200">
                        {{ t('forms.trashWarningMessage') }}
                    </p>
                </div>
            </div>

            <!-- Trash Grid -->
            <div class="flex-1 min-h-0 overflow-y-auto">
                <!-- Loading skeleton -->
                <div v-if="loading && submissions.length === 0" class="grid grid-cols-3 gap-4">
                    <SubmissionCardSkeleton v-for="i in 6" :key="i" />
                </div>

                <!-- Empty state -->
                <div v-else-if="submissions.length === 0" class="flex items-center justify-center h-full">
                    <div class="text-center">
                        <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-muted flex items-center justify-center">
                            <Icon name="trash" size="xl" class="text-muted-foreground" />
                        </div>
                        <h3 class="text-lg font-medium mb-1">{{ t('forms.noTrashedSubmissions') }}</h3>
                        <p class="text-sm text-muted-foreground">{{ t('forms.noTrashedSubmissionsDescription') }}</p>
                    </div>
                </div>

                <!-- Grid with trashed submissions -->
                <div v-else>
                    <div class="grid grid-cols-3 gap-4 pb-4">
                        <SubmissionCard
                            v-for="submission in submissions"
                            :key="submission.id"
                            :submission="submission"
                            :form="form"
                            :is-trashed="true"
                            @restore="handleRestore"
                            @force-delete="handleForceDelete"
                        />
                    </div>

                    <!-- Loading more skeletons -->
                    <div v-if="hasMore" class="grid grid-cols-3 gap-4">
                        <SubmissionCardSkeleton v-for="i in 3" :key="`skeleton-trash-${i}`" />
                    </div>

                    <!-- Infinite scroll trigger -->
                    <div ref="triggerElement" class="h-1"></div>
                </div>
            </div>
        </div>

        <!-- Create Dialog -->
        <CreateSubmissionDialog
            v-if="form"
            v-model="showCreateDialog"
            :form="form"
            @created="handleSubmissionCreated"
        />

        <!-- Preview Dialog -->
        <Dialog
            v-if="previewSubmission && form"
            v-model="showPreviewDialog"
            :title="t('forms.viewSubmission')"
            width="xl"
        >
            <div class="overflow-y-auto max-h-[70vh]">
                <FormViewer
                    :form="form"
                    :initial-data="previewSubmission.data"
                    mode="preview"
                />
            </div>
            
            <template #footer>
                <div class="flex justify-between items-center w-full">
                    <div class="text-sm text-muted-foreground">
                        <span class="flex items-center gap-2">
                            <Icon name="user" size="sm" />
                            {{ previewSubmission.creator?.name || t('forms.anonymous') }}
                            <span class="text-muted-foreground/50">•</span>
                            <Icon name="calendar" size="sm" />
                            {{ new Date(previewSubmission.approved_at || previewSubmission.created_at).toLocaleDateString() }}
                        </span>
                    </div>
                    <Button variant="secondary" @click="showPreviewDialog = false">
                        {{ t('common.close') }}
                    </Button>
                </div>
            </template>
        </Dialog>
    </div>
</template>
