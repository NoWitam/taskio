import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { api } from '@/lib/api';
import type { User, Label, ApiMeta, ApiResponse } from '@/types';

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

export interface Task {
    id: string;
    title: string;
    description?: string;
    status: string;
    priority: 'urgent' | 'high' | 'medium' | 'low';
    deadline?: string | null;
    deadline_overdue?: number | null;
    is_overdue?: boolean;
    is_at_risk?: boolean;
    assigned: User;
    creator?: User;
    comments?: number;
    labels: Label[];
    attachments?: TaskAttachment[];
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
    const tasks = ref<Task[]>([]);
    const loading = ref<Record<string, boolean>>({});
    const error = ref<string | null>(null);
    const cursors = ref<Record<string, string | null>>({});
    const hasMoreByStatus = ref<Record<string, boolean>>({});
    const totalByStatus = ref<Record<string, number>>({});

    const tasksByStatus = ref<Record<string, Task[]>>({});

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
        } catch (err: any) {
            error.value = err.response?.data?.message || 'Błąd podczas usuwania zadania';
            throw err;
        }
    };

    const fetchTask = async (id: string | number) => {
        error.value = null;

        try {
            const response: ApiResponse<Task> = await api.get(`/tasks/${id}`);
            return response.data;
        } catch (err: any) {
            error.value = err.response?.data?.message || 'Błąd podczas pobierania zadania';
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
        
        // Actions
        fetchTasksByStatus,
        createTask,
        updateTask,
        deleteTask,
        fetchTask,
        
        // Getters
        getTasksByStatus,
        getTasksCount,
        getLoadingByStatus,
        getHasMoreByStatus,
        getTotalByStatus,
    };
});
