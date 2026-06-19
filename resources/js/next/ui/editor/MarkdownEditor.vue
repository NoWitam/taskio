<script setup lang="ts">
// MarkdownEditor — the flagship rich-text control for the "next" frontend
// (Tier 7, PART 1). It edits a MARKDOWN STRING (v-model) via Tiptap, mirroring
// the FieldShell visual language (the same bordered surface + "state line" the
// Textarea uses, since the editor's HEIGHT grows with content while its WIDTH
// stays fixed) and integrating with FormField for id/aria/dirty wiring.
//
// CONTRACT
//  • `v-model` is a markdown string. We PARSE it to a Tiptap doc on external set
//    and SERIALIZE the doc back to markdown on every edit (see ./markdown.ts).
//  • LOOP PREVENTION: on `onUpdate` we serialize and only emit when the markdown
//    actually changed; the `watch(modelValue)` re-parses ONLY when the incoming
//    value differs from what we'd currently serialize, and uses `emitUpdate:false`
//    so `setContent` doesn't re-fire `onUpdate`. So typing -> emit -> parent set
//    -> (equal) -> no re-parse; external set -> parse -> no spurious emit.
//
// STATES: disabled (not editable + toolbar disabled), readonly (not editable,
// toolbar hidden), loading (paragraph-shaped skeleton), plus the FieldShell
// error/success/dirty/focus signalling. Optional character counter / maxLength.
//
// PART 2: pass `extensions` to merge app nodes (mention/variable/if-block/AI) on
// top of the core schema without touching this file (see ./README.md).
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useEditor, EditorContent } from '@tiptap/vue-3';
import type { Editor } from '@tiptap/vue-3';
import type { AnyExtension } from '@tiptap/core';
import {
  createCoreExtensions,
  mergeExtensions,
  buildPart2Extensions,
  type Part2Features,
  type MentionOptions,
} from './extensions';
import type {
  AiTextFeatureConfig,
  IfBlockFeatureConfig,
  VariableFeatureConfig,
} from './extensions/types';
import { markdownToDoc, docToMarkdown, type JSONNode } from './markdown';
import { useFormField } from '../forms/formField';
import { resolveFieldState } from '../forms/fieldShell';
import EditorToolbar from './EditorToolbar.vue';
import Skeleton from '../data/Skeleton.vue';

const props = withDefaults(
  defineProps<{
    /** Placeholder shown when empty. */
    placeholder?: string;
    disabled?: boolean;
    readonly?: boolean;
    /** Hide the toolbar (forced when readonly). */
    hideToolbar?: boolean;
    /** Loading skeleton instead of the editor. */
    loading?: boolean;
    /** Show a word/character counter footer. */
    counter?: boolean;
    /** Soft character limit; drives the counter color (does not hard-block). */
    maxlength?: number;
    /** Min visible content height (CSS length). Content scrolls past `maxHeight`. */
    minHeight?: string;
    /** Max visible content height before internal scroll (CSS length). */
    maxHeight?: string;
    /** Force invalid styling standalone (FormField sets this). */
    ariaInvalid?: boolean;
    /** Force success styling standalone (FormField sets this). */
    success?: boolean;
    /** Force the subtle dirty accent standalone (FormField tracks this). */
    dirty?: boolean;
    /** PART 2: app-specific Tiptap extensions merged after the core set. */
    extensions?: AnyExtension[];
    // --- PART 2 feature toggles (all OFF by default — keeps the core lean) ---
    /** Enable `@`-mentions with an async `fetch(query)` source. */
    mentions?: MentionOptions;
    /**
     * Enable template variables: a predefined list + an operations catalog
     * (+ optional `trigger`, default `{`). Variables are inserted via the trigger
     * and edited (full pipeline) in a Modal.
     */
    variables?: VariableFeatureConfig;
    /** Enable conditional `if-block` containers. `true` or `{ maxElseIf, maxDepth }`. */
    ifBlocks?: boolean | IfBlockFeatureConfig;
    /** Enable AI-text chips. `true` or `{ personas, labelsEnabled, labelsCatalog }`. */
    aiText?: boolean | AiTextFeatureConfig;
    id?: string;
    describedById?: string;
    ariaLabel?: string;
  }>(),
  {
    placeholder: 'Write something…',
    disabled: false,
    readonly: false,
    hideToolbar: false,
    loading: false,
    counter: false,
    minHeight: '8rem',
    maxHeight: '24rem',
    success: false,
    dirty: false,
  },
);

const model = defineModel<string>({ default: '' });

// --- FormField wiring -------------------------------------------------------
const field = useFormField();
const resolvedId = computed(() => props.id ?? field?.id.value);
const resolvedDescribedBy = computed(
  () => props.describedById ?? field?.describedById.value,
);
const invalid = computed(() => props.ariaInvalid ?? field?.invalid.value ?? false);
const success = computed(() => props.success || (field?.valid.value ?? false));
const dirty = computed(() => props.dirty || (field?.dirty.value ?? false));
const disabled = computed(() => props.disabled || (field?.disabled.value ?? false));
const readonly = computed(() => props.readonly || (field?.readonly.value ?? false));
const required = computed(() => field?.required.value ?? false);

if (field?.registerValue) {
  const dispose = field.registerValue(() => model.value);
  onBeforeUnmount(dispose);
}

// --- Editor instance --------------------------------------------------------
// `syncingFromModel` guards the onUpdate emit while we programmatically setContent
// (belt-and-braces alongside `emitUpdate:false`).
let syncingFromModel = false;
const lastSerialized = ref(model.value);

// The ProseMirror surface IS the labelled textbox, so its a11y attributes must
// track the (reactive) FormField state — recomputed and re-applied via setOptions.
const contentAttributes = computed<Record<string, string>>(() => ({
  class: 'next-md-content',
  role: 'textbox',
  'aria-multiline': 'true',
  ...(resolvedId.value ? { id: resolvedId.value } : {}),
  ...(resolvedDescribedBy.value
    ? { 'aria-describedby': resolvedDescribedBy.value }
    : {}),
  ...(props.ariaLabel ? { 'aria-label': props.ariaLabel } : {}),
  'aria-invalid': invalid.value ? 'true' : 'false',
  'aria-required': required.value ? 'true' : 'false',
}));

// PART 2: assemble the enabled app nodes (mention/variable/if-block/AI). Variable
// /mention/AI nodes share the schema, so they work inside if-block branch bodies
// automatically. The direct `extensions` prop is appended last.
const features: Part2Features = {
  mentions: props.mentions,
  variables: props.variables,
  ifBlocks: props.ifBlocks,
  aiText: props.aiText,
};
const part2 = buildPart2Extensions(features);

const editor = useEditor({
  content: markdownToDoc(model.value) as never,
  editable: !disabled.value && !readonly.value,
  extensions: mergeExtensions(
    mergeExtensions(
      createCoreExtensions({ placeholder: props.placeholder }),
      part2.extensions,
    ),
    props.extensions,
  ),
  editorProps: { attributes: contentAttributes.value },
  onUpdate({ editor }) {
    if (syncingFromModel) return;
    const md = docToMarkdown(editor.getJSON() as JSONNode);
    lastSerialized.value = md;
    if (md !== model.value) model.value = md;
  },
});

// Re-apply the textbox a11y attributes whenever the FormField state changes.
watch(contentAttributes, (attrs) => {
  editor.value?.setOptions({ editorProps: { attributes: attrs } });
});

// Re-parse ONLY genuinely external changes (parent set a value we didn't just
// emit). Compare against the live serialization so an echo of our own emit is a
// no-op and never re-parses (which would reset the selection / loop).
watch(model, (value) => {
  const ed = editor.value;
  if (!ed) return;
  const current = docToMarkdown(ed.getJSON() as JSONNode);
  if (value === current || value === lastSerialized.value) return;
  syncingFromModel = true;
  ed.commands.setContent(markdownToDoc(value) as never, false);
  lastSerialized.value = value;
  syncingFromModel = false;
});

watch([disabled, readonly], ([d, r]) => {
  editor.value?.setEditable(!d && !r);
});

// --- Field state line (mirrors FieldShell/Textarea) -------------------------
const focused = ref(false);
const state = computed(() =>
  resolveFieldState({
    disabled: disabled.value,
    error: invalid.value,
    success: success.value,
    focused: focused.value,
    dirty: dirty.value,
  }),
);
const lineVar = computed(() => {
  switch (state.value) {
    case 'error':
      return 'var(--color-next-danger)';
    case 'success':
      return 'var(--color-next-success)';
    case 'focus':
      return 'var(--color-next-ring)';
    case 'dirty':
      return 'var(--color-next-primary)';
    default:
      return 'var(--color-next-input)';
  }
});
const showRing = computed(
  () =>
    state.value === 'error' ||
    state.value === 'success' ||
    state.value === 'focus',
);

const showToolbar = computed(
  () => !props.hideToolbar && !readonly.value && !props.loading,
);

// --- Counter ----------------------------------------------------------------
const wordCount = ref(0);
const charCount = ref(0);
function recount(ed: Editor | undefined): void {
  if (!ed) return;
  const text = ed.getText();
  charCount.value = text.length;
  const words = text.trim().match(/\S+/g);
  wordCount.value = words ? words.length : 0;
}
watch(
  () => editor.value && model.value,
  () => recount(editor.value),
  { immediate: true },
);
const overLimit = computed(
  () => props.maxlength != null && charCount.value > props.maxlength,
);

const contentStyle = computed(() => ({
  '--md-min-h': props.minHeight,
  '--md-max-h': props.maxHeight,
}));

// Expose the editor instance so a PART 2 host / tests can drive commands.
defineExpose({ editor });

onBeforeUnmount(() => {
  editor.value?.destroy();
});
</script>

<template>
  <div class="flex flex-col gap-next-1">
    <!-- Loading: paragraph-shaped skeleton lines (skeleton rule: mimic the real
         element, show several). -->
    <div
      v-if="loading"
      class="next-md-shell is-loading bg-next-card"
      role="status"
      aria-label="Loading editor"
    >
      <div
        v-if="!hideToolbar"
        class="flex items-center gap-next-2 border-b border-next-border px-next-3 py-next-2"
      >
        <Skeleton variant="rect" width="6rem" height="1.5rem" radius="md" />
        <Skeleton variant="rect" width="8rem" height="1.5rem" radius="md" />
      </div>
      <div class="flex flex-col gap-next-2 px-next-4 py-next-3">
        <Skeleton variant="text" width="92%" />
        <Skeleton variant="text" width="100%" />
        <Skeleton variant="text" width="78%" />
        <Skeleton variant="text" width="40%" />
      </div>
    </div>

    <!-- Editor -->
    <div
      v-else
      class="next-md-shell"
      :class="[
        disabled ? 'is-disabled bg-next-muted' : readonly ? 'is-readonly bg-next-muted/50' : 'bg-next-card',
        !disabled && !readonly && !showRing && state !== 'dirty' ? 'allow-hover' : '',
        showRing ? 'has-ring' : '',
        `state-${state}`,
      ]"
      :style="{ '--field-line': lineVar }"
    >
      <EditorToolbar
        v-if="showToolbar && editor"
        :editor="editor"
        :disabled="disabled"
        :show-if-block="!!ifBlocks"
        :show-ai-text="!!aiText"
      />

      <EditorContent
        :editor="editor"
        class="next-md-content-wrap"
        :style="contentStyle"
        @focusin="focused = true"
        @focusout="focused = false"
      />
    </div>

    <!-- Counter footer -->
    <div
      v-if="counter && !loading"
      class="flex items-center justify-end gap-next-2 text-next-xs"
      :class="overLimit ? 'text-next-danger' : 'text-next-muted-foreground'"
      aria-live="polite"
    >
      <span>{{ wordCount }} {{ wordCount === 1 ? 'word' : 'words' }}</span>
      <span aria-hidden="true">·</span>
      <span>
        {{ charCount }}<template v-if="maxlength"> / {{ maxlength }}</template>
        characters
      </span>
    </div>
  </div>
</template>

<style scoped>
/* Mirror FieldShell/Textarea: rounded surface + 1px border = the state line, an
   inset ring for error/success/focus. Height is free (grows with content). */
.next-md-shell {
  border-radius: var(--radius-next-md);
  border: 1px solid var(--field-line, var(--color-next-input));
  overflow: hidden;
  transition:
    border-color var(--duration-next-fast) var(--ease-next-standard),
    box-shadow var(--duration-next-fast) var(--ease-next-standard);
}
.next-md-shell.has-ring {
  box-shadow: inset 0 0 0 1.5px var(--field-line);
}
.next-md-shell.is-disabled {
  opacity: 0.6;
}
.next-md-shell.allow-hover:hover {
  --field-line: color-mix(in srgb, var(--color-next-fg) 30%, transparent);
}

.next-md-content-wrap {
  min-height: var(--md-min-h, 8rem);
  max-height: var(--md-max-h, 24rem);
  overflow-y: auto;
}
</style>
