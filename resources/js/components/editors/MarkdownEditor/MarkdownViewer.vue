<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import type { JSONContent } from '@tiptap/core';
import { useEditor, EditorContent } from '@tiptap/vue-3';
import { createBaseExtensions } from './utils/schema';
import type { EditorConfig } from './types/editor';
import { parseMarkdown } from './utils/parse';

const props = withDefaults(defineProps<{
  value: JSONContent | string | null;
  config?: EditorConfig;
  placeholder?: string;
}>(), {
  placeholder: 'Brak treści',
});

const DEFAULT_DOC: JSONContent = {
  type: 'doc',
  content: [{ type: 'paragraph', content: [] }],
};

const defaultConfig: EditorConfig = {
  features: {
    markdown: {
      headings: [1, 2, 3],
      links: true,
      lists: true,
      bold: true,
      italic: true,
      underline: true,
    },
    mentions: { enabled: true, users: [], trigger: '@' },
    variables: { enabled: true, variables: [], operationsCatalog: [] },
    ifBlock: { enabled: true },
    aiText: { enabled: true, labelsEnabled: false },
  },
};

function normalizeDoc(value: JSONContent | string | null): JSONContent {
  if (!value) return DEFAULT_DOC;

  if (typeof value === 'string') {
    try {
      const parsed = JSON.parse(value) as JSONContent;
      if (parsed?.type === 'doc') {
        return parsed;
      }
    } catch (error) {
      return parseMarkdown(value);
    }
    return DEFAULT_DOC;
  }

  if (typeof value === 'object' && value.type === 'doc') {
    return value;
  }

  return DEFAULT_DOC;
}

const currentDoc = ref<JSONContent>(normalizeDoc(props.value));

const resolvedConfig = computed(() => props.config ?? defaultConfig);

const editor = useEditor({
  extensions: createBaseExtensions(resolvedConfig.value),
  content: currentDoc.value,
  editable: false,
});

watch(() => props.value, (val) => {
  currentDoc.value = normalizeDoc(val);
  if (editor.value) {
    editor.value.commands.setContent(currentDoc.value, false);
  }
});

watch(resolvedConfig, (config) => {
  if (!editor.value) return;
  editor.value.setOptions({
    extensions: createBaseExtensions(config),
  });
  editor.value.commands.setContent(currentDoc.value, false);
});

const hasContent = computed(() => {
  const content = currentDoc.value.content ?? [];
  return Array.isArray(content) && content.some((node) => {
    if (node.type === 'paragraph' && (!node.content || node.content.length === 0)) {
      return false;
    }
    return true;
  });
});
</script>

<template>
  <div class="rounded-xl border border-border bg-card">
    <EditorContent
      v-if="editor && hasContent"
      :editor="editor"
      class="prose max-w-none p-4 text-foreground"
    />
    <p v-else class="p-4 text-sm text-muted-foreground">{{ placeholder }}</p>
  </div>
</template>
