<script setup lang="ts">
import { computed } from 'vue';
import Button from '@/components/ui/Button.vue';
import Icon from '@/components/ui/Icon.vue';
import DropdownMenu from '@/components/ui/DropdownMenu.vue';
import type { Editor } from '@tiptap/core';
import type { EditorConfig, EditorMode } from './types';

const props = defineProps<{
  editor: Editor;
  config: EditorConfig;
  mode: EditorMode;
}>();

const emit = defineEmits<{
  'update:mode': [mode: EditorMode];
  'insert-mention': [];
  'insert-variable': [varId: string];
  'insert-ai': [aiId: string];
  'insert-conditional': [type: 'if' | 'for' | 'switch'];
}>();

// Computed
const canBold = computed(() => props.editor.can().toggleBold());
const canItalic = computed(() => props.editor.can().toggleItalic());
const canUnderline = computed(() => props.editor.can().toggleUnderline());

// Actions
const toggleBold = () => props.editor.chain().focus().toggleBold().run();
const toggleItalic = () => props.editor.chain().focus().toggleItalic().run();
const toggleUnderline = () => props.editor.chain().focus().toggleUnderline().run();

const toggleHeading = (level: number) => {
  props.editor.chain().focus().toggleHeading({ level: level as any }).run();
};

const toggleBulletList = () => props.editor.chain().focus().toggleBulletList().run();
const toggleOrderedList = () => props.editor.chain().focus().toggleOrderedList().run();
const toggleBlockquote = () => props.editor.chain().focus().toggleBlockquote().run();
const toggleCodeBlock = () => props.editor.chain().focus().toggleCodeBlock().run();

const insertLink = () => {
  const url = prompt('URL:');
  if (url) {
    props.editor
      .chain()
      .focus()
      .extendMarkRange('link')
      .setLink({ href: url })
      .run();
  }
};

const insertImage = () => {
  const url = prompt('Image URL:');
  if (url) {
    props.editor.chain().focus().insertContent(`![image](${url})`).run();
  }
};
</script>

<template>
  <div class="markdown-toolbar">
    <!-- Format Buttons -->
    <div class="toolbar-group">
      <Button
        v-if="config.code !== false"
        variant="secondary"
        size="sm"
        @click="toggleBold"
        title="Bold (Ctrl+B)"
      >
        <Icon name="bold" size="sm" />
      </Button>
      <Button
        v-if="config.code !== false"
        variant="secondary"
        size="sm"
        @click="toggleItalic"
        title="Italic (Ctrl+I)"
      >
        <Icon name="italic" size="sm" />
      </Button>
      <Button
        v-if="config.underline !== false"
        variant="secondary"
        size="sm"
        @click="toggleUnderline"
        title="Underline"
      >
        <Icon name="underline" size="sm" />
      </Button>
    </div>

    <!-- Headings -->
    <div v-if="config.headings !== false" class="toolbar-group">
      <DropdownMenu>
        <template #trigger="{ isOpen }">
          <Button variant="secondary" size="sm">
            <Icon name="heading-2" size="sm" />
            <Icon name="chevron-down" size="xs" />
          </Button>
        </template>
        <template #default="{ closeMenu }">
          <button
            @click="() => {
              toggleHeading(1);
              closeMenu();
            }"
            class="dropdown-item"
          >
            H1
          </button>
          <button
            @click="() => {
              toggleHeading(2);
              closeMenu();
            }"
            class="dropdown-item"
          >
            H2
          </button>
          <button
            @click="() => {
              toggleHeading(3);
              closeMenu();
            }"
            class="dropdown-item"
          >
            H3
          </button>
        </template>
      </DropdownMenu>
    </div>

    <!-- Lists -->
    <div v-if="config.lists !== false" class="toolbar-group">
      <Button variant="secondary" size="sm" @click="toggleBulletList" title="Bullet List">
        <Icon name="list" size="sm" />
      </Button>
      <Button variant="secondary" size="sm" @click="toggleOrderedList" title="Ordered List">
        <Icon name="list-ordered" size="sm" />
      </Button>
    </div>

    <!-- Blockquote & Code -->
    <div v-if="config.blockquote !== false || config.code !== false" class="toolbar-group">
      <Button
        v-if="config.blockquote !== false"
        variant="secondary"
        size="sm"
        @click="toggleBlockquote"
        title="Blockquote"
      >
        <Icon name="quote" size="sm" />
      </Button>
      <Button
        v-if="config.code !== false"
        variant="secondary"
        size="sm"
        @click="toggleCodeBlock"
        title="Code Block"
      >
        <Icon name="code" size="sm" />
      </Button>
    </div>

    <!-- Link & Image -->
    <div v-if="config.links !== false || config.images !== false" class="toolbar-group">
      <Button
        v-if="config.links !== false"
        variant="secondary"
        size="sm"
        @click="insertLink"
        title="Insert Link"
      >
        <Icon name="link" size="sm" />
      </Button>
      <Button
        v-if="config.images !== false"
        variant="secondary"
        size="sm"
        @click="insertImage"
        title="Insert Image"
      >
        <Icon name="image" size="sm" />
      </Button>
    </div>

    <!-- Insert Menu -->
    <div class="toolbar-group toolbar-group-insert">
      <DropdownMenu>
        <template #trigger="{ isOpen }">
          <Button variant="secondary" size="sm">
            <Icon name="plus" size="sm" />
            Insert
            <Icon name="chevron-down" size="xs" />
          </Button>
        </template>
        <template #default="{ closeMenu }">
          <button
            v-if="config.mentions !== false"
            @click="() => {
              emit('insert-mention');
              closeMenu();
            }"
            class="dropdown-item"
          >
            <Icon name="at-sign" size="xs" />
            Mention
          </button>
          <button
            v-if="config.variables !== false"
            @click="() => {
              emit('insert-variable', config.variablesList?.[0]?.id || '');
              closeMenu();
            }"
            class="dropdown-item"
          >
            <Icon name="database" size="xs" />
            Variable
          </button>
          <button
            v-if="config.aiText !== false"
            @click="() => {
              emit('insert-ai', config.aiBots?.[0]?.id || '');
              closeMenu();
            }"
            class="dropdown-item"
          >
            <Icon name="sparkles" size="xs" />
            AI Block
          </button>
          <button
            v-if="config.conditionBlocks !== false"
            @click="() => {
              emit('insert-conditional', 'if');
              closeMenu();
            }"
            class="dropdown-item"
          >
            <Icon name="git-branch" size="xs" />
            IF Block
          </button>
          <button
            v-if="config.conditionBlocks !== false"
            @click="() => {
              emit('insert-conditional', 'for');
              closeMenu();
            }"
            class="dropdown-item"
          >
            <Icon name="repeat" size="xs" />
            FOR Loop
          </button>
          <button
            v-if="config.conditionBlocks !== false"
            @click="() => {
              emit('insert-conditional', 'switch');
              closeMenu();
            }"
            class="dropdown-item"
          >
            <Icon name="git-compare" size="xs" />
            SWITCH
          </button>
        </template>
      </DropdownMenu>
    </div>

    <!-- Mode Switcher -->
    <div class="toolbar-group toolbar-group-modes">
      <Button
        :variant="mode === 'edit' ? 'primary' : 'secondary'"
        size="sm"
        @click="emit('update:mode', 'edit')"
        title="Edit Mode"
      >
        <Icon name="pencil" size="sm" />
      </Button>
      <Button
        :variant="mode === 'preview-raw' ? 'primary' : 'secondary'"
        size="sm"
        @click="emit('update:mode', 'preview-raw')"
        title="Raw Preview"
      >
        <Icon name="file-text" size="sm" />
      </Button>
      <Button
        :variant="mode === 'preview-rendered' ? 'primary' : 'secondary'"
        size="sm"
        @click="emit('update:mode', 'preview-rendered')"
        title="Rendered Preview"
      >
        <Icon name="eye" size="sm" />
      </Button>
    </div>
  </div>
</template>

<style scoped>
.markdown-toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  padding: 0.75rem;
  border-bottom: 1px solid var(--color-border);
  background-color: var(--color-card);
  align-items: center;
}

.toolbar-group {
  display: flex;
  gap: 0.25rem;
  border-right: 1px solid var(--color-border);
  padding-right: 0.5rem;
}

.toolbar-group:last-child {
  border-right: none;
}

.toolbar-group-insert {
  margin-left: auto;
}

.toolbar-group-modes {
  border-left: 1px solid var(--color-border);
  border-right: none;
  padding-left: 0.5rem;
  padding-right: 0;
}

.dropdown-item {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  width: 100%;
  padding: 0.5rem 1rem;
  border: none;
  background: none;
  cursor: pointer;
  text-align: left;
  font-size: 0.875rem;
  color: var(--color-foreground);
  transition: background-color 0.15s;
}

.dropdown-item:hover {
  background-color: var(--color-muted);
}
</style>
