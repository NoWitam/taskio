<script setup lang="ts">
import { ref, computed } from 'vue'
import type { FormElement } from '@/types/forms'
import { useI18n } from '@/composables/useI18n'
import Icon from '@/components/ui/Icon.vue'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'

const { t } = useI18n()

const props = defineProps<{
    element: FormElement
    isSelected: boolean
    selectedElementId?: string | null
    readonly?: boolean
    isInGridColumn?: boolean
}>()

const emit = defineEmits<{
    select: [elementId: string]
    delete: [elementId: string]
    duplicate: [elementId: string]
    dragStart: []
    dragEnd: []
    dropBefore: []
    dropAfter: []
    dropToSection: [parentId: string, index: number]
    dropToRepeater: [parentId: string, index: number]
    dropToGridColumn: [parentId: string, columnIndex: number]
    reorderGridColumn: [gridId: string, fromIndex: number, toIndex: number]
}>()

const elementIcon = computed(() => {
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
    return icons[props.element.type] || 'file'
})

const elementLabel = computed(() => {
    const config = props.element.config as any
    if (config.name) return config.name
    if (config.label) return config.label
    if (config.text) return config.text
    if (props.element.type === 'divider') return 'Separator'
    return props.element.type
})

const elementCategory = computed(() => {
    const layoutTypes = ['section', 'grid', 'repeater']
    const contentTypes = ['heading', 'text_block', 'divider']
    
    if (layoutTypes.includes(props.element.type)) return 'layout'
    if (contentTypes.includes(props.element.type)) return 'content'
    return 'input'
})

const categoryColor = computed(() => {
    switch (elementCategory.value) {
        case 'layout': return 'primary'
        case 'content': return 'warning'
        case 'input': return 'success'
        default: return 'neutral'
    }
})

const hasChildren = computed(() => {
    const config = props.element.config as any
    if (props.element.type === 'section' || props.element.type === 'repeater') {
        return config.children && config.children.length > 0
    }
    if (props.element.type === 'grid') {
        return config.columns && config.columns.length > 0
    }
    return false
})

const gridTemplateColumns = computed(() => {
    if (props.element.type === 'grid' && props.element.config.columns) {
        return props.element.config.columns
            .map((col: any) => `${col.width}fr`)
            .join(' ')
    }
    return ''
})

const isCollapsed = ref(false)

const toggleCollapse = () => {
    isCollapsed.value = !isCollapsed.value
}

const draggedColumnIndex = ref<number | null>(null)

const handleColumnDragStart = (event: DragEvent, columnIndex: number) => {
    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move'
        event.dataTransfer.setData('columnIndex', columnIndex.toString())
    }
    draggedColumnIndex.value = columnIndex
}

const handleColumnDragEnd = () => {
    draggedColumnIndex.value = null
}

const handleDragStart = (event: DragEvent) => {
    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move'
    }
    emit('dragStart')
}

const handleDragOver = (event: DragEvent, position: 'before' | 'after') => {
    event.preventDefault()
    if (event.dataTransfer) {
        event.dataTransfer.dropEffect = 'move'
    }
}

const handleDrop = (event: DragEvent, position: 'before' | 'after') => {
    event.preventDefault()
    event.stopPropagation()
    
    if (position === 'before') {
        emit('dropBefore')
    } else {
        emit('dropAfter')
    }
}

const handleDropToContainer = (event: DragEvent, index: number) => {
    event.preventDefault()
    event.stopPropagation()
    
    if (props.element.type === 'section') {
        emit('dropToSection', props.element.id, index)
    } else if (props.element.type === 'repeater') {
        emit('dropToRepeater', props.element.id, index)
    }
}

const handleColumnDrop = (event: DragEvent, targetIndex: number) => {
    event.preventDefault()
    event.stopPropagation()
    
    // Check if we're dragging a column (reorder) or an element (add to column)
    const columnIndexData = event.dataTransfer?.getData('columnIndex')
    
    if (columnIndexData && columnIndexData !== '') {
        // Reordering column
        const fromIndex = parseInt(columnIndexData)
        if (fromIndex !== targetIndex) {
            emit('reorderGridColumn', props.element.id, fromIndex, targetIndex)
        }
        draggedColumnIndex.value = null
    } else {
        // Adding element to column
        emit('dropToGridColumn', props.element.id, targetIndex)
    }
}

const handleDropToGridColumn = (event: DragEvent, columnIndex: number) => {
    event.preventDefault()
    event.stopPropagation()
    emit('dropToGridColumn', props.element.id, columnIndex)
}
</script>

<template>
    <div class="relative" :data-element-id="element.id">
        <!-- Drop zone before -->
        <div
            v-if="!readonly"
            class="h-2 -mt-1 mb-1 opacity-0 hover:opacity-100 transition-opacity"
            @dragover="handleDragOver($event, 'before')"
            @drop="handleDrop($event, 'before')"
        >
            <div class="h-0.5 bg-primary rounded"></div>
        </div>

        <!-- Element card -->
        <div
            class="group relative rounded-lg border transition-all"
            :class="[
                isSelected 
                    ? 'border-primary bg-primary/5 shadow-sm' 
                    : 'border-border bg-background hover:border-border/80 hover:shadow-sm',
            ]"
        >
            <!-- Header -->
            <div
                class="flex items-start gap-3 p-3 cursor-pointer"
                :draggable="!readonly && !isInGridColumn"
                @dragstart="handleDragStart"
                @dragend="emit('dragEnd')"
                @click="emit('select', element.id)"
            >
                <!-- Drag handle -->
                <div 
                    v-if="!readonly && !isInGridColumn"
                    class="mt-0.5 text-muted-foreground cursor-grab active:cursor-grabbing opacity-0 group-hover:opacity-100 transition-opacity"
                >
                    <Icon name="grip-vertical" size="sm" />
                </div>

                <!-- Element icon -->
                <div class="mt-0.5 text-muted-foreground">
                    <Icon :name="elementIcon" size="sm" />
                </div>

                <!-- Element info -->
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2">
                        <span class="font-medium text-sm truncate">{{ elementLabel }}</span>
                        <Badge :tone="categoryColor" size="sm">
                            {{ element.type }}
                        </Badge>
                    </div>
                    
                    <div v-if="element.config.description || element.config.hint" class="mt-1 text-xs text-muted-foreground truncate">
                        {{ element.config.description || element.config.hint }}
                    </div>
                </div>

                <!-- Collapse toggle for Section/Repeater -->
                <Button
                    v-if="!readonly && (element.type === 'section' || element.type === 'repeater')"
                    variant="ghost"
                    size="sm"
                    class="opacity-100"
                    @click.stop="toggleCollapse"
                >
                    <Icon :name="isCollapsed ? 'chevron-down' : 'chevron-up'" size="sm" />
                </Button>

                <!-- Actions -->
                <div v-if="!readonly" class="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                    <Button
                        v-if="!isInGridColumn"
                        variant="ghost"
                        size="sm"
                        @click.stop="emit('duplicate', element.id)"
                    >
                        <Icon name="copy" size="sm" />
                    </Button>
                    
                    <Button
                        variant="ghost"
                        size="sm"
                        @click.stop="emit('delete', element.id)"
                    >
                        <Icon name="trash" size="sm" />
                    </Button>
                </div>
            </div>

            <!-- Grid columns content -->
            <div v-if="element.type === 'grid' && element.config.columns" class="px-3 pb-3 pt-0">
                <div class="flex gap-2">
                    <div
                        v-for="(column, colIndex) in element.config.columns"
                        :key="colIndex"
                        class="rounded border-2 border-dashed border-border p-2 min-h-24 transition-colors relative flex-1"
                        :class="{
                            'hover:border-primary/50 hover:bg-primary/5': !readonly,
                            'opacity-50': draggedColumnIndex === colIndex
                        }"
                        :draggable="!readonly"
                        @dragstart="handleColumnDragStart($event, Number(colIndex))"
                        @dragend="handleColumnDragEnd"
                        @dragover.prevent
                        @drop="handleColumnDrop($event, Number(colIndex))"
                    >
                        <!-- Column drag handle -->
                        <div
                            v-if="!readonly"
                            class="absolute top-1 left-1/2 transform -translate-x-1/2 text-muted-foreground cursor-grab active:cursor-grabbing opacity-0 hover:opacity-100 transition-opacity"
                        >
                            <Icon name="grip-horizontal" size="xs" />
                        </div>

                        <div class="text-xs font-medium text-muted-foreground mb-2 text-center mt-3">
                            {{ column.width }}%
                        </div>
                        
                        <!-- Existing element in column -->
                        <div v-if="column.element" class="space-y-2">
                            <ElementRenderer
                                :element="column.element"
                                :is-selected="props.selectedElementId === column.element.id"
                                :selected-element-id="selectedElementId"
                                :readonly="readonly"
                                :is-in-grid-column="true"
                                @select="(id) => emit('select', id)"
                                @delete="(id) => emit('delete', id)"
                                @duplicate="(id) => emit('duplicate', id)"
                                @drag-start="emit('dragStart')"
                                @drag-end="emit('dragEnd')"
                                @drop-to-section="(pid, idx) => emit('dropToSection', pid, idx)"
                                @drop-to-repeater="(pid, idx) => emit('dropToRepeater', pid, idx)"
                                @drop-to-grid-column="(pid, cidx) => emit('dropToGridColumn', pid, cidx)"
                                @reorder-grid-column="(gid, from, to) => emit('reorderGridColumn', gid, from, to)"
                            />
                        </div>
                        
                        <!-- Empty column placeholder -->
                        <div v-else class="flex items-center justify-center text-xs text-muted-foreground h-full">
                            {{ t('forms.dropHere') }}
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section/Repeater children content -->
            <div 
                v-if="!isCollapsed && (element.type === 'section' || element.type === 'repeater') && element.config.children"
                class="px-3 pb-3 pt-0 border-t border-border/50 mt-2"
            >
                <div class="space-y-2 pl-6">
                    <!-- Children -->
                    <template v-if="element.config.children.length > 0">
                        <ElementRenderer
                            v-for="(child, childIndex) in element.config.children"
                            :key="child.id"
                            :element="child"
                            :is-selected="props.selectedElementId === child.id"
                            :selected-element-id="selectedElementId"
                            :readonly="readonly"
                            @select="(id) => emit('select', id)"
                            @delete="(id) => emit('delete', id)"
                            @duplicate="(id) => emit('duplicate', id)"
                            @drag-start="emit('dragStart')"
                            @drag-end="emit('dragEnd')"
                            @drop-before="handleDropToContainer($event, Number(childIndex))"
                            @drop-after="handleDropToContainer($event, Number(childIndex) + 1)"
                            @drop-to-section="(pid, idx) => emit('dropToSection', pid, idx)"
                            @drop-to-repeater="(pid, idx) => emit('dropToRepeater', pid, idx)"
                            @drop-to-grid-column="(pid, cidx) => emit('dropToGridColumn', pid, cidx)"
                            @reorder-grid-column="(gid, from, to) => emit('reorderGridColumn', gid, from, to)"
                        />
                    </template>

                    <!-- Drop zone for empty container -->
                    <div
                        v-if="!readonly"
                        class="h-16 rounded border-2 border-dashed border-border flex items-center justify-center transition-colors hover:border-primary/50 hover:bg-primary/5"
                        @dragover.prevent
                        @drop="handleDropToContainer($event, element.config.children.length)"
                    >
                        <p class="text-xs text-muted-foreground">
                            {{ t('forms.dropHere') }}
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Drop zone after -->
        <div
            v-if="!readonly"
            class="h-2 mt-1 -mb-1 opacity-0 hover:opacity-100 transition-opacity"
            @dragover="handleDragOver($event, 'after')"
            @drop="handleDrop($event, 'after')"
        >
            <div class="h-0.5 bg-primary rounded"></div>
        </div>
    </div>
</template>
