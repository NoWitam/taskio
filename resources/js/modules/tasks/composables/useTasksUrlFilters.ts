import { computed, ref } from 'vue';
import type { LocationQueryRaw } from 'vue-router';
import { asBool, asString, asStringArray } from '@/lib/queryParams';
import { useRouteQueryHydration } from '@/composables/useRouteQueryHydration';
import { useUsersStore } from '@/store/users';
import { useLabelsStore } from '@/store/labels';

type DatePreset = '' | 'today' | 'this_week' | 'last_week' | 'this_month';
type LabelOperator = 'AND' | 'OR';

export type TasksUrlFiltersState = {
  search: string;
  priority: string;
  labels: string[];
  label_operator: LabelOperator;
  user_id: Array<string | number>;
  dateRange: {
    preset: DatePreset;
    from: string | null;
    to: string | null;
    hide_without_deadline: boolean;
  };
};

function normalizeIdArray(v: unknown): string[] {
  if (Array.isArray(v)) return v.map((x) => String(x)).filter(Boolean);
  if (typeof v === 'string') return v.trim() ? [v] : [];
  if (v === undefined || v === null) return [];
  const s = String(v);
  return s.trim() ? [s] : [];
}

function stringArrayEqual(a: unknown, b: unknown): boolean {
  const aa = normalizeIdArray(a);
  const bb = normalizeIdArray(b);
  if (aa.length !== bb.length) return false;
  for (let i = 0; i < aa.length; i++) {
    if (aa[i] !== bb[i]) return false;
  }
  return true;
}

export function useTasksUrlFilters() {
  const usersStore = useUsersStore();
  const labelsStore = useLabelsStore();

  const filters = ref<TasksUrlFiltersState>({
    search: '',
    priority: '',
    labels: [],
    label_operator: 'OR',
    user_id: [],
    dateRange: {
      preset: '',
      from: null,
      to: null,
      hide_without_deadline: false,
    },
  });

  async function prefetchFromFilters() {
    const userIds = Array.isArray(filters.value.user_id)
      ? (filters.value.user_id as Array<string | number>).map(String).filter(Boolean)
      : [];
    const labelIds = Array.isArray(filters.value.labels) ? filters.value.labels.map(String).filter(Boolean) : [];

    await Promise.all([
      userIds.length ? usersStore.fetchUsersByIds(userIds) : Promise.resolve(null),
      labelIds.length ? labelsStore.fetchLabelsByIds(labelIds) : Promise.resolve(null),
    ]);
  }

  function applyRouteQueryToFilters(query: Record<string, any>) {
    const q = (asString(query.q) || '').trim();
    const priority = (asString(query.priority) || '').trim();
    // URL param: users[]=... (backward compatible with legacy user_id)
    const userIds = asStringArray(query.users).length ? asStringArray(query.users) : asStringArray(query.user_id);
    const labels = asStringArray(query.labels);

    const operatorRaw = (asString(query.label_operator) || '').toUpperCase();
    const operator: LabelOperator = operatorRaw === 'AND' ? 'AND' : 'OR';

    const presetRaw = (asString(query.date_preset) || '').trim();
    const presetAllowed = ['today', 'this_week', 'last_week', 'this_month'] as const;
    const preset: DatePreset = (presetAllowed as readonly string[]).includes(presetRaw) ? (presetRaw as any) : '';

    const from = (asString(query.date_from) || '').trim();
    const to = (asString(query.date_to) || '').trim();
    const hideWithoutDeadline = asBool(query.hide_without_deadline);

    if (filters.value.search !== q) filters.value.search = q;
    if (filters.value.priority !== priority) filters.value.priority = priority;

    if (!stringArrayEqual(filters.value.user_id, userIds)) filters.value.user_id = userIds as any;
    if (!stringArrayEqual(filters.value.labels, labels)) filters.value.labels = labels;

    const nextOperator: LabelOperator = labels.length > 1 ? operator : 'OR';
    if (filters.value.label_operator !== nextOperator) filters.value.label_operator = nextOperator;

    const dr = filters.value.dateRange;
    const nextFrom = from || null;
    const nextTo = to || null;

    if (dr.preset !== preset) dr.preset = preset;
    if (dr.from !== nextFrom) dr.from = nextFrom;
    if (dr.to !== nextTo) dr.to = nextTo;
    if (dr.hide_without_deadline !== hideWithoutDeadline) dr.hide_without_deadline = hideWithoutDeadline;

    void prefetchFromFilters();
  }

  useRouteQueryHydration((q) => applyRouteQueryToFilters(q as any));

  const taskFilters = computed(() => {
    const { dateRange, ...rest } = filters.value;
    return {
      ...rest,
      date_preset: dateRange.preset,
      date_from: dateRange.from,
      date_to: dateRange.to,
      hide_without_deadline: dateRange.hide_without_deadline ? 1 : undefined,
    };
  });

  const urlQuery = computed<LocationQueryRaw>(() => {
    const f = filters.value;
    const dr = f.dateRange;

    const selectedUserIds = Array.isArray(f.user_id)
      ? (f.user_id as Array<string | number>)
      : f.user_id != null && f.user_id !== ''
        ? [f.user_id as string | number]
        : [];

    return {
      q: (f.search ?? '').trim() || undefined,
      priority: f.priority || undefined,
      users: selectedUserIds.length ? selectedUserIds.map(String) : undefined,
      labels: Array.isArray(f.labels) && f.labels.length ? f.labels.map(String) : undefined,
      label_operator: Array.isArray(f.labels) && f.labels.length > 1 ? (f.label_operator || undefined) : undefined,
      date_preset: dr.preset || undefined,
      date_from: dr.from || undefined,
      date_to: dr.to || undefined,
      hide_without_deadline: dr.hide_without_deadline ? '1' : undefined,
    };
  });

  return {
    filters,
    taskFilters,
    urlQuery,
  };
}
