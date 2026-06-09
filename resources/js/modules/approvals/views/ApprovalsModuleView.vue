<script setup lang="ts">
import { computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PageHeader from '@/components/ui/patterns/PageHeader.vue'
import Icon from '@/components/ui/Icon.vue'
import Tabs from '@/components/ui/Tabs.vue'
import { useApprovalsStore } from '@/store/approvals'
import { useI18n } from '@/composables/useI18n'

const route = useRoute()
const router = useRouter()
const store = useApprovalsStore()
const { t } = useI18n()

onMounted(() => {
    store.fetchQueueCount()
})

const tabs = computed(() => [
    {
        id: 'queue',
        label: t('approvals.labels.queue'),
        icon: 'inbox',
        badge: store.queueCount > 0 ? store.queueCount : undefined,
    },
    {
        id: 'pipelines',
        label: t('approvals.labels.pipelines'),
        icon: 'workflow',
    },
])

const routeToTab: Record<string, string> = {
    'approvals.queue': 'queue',
    'approvals.pipelines': 'pipelines',
}

const tabToRoute: Record<string, string> = {
    queue: 'approvals.queue',
    pipelines: 'approvals.pipelines',
}

const tab = computed({
    get: () => routeToTab[route.name as string] ?? 'queue',
    set: (value: string) => {
        const routeName = tabToRoute[value]
        if (routeName && route.name !== routeName) {
            router.push({ name: routeName })
        }
    },
})
</script>

<template>
    <div class="h-full max-h-full min-h-0 flex flex-col gap-8 px-6 pt-6 overflow-hidden">
        <PageHeader
            :title="t('approvals.module_name')"
            :description="t('approvals.module_description')"
        >
            <template #icon>
                <span class="text-primary">
                    <Icon size="lg" name="workflow" />
                </span>
            </template>
        </PageHeader>

        <Tabs :tabs="tabs" v-model="tab" />

        <div class="flex-1 min-h-0">
            <RouterView />
        </div>
    </div>
</template>
