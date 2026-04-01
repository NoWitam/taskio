<script setup lang="ts">
import { ref } from 'vue'
import type { FormElementType, FormElementCategory } from '@/types/forms'
import { useI18n } from '@/composables/useI18n'
import Icon from '@/components/ui/Icon.vue'

const emit = defineEmits<{
    dragStart: [type: FormElementType]
}>()

const { t } = useI18n()

interface ElementInfo {
    type: FormElementType
    category: FormElementCategory
    label: string
    icon: string
}

const elementsByCategory: Record<FormElementCategory, ElementInfo[]> = {
    layout: [
        { type: 'section', category: 'layout', label: t('forms.elementTypes.section'), icon: 'layout-grid' },
        { type: 'grid', category: 'layout', label: t('forms.elementTypes.grid'), icon: 'columns' },
        { type: 'repeater', category: 'layout', label: t('forms.elementTypes.repeater'), icon: 'repeat' },
    ],
    content: [
        { type: 'heading', category: 'content', label: t('forms.elementTypes.heading'), icon: 'heading' },
        { type: 'text_block', category: 'content', label: t('forms.elementTypes.text_block'), icon: 'text' },
        { type: 'divider', category: 'content', label: t('forms.elementTypes.divider'), icon: 'minus' },
    ],
    input: [
        { type: 'short_text', category: 'input', label: t('forms.elementTypes.short_text'), icon: 'type' },
        { type: 'long_text', category: 'input', label: t('forms.elementTypes.long_text'), icon: 'file-text' },
        { type: 'select', category: 'input', label: t('forms.elementTypes.select'), icon: 'list' },
        { type: 'checklist', category: 'input', label: t('forms.elementTypes.checklist'), icon: 'list-check' },
        { type: 'number', category: 'input', label: t('forms.elementTypes.number'), icon: 'hash' },
        { type: 'date', category: 'input', label: t('forms.elementTypes.date'), icon: 'calendar' },
        { type: 'time', category: 'input', label: t('forms.elementTypes.time'), icon: 'clock' },
        { type: 'url', category: 'input', label: t('forms.elementTypes.url'), icon: 'link' },
        { type: 'image', category: 'input', label: t('forms.elementTypes.image'), icon: 'image' },
        { type: 'checkbox', category: 'input', label: t('forms.elementTypes.checkbox'), icon: 'check-square' },
    ],
}

const categories: { key: FormElementCategory; label: string }[] = [
    { key: 'layout', label: t('forms.categories.layout') },
    { key: 'content', label: t('forms.categories.content') },
    { key: 'input', label: t('forms.categories.input') },
]

const handleDragStart = (event: DragEvent, type: FormElementType) => {
    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'copy'
        event.dataTransfer.setData('text/plain', type)
    }
    emit('dragStart', type)
}
</script>

<template>
    <div class="space-y-6 p-4">
        <div v-for="category in categories" :key="category.key" class="space-y-2">
            <h4 class="text-xs font-semibold text-muted-foreground uppercase tracking-wide">
                {{ category.label }}
            </h4>
            
            <div class="space-y-1">
                <button
                    v-for="element in elementsByCategory[category.key]"
                    :key="element.type"
                    type="button"
                    class="w-full flex items-center gap-3 px-3 py-2 rounded-lg text-left transition-colors hover:bg-secondary border border-transparent hover:border-border cursor-grab active:cursor-grabbing"
                    draggable="true"
                    @dragstart="handleDragStart($event, element.type)"
                >
                    <Icon :name="element.icon" class="text-muted-foreground" size="sm" />
                    <span class="text-sm">{{ element.label }}</span>
                </button>
            </div>
        </div>
    </div>
</template>
