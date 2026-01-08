import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { api } from '@/lib/api';
import type { User, Label, ApiMeta, ApiResponse } from '@/types';

export interface Task {
    id: string;
    title: string;
    description?: string;
    status: string;
    priority: 'high' | 'medium' | 'low';
    date: string;
    user: User;
    comments: number;
    labels: Label[];
}

export interface TaskFilters {
    status?: string;
    priority?: string;
    search?: string;
    user_id?: number;
    label?: string;
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
                if (value !== undefined && value !== null && value !== '') {
                    params.append(key, value.toString());
                }
            });

            const response: TasksResponse = await api.get(`/tasks?${params.toString()}`);
            
            if (resetCursor) {
                tasksByStatus.value[status] = response.data;
            } else {
                tasksByStatus.value[status] = [...(tasksByStatus.value[status] || []), ...response.data];
            }
            
            cursors.value[status] = response.meta.next_cursor;
            hasMoreByStatus.value[status] = response.meta.next_cursor !== null;
            totalByStatus.value[status] = response.meta.total ?? 0;
            
            return response;
        } catch (err: any) {
            error.value = err.response?.data?.message || 'Błąd podczas pobierania zadań';
            throw err;
        } finally {
            loading.value[status] = false;
        }
    };

    const createTask = async (taskData: Partial<Task>) => {
        error.value = null;

        try {
            const response = await api.post('/tasks', taskData);
            tasks.value.unshift(response);
            return response;
        } catch (err: any) {
            error.value = err.response?.data?.message || 'Błąd podczas tworzenia zadania';
            throw err;
        }
    };

    const updateTask = async (id: number, taskData: Partial<Task>) => {
        error.value = null;

        try {
            const response = await api.put(`/tasks/${id}`, taskData);
            const index = tasks.value.findIndex(t => t.id === id);
            if (index !== -1) {
                tasks.value[index] = response;
            }
            return response;
        } catch (err: any) {
            error.value = err.response?.data?.message || 'Błąd podczas aktualizacji zadania';
            throw err;
        }
    };

    const deleteTask = async (id: number) => {
        error.value = null;

        try {
            await api.delete(`/tasks/${id}`);
            tasks.value = tasks.value.filter(t => t.id !== id);
        } catch (err: any) {
            error.value = err.response?.data?.message || 'Błąd podczas usuwania zadania';
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
        
        // Getters
        getTasksByStatus,
        getTasksCount,
        getLoadingByStatus,
        getHasMoreByStatus,
        getTotalByStatus,
    };
});
