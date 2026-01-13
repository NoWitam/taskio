import { defineStore } from 'pinia';
import { ref } from 'vue';
import { api } from '../lib/api';
import type { Label, ApiMeta } from '../types';

export interface LabelsResponse {
    data: Label[];
    meta: ApiMeta;
}

export interface FetchLabelsParams {
    cursor?: string | null;
    q?: string;
    per_page?: number;
}

export const useLabelsStore = defineStore('labels', () => {
    const loading = ref(false);
    const error = ref<string | null>(null);

    const labelsById = ref<Record<string, Label>>({});
    const createdLabels = ref<Label[]>([]);
    const labelsVersion = ref(0);

    const createLabel = async (payload: { text: string; color?: string | null; icon?: string | null }) => {
        error.value = null;

        const text = String(payload?.text ?? '').trim();
        if (!text) {
            error.value = 'Nazwa etykiety jest wymagana';
            throw new Error(error.value);
        }

        try {
            const created: Label = await api.post('/labels', {
                text,
                color: payload.color ?? null,
                icon: payload.icon ?? null,
            });

            const id = (created as any)?.id;
            if (id !== undefined && id !== null && id !== '') {
                labelsById.value[String(id)] = created;
            }

            // Keep client-side list so UI can immediately resolve selected IDs
            createdLabels.value = [created, ...createdLabels.value.filter((l) => String((l as any)?.id) !== String(id))];
            labelsVersion.value++;
            return created;
        } catch (err: any) {
            // If backend is not available (e.g. mock API), fall back to local creation.
            if (err?.response?.status === 404 || err?.response?.status === 405) {
                const local: Label = {
                    id: String((globalThis as any)?.crypto?.randomUUID?.() ?? Math.random().toString(16).slice(2)),
                    text,
                    color: payload.color ?? undefined,
                    icon: payload.icon ?? null,
                };
                labelsById.value[String(local.id)] = local;
                createdLabels.value = [local, ...createdLabels.value];
                labelsVersion.value++;
                return local;
            }

            error.value = err.response?.data?.message || 'Błąd podczas tworzenia etykiety';
            throw err;
        }
    };

    const fetchLabels = async (params: FetchLabelsParams = {}) => {
        loading.value = true;
        error.value = null;

        try {
            const qs = new URLSearchParams();

            if (params.cursor) qs.append('cursor', params.cursor);
            if (params.q) qs.append('q', params.q);
            if (params.per_page) qs.append('per_page', String(params.per_page));

            const query = qs.toString();
            const response: LabelsResponse = await api.get(`/labels${query ? `?${query}` : ''}`);

            // Inject client-created labels at the top of the first page so selects can resolve them.
            if (!params.cursor && createdLabels.value.length) {
                const q = (params.q || '').trim().toLowerCase();
                const locals = q
                    ? createdLabels.value.filter((l) => String(l.text).toLowerCase().includes(q))
                    : createdLabels.value;

                const seen = new Set<string>();
                const merged: Label[] = [];
                for (const l of [...locals, ...(response.data || [])]) {
                    const id = String((l as any)?.id ?? '');
                    if (!id) continue;
                    if (seen.has(id)) continue;
                    seen.add(id);
                    merged.push(l);
                }
                response.data = merged;
            }

            // Cache by ID for easy lookup (e.g. filter chips)
            (response.data || []).forEach((l) => {
                const id = (l as any)?.id;
                if (id === undefined || id === null || id === '') return;
                labelsById.value[String(id)] = l;
            });

            return response;
        } catch (err: any) {
            error.value = err.response?.data?.message || 'Błąd podczas pobierania etykiet';
            throw err;
        } finally {
            loading.value = false;
        }
    };

    const fetchLabelsByIds = async (ids: Array<string | number>) => {
        const unique = Array.from(new Set((ids || []).map((x) => String(x)).filter(Boolean)));
        if (!unique.length) {
            return { data: [], meta: { next_cursor: null, prev_cursor: null, per_page: unique.length, total: 0 } as any };
        }

        const missing = unique.filter((id) => !labelsById.value[id]);
        if (!missing.length) {
            return {
                data: unique.map((id) => labelsById.value[id]).filter(Boolean),
                meta: { next_cursor: null, prev_cursor: null, per_page: unique.length, total: unique.length } as any,
            };
        }

        loading.value = true;
        error.value = null;

        try {
            const qs = new URLSearchParams();
            missing.forEach((id) => qs.append('ids[]', id));
            const response: LabelsResponse = await api.get(`/labels?${qs.toString()}`);

            (response.data || []).forEach((l) => {
                const id = (l as any)?.id;
                if (id === undefined || id === null || id === '') return;
                labelsById.value[String(id)] = l;
            });

            return response;
        } catch (err: any) {
            error.value = err.response?.data?.message || 'Błąd podczas pobierania etykiet';
            throw err;
        } finally {
            loading.value = false;
        }
    };

    return {
        loading,
        error,
        labelsById,
        createdLabels,
        labelsVersion,
        fetchLabels,
        fetchLabelsByIds,
        createLabel,
    };
});
