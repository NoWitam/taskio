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

// Source helpers
const sourceLabel = computed(() => {
    if (props.submission.source === 'form') return t('forms.manual')
    if (props.submission.source === 'task') return t('forms.task')
    return t('forms.unknown')
})

const sourceIcon = computed(() => {
    if (props.submission.source === 'form') return 'file-text'
    if (props.submission.source === 'task') return 'check-square'
    return 'help-circle'
})

// Creator initials for avatar
const creatorInitials = computed(() => {
    const name = props.submission.creator?.name
    if (!name) return '?'
    const parts = name.split(' ')
    if (parts.length >= 2) {
        return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
    }
    return name.substring(0, 2).toUpperCase()
})

// Submission state
type SubmissionState = 'indexed' | 'approved' | 'draft'

const currentState = computed<SubmissionState>(() => {
    if (props.submission.indexed_at) return 'indexed'
    if (props.submission.approved_at) return 'approved'
    return 'draft'
})

const stateConfig = {
    draft: {
        icon: 'pencil',
        label: 'forms.draft',
        borderClass: 'border-amber-500/40',
        textClass: 'text-amber-600 dark:text-amber-500',
        iconBgClass: 'bg-amber-500/10',
    },
    approved: {
        icon: 'check-circle',
        label: 'forms.approved',
        borderClass: 'border-emerald-500/40',
        textClass: 'text-emerald-600 dark:text-emerald-500',
        iconBgClass: 'bg-emerald-500/10',
    },
    indexed: {
        icon: 'database',
        label: 'forms.indexed',
        borderClass: 'border-blue-500/40',
        textClass: 'text-blue-600 dark:text-blue-500',
        iconBgClass: 'bg-blue-500/10',
    },
} as const

const activeConfig = computed(() => stateConfig[currentState.value])

const formatDateTime = (iso: string) => {
    const d = new Date(iso)
    return d.toLocaleDateString(undefined, { year: 'numeric', month: '2-digit', day: '2-digit' })
        + ' ' + d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', second: '2-digit' })
}

const currentStateDate = computed(() => {
    if (currentState.value === 'indexed') return formatDateTime(props.submission.indexed_at!)
    if (currentState.value === 'approved') return formatDateTime(props.submission.approved_at!)
    return formatDateTime(props.submission.created_at)
})

// Previous states shown smaller below the current one
const previousStates = computed(() => {
    const states: { key: SubmissionState; date: string }[] = []
    const current = currentState.value

    if (current === 'indexed' && props.submission.approved_at) {
        states.push({ key: 'approved', date: formatDateTime(props.submission.approved_at) })
    }

    if (current === 'indexed' || current === 'approved') {
        const createdDate = formatDateTime(props.submission.created_at)
        const approvedDate = props.submission.approved_at ? formatDateTime(props.submission.approved_at) : null
        if (createdDate !== approvedDate) {
            states.push({ key: 'draft', date: createdDate })
        }
    }

    return states
})
</script>

<template>
    <div 
        class="border rounded-lg p-4 transition-all group hover:shadow-md"
        :class="isTrashed 
            ? 'border-muted-foreground/20 bg-muted/50' 
            : [activeConfig.borderClass, 'bg-background']"
    >
        <!-- State section -->
        <div class="flex items-start justify-between">
            <div class="flex items-center gap-3 flex-1 min-w-0">
                <!-- State icon -->
                <div 
                    class="shrink-0 w-10 h-10 rounded-full flex items-center justify-center"
                    :class="isTrashed 
                        ? 'bg-muted-foreground/20 text-muted-foreground' 
                        : [activeConfig.iconBgClass, activeConfig.textClass]"
                >
                    <Icon :name="activeConfig.icon" size="sm" />
                </div>
                
                <div class="flex-1 min-w-0">
                    <!-- Current state + timestamp -->
                    <div class="flex items-center gap-2">
                        <span class="font-medium text-sm" :class="isTrashed ? 'text-muted-foreground' : activeConfig.textClass">
                            {{ t(activeConfig.label) }}
                        </span>
                        <span class="text-sm text-foreground">
                            {{ currentStateDate }}
                        </span>
                    </div>
                    
                    <!-- Previous states (smaller) -->
                    <div v-if="previousStates.length > 0" class="flex flex-col gap-0.5 mt-1">
                        <div 
                            v-for="prev in previousStates" 
                            :key="prev.key"
                            class="flex items-center gap-1.5 text-xs"
                            :class="stateConfig[prev.key].textClass"
                        >
                            <Icon :name="stateConfig[prev.key].icon" size="xs" />
                            <span>{{ t(stateConfig[prev.key].label) }}</span>
                            <span class="text-muted-foreground">{{ prev.date }}</span>
                        </div>
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
                    <Icon name="restore" size="sm" />
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

        <!-- Footer: creator + source -->
        <div class="pt-3 mt-3 border-t border-border flex items-center justify-between">
            <div class="flex items-center gap-2 min-w-0">
                <div 
                    class="shrink-0 w-6 h-6 rounded-full flex items-center justify-center text-[10px] font-medium"
                    :class="isTrashed 
                        ? 'bg-muted-foreground/20 text-muted-foreground' 
                        : 'bg-primary/10 text-primary'"
                >
                    {{ creatorInitials }}
                </div>
                <span class="text-sm text-foreground truncate">
                    {{ submission.creator?.name || t('forms.anonymous') }}
                </span>
            </div>
            <span class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-muted text-muted-foreground shrink-0">
                <Icon :name="sourceIcon" size="xs" />
                {{ sourceLabel }}
            </span>
        </div>
    </div>
</template>
