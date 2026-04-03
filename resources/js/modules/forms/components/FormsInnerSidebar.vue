<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from '@/composables/useI18n'
import type { Form } from '@/types/forms'
import Icon from '@/components/ui/Icon.vue'
import Button from '@/components/ui/Button.vue'

interface Props {
    selectedForm?: Form | null
}

const props = defineProps<Props>()

const route = useRoute()
const router = useRouter()
const { t } = useI18n()

const navItems = computed(() => {
    if (!props.selectedForm) return []
    
    return [
        {
            name: 'forms.detail.preview',
            label: t('forms.preview'),
            icon: 'eye',
            to: { name: 'forms.detail.preview', params: { formId: props.selectedForm.id } }
        },
        {
            name: 'forms.detail.submissions',
            label: t('forms.submissions'),
            icon: 'inbox',
            to: { name: 'forms.detail.submissions', params: { formId: props.selectedForm.id } }
        },
        {
            name: 'forms.detail.reports',
            label: t('forms.reports'),
            icon: 'chart-bar',
            to: { name: 'forms.detail.reports', params: { formId: props.selectedForm.id } }
        }
    ]
})

const isRouteActive = (routeName: string) => {
    return route.name === routeName
}

const goToList = () => {
    router.push({ name: 'forms.list' })
}
</script>

<template>
    <div class="w-64 h-full border-r border-border bg-background flex flex-col">
        <!-- Back to List -->
        <div v-if="selectedForm" class="p-4 border-b border-border">
            <Button 
                variant="ghost" 
                size="sm" 
                class="w-full justify-start"
                @click="goToList"
            >
                <Icon name="arrow-left" size="sm" />
                {{ t('forms.backToList') }}
            </Button>
        </div>

        <!-- Selected Form Info -->
        <div v-if="selectedForm" class="p-4 border-b border-border">
            <div class="flex items-start gap-3">
                <div 
                    class="shrink-0 w-10 h-10 rounded-lg flex items-center justify-center"
                    :class="selectedForm.is_enabled ? 'bg-primary/10 text-primary' : 'bg-muted text-muted-foreground'"
                >
                    <Icon 
                        :name="selectedForm.icon || 'file-text'" 
                        size="lg" 
                    />
                </div>
                <div class="flex-1 min-w-0">
                    <h3 class="font-medium text-sm truncate">
                        {{ selectedForm.name }}
                    </h3>
                    <p v-if="selectedForm.description" class="text-xs text-muted-foreground mt-1 line-clamp-2">
                        {{ selectedForm.description }}
                    </p>
                    <div class="flex items-center gap-2 mt-2">
                        <span 
                            class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full"
                            :class="selectedForm.is_enabled 
                                ? 'bg-green-500/10 text-green-600 dark:text-green-500' 
                                : 'bg-muted text-muted-foreground'"
                        >
                            <span class="w-1.5 h-1.5 rounded-full" 
                                :class="selectedForm.is_enabled ? 'bg-green-600 dark:bg-green-500' : 'bg-muted-foreground'"
                            ></span>
                            {{ selectedForm.is_enabled ? t('forms.enabled') : t('forms.disabled') }}
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- No Selection State -->
        <div v-else class="p-4 border-b border-border">
            <div class="flex items-center gap-3 text-muted-foreground">
                <div class="shrink-0 w-10 h-10 rounded-lg bg-muted flex items-center justify-center">
                    <Icon name="file-text" size="lg" />
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm">
                        {{ t('forms.noFormSelected') }}
                    </p>
                </div>
            </div>
        </div>

        <!-- Navigation Items -->
        <nav class="flex-1 overflow-y-auto p-2" :class="{ 'opacity-50 pointer-events-none': !selectedForm }">
            <RouterLink
                v-for="item in navItems"
                :key="item.name"
                :to="item.to"
                class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition-colors mb-1"
                :class="isRouteActive(item.name)
                    ? 'bg-primary/10 text-primary font-medium'
                    : 'text-muted-foreground hover:bg-muted hover:text-foreground'"
            >
                <Icon :name="item.icon" size="sm" />
                {{ item.label }}
            </RouterLink>
        </nav>
    </div>
</template>
