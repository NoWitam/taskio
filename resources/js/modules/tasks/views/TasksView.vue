<template>
<div class="flex flex-col gap-8">
    <PageHeader
        title="Publishing"
        description="Zarządzaj kolejką postów, edytorem i historią publikacji."
    >
        <template #icon>
            ICON
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

    <StatsGrid class="mt-2">
        <StatCard label="Queue" :value="12" delta="+3" deltaTone="success" />
        <StatCard label="Scheduled" :value="8" />
        <StatCard label="Published" :value="103" delta="+12%" deltaTone="success" />
        <StatCard label="Errors" :value="1" delta="!" deltaTone="danger" />
    </StatsGrid>

    <Tabs :tabs v-model="tab">
        <template #before>
            XDD
        </template>

        <template #panel="{ activeId }">
            <div class="rounded-xl border border-border bg-background p-4">
                Dynamic
            </div>
        </template>

        <template #panel-archive>
            <div class="rounded-xl border border-border bg-background p-4">
                Main
            </div>
        </template>
    </Tabs>
</div>
</template>

<script setup>
import PageHeader from '../../../components/ui/patterns/PageHeader.vue';
import Badge from '../../../components/ui/Badge.vue';
import Button from '../../../components/ui/Button.vue';
import StatsGrid from '../../../components/ui/patterns/StatsGrid.vue';
import StatCard from '../../../components/ui/patterns/StatCard.vue';
import Tabs from '../../../components/ui/Tabs.vue';
import { ref } from 'vue';

const tab = ref('main');

const tabs = [
    { id: "main", label: "All"},
    { id: "archive", label: "Archive" },
    { id: "trash", label: "Trash"},
];
</script>
