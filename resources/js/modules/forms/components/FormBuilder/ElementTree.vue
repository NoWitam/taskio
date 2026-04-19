<script setup lang="ts">
import { computed } from 'vue'
import type { FormElement } from '@/types/forms'
import Icon from '@/components/ui/Icon.vue'

const props = defineProps<{
    elements: FormElement[]
    selectedId: string | null
    level?: number
}>()

const emit = defineEmits<{
    select: [id: string]
}>()

const currentLevel = computed(() => props.level || 0)

const getElementIcon = (type: string): string => {
    const icons: Record<string, string> = {
        section: 'layout-grid',
        grid: 'columns',
        repeater: 'repeat',
        heading: 'heading',
        text_block: 'text',
        divider: 'minus',
        short_text: 'type',
        long_text: 'file-text',
        select: 'list',
        checklist: 'list-check',
        number: 'hash',
        date: 'calendar',
        time: 'clock',
        url: 'link',
        image: 'image',
        checkbox: 'check-square',
    }
    return icons[type] || 'file'
}

const getElementLabel = (element: FormElement): string => {
    if (element.config.name) return element.config.name
    if (element.config.label) return element.config.label
    if (element.config.text) return element.config.text
    if (element.type === 'divider') return 'Separator'
    return element.type
}

const getChildren = (element: FormElement): FormElement[] => {
    if (element.type === 'section' || element.type === 'repeater') {
        return (element.config as any).children || []
    }
    if (element.type === 'grid') {
        return ((element.config as any).columns || []).map((col: any) => col.element).filter(Boolean)
    }
    return []
}

const getPaddingLeft = (level: number): string => {
    return `${level * 12 + 8}px`
}
</script>

<template>
    <div class="space-y-0.5">
        <div v-if="elements.length === 0 && currentLevel === 0" class="text-center py-8 text-muted-foreground text-sm">
            <Icon name="inbox" class="mx-auto mb-2 opacity-50" />
            <p>Brak elementów</p>
        </div>

        <div v-for="element in elements" :key="element.id">
            <button
                type="button"
                class="w-full flex items-center gap-2 px-2 py-1.5 rounded text-left text-sm transition-colors hover:bg-secondary"
                :class="{ 'bg-primary/10 text-primary': selectedId === element.id }"
                :style="{ paddingLeft: getPaddingLeft(currentLevel) }"
                @click="emit('select', element.id)"
            >
                <Icon :name="getElementIcon(element.type)" size="sm" class="shrink-0" />
                <span class="truncate">{{ getElementLabel(element) }}</span>
                <span v-if="getChildren(element).length > 0" class="ml-auto text-xs text-muted-foreground">
                    {{ getChildren(element).length }}
                </span>
            </button>
            
            <ElementTree
                v-if="getChildren(element).length > 0"
                :elements="getChildren(element)"
                :selected-id="selectedId"
                :level="currentLevel + 1"
                @select="emit('select', $event)"
            />
        </div>
    </div>
</template>
