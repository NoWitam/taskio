<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import type { Form } from '@/types/forms'
import { useI18n } from '@/composables/useI18n'
import { useInfiniteScroll } from '@/composables/useInfiniteScroll'
import Icon from '@/components/ui/Icon.vue'
import Button from '@/components/ui/Button.vue'
import Card from '@/components/ui/Card.vue'
import Avatar from '@/components/ui/Avatar.vue'
import Badge from '@/components/ui/Badge.vue'
import DropdownMenu from '@/components/ui/DropdownMenu.vue'
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue'
import EmptyState from '@/components/ui/tables/EmptyState.vue'

const props = defineProps<{
    forms: Form[]
    loading: boolean
    hasMore: boolean
}>()

const emit = defineEmits<{
    loadMore: []
    edit: [id: string]
    delete: [id: string]
    restore: [id: string]
}>()

const router = useRouter()
const { t } = useI18n()

// Infinite scroll
const { triggerElement } = useInfiniteScroll(() => {
    if (props.hasMore && !props.loading) {
        emit('loadMore')
    }
})

// Actions
const openBuilder = (formId: string) => {
    emit('edit', formId)
}

const formatDate = (dateString: string): string => {
    const date = new Date(dateString)
    return date.toLocaleDateString('pl-PL', { 
        year: 'numeric', 
        month: 'long', 
        day: 'numeric' 
    })
}
</script>

<template>
    <div ref="scrollContainer" class="space-y-4">
        <!-- Empty state -->
        <EmptyState
            v-if="!loading && forms.length === 0"
            icon="file-text"
            :title="t('forms.noForms')"
            :description="t('forms.noFormsDescription')"
        />

        <!-- Forms grid -->
        <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <Card
                v-for="form in forms"
                :key="form.id"
                class="p-6 hover:shadow-md transition-shadow cursor-pointer"
                @click="openBuilder(form.id)"
            >
                <div class="flex items-start gap-4">
                    <!-- Icon -->
                    <div class="shrink-0">
                        <div class="w-12 h-12 rounded-lg bg-primary/10 flex items-center justify-center">
                            <Icon :name="form.icon || 'file-text'" size="lg" class="text-primary" />
                        </div>
                    </div>

                    <!-- Content -->
                    <div class="flex-1 min-w-0">
                        <h3 class="font-semibold text-lg truncate">{{ form.name }}</h3>
                        
                        <p
                            v-if="form.description"
                            class="mt-1 text-sm text-muted-foreground line-clamp-2"
                        >
                            {{ form.description }}
                        </p>

                        <!-- Stats -->
                        <div class="mt-3 flex items-center gap-3 text-xs text-muted-foreground">
                            <div class="flex items-center gap-1">
                                <Icon name="file-check" size="xs" />
                                <span>{{ form.submissions_count || 0 }} {{ t('forms.submissions') }}</span>
                            </div>

                            <div class="flex items-center gap-1">
                                <Icon name="calendar" size="xs" />
                                <span>{{ formatDate(form.created_at) }}</span>
                            </div>
                        </div>

                        <!-- Creator -->
                        <div v-if="form.creator" class="mt-3 flex items-center gap-2">
                            <span class="text-xs text-muted-foreground">{{ form.creator.name }}</span>
                        </div>
                    </div>

                    <!-- Actions menu -->
                    <DropdownMenu>
                        <template #trigger>
                            <Button
                                variant="ghost"
                                size="sm"
                                @click.stop
                            >
                                <Icon name="more-vertical" size="sm" />
                            </Button>
                        </template>

                        <template #items>
                            <button
                                type="button"
                                class="dropdown-item"
                                @click.stop="openBuilder(form.id)"
                            >
                                <Icon name="pencil" size="sm" />
                                {{ t('common.edit') }}
                            </button>

                            <div class="dropdown-divider"></div>

                            <button
                                type="button"
                                class="dropdown-item text-red-600"
                                @click.stop="emit('delete', form.id)"
                            >
                                <Icon name="trash" size="sm" />
                                {{ t('common.delete') }}
                            </button>
                        </template>
                    </DropdownMenu>
                </div>
            </Card>
        </div>

        <!-- Loading more -->
        <div v-if="loading" class="flex justify-center py-8">
            <LoadingSpinner />
        </div>

        <!-- Infinite scroll trigger -->
        <div ref="triggerElement" class="h-px"></div>
    </div>
</template>

<script lang="ts">
export default {
    name: 'FormsList'
}
</script>
