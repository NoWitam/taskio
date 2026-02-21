import { defineStore } from 'pinia';
import { ref } from 'vue';
import { api } from '../lib/api';
import type { User, ApiMeta } from '../types';

export interface UsersResponse {
    data: User[];
    meta: ApiMeta;
}

export interface FetchUsersParams {
    cursor?: string | null;
    q?: string;
    per_page?: number;
}

export const useUsersStore = defineStore('users', () => {
    const loading = ref(false);
    const error = ref<string | null>(null);

    const usersById = ref<Record<string, User>>({});

    const fetchUsersByIds = async (ids: Array<string | number>) => {
        const unique = Array.from(new Set((ids || []).map((x) => String(x)).filter(Boolean)));
        if (!unique.length) {
            return { data: [], meta: { next_cursor: null, prev_cursor: null, per_page: unique.length, total: 0 } as any };
        }

        // Jeśli wszystko mamy w cache, nie rób requestu.
        const missing = unique.filter((id) => !usersById.value[id]);
        if (!missing.length) {
            return {
                data: unique.map((id) => usersById.value[id]).filter(Boolean),
                meta: { next_cursor: null, prev_cursor: null, per_page: unique.length, total: unique.length } as any,
            };
        }

        loading.value = true;
        error.value = null;

        try {
            const qs = new URLSearchParams();
            missing.forEach((id) => qs.append('ids[]', id));
            const response: UsersResponse = await api.get(`/users?${qs.toString()}`);

            const normalized: UsersResponse = {
                ...response,
                data: (response.data || []).map((u: any) => ({
                    ...u,
                    avatar: u.avatar ?? u.src ?? null,
                })),
            };

            (normalized.data || []).forEach((u) => {
                const id = (u as any)?.id;
                if (id === undefined || id === null || id === '') return;
                usersById.value[String(id)] = u;
            });

            return normalized;
        } catch (err: any) {
            error.value = err.response?.data?.message || 'Błąd podczas pobierania użytkowników';
            throw err;
        } finally {
            loading.value = false;
        }
    };

    const fetchUsers = async (params: FetchUsersParams = {}) => {
        loading.value = true;
        error.value = null;

        try {
            const qs = new URLSearchParams();

            if (params.cursor) qs.append('cursor', params.cursor);
            if (params.q) qs.append('q', params.q);
            if (params.per_page) qs.append('per_page', String(params.per_page));

            const query = qs.toString();
            const response: UsersResponse = await api.get(`/users${query ? `?${query}` : ''}`);

            // Normalizacja: backend w mocku zwraca `src`, a frontend używa `avatar`
            const normalized: UsersResponse = {
                ...response,
                data: (response.data || []).map((u: any) => ({
                    ...u,
                    avatar: u.avatar ?? u.src ?? null,
                })),
            };

            // Cache by ID for easy lookup (e.g. filter chips)
            (normalized.data || []).forEach((u) => {
                const id = (u as any)?.id;
                if (id === undefined || id === null || id === '') return;
                usersById.value[String(id)] = u;
            });

            return normalized;
        } catch (err: any) {
            error.value = err.response?.data?.message || 'Błąd podczas pobierania użytkowników';
            throw err;
        } finally {
            loading.value = false;
        }
    };

    return {
        loading,
        error,
        usersById,
        fetchUsers,
        fetchUsersByIds,
    };
});
