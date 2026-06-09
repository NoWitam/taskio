<script setup lang="ts">
import Icon from '@/components/ui/Icon.vue'
import Button from '@/components/ui/Button.vue'
import type { ApprovalPipelineListItem } from '@/types/approvals'
import { useI18n } from '@/composables/useI18n'

defineProps<{
    pipeline: ApprovalPipelineListItem
}>()

defineEmits<{
    edit: []
    delete: []
}>()

const { t } = useI18n()
</script>

<template>
    <div class="flex flex-col rounded-lg border border-border bg-card p-4 transition-colors hover:bg-muted/30">
        <!-- Header -->
        <div class="flex items-start gap-3">
            <div class="shrink-0 w-10 h-10 rounded-lg flex items-center justify-center bg-primary/10 text-primary">
                <Icon :name="pipeline.icon ?? 'workflow'" size="sm" />
            </div>
            <div class="flex-1 min-w-0">
                <h3 class="text-sm font-semibold truncate">{{ pipeline.name }}</h3>
                <p v-if="pipeline.description" class="text-xs text-muted-foreground truncate mt-0.5">
                    {{ pipeline.description }}
                </p>
            </div>
        </div>

        <!-- Stages flow -->
        <div class="flex flex-wrap items-center gap-1.5 mt-3 text-xs text-muted-foreground">
            <template v-for="(stage, idx) in pipeline.stages" :key="idx">
                <Icon v-if="idx > 0" name="arrow-right" size="xs" class="shrink-0 text-muted-foreground/50" />
                <span class="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 whitespace-nowrap">
                    <Icon :name="stage.icon ?? 'circle'" size="xs" />
                    {{ stage.name }}
                </span>
            </template>
        </div>

        <!-- Actions -->
        <div class="flex items-center gap-2 mt-3 pt-3 border-t border-border">
            <Button
                v-if="pipeline.can_be_edited"
                variant="ghost"
                size="sm"
                @click.stop="$emit('edit')"
            >
                <Icon name="pencil" size="xs" />
                {{ t('common.edit') }}
            </Button>
            <Button
                v-if="pipeline.can_be_deleted"
                variant="ghost"
                size="sm"
                class="text-danger hover:text-danger"
                @click.stop="$emit('delete')"
            >
                <Icon name="trash" size="xs" />
                {{ t('common.delete') }}
            </Button>
        </div>
    </div>
</template>
