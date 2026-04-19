import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { api } from '@/lib/api'
import type { ApiMeta, ApiResponse } from '@/types'
import type { Form, FormSubmission, FormReport } from '@/types/forms'

export interface FormFilters {
    search?: string
    is_anonymous?: boolean
    trashed?: boolean
    enabled?: boolean
    indexed?: boolean
    date_from?: string | null
    date_to?: string | null
    date_preset?: '' | 'today' | 'this_week' | 'last_week' | 'this_month'
}

export interface CompatibilityInfo {
    total_submissions: number
    compatible_count: number
    incompatible_count: number
    incompatible_periods: {
        version: number
        submissions_count: number
        period_from: string
        period_to: string
    }[]
}

export interface SubmissionFilters {
    search?: string
    sources?: string[] // Array of 'task' or 'form'
    indexed?: 'true' | 'false' | null
    trashed?: boolean
    date_from?: string | null
    date_to?: string | null
    sort?: 'newest' | 'oldest'
}

export interface ReportFilters {
    search?: string
    creator_id?: string[]
    trashed?: boolean
    date_from?: string | null
    date_to?: string | null
    sort?: 'newest' | 'oldest'
    only_completed?: boolean
    only_pending?: boolean
}

export interface FormReportsResponse {
    data: FormReport[]
    meta: ApiMeta
}

export interface FormsResponse {
    data: Form[]
    meta: ApiMeta
}

export interface FormSubmissionsResponse {
    data: FormSubmission[]
    meta: ApiMeta
}

export const useFormsStore = defineStore('forms', () => {
    const forms = ref<Form[]>([])
    const formById = ref<Record<string, Form>>({})
    const submissions = ref<Record<string, FormSubmission[]>>({}) // by form_id
    const reports = ref<Record<string, FormReport[]>>({}) // by form_id
    const loading = ref<Record<string, boolean>>({})
    const cursors = ref<Record<string, string | null>>({})
    const hasMore = ref<Record<string, boolean>>({})
    const total = ref<number | null>(null)
    const abortControllers = ref<Record<string, AbortController | null>>({}) // for aborting fetch reports

    // ============================================
    // COMPUTED
    // ============================================

    const publicForms = computed(() => forms.value.filter(f => !f.is_anonymous))
    const anonymousForms = computed(() => forms.value.filter(f => f.is_anonymous))
    const enabledForms = computed(() => forms.value.filter(f => f.is_enabled))
    const disabledForms = computed(() => forms.value.filter(f => !f.is_enabled))
    const indexedForms = computed(() => forms.value.filter(f => f.is_indexed))

    // ============================================
    // FORMS CRUD
    // ============================================

    const fetchForms = async (filters: FormFilters = {}, resetCursor: boolean = true) => {
        const key = 'list'
        loading.value[key] = true

        if (resetCursor) {
            forms.value = []
            cursors.value[key] = null
        }

        try {
            const params = new URLSearchParams()

            if (cursors.value[key] && !resetCursor) {
                params.append('cursor', cursors.value[key]!)
            }

            Object.entries(filters).forEach(([key, value]) => {
                if (value === undefined || value === null || value === '') return
                params.append(key, String(value))
            })

            const response = await api.get<FormsResponse>(`/forms?${params.toString()}`)
            
            if (resetCursor) {
                forms.value = response.data
            } else {
                forms.value.push(...response.data)
            }

            // Update cache
            response.data.forEach(form => {
                formById.value[form.id] = form
            })

            cursors.value[key] = response.meta.next_cursor
            hasMore.value[key] = !!response.meta.next_cursor
            
            if (response.meta.total !== undefined) {
                total.value = response.meta.total
            }
        } catch (err: any) {
            console.error('Failed to fetch forms:', err)
            throw err
        } finally {
            loading.value[key] = false
        }
    }

    const fetchForm = async (id: string): Promise<Form> => {
        loading.value[id] = true

        try {
            const response = await api.get<ApiResponse<Form>>(`/forms/${id}`)
            const form = response.data

            formById.value[id] = form

            // Update in list if exists
            const index = forms.value.findIndex(f => f.id === id)
            if (index !== -1) {
                forms.value[index] = form
            }

            return form
        } catch (err: any) {
            console.error('Failed to fetch form:', err)
            throw err
        } finally {
            loading.value[id] = false
        }
    }

    const createForm = async (data: {
        name: string
        icon?: string | null
        description?: string | null
        content: any[]
        is_anonymous?: boolean
    }): Promise<Form> => {
        try {
            const response = await api.post<ApiResponse<Form>>('/forms', data)
            const form = response.data

            forms.value.unshift(form)
            formById.value[form.id] = form
            
            if (total.value !== null) {
                total.value++
            }

            return form
        } catch (err: any) {
            console.error('Failed to create form:', err)
            throw err
        }
    }

    const updateForm = async (id: string, data: {
        name: string
        icon?: string | null
        description?: string | null
        content: any[]
        is_anonymous?: boolean
    }): Promise<Form> => {
        try {
            const response = await api.put<ApiResponse<Form>>(`/forms/${id}`, data)
            const form = response.data

            // Update cache
            formById.value[id] = form

            // Update in list
            const index = forms.value.findIndex(f => f.id === id)
            if (index !== -1) {
                forms.value[index] = form
            }

            return form
        } catch (err: any) {
            console.error('Failed to update form:', err)
            throw err
        }
    }

    const deleteForm = async (id: string): Promise<void> => {
        try {
            await api.delete(`/forms/${id}`)

            // Remove from list
            forms.value = forms.value.filter(f => f.id !== id)
            delete formById.value[id]

            if (total.value !== null) {
                total.value--
            }
        } catch (err: any) {
            console.error('Failed to delete form:', err)
            throw err
        }
    }

    const forceDeleteForm = async (id: string): Promise<void> => {
        try {
            await api.delete(`/forms/${id}/force`)

            forms.value = forms.value.filter(f => f.id !== id)
            delete formById.value[id]

            if (total.value !== null) {
                total.value--
            }
        } catch (err: any) {
            console.error('Failed to permanently delete form:', err)
            throw err
        }
    }

    const restoreForm = async (id: string): Promise<Form> => {
        try {
            const response = await api.post<ApiResponse<Form>>(`/forms/${id}/restore`)
            const form = response.data

            forms.value.unshift(form)
            formById.value[form.id] = form

            if (total.value !== null) {
                total.value++
            }

            return form
        } catch (err: any) {
            console.error('Failed to restore form:', err)
            throw err
        }
    }

    const enableForm = async (id: string): Promise<Form> => {
        try {
            const response = await api.post<ApiResponse<Form>>(`/forms/${id}/enable`)
            const form = response.data

            // Update cache
            formById.value[id] = form

            // Update in list
            const index = forms.value.findIndex(f => f.id === id)
            if (index !== -1) {
                forms.value[index] = form
            }

            return form
        } catch (err: any) {
            console.error('Failed to enable form:', err)
            throw err
        }
    }

    const disableForm = async (id: string): Promise<Form> => {
        try {
            const response = await api.post<ApiResponse<Form>>(`/forms/${id}/disable`)
            const form = response.data

            formById.value[id] = form

            const index = forms.value.findIndex(f => f.id === id)
            if (index !== -1) {
                forms.value[index] = form
            }

            return form
        } catch (err: any) {
            console.error('Failed to disable form:', err)
            throw err
        }
    }

    const indexForm = async (id: string): Promise<Form> => {
        try {
            const response = await api.post<ApiResponse<Form>>(`/forms/${id}/index`)
            const form = response.data

            formById.value[id] = form

            const index = forms.value.findIndex(f => f.id === id)
            if (index !== -1) {
                forms.value[index] = form
            }

            return form
        } catch (err: any) {
            console.error('Failed to index form:', err)
            throw err
        }
    }

    const unindexForm = async (id: string, backupIndexes: boolean = false): Promise<Form> => {
        try {
            const response = await api.post<ApiResponse<Form>>(`/forms/${id}/unindex`, {
                backup_indexes: backupIndexes,
            })
            const form = response.data

            formById.value[id] = form

            const index = forms.value.findIndex(f => f.id === id)
            if (index !== -1) {
                forms.value[index] = form
            }

            return form
        } catch (err: any) {
            console.error('Failed to unindex form:', err)
            throw err
        }
    }

    const fetchCompatibilityInfo = async (id: string): Promise<CompatibilityInfo> => {
        try {
            const response = await api.get<CompatibilityInfo>(`/forms/${id}/compatibility`)
            return response
        } catch (err: any) {
            console.error('Failed to fetch compatibility info:', err)
            throw err
        }
    }

    const restoreIndex = async (id: string): Promise<Form> => {
        try {
            const response = await api.post<ApiResponse<Form>>(`/forms/${id}/restore-index`)
            const form = response.data

            formById.value[id] = form

            const index = forms.value.findIndex(f => f.id === id)
            if (index !== -1) {
                forms.value[index] = form
            }

            return form
        } catch (err: any) {
            console.error('Failed to restore index:', err)
            throw err
        }
    }

    // ============================================
    // FORM SUBMISSIONS
    // ============================================

    const fetchSubmissions = async (formId: string, filters: SubmissionFilters = {}, resetCursor: boolean = true) => {
        const key = `submissions_${formId}`
        loading.value[key] = true

        if (resetCursor) {
            submissions.value[formId] = []
            cursors.value[key] = null
        }

        try {
            const params = new URLSearchParams()

            if (cursors.value[key] && !resetCursor) {
                params.append('cursor', cursors.value[key]!)
            }

            // Add filters to URL params
            Object.entries(filters).forEach(([key, value]) => {
                if (value === undefined || value === null || value === '') return
                if (key === 'sources' && Array.isArray(value)) {
                    // Send as array parameters: sources[]=form&sources[]=task
                    value.forEach(source => {
                        params.append('sources[]', source)
                    })
                } else if (key === 'trashed') {
                    params.append('trashed', value ? 'true' : 'false')
                } else {
                    params.append(key, String(value))
                }
            })

            const response = await api.get<FormSubmissionsResponse>(
                `/forms/${formId}/submissions?${params.toString()}`
            )

            if (resetCursor) {
                submissions.value[formId] = response.data
            } else {
                if (!submissions.value[formId]) {
                    submissions.value[formId] = []
                }
                submissions.value[formId].push(...response.data)
            }

            cursors.value[key] = response.meta.next_cursor
            hasMore.value[key] = !!response.meta.next_cursor
        } catch (err: any) {
            console.error('Failed to fetch submissions:', err)
            throw err
        } finally {
            loading.value[key] = false
        }
    }

    const createSubmission = async (data: {
        form_id: string
        submittable_type: string
        submittable_id: string
        data: Record<string, any>
    }): Promise<FormSubmission> => {
        try {
            const response = await api.post<ApiResponse<FormSubmission>>('/form-submissions', data)
            const submission = response.data

            // Add to submissions list if exists
            if (submissions.value[data.form_id]) {
                submissions.value[data.form_id].unshift(submission)
            }

            return submission
        } catch (err: any) {
            console.error('Failed to create submission:', err)
            throw err
        }
    }

    const updateSubmission = async (id: string, data: {
        data: Record<string, any>
    }): Promise<FormSubmission> => {
        try {
            const response = await api.put<ApiResponse<FormSubmission>>(`/form-submissions/${id}`, data)
            const submission = response.data

            // Update in submissions list if exists
            Object.keys(submissions.value).forEach(formId => {
                const index = submissions.value[formId]?.findIndex(s => s.id === id)
                if (index !== undefined && index !== -1) {
                    submissions.value[formId][index] = submission
                }
            })

            return submission
        } catch (err: any) {
            console.error('Failed to update submission:', err)
            throw err
        }
    }

    const deleteSubmission = async (id: string, formId: string): Promise<void> => {
        try {
            await api.delete(`/form-submissions/${id}`)

            // Remove from submissions list
            if (submissions.value[formId]) {
                submissions.value[formId] = submissions.value[formId].filter(s => s.id !== id)
            }
        } catch (err: any) {
            console.error('Failed to delete submission:', err)
            throw err
        }
    }

    const forceDeleteSubmission = async (id: string, formId: string): Promise<void> => {
        try {
            await api.delete(`/form-submissions/${id}/force`)

            // Remove from submissions list
            if (submissions.value[formId]) {
                submissions.value[formId] = submissions.value[formId].filter(s => s.id !== id)
            }
        } catch (err: any) {
            console.error('Failed to permanently delete submission:', err)
            throw err
        }
    }

    const restoreSubmission = async (id: string, formId: string): Promise<FormSubmission> => {
        try {
            const response = await api.post<ApiResponse<FormSubmission>>(`/form-submissions/${id}/restore`)
            const submission = response.data

            // Add to submissions list if exists
            if (submissions.value[formId]) {
                submissions.value[formId].unshift(submission)
            }

            return submission
        } catch (err: any) {
            console.error('Failed to restore submission:', err)
            throw err
        }
    }

    // ============================================
    // FORM REPORTS
    // ============================================

    const fetchReports = async (formId: string, filters: ReportFilters = {}, resetCursor: boolean = true) => {
        const key = `reports_${formId}`
        
        // Cancel previous request if resetting cursor (new filters)
        if (resetCursor && abortControllers.value[key]) {
            abortControllers.value[key]!.abort()
        }
        
        // Create new abort controller
        const abortController = new AbortController()
        abortControllers.value[key] = abortController
        
        loading.value[key] = true

        if (resetCursor) {
            reports.value[formId] = []
            cursors.value[key] = null
        }

        try {
            const params = new URLSearchParams()

            if (cursors.value[key] && !resetCursor) {
                params.append('cursor', cursors.value[key]!)
            }

            // Add filters to URL params
            Object.entries(filters).forEach(([key, value]) => {
                if (value === undefined || value === null) return
                
                if (Array.isArray(value)) {
                    // Handle arrays (e.g., creator_id)
                    value.forEach(item => {
                        if (item !== undefined && item !== null && item !== '') {
                            params.append(`${key}[]`, String(item))
                        }
                    })
                } else if (key === 'trashed' || key === 'only_completed' || key === 'only_pending') {
                    params.append(key, value ? 'true' : 'false')
                } else if (value !== '') {
                    params.append(key, String(value))
                }
            })

            const response = await api.get<FormReportsResponse>(
                `/forms/${formId}/reports?${params.toString()}`,
                { signal: abortController.signal }
            )

            if (resetCursor) {
                reports.value[formId] = response.data
            } else {
                if (!reports.value[formId]) {
                    reports.value[formId] = []
                }
                reports.value[formId].push(...response.data)
            }

            cursors.value[key] = response.meta.next_cursor
            hasMore.value[key] = !!response.meta.next_cursor
        } catch (err: any) {
            // Ignore aborted requests
            if (err.name === 'AbortError' || err.name === 'CanceledError' || err.code === 'ERR_CANCELED') {
                return
            }
            console.error('Failed to fetch reports:', err)
            throw err
        } finally {
            loading.value[key] = false
            // Clear abort controller if it's still the current one
            if (abortControllers.value[key] === abortController) {
                abortControllers.value[key] = null
            }
        }
    }

    const fetchReport = async (id: string): Promise<FormReport> => {
        try {
            const response = await api.get<ApiResponse<FormReport>>(`/form-reports/${id}`)
            return response.data
        } catch (err: any) {
            console.error('Failed to fetch report:', err)
            throw err
        }
    }

    const createReport = async (data: {
        form_id: string
        name: string
        guidelines?: string | null
        sources?: string[]
        submissions_from?: string | null
        submissions_to?: string | null
    }): Promise<FormReport> => {
        try {
            const response = await api.post<ApiResponse<FormReport>>('/form-reports', data)
            const report = response.data

            // Add to reports list if exists
            if (reports.value[data.form_id]) {
                reports.value[data.form_id].unshift(report)
            }

            return report
        } catch (err: any) {
            console.error('Failed to create report:', err)
            throw err
        }
    }

    const deleteReport = async (id: string, formId: string): Promise<void> => {
        try {
            await api.delete(`/form-reports/${id}`)

            // Remove from reports list
            if (reports.value[formId]) {
                reports.value[formId] = reports.value[formId].filter(r => r.id !== id)
            }
        } catch (err: any) {
            console.error('Failed to delete report:', err)
            throw err
        }
    }

    const restoreReport = async (id: string, formId: string): Promise<FormReport> => {
        try {
            const response = await api.post<ApiResponse<FormReport>>(`/form-reports/${id}/restore`)
            const report = response.data

            // Add to reports list if exists
            if (reports.value[formId]) {
                reports.value[formId].unshift(report)
            }

            return report
        } catch (err: any) {
            console.error('Failed to restore report:', err)
            throw err
        }
    }

    // ============================================
    // HELPERS
    // ============================================

    const getFormById = (id: string): Form | undefined => {
        return formById.value[id]
    }

    const clearCache = () => {
        forms.value = []
        formById.value = {}
        submissions.value = {}
        reports.value = {}
        cursors.value = {}
        hasMore.value = {}
        total.value = null
    }

    return {
        // State
        forms,
        formById,
        submissions,
        reports,
        loading,
        cursors,
        hasMore,
        total,

        // Computed
        publicForms,
        anonymousForms,
        enabledForms,
        disabledForms,
        indexedForms,

        // Actions
        fetchForms,
        fetchForm,
        createForm,
        updateForm,
        deleteForm,
        forceDeleteForm,
        restoreForm,
        enableForm,
        disableForm,
        indexForm,
        unindexForm,
        fetchCompatibilityInfo,
        restoreIndex,
        fetchSubmissions,
        createSubmission,
        updateSubmission,
        deleteSubmission,
        forceDeleteSubmission,
        restoreSubmission,
        fetchReports,
        fetchReport,
        createReport,
        deleteReport,
        restoreReport,

        // Helpers
        getFormById,
        clearCache,
    }
})
