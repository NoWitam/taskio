<script setup lang="ts">
import { computed, ref, watch, onMounted, watchEffect } from 'vue';
import { useEditor, EditorContent } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import Link from '@tiptap/extension-link';
import Underline from '@tiptap/extension-underline';
import type { Editor } from '@tiptap/core';

import { cn } from '@/lib/helpers';
import type { EditorConfig, EditorMode, EditorEmits, MentionUser } from './types';
import { serializeDocument } from './utils/serialize';
import { deserializeMarkdown, markdownToEditorJSON } from './utils/deserialize';
import { renderMarkdown } from './utils/render';
import { MentionNode, VariableNode, AiBlockNode, ConditionalBlockNode } from './utils/schema';
import MarkdownWysiwygEditorToolbar from './MarkdownWysiwygEditorToolbar.vue';
import MentionDropdown from './panels/MentionDropdown.vue';
import VariablePanel from './panels/VariablePanel.vue';
import AiPanel from './panels/AiPanel.vue';
import ConditionalPanel from './panels/ConditionalPanel.vue';

const props = withDefaults(
  defineProps<{
    modelValue: string;
    config: EditorConfig;
    disabled?: boolean;
    placeholder?: string;
  }>(),
  {
    disabled: false,
    placeholder: 'Wpisz tekst...',
  }
);

const emit = defineEmits<EditorEmits>();

// State
const mode = ref<EditorMode>('edit');
const internalValue = ref(props.modelValue);
const mentionQuery = ref('');
const showMentionDropdown = ref(false);
const selectedMentionIndex = ref(-1);
const filteredUsers = ref<MentionUser[]>([]);
const dropdownPositionTop = ref(0);
const dropdownPositionLeft = ref(0);
const selectedVariableId = ref<string | null>(null);
const selectedAiBlockId = ref<string | null>(null);
const selectedConditionalBlockId = ref<string | null>(null);

// Filter users from config based on query
watchEffect(() => {
  // Only filter if dropdown is visible
  if (!showMentionDropdown.value) {
    filteredUsers.value = [];
    return;
  }

  // Get all available users from config
  const allUsers = props.config.mentionUsers || [];
  const query = mentionQuery.value.toLowerCase();
  
  // Filter users by name based on query
  filteredUsers.value = allUsers.filter(user => 
    query === '' || user.name.toLowerCase().includes(query)
  );
  selectedMentionIndex.value = -1;
});

// Detectuj mention pattern: @query
const detectMentionPattern = (ed: Editor) => {
  const { $from } = ed.state.selection;
  
  // Get text from 50 chars before cursor to current position
  const startPos = Math.max(0, $from.pos - 50);
  const textBefore = ed.state.doc.textBetween(startPos, $from.pos);
  
  // Match @ followed by word chars
  const atMatch = textBefore.match(/@(\w*)$/);
  
  if (atMatch) {
    mentionQuery.value = atMatch[1] || '';
    showMentionDropdown.value = true;
    selectedMentionIndex.value = -1;
    updateDropdownPosition(ed); // Update dropdown position based on cursor
    // watchEffect will automatically fetch users when mentionQuery changes
  } else {
    showMentionDropdown.value = false;
    mentionQuery.value = '';
    filteredUsers.value = [];
  }
};

// Oblicz pozycję dropdown'u względem pozycji kursora
const updateDropdownPosition = (ed: Editor) => {
  if (!editor.value) return;
  
  try {
    const { $from } = ed.state.selection;
    const editorWrapper = document.querySelector('.markdown-wysiwyg-editor') as HTMLElement;
    
    if (!editorWrapper) return;
    
    // Get cursor coordinates within the editor view
    const coords = ed.view.coordsAtPos($from.pos);
    
    // Get editor wrapper position
    const wrapperRect = editorWrapper.getBoundingClientRect();
    
    // Calculate position relative to the .markdown-wysiwyg-editor div
    // coords.top/left are absolute screen coordinates
    const relativeTop = coords.top - wrapperRect.top;
    const relativeLeft = coords.left - wrapperRect.left;
    
    dropdownPositionTop.value = relativeTop + 20; // 20px below cursor line
    dropdownPositionLeft.value = Math.max(0, relativeLeft - 10); // Slight left offset for cursor
  } catch (error) {
    // Silently handle positioning errors
  }
};

// Editor setup

const editor = useEditor({
  content: markdownToEditorJSON(internalValue.value),
  extensions: [
    StarterKit.configure({
      heading: props.config.headings !== false ? { levels: [1, 2, 3, 4, 5, 6] } : false,
      bulletList: props.config.lists !== false ? {} : false,
      orderedList: props.config.lists !== false ? {} : false,
      blockquote: props.config.blockquote !== false ? {} : false,
      codeBlock: props.config.code !== false ? {} : false,
    }),
    Link.configure({
      openOnClick: false,
      autolink: true,
    }),
    Underline,
    MentionNode,
    VariableNode,
    AiBlockNode,
    ConditionalBlockNode,
  ],
  editable: !props.disabled,
  onUpdate: ({ editor }) => {
    const markdown = serializeDocument(editor.state.doc);
    internalValue.value = markdown;
    
    // Detectuj @ mention pattern
    detectMentionPattern(editor);
  },
});

// Watch model value from parent
watch(
  () => props.modelValue,
  (newValue) => {
    if (internalValue.value !== newValue) {
      internalValue.value = newValue;
      if (editor.value) {
        // Konwertuj mention/variable tokens do custom nodes
        const converted = convertTokensToNodes(newValue);
        editor.value.commands.setContent(converted);
      }
    }
  }
);

// Konwertuj @[user:id|label] → mention node HTML
const convertTokensToNodes = (content: string): string => {
  let html = content;
  
  // Convert mentions: @[user:ID|NAME] → mention node
  html = html.replace(
    /@\[user:([^|]+)\|([^\]]+)\]/g,
    '<span data-mention="true" data-id="$1" data-label="$2" class="mention-chip">@$2</span>'
  );

  // Convert variables: {{var:ID|...}} → variable node
  html = html.replace(
    /\{\{var:([^|}\]]+)(?:\|(.+?))?\}\}/g,
    (match, varId, opsStr) => {
      const ops = opsStr ? opsStr : '';
      return `<span data-variable="true" data-var-id="${varId}" data-ops="${btoa(ops)}" class="variable-chip">{{${varId}}}</span>`;
    }
  );

  return html;
};

// Watch internal value to emit updates
watch(internalValue, (newValue) => {
  emit('update:modelValue', newValue);
  emit('change', newValue);
});

// Handle keyboard for mentions
const handleKeyDown = (e: KeyboardEvent) => {
  if (showMentionDropdown.value) {
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      selectedMentionIndex.value = Math.min(
        selectedMentionIndex.value + 1,
        filteredUsers.value.length - 1
      );
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      selectedMentionIndex.value = Math.max(selectedMentionIndex.value - 1, 0);
    } else if (e.key === 'Enter') {
      e.preventDefault();
      insertMention(filteredUsers.value[selectedMentionIndex.value]);
    } else if (e.key === 'Escape') {
      e.preventDefault();
      showMentionDropdown.value = false;
    }
  }
};

// Insert mention
const insertMention = (user: MentionUser) => {
  if (!editor.value) return;

  const { $from } = editor.value.state.selection;
  
  // Get text before cursor to find @ position
  const textBeforeCursor = editor.value.state.doc.textBetween(
    Math.max(0, $from.pos - 100),
    $from.pos
  );
  
  // Find @ position
  const atIndex = textBeforeCursor.lastIndexOf('@');
  if (atIndex === -1) return;
  
  // Calculate absolute position of @
  const startPos = $from.pos - (textBeforeCursor.length - atIndex);
  const endPos = $from.pos;
  
  // Replace @query with mention node
  editor.value
    .chain()
    .focus()
    .deleteRange({ from: startPos, to: endPos })
    .insertContent({
      type: 'mention',
      attrs: {
        id: user.id,
        label: user.name,
      },
    })
    .insertContent(' ') // Add space after mention
    .run();

  // Close dropdown
  showMentionDropdown.value = false;
  mentionQuery.value = '';
  filteredUsers.value = [];
};

// Insert variable
const insertVariable = (varId: string) => {
  if (!editor.value) return;
  const variable = `{{var:${varId}}}`;
  editor.value.commands.insertContent(variable);
  selectedVariableId.value = varId;
};

// Insert AI block
const insertAiBlock = (aiId: string, botId: string, tags: string[], prompt: string) => {
  if (!editor.value) return;
  const aiBlock = `{{ai:${aiId}|bot:${botId}|tags:${tags.join(',')}|prompt:"${prompt}"}}`;
  editor.value.commands.insertContent(aiBlock);
};

// Insert conditional
const insertConditional = (type: 'if' | 'for' | 'switch') => {
  if (!editor.value) return;

  const blocks: Record<string, string> = {
    if: '{{#if condition:""}}{{/if}}',
    for: '{{#for item:""}}{{/for}}',
    switch: '{{#switch expr:""}}{{/switch}}',
  };

  editor.value.commands.insertContent(blocks[type]);
};

// Get preview raw
const getRawPreview = computed(() => {
  return internalValue.value;
});

// Get preview rendered - konwertuj markdown na HTML
const getRenderedPreview = computed(() => {
  const html = renderMarkdown(
    internalValue.value || '',
    props.config.variablesList || [],
    props.config.aiBots || []
  );
  return html;
});

onMounted(() => {
  if (editor.value) {
    editor.value.view.dom.addEventListener('keydown', handleKeyDown);
  }
});
</script>

<template>
  <div :class="cn('markdown-wysiwyg-editor', { disabled })">
    <!-- Toolbar -->
    <MarkdownWysiwygEditorToolbar
      v-if="editor"
      :editor="editor"
      :config="config"
      :mode="mode"
      @update:mode="mode = $event"
      @insert-mention="showMentionDropdown = true"
      @insert-variable="selectedVariableId = $event"
      @insert-ai="selectedAiBlockId = $event"
      @insert-conditional="insertConditional"
    />

    <!-- Editor modes -->
    <div v-if="mode === 'edit'" class="editor-container">
      <EditorContent :editor="editor" class="editor-content" />
    </div>

    <!-- Mention dropdown (outside editor-container to avoid overflow clipping) -->
    <MentionDropdown
      v-if="showMentionDropdown && filteredUsers.length > 0 && mode === 'edit'"
      :users="filteredUsers"
      :selected-index="selectedMentionIndex"
      :position-top="dropdownPositionTop"
      :position-left="dropdownPositionLeft"
      @select="insertMention"
      @close="showMentionDropdown = false"
      class="mention-dropdown-overlay"
    />

    <!-- Raw preview -->
    <div v-else-if="mode === 'preview-raw'" class="preview-raw-container">
      <pre><code>{{ getRawPreview }}</code></pre>
    </div>

    <!-- Rendered preview -->
    <div v-else-if="mode === 'preview-rendered'" class="preview-rendered-container">
      <!-- Preview content will be rendered here -->
      <div class="preview-rendered-content" v-html="getRenderedPreview"></div>
    </div>

    <!-- Variable panel -->
    <VariablePanel
      v-if="selectedVariableId"
      :variable-id="selectedVariableId"
      :variables="config.variablesList || []"
      @close="selectedVariableId = null"
      @apply="selectedVariableId = null"
    />

    <!-- AI panel -->
    <AiPanel
      v-if="selectedAiBlockId"
      :ai-id="selectedAiBlockId"
      :ai-bots="config.aiBots || []"
      :knowledge-tags="config.knowledgeTags || []"
      :max-nesting="config.maxAiNesting || 3"
      @close="selectedAiBlockId = null"
      @apply="selectedAiBlockId = null"
    />

    <!-- Conditional panel -->
    <ConditionalPanel
      v-if="selectedConditionalBlockId"
      :block-id="selectedConditionalBlockId"
      :variables="config.variablesList || []"
      @close="selectedConditionalBlockId = null"
      @apply="selectedConditionalBlockId = null"
    />
  </div>
</template>

<style scoped>
.markdown-wysiwyg-editor {
  display: flex;
  flex-direction: column;
  border: 1px solid var(--color-border);
  border-radius: 0.5rem;
  background-color: var(--color-background);
  overflow: visible;
  position: relative;
}

.markdown-wysiwyg-editor.disabled {
  opacity: 0.6;
  pointer-events: none;
}

.editor-container {
  flex: 1;
  overflow-y: auto;
  overflow-x: hidden;
  padding: 1rem;
  min-height: 200px;
  text-align: left;
  position: relative;
}

.editor-content {
  outline: none;
  text-align: left;
}

:deep(.editor-content) p {
  margin: 0.5rem 0;
}

:deep(.editor-content) h1,
:deep(.editor-content) h2,
:deep(.editor-content) h3 {
  margin: 1rem 0 0.5rem;
  font-weight: 600;
}

:deep(.editor-content) ul,
:deep(.editor-content) ol {
  padding-left: 1.5rem;
  margin: 0.5rem 0;
}

:deep(.editor-content) code {
  background-color: var(--color-muted);
  padding: 0.125rem 0.375rem;
  border-radius: 0.25rem;
  font-family: monospace;
  font-size: 0.875em;
}

:deep(.editor-content) pre {
  background-color: var(--color-muted);
  padding: 1rem;
  border-radius: 0.5rem;
  overflow-x: auto;
  margin: 0.5rem 0;
}

:deep(.editor-content) blockquote {
  border-left: 4px solid var(--color-primary);
  padding-left: 1rem;
  margin: 0.5rem 0;
  color: var(--color-muted-foreground);
  font-style: italic;
}

:deep(.mention-chip) {
  background-color: var(--color-primary);
  color: var(--color-primary-foreground);
  padding: 0.25rem 0.5rem;
  border-radius: 0.25rem;
  font-weight: 500;
  cursor: pointer;
  font-family: monospace;
  font-size: 0.875em;
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
}

:deep(.variable-chip) {
  background-color: var(--color-secondary);
  color: var(--color-secondary-foreground);
  padding: 0.25rem 0.5rem;
  border-radius: 0.25rem;
  font-weight: 500;
  cursor: pointer;
  font-family: monospace;
  font-size: 0.875em;
}

:deep(.ai-block) {
  background-color: var(--color-muted);
  border: 1px solid var(--color-border);
  border-radius: 0.5rem;
  padding: 1rem;
  margin: 0.5rem 0;
  cursor: pointer;
}

:deep(.ai-block-header) {
  font-weight: 600;
  margin-bottom: 0.5rem;
  color: var(--color-foreground);
}

:deep(.conditional-block) {
  border: 1px dashed var(--color-border);
  border-radius: 0.5rem;
  padding: 1rem;
  margin: 0.5rem 0;
  cursor: pointer;
  background-color: rgba(var(--color-primary), 0.05);
}

:deep(.conditional-block-header) {
  font-weight: 600;
  margin-bottom: 0.5rem;
  color: var(--color-primary);
  font-size: 0.875rem;
}

.preview-raw-container {
  padding: 1rem;
  background-color: var(--color-muted);
  border-radius: 0.5rem;
  overflow-x: auto;
  min-height: 200px;
  max-height: 600px;
}

.preview-raw-container pre {
  margin: 0;
  line-height: 1.5;
}

.preview-raw-container code {
  font-family: monospace;
  font-size: 0.875rem;
  color: var(--color-foreground);
}

.preview-rendered-container {
  padding: 1rem;
  min-height: 200px;
  max-height: 600px;
  overflow-y: auto;
  text-align: left;
}

.preview-rendered-content {
  line-height: 1.6;
  text-align: left;
}

:deep(.mention-chip-styled) {
  display: inline-block;
  background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary) 100%);
  color: white;
  padding: 0.25rem 0.75rem;
  border-radius: 1rem;
  font-weight: 500;
  font-size: 0.875rem;
  margin: 0 0.25rem;
  cursor: default;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
  align-items: center;
}

:deep(.mention-icon) {
  color: currentColor;
  filter: drop-shadow(0 0.5px 1px rgba(0, 0, 0, 0.1));
}

:deep(.variable-chip-styled) {
  display: inline-block;
  background: linear-gradient(135deg, var(--color-secondary) 0%, var(--color-secondary) 100%);
  color: white;
  padding: 0.25rem 0.75rem;
  border-radius: 1rem;
  font-weight: 500;
  font-size: 0.875rem;
  margin: 0 0.25rem;
  cursor: default;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

:deep(.ai-block-styled) {
  display: block;
  background: linear-gradient(135deg, rgba(168, 85, 247, 0.1) 0%, rgba(168, 85, 247, 0.05) 100%);
  border: 1px solid rgba(168, 85, 247, 0.3);
  border-radius: 0.5rem;
  padding: 1rem;
  margin: 0.75rem 0;
  backdrop-filter: blur(4px);
}

:deep(.ai-block-header-styled) {
  font-weight: 600;
  margin-bottom: 0.5rem;
  color: var(--color-foreground);
  font-size: 0.95rem;
}
</style>
