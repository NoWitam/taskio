<script setup lang="ts">
import Badge from '../../../components/ui/Badge.vue';
import { ref, computed, onMounted, watch } from 'vue';
import Card from '../../../components/ui/Card.vue';
import Avatar from '../../../components/ui/Avatar.vue';
import Icon from '@/components/ui/Icon.vue';
import Skeleton from '../../../components/ui/Skeleton.vue';
import { useTasksStore } from '@/store/tasks';
import { useInfiniteScroll } from '@/composables/useInfiniteScroll';
import { useI18n } from '@/composables/useI18n';
import type { TaskFilters } from '@/store/tasks';

const props = defineProps<{
    status: string;
    label: string;
    filters: TaskFilters;
}>();

const emit = defineEmits<{
    (e: 'open-task', taskId: string): void;
}>();

const tasksStore = useTasksStore();
const { t } = useI18n();

// Pobierz taski dla danego statusu
const tasks = computed(() => tasksStore.getTasksByStatus(props.status).value);
const loading = computed(() => tasksStore.getLoadingByStatus(props.status).value);
const hasMore = computed(() => tasksStore.getHasMoreByStatus(props.status).value);
const total = computed(() => tasksStore.getTotalByStatus(props.status).value);

const priorityObject = computed(() => ({
    "urgent": { tone: 'danger' as const, label: t('tasks.priorityUrgent') },
    "high": { tone: 'warning' as const, label: t('tasks.priorityHigh') },
    "medium": { tone: 'primary' as const, label: t('tasks.priorityMedium') },
    "low": { tone: 'neutral' as const, label: t('tasks.priorityLow') }
}));

const openTaskDialog = (taskId: string) => {
    emit('open-task', taskId);
};

// Funkcja do pobierania tasków
const loadTasks = async () => {
    try {
        await tasksStore.fetchTasksByStatus(props.status, props.filters, true);
    } catch (error) {
        console.error('Błąd podczas ładowania tasków:', error);
    }
};

// Funkcja do ładowania kolejnych tasków (infinite scroll)
const loadMoreTasks = async () => {
    if (!hasMore.value || loading.value) return;
    
    try {
        await tasksStore.fetchTasksByStatus(props.status, props.filters, false);
    } catch (error) {
        console.error('Błąd podczas ładowania kolejnych tasków:', error);
    }
};

// Infinite scroll setup
const { triggerElement: loadMoreTrigger } = useInfiniteScroll(loadMoreTasks, {
    rootMargin: '100px',
    threshold: 0.1,
});

// Załaduj taski przy montowaniu komponentu
onMounted(() => {
    loadTasks();
});

// Przeładuj taski gdy zmienią się filtry
watch(() => props.filters, () => {
    loadTasks();
}, { deep: true });

</script>

<template>
<div class="w-full min-h-0 flex-1 flex flex-col">
    <div 
        class="flex items-center justify-between pb-3 mb-3 border-b border-border"
    >
        <div
            class="flex gap-2 items-end"
        >
            <h3 class="text-lg font-semibold text-foreground">
                {{ label }}
            </h3>
        </div>

        <Badge
            tone="primary"
        >
            {{ total ?? '-' }}
        </Badge>
    </div>

    <!-- Archive Warning Alert -->
    <div 
        v-if="status === 'archive'" 
        class="flex items-start gap-3 p-3 mb-4 rounded-lg border border-blue-500/20 bg-blue-500/10"
    >
        <div class="shrink-0 text-blue-600 dark:text-blue-500">
            <Icon name="info-circle" size="lg" />
        </div>
        <div class="flex-1 min-w-0">
            <h4 class="font-medium text-sm mb-1 text-blue-900 dark:text-blue-100">
                {{ t('tasks.archiveWarningTitle') }}
            </h4>
            <p class="text-sm text-blue-800 dark:text-blue-200">
                {{ t('tasks.archiveWarningMessage') }}
            </p>
        </div>
    </div>

    <!-- Trash Warning Alert -->
    <div 
        v-else-if="status === 'trash'" 
        class="flex items-start gap-3 p-3 mb-4 rounded-lg border border-amber-500/20 bg-amber-500/10"
    >
        <div class="shrink-0 text-amber-600 dark:text-amber-500">
            <Icon name="alert-triangle" size="lg" />
        </div>
        <div class="flex-1 min-w-0">
            <h4 class="font-medium text-sm mb-1 text-amber-900 dark:text-amber-100">
                {{ t('tasks.trashWarningTitle') }}
            </h4>
            <p class="text-sm text-amber-800 dark:text-amber-200">
                {{ t('tasks.trashWarningMessage') }}
            </p>
        </div>
    </div>

    <div 
        class="flex-1 flex flex-col gap-5 overflow-y-scroll pr-4 pb-4"
    >
        <Card
            v-for="task in tasks"
            :key="task.id"
            class="group cursor-pointer hover:border-primary"
            @click="openTaskDialog(task.id)"
        >
            <div>
                <p class="text-lg font-semibold text-foreground group-hover:text-primary">
                    {{ task.title }}
                </p>

                <div class="flex gap-2 mt-2">
                    <Badge 
                        dot 
                        :tone="priorityObject[task.priority].tone"
                    >
                        {{ priorityObject[task.priority].label }}
                    </Badge>

                    <Badge
                        v-if="task.comments"
                    >
                        <Icon name="message" size="xs" />
                        {{ task.comments }}
                    </Badge>

                    <Badge
                        v-for="(label, index) in task.labels"
                        :key="`${task.id}-label-${index}`"
                        :tone="label.hasOwnProperty('color') ? 'custom' : 'neutral'"
                        :color="label.hasOwnProperty('color') ? label.color : null"
                    >
                        <Icon v-if="label.icon" :name="label.icon" size="xs" />
                        {{ label.name }}
                    </Badge>
                </div>
            </div>

            <div class="flex items-center justify-between mt-2 pt-2 border-t border-border/50">
                <div class="flex items-center gap-2 text-muted-foreground">
                    <Icon name="calendar" size="sm" />
                    <span class="font-semibold text-sm"> {{ task.deadline || t('tasks.noDeadline') }} </span>
                </div>

                <Avatar
                    v-if="task.assigned || task.assignee"
                    :name="(task.assigned || task.assignee).name"
                    :src="task.assigned?.avatar"
                    tooltip
                />
                <span v-else class="text-sm text-muted-foreground">{{ t('tasks.unassigned') }}</span>
            </div>
        </Card>

        <template v-if="loading || hasMore">
            <Card
                v-for="i in 3"
                :key="`loading-skeleton-${i}`"
                :ref="i === 1 ? (el: any) => { if (el?.$el) loadMoreTrigger = el.$el; else if (el) loadMoreTrigger = el; } : undefined"
            >
                <div>
                    <Skeleton width="340px" height="22px" />

                    <div class="flex gap-2 mt-2">
                        <Skeleton rounded="lg" width="60px" height="18px" />
                        <Skeleton rounded="lg" width="50px" height="18px" />
                    </div>
                </div>

                <div class="flex items-center justify-between mt-2 pt-2 border-t border-border/50">
                    <div class="flex items-center gap-2 text-muted-foreground">
                        <Skeleton rounded="lg" width="110px" height="40px" />
                    </div>
                    <Skeleton rounded="full" width="40px" height="40px" />
                </div>
            </Card>
        </template>

        <div v-if="total === 0" class="text-center py-8">
            <p class="text-muted-foreground">{{ t('tasks.noTasks') }}</p>
        </div>
    </div>
</div>
</template>