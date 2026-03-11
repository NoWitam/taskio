<script setup lang="ts">
import { computed, watch, watchEffect } from 'vue';
import { useEditor, EditorContent } from '@tiptap/vue-3';
import type { JSONContent, Editor as TiptapEditor } from '@tiptap/core';
import { createBaseExtensions } from './utils/schema';
import { parseMarkdown } from './utils/parse';
import { serializeDocument } from './utils/serialize';
import type {
  EditorConfig,
  MarkdownEditorChangeMeta,
  MentionUser,
  VariableDefinition,
  VariableNodeAttrs,
  AiTextNodeAttrs,
  IfBranchState,
  IfConditionState,
} from './types/editor';
import Button from '@/components/ui/Button.vue';
import DropdownMenu from '@/components/ui/DropdownMenu.vue';
import Icon from '@/components/ui/Icon.vue';
import { getVariableIconName, getVariableIconLabel } from './utils/variableIcons';

const props = withDefaults(defineProps<{
  modelValue: string;
  config: EditorConfig;
  readonly?: boolean;
  placeholder?: string;
}>(), {
  readonly: false,
  placeholder: 'Napisz coś...',
});

const emit = defineEmits<{
  (e: 'update:modelValue', value: string): void;
  (e: 'change', meta: MarkdownEditorChangeMeta): void;
}>();

const editor = useEditor({
  extensions: createBaseExtensions(props.config),
  content: parseMarkdown(props.modelValue),
  editable: !props.readonly,
  onUpdate({ editor }) {
    handleUpdate(editor);
  },
});

const markdownValue = computed(() => props.modelValue);

watchEffect(() => {
  if (editor.value) {
    editor.value.storage.markdownEditorConfig = editor.value.storage.markdownEditorConfig || {};
    editor.value.storage.markdownEditorConfig.config = props.config;
  }
});

function handleUpdate(instance: TiptapEditor) {
  const doc = instance.getJSON();
  const markdown = serializeDocument(doc);
  emit('update:modelValue', markdown);
  emit('change', { doc, dirty: markdown !== markdownValue.value });
}

watch(() => props.modelValue, (value) => {
  if (!editor.value) return;
  if (serializeDocument(editor.value.getJSON()) === value) return;
  const doc = parseMarkdown(value);
  editor.value.commands.setContent(doc, false);
});

watch(() => props.readonly, (value) => {
  editor.value?.setEditable(!value);
});

const isActive = (command: (editor: TiptapEditor) => boolean) => {
  const instance = editor.value;
  return instance ? command(instance) : false;
};

function toggleMark(mark: 'bold' | 'italic' | 'underline') {
  if (!editor.value) return;
  editor.value.chain().focus()[`toggle${capitalize(mark)}` as 'toggleBold']().run();
}

function setHeading(level: 1 | 2 | 3) {
  editor.value?.chain().focus().toggleHeading({ level }).run();
}

function capitalize(input: string) {
  return input.charAt(0).toUpperCase() + input.slice(1);
}

const mentionFeature = computed(() => props.config.features.mentions);
const mentionUsers = computed(() => mentionFeature.value?.users ?? []);
const mentionEnabled = computed(
  () => Boolean(!props.readonly && mentionFeature.value?.enabled && mentionUsers.value.length)
);

const variableFeature = computed(() => props.config.features.variables);
const variableDefinitions = computed(() => variableFeature.value?.variables ?? []);
const variableEnabled = computed(
  () => Boolean(!props.readonly && variableFeature.value?.enabled && variableDefinitions.value.length)
);

const aiFeature = computed(() => props.config.features.aiText);
const aiEnabled = computed(() => Boolean(!props.readonly && aiFeature.value?.enabled));

const ifBlockFeature = computed(() => props.config.features.ifBlock);
const ifBlockEnabled = computed(() => Boolean(!props.readonly && ifBlockFeature.value?.enabled));

const hasAdvancedToolbar = computed(
  () => mentionEnabled.value || variableEnabled.value || aiEnabled.value || ifBlockEnabled.value
);

type CloseMenuFn = (() => void) | undefined;

function insertMention(user: MentionUser, closeMenu?: CloseMenuFn) {
  if (!editor.value) return;
  editor.value
    .chain()
    .focus()
    .insertContent([
      {
        type: 'mention',
        attrs: {
          id: user.id,
          name: user.name,
          avatar: user.avatar,
        },
      },
      { type: 'text', text: ' ' },
    ])
    .run();
  closeMenu?.();
}

function insertVariable(definition: VariableDefinition, closeMenu?: CloseMenuFn) {
  if (!editor.value) return;
  const payload: VariableNodeAttrs = {
    id: definition.id,
    name: definition.name,
    type: definition.type,
    locked: false,
    pipeline: [],
    resultType: definition.type,
  };

  editor.value
    .chain()
    .focus()
    .insertContent([
      { type: 'variable', attrs: payload },
      { type: 'text', text: ' ' },
    ])
    .run();
  closeMenu?.();
}

function insertAiText() {
  if (!editor.value || !aiEnabled.value) return;
  const attrs: AiTextNodeAttrs = {
    id: generateId('ai'),
    personaId: null,
    prompt: '',
    labels: [],
  };

  editor.value
    .chain()
    .focus()
    .insertContent([
      { type: 'aiText', attrs },
      { type: 'text', text: ' ' },
    ])
    .run();
}

function insertIfBlock() {
  if (!editor.value || !ifBlockEnabled.value) return;
  const blockId = generateId('if_block');
  const condition = createDefaultIfCondition();
  const branches: IfBranchState[] = [
    {
      id: `${blockId}_if`,
      kind: 'if',
      ...(condition ? { condition } : {}),
      content: createEmptyParagraph(),
    },
    {
      id: `${blockId}_else`,
      kind: 'else',
      content: createEmptyParagraph(),
    },
  ];

  editor.value
    .chain()
    .focus()
    .insertContent([
      { type: 'ifBlock', attrs: { id: blockId, branches } },
      createEmptyParagraph(),
    ])
    .run();
}

function createDefaultIfCondition(): IfConditionState | undefined {
  const target = variableDefinitions.value.find((variable) => variable.type === 'boolean');
  if (!target) return undefined;
  return {
    variableId: target.id,
    pipeline: [],
    resultType: 'boolean',
  };
}

function createEmptyParagraph(): JSONContent {
  return { type: 'paragraph', content: [] };
}

function generateId(prefix: string) {
  const cryptoApi = typeof globalThis !== 'undefined' ? globalThis.crypto : undefined;
  if (cryptoApi && typeof cryptoApi.randomUUID === 'function') {
    return `${prefix}_${cryptoApi.randomUUID()}`;
  }
  return `${prefix}_${Math.random().toString(36).slice(2, 9)}`;
}
</script>

<template>
  <div class="rounded-xl border border-border bg-background">
    <header class="flex flex-wrap items-center gap-3 border-b border-border px-4 py-3">
      <div class="flex flex-wrap items-center gap-3">
        <div class="flex items-center gap-1">
          <Button
            v-for="level in props.config.features.markdown.headings"
            :key="`h-${level}`"
            size="sm"
            variant="ghost"
            type="button"
            :class="isActive((ed) => ed.isActive('heading', { level })) ? 'bg-secondary/60' : ''"
            @click="setHeading(level)"
          >
            H{{ level }}
          </Button>
        </div>
        <div class="flex items-center gap-1">
          <Button
            v-if="props.config.features.markdown.bold"
            size="sm"
            variant="ghost"
            type="button"
            :class="isActive((ed) => ed.isActive('bold')) ? 'bg-secondary/60' : ''"
            @click="toggleMark('bold')"
          >
            B
          </Button>
          <Button
            v-if="props.config.features.markdown.italic"
            size="sm"
            variant="ghost"
            type="button"
            :class="isActive((ed) => ed.isActive('italic')) ? 'bg-secondary/60' : ''"
            @click="toggleMark('italic')"
          >
            I
          </Button>
          <Button
            v-if="props.config.features.markdown.underline"
            size="sm"
            variant="ghost"
            type="button"
            :class="isActive((ed) => ed.isActive('underline')) ? 'bg-secondary/60' : ''"
            @click="toggleMark('underline')"
          >
            U
          </Button>
        </div>
      </div>

      <div class="ml-auto flex flex-wrap items-center gap-1">
        <template v-if="hasAdvancedToolbar">
          <DropdownMenu v-if="mentionEnabled">
            <template #activator="{ toggle }">
              <Button size="sm" variant="ghost" type="button" @click.stop="toggle">@ Wzmianka</Button>
            </template>
            <template #default="{ closeMenu }">
              <div class="min-w-[220px] py-1 text-left">
                <button
                  v-for="user in mentionUsers"
                  :key="user.id"
                  type="button"
                  class="flex w-full flex-col gap-0.5 px-3 py-2 text-sm hover:bg-secondary/60"
                  @click="insertMention(user, closeMenu)"
                >
                  <span class="font-medium text-foreground">@{{ user.name }}</span>
                  <span class="text-xs text-muted-foreground">{{ user.id }}</span>
                </button>
              </div>
            </template>
          </DropdownMenu>

          <DropdownMenu v-if="variableEnabled">
            <template #activator="{ toggle }">
              <Button size="sm" variant="ghost" type="button" @click.stop="toggle">+ Zmienna</Button>
            </template>
            <template #default="{ closeMenu }">
              <div class="min-w-[220px] py-1 text-left">
                <button
                  v-for="variable in variableDefinitions"
                  :key="variable.id"
                  type="button"
                  class="flex w-full items-center justify-between gap-3 px-3 py-2 text-sm hover:bg-secondary/60"
                  @click="insertVariable(variable, closeMenu)"
                >
                  <span class="flex flex-col text-left">
                    <span class="font-medium text-foreground">{{ variable.name }}</span>
                    <span class="text-xs text-muted-foreground">{{ variable.id }}</span>
                  </span>
                  <span
                    class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-secondary/60 text-foreground/80"
                    :title="getVariableIconLabel(variable.type)"
                  >
                    <Icon :name="getVariableIconName(variable.type)" size="sm" />
                    <span class="sr-only">{{ getVariableIconLabel(variable.type) }}</span>
                  </span>
                </button>
              </div>
            </template>
          </DropdownMenu>

          <Button
            v-if="aiEnabled"
            size="sm"
            variant="ghost"
            type="button"
            @click="insertAiText"
          >
            + Tekst AI
          </Button>

          <Button
            v-if="ifBlockEnabled"
            size="sm"
            variant="ghost"
            type="button"
            @click="insertIfBlock"
          >
            + Blok IF
          </Button>
        </template>

        <slot name="toolbar" :editor="editor" />
      </div>
    </header>
    <EditorContent
      v-if="editor"
      :editor="editor"
      class="prose max-w-none px-4 py-3 text-left"
    />
    <div v-else class="px-4 py-3 text-sm text-muted-foreground">Ładowanie edytora...</div>
  </div>
</template>
