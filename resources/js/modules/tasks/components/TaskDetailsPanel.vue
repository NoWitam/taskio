<script setup lang="ts">
import { ref } from 'vue';
import type { Task } from '@/store/tasks';
import Icon from '@/components/ui/Icon.vue';
import Avatar from '@/components/ui/Avatar.vue';
import Tabs from '@/components/ui/Tabs.vue';

const props = defineProps<{
    task: Task;
}>();

const activeTab = ref('history');

const tabs = [
    { id: 'history', label: 'Historia', icon: 'clock' },
    { id: 'form', label: 'Formularz', icon: 'file-text' },
    { id: 'checklist', label: 'Checklista', icon: 'check-square' },
    { id: 'approval', label: 'Lejek zatwierdzenia', icon: 'git-merge' },
];

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
        <div class="flex items-center justify-center py-12 text-muted-foreground">
          <div class="text-center">
            <Icon name="clock" size="lg" class="mx-auto mb-2 opacity-50" />
            <p class="text-sm">Historia zmian w przygotowaniu</p>
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
