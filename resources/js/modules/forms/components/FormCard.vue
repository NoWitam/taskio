<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from '@/composables/useI18n'
import type { Form } from '@/types/forms'
import Icon from '@/components/ui/Icon.vue'
import Button from '@/components/ui/Button.vue'
import SwitchInput from '@/components/ui/inputs/SwitchInput.vue'

interface Props {
    form: Form
    isTrashed?: boolean
}

interface Emits {
    (e: 'view', id: string): void
    (e: 'select', id: string): void
    (e: 'edit', id: string): void
    (e: 'enable', id: string): void
    (e: 'delete', id: string): void
    (e: 'restore', id: string): void
    (e: 'force-delete', id: string): void
}

const props = defineProps<Props>()
const emit = defineEmits<Emits>()
const { t } = useI18n()

const cardClass = computed(() => {
    if (props.isTrashed) {
        return 'border-muted-foreground/20 bg-muted/50'
    }
    return props.form.is_enabled
        ? 'border-primary/20 bg-background hover:border-primary/40'
        : 'border-border bg-background hover:border-border/80'
})

const handleEnableToggle = () => {
    if (!props.form.is_enabled) {
        emit('enable', props.form.id)
    }
    // Disable is not allowed via toggle - needs explicit confirmation
}
</script>

<template>
    <div 
        class="border rounded-lg p-4 transition-all group cursor-default"
        :class="cardClass"
    >
        <!-- Header -->
        <div class="flex items-start justify-between mb-3">
            <div class="flex items-start gap-3 flex-1 min-w-0">
                <div 
                    class="shrink-0 w-10 h-10 rounded-lg flex items-center justify-center transition-colors"
                    :class="form.is_enabled 
                        ? 'bg-primary/10 text-primary' 
                        : 'bg-muted text-muted-foreground'"
                >
                    <Icon :name="form.icon || 'file-text'" size="lg" />
                </div>
                <div class="flex-1 min-w-0">
                    <h3 class="font-medium text-sm truncate">
                        {{ form.name }}
                    </h3>
                    <div class="flex items-center gap-2 mt-1">
                        <span 
                            class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full"
                            :class="form.is_enabled 
                                ? 'bg-green-500/10 text-green-600 dark:text-green-500' 
                                : 'bg-muted text-muted-foreground'"
                        >
                            <span 
                                class="w-1.5 h-1.5 rounded-full" 
                                :class="form.is_enabled ? 'bg-green-600 dark:bg-green-500' : 'bg-muted-foreground'"
                            ></span>
                            {{ form.is_enabled ? t('forms.enabled') : t('forms.disabled') }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div v-if="!isTrashed" class="flex gap-1" @click.stop>
                <Button 
                    variant="ghost" 
                    size="sm" 
                    class="h-8 w-8 p-0"
                    @click="$emit('view', form.id)"
                >
                    <Icon name="eye" size="sm" />
                </Button>
                <Button 
                    variant="ghost" 
                    size="sm" 
                    class="h-8 w-8 p-0"
                    :disabled="!form.can_be_edited"
                    @click="$emit('edit', form.id)"
                >
                    <Icon name="pencil" size="sm" />
                </Button>
                <Button 
                    variant="ghost" 
                    size="sm" 
                    class="h-8 w-8 p-0 text-destructive hover:text-destructive"
                    @click="$emit('delete', form.id)"
                >
                    <Icon name="trash" size="sm" />
                </Button>
                
                <!-- Enable Switch or Select Button -->
                <div v-if="form.is_enabled">
                    <Button 
                        variant="primary" 
                        size="sm"
                        @click="$emit('select', form.id)"
                    >
                        {{ t('common.select') }}
                    </Button>
                </div>
                <div v-else>
                    <Button 
                        variant="primary" 
                        size="sm"
                        @click="handleEnableToggle"
                    >
                        {{ t('forms.enable') }}
                        <SwitchInput
                            :model-value="form.is_enabled"
                            @update:model-value="handleEnableToggle"
                            :disabled="form.is_enabled"
                        />
                    </Button>
                </div>
            </div>

            <!-- Trash Actions -->
            <div v-else class="flex gap-1" @click.stop>
                <Button variant="ghost" size="sm" class="h-8 w-8 p-0" @click="$emit('restore', form.id)">
                    <Icon name="arrow-up-circle" size="sm" />
                </Button>
                <Button variant="ghost" size="sm" class="h-8 w-8 p-0 text-destructive" @click="$emit('force-delete', form.id)">
                    <Icon name="trash" size="sm" />
                </Button>
            </div>
        </div>

        <!-- Description -->
        <div class="text-sm text-muted-foreground line-clamp-2 mb-3 min-h-[2.8rem]">
            {{ form.description || '' }}
        </div>

        <!-- Footer -->
        <div class="flex items-center gap-4 pt-3 border-t border-border text-xs text-muted-foreground">
            <span class="flex items-center gap-1">
                <Icon name="inbox" size="xs" />
                {{ form.submissions_count || 0 }} {{ t('forms.submissions') }}
            </span>
            <span class="flex items-center gap-1">
                <Icon name="calendar" size="xs" />
                {{ new Date(form.created_at).toLocaleDateString() }}
            </span>
        </div>
    </div>
</template>
