<script setup lang="ts">
import type { VariableDef } from '../types';
import { cn } from '@/lib/helpers';

const props = defineProps<{
  variables: VariableDef[];
  selectedIndex: number;
  positionTop: number;
  positionLeft: number;
}>();

const emit = defineEmits<{
  'select': [variable: VariableDef];
  'close': [];
}>();
</script>

<template>
  <div 
    class="variable-dropdown"
    :style="{
      top: `${positionTop}px`,
      left: `${positionLeft}px`,
    }"
  >
    <div class="variable-dropdown-content">
      <div
        v-for="(variable, index) in variables"
        :key="variable.id"
        :class="cn('variable-item', { active: index === selectedIndex })"
        @click="emit('select', variable)"
      >
        <span class="variable-name">{{ variable.name }}</span>
        <span class="variable-type">{{ variable.type }}</span>
      </div>
    </div>
  </div>
</template>

<style scoped>
.variable-dropdown {
  position: absolute;
  background-color: var(--color-card);
  border: 1px solid var(--color-border);
  border-radius: 0.5rem;
  box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
  z-index: 1000;
  max-height: 250px;
  overflow-y: auto;
}

.variable-dropdown-content {
  display: flex;
  flex-direction: column;
}

.variable-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.5rem 1rem;
  cursor: pointer;
  transition: background-color 0.15s;
  white-space: nowrap;
}

.variable-item:hover {
  background-color: var(--color-muted);
}

.variable-item.active {
  background-color: var(--color-primary);
  color: var(--color-primary-foreground);
}

.variable-name {
  font-size: 0.875rem;
  font-weight: 500;
  flex-grow: 1;
}

.variable-type {
  font-size: 0.75rem;
  padding: 0.125rem 0.375rem;
  background-color: var(--color-muted);
  border-radius: 0.25rem;
  text-transform: uppercase;
  opacity: 0.7;
}

.variable-item.active .variable-type {
  background-color: var(--color-secondary-foreground);
  color: var(--color-secondary);
  opacity: 0.8;
}
</style>
