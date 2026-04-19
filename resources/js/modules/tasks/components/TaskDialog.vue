<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import Dialog from '@/components/ui/Dialog.vue';
import Badge from '@/components/ui/Badge.vue';
import Icon from '@/components/ui/Icon.vue';
import Skeleton from '@/components/ui/Skeleton.vue';
import Button from '@/components/ui/Button.vue';
import CommentPanel from '@/components/CommentPanel.vue';
import TaskDetailsPanel from './TaskDetailsPanel.vue';
import CreateTaskDialog from './CreateTaskDialog.vue';
import { useTasksStore } from '@/store/tasks';
import { useI18n } from '@/composables/useI18n';
import type { Task } from '@/store/tasks';

const props = defineProps<{
    modelValue: boolean;
    taskId: string;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: boolean): void;
}>();

const tasksStore = useTasksStore();
const { t } = useI18n();
const task = ref<Task | null>(null);
const loading = ref(false);
const showEditDialog = ref(false);

const statusConfig = {
    to_do: { label: '', tone: 'neutral' as const, icon: 'circle-help' },
    in_progress: { label: '', tone: 'primary' as const, icon: 'loader' },
    in_test: { label: '', tone: 'warning' as const, icon: 'info-circle' },
    done: { label: '', tone: 'success' as const, icon: 'check-circle' },
    archive: { label: '', tone: 'neutral' as const, icon: 'archive' },
    trash: { label: '', tone: 'neutral' as const, icon: 'trash' },
};

const priorityConfig = {
    urgent: { label: '', tone: 'danger' as const },
    high: { label: '', tone: 'danger' as const },
    medium: { label: '', tone: 'warning' as const },
    low: { label: '', tone: 'neutral' as const },
};

const statusBadge = computed(() => {
    if (!task.value) return null;
    const config = statusConfig[task.value.status as keyof typeof statusConfig] || statusConfig.to_do;
    const statusKey = task.value.status as keyof typeof statusConfig;
    return {
        ...config,
        label: t(`changelog.statusValues.${statusKey}`)
    };
});

const priorityBadge = computed(() => {
    if (!task.value) return null;
    const config = priorityConfig[task.value.priority] || priorityConfig.medium;
    return {
        ...config,
        label: t(`changelog.priorityValues.${task.value.priority}`)
    };
});

const alertBadge = computed(() => {
    if (!task.value) return null;
    
    if (task.value.is_overdue && task.value.deadline_overdue) {
        const days = Math.abs(Math.round(task.value.deadline_overdue));
        return {
            tone: 'danger' as const,
            icon: 'alert-triangle',
            text: t('tasks.daysOverdue', '', { count: days }),
        };
    }
    
    if (task.value.is_at_risk && task.value.deadline_overdue) {
        const days = Math.abs(Math.round(task.value.deadline_overdue));
        const daysText = days === 1 ? t('tasks.deadlineDay') : t('tasks.deadlineDays');
        return {
            tone: 'warning' as const,
            icon: 'alert-circle',
            text: t('tasks.deadlineApproaching', '', { count: days, days: daysText }),
        };
    }
    
    return null;
});

// Dostępne akcje zmiany statusu w zależności od aktualnego statusu
const statusActions = computed(() => {
    if (!task.value || task.value.status === 'trash') return [];
    
    const actions = [];
    const currentStatus = task.value.status;
    
    if (currentStatus === 'to_do') {
        actions.push({ 
            label: 'startTask', 
            status: 'in_progress', 
            variant: 'primary' as const,
            icon: 'play'
        });
    } else if (currentStatus === 'in_progress') {
        actions.push({ 
            label: 'sendToTest', 
            status: 'in_test', 
            variant: 'warning' as const,
            icon: 'flask'
        });
        actions.push({ 
            label: 'complete', 
            status: 'done', 
            variant: 'success' as const,
            icon: 'check'
        });
    } else if (currentStatus === 'in_test') {
        actions.push({ 
            label: 'backToProgress', 
            status: 'in_progress', 
            variant: 'secondary' as const,
            icon: 'rotate-ccw'
        });
        actions.push({ 
            label: 'complete', 
            status: 'done', 
            variant: 'success' as const,
            icon: 'check'
        });
    } else if (currentStatus === 'done') {
        actions.push({ 
            label: 'backToProgress', 
            status: 'in_progress', 
            variant: 'secondary' as const,
            icon: 'rotate-ccw'
        });
    }
    
    return actions;
});

// Placeholder funkcje (do implementacji później)
const handleEdit = () => {
    showEditDialog.value = true;
};

const onTaskUpdated = (updatedTask: Task) => {
    // Zaktualizuj dane w podglądzie zadania danymi z odpowiedzi serwera
    task.value = updatedTask;
};

const handleDelete = async () => {
    if (!task.value) return;
    
    const confirmed = confirm('Czy na pewno chcesz przenieść to zadanie do kosza?');
    if (!confirmed) return;
    
    try {
        await tasksStore.deleteTask(task.value.id);
        emit('update:modelValue', false);
    } catch (error) {
        console.error('Error deleting task:', error);
        alert('Wystąpił błąd podczas usuwania zadania');
    }
};

const handleRestore = async () => {
    if (!task.value) return;
    
    try {
        const restoredTask = await tasksStore.restoreTask(task.value.id);
        task.value = restoredTask;
        emit('update:modelValue', false);
    } catch (error) {
        console.error('Error restoring task:', error);
        alert('Wystąpił błąd podczas przywracania zadania');
    }
};

const handleStatusChange = async (newStatus: string) => {
    if (!task.value) return;
    
    try {
        const updatedTask = await tasksStore.changeStatus(task.value.id, newStatus);
        task.value = updatedTask;
    } catch (error: any) {
        console.error('Error changing status:', error);
        const message = error.response?.data?.message || 'Wystąpił błąd podczas zmiany statusu';
        alert(message);
    }
};

watch(() => props.modelValue, async (isOpen) => {
    if (isOpen && props.taskId) {
        loading.value = true;
        try {
            task.value = await tasksStore.fetchTask(props.taskId);
        } catch (error) {
            console.error('Błąd podczas pobierania taska:', error);
        } finally {
            loading.value = false;
        }
    }
}, { immediate: true });

const close = () => {
    emit('update:modelValue', false);
    task.value = null; // Reset task data po zamknięciu
};
</script>

<template>
  <Dialog :model-value="modelValue" width="2xl" @update:model-value="close" height="h-[90vh]">
    <template #header>
      <div class="p-6">
        <h2 v-if="!loading" class="text-4xl font-semibold">{{ task.title }}</h2>
        <Skeleton v-else width="300px" height="28px" />
        
        <div v-if="!loading && task" class="mt-3 flex flex-wrap items-center gap-2">
          <!-- Status Badge -->
          <Badge v-if="statusBadge" :tone="statusBadge.tone">
            <Icon :name="statusBadge.icon" size="xs" />
            {{ statusBadge.label }}
          </Badge>

          <!-- Priority Badge -->
          <Badge v-if="priorityBadge" :tone="priorityBadge.tone" dot>
            {{ priorityBadge.label }}
          </Badge>

          <!-- Alert Badge -->
          <Badge v-if="alertBadge" :tone="alertBadge.tone">
            <Icon :name="alertBadge.icon" size="xs" />
            {{ alertBadge.text }}
          </Badge>

          <!-- Custom Labels -->
          <Badge
            v-for="label in task.labels"
            :key="label.id"
            tone="custom"
            :color="label.color"
          >
            <Icon v-if="label.icon" :name="label.icon" size="xs" />
            {{ label.name }}
          </Badge>
        </div>

        <div v-else-if="loading" class="mt-3 flex flex-wrap items-center gap-2">
          <Skeleton width="100px" height="24px" rounded="full" />
          <Skeleton width="80px" height="24px" rounded="full" />
          <Skeleton width="120px" height="24px" rounded="full" />
        </div>
      </div>
    </template>

    <div class="flex flex-1 h-full overflow-hidden relative">
      <!-- Left panel - Task Details -->
      <div v-if="loading" class="flex h-full flex-1 flex-col">
        <!-- Info section skeleton -->
        <div class="border-b border-border p-6">
          <div class="flex flex-wrap items-start justify-between gap-6">
            <div class="flex items-start gap-3">
              <Skeleton width="16px" height="16px" rounded="sm" />
              <div class="space-y-2">
                <Skeleton width="60px" height="12px" />
                <Skeleton width="120px" height="16px" />
              </div>
            </div>
            <div class="flex items-start gap-3">
              <Skeleton width="16px" height="16px" rounded="sm" />
              <div class="space-y-2">
                <Skeleton width="100px" height="12px" />
                <div class="flex items-center gap-2">
                  <Skeleton width="24px" height="24px" rounded="full" />
                  <Skeleton width="120px" height="16px" />
                </div>
              </div>
            </div>
            <div class="flex items-start gap-3">
              <Skeleton width="16px" height="16px" rounded="sm" />
              <div class="space-y-2">
                <Skeleton width="80px" height="12px" />
                <div class="flex items-center gap-2">
                  <Skeleton width="24px" height="24px" rounded="full" />
                  <Skeleton width="120px" height="16px" />
                </div>
              </div>
            </div>
            <div class="w-full flex items-start gap-3">
              <Skeleton width="16px" height="16px" rounded="sm" />
              <div class="flex-1 space-y-2">
                <Skeleton width="40px" height="12px" />
                <Skeleton width="100%" height="200px" />
              </div>
            </div>
          </div>
        </div>

        <!-- Tabs skeleton -->
        <div class="border-b border-border p-6">
          <div class="flex gap-2">
            <Skeleton width="120px" height="40px" rounded="lg" />
            <Skeleton width="120px" height="40px" rounded="lg" />
            <Skeleton width="120px" height="40px" rounded="lg" />
            <Skeleton width="180px" height="40px" rounded="lg" />
          </div>
        </div>

        <!-- Content skeleton -->
        <div class="flex-1 overflow-y-auto p-6">
          <div class="space-y-3">
            <Skeleton width="100%" height="20px" />
            <Skeleton width="90%" height="20px" />
            <Skeleton width="95%" height="20px" />
          </div>
        </div>
      </div>

      <TaskDetailsPanel v-else-if="task" :key="task.id" :task="task" @task-updated="onTaskUpdated" />

      <!-- Right panel - Comments -->
      <CommentPanel v-if="task" :entity-id="task.id" entity-type="task" />
    </div>

    <template v-if="!loading && task" #footer>
      <div class="flex w-full items-center justify-between">
        <!-- Akcje ogólne po lewej -->
        <div class="flex gap-2">
          <Button v-if="task.status !== 'trash'" variant="secondary" size="sm" @click="handleEdit">
            <Icon name="pencil" size="sm" />
            {{ t('taskActions.edit') }}
          </Button>
          <Button v-if="task.status !== 'trash'" variant="danger" size="sm" @click="handleDelete">
            <Icon name="trash" size="sm" />
            {{ t('taskActions.delete') }}
          </Button>
          <Button v-else variant="success" size="sm" @click="handleRestore">
            <Icon name="undo" size="sm" />
            {{ t('taskActions.restore') }}
          </Button>
        </div>

        <!-- Akcje zmiany statusu po prawej -->
        <div class="flex gap-2">
          <Button
            v-for="action in statusActions"
            :key="action.status"
            :variant="action.variant"
            size="sm"
            @click="handleStatusChange(action.status)"
          >
            <Icon :name="action.icon" size="sm" />
            {{ t(`taskActions.${action.label}`) }}
          </Button>
        </div>
      </div>
    </template>
  </Dialog>

  <!-- Dialog edycji zadania -->
  <CreateTaskDialog
    v-if="task"
    v-model="showEditDialog"
    :edit-mode="true"
    :task-id="task.id"
    @updated="onTaskUpdated"
  />
</template>
