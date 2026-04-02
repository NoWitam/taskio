<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from '@/composables/useI18n'
import type { FormSubmission, Form } from '@/types/forms'
import Icon from '@/components/ui/Icon.vue'
import Button from '@/components/ui/Button.vue'

interface Props {
    submission: FormSubmission
    form?: Form | null
    isTrashed?: boolean
}

interface Emits {
    (e: 'view', id: string): void
    (e: 'delete', id: string): void
    (e: 'restore', id: string): void
    (e: 'force-delete', id: string): void
}

const props = defineProps<Props>()
const emit = defineEmits<Emits>()
const { t } = useI18n()

const sourceLabel = computed(() => {
    if (props.submission.source === 'form') {
        return t('forms.manual')
    } else if (props.submission.source === 'task') {
        return t('forms.task')
    }
    return t('forms.unknown')
})

const sourceIcon = computed(() => {
    if (props.submission.source === 'form') {
        return 'file-text'
    } else if (props.submission.source === 'task') {
        return 'check-square'
    }
    return 'help-circle'
})

// Get creator initials for avatar fallback
const creatorInitials = computed(() => {
    const name = props.submission.creator?.name
    if (!name) return '?'
    const parts = name.split(' ')
    if (parts.length >= 2) {
        return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
    }
    return name.substring(0, 2).toUpperCase()
})
</script>

<template>
    <div 
        class="border rounded-lg p-4 transition-all group hover:shadow-md"
        :class="isTrashed 
            ? 'border-muted-foreground/20 bg-muted/50' 
            : 'border-border bg-background hover:border-primary/40'"
    >
        <!-- Header with creator -->
        <div class="flex items-start justify-between mb-3">
            <div class="flex items-center gap-3 flex-1 min-w-0">
                <!-- Creator Avatar -->
                <div 
                    class="shrink-0 w-10 h-10 rounded-full flex items-center justify-center font-medium text-sm"
                    :class="isTrashed 
                        ? 'bg-muted-foreground/20 text-muted-foreground' 
                        : 'bg-primary/10 text-primary'"
                >
                    {{ creatorInitials }}
                </div>
                
                <div class="flex-1 min-w-0">
                    <div class="font-medium text-sm truncate">
                        {{ submission.creator?.name || t('forms.anonymous') }}
                    </div>
                    <div class="flex items-center gap-1.5 mt-0.5">
                        <span class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-muted text-muted-foreground">
                            <Icon :name="sourceIcon" size="xs" />
                            {{ sourceLabel }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div v-if="!isTrashed" class="flex gap-1" @click.stop>
                <Button 
                    variant="ghost" 
                    size="sm" 
                    class="h-8 w-8 p-0 opacity-0 group-hover:opacity-100 transition-opacity"
                    @click="$emit('view', submission.id)"
                >
                    <Icon name="eye" size="sm" />
                </Button>
                <Button 
                    variant="ghost" 
                    size="sm" 
                    class="h-8 w-8 p-0 text-destructive hover:text-destructive opacity-0 group-hover:opacity-100 transition-opacity"
                    @click="$emit('delete', submission.id)"
                >
                    <Icon name="trash" size="sm" />
                </Button>
            </div>

            <!-- Trash Actions -->
            <div v-else class="flex gap-1" @click.stop>
                <Button 
                    variant="ghost" 
                    size="sm" 
                    class="h-8 w-8 p-0 opacity-0 group-hover:opacity-100 transition-opacity" 
                    @click="$emit('restore', submission.id)"
                >
                    <Icon name="arrow-up-circle" size="sm" />
                </Button>
                <Button 
                    variant="ghost" 
                    size="sm" 
                    class="h-8 w-8 p-0 text-destructive opacity-0 group-hover:opacity-100 transition-opacity" 
                    @click="$emit('force-delete', submission.id)"
                >
                    <Icon name="trash" size="sm" />
                </Button>
            </div>
        </div>

        <!-- Footer with approved date -->
        <div v-if="submission.approved_at" class="pt-3 border-t border-border">
            <div class="flex items-center gap-1.5 text-xs text-green-600 dark:text-green-500">
                <Icon name="check-circle" size="xs" />
                <span>{{ t('forms.approved') }}</span>
                <span class="text-muted-foreground">•</span>
                <span class="text-muted-foreground">
                    {{ new Date(submission.approved_at).toLocaleDateString() }}
                </span>
            </div>
        </div>
    </div>
</template>
