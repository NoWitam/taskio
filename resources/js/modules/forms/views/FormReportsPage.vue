<script setup lang=ts>
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useFormsStore } from '@/store/forms'
import { useI18n } from '@/composables/useI18n'
import { useToast } from '@/composables/useToast'
import { useInfiniteScroll } from '@/composables/useInfiniteScroll'
import type { ReportFilters } from '@/store/forms'
import type { FormReport } from '@/types/forms'
import Button from '@/components/ui/Button.vue'
import Icon from '@/components/ui/Icon.vue'
import TextInput from '@/components/ui/inputs/TextInput.vue'
import SelectInput from '@/components/ui/inputs/SelectInput.vue'
import DateInput from '@/components/ui/inputs/DateInput.vue'
import UserSelect from '@/components/ui/inputs/reusable/UserSelect.vue'
import Tabs from '@/components/ui/Tabs.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import ReportCard from '../components/ReportCard.vue'
import CreateReportDialog from '../components/Dialogs/CreateReportDialog.vue'
import ViewReportDialog from '../components/Dialogs/ViewReportDialog.vue'

const router = useRouter()
const route = useRoute()
const formsStore = useFormsStore()
const toast = useToast()
const { t } = useI18n()

const formId = computed(() => route.params.formId as string)
const form = computed(() => formsStore.getFormById(formId.value))

// State
const showCreateDialog = ref(false)
const showViewDialog = ref(false)
const viewingReport = ref<FormReport | null>(null)
const reportContent = ref('')
const activeTab = ref<'all' | 'trash'>('all')
const isInitializing = ref(true)
const initialLoadComplete = ref(false) // Prevent auto-load on mount

// Filters
const filters = ref<ReportFilters>({
    search: '',
    creator_id: [],
    trashed: false,
    date_from: '',
    date_to: '',
    sort: 'newest',
    only_completed: false,
    only_pending: false
})

// Sort options
const sortOptions = computed(() => [
    { label: t('forms.newest'), value: 'newest' },
    { label: t('forms.oldest'), value: 'oldest' }
])

const tabs = computed(() => [
    { id: 'all', label: t('forms.allReports'), icon: 'file-text' },
    { id: 'trash', label: t('forms.trash'), icon: 'trash' }
])

const loading = computed(() => formsStore.loading[`reports_${formId.value}`] || false)
const hasMore = computed(() => formsStore.hasMore[`reports_${formId.value}`] || false)
const reports = computed(() => formsStore.reports[formId.value] || [])

// Fetch reports with current filters
const fetchReports = async (resetCursor = true) => {
    // Guard: don't fetch if already loading
    if (loading.value && !resetCursor) {
        return
    }
    
    // Reset infinite scroll flag when starting fresh
    if (resetCursor) {
        initialLoadComplete.value = false
    }
    
    await formsStore.fetchReports(formId.value, {
        search: filters.value.search || undefined,
        creator_id: filters.value.creator_id && filters.value.creator_id.length > 0 ? filters.value.creator_id : undefined,
        trashed: filters.value.trashed,
        date_from: filters.value.date_from || undefined,
        date_to: filters.value.date_to || undefined,
        sort: filters.value.sort,
        only_completed: filters.value.only_completed || undefined,
        only_pending: filters.value.only_pending || undefined
    }, resetCursor)
    
    // Re-enable infinite scroll after data loads
    if (resetCursor) {
        setTimeout(() => {
            initialLoadComplete.value = true
        }, 100)
    }
}

// Update URL params based on current filters
const updateURLParams = () => {
    const query: Record<string, any> = {}
    
    if (filters.value.search) {
        query.search = filters.value.search
    }
    
    if (activeTab.value === 'trash') {
        query.tab = 'trash'
    }
    
    if (filters.value.creator_id && filters.value.creator_id.length > 0) {
        query.creators = filters.value.creator_id
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
    
    if (query.search && typeof query.search === 'string') {
        filters.value.search = query.search
    }
    
    if (query.tab === 'trash') {
        activeTab.value = 'trash'
        filters.value.trashed = true
    }
    
    // Support both creators[] and legacy creator_id
    const creatorIds = Array.isArray(query.creators) ? query.creators : 
                      query.creators ? [query.creators] :
                      query.creator_id ? [query.creator_id] : []
    filters.value.creator_id = creatorIds.map(String).filter(Boolean)
    
    if (query.date_from && typeof query.date_from === 'string') {
        filters.value.date_from = query.date_from
    }
    
    if (query.date_to && typeof query.date_to === 'string') {
        filters.value.date_to = query.date_to
    }
    
    if (query.sort === 'oldest') {
        filters.value.sort = 'oldest'
    }
}

// Watch tab changes
watch(activeTab, (newTab) => {
    filters.value.trashed = newTab === 'trash'
    if (!isInitializing.value) {
        updateURLParams()
        fetchReports()
    }
})

// Watch search with debounce
let searchTimeout: ReturnType<typeof setTimeout> | null = null
watch(() => filters.value.search, () => {
    if (searchTimeout) clearTimeout(searchTimeout)
    searchTimeout = setTimeout(() => {
        if (!isInitializing.value) {
            updateURLParams()
            fetchReports()
        }
    }, 500)
})

// Watch creator_id
watch(() => filters.value.creator_id, (newVal, oldVal) => {
    if (isInitializing.value) return
    
    // Check if actually changed (deep compare arrays)
    if (JSON.stringify(newVal) === JSON.stringify(oldVal)) {
        return
    }
    
    updateURLParams()
    fetchReports()
}, { deep: true })

// Watch date_from
watch(() => filters.value.date_from, () => {
    if (!isInitializing.value) {
        updateURLParams()
        fetchReports()
    }
})

// Watch date_to
watch(() => filters.value.date_to, () => {
    if (!isInitializing.value) {
        updateURLParams()
        fetchReports()
    }
})

// Watch sort
watch(() => filters.value.sort, () => {
    if (!isInitializing.value) {
        updateURLParams()
        fetchReports()
    }
})

// Watch route query (browser back/forward)
watch(() => route.query, () => {
    if (!isInitializing.value) {
        initFromURL()
        fetchReports()
    }
})

// Load more
const loadMore = () => {
    // Don't auto-load immediately after initial load
    if (!initialLoadComplete.value) {
        return
    }
    
    if (!loading.value && hasMore.value) {
        fetchReports(false)
    }
}

// Setup infinite scroll
const { triggerElement } = useInfiniteScroll(loadMore, {
    rootMargin: '400px',
    threshold: 0
})

// Watch initialLoadComplete - trigger loadMore if it becomes true and element is visible
watch(initialLoadComplete, (isComplete) => {
    if (isComplete && !loading.value && hasMore.value) {
        // Small delay to ensure everything is settled
        setTimeout(() => {
            loadMore()
        }, 150)
    }
})

// Handle view
const handleView = async (reportId: string) => {
    try {
        // Fetch full report with file
        const report = await formsStore.fetchReport(reportId)
        
        if (!report.is_completed || !report.file) {
            toast.push({
                tone: 'warning',
                title: t('common.warning'),
                message: t('forms.reportNotReady'),
                timeoutMs: 3500
            })
            return
        }

        // Fetch file content from file.path URL with inline parameter
        const response = await fetch(report.file.path + '?inline=1')
        if (!response.ok) {
            throw new Error('Failed to fetch report content')
        }
        const content = await response.text()
        
        reportContent.value = content
        viewingReport.value = report
        showViewDialog.value = true
    } catch (err: any) {
        toast.push({
            tone: 'danger',
            title: t('common.error'),
            message: err.response?.data?.message || t('forms.fetchReportError'),
            timeoutMs: 4500
        })
    }
}

// Handle delete
const handleDelete = async (reportId: string) => {
    if (!confirm('Czy na pewno chcesz usunąć ten raport?')) return

    try {
        await formsStore.deleteReport(reportId, formId.value)
        toast.push({
            tone: 'success',
            title: t('common.success'),
            message: t('forms.reportDeleted'),
            timeoutMs: 3500
        })
    } catch (err: any) {
        toast.push({
            tone: 'danger',
            title: t('common.error'),
            message: err.response?.data?.message || t('forms.deleteReportError'),
            timeoutMs: 4500
        })
    }
}

// Handle report created
const handleReportCreated = () => {
    showCreateDialog.value = false
    fetchReports()
}

// Handle restore
const handleRestore = async (reportId: string) => {
    try {
        await formsStore.restoreReport(reportId, formId.value)
        toast.push({
            tone: 'success',
            title: t('common.success'),
            message: t('forms.reportRestored'),
            timeoutMs: 3500
        })
        fetchReports()
    } catch (err: any) {
        toast.push({
            tone: 'danger',
            title: t('common.error'),
            message: err.response?.data?.message || t('forms.restoreReportError'),
            timeoutMs: 4500
        })
    }
}

// Initial load
onMounted(async () => {
    if (!form.value) {
        await formsStore.fetchForm(formId.value)
    }
    initFromURL()
    await fetchReports()
    // Set after fetchReports to prevent watchers from triggering during init
    isInitializing.value = false
})
</script>

<template>
    <div class='h-full max-h-full min-h-0 flex flex-col gap-6 px-6 pt-6 overflow-hidden'>
        <div class='flex items-center justify-between'>
            <div>
                <h2 class='text-2xl font-bold'>{{ t('forms.reports') }}</h2>
                <p class='text-sm text-muted-foreground mt-1'>
                    {{ t('forms.reportsDescription') }}
                </p>
            </div>
            <Button 
                variant='primary' 
                @click='showCreateDialog = true'
            >
                <Icon name='plus' size='sm' />
                {{ t('forms.createReport') }}
            </Button>
        </div>

        <Tabs :tabs='tabs' v-model='activeTab' />

        <div v-if='activeTab === "all"' class='flex-1 min-h-0 flex flex-col gap-4'>
            <div class='grid grid-cols-12 gap-3'>
                <div class='col-span-4'>
                    <TextInput
                        v-model='filters.search'
                        :placeholder='t("forms.searchReportsPlaceholder")'
                    >
                        <template #left>
                            <span class='text-muted-foreground'>
                                <Icon name='search' />
                            </span>
                        </template>
                    </TextInput>
                </div>

                <div class='col-span-3'>
                    <UserSelect
                        v-model='filters.creator_id'
                        :placeholder='t("forms.allCreators")'
                        multiple
                    />
                </div>

                <div class='col-span-2'>
                    <DateInput
                        v-model='filters.date_from'
                        :placeholder='t("forms.dateFrom")'
                        :clearable='true'
                    >
                        <template #left>
                            <span class='text-muted-foreground'>
                                <Icon name='calendar' />
                            </span>
                        </template>
                    </DateInput>
                </div>

                <div class='col-span-2'>
                    <DateInput
                        v-model='filters.date_to'
                        :placeholder='t("forms.dateTo")'
                        :clearable='true'
                    >
                        <template #left>
                            <span class='text-muted-foreground'>
                                <Icon name='calendar' />
                            </span>
                        </template>
                    </DateInput>
                </div>

                <div class='col-span-1'>
                    <SelectInput
                        v-model='filters.sort'
                        :options='sortOptions'
                        :placeholder='t("forms.sort")'
                    >
                        <template #left>
                            <span class='text-muted-foreground'>
                                <Icon name='sliders-horizontal' />
                            </span>
                        </template>
                    </SelectInput>
                </div>
            </div>

            <div class='flex-1 min-h-0 overflow-y-auto'>
                <div v-if='loading && reports.length === 0' class='grid grid-cols-3 gap-4'>
                    <div v-for='i in 6' :key='i' class='border border-border rounded-lg p-4'>
                        <!-- Header: status icon + name -->
                        <div class='flex items-start gap-2 mb-3'>
                            <Skeleton width='28px' height='28px' rounded='full' />
                            <Skeleton height='24px' class='flex-1' />
                        </div>
                        
                        <!-- Body: guidelines (2 lines) -->
                        <div class='space-y-2 mb-3'>
                            <Skeleton height='40px' />
                            <!-- Date + sources -->
                            <div class='flex gap-2'>
                                <Skeleton width='120px' height='20px' />
                                <Skeleton width='100px' height='20px' rounded='full' />
                            </div>
                        </div>
                        
                        <!-- Footer: creator + date -->
                        <div class='pt-3 border-t border-border flex items-center justify-between'>
                            <div class='flex items-center gap-2'>
                                <Skeleton width='24px' height='24px' rounded='full' />
                                <Skeleton width='80px' height='16px' />
                            </div>
                            <Skeleton width='70px' height='16px' />
                        </div>
                    </div>
                </div>

                <div v-else-if='reports.length === 0' class='flex items-center justify-center h-full'>
                    <div class='text-center'>
                        <div class='w-16 h-16 mx-auto mb-4 rounded-full bg-muted flex items-center justify-center'>
                            <Icon name='file-text' size='xl' class='text-muted-foreground' />
                        </div>
                        <h3 class='text-lg font-medium mb-1'>{{ t('forms.noReports') }}</h3>
                        <p class='text-sm text-muted-foreground mb-4'>{{ t('forms.noReportsDescription') }}</p>
                        <Button 
                            variant='primary' 
                            @click='showCreateDialog = true'
                        >
                            <Icon name='plus' size='sm' />
                            {{ t('forms.createFirstReport') }}
                        </Button>
                    </div>
                </div>

                <div v-else>
                    <div class='grid grid-cols-3 gap-4 pb-4'>
                        <ReportCard
                            v-for='report in reports'
                            :key='report.id'
                            :report='report'
                            @view='handleView'
                            @delete='handleDelete'
                        />
                    </div>

                    <div v-if='hasMore' class='grid grid-cols-3 gap-4'>
                        <div v-for='i in 3' :key='`skeleton-${i}`' class='border border-border rounded-lg p-4'>
                            <!-- Header: status icon + name -->
                            <div class='flex items-start gap-2 mb-3'>
                                <Skeleton width='28px' height='28px' rounded='full' />
                                <Skeleton height='24px' class='flex-1' />
                            </div>
                            
                            <!-- Body: guidelines (2 lines) -->
                            <div class='space-y-2 mb-3'>
                                <Skeleton height='40px' />
                                <!-- Date + sources -->
                                <div class='flex gap-2'>
                                    <Skeleton width='120px' height='20px' />
                                    <Skeleton width='100px' height='20px' rounded='full' />
                                </div>
                            </div>
                            
                            <!-- Footer: creator + date -->
                            <div class='pt-3 border-t border-border flex items-center justify-between'>
                                <div class='flex items-center gap-2'>
                                    <Skeleton width='24px' height='24px' rounded='full' />
                                    <Skeleton width='80px' height='16px' />
                                </div>
                                <Skeleton width='70px' height='16px' />
                            </div>
                        </div>
                    </div>

                    <div v-show='hasMore' ref='triggerElement' class='h-1'></div>
                </div>
            </div>
        </div>

        <div v-if='activeTab === "trash"' class='flex-1 min-h-0 flex flex-col gap-4'>
            <div class='flex items-start gap-3 p-4 rounded-lg border border-amber-500/20 bg-amber-500/10'>
                <div class='shrink-0 text-amber-600 dark:text-amber-500'>
                    <Icon name='alert-triangle' size='lg' />
                </div>
                <div class='flex-1 min-w-0'>
                    <h4 class='font-medium text-sm mb-1 text-amber-900 dark:text-amber-100'>
                        {{ t('forms.trashWarningTitle') }}
                    </h4>
                    <p class='text-sm text-amber-800 dark:text-amber-200'>
                        {{ t('forms.trashWarningMessage') }}
                    </p>
                </div>
            </div>

            <div class='flex-1 min-h-0 overflow-y-auto'>
                <div v-if='loading && reports.length === 0' class='grid grid-cols-3 gap-4'>
                    <div v-for='i in 6' :key='i' class='border rounded-lg p-4'>
                        <!-- Header: status icon + name -->
                        <div class='flex items-start gap-2 mb-3'>
                            <Skeleton width='28px' height='28px' rounded='full' />
                            <Skeleton height='24px' class='flex-1' />
                        </div>
                        
                        <!-- Body: guidelines (2 lines) -->
                        <div class='space-y-2 mb-3'>
                            <Skeleton height='40px' />
                            <!-- Date + sources -->
                            <div class='flex gap-2'>
                                <Skeleton width='120px' height='20px' />
                                <Skeleton width='100px' height='20px' rounded='full' />
                            </div>
                        </div>
                        
                        <!-- Footer: creator + date -->
                        <div class='pt-3 border-t border-border flex items-center justify-between'>
                            <div class='flex items-center gap-2'>
                                <Skeleton width='24px' height='24px' rounded='full' />
                                <Skeleton width='80px' height='16px' />
                            </div>
                            <Skeleton width='70px' height='16px' />
                        </div>
                    </div>
                </div>

                <div v-else-if='reports.length === 0' class='flex items-center justify-center h-full'>
                    <div class='text-center'>
                        <div class='w-16 h-16 mx-auto mb-4 rounded-full bg-muted flex items-center justify-center'>
                            <Icon name='trash' size='xl' class='text-muted-foreground' />
                        </div>
                        <h3 class='text-lg font-medium mb-1'>{{ t('forms.noTrashedReports') }}</h3>
                        <p class='text-sm text-muted-foreground'>{{ t('forms.noTrashedReportsDescription') }}</p>
                    </div>
                </div>

                <div v-else>
                    <div class='grid grid-cols-3 gap-4 pb-4'>
                        <ReportCard
                            v-for='report in reports'
                            :key='report.id'
                            :report='report'
                            :is-trashed='true'
                            @restore='handleRestore'
                        />
                    </div>

                    <div v-if='hasMore' class='grid grid-cols-3 gap-4'>
                        <div v-for='i in 3' :key='`skeleton-trash-${i}`' class='border rounded-lg p-4'>
                            <!-- Header: status icon + name -->
                            <div class='flex items-start gap-2 mb-3'>
                                <Skeleton width='28px' height='28px' rounded='full' />
                                <Skeleton height='24px' class='flex-1' />
                            </div>
                            
                            <!-- Body: guidelines (2 lines) -->
                            <div class='space-y-2 mb-3'>
                                <Skeleton height='40px' />
                                <!-- Date + sources -->
                                <div class='flex gap-2'>
                                    <Skeleton width='120px' height='20px' />
                                    <Skeleton width='100px' height='20px' rounded='full' />
                                </div>
                            </div>
                            
                            <!-- Footer: creator + date -->
                            <div class='pt-3 border-t border-border flex items-center justify-between'>
                                <div class='flex items-center gap-2'>
                                    <Skeleton width='24px' height='24px' rounded='full' />
                                    <Skeleton width='80px' height='16px' />
                                </div>
                                <Skeleton width='70px' height='16px' />
                            </div>
                        </div>
                    </div>

                    <!-- Infinite scroll trigger -->
                    <div v-show='hasMore' ref='triggerElement' class='h-1'></div>
                </div>
            </div>
        </div>

        <!-- Create Report Dialog -->
        <CreateReportDialog
            v-if="form"
            v-model="showCreateDialog"
            :form="form"
            @created="handleReportCreated"
        />

        <!-- View Report Dialog -->
        <ViewReportDialog
            v-if="viewingReport && showViewDialog"
            v-model="showViewDialog"
            :report="viewingReport"
            :content="reportContent"
        />
    </div>
</template>
