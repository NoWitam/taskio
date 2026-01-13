<template>
<div class="h-full max-h-full min-h-0 flex flex-col gap-8 px-6 pt-6 overflow-hidden">
    <PageHeader
        title="Zadania"
        description="Zarządzaj i organizuj pracę swojego zespołu."
    >
        <template #icon>
            <span class="text-primary">
                <Icon size="lg" name="check-circle"></Icon>
            </span>
        </template>

        <template #actions>
            <Button variant="primary" @click="showCreateTaskDialog = true">
                <Icon name="plus" size="sm" />
                Nowe zadanie
            </Button>
        </template>

    </PageHeader>

    <div
        class="grid grid-cols-12 gap-4"
    >
        <div class="col-span-4 flex">
            <TextInput
                v-model="filters.search"
                placeholder="Szukaj zadań.."
            >
                <template #left>
                    <span
                        class="text-muted-foreground"
                    >
                        <Icon name="search" />
                    </span>
                </template>
            </TextInput>
        </div>

        <div class="col-span-1">
            <SelectInput
                v-model="filters.priority"
                :options="[
                    { label: 'Wszystkie', value: '' },
                    { label: 'Wysoki', value: 'high' },
                    { label: 'Średni', value: 'medium' },
                    { label: 'Niski', value: 'low' },
                ]"
            >
                <template #left>
                    <span
                        class="text-muted-foreground"
                    >
                        <Icon name="flag" />
                    </span>
                </template>
            </SelectInput>
        </div>
        
        <div class="col-span-3">
            <UserSelect
                v-model="filters.user_id"
                multiple
            />
        </div>

        <div class="col-span-2">
            <LabelSelect
                v-model="filters.labels"
                v-model:operator="filters.label_operator"
                addable
            />
        </div>


        <div class="col-span-2">
            <DateRangeSelect
                v-model="filters.dateRange"
            />
        </div>

    </div>

    <FilterBar
        urlable
        title="Aktywne filtry"
        :active="activeFilters"
        :urlQuery="urlQuery"
        @remove="removeActiveFilter"
        @clear="clearAllFilters"
    >
        <span
            v-if="labelsOperatorInfo"
            class="text-sm font-medium text-foreground/60"
        >
            {{ labelsOperatorInfo }}
        </span>
    </FilterBar>

    <Tabs :tabs="tabs" v-model="tab" />

    <div
        class="w-full min-h-0 flex-1 flex gap-8"
    >
        <div 
            v-for="status in activeTab.statuses"
            :key="status.key"
            class="w-full min-h-0 flex flex-col flex-1"
        >
            <TasksList 
                :status="status.key"
                :label="status.label"
                :filters="debouncedTaskFilters"
            />
        </div>
    </div>

    <CreateTaskDialog
        v-model="showCreateTaskDialog"
        @created="handleTaskCreated"
    />
</div>
</template>

<script setup lang="ts">
import PageHeader from '../../../components/ui/patterns/PageHeader.vue';
import Button from '../../../components/ui/Button.vue';
import Tabs from '../../../components/ui/Tabs.vue';
import TasksList from '../components/TasksList.vue';
import TextInput from '../../../components/ui/inputs/TextInput.vue';
import LabelSelect from '@/modules/labels/components/LabelSelect.vue';
import UserSelect from '../../../components/ui/inputs/reusable/UserSelect.vue';
import DateRangeSelect from '@/components/ui/inputs/DateRangeSelect.vue';
import { computed, ref, watch } from 'vue';
import Icon from '../../../components/ui/Icon.vue';
import SelectInput from '../../../components/ui/inputs/SelectInput.vue';
import { useDebounceFn } from '@/composables/useDebounce';
import FilterBar, { type ActiveFilter } from '@/components/ui/tables/FilterBar.vue';
import { useUsersStore } from '@/store/users';
import { useLabelsStore } from '@/store/labels';
import CreateTaskDialog from '../components/CreateTaskDialog.vue';
import { useTasksStore } from '@/store/tasks';
import { useTasksUrlFilters } from '@/modules/tasks/composables/useTasksUrlFilters';

const tab = ref('main');

const tasksStore = useTasksStore();
const usersStore = useUsersStore();
const labelsStore = useLabelsStore();
const showCreateTaskDialog = ref(false);
const { filters, taskFilters, urlQuery } = useTasksUrlFilters();

function displayDatePreset(preset: typeof filters.value.dateRange.preset) {
    const presetLabels: Record<string, string> = {
        today: 'Dzisiaj',
        this_week: 'Ten tydzień',
        last_week: 'Ostatni tydzień',
        this_month: 'Ten miesiąc',
    };

    if (!preset) return '';
    return presetLabels[preset] ?? 'Zakres terminów';
}

function displayDateYmd(ymd: string | null) {
    if (!ymd) return '';
    const m = String(ymd).match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (!m) return String(ymd);
    return `${m[3]}.${m[2]}.${m[1]}`;
}

const activeFilters = computed<ActiveFilter[]>(() => {
    const active: ActiveFilter[] = [];
    const f = filters.value;

    const search = (f.search ?? '').trim();
    if (search) active.push({ key: 'search', label: `Szukaj: ${search}` });

    if (f.priority) {
        const priorityLabel: Record<string, string> = {
            high: 'Wysoki',
            medium: 'Średni',
            low: 'Niski',
        };
        active.push({ key: 'priority', label: `Priorytet: ${priorityLabel[f.priority] ?? f.priority}` });
    }

    const selectedUserIds = Array.isArray(f.user_id)
        ? (f.user_id as Array<string | number>)
        : (f.user_id != null && f.user_id !== '' ? [f.user_id as string | number] : []);

    selectedUserIds.forEach((id) => {
        const u = usersStore.usersById?.[String(id)];
        const name = u?.name ? u.name : `#${id}`;
        active.push({ key: `user_id:${id}`, label: `Użytkownik: ${name}` });
    });

    if (Array.isArray(f.labels) && f.labels.length) {
        f.labels.forEach((labelId) => {
            const l = labelsStore.labelsById?.[String(labelId)];
            const text = l?.text ? l.text : `#${labelId}`;
            active.push({ key: `labels:${labelId}`, label: `Etykieta: ${text}` });
        });
    }

    if (f.dateRange.from) active.push({ key: 'date_from', label: `Termin: od ${displayDateYmd(f.dateRange.from)}` });
    if (f.dateRange.to) active.push({ key: 'date_to', label: `Termin: do ${displayDateYmd(f.dateRange.to)}` });
    if (f.dateRange.preset) {
        const presetText = displayDatePreset(f.dateRange.preset);
        if (presetText) active.push({ key: 'date_preset', label: `Termin: ${presetText}` });
    }

    if (f.dateRange.hide_without_deadline) {
        active.push({ key: 'hide_without_deadline', label: 'Bez terminu: ukryte' });
    }

    return active;
});

const labelsOperatorInfo = computed(() => {
    const f = filters.value;
    if (!Array.isArray(f.labels) || f.labels.length <= 1) return '';
    const op = f.label_operator === 'AND' ? 'ORAZ' : 'LUB';
    return `Etykiety (${op})`;
});

function removeActiveFilter(key: string) {
    if (key.startsWith('user_id:')) {
        const id = key.slice('user_id:'.length);
        const arr = Array.isArray(filters.value.user_id) ? (filters.value.user_id as any[]) : [];
        filters.value.user_id = arr.filter((x) => String(x) !== id) as any;
        return;
    }

    if (key.startsWith('labels:')) {
        const id = key.slice('labels:'.length);
        filters.value.labels = (filters.value.labels || []).filter((x) => String(x) !== id);
        if (!filters.value.labels.length) filters.value.label_operator = 'OR';
        return;
    }

    switch (key) {
        case 'search':
            filters.value.search = '';
            break;
        case 'priority':
            filters.value.priority = '';
            break;
        case 'date_from':
            filters.value.dateRange = { ...filters.value.dateRange, preset: '', from: null };
            break;
        case 'date_to':
            filters.value.dateRange = { ...filters.value.dateRange, preset: '', to: null };
            break;
        case 'date_preset':
            filters.value.dateRange = { ...filters.value.dateRange, preset: '', from: null, to: null };
            break;
        case 'hide_without_deadline':
            filters.value.dateRange = { ...filters.value.dateRange, hide_without_deadline: false };
            break;
    }
}

function clearAllFilters() {
    filters.value.search = '';
    filters.value.priority = '';
    filters.value.labels = [];
    filters.value.label_operator = 'OR';
    filters.value.user_id = [] as any;
    filters.value.dateRange = { preset: '', from: null, to: null, hide_without_deadline: false };
}

const debouncedTaskFilters = ref(taskFilters.value);
const applyDebouncedTaskFilters = useDebounceFn((v: any) => {
    debouncedTaskFilters.value = v;
}, 900);

watch(
    taskFilters,
    (v) => {
        applyDebouncedTaskFilters(v);
    },
    { deep: true }
);

const tabs = [
    { 
        id: "main", label: "Lista", 
        statuses: [{ key: "to_do", label: "Do zrobienia"}, { key: "in_progrss", label: "W trakcie"}, { key: "in_test", label: "W testach"}, { key: "done", label: "Zrobione" }] 
    },
    { id: "archive", label: "Archiwum", icon: "archive", statuses: [{ key: "archive", label: "Archiwum"}] },
    { id: "trash", label: "Kosz", icon: "trash", statuses: [{ key: "trash", label: "Kosz"}] },
];

const activeTab = computed(() => {
    const idx = tabs.findIndex((t) => t.id === tab.value);
    return tabs[idx];
});

async function handleTaskCreated() {
    // Ensure lists refresh even if backend returns a different shape.
    // We refresh the default column to keep counters in sync.
    try {
        await tasksStore.fetchTasksByStatus('to_do', debouncedTaskFilters.value as any, true);
    } catch (e) {
        // no-op
    }
}
</script>
