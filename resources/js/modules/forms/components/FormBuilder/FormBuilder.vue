<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import type { FormElement, FormElementType } from '@/types/forms'
import { useI18n } from '@/composables/useI18n'
import Button from '@/components/ui/Button.vue'
import Icon from '@/components/ui/Icon.vue'
import ElementPalette from './ElementPalette.vue'
import ElementTree from './ElementTree.vue'
import ElementEditor from './ElementEditor.vue'
import ElementRenderer from './ElementRenderer.vue'

const props = withDefaults(defineProps<{
    formId?: string
    initialElements?: FormElement[]
    readonly?: boolean
    embedded?: boolean
}>(), {
    readonly: false,
    embedded: false,
    initialElements: () => []
})

const emit = defineEmits<{
    save: [elements: FormElement[]]
}>()

const { t } = useI18n()

// State
const elements = ref<FormElement[]>(props.initialElements || [])
const selectedElementId = ref<string | null>(null)
const isDragging = ref(false)
const draggedElementType = ref<FormElementType | null>(null)
const draggedElement = ref<FormElement | null>(null)

// Computed
const selectedElement = computed(() => {
    const findElement = (els: FormElement[]): FormElement | null => {
        for (const el of els) {
            if (el.id === selectedElementId.value) return el
            
            // Check children in sections and repeaters
            if (el.type === 'section' || el.type === 'repeater') {
                const children = (el.config as any).children
                if (children) {
                    const found = findElement(children)
                    if (found) return found
                }
            }
            
            // Check grid columns
            if (el.type === 'grid') {
                const columns = (el.config as any).columns || []
                for (const col of columns) {
                    if (col.element) {
                        const found = findElement([col.element])
                        if (found) return found
                    }
                }
            }
        }
        return null
    }
    
    return findElement(elements.value)
})

const hasElements = computed(() => elements.value.length > 0)

// Generate unique ID for new elements
const generateId = (): string => {
    return `el_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`
}

// Element templates
const createElementTemplate = (type: FormElementType): FormElement => {
    const id = generateId()
    
    switch (type) {
        case 'section':
            return {
                id,
                type: 'section',
                config: {
                    name: 'Nowa sekcja',
                    icon: 'layout-grid',
                    description: '',
                    children: []
                }
            }
        case 'grid':
            return {
                id,
                type: 'grid',
                config: {
                    columns: []
                }
            }
        case 'repeater':
            return {
                id,
                type: 'repeater',
                config: {
                    name: 'Powtórzenia',
                    icon: 'repeat',
                    description: '',
                    min: 1,
                    max: 5,
                    children: []
                }
            }
        case 'heading':
            return {
                id,
                type: 'heading',
                config: {
                    level: 2,
                    text: 'Nagłówek'
                }
            }
        case 'text_block':
            return {
                id,
                type: 'text_block',
                config: {
                    content: 'Wprowadź tekst...'
                }
            }
        case 'divider':
            return {
                id,
                type: 'divider',
                config: {}
            }
        case 'short_text':
            return {
                id,
                type: 'short_text',
                config: {
                    label: 'Krótki tekst',
                    placeholder: '',
                    hint: '',
                    required: false,
                    minLength: undefined,
                    maxLength: undefined
                }
            }
        case 'long_text':
            return {
                id,
                type: 'long_text',
                config: {
                    label: 'Długi tekst',
                    placeholder: '',
                    hint: '',
                    required: false,
                    rows: 4,
                    minLength: undefined,
                    maxLength: undefined
                }
            }
        case 'select':
            return {
                id,
                type: 'select',
                config: {
                    label: 'Lista wyboru',
                    placeholder: 'Wybierz opcję',
                    hint: '',
                    required: false,
                    multiple: false,
                    options: []
                }
            }
        case 'checklist':
            return {
                id,
                type: 'checklist',
                config: {
                    label: 'Checklista',
                    hint: '',
                    required: false,
                    options: []
                }
            }
        case 'number':
            return {
                id,
                type: 'number',
                config: {
                    label: 'Liczba',
                    placeholder: '',
                    hint: '',
                    required: false,
                    min: undefined,
                    max: undefined,
                    step: 1
                }
            }
        case 'date':
            return {
                id,
                type: 'date',
                config: {
                    label: 'Data',
                    placeholder: '',
                    hint: '',
                    required: false,
                    min: undefined,
                    max: undefined
                }
            }
        case 'time':
            return {
                id,
                type: 'time',
                config: {
                    label: 'Czas',
                    placeholder: '',
                    hint: '',
                    required: false
                }
            }
        case 'url':
            return {
                id,
                type: 'url',
                config: {
                    label: 'URL',
                    placeholder: 'https://',
                    hint: '',
                    required: false
                }
            }
        case 'image':
            return {
                id,
                type: 'image',
                config: {
                    label: 'Obraz',
                    hint: '',
                    required: false,
                    maxSize: 5,
                    acceptedTypes: ['image/jpeg', 'image/png', 'image/gif', 'image/webp']
                }
            }
        case 'checkbox':
            return {
                id,
                type: 'checkbox',
                config: {
                    label: 'Checkbox',
                    hint: ''
                }
            }
        default:
            throw new Error(`Unknown element type: ${type}`)
    }
}

// Drag & Drop handlers
const onPaletteElementDragStart = (type: FormElementType) => {
    isDragging.value = true
    draggedElementType.value = type
    draggedElement.value = null
}

const onCanvasElementDragStart = (element: FormElement) => {
    isDragging.value = true
    draggedElement.value = element
    draggedElementType.value = null
}

const onDragEnd = () => {
    isDragging.value = false
    draggedElementType.value = null
    draggedElement.value = null
}

const onDropToCanvas = (index: number) => {
    if (draggedElementType.value) {
        // Drop from palette - create new element
        const newElement = createElementTemplate(draggedElementType.value)
        elements.value.splice(index, 0, newElement)
        selectedElementId.value = newElement.id
    } else if (draggedElement.value) {
        // Drop existing element - reorder
        const oldIndex = elements.value.findIndex(el => el.id === draggedElement.value!.id)
        if (oldIndex !== -1 && oldIndex !== index) {
            const [movedElement] = elements.value.splice(oldIndex, 1)
            const targetIndex = index > oldIndex ? index - 1 : index
            elements.value.splice(targetIndex, 0, movedElement)
        }
    }
    onDragEnd()
}

// Drop to Section
const onDropToSection = (parentId: string, index: number) => {
    const findAndAddToSection = (els: FormElement[]): boolean => {
        for (const el of els) {
            if (el.id === parentId && (el.type === 'section' || el.type === 'repeater')) {
                const children = (el.config as any).children || []
                
                if (draggedElementType.value) {
                    // Create new element from palette
                    if (draggedElementType.value === 'section') return false // No nested sections
                    const newElement = createElementTemplate(draggedElementType.value)
                    children.splice(index, 0, newElement)
                    selectedElementId.value = newElement.id
                } else if (draggedElement.value) {
                    // Move existing element
                    deleteElement(draggedElement.value.id)
                    children.splice(index, 0, draggedElement.value)
                }
                
                (el.config as any).children = children
                onDragEnd()
                return true
            }
            
            // Recurse into children
            if ((el.type === 'section' || el.type === 'repeater') && (el.config as any).children) {
                if (findAndAddToSection((el.config as any).children)) return true
            }
        }
        return false
    }
    
    findAndAddToSection(elements.value)
}

// Drop to Repeater (same as section)
const onDropToRepeater = onDropToSection

// Drop to Grid Column
const onDropToGridColumn = (parentId: string, columnIndex: number) => {
    const findAndAddToGrid = (els: FormElement[]): boolean => {
        for (const el of els) {
            if (el.id === parentId && el.type === 'grid') {
                const columns = (el.config as any).columns || []
                
                if (columnIndex >= columns.length) return false
                
                if (draggedElementType.value) {
                    // Create new element from palette
                    // Only input fields allowed in grid
                    const inputTypes = ['short_text', 'long_text', 'select', 'checklist', 'number', 'date', 'time', 'url', 'image', 'checkbox']
                    if (!inputTypes.includes(draggedElementType.value)) {
                        return false
                    }
                    
                    const newElement = createElementTemplate(draggedElementType.value)
                    columns[columnIndex].element = newElement
                    selectedElementId.value = newElement.id
                } else if (draggedElement.value) {
                    // Move existing element
                    deleteElement(draggedElement.value.id)
                    columns[columnIndex].element = draggedElement.value
                }
                
                (el.config as any).columns = columns
                onDragEnd()
                return true
            }
            
            // Recurse into children
            if ((el.type === 'section' || el.type === 'repeater') && (el.config as any).children) {
                if (findAndAddToGrid((el.config as any).children)) return true
            }
        }
        return false
    }
    
    findAndAddToGrid(elements.value)
}

// Reorder Grid Columns
const onReorderGridColumn = (gridId: string, fromIndex: number, toIndex: number) => {
    const findAndReorder = (els: FormElement[]): boolean => {
        for (const el of els) {
            if (el.id === gridId && el.type === 'grid') {
                const columns = (el.config as any).columns || []
                if (fromIndex >= columns.length || toIndex >= columns.length) return false
                
                // Move column
                const [movedColumn] = columns.splice(fromIndex, 1)
                columns.splice(toIndex, 0, movedColumn)
                
                (el.config as any).columns = columns
                return true
            }
            
            // Recurse into children
            if ((el.type === 'section' || el.type === 'repeater') && (el.config as any).children) {
                if (findAndReorder((el.config as any).children)) return true
            }
            
            // Recurse into grid columns
            if (el.type === 'grid') {
                const columns = (el.config as any).columns || []
                for (const col of columns) {
                    if (col.element && findAndReorder([col.element])) return true
                }
            }
        }
        return false
    }
    
    findAndReorder(elements.value)
}

// Element actions
const selectElement = (elementId: string) => {
    selectedElementId.value = elementId
    
    // Scroll to element
    setTimeout(() => {
        const elementDiv = document.querySelector(`[data-element-id="${elementId}"]`)
        if (elementDiv) {
            elementDiv.scrollIntoView({ behavior: 'smooth', block: 'center' })
        }
    }, 100)
}

const updateElement = (elementId: string, newConfig: any) => {
    const findAndUpdate = (els: FormElement[]): boolean => {
        for (let i = 0; i < els.length; i++) {
            if (els[i].id === elementId) {
                els[i] = { ...els[i], config: newConfig }
                return true
            }
            
            // Check children for sections and repeaters
            if (els[i].type === 'section' || els[i].type === 'repeater') {
                const children = (els[i].config as any).children
                if (children && findAndUpdate(children)) {
                    return true
                }
            }
            
            // Check grid columns - FIXED: directly update col.element instead of creating temporary array
            if (els[i].type === 'grid') {
                const columns = (els[i].config as any).columns || []
                for (let colIndex = 0; colIndex < columns.length; colIndex++) {
                    const col = columns[colIndex]
                    if (col.element) {
                        if (col.element.id === elementId) {
                            // Direct update to the column element
                            col.element = { ...col.element, config: newConfig }
                            return true
                        }
                        // Recursively check nested elements (in case grid column contains section/repeater)
                        if (col.element.type === 'section' || col.element.type === 'repeater') {
                            const children = (col.element.config as any).children
                            if (children && findAndUpdate(children)) {
                                return true
                            }
                        }
                    }
                }
            }
        }
        return false
    }

    findAndUpdate(elements.value)
}

const deleteElement = (elementId: string) => {
    const findAndDelete = (els: FormElement[]): boolean => {
        const index = els.findIndex(el => el.id === elementId)
        if (index !== -1) {
            els.splice(index, 1)
            if (selectedElementId.value === elementId) {
                selectedElementId.value = null
            }
            return true
        }

        // Check nested
        for (const el of els) {
            if (el.type === 'section' || el.type === 'repeater') {
                const children = (el.config as any).children
                if (children && findAndDelete(children)) {
                    return true
                }
            }
            
            if (el.type === 'grid') {
                const columns = (el.config as any).columns || []
                for (let i = 0; i < columns.length; i++) {
                    if (columns[i].element?.id === elementId) {
                        columns[i].element = null
                        if (selectedElementId.value === elementId) {
                            selectedElementId.value = null
                        }
                        return true
                    }
                }
            }
        }
        return false
    }

    findAndDelete(elements.value)
}

const duplicateElement = (elementId: string) => {
    const findAndDuplicate = (els: FormElement[], parentArray: FormElement[]): boolean => {
        const index = els.findIndex(el => el.id === elementId)
        if (index !== -1) {
            const original = els[index]
            const duplicate = JSON.parse(JSON.stringify(original))
            duplicate.id = generateId()
            
            // Update IDs recursively
            const updateIds = (el: FormElement) => {
                el.id = generateId()
                if (el.type === 'section' || el.type === 'repeater') {
                    (el.config as any).children?.forEach(updateIds)
                }
                if (el.type === 'grid') {
                    (el.config as any).columns?.forEach((col: any) => {
                        if (col.element) updateIds(col.element)
                    })
                }
            }
            updateIds(duplicate)
            
            parentArray.splice(index + 1, 0, duplicate)
            return true
        }
        
        // Check nested in sections and repeaters
        for (const el of els) {
            if (el.type === 'section' || el.type === 'repeater') {
                const children = (el.config as any).children
                if (children && findAndDuplicate(children, children)) {
                    return true
                }
            }
            
            // Check grid columns elements (they may have children to duplicate)
            if (el.type === 'grid') {
                const columns = (el.config as any).columns || []
                for (const col of columns) {
                    if (col.element) {
                        // Check if the element inside the column has children that need duplicating
                        if (col.element.type === 'section' || col.element.type === 'repeater') {
                            const children = (col.element.config as any).children
                            if (children && findAndDuplicate(children, children)) {
                                return true
                            }
                        }
                    }
                }
            }
        }
        
        return false
    }

    findAndDuplicate(elements.value, elements.value)
}

// Watch for external changes
watch(() => props.initialElements, (newVal) => {
    if (newVal) {
        elements.value = JSON.parse(JSON.stringify(newVal))
    }
}, { immediate: true })

// Expose methods for parent
defineExpose({
    getElements: () => elements.value
})
</script>

<template>
    <div class="flex h-full overflow-hidden">
        <!-- Left Sidebar: Element Palette -->
        <div v-if="!readonly" class="w-64 border-r border-border overflow-y-auto">
            <div class="p-4 border-b border-border">
                <h3 class="font-semibold text-sm">{{ t('forms.elementPalette') }}</h3>
            </div>
            <ElementPalette @drag-start="onPaletteElementDragStart" />
        </div>

        <!-- Center: Canvas -->
        <div class="flex-1 flex flex-col overflow-hidden">
            <!-- Canvas content -->
            <div class="flex-1 overflow-y-auto p-6">
                <div class="max-w-4xl mx-auto">
                    <div class="space-y-2">
                        <!-- Empty state -->
                        <div 
                            v-if="!hasElements" 
                            class="rounded-lg border-2 border-dashed border-border p-12 text-center"
                            @dragover.prevent
                            @drop.prevent="onDropToCanvas(0)"
                        >
                            <Icon name="layout-grid" size="lg" class="mx-auto mb-3 opacity-30" />
                            <p class="text-muted-foreground text-sm">
                                {{ t('forms.dragElementsHere') }}
                            </p>
                        </div>

                        <!-- Elements list -->
                        <div v-else class="space-y-2">
                            <ElementRenderer
                                v-for="(element, index) in elements"
                                :key="element.id"
                                :element="element"
                                :is-selected="selectedElementId === element.id"
                                :selected-element-id="selectedElementId"
                                :readonly="readonly"
                                @select="(id) => selectElement(id)"
                                @delete="(id) => deleteElement(id)"
                                @duplicate="(id) => duplicateElement(id)"
                                @drag-start="onCanvasElementDragStart(element)"
                                @drag-end="onDragEnd"
                                @drop-before="onDropToCanvas(index)"
                                @drop-after="onDropToCanvas(index + 1)"
                                @drop-to-section="onDropToSection"
                                @drop-to-repeater="onDropToRepeater"
                                @drop-to-grid-column="onDropToGridColumn"
                                @reorder-grid-column="onReorderGridColumn"
                            />

                            <!-- Final drop zone -->
                            <div
                                v-if="!readonly"
                                class="h-16 rounded-lg border-2 border-dashed border-border hover:border-primary/50 hover:bg-primary/5 transition-colors flex items-center justify-center"
                                @dragover.prevent
                                @drop.prevent="onDropToCanvas(elements.length)"
                            >
                                <p class="text-xs text-muted-foreground">
                                    {{ t('forms.dropHere') }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Sidebar: Element Editor -->
        <div v-if="!readonly" class="w-80 border-l border-border overflow-y-auto">
            <div class="p-4 border-b border-border">
                <h3 class="font-semibold text-sm">
                    {{ selectedElement ? t('forms.elementProperties') : t('forms.elementTree') }}
                </h3>
            </div>

            <!-- Show tree when no element selected -->
            <div v-if="!selectedElement" class="p-4">
                <ElementTree 
                    :elements="elements" 
                    :selected-id="selectedElementId"
                    @select="selectElement"
                />
            </div>

            <!-- Show editor when element selected -->
            <div v-else class="p-4">
                <ElementEditor
                    :element="selectedElement"
                    @update="updateElement(selectedElement.id, $event)"
                    @close="selectedElementId = null"
                />
            </div>
        </div>
    </div>
</template>
