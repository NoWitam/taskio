<script setup lang="ts">
import { computed } from 'vue'
import type { FormElement } from '@/types/forms'
import { useI18n } from '@/composables/useI18n'
import Icon from '@/components/ui/Icon.vue'
import Button from '@/components/ui/Button.vue'
import Card from '@/components/ui/Card.vue'
import TextInput from '@/components/ui/inputs/TextInput.vue'
import TextareaInput from '@/components/ui/inputs/TextareaInput.vue'
import SelectInput from '@/components/ui/inputs/SelectInput.vue'
import NumberInput from '@/components/ui/inputs/NumberInput.vue'
import CheckboxInput from '@/components/ui/inputs/CheckboxInput.vue'
import DateInput from '@/components/ui/inputs/DateInput.vue'
import TimeInput from '@/components/ui/inputs/TimeInput.vue'
import FileDropzone from '@/components/ui/inputs/FileDropzone.vue'

const props = defineProps<{
    element: FormElement
    mode: 'preview' | 'fill'
    formData: Record<string, any>
    repeaterInstances: Record<string, number>
    getError: (elementId: string) => string | undefined
}>()

const emit = defineEmits<{
    addRepeater: [repeaterId: string]
    removeRepeater: [repeaterId: string]
}>()

const { t } = useI18n()
</script>

<template>
    <!-- Section -->
    <Card v-if="element.type === 'section'" class="p-6 space-y-4">
        <div class="flex items-start gap-3 pb-4 border-b border-border">
            <div v-if="element.config.icon" class="mt-0.5">
                <Icon :name="element.config.icon" size="lg" class="text-primary" />
            </div>
            <div>
                <h3 class="text-lg font-semibold">{{ element.config.name }}</h3>
                <p v-if="element.config.description" class="mt-1 text-sm text-muted-foreground">
                    {{ element.config.description }}
                </p>
            </div>
        </div>

        <div class="space-y-4">
            <InputRenderer
                v-for="child in (element.config as any).children"
                :key="child.id"
                :element="child"
                :mode="mode"
                :form-data="formData"
                :repeater-instances="repeaterInstances"
                :get-error="getError"
                @add-repeater="emit('addRepeater', $event)"
                @remove-repeater="emit('removeRepeater', $event)"
            />
        </div>
    </Card>

    <!-- Grid -->
    <div v-else-if="element.type === 'grid'" class="grid gap-4" :style="{ gridTemplateColumns: (element.config as any).columns.map((col: any) => `${col.width}fr`).join(' ') }">
        <div v-for="(column, index) in (element.config as any).columns" :key="index">
            <InputRenderer
                v-if="column.element"
                :element="column.element"
                :mode="mode"
                :form-data="formData"
                :repeater-instances="repeaterInstances"
                :get-error="getError"
                @add-repeater="emit('addRepeater', $event)"
                @remove-repeater="emit('removeRepeater', $event)"
            />
        </div>
    </div>

    <!-- Repeater -->
    <Card v-else-if="element.type === 'repeater'" class="p-6 space-y-4">
        <div class="flex items-start justify-between gap-3 pb-4 border-b border-border">
            <div class="flex items-start gap-3">
                <div v-if="element.config.icon" class="mt-0.5">
                    <Icon :name="element.config.icon" size="lg" class="text-primary" />
                </div>
                <div>
                    <h3 class="text-lg font-semibold">{{ element.config.name }}</h3>
                    <p v-if="element.config.description" class="mt-1 text-sm text-muted-foreground">
                        {{ element.config.description }}
                    </p>
                </div>
            </div>

            <div v-if="mode === 'fill'" class="flex gap-2">
                <Button
                    variant="secondary"
                    size="sm"
                    :disabled="(repeaterInstances[element.id] || element.config.min) >= element.config.max"
                    @click="emit('addRepeater', element.id)"
                >
                    <Icon name="plus" size="sm" />
                </Button>
                <Button
                    variant="secondary"
                    size="sm"
                    :disabled="(repeaterInstances[element.id] || element.config.min) <= element.config.min"
                    @click="emit('removeRepeater', element.id)"
                >
                    <Icon name="minus" size="sm" />
                </Button>
            </div>
        </div>

        <!-- Repeater instances -->
        <div class="space-y-6">
            <div
                v-for="instanceIndex in (repeaterInstances[element.id] || element.config.min)"
                :key="instanceIndex"
                class="space-y-4 p-4 rounded-lg border border-border bg-secondary/10"
            >
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium text-muted-foreground">
                        #{{ instanceIndex }}
                    </span>
                </div>

                <div class="space-y-4">
                    <InputRenderer
                        v-for="child in (element.config as any).children"
                        :key="`${child.id}_${instanceIndex}`"
                        :element="{ ...child, id: `${child.id}_${instanceIndex}` }"
                        :mode="mode"
                        :form-data="formData"
                        :repeater-instances="repeaterInstances"
                        :get-error="getError"
                        @add-repeater="emit('addRepeater', $event)"
                        @remove-repeater="emit('removeRepeater', $event)"
                    />
                </div>
            </div>
        </div>
    </Card>

    <!-- Heading -->
    <component
        v-else-if="element.type === 'heading'"
        :is="`h${element.config.level}`"
        :class="{
            'text-3xl font-bold': element.config.level === 1,
            'text-2xl font-bold': element.config.level === 2,
            'text-xl font-semibold': element.config.level === 3,
        }"
    >
        {{ element.config.text }}
    </component>

    <!-- Text Block -->
    <div v-else-if="element.type === 'text_block'" class="prose prose-sm max-w-none">
        <p class="text-muted-foreground whitespace-pre-wrap">{{ element.config.content }}</p>
    </div>

    <!-- Divider -->
    <hr v-else-if="element.type === 'divider'" class="border-border" />

    <!-- Input Fields -->
    <template v-else>
        <TextInput
            v-if="element.type === 'short_text'"
            v-model="formData[element.id]"
            :label="element.config.label"
            :placeholder="element.config.placeholder"
            :hint="element.config.hint"
            :error="getError(element.id)"
            :disabled="mode === 'preview'"
            :required="element.config.required"
        />

        <TextareaInput
            v-else-if="element.type === 'long_text'"
            v-model="formData[element.id]"
            :label="element.config.label"
            :placeholder="element.config.placeholder"
            :hint="element.config.hint"
            :error="getError(element.id)"
            :disabled="mode === 'preview'"
            :required="element.config.required"
            :rows="element.config.rows || 4"
        />

        <SelectInput
            v-else-if="element.type === 'select'"
            v-model="formData[element.id]"
            :label="element.config.label"
            :placeholder="element.config.placeholder"
            :hint="element.config.hint"
            :error="getError(element.id)"
            :disabled="mode === 'preview'"
            :required="element.config.required"
            :multiple="element.config.multiple"
            :options="element.config.options || []"
        />

        <div v-else-if="element.type === 'checklist'" class="space-y-2">
            <label class="block text-sm font-medium">
                {{ element.config.label }}
                <span v-if="element.config.required" class="text-red-500">*</span>
            </label>
            <p v-if="element.config.hint" class="text-sm text-muted-foreground">
                {{ element.config.hint }}
            </p>

            <div class="space-y-2">
                <CheckboxInput
                    v-for="option in element.config.options"
                    :key="option.value"
                    v-model="formData[`${element.id}_${option.value}`]"
                    :disabled="mode === 'preview'"
                >
                    <template #default>
                        <div class="flex items-start gap-2">
                            <div v-if="option.icon" class="mt-0.5">
                                <Icon :name="option.icon" size="sm" />
                            </div>
                            <div>
                                <div class="font-medium">{{ option.label }}</div>
                                <div v-if="option.hint" class="text-xs text-muted-foreground">
                                    {{ option.hint }}
                                </div>
                            </div>
                        </div>
                    </template>
                </CheckboxInput>
            </div>

            <p v-if="getError(element.id)" class="text-sm text-red-500">
                {{ getError(element.id) }}
            </p>
        </div>

        <NumberInput
            v-else-if="element.type === 'number'"
            v-model="formData[element.id]"
            :label="element.config.label"
            :placeholder="element.config.placeholder"
            :hint="element.config.hint"
            :error="getError(element.id)"
            :disabled="mode === 'preview'"
            :required="element.config.required"
            :min="element.config.min"
            :max="element.config.max"
            :step="element.config.step"
        />

        <DateInput
            v-else-if="element.type === 'date'"
            v-model="formData[element.id]"
            :label="element.config.label"
            :placeholder="element.config.placeholder"
            :hint="element.config.hint"
            :error="getError(element.id)"
            :disabled="mode === 'preview'"
            :required="element.config.required"
        />

        <TimeInput
            v-else-if="element.type === 'time'"
            v-model="formData[element.id]"
            :label="element.config.label"
            :placeholder="element.config.placeholder"
            :hint="element.config.hint"
            :error="getError(element.id)"
            :disabled="mode === 'preview'"
            :required="element.config.required"
        />

        <TextInput
            v-else-if="element.type === 'url'"
            v-model="formData[element.id]"
            type="url"
            :label="element.config.label"
            :placeholder="element.config.placeholder || 'https://'"
            :hint="element.config.hint"
            :error="getError(element.id)"
            :disabled="mode === 'preview'"
            :required="element.config.required"
        />

        <FileDropzone
            v-else-if="element.type === 'image'"
            v-model="formData[element.id]"
            :label="element.config.label"
            :hint="element.config.hint"
            :error="getError(element.id)"
            :disabled="mode === 'preview'"
            :accept="(element.config.acceptedTypes || []).join(',')"
            :max-size="element.config.maxSize * 1024 * 1024"
            :multiple="false"
        />

        <CheckboxInput
            v-else-if="element.type === 'checkbox'"
            v-model="formData[element.id]"
            :label="element.config.label"
            :hint="element.config.hint"
            :disabled="mode === 'preview'"
        />
    </template>
</template>
