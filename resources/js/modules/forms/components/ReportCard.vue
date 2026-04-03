<script setup lang="ts">
import { computed } from 'vue'
import type { FormReport } from '@/types/forms'
import Icon from '@/components/ui/Icon.vue'
import Button from '@/components/ui/Button.vue'

interface Props {
    report: FormReport
    isTrashed?: boolean
}

interface Emits {
    (e: 'view', id: string): void
    (e: 'delete', id: string): void
    (e: 'restore', id: string): void
}

const props = defineProps<Props>()
const emit = defineEmits<Emits>()

// Get creator initials for avatar fallback
const creatorInitials = computed(() => {
    const name = props.report.creator?.name
    if (!name) return '?'
    const parts = name.split(' ')
    if (parts.length >= 2) {
        return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
    }
    return name.substring(0, 2).toUpperCase()
})

const statusBadgeClass = computed(() => {
    if (props.report.is_completed) {
        return 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400'
    }
    return 'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-400'
})

const statusIcon = computed(() => {
    return props.report.is_completed ? 'check-circle' : 'clock'
})
</script>

<template>
    <div 
        class="border rounded-lg p-4 transition-all group hover:shadow-md"
        :class="isTrashed 
            ? 'border-muted-foreground/20 bg-muted/50' 
            : 'border-border bg-background hover:border-primary/40'"
    >
        <!-- Header with status icon and name -->
        <div class="flex items-start justify-between mb-3">
            <div class="flex items-start gap-2 flex-1 min-w-0">
                <div 
                    class="shrink-0 w-7 h-7 rounded-full flex items-center justify-center mt-0.5"
                    :class="statusBadgeClass"
                >
                    <Icon :name="statusIcon" size="sm" />
                </div>
                <h4 class="font-semibold text-base truncate pt-0.5">
                    {{ report.name }}
                </h4>
            </div>

            <!-- Action Buttons -->
            <div v-if="!isTrashed" class="flex gap-1" @click.stop>
                <Button 
                    v-if="report.is_completed"
                    variant="ghost" 
                    size="sm" 
                    class="h-8 w-8 p-0 opacity-0 group-hover:opacity-100 transition-opacity"
                    @click="$emit('view', report.id)"
                >
                    <Icon name="eye" size="sm" />
                </Button>
                <Button 
                    variant="ghost" 
                    size="sm" 
                    class="h-8 w-8 p-0 text-destructive hover:text-destructive opacity-0 group-hover:opacity-100 transition-opacity"
                    @click="$emit('delete', report.id)"
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
                    @click="$emit('restore', report.id)"
                >
                    <Icon name="arrow-up-circle" size="sm" />
                </Button>
            </div>
        </div>

        <!-- Body with guidelines and metadata -->
        <div class="space-y-2 mb-3">
            <!-- Guidelines (always 2 lines) -->
            <p class="text-sm text-muted-foreground line-clamp-2 min-h-[2.5rem]">
                {{ report.guidelines }}
            </p>

            <!-- Date range and Sources in one line -->
            <div class="flex items-center gap-3 text-xs text-muted-foreground flex-wrap">
                <!-- Date range first -->
                <div class="flex items-center gap-1.5">
                    <Icon name="calendar" size="xs" />
                    <span>{{ report.date_range }}</span>
                </div>
                
                <!-- Sources -->
                <div class="flex items-center gap-1.5 flex-wrap">
                    <span 
                        v-for="(source, index) in report.sources_formatted" 
                        :key="index"
                        class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-muted text-muted-foreground"
                    >
                        <Icon :name="source.includes('Ręczne') ? 'file-text' : 'check-square'" size="xs" />
                        {{ source }}
                    </span>
                </div>
            </div>
        </div>

        <!-- Footer with creator and date -->
        <div class="pt-3 border-t border-border">
            <div class="flex items-center justify-between text-xs text-muted-foreground">
                <div class="flex items-center gap-2">
                    <div 
                        class="shrink-0 w-6 h-6 rounded-full flex items-center justify-center font-medium text-xs"
                        :class="isTrashed 
                            ? 'bg-muted-foreground/20 text-muted-foreground' 
                            : 'bg-primary/10 text-primary'"
                    >
                        {{ creatorInitials }}
                    </div>
                    <span>{{ report.creator?.name }}</span>
                </div>
                <span>
                    {{ new Date(report.created_at).toLocaleDateString() }}
                </span>
            </div>
        </div>
    </div>
</template>
