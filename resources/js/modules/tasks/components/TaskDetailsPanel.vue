<script setup lang="ts">
import { ref, onMounted, computed, watch } from 'vue';
import { useTasksStore, type Task, type ChangelogEntry } from '@/store/tasks';
import { useI18n } from '@/composables/useI18n';
import Icon from '@/components/ui/Icon.vue';
import Avatar from '@/components/ui/Avatar.vue';
import Tabs from '@/components/ui/Tabs.vue';
import Skeleton from '@/components/ui/Skeleton.vue';
import Badge from '@/components/ui/Badge.vue';
import MarkdownViewer from '@/components/editors/MarkdownEditor/MarkdownViewer.vue';
import type { EditorConfig as MarkdownEditorConfig } from '@/components/editors/MarkdownEditor/types/editor';

const props = defineProps<{
    task: Task;
}>();

const tasksStore = useTasksStore();
const { t } = useI18n();
const activeTab = ref('history');

const tabsData = [
    { id: 'history', labelKey: 'taskDetails.tabHistory', icon: 'clock' },
    { id: 'form', labelKey: 'taskDetails.tabForm', icon: 'file-text' },
    { id: 'checklist', labelKey: 'taskDetails.tabChecklist', icon: 'check-square' },
    { id: 'approval', labelKey: 'taskDetails.tabApproval', icon: 'git-merge' },
];

const tabs = computed(() =>
    tabsData.map(tab => ({
        ...tab,
        label: t(tab.labelKey),
    }))
);

const changelog = computed(() => tasksStore.changelogByTask[props.task.id] || []);
const loadingChangelog = computed(() => tasksStore.loadingChangelog[props.task.id] || false);

const fetchChangelog = async () => {
    await tasksStore.fetchChangelog(props.task.id);
};

const formatDate = (dateString?: string | null) => {
    if (!dateString) return t('taskDetails.noValue');
    try {
        const date = new Date(dateString);
        const day = date.getDate();
        const months = t('datePicker.months');
        const month = months[date.getMonth()] || '';
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
        created: 'plus',
        updated: 'pencil',
        deleted: 'trash',
        restored: 'undo',
        change_status: 'chevron-right',
        archived: 'archive',
        unarchived: 'restore',
    };
    return icons[event] || 'info-circle';
};

const getEventTone = (event: string) => {
    const tones: Record<string, 'success' | 'primary' | 'warning' | 'danger' | 'neutral'> = {
        created: 'success',
        updated: 'primary',
        deleted: 'danger',
        restored: 'success',
        change_status: 'warning',
        archived: 'neutral',
        unarchived: 'primary',
    };
    return tones[event] || 'neutral';
};

const formatChangeValue = (value: any): string => {
    if (value === null || value === undefined) return t('taskDetails.noValue');
    if (typeof value === 'boolean') return value ? 'tak' : 'nie';
    if (typeof value === 'object') return JSON.stringify(value);
    return String(value);
};

const isUserObject = (value: any): boolean => {
    return value && typeof value === 'object' && 'name' in value && 'email' in value;
};

const getFileIcon = (type: string): string => {
    if (!type) return 'file';
    if (type.startsWith('image/')) return 'image';
    if (type.startsWith('video/')) return 'video';
    if (type.includes('pdf')) return 'file-text';
    if (type.includes('zip') || type.includes('rar') || type.includes('7z')) return 'archive';
    if (type.includes('word') || type.includes('document')) return 'file-text';
    if (type.includes('excel') || type.includes('spreadsheet')) return 'table';
    return 'file';
};

const formatFileSize = (bytes: number): string => {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    if (bytes < 1024 * 1024 * 1024) return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    return (bytes / (1024 * 1024 * 1024)).toFixed(1) + ' GB';
};

const viewerConfig = computed<MarkdownEditorConfig>(() => ({
  features: {
    markdown: {
      headings: [1, 2, 3],
      links: true,
      lists: true,
      bold: true,
      italic: true,
      underline: true,
    },
    mentions: { enabled: true, users: [], trigger: '@' },
    variables: { enabled: true, variables: [], operationsCatalog: [] },
    ifBlock: { enabled: true },
    aiText: { enabled: true, labelsEnabled: false },
  },
}));

onMounted(() => {
    fetchChangelog();
});

// Refresh changelog when task changes (deep watch)
watch(() => props.task, (newVal, oldVal) => {
    if (newVal && oldVal && newVal.id === oldVal.id) {
        // Tylko jeśli to ten sam task (nie nowy task)
        fetchChangelog();
    }
}, { deep: true });
</script>

<template>
  <div class="flex h-full flex-1 flex-col">
    <!-- Sekcja informacji o tasku -->
    <div class="border-b border-border">
      <div class="flex flex-wrap items-start justify-between gap-6 p-6">
        <!-- Deadline -->
        <div class="flex items-start gap-3">
          <Icon name="calendar" size="sm" class="mt-0.5 text-muted-foreground" />
          <div>
            <div class="text-xs font-medium text-muted-foreground">{{ t('taskDetails.deadline') }}</div>
            <div class="text-sm">{{ formatDate(task.deadline) }}</div>
          </div>
        </div>

        <!-- Przypisana osoba -->
        <div class="flex items-start gap-3">
          <Icon name="user" size="sm" class="mt-0.5 text-muted-foreground" />
          <div>
            <div class="text-xs font-medium text-muted-foreground">{{ t('taskDetails.assignedUser') }}</div>
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
            <div class="text-xs font-medium text-muted-foreground">{{ t('taskDetails.createdBy') }}</div>
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
            <div class="text-xs font-medium text-muted-foreground">{{ t('taskDetails.description') }}</div>
            <div class="mt-2">
              <MarkdownViewer
                :value="task.description ?? null"
                :config="viewerConfig"
                :placeholder="t('taskDetails.noDescription')"
              />
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
    <div class="flex-1 overflow-y-auto p-6">
      <div v-if="activeTab === 'history'" class="space-y-4">
        <!-- Loading state -->
        <div v-if="loadingChangelog" class="space-y-3">
          <div v-for="i in 5" :key="i" class="flex gap-3">
            <Skeleton class="h-10 w-10 rounded-full" />
            <div class="flex-1 space-y-2">
              <Skeleton class="h-4 w-48" />
              <Skeleton class="h-3 w-full" />
            </div>
          </div>
        </div>

        <!-- Empty state -->
        <div v-else-if="changelog.length === 0" class="flex items-center justify-center py-12 text-muted-foreground">
          <div class="text-center">
            <Icon name="clock" size="lg" class="mx-auto mb-2 opacity-50" />
            <p class="text-sm">{{ t('taskDetails.noChanges') }}</p>
          </div>
        </div>

        <!-- Changelog timeline -->
        <div v-else class="relative space-y-4 pl-8">
          <!-- Vertical line -->
          <div class="absolute left-[19px] top-2 bottom-2 w-px bg-border" />

          <div
            v-for="entry in changelog"
            :key="entry.id"
            class="relative"
          >
            <!-- Timeline avatar (zamiast ikony) -->
            <div class="absolute left-[-32px] top-1">
              <Avatar 
                v-if="entry.causer" 
                :name="entry.causer.name" 
                size="md" 
                class="border-2 border-border"
              />
              <div v-else class="flex h-10 w-10 items-center justify-center rounded-full border-2 border-border bg-background">
                <Icon :name="getEventIcon(entry.event)" size="sm" />
              </div>
            </div>

            <!-- Changelog entry card -->
            <div class="rounded-lg border border-border bg-background p-4">
              <div class="mb-2 flex items-start justify-between gap-2">
                <div>
                  <Badge :tone="getEventTone(entry.event)" size="sm">
                    {{ t(entry.event_description) }}
                  </Badge>
                  <div v-if="entry.causer" class="mt-2 flex items-center gap-2 text-sm text-muted-foreground">
                    <span>{{ entry.causer.name }}</span>
                  </div>
                </div>
                <p class="text-xs text-muted-foreground whitespace-nowrap">
                  {{ formatDateTime(entry.created_at) }}
                </p>
              </div>

              <!-- Details (changes from trackers) -->
              <div v-if="Object.keys(entry.details || {}).length > 0" class="mt-3 space-y-3 border-t border-border pt-3">
                <div
                  v-for="(detail, key) in entry.details"
                  :key="key"
                  class="text-sm"
                >
                  <div class="mb-1 font-medium text-foreground">
                    {{ detail.field_translation_key ? t(detail.field_translation_key) : detail.field_label || key }}:
                  </div>
                  <div class="ml-3">
                    <!-- Status change (custom event) -->
                    <div v-if="detail.type === 'status_change'" class="flex items-center gap-2">
                      <Badge v-if="detail.before" :tone="detail.before.tone" size="sm">
                        <Icon :name="detail.before.icon" size="xs" class="mr-1" />
                        {{ detail.before.translation_key ? t(detail.before.translation_key) : detail.before.label }}
                      </Badge>
                      <Icon name="chevron-right" size="xs" class="text-muted-foreground" />
                      <Badge v-if="detail.after" :tone="detail.after.tone" size="sm">
                        <Icon :name="detail.after.icon" size="xs" class="mr-1" />
                        {{ detail.after.translation_key ? t(detail.after.translation_key) : detail.after.label }}
                      </Badge>
                    </div>
                    
                    <!-- Field type tracker -->
                    <div v-else-if="detail.type === 'field'">
                      <!-- Badge component render -->
                      <div v-if="detail.component === 'badge'" class="flex items-center gap-2">
                        <Badge v-if="detail.before" :tone="detail.before.tone" size="sm">
                          <Icon v-if="detail.before.icon" :name="detail.before.icon" size="xs" class="mr-1" />
                          {{ detail.before.translation_key ? t(detail.before.translation_key) : detail.before.label }}
                        </Badge>
                        <span v-else class="text-muted-foreground italic">{{ t('taskDetails.noValue') }}</span>
                        <Icon name="chevron-right" size="xs" class="text-muted-foreground" />
                        <Badge v-if="detail.after" :tone="detail.after.tone" size="sm">
                          <Icon v-if="detail.after.icon" :name="detail.after.icon" size="xs" class="mr-1" />
                          {{ detail.after.translation_key ? t(detail.after.translation_key) : detail.after.label }}
                        </Badge>
                        <span v-else class="text-muted-foreground italic">{{ t('taskDetails.noValue') }}</span>
                      </div>
                      <!-- User component render (assigned user) -->
                      <div v-else-if="isUserObject(detail.before) || isUserObject(detail.after)" class="flex items-center gap-2">
                        <div v-if="detail.before" class="flex items-center gap-2">
                          <Avatar :name="detail.before.name" :src="detail.before.avatar" size="xs" />
                          <span class="text-sm">{{ detail.before.name }}</span>
                        </div>
                        <span v-else class="text-muted-foreground italic text-sm">{{ t('taskDetails.noValue') }}</span>
                        <Icon name="chevron-right" size="xs" class="text-muted-foreground" />
                        <div v-if="detail.after" class="flex items-center gap-2">
                          <Avatar :name="detail.after.name" :src="detail.after.avatar" size="xs" />
                          <span class="text-sm">{{ detail.after.name }}</span>
                        </div>
                        <span v-else class="text-muted-foreground italic text-sm">{{ t('taskDetails.noValue') }}</span>
                      </div>
                      <!-- Text comparison (word-level diff) -->
                      <div v-else-if="detail.comparison" class="rounded-md bg-muted/30 p-3">
                        <span
                          v-for="(part, idx) in detail.comparison"
                          :key="idx"
                          :class="{
                            'text-danger bg-danger/10': part.type === 'removed',
                            'text-success bg-success/10': part.type === 'added',
                            'text-foreground': part.type === 'unchanged'
                          }"
                          class="rounded px-0.5"
                        >{{ part.text }}</span>
                      </div>
                      <!-- Regular field (fallback) -->
                      <div v-else class="flex items-center gap-2">
                        <span class="text-danger" :class="{ 'line-through': detail.after }">{{ formatChangeValue(detail.before) }}</span>
                        <Icon v-if="detail.before && detail.after" name="chevron-right" size="xs" class="text-muted-foreground" />
                        <span v-if="detail.after" class="text-success">{{ formatChangeValue(detail.after) }}</span>
                      </div>
                    </div>
                    
                    <!-- Bag type tracker -->
                    <div v-else-if="detail.type === 'bag'" class="space-y-2">
                      <!-- Attached items -->
                      <div v-if="detail.attached?.length > 0" class="space-y-1">
                        <div class="flex items-center gap-1 text-xs font-medium text-success">
                          <Icon name="plus" size="xs" />
                          <span>{{ t('taskDetails.added', '', { count: detail.attached.length }) }}</span>
                        </div>
                        <div class="ml-5 space-y-1">
                          <!-- Files -->
                          <div v-if="key === 'files'" v-for="item in detail.attached" :key="item.id" class="flex items-center gap-2 text-xs">
                            <Icon :name="getFileIcon(item.type)" size="xs" class="text-muted-foreground" />
                            <span class="font-medium">{{ item.name }}</span>
                            <span class="text-muted-foreground">({{ formatFileSize(item.size) }})</span>
                          </div>
                          <!-- Labels -->
                          <div v-else v-for="item in detail.attached" :key="item.id" class="flex items-center gap-2 text-xs">
                            <Icon v-if="item.icon" :name="item.icon" size="xs" class="text-muted-foreground" />
                            <Icon v-else name="tag" size="xs" class="text-muted-foreground" />
                            <Badge v-if="item.color" :style="{ backgroundColor: item.color }" size="xs">
                              <span class="text-white">{{ item.name }}</span>
                            </Badge>
                            <span v-else>{{ item.name }}</span>
                          </div>
                        </div>
                      </div>
                      <!-- Detached items -->
                      <div v-if="detail.detached?.length > 0" class="space-y-1">
                        <div class="flex items-center gap-1 text-xs font-medium text-danger">
                          <Icon name="x-circle" size="xs" />
                          <span>{{ t('taskDetails.removed', '', { count: detail.detached.length }) }}</span>
                        </div>
                        <div class="ml-5 space-y-1">
                          <!-- Files -->
                          <div v-if="key === 'files'" v-for="item in detail.detached" :key="item.id" class="flex items-center gap-2 text-xs">
                            <Icon :name="getFileIcon(item.type)" size="xs" class="text-muted-foreground" />
                            <span class="font-medium">{{ item.name }}</span>
                            <span class="text-muted-foreground">({{ formatFileSize(item.size) }})</span>
                          </div>
                          <!-- Labels -->
                          <div v-else v-for="item in detail.detached" :key="item.id" class="flex items-center gap-2 text-xs">
                            <Icon v-if="item.icon" :name="item.icon" size="xs" class="text-muted-foreground" />
                            <Icon v-else name="tag" size="xs" class="text-muted-foreground" />
                            <span>{{ item.name }}</span>
                          </div>
                        </div>
                      </div>
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
            <p class="text-sm">{{ t('taskDetails.formPreparing') }}</p>
          </div>
        </div>
      </div>

      <div v-if="activeTab === 'checklist'" class="space-y-4">
        <div class="flex items-center justify-center py-12 text-muted-foreground">
          <div class="text-center">
            <Icon name="check-square" size="lg" class="mx-auto mb-2 opacity-50" />
            <p class="text-sm">{{ t('taskDetails.checklistPreparing') }}</p>
          </div>
        </div>
      </div>

      <div v-if="activeTab === 'approval'" class="space-y-4">
        <div class="flex items-center justify-center py-12 text-muted-foreground">
          <div class="text-center">
            <Icon name="git-merge" size="lg" class="mx-auto mb-2 opacity-50" />
            <p class="text-sm">{{ t('taskDetails.approvalPreparing') }}</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
