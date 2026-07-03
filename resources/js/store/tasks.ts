import type { JSONContent } from '@tiptap/core';
import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { api } from '@/lib/api';
import type { User, Label, ApiMeta, ApiResponse } from '@/types';
import { useI18n } from '@/composables/useI18n';

export interface TaskAttachment {
    id: string;
    name: string;
    path: string;
    type: string;
    size: number;
    size_human: string;
    created_at: string;
    updated_at: string;
}

export interface TaskAssignee {
    type: 'user' | 'bot';
    id: string;
    name: string;
    email: string | null;
    avatar: string | null;
    is_bot: boolean;
}

export interface Task {
    id: string;
    title: string;
    description?: string | JSONContent | null;
    status: string;
    priority: 'urgent' | 'high' | 'medium' | 'low';
    deadline?: string | null;
    deadline_overdue?: number | null;
    is_overdue?: boolean;
    is_at_risk?: boolean;
    // Polymorphic assignee (User|Bot). Legacy `assigned` stays for the user case and is
    // null when the task is assigned to a bot; `assignee` is the type-aware source.
    assigned: User | null;
    assignee?: TaskAssignee | null;
    creator?: User;
    comments?: number;
    labels: Label[];
    attachments?: TaskAttachment[];
    form_id?: string | null;
    approval_pipeline_id?: string | null;
    is_in_approval?: boolean;
    pending_approval_process?: import('@/types/approvals').ApprovalProcess | null;
    form?: {
        id: string;
        name: string;
        icon: string;
        description: string | null;
        content: any[];
    } | null;
    form_submission?: {
        id: string;
        data: Record<string, any>;
        submitted_at: string;
    } | null;
    created_at?: string;
    updated_at?: string;
}

export interface Comment {
    id: string;
    content: string;
    author: {
        id: string;
        name: string;
        email: string;
    };
    created_at: string;
    updated_at: string;
    is_edited: boolean;
}

export interface ChangelogEntry {
    id: string;
    event: string;
    event_description: string;
    details: Record<string, any>;
    causer: {
        id: string;
        name: string;
        email: string;
    } | null;
    created_at: string;
}

export interface TaskFilters {
    status?: string;
    priority?: string;
    search?: string;
    user_id?: Array<string | number>;
    labels?: string[];
    labelOperator?: 'AND' | 'OR';
    date_from?: string | null;
    date_to?: string | null;
    date_preset?: '' | 'today' | 'this_week' | 'last_week' | 'this_month';
    hide_without_deadline?: boolean | number | '0' | '1';
}

export interface TasksResponse {
    data: Task[];
    meta: ApiMeta;
}

export const useTasksStore = defineStore('tasks', () => {
    const { t } = useI18n();
    const tasks = ref<Task[]>([]);
    const loading = ref<Record<string, boolean>>({});
    const error = ref<string | null>(null);
    const cursors = ref<Record<string, string | null>>({});
    const hasMoreByStatus = ref<Record<string, boolean>>({});
    const totalByStatus = ref<Record<string, number>>({});

    const tasksByStatus = ref<Record<string, Task[]>>({});
    const commentsByTask = ref<Record<string, Comment[]>>({});
    const changelogByTask = ref<Record<string, ChangelogEntry[]>>({});
    const loadingComments = ref<Record<string, boolean>>({});
    const loadingChangelog = ref<Record<string, boolean>>({});
    const commentsCursors = ref<Record<string, string | null>>({});
    const hasMoreComments = ref<Record<string, boolean>>({});

    const fetchTasksByStatus = async (status: string, filters: TaskFilters = {}, resetCursor: boolean = true) => {
        loading.value[status] = true;
        error.value = null;

        if (resetCursor) {
            tasksByStatus.value[status] = [];
            cursors.value[status] = null;
        }

        try {
            const params = new URLSearchParams();
            params.append('status', status);

            if (cursors.value[status] && !resetCursor) {
                params.append('cursor', cursors.value[status]!);
            }

            Object.entries(filters).forEach(([key, value]) => {
                if (value === undefined || value === null || value === '') return;

                if (Array.isArray(value)) {
                    if (!value.length) return;
                    value.forEach(element => {
                        params.append(key+"[]", element);
                    });
                    return;
                }

                params.append(key, value.toString());
            });

            const response: TasksResponse = await api.get(`/tasks?${params.toString()}`);
            
            if (resetCursor) {
                tasksByStatus.value[status] = response.data;
            } else {
                tasksByStatus.value[status] = [...(tasksByStatus.value[status] || []), ...response.data];
            }
            
            cursors.value[status] = response.meta.next_cursor;
            hasMoreByStatus.value[status] = response.meta.next_cursor !== null;
            if(response.meta.hasOwnProperty('total') && response.meta.total !== null) {
                totalByStatus.value[status] = response.meta.total ?? 0;
            }
            
            return response;
        } catch (err: any) {
            error.value = err.response?.data?.message || 'Błąd podczas pobierania zadań';
            throw err;
        } finally {
            loading.value[status] = false;
        }
    };

    const createTask = async (taskData: any) => {
        error.value = null;

        try {
            const response = await api.post('/tasks', taskData);
            tasks.value.unshift(response);

            const created: any = response as any;
            const status = created?.status ?? (taskData && typeof taskData === 'object' ? (taskData as any).status : undefined);
            if (status) {
                const current = tasksByStatus.value[status] || [];
                tasksByStatus.value[status] = [created, ...current];
                if (typeof totalByStatus.value[status] === 'number') {
                    totalByStatus.value[status] = (totalByStatus.value[status] || 0) + 1;
                }
            }
            return response;
        } catch (err: any) {
            error.value = err.response?.data?.message || 'Błąd podczas tworzenia zadania';
            throw err;
        }
    };

    const updateTask = async (id: string | number, taskData: Partial<Task> | FormData) => {
        error.value = null;

        try {
            const idStr = String(id);
            // Użyj POST zamiast PUT gdy taskData to FormData (Laravel method spoofing)
            const isFormData = taskData instanceof FormData;
            const response: ApiResponse<Task> = isFormData 
                ? await api.post(`/tasks/${id}`, taskData)
                : await api.put(`/tasks/${id}`, taskData);
            const updatedTask = response.data;
            
            const index = tasks.value.findIndex(t => t.id === idStr);
            if (index !== -1) {
                tasks.value[index] = updatedTask;
            }
            
            // Zaktualizuj także w tasksByStatus je\u015bli jest tam obecny
            Object.keys(tasksByStatus.value).forEach(status => {
                const statusIndex = tasksByStatus.value[status]?.findIndex(t => t.id === idStr);
                if (statusIndex !== undefined && statusIndex !== -1) {
                    tasksByStatus.value[status][statusIndex] = updatedTask;
                }
            });
            
            return updatedTask;
        } catch (err: any) {
            error.value = err.response?.data?.message || 'Błąd podczas aktualizacji zadania';
            throw err;
        }
    };

    const deleteTask = async (id: string | number) => {
        error.value = null;

        try {
            const idStr = String(id);
            await api.delete(`/tasks/${id}`);
            tasks.value = tasks.value.filter(t => t.id !== idStr);
            
            // Usuń z tasksByStatus
            Object.keys(tasksByStatus.value).forEach(status => {
                tasksByStatus.value[status] = tasksByStatus.value[status]?.filter(t => t.id !== idStr) || [];
                if (typeof totalByStatus.value[status] === 'number') {
                    totalByStatus.value[status] = Math.max(0, (totalByStatus.value[status] || 0) - 1);
                }
            });
        } catch (err: any) {
            error.value = err.response?.data?.message || 'Błąd podczas usuwania zadania';
            throw err;
        }
    };

    const forceDeleteTask = async (id: string | number) => {
        error.value = null;

        try {
            const idStr = String(id);
            await api.delete(`/tasks/${id}/force`);
            tasks.value = tasks.value.filter(t => t.id !== idStr);
            
            // Usuń z tasksByStatus
            Object.keys(tasksByStatus.value).forEach(status => {
                tasksByStatus.value[status] = tasksByStatus.value[status]?.filter(t => t.id !== idStr) || [];
                if (typeof totalByStatus.value[status] === 'number') {
                    totalByStatus.value[status] = Math.max(0, (totalByStatus.value[status] || 0) - 1);
                }
            });
        } catch (err: any) {
            error.value = err.response?.data?.message || 'Błąd podczas permanentnego usuwania zadania';
            throw err;
        }
    };

    const restoreTask = async (id: string | number) => {
        error.value = null;

        try {
            const idStr = String(id);
            const response: ApiResponse<Task> = await api.post(`/tasks/${id}/restore`);
            const restoredTask = response.data;
            
            // Usuń z listy trash
            if (tasksByStatus.value['trash']) {
                tasksByStatus.value['trash'] = tasksByStatus.value['trash'].filter(t => t.id !== idStr);
                if (typeof totalByStatus.value['trash'] === 'number') {
                    totalByStatus.value['trash'] = Math.max(0, (totalByStatus.value['trash'] || 0) - 1);
                }
            }
            
            // Dodaj do to_do statusu
            const status = 'to_do';
            if (!tasksByStatus.value[status]) {
                tasksByStatus.value[status] = [];
            }
            tasksByStatus.value[status].unshift(restoredTask);
            
            if (typeof totalByStatus.value[status] === 'number') {
                totalByStatus.value[status] = (totalByStatus.value[status] || 0) + 1;
            }
            
            return restoredTask;
        } catch (err: any) {
            error.value = err.response?.data?.message || 'Błąd podczas przywracania zadania';
            throw err;
        }
    };

    const changeStatus = async (id: string | number, newStatus: string) => {
        error.value = null;

        try {
            const idStr = String(id);
            const response: ApiResponse<Task> = await api.patch(`/tasks/${id}/status/${newStatus}`);
            const updatedTask = response.data;
            
            // Usuń z poprzedniego statusu
            Object.keys(tasksByStatus.value).forEach(status => {
                tasksByStatus.value[status] = tasksByStatus.value[status]?.filter(t => t.id !== idStr) || [];
            });
            
            // Dodaj do nowego statusu
            if (!tasksByStatus.value[newStatus]) {
                tasksByStatus.value[newStatus] = [];
            }
            tasksByStatus.value[newStatus].unshift(updatedTask);
            
            // Zaktualizuj w tasks
            const index = tasks.value.findIndex(t => t.id === idStr);
            if (index !== -1) {
                tasks.value[index] = updatedTask;
            }
            
            return updatedTask;
        } catch (err: any) {
            error.value = err.response?.data?.message || 'Błąd podczas zmiany statusu zadania';
            throw err;
        }
    };

    const fetchComments = async (taskId: string | number, resetCursor: boolean = true) => {
        const taskIdStr = String(taskId);
        loadingComments.value[taskIdStr] = true;
        error.value = null;

        if (resetCursor) {
            commentsByTask.value[taskIdStr] = [];
            commentsCursors.value[taskIdStr] = null;
        }

        try {
            const params = new URLSearchParams();
            if (commentsCursors.value[taskIdStr] && !resetCursor) {
                params.append('cursor', commentsCursors.value[taskIdStr]!);
            }

            const url = `/tasks/${taskId}/comments${params.toString() ? '?' + params.toString() : ''}`;
            const response: ApiResponse<Comment[]> = await api.get(url);
            
            if (resetCursor) {
                commentsByTask.value[taskIdStr] = response.data;
            } else {
                commentsByTask.value[taskIdStr] = [...(commentsByTask.value[taskIdStr] || []), ...response.data];
            }
            
            commentsCursors.value[taskIdStr] = response.meta?.next_cursor ?? null;
            hasMoreComments.value[taskIdStr] = response.meta?.next_cursor !== null;
            
            return response.data;
        } catch (err: any) {
            error.value = err.response?.data?.message || 'Błąd podczas pobierania komentarzy';
            throw err;
        } finally {
            loadingComments.value[taskIdStr] = false;
        }
    };

    const loadMoreComments = async (taskId: string | number) => {
        return fetchComments(taskId, false);
    };

    const addComment = async (taskId: string | number, content: string) => {
        const taskIdStr = String(taskId);
        error.value = null;

        try {
            const response: ApiResponse<Comment> = await api.post(`/tasks/${taskId}/comments`, { content });
            
            if (!commentsByTask.value[taskIdStr]) {
                commentsByTask.value[taskIdStr] = [];
            }
            commentsByTask.value[taskIdStr].unshift(response.data);
            
            // Zwiększ licznik komentarzy w zadaniu
            Object.keys(tasksByStatus.value).forEach(status => {
                const task = tasksByStatus.value[status]?.find(t => t.id === taskIdStr);
                if (task && typeof task.comments === 'number') {
                    task.comments += 1;
                }
            });
            
            return response.data;
        } catch (err: any) {
            error.value = err.response?.data?.message || 'Błąd podczas dodawania komentarza';
            throw err;
        }
    };

    const updateComment = async (commentId: string, content: string) => {
        error.value = null;

        try {
            const response: ApiResponse<Comment> = await api.patch(`/comments/${commentId}`, { content });
            
            // Zaktualizuj w commentsByTask
            Object.keys(commentsByTask.value).forEach(taskId => {
                const index = commentsByTask.value[taskId]?.findIndex(c => c.id === commentId);
                if (index !== undefined && index !== -1) {
                    commentsByTask.value[taskId][index] = response.data;
                }
            });
            
            return response.data;
        } catch (err: any) {
            error.value = err.response?.data?.message || 'Błąd podczas edycji komentarza';
            throw err;
        }
    };

    const deleteComment = async (commentId: string, taskId: string | number) => {
        const taskIdStr = String(taskId);
        error.value = null;

        try {
            await api.delete(`/comments/${commentId}`);
            
            if (commentsByTask.value[taskIdStr]) {
                commentsByTask.value[taskIdStr] = commentsByTask.value[taskIdStr].filter(c => c.id !== commentId);
            }
            
            // Zmniejsz licznik komentarzy w zadaniu
            Object.keys(tasksByStatus.value).forEach(status => {
                const task = tasksByStatus.value[status]?.find(t => t.id === taskIdStr);
                if (task && typeof task.comments === 'number') {
                    task.comments = Math.max(0, task.comments - 1);
                }
            });
        } catch (err: any) {
            error.value = err.response?.data?.message || 'Błąd podczas usuwania komentarza';
            throw err;
        }
    };

    const fetchChangelog = async (taskId: string | number) => {
        const taskIdStr = String(taskId);
        loadingChangelog.value[taskIdStr] = true;
        error.value = null;

        try {
            const response: ApiResponse<ChangelogEntry[]> = await api.get(`/task/${taskId}/changelog`);
            changelogByTask.value[taskIdStr] = response.data;
            return response.data;
        } catch (err: any) {
            error.value = err.response?.data?.message || 'Błąd podczas pobierania changelogu';
            throw err;
        } finally {
            loadingChangelog.value[taskIdStr] = false;
        }
    };

    const fetchTask = async (id: string | number) => {
        error.value = null;

        try {
            const response: ApiResponse<Task> = await api.get(`/tasks/${id}`);
            return response.data;
        } catch (err: any) {
            error.value = err.response?.data?.message || t('errors.taskSingleFetch');
            throw err;
        }
    };

    const submitTaskForm = async (taskId: string | number, formData: Record<string, any>) => {
        error.value = null;

        try {
            const response: ApiResponse<Task> = await api.post(`/tasks/${taskId}/form-submission`, { data: formData });
            const updatedTask = response.data;
            
            // Update task in cache
            const taskIdStr = String(taskId);
            const index = tasks.value.findIndex(t => t.id === taskIdStr);
            if (index !== -1) {
                tasks.value[index] = updatedTask;
            }
            
            // Update in tasksByStatus
            Object.keys(tasksByStatus.value).forEach(status => {
                const statusIndex = tasksByStatus.value[status]?.findIndex(t => t.id === taskIdStr);
                if (statusIndex !== undefined && statusIndex !== -1) {
                    tasksByStatus.value[status][statusIndex] = updatedTask;
                }
            });
            
            return updatedTask;
        } catch (err: any) {
            error.value = err.response?.data?.message || 'Błąd podczas zapisywania formularza';
            throw err;
        }
    };

    const getTasksByStatus = (status: string) => {
        return computed(() => tasksByStatus.value[status] || []);
    };

    const getTasksCount = (status?: string) => {
        if (status) {
            return computed(() => (tasksByStatus.value[status] || []).length);
        }
        return computed(() => tasks.value.length);
    };

    const getLoadingByStatus = (status: string) => {
        return computed(() => loading.value[status] || false);
    };

    const getHasMoreByStatus = (status: string) => {
        return computed(() => hasMoreByStatus.value[status] ?? true);
    };

    const getTotalByStatus = (status: string) => {
        return computed(() => totalByStatus.value[status] ?? null);
    };

    return {
        // State
        tasks,
        tasksByStatus,
        loading,
        error,
        cursors,
        hasMoreByStatus,
        totalByStatus,
        commentsByTask,
        changelogByTask,
        loadingComments,
        loadingChangelog,
        hasMoreComments,
        
        // Actions
        fetchTasksByStatus,
        createTask,
        updateTask,
        deleteTask,
        forceDeleteTask,
        restoreTask,
        changeStatus,
        fetchTask,
        submitTaskForm,
        fetchComments,
        loadMoreComments,
        addComment,
        updateComment,
        deleteComment,
        fetchChangelog,
        
        // Getters
        getTasksByStatus,
        getTasksCount,
        getLoadingByStatus,
        getHasMoreByStatus,
        getTotalByStatus,
    };
});
