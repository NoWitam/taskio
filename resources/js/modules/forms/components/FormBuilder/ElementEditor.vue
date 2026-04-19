<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import type { FormElement } from '@/types/forms'
import { useI18n } from '@/composables/useI18n'
import Button from '@/components/ui/Button.vue'
import Icon from '@/components/ui/Icon.vue'
import TextInput from '@/components/ui/inputs/TextInput.vue'
import TextareaInput from '@/components/ui/inputs/TextareaInput.vue'
import IconInput from '@/components/ui/inputs/IconInput.vue'
import NumberInput from '@/components/ui/inputs/NumberInput.vue'
import CheckboxInput from '@/components/ui/inputs/CheckboxInput.vue'
import DateInput from '@/components/ui/inputs/DateInput.vue'

const props = defineProps<{
    element: FormElement
}>()

const emit = defineEmits<{
    update: [config: any]
    close: []
}>()

const { t } = useI18n()

// Local config copy for editing
const localConfig = ref<any>({})
const isUpdating = ref(false)

// Initialize local config
watch(() => props.element, (newElement) => {
    if (!isUpdating.value) {
        try {
            localConfig.value = structuredClone(newElement.config)
        } catch (e) {
            // Fallback for structuredClone not available
            localConfig.value = { ...newElement.config }
        }
    }
}, { immediate: true, deep: true })

// Apply changes
const applyChanges = () => {
    isUpdating.value = true
    emit('update', localConfig.value)
    setTimeout(() => {
        isUpdating.value = false
    }, 0)
}

// Watch for changes and auto-apply
watch(localConfig, () => {
    applyChanges()
}, { deep: true })

const elementTypeLabel = computed(() => {
    const labels: Record<string, string> = {
        section: t('forms.elementTypes.section'),
        grid: t('forms.elementTypes.grid'),
        repeater: t('forms.elementTypes.repeater'),
        heading: t('forms.elementTypes.heading'),
        text_block: t('forms.elementTypes.text_block'),
        divider: t('forms.elementTypes.divider'),
        short_text: t('forms.elementTypes.short_text'),
        long_text: t('forms.elementTypes.long_text'),
        select: t('forms.elementTypes.select'),
        checklist: t('forms.elementTypes.checklist'),
        number: t('forms.elementTypes.number'),
        date: t('forms.elementTypes.date'),
        time: t('forms.elementTypes.time'),
        url: t('forms.elementTypes.url'),
        image: t('forms.elementTypes.image'),
        checkbox: t('forms.elementTypes.checkbox'),
    }
    return labels[props.element.type] || props.element.type
})

// Options management for select/checklist
const addOption = () => {
    if (!localConfig.value.options) {
        localConfig.value.options = []
    }
    localConfig.value.options.push({
        value: `option_${Date.now()}`,
        label: t('forms.newOption'),
        icon: '',
        hint: ''
    })
}

const removeOption = (index: number) => {
    localConfig.value.options.splice(index, 1)
}

const moveOptionUp = (index: number) => {
    if (index > 0) {
        const temp = localConfig.value.options[index]
        localConfig.value.options[index] = localConfig.value.options[index - 1]
        localConfig.value.options[index - 1] = temp
    }
}

const moveOptionDown = (index: number) => {
    if (index < localConfig.value.options.length - 1) {
        const temp = localConfig.value.options[index]
        localConfig.value.options[index] = localConfig.value.options[index + 1]
        localConfig.value.options[index + 1] = temp
    }
}

// Grid column management
const totalGridWidth = computed(() => {
    if (!localConfig.value.columns || !Array.isArray(localConfig.value.columns)) {
        return 0
    }
    return localConfig.value.columns.reduce((sum: number, col: any) => sum + (col.width || 0), 0)
})

const addGridColumn = () => {
    if (!localConfig.value.columns) {
        localConfig.value.columns = []
    }
    if (localConfig.value.columns.length < 4) {
        localConfig.value.columns.push({
            width: 50,
            element: null
        })
    }
}

const removeGridColumn = (index: number) => {
    if (localConfig.value.columns) {
        localConfig.value.columns.splice(index, 1)
    }
}
</script>

<template>
    <div class="space-y-4">
        <!-- Header -->
        <div class="flex items-center justify-between">
            <h4 class="font-semibold text-sm">{{ elementTypeLabel }}</h4>
            <Button variant="ghost" size="sm" @click="emit('close')">
                <Icon name="x" size="sm" />
            </Button>
        </div>

        <!-- Section/Repeater fields -->
        <template v-if="element.type === 'section' || element.type === 'repeater'">
            <TextInput
                v-model="localConfig.name"
                :label="t('common.name')"
                placeholder="Nazwa sekcji"
            />
            
            <IconInput
                v-model="localConfig.icon"
                :label="t('common.icon')"
            />
            
            <TextareaInput
                v-model="localConfig.description"
                :label="t('common.description')"
                placeholder="Opcjonalny opis"
                :rows="3"
            />

            <template v-if="element.type === 'repeater'">
                <NumberInput
                    v-model="localConfig.min"
                    :label="t('forms.minRepetitions')"
                    :min="0"
                />
                
                <NumberInput
                    v-model="localConfig.max"
                    :label="t('forms.maxRepetitions')"
                    :min="1"
                />
            </template>
        </template>

        <!-- Grid fields -->
        <template v-if="element.type === 'grid'">
            <div class="space-y-3">
                <div class="flex items-center justify-between">
                    <label class="text-sm font-medium">{{ t('forms.gridColumns') }}</label>
                    <span class="text-xs text-muted-foreground">
                        {{ localConfig.columns?.length || 0 }}/4
                    </span>
                </div>

                <div v-if="localConfig.columns && localConfig.columns.length > 0" class="space-y-2">
                    <div
                        v-for="(column, index) in localConfig.columns"
                        :key="index"
                        class="rounded-lg border border-border p-3"
                    >
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-medium text-muted-foreground">
                                {{ t('forms.column') }} #{{ Number(index) + 1 }}
                            </span>
                            <Button
                                variant="ghost"
                                size="sm"
                                @click="removeGridColumn(Number(index))"
                            >
                                <Icon name="x" size="xs" />
                            </Button>
                        </div>

                        <div class="space-y-2">
                            <label class="text-xs font-medium">{{ t('forms.columnWidth') }}</label>
                            <div class="grid grid-cols-4 gap-2">
                                <Button
                                    v-for="width in [25, 50, 75, 100]"
                                    :key="width"
                                    :variant="column.width === width ? 'primary' : 'secondary'"
                                    size="sm"
                                    @click="column.width = width"
                                >
                                    {{ width }}%
                                </Button>
                            </div>
                        </div>
                    </div>

                    <div class="text-xs text-center" :class="totalGridWidth > 100 ? 'text-danger' : 'text-muted-foreground'">
                        {{ t('forms.totalWidth') }}: {{ totalGridWidth }}%
                        <span v-if="totalGridWidth > 100" class="text-danger">
                            ({{ t('forms.exceedsMaxWidth') }})
                        </span>
                    </div>
                </div>

                <Button
                    v-if="!localConfig.columns || localConfig.columns.length < 4"
                    variant="secondary"
                    size="sm"
                    class="w-full"
                    @click="addGridColumn"
                >
                    <Icon name="plus" size="sm" />
                    {{ t('forms.addColumn') }}
                </Button>

                <p v-if="localConfig.columns && localConfig.columns.length >= 4" class="text-xs text-muted-foreground text-center">
                    {{ t('forms.maxColumnsReached') }}
                </p>
            </div>
        </template>

        <!-- Heading fields -->
        <template v-if="element.type === 'heading'">
            <div class="space-y-2">
                <label class="text-sm font-medium">{{ t('forms.headingLevel') }}</label>
                <div class="flex gap-2">
                    <Button
                        v-for="level in [1, 2, 3]"
                        :key="level"
                        :variant="localConfig.level === level ? 'primary' : 'secondary'"
                        size="sm"
                        @click="localConfig.level = level"
                    >
                        H{{ level }}
                    </Button>
                </div>
            </div>

            <TextInput
                v-model="localConfig.text"
                :label="t('forms.headingText')"
                placeholder="Wprowadź tekst nagłówka"
            />
        </template>

        <!-- Text Block fields -->
        <template v-if="element.type === 'text_block'">
            <TextareaInput
                v-model="localConfig.content"
                :label="t('forms.textContent')"
                placeholder="Wprowadź treść"
                :rows="6"
            />
        </template>

        <!-- Divider - no config needed -->
        <template v-if="element.type === 'divider'">
            <p class="text-sm text-muted-foreground">
                {{ t('forms.dividerNoConfig') }}
            </p>
        </template>

        <!-- Input fields - common -->
        <template v-if="element.type !== 'section' && element.type !== 'grid' && element.type !== 'repeater' && element.type !== 'heading' && element.type !== 'text_block' && element.type !== 'divider'">
            <TextInput
                v-model="localConfig.label"
                :label="t('common.label')"
                placeholder="Etykieta pola"
            />

            <TextInput
                v-if="element.type !== 'checkbox' && element.type !== 'checklist'"
                v-model="localConfig.placeholder"
                :label="t('forms.placeholder')"
                placeholder="Opcjonalny placeholder"
            />

            <TextareaInput
                v-model="localConfig.hint"
                :label="t('forms.hint')"
                placeholder="Opcjonalna podpowiedź"
                :rows="2"
            />

            <CheckboxInput
                v-model="localConfig.required"
                :label="t('forms.requiredField')"
            />

            <!-- Short/Long text specific -->
            <template v-if="element.type === 'short_text' || element.type === 'long_text'">
                <NumberInput
                    v-model="localConfig.minLength"
                    :label="t('forms.minLength')"
                    :min="0"
                />
                
                <NumberInput
                    v-model="localConfig.maxLength"
                    :label="t('forms.maxLength')"
                    :min="1"
                />

                <template v-if="element.type === 'long_text'">
                    <NumberInput
                        v-model="localConfig.rows"
                        :label="t('forms.rows')"
                        :min="2"
                        :max="20"
                    />
                </template>
            </template>

            <!-- Number specific -->
            <template v-if="element.type === 'number'">
                <NumberInput
                    v-model="localConfig.min"
                    :label="t('forms.minValue')"
                />
                
                <NumberInput
                    v-model="localConfig.max"
                    :label="t('forms.maxValue')"
                />

                <NumberInput
                    v-model="localConfig.step"
                    :label="t('forms.step')"
                    :min="0.01"
                    :step="0.01"
                />
            </template>

            <!-- Date specific -->
            <template v-if="element.type === 'date'">
                <DateInput
                    v-model="localConfig.min"
                    :label="t('forms.minDate')"
                />
                
                <DateInput
                    v-model="localConfig.max"
                    :label="t('forms.maxDate')"
                />
            </template>

            <!-- Image specific -->
            <template v-if="element.type === 'image'">
                <NumberInput
                    v-model="localConfig.maxSize"
                    :label="t('forms.maxSizeMB')"
                    :min="1"
                    :max="50"
                />
            </template>

            <!-- Select/Checklist options -->
            <template v-if="element.type === 'select' || element.type === 'checklist'">
                <div class="space-y-2">
                    <div class="flex items-center justify-between">
                        <label class="text-sm font-medium">{{ t('forms.options') }}</label>
                        <Button variant="secondary" size="sm" @click="addOption">
                            <Icon name="plus" size="sm" />
                            {{ t('forms.addOption') }}
                        </Button>
                    </div>

                    <div v-if="localConfig.options && localConfig.options.length > 0" class="space-y-2">
                        <div
                            v-for="(option, index) in localConfig.options"
                            :key="index"
                            class="rounded-lg border border-border p-3 space-y-2"
                        >
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-xs font-medium text-muted-foreground">
                                    {{ t('forms.option') }} #{{ Number(index) + 1 }}
                                </span>
                                <div class="flex gap-1">
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        :disabled="index === 0"
                                        @click="moveOptionUp(Number(index))"
                                    >
                                        <Icon name="arrow-up" size="xs" />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        :disabled="index === localConfig.options.length - 1"
                                        @click="moveOptionDown(Number(index))"
                                    >
                                        <Icon name="arrow-down" size="xs" />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        @click="removeOption(Number(index))"
                                    >
                                        <Icon name="x" size="xs" />
                                    </Button>
                                </div>
                            </div>

                            <TextInput
                                v-model="option.label"
                                :label="t('common.label')"
                                placeholder="Etykieta opcji"
                                size="sm"
                            />

                            <TextInput
                                v-model="option.value"
                                :label="t('forms.value')"
                                placeholder="Wartość (unikalna)"
                                size="sm"
                            />

                            <IconInput
                                v-model="option.icon"
                                :label="t('common.icon')"
                                size="sm"
                            />

                            <TextInput
                                v-model="option.hint"
                                :label="t('forms.hint')"
                                placeholder="Opcjonalna podpowiedź"
                                size="sm"
                            />
                        </div>
                    </div>

                    <p v-else class="text-sm text-muted-foreground text-center py-4">
                        {{ t('forms.noOptions') }}
                    </p>
                </div>

                <CheckboxInput
                    v-if="element.type === 'select'"
                    v-model="localConfig.multiple"
                    :label="t('forms.multipleSelection')"
                />
            </template>
        </template>
    </div>
</template>
