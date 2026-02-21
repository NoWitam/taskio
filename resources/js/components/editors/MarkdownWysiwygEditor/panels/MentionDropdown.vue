<script setup lang="ts">
import type { MentionUser } from '../types';
import { cn } from '@/lib/helpers';

const props = defineProps<{
  users: MentionUser[];
  selectedIndex: number;
  positionTop: number;
  positionLeft: number;
}>();

const emit = defineEmits<{
  'select': [user: MentionUser];
  'close': [];
}>();
</script>

<template>
  <div 
    class="mention-dropdown"
    :style="{
      top: `${positionTop}px`,
      left: `${positionLeft}px`,
    }"
  >
    <div class="mention-dropdown-content">
      <div
        v-for="(user, index) in users"
        :key="user.id"
        :class="cn('mention-item', { active: index === selectedIndex })"
        @click="emit('select', user)"
      >
        <div v-if="user.avatarUrl" class="mention-avatar-wrapper">
          <img :src="user.avatarUrl" :alt="user.name" class="mention-avatar" />
        </div>
        <div v-else class="mention-avatar-placeholder">
          {{ user.name.charAt(0).toUpperCase() }}
        </div>
        <span class="mention-name">{{ user.name }}</span>
      </div>
    </div>
  </div>
</template>

<style scoped>
.mention-dropdown {
  position: absolute;
  background-color: var(--color-card);
  border: 1px solid var(--color-border);
  border-radius: 0.5rem;
  box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
  z-index: 1000;
  max-height: 250px;
  overflow-y: auto;
}

.mention-dropdown-content {
  display: flex;
  flex-direction: column;
}

.mention-item {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.5rem 1rem;
  cursor: pointer;
  transition: background-color 0.15s;
  white-space: nowrap;
}

.mention-item:hover {
  background-color: var(--color-muted);
}

.mention-item.active {
  background-color: var(--color-primary);
  color: var(--color-primary-foreground);
}

.mention-avatar {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  object-fit: cover;
  flex-shrink: 0;
}

.mention-avatar-wrapper {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border-radius: 50%;
  overflow: hidden;
  flex-shrink: 0;
}

.mention-avatar-placeholder {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border-radius: 50%;
  background-color: var(--color-primary);
  color: var(--color-primary-foreground);
  font-weight: 600;
  font-size: 0.75rem;
  flex-shrink: 0;
}

.mention-name {
  font-size: 0.875rem;
  font-weight: 500;
  flex-shrink: 0;
}

.mention-item.active .mention-name {
  color: var(--color-primary-foreground);
}
</style>

