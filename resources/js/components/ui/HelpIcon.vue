<script setup lang="ts">
import { computed } from 'vue';
import Tooltip from './Tooltip.vue';
import Icon from './Icon.vue';
import { cn } from '@/lib/helpers';

type Side = 'top' | 'right' | 'bottom' | 'left';
type Size = 'xs' | 'sm' | 'md' | 'lg';

const props = withDefaults(
    defineProps<{
        side?: Side;
        size?: Size;
        class?: string;
    }>(),
    {
        side: 'top',
        size: 'sm',
    }
);
</script>

<template>
    <Tooltip 
        :side="side"
        contentClass="bg-primary-glow shadow-lg text-center"
        arrowClass="bg-primary-glow"
    >
        <span
            :class="cn(
                'inline-flex items-center justify-center rounded-full',
                'bg-muted hover:bg-muted/80 text-primary',
                'cursor-help transition-colors',
                'ring-1 ring-border hover:ring-primary/50',
                props.class
            )"
        >
            <Icon name="circle-help" :size="size" />
        </span>

        <template #content>
            <slot />
        </template>
    </Tooltip>
</template>
