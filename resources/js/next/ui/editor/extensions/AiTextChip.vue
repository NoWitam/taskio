<script setup lang="ts">
// AiTextChip — inline atomic NodeView for an `aiText` node: a chip marking
// AI-generated / AI-insertable text. Clicking opens the Modal edit panel (author +
// nested-MarkdownEditor prompt).
//
// The chip SUMMARIZES the block, in this priority: AUTHOR (the voice — the strongest
// signal about how the text will read) → LEGACY TONE → a prompt fragment → a fallback.
// Knowledge labels dropped out of that chain along with their (now hidden) field, so a
// block that only carried labels now shows its prompt fragment instead.
//
// It NEVER issues a request of its own: it renders the stored `authorName` snapshot and,
// when the panel has already resolved that id, the fresher directory entry.
import { computed, ref } from 'vue';
import { NodeViewWrapper } from '@tiptap/vue-3';
import type { Editor } from '@tiptap/vue-3';
import Icon from '../../primitives/Icon.vue';
import AiTextPanel from './AiTextPanel.vue';
import { useI18n } from '../../../app/i18n';
import { useBotDirectoryStore } from '../../../app/stores/botDirectory';
import type {
  AiLabelOption,
  AiPersona,
  AiTextNodeAttrs,
  VariableFeatureConfig,
  VariableStorage,
} from './types';

const props = defineProps<{
  editor: Editor;
  node: { attrs: AiTextNodeAttrs };
  updateAttributes: (attrs: Partial<AiTextNodeAttrs>) => void;
  deleteNode: () => void;
  selected?: boolean;
}>();

const { t } = useI18n();
const directory = useBotDirectoryStore();

const open = ref(false);

const aiStorage = computed(
  () =>
    (props.editor.storage?.aiText as {
      personas?: AiPersona[];
      labelsEnabled?: boolean;
      labelsCatalog?: AiLabelOption[];
    }) ?? {},
);
const personas = computed(() => aiStorage.value.personas ?? []);
const labelsEnabled = computed(() => aiStorage.value.labelsEnabled ?? false);
const labelsCatalog = computed(() => aiStorage.value.labelsCatalog ?? []);

// Nested prompt editor feature config (re-enable variables / if-blocks inside). Forward the variable
// extension's LIVE getters (getSource/getDefinitions/getCatalog) — NOT the FROZEN `definitions` array —
// so the nested prompt's `{` suggestion sees the host's CURRENT feed (e.g. template SLOTS that arrive
// async from the catalog), exactly like the parent body editor. Reading the frozen array left the prompt
// showing only the variables that existed at editor-creation time (the workspace globals).
const variableStorage = computed(
  () => props.editor.storage?.variable as VariableStorage | undefined,
);
const nestedVariables = computed<VariableFeatureConfig | undefined>(() => {
  const s = variableStorage.value;
  if (!s) return undefined;
  return {
    variables: s.getDefinitions?.() ?? s.definitions ?? [],
    operationsCatalog: s.getCatalog?.() ?? s.catalog ?? [],
    source: s.getSource,
    catalog: s.getCatalog,
    argVariableField: s.argVariableField,
  };
});
const nestedIfBlocks = computed(() => Boolean(props.editor.storage?.ifBlock));

// --- Summary ----------------------------------------------------------------
const authorId = computed(() => props.node.attrs.authorId ?? null);
const authorEntry = computed(() => directory.entry(authorId.value));
/** Definitive "gone" only — an unverified id keeps rendering as a normal author. */
const authorMissing = computed(
  () => !!authorId.value && authorEntry.value?.state === 'missing',
);
/** Resolved name wins over the stored snapshot; a bare id is never shown. */
const authorLabel = computed(() => {
  if (!authorId.value) return null;
  const entry = authorEntry.value;
  const resolved = entry?.state === 'resolved' ? entry.name : null;
  return resolved ?? props.node.attrs.authorName ?? t('editor.aiText.authorUnknownName', 'Unknown author');
});

const legacyToneLabel = computed(() => {
  const id = props.node.attrs.personaId;
  if (!id) return null;
  const fromCatalog = personas.value.find((p) => p.id === id)?.label;
  if (fromCatalog) return fromCatalog;
  const key = `editor.aiText.tone.${id}`;
  const translated = t(key);
  return translated === key ? t('editor.aiText.tone.unknown', 'Unknown tone') : translated;
});

function truncate(value: string): string {
  return value.length > 18 ? `${value.slice(0, 18)}…` : value;
}

const summary = computed(() => {
  if (authorLabel.value) return truncate(authorLabel.value);
  if (legacyToneLabel.value) return legacyToneLabel.value;
  const prompt = (props.node.attrs.prompt ?? '').trim();
  return prompt ? truncate(prompt) : t('editor.aiText.chipFallback', 'AI text');
});

/** The glyph that names WHAT the summary is (never color alone). */
const summaryIcon = computed<'sparkles' | 'clock' | null>(() => {
  if (authorLabel.value) return 'sparkles';
  if (legacyToneLabel.value) return 'clock';
  return null;
});

/** A block with an author carries the app-wide "bot" surface. */
const hasAuthor = computed(() => !!authorId.value);

const ariaLabel = computed(() => {
  if (authorMissing.value) {
    return t(
      'editor.aiText.chipAria.missingAuthor',
      'AI text. The chosen author no longer exists — the default tone will be used. Open editor.',
    );
  }
  if (authorLabel.value) {
    return t('editor.aiText.chipAria.withAuthor', 'AI text, author: {name}. Open editor.', {
      name: authorLabel.value,
    });
  }
  if (legacyToneLabel.value) {
    return t('editor.aiText.chipAria.withTone', 'AI text, legacy tone: {tone}. Open editor.', {
      tone: legacyToneLabel.value,
    });
  }
  return t('editor.aiText.chipAria.plain', 'AI text. Open editor.');
});

function onSave(attrs: AiTextNodeAttrs): void {
  props.updateAttributes(attrs);
  open.value = false;
}
function onRemove(): void {
  open.value = false;
  props.deleteNode();
}
function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Enter' || event.key === ' ') {
    event.preventDefault();
    open.value = true;
  }
}
</script>

<template>
  <NodeViewWrapper as="span" class="next-ai-chip-wrap" contenteditable="false" data-ai-text>
    <button
      type="button"
      class="next-ai-chip"
      :class="[selected ? 'is-selected' : '', hasAuthor ? 'has-author' : '']"
      :aria-expanded="open"
      :aria-label="ariaLabel"
      aria-haspopup="dialog"
      @click="open = true"
      @keydown="onKeydown"
    >
      <Icon name="sparkles" class="next-ai-chip__icon" aria-hidden="true" />
      <span class="next-ai-chip__badge" aria-hidden="true">AI</span>
      <!-- A missing author is a DEGRADED block, not a broken one: the warning glyph adds
           information, it does not replace the name. -->
      <Icon
        v-if="authorMissing"
        name="alert-triangle"
        class="next-ai-chip__icon"
        aria-hidden="true"
      />
      <Icon
        v-else-if="summaryIcon"
        :name="summaryIcon"
        class="next-ai-chip__icon"
        aria-hidden="true"
      />
      <span class="next-ai-chip__label">{{ summary }}</span>
    </button>

    <AiTextPanel
      v-model:open="open"
      :state="node.attrs"
      :personas="personas"
      :labels-enabled="labelsEnabled"
      :labels-catalog="labelsCatalog"
      :variables="nestedVariables"
      :if-blocks="nestedIfBlocks"
      @save="onSave"
      @remove="onRemove"
    />
  </NodeViewWrapper>
</template>

<style scoped>
.next-ai-chip-wrap {
  display: inline-block;
  vertical-align: baseline;
}
.next-ai-chip {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  padding: 0.05rem 0.5rem;
  border-radius: var(--radius-next-full, 9999px);
  background-color: var(--color-next-info-subtle);
  color: var(--color-next-info-subtle-foreground);
  font-weight: var(--font-weight-next-medium);
  font-size: 0.9em;
  line-height: 1.4;
  white-space: nowrap;
  cursor: pointer;
}
/* A block written by a bot joins the app-wide "bot" surface family. A block whose
   author is MISSING stays here too — it is degraded, not broken; the warning glyph
   and the name carry that meaning. */
.next-ai-chip.has-author {
  background-color: var(--color-next-primary-subtle);
  color: var(--color-next-primary-subtle-foreground);
}
.next-ai-chip.is-selected {
  box-shadow: 0 0 0 2px var(--color-next-ring);
}
.next-ai-chip__icon {
  font-size: 0.85em;
}
.next-ai-chip__badge {
  font-size: 0.72em;
  font-weight: var(--font-weight-next-semibold);
  letter-spacing: 0.04em;
  background-color: color-mix(in srgb, currentColor 16%, transparent);
  border-radius: var(--radius-next-sm);
  padding: 0 0.25rem;
}
</style>
