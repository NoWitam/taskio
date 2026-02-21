<template>
<div class="h-full max-h-full min-h-0 flex flex-col gap-8 px-6 pt-6 overflow-hidden">
    <PageHeader
        :title="t('tasks.title')"
        :description="t('tasks.description')"
    >
        <template #icon>
            <span class="text-primary">
                <Icon size="lg" name="check-circle"></Icon>
            </span>
        </template>

        <template #actions>
            <Button variant="primary" @click="showCreateTaskDialog = true">
                <Icon name="plus" size="sm" />
                {{ t('tasks.newTask') }}
            </Button>
        </template>

    </PageHeader>

    <div
        class="grid grid-cols-12 gap-4"
    >
        <div class="col-span-4 flex">
            <TextInput
                v-model="filters.search"
                :placeholder="t('tasks.searchPlaceholder')"
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
                :options="priorityOptions"
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
        :title="t('tasks.activeFilters')"
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
        class="w-full min-h-0 flex-1 grid grid-cols-4 gap-8"
    >
        <div 
            v-for="status in activeTab.statuses"
            :key="status.key"
            class="w-full min-h-0 flex flex-col"
        >
            <TasksList 
                :status="status.key"
                :label="status.label"
                :filters="debouncedTaskFilters"
                @open-task="openTaskDialog"
            />
        </div>
    </div>

    <CreateTaskDialog
        v-model="showCreateTaskDialog"
        @created="handleTaskCreated"
    />

    <TaskDialog
        v-if="selectedTaskId"
        v-model="isTaskDialogOpen"
        :task-id="selectedTaskId"
        @update:model-value="closeTaskDialog"
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
import { computed, ref, watch, onMounted } from 'vue';
import Icon from '../../../components/ui/Icon.vue';
import SelectInput from '../../../components/ui/inputs/SelectInput.vue';
import { useDebounceFn } from '@/composables/useDebounce';
import { useI18n } from '@/composables/useI18n';
import FilterBar, { type ActiveFilter } from '@/components/ui/tables/FilterBar.vue';
import { useUsersStore } from '@/store/users';
import { useLabelsStore } from '@/store/labels';
import CreateTaskDialog from '../components/CreateTaskDialog.vue';
import TaskDialog from '../components/TaskDialog.vue';
import { useTasksStore } from '@/store/tasks';
import { useTasksUrlFilters } from '@/modules/tasks/composables/useTasksUrlFilters';
import { useRouter, useRoute } from 'vue-router';

const { t } = useI18n();
const tab = ref('main');

const tasksStore = useTasksStore();
const usersStore = useUsersStore();
const labelsStore = useLabelsStore();
const router = useRouter();
const route = useRoute();
const showCreateTaskDialog = ref(false);
const { filters, taskFilters, urlQuery } = useTasksUrlFilters();

const priorityOptions = computed(() => [
    { label: t('tasks.allPriorities'), value: '' },
    { label: t('tasks.urgentPriority'), value: 'urgent' },
    { label: t('tasks.highPriority'), value: 'high' },
    { label: t('tasks.mediumPriority'), value: 'medium' },
    { label: t('tasks.lowPriority'), value: 'low' },
]);

// Dialog state for task preview
const isTaskDialogOpen = ref(false);
const selectedTaskId = ref<string | null>(null);

const openTaskDialog = (taskId: string) => {
    selectedTaskId.value = taskId;
    isTaskDialogOpen.value = true;
    router.push({ hash: `#task-${taskId}` });
};

const closeTaskDialog = () => {
    isTaskDialogOpen.value = false;
    router.push({ hash: '' });
};

// Check URL hash on mount and open dialog if needed
const checkUrlHash = () => {
    const hash = route.hash;
    if (hash && hash.startsWith('#task-')) {
        const taskId = hash.replace('#task-', '');
        if (taskId) {
            selectedTaskId.value = taskId;
            isTaskDialogOpen.value = true;
        }
    }
};

// Watch for hash changes
watch(() => route.hash, (newHash) => {
    if (!newHash || !newHash.startsWith('#task-')) {
        isTaskDialogOpen.value = false;
    } else {
        const taskId = newHash.replace('#task-', '');
        if (taskId && taskId !== selectedTaskId.value) {
            selectedTaskId.value = taskId;
            isTaskDialogOpen.value = true;
        }
    }
});

onMounted(() => {
    checkUrlHash();
});

function displayDatePreset(preset: typeof filters.value.dateRange.preset) {
    const presetLabels: Record<string, string> = {
        today: t('datePicker.today'),
        this_week: t('datePicker.thisWeek'),
        last_week: t('datePicker.lastWeek'),
        this_month: t('datePicker.thisMonth'),
    };

    if (!preset) return '';
    return presetLabels[preset] ?? t('tasks.deadline');
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
    if (search) active.push({ key: 'search', label: `${t('common.search')}: ${search}` });

    if (f.priority) {
        const priorityLabel: Record<string, string> = {
            urgent: t('tasks.urgentPriority'),
            high: t('tasks.highPriority'),
            medium: t('tasks.mediumPriority'),
            low: t('tasks.lowPriority'),
        };
        active.push({ key: 'priority', label: `${t('tasks.priority')}: ${priorityLabel[f.priority] ?? f.priority}` });
    }

    const selectedUserIds = Array.isArray(f.user_id)
        ? (f.user_id as Array<string | number>)
        : (f.user_id != null && f.user_id !== '' ? [f.user_id as string | number] : []);

    selectedUserIds.forEach((id) => {
        const u = usersStore.usersById?.[String(id)];
        const name = u?.name ? u.name : `#${id}`;
        active.push({ key: `user_id:${id}`, label: `${t('users.title')}: ${name}` });
    });

    if (Array.isArray(f.labels) && f.labels.length) {
        f.labels.forEach((labelId) => {
            const l = labelsStore.labelsById?.[String(labelId)];
            const text = l?.name ? l.name : `#${labelId}`;
            active.push({ key: `labels:${labelId}`, label: `${t('tasks.labels')}: ${text}` });
        });
    }

    if (f.dateRange.from) active.push({ key: 'date_from', label: `${t('tasks.deadline')}: ${t('tasks.from')} ${displayDateYmd(f.dateRange.from)}` });
    if (f.dateRange.to) active.push({ key: 'date_to', label: `${t('tasks.deadline')}: ${t('tasks.to')} ${displayDateYmd(f.dateRange.to)}` });
    if (f.dateRange.preset) {
        const presetText = displayDatePreset(f.dateRange.preset);
        if (presetText) active.push({ key: 'date_preset', label: `${t('tasks.deadline')}: ${presetText}` });
    }

    if (f.dateRange.hide_without_deadline) {
        active.push({ key: 'hide_without_deadline', label: t('tasks.without_deadline') });
    }

    return active;
});

const labelsOperatorInfo = computed(() => {
    const f = filters.value;
    if (!Array.isArray(f.labels) || f.labels.length <= 1) return '';
    const op = f.label_operator === 'AND' ? t('tasks.labels_and') : t('tasks.labels_or');
    return op;
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
        id: "main", label: t('tasks.list'), 
        statuses: [
            { key: "to_do", label: t('tasks.toDo')}, 
            { key: "in_progress", label: t('tasks.inProgress')}, 
            { key: "in_test", label: t('tasks.inTest')}, 
            { key: "done", label: t('tasks.done')} 
        ] 
    },
    { id: "archive", label: t('tasks.archive'), icon: "archive", statuses: [{ key: "archive", label: t('tasks.archive')}] },
    { id: "trash", label: t('tasks.trash'), icon: "trash", statuses: [{ key: "trash", label: t('tasks.trash')}] },
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
