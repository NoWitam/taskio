import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { api } from '@/lib/api'
import type { ApiMeta, ApiResponse } from '@/types'
import type { Form, FormSubmission } from '@/types/forms'

export interface FormFilters {
    search?: string
    is_anonymous?: boolean
    archived?: boolean
    date_from?: string | null
    date_to?: string | null
    date_preset?: '' | 'today' | 'this_week' | 'last_week' | 'this_month'
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
    const loading = ref<Record<string, boolean>>({})
    const cursors = ref<Record<string, string | null>>({})
    const hasMore = ref<Record<string, boolean>>({})
    const total = ref<number | null>(null)

    // ============================================
    // COMPUTED
    // ============================================

    const publicForms = computed(() => forms.value.filter(f => !f.is_anonymous))
    const anonymousForms = computed(() => forms.value.filter(f => f.is_anonymous))

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

    // ============================================
    // FORM SUBMISSIONS
    // ============================================

    const fetchSubmissions = async (formId: string, resetCursor: boolean = true) => {
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
        cursors.value = {}
        hasMore.value = {}
        total.value = null
    }

    return {
        // State
        forms,
        formById,
        submissions,
        loading,
        cursors,
        hasMore,
        total,

        // Computed
        publicForms,
        anonymousForms,

        // Actions
        fetchForms,
        fetchForm,
        createForm,
        updateForm,
        deleteForm,
        forceDeleteForm,
        restoreForm,
        fetchSubmissions,
        createSubmission,
        updateSubmission,

        // Helpers
        getFormById,
        clearCache,
    }
})
