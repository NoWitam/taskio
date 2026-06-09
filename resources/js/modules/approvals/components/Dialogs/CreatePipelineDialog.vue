<script setup lang="ts">
import { ref, reactive, watch, onMounted } from 'vue'
import Dialog from '@/components/ui/Dialog.vue'
import Button from '@/components/ui/Button.vue'
import Icon from '@/components/ui/Icon.vue'
import TextInput from '@/components/ui/inputs/TextInput.vue'
import TextareaInput from '@/components/ui/inputs/TextareaInput.vue'
import RadioGroupInput from '@/components/ui/inputs/RadioGroupInput.vue'
import UserSelect from '@/components/ui/inputs/reusable/UserSelect.vue'
import IconInput from '@/components/ui/inputs/IconInput.vue'
import { useApprovalsStore } from '@/store/approvals'
import { useI18n } from '@/composables/useI18n'
import { useToast } from '@/composables/useToast'
import type { StageInput } from '@/types/approvals'

const props = defineProps<{
    modelValue: boolean
    pipelineId?: string | null
}>()

const emit = defineEmits<{
    'update:modelValue': [value: boolean]
    saved: []
}>()

const store = useApprovalsStore()
const { t } = useI18n()
const { toast } = useToast()

const loading = ref(false)
const submitting = ref(false)

const form = reactive({
    name: '',
    icon: null as string | null,
    description: '',
    stages: [] as StageInput[],
})

const errors = reactive<Record<string, string>>({})

function addStage() {
    form.stages.push({
        name: '',
        icon: null,
        description: null,
        approver_type: 'user',
        approver_id: null,
    })
}

function removeStage(idx: number) {
    form.stages.splice(idx, 1)
}

function moveStage(idx: number, direction: -1 | 1) {
    const target = idx + direction
    if (target < 0 || target >= form.stages.length) return
    const temp = form.stages[idx]
    form.stages[idx] = form.stages[target]
    form.stages[target] = temp
}

function resetForm() {
    form.name = ''
    form.icon = null
    form.description = ''
    form.stages = []
    Object.keys(errors).forEach((k) => delete errors[k])
}

// Load existing pipeline for editing
watch(
    () => props.modelValue,
    async (open) => {
        if (!open) return
        resetForm()

        if (props.pipelineId) {
            loading.value = true
            try {
                const pipeline = await store.fetchPipeline(props.pipelineId)
                form.name = pipeline.name
                form.icon = pipeline.icon
                form.description = pipeline.description ?? ''
                form.stages = pipeline.stages.map((s) => ({
                    name: s.name,
                    icon: s.icon,
                    description: s.description,
                    approver_type: s.approver_type,
                    approver_id: s.approver?.id ?? null,
                }))
            } finally {
                loading.value = false
            }
        } else {
            addStage()
        }
    }
)

function validate(): boolean {
    Object.keys(errors).forEach((k) => delete errors[k])

    if (!form.name.trim()) errors.name = t('approvals.validation.name_required')
    if (form.stages.length === 0) errors.stages = t('approvals.validation.stages_required')

    form.stages.forEach((stage, idx) => {
        if (!stage.name.trim()) errors[`stages.${idx}.name`] = t('approvals.validation.stage_name_required')
        if (stage.approver_type === 'user' && !stage.approver_id) {
            errors[`stages.${idx}.approver_id`] = t('approvals.validation.approver_required')
        }
    })

    return Object.keys(errors).length === 0
}

async function handleSubmit() {
    if (!validate()) return

    submitting.value = true
    try {
        const payload = {
            name: form.name.trim(),
            icon: form.icon,
            description: form.description.trim() || null,
            stages: form.stages,
        }

        if (props.pipelineId) {
            await store.updatePipeline(props.pipelineId, payload)
            toast({ type: 'success', message: t('approvals.messages.pipeline_updated') })
        } else {
            await store.createPipeline(payload)
            toast({ type: 'success', message: t('approvals.messages.pipeline_created') })
        }

        emit('saved')
    } catch (err: any) {
        if (err?.response?.data?.errors) {
            const serverErrors = err.response.data.errors
            Object.entries(serverErrors).forEach(([key, msgs]: [string, any]) => {
                errors[key] = Array.isArray(msgs) ? msgs[0] : msgs
            })
        } else {
            toast({ type: 'error', message: t('common.error') })
        }
    } finally {
        submitting.value = false
    }
}

function close() {
    emit('update:modelValue', false)
}
</script>

<template>
    <Dialog
        :model-value="modelValue"
        :title="pipelineId ? t('approvals.actions.edit_pipeline') : t('approvals.actions.create_pipeline')"
        width="2xl"
        @update:model-value="close"
    >
        <div v-if="loading" class="p-6 flex items-center justify-center">
            <Icon name="loader" size="lg" class="animate-spin text-muted-foreground" />
        </div>

        <div v-else class="p-6 space-y-6">
            <!-- Name + Icon (same layout as CreateFormDialog) -->
            <div class="grid grid-cols-4 space-x-4">
                <div class="col-span-3">
                    <TextInput
                        v-model="form.name"
                        :label="t('approvals.labels.pipeline_name')"
                        :placeholder="t('approvals.labels.pipeline_name_placeholder')"
                        :error="errors.name"
                        required
                        autofocus
                    />
                </div>
                <IconInput
                    v-model="form.icon"
                    :label="t('common.icon')"
                />
            </div>

            <!-- Description -->
            <TextareaInput
                v-model="form.description"
                :label="t('approvals.labels.description')"
                :placeholder="t('approvals.labels.description_placeholder')"
                :rows="2"
            />

            <!-- Stages -->
            <div class="space-y-3">
                <div class="flex items-center justify-between">
                    <label class="text-sm font-medium">{{ t('approvals.labels.stages') }}</label>
                    <Button variant="ghost" size="sm" @click="addStage">
                        <Icon name="plus" size="xs" />
                        {{ t('approvals.actions.add_stage') }}
                    </Button>
                </div>

                <p v-if="errors.stages" class="text-xs text-danger">{{ errors.stages }}</p>

                <div
                    v-for="(stage, idx) in form.stages"
                    :key="idx"
                    class="rounded-lg border border-border p-4 space-y-3"
                >
                    <div class="flex items-center gap-2">
                        <span class="text-xs font-medium text-muted-foreground">
                            {{ t('approvals.labels.stage') }} {{ idx + 1 }}
                        </span>
                        <div class="flex-1" />
                        <Button
                            variant="ghost"
                            size="sm"
                            :disabled="idx === 0"
                            @click="moveStage(idx, -1)"
                        >
                            <Icon name="arrow-up" size="xs" />
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            :disabled="idx === form.stages.length - 1"
                            @click="moveStage(idx, 1)"
                        >
                            <Icon name="arrow-down" size="xs" />
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            class="text-danger hover:text-danger"
                            @click="removeStage(idx)"
                        >
                            <Icon name="trash" size="xs" />
                        </Button>
                    </div>

                    <!-- Stage name + icon -->
                    <div class="grid grid-cols-4 space-x-4">
                        <div class="col-span-3">
                            <TextInput
                                v-model="stage.name"
                                :label="t('approvals.labels.stage_name')"
                                :placeholder="t('approvals.labels.stage_name_placeholder')"
                                :error="errors[`stages.${idx}.name`]"
                            />
                        </div>
                        <IconInput v-model="stage.icon" :label="t('common.icon')" />
                    </div>

                    <!-- Stage criteria -->
                    <TextareaInput
                        v-model="stage.description"
                        :label="t('approvals.labels.stage_criteria')"
                        :placeholder="t('approvals.labels.stage_criteria_placeholder')"
                        :rows="2"
                    />

                    <!-- Approver type -->
                    <RadioGroupInput
                        v-model="stage.approver_type"
                        :options="[
                            { value: 'user', label: t('approvals.approver_type.user') },
                            { value: 'ai', label: t('approvals.approver_type.ai') },
                        ]"
                        orientation="horizontal"
                    />

                    <!-- User select (only for user type) -->
                    <div v-if="stage.approver_type === 'user'" class="space-y-1">
                        <UserSelect
                            v-model="stage.approver_id"
                            :placeholder="t('approvals.labels.select_approver')"
                            :error="errors[`stages.${idx}.approver_id`]"
                        />
                    </div>
                </div>
            </div>
        </div>

        <template #footer>
            <div class="flex items-center gap-3 justify-end px-6 py-4">
                <Button variant="ghost" @click="close">
                    {{ t('common.cancel') }}
                </Button>
                <Button variant="primary" :loading="submitting" @click="handleSubmit">
                    {{ pipelineId ? t('common.save') : t('common.create') }}
                </Button>
            </div>
        </template>
    </Dialog>
</template>
