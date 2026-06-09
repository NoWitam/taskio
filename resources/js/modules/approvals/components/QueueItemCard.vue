<script setup lang="ts">
import Icon from '@/components/ui/Icon.vue'
import type { ApprovalQueueItem } from '@/types/approvals'

defineProps<{
    item: ApprovalQueueItem
}>()

defineEmits<{
    click: []
}>()
</script>

<template>
    <div
        class="group flex items-start gap-4 rounded-lg border border-border bg-card p-4 transition-colors hover:bg-muted/50 cursor-pointer"
        @click="$emit('click')"
    >
        <!-- Entity icon -->
        <div class="shrink-0 w-10 h-10 rounded-lg flex items-center justify-center bg-primary/10 text-primary">
            <Icon :name="item.entity?.type_icon ?? 'file'" size="sm" />
        </div>

        <!-- Content -->
        <div class="flex-1 min-w-0">
            <span class="text-xs text-muted-foreground">{{ item.entity?.type_label }}</span>
            <h3 class="text-sm font-medium truncate mt-0.5">{{ item.entity?.name ?? '—' }}</h3>
            <p v-if="item.entity?.description" class="text-xs text-muted-foreground line-clamp-2 mt-0.5">
                {{ item.entity.description }}
            </p>

            <!-- Pipeline / Stage info -->
            <div class="flex items-center gap-3 mt-2 text-xs text-muted-foreground">
                <span v-if="item.pipeline" class="flex items-center gap-1">
                    <Icon :name="item.pipeline.icon ?? 'workflow'" size="xs" />
                    {{ item.pipeline.name }}
                </span>
                <span v-if="item.stage" class="flex items-center gap-1">
                    <Icon name="arrow-right" size="xs" />
                    {{ item.stage.name }}
                </span>
            </div>
        </div>

        <!-- Arrow -->
        <div class="shrink-0 self-center text-muted-foreground group-hover:text-foreground transition-colors">
            <Icon name="chevron-right" size="sm" />
        </div>
    </div>
</template>
