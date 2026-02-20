<script setup lang="ts">
import { ref, onMounted, computed } from 'vue';
import { useTasksStore, type Task, type Activity } from '@/store/tasks';
import Icon from '@/components/ui/Icon.vue';
import Avatar from '@/components/ui/Avatar.vue';
import Tabs from '@/components/ui/Tabs.vue';
import Skeleton from '@/components/ui/Skeleton.vue';
import Badge from '@/components/ui/Badge.vue';

const props = defineProps<{
    task: Task;
}>();

const tasksStore = useTasksStore();
const activeTab = ref('history');

const tabs = [
    { id: 'history', label: 'Historia', icon: 'clock' },
    { id: 'form', label: 'Formularz', icon: 'file-text' },
    { id: 'checklist', label: 'Checklista', icon: 'check-square' },
    { id: 'approval', label: 'Lejek zatwierdzenia', icon: 'git-merge' },
];

const history = computed(() => tasksStore.historyByTask[props.task.id] || []);
const loadingHistory = computed(() => tasksStore.loadingHistory[props.task.id] || false);

const fetchHistory = async () => {
    await tasksStore.fetchHistory(props.task.id);
};

const formatDate = (dateString?: string | null) => {
    if (!dateString) return 'Brak';
    try {
        const date = new Date(dateString);
        const day = date.getDate();
        const months = ['stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca', 'lipca', 'sierpnia', 'września', 'października', 'listopada', 'grudnia'];
        const month = months[date.getMonth()];
        const year = date.getFullYear();
        return `${day} ${month} ${year}`;
    } catch {
        return dateString;
    }
};

const formatDateTime = (dateString: string) => {
    const date = new Date(dateString);
    return date.toLocaleString('pl-PL', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
};

const getEventIcon = (event: string) => {
    const icons: Record<string, string> = {
        created: 'plus-circle',
        updated: 'edit',
        deleted: 'trash',
        restored: 'rotate-ccw',
        status_changed: 'arrow-right',
        archived: 'archive',
        unarchived: 'package',
    };
    return icons[event] || 'activity';
};

const getEventTone = (event: string) => {
    const tones: Record<string, 'success' | 'primary' | 'warning' | 'danger' | 'neutral'> = {
        created: 'success',
        updated: 'primary',
        deleted: 'danger',
        restored: 'success',
        status_changed: 'warning',
        archived: 'neutral',
        unarchived: 'primary',
    };
    return tones[event] || 'neutral';
};

const formatChangeValue = (value: any): string => {
    if (value === null || value === undefined) return 'brak';
    if (typeof value === 'boolean') return value ? 'tak' : 'nie';
    if (typeof value === 'object') return JSON.stringify(value);
    return String(value);
};

onMounted(() => {
    fetchHistory();
});
</script>

<template>
  <div class="flex h-full flex-1 flex-col overflow-y-auto">
    <!-- Sekcja informacji o tasku -->
    <div class="border-b border-border">
      <div class="flex flex-wrap items-start justify-between gap-6 p-6">
        <!-- Deadline -->
        <div class="flex items-start gap-3">
          <Icon name="calendar" size="sm" class="mt-0.5 text-muted-foreground" />
          <div>
            <div class="text-xs font-medium text-muted-foreground">Termin</div>
            <div class="text-sm">{{ formatDate(task.deadline) }}</div>
          </div>
        </div>

        <!-- Przypisana osoba -->
        <div class="flex items-start gap-3">
          <Icon name="user" size="sm" class="mt-0.5 text-muted-foreground" />
          <div>
            <div class="text-xs font-medium text-muted-foreground">Przypisana osoba</div>
            <div class="flex items-center gap-2 text-sm">
              <Avatar :name="task.assigned.name" :src="task.assigned.avatar" size="xs" />
              {{ task.assigned.name }}
            </div>
          </div>
        </div>

        <!-- Twórca -->
        <div v-if="task.creator" class="flex items-start gap-3">
          <Icon name="user-circle" size="sm" class="mt-0.5 text-muted-foreground" />
          <div>
            <div class="text-xs font-medium text-muted-foreground">Utworzył</div>
            <div class="flex items-center gap-2 text-sm">
              <Avatar :name="task.creator.name" :src="task.creator.avatar" size="xs" />
              {{ task.creator.name }}
            </div>
          </div>
        </div>

        <!-- Opis -->
        <div class="w-full flex items-start gap-3">
          <Icon name="file-text" size="sm" class="mt-0.5 text-muted-foreground" />
          <div class="flex-1">
            <div class="text-xs font-medium text-muted-foreground">Opis</div>
            <div class="mt-1 h-[200px] overflow-y-auto pr-3 text-sm">
              <p v-if="task.description" class="whitespace-pre-wrap">{{ task.description }}</p>
              <p v-else class="text-muted-foreground">Brak opisu</p>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Zakładki -->
    <div class="border-b border-border p-6">
      <Tabs v-model="activeTab" :tabs="tabs" />
    </div>

    <!-- Zawartość zakładek -->
    <div class="flex-1 p-6">
      <div v-if="activeTab === 'history'" class="space-y-4">
        <!-- Loading state -->
        <div v-if="loadingHistory" class="space-y-3">
          <div v-for="i in 5" :key="i" class="flex gap-3">
            <Skeleton class="h-10 w-10 rounded-full" />
            <div class="flex-1 space-y-2">
              <Skeleton class="h-4 w-48" />
              <Skeleton class="h-3 w-full" />
            </div>
          </div>
        </div>

        <!-- Empty state -->
        <div v-else-if="history.length === 0" class="flex items-center justify-center py-12 text-muted-foreground">
          <div class="text-center">
            <Icon name="clock" size="lg" class="mx-auto mb-2 opacity-50" />
            <p class="text-sm">Brak historii zmian</p>
          </div>
        </div>

        <!-- History timeline -->
        <div v-else class="relative space-y-4 pl-8">
          <!-- Vertical line -->
          <div class="absolute left-[19px] top-2 bottom-2 w-px bg-border" />

          <div
            v-for="activity in history"
            :key="activity.id"
            class="relative"
          >
            <!-- Timeline dot -->
            <div class="absolute left-[-32px] top-1 flex h-10 w-10 items-center justify-center rounded-full border-2 border-border bg-background">
              <Icon :name="getEventIcon(activity.event)" size="sm" />
            </div>

            <!-- Activity card -->
            <div class="rounded-lg border border-border bg-background p-4">
              <div class="mb-2 flex items-start justify-between gap-2">
                <div>
                  <Badge :tone="getEventTone(activity.event)" size="sm">
                    {{ activity.event_description }}
                  </Badge>
                  <p v-if="activity.description" class="mt-1 text-sm text-muted-foreground">
                    {{ activity.description }}
                  </p>
                </div>
                <p class="text-xs text-muted-foreground whitespace-nowrap">
                  {{ formatDateTime(activity.created_at) }}
                </p>
              </div>

              <!-- Causer -->
              <div v-if="activity.causer" class="mb-3 flex items-center gap-2 text-sm text-muted-foreground">
                <Avatar :name="activity.causer.name" size="xs" />
                <span>{{ activity.causer.name }}</span>
              </div>

              <!-- Changes -->
              <div v-if="Object.keys(activity.changes).length > 0" class="mt-3 space-y-2 border-t border-border pt-3">
                <div
                  v-for="(change, key) in activity.changes"
                  :key="key"
                  class="text-sm"
                >
                  <span class="font-medium">{{ key }}:</span>
                  <div class="ml-4 mt-1 space-y-1">
                    <div class="flex items-center gap-2">
                      <span class="text-danger line-through">{{ formatChangeValue(change.old) }}</span>
                      <Icon name="arrow-right" size="xs" class="text-muted-foreground" />
                      <span class="text-success">{{ formatChangeValue(change.new) }}</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div v-if="activeTab === 'form'" class="space-y-4">
        <div class="flex items-center justify-center py-12 text-muted-foreground">
          <div class="text-center">
            <Icon name="file-text" size="lg" class="mx-auto mb-2 opacity-50" />
            <p class="text-sm">Formularz w przygotowaniu</p>
          </div>
        </div>
      </div>

      <div v-if="activeTab === 'checklist'" class="space-y-4">
        <div class="flex items-center justify-center py-12 text-muted-foreground">
          <div class="text-center">
            <Icon name="check-square" size="lg" class="mx-auto mb-2 opacity-50" />
            <p class="text-sm">Checklista w przygotowaniu</p>
          </div>
        </div>
      </div>

      <div v-if="activeTab === 'approval'" class="space-y-4">
        <div class="flex items-center justify-center py-12 text-muted-foreground">
          <div class="text-center">
            <Icon name="git-merge" size="lg" class="mx-auto mb-2 opacity-50" />
            <p class="text-sm">Lejek zatwierdzenia w przygotowaniu</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
