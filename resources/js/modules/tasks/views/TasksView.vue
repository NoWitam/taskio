<template>
<div class="h-full max-h-screen flex flex-col gap-8 px-6 pt-6">
    <PageHeader
        title="Zadania"
        description="Zarządzaj kolejką postów, edytorem i historią publikacji."
    >
        <template #icon>
            <span class="text-primary">
                <Icon size="lg" name="check-circle"></Icon>
            </span>
        </template>

        <template #actions>
            <Button variant="secondary">Import</Button>
            <Button variant="primary">Nowy post</Button>
        </template>

        <template #extra>
            <div class="flex flex-wrap gap-2">
                <Badge tone="primary">Connected: 3</Badge>
                <Badge tone="neutral">Queue: 12</Badge>
            </div>
        </template>
    </PageHeader>

    <div
        class="grid grid-cols-2 gap-4"
    >
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
        
        <div
            class="grid grid-cols-3 gap-4"
        >
            <SelectInput
                v-model="filters.priority"
                multiple
                :options="[
                    { label: 'Wszystkie', value: '' },
                    { label: 'Wysoki', value: 'high' },
                    { label: 'Średni', value: 'medium' },
                    { label: 'Niski', value: 'low' }
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
    </div>

    <Tabs :tabs v-model="tab" />

    <div
        class="w-full min-h-0 flex-1 flex gap-8"
    >
        <div 
            v-for="status in activeTab.statuses"
            class="w-full min-h-0 flex flex-col flex-1"
        >
            <TasksList 
                :status="status.key"
                :label="status.label"
                :filters="filters"
            />
        </div>
    </div>
</div>
</template>

<script setup lang="ts">
import PageHeader from '../../../components/ui/patterns/PageHeader.vue';
import Badge from '../../../components/ui/Badge.vue';
import Button from '../../../components/ui/Button.vue';
import Tabs from '../../../components/ui/Tabs.vue';
import TasksList from '../components/TasksList.vue';
import TextInput from '../../../components/ui/inputs/TextInput.vue';
import { computed, ref } from 'vue';
import Icon from '../../../components/ui/Icon.vue';
import SelectInput from '../../../components/ui/inputs/SelectInput.vue';

const tab = ref('main');
const filters = ref({
    search: '',
    priority: ''
});

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
</script>
