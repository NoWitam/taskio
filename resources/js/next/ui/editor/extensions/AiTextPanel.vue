<script setup lang="ts">
// AiTextPanel — the Modal edit panel for an `aiText` node.
//
// Two zones, in this order:
//   1. VOICE   — the block's AUTHOR: a bot, picked with the shared BotSelect. A bot contributes its
//                VOICE (tone, style, vocabulary), never its knowledge or its tools. Optional: no
//                author is a CORRECT configuration (the block writes in a neutral tone), so the
//                empty state is a placeholder, never a warning. Below it, when the block still
//                carries one, the READ-ONLY "legacy tone" bar (the retired `personaId`).
//   2. CONTENT — the prompt, edited in a NESTED MarkdownEditor (same features as the parent, so the
//                prompt can contain directives + if-blocks — recursively serialized per FORMAT).
//
// GONE from the UI (not from the data): the persona picker (replaced by the author) and the
// knowledge-labels multi-select. `labels` is still loaded and re-emitted UNCHANGED on save, so
// editing an existing block never silently drops authored content.
//
// The author's name/status is resolved through the small `botDirectory` store (id → name/status,
// deduplicated + cached per session) — NOT the browse `useBotsStore`, whose `fetchBot` would stomp
// the global bot-detail view.
import { computed, defineAsyncComponent, ref, watch } from 'vue';
import Modal from '../../overlay/Modal.vue';
import Button from '../../primitives/Button.vue';
import Badge from '../../primitives/Badge.vue';
import Icon from '../../primitives/Icon.vue';
import Alert from '../../feedback/Alert.vue';
import FormField from '../../forms/FormField.vue';
import BotSelect from '../../forms/BotSelect.vue';
import { generateId, type AiTextNodeAttrs } from './types';
import { useI18n } from '../../../app/i18n';
import { useBotDirectoryStore } from '../../../app/stores/botDirectory';
import type { AiLabelOption, AiPersona, VariableFeatureConfig } from './types';

// Async import avoids a circular dependency (MarkdownEditor → aiText → panel).
const MarkdownEditor = defineAsyncComponent(() => import('../MarkdownEditor.vue'));

const props = defineProps<{
  state: AiTextNodeAttrs;
  /**
   * The LEGACY tone catalog. No longer selectable — it survives ONLY as the label source for the
   * "legacy tone" bar, so a block saved with `personaId` still names its tone in the user's
   * language. Hosts keep passing it unchanged.
   */
  personas: AiPersona[];
  /** Retired (both hosts pass `false`); the labels field is no longer rendered. */
  labelsEnabled: boolean;
  /** Retired alongside `labelsEnabled`. */
  labelsCatalog: AiLabelOption[];
  /**
   * Feature config to re-enable inside the nested prompt editor. A full {@link VariableFeatureConfig}
   * so the LIVE `source()`/`catalog()` getters (not just the frozen arrays) reach the nested editor —
   * that is what lets the prompt's `{` suggestion offer the host's current feed, e.g. template SLOTS.
   */
  variables?: VariableFeatureConfig;
  ifBlocks?: boolean | { maxElseIf?: number; maxDepth?: number };
}>();

const emit = defineEmits<{
  (e: 'save', attrs: AiTextNodeAttrs): void;
  (e: 'remove'): void;
}>();

const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();
const directory = useBotDirectoryStore();

// --- Local edit state (committed only on Save; Cancel = discard) -------------
const authorId = ref<string | null>(null);
/** DISPLAY-ONLY snapshot; never used to authorize or generate. */
const authorName = ref<string | null>(null);
const personaId = ref<string | null>(null);
const prompt = ref('');
/** Carried through untouched — the field is hidden, the data is not dropped. */
const labels = ref<string[]>([]);
/** The tone the block was OPENED with, so "cleared" can be told from "never had one". */
const openedWithPersonaId = ref<string | null>(null);

watch(
  open,
  (isOpen) => {
    if (!isOpen) return;
    authorId.value = props.state.authorId ?? null;
    authorName.value = props.state.authorName ?? null;
    personaId.value = props.state.personaId ?? null;
    openedWithPersonaId.value = props.state.personaId ?? null;
    prompt.value = props.state.prompt ?? '';
    labels.value = [...(props.state.labels ?? [])];
  },
  { immediate: true },
);

// Clearing the author drops its display snapshot too (never keep an orphan name).
watch(authorId, (id) => {
  if (!id) authorName.value = null;
});

// --- Author resolution ------------------------------------------------------
// Ask the directory once per id, only while the panel is open. The store dedupes
// concurrent callers and caches the verdict for the session.
watch(
  [open, authorId],
  ([isOpen, id]) => {
    if (isOpen && id) void directory.resolve(id);
  },
  { immediate: true },
);

const authorEntry = computed(() => directory.entry(authorId.value));

/** 404/403 — a definitive "this author is gone". */
const authorMissing = computed(
  () => !!authorId.value && authorEntry.value?.state === 'missing',
);
/** Network/5xx — we do NOT know; never rendered as "deleted". */
const authorUnverified = computed(
  () => !!authorId.value && authorEntry.value?.state === 'unresolved',
);
/** Resolved, but the bot is switched off. NOT a blocker: its voice is still used. */
const authorInactive = computed(
  () =>
    !!authorId.value &&
    authorEntry.value?.state === 'resolved' &&
    authorEntry.value.status === 'inactive',
);

const unknownAuthorName = computed(() => t('editor.aiText.authorUnknownName', 'Unknown author'));

/**
 * What the trigger shows for the current author: the RESOLVED name wins, then the stored display
 * snapshot, then a neutral placeholder. Never the raw id.
 */
const authorSeed = computed(() => {
  const id = authorId.value;
  if (!id) return [];
  const entry = authorEntry.value;
  const resolved = entry?.state === 'resolved' ? entry.name : null;
  return [
    {
      id,
      name: resolved ?? authorName.value ?? unknownAuthorName.value,
      status: entry?.status ?? null,
    },
  ];
});

/**
 * Keep the display snapshot in step with the picker. Guarded so a placeholder / id fallback can
 * never be written back as if it were a real name.
 */
function onAuthorSelected(
  options: Array<{ value: string; label: string; status?: string | null }>,
): void {
  const picked = options[0];
  if (!picked || !authorId.value || picked.value !== authorId.value) return;
  if (picked.label === picked.value || picked.label === unknownAuthorName.value) return;
  authorName.value = picked.label;
  // A bot picked from a loaded page already told us its status — no extra request needed.
  if (picked.status) {
    directory.prime({
      id: picked.value,
      name: picked.label,
      status: picked.status as 'active' | 'inactive',
    });
  }
}

function clearAuthor(): void {
  authorId.value = null;
}

// --- Legacy tone ------------------------------------------------------------
const hasLegacyTone = computed(() => !!personaId.value);
/** True only when the block ARRIVED with a tone and the user cleared it in this session. */
const legacyToneCleared = computed(
  () => !!openedWithPersonaId.value && !personaId.value,
);

/** Host catalog label → localized tone key → a neutral "unknown tone". */
const legacyToneLabel = computed(() => {
  const id = personaId.value;
  if (!id) return '';
  const fromCatalog = props.personas.find((p) => p.id === id)?.label;
  if (fromCatalog) return fromCatalog;
  const key = `editor.aiText.tone.${id}`;
  const translated = t(key);
  return translated === key ? t('editor.aiText.tone.unknown', 'Unknown tone') : translated;
});

function clearLegacyTone(): void {
  personaId.value = null;
}

// --- Save -------------------------------------------------------------------
function save(): void {
  emit('save', {
    id: props.state.id || generateId('ai'),
    personaId: personaId.value,
    authorId: authorId.value,
    // The snapshot only travels with a real author id.
    authorName: authorId.value ? authorName.value : null,
    prompt: prompt.value,
    labels: labels.value,
  });
  open.value = false;
}
</script>

<template>
  <Modal v-model:open="open" size="xl" :aria-label="t('editor.aiText.editTitle', 'Edit AI text')">
    <template #title>{{ t('editor.aiText.editTitle', 'Edit AI text') }}</template>

    <div class="flex flex-col gap-next-5">
      <!-- ZONE 1 · VOICE -->
      <FormField :label="t('editor.aiText.author', 'Author')">
        <BotSelect
          v-model="authorId"
          status-badge
          :seed="authorSeed"
          :placeholder="t('editor.aiText.authorPlaceholder', 'No author (neutral tone)')"
          :aria-label="t('editor.aiText.authorAria', 'Choose the bot author for this text')"
          @update:selected="onAuthorSelected"
        >
          <!-- The selected author on the trigger. A since-deleted author keeps its snapshot
               name (NEVER struck through) and gains an explicit badge — the meaning is carried
               by the glyph + words, not by color. -->
          <template #value="{ option }">
            <Icon name="sparkles" class="shrink-0 text-next-muted-foreground" aria-hidden="true" />
            <span class="truncate">{{ option.label }}</span>
            <Badge
              v-if="authorMissing"
              variant="warning"
              size="sm"
              icon="alert-triangle"
              class="shrink-0"
            >
              {{ t('editor.aiText.authorMissingShort', 'Author unavailable') }}
            </Badge>
          </template>

          <!-- Two DIFFERENT empty states: an empty workspace is an invitation to create a bot;
               a fruitless search is an invitation to clear the query. -->
          <template #empty="{ query, setQuery }">
            <div v-if="query" class="flex flex-col items-start gap-next-2">
              <p class="text-next-sm text-next-fg">
                {{ t('editor.aiText.authorNoResults', 'No bots match “{query}”.', { query }) }}
              </p>
              <Button variant="ghost" size="sm" @click="setQuery('')">
                {{ t('editor.aiText.authorClearSearch', 'Clear search') }}
              </Button>
            </div>
            <div v-else class="flex flex-col items-start gap-next-2">
              <p class="text-next-sm font-next-medium text-next-fg">
                {{ t('editor.aiText.authorEmptyTitle', 'No bots in this workspace') }}
              </p>
              <p class="text-next-xs text-next-muted-foreground">
                {{ t('editor.aiText.authorEmptyBody', 'Without an author the block writes in a neutral tone. Create a bot to give it a voice.') }}
              </p>
              <!-- NEW TAB on purpose: this panel is a Modal inside a Drawer, so navigating in
                   place would throw away unsaved work. -->
              <Button
                variant="outline"
                size="sm"
                href="/next/bots"
                target="_blank"
                trailing-icon="external-link"
              >
                {{ t('editor.aiText.authorEmptyAction', 'Open Bots in a new tab') }}
              </Button>
            </div>
          </template>
        </BotSelect>

        <p class="text-next-xs text-next-muted-foreground">
          {{ t('editor.aiText.authorHint', 'A bot brings its voice: tone, style and vocabulary. It does not bring its knowledge or tools.') }}
        </p>

        <!-- Inactive: informational only. Delegation does not filter by status either, so
             hiding inactive bots here would lie about how the system behaves. -->
        <p
          v-if="authorInactive"
          class="flex items-start gap-next-1 text-next-xs text-next-muted-foreground"
        >
          <Icon name="circle" class="mt-px shrink-0" aria-hidden="true" />
          <span>{{ t('editor.aiText.authorInactive', 'This bot is inactive. Its voice will still be used in this block.') }}</span>
        </p>

        <!-- Gone (404/403). Keyed by the author id so it is announced once, not on every
             keystroke elsewhere in the panel. -->
        <Alert
          v-if="authorMissing"
          :key="`missing-${authorId}`"
          variant="warning"
          size="sm"
        >
          {{ t('editor.aiText.authorMissing', 'This author no longer exists. The block will use the default, neutral tone.') }}
          <template #actions>
            <Button variant="ghost" size="sm" @click="clearAuthor">
              {{ t('editor.aiText.authorClear', 'Clear author') }}
            </Button>
          </template>
        </Alert>

        <!-- Could not verify — explicitly NOT "deleted". -->
        <div
          v-else-if="authorUnverified"
          class="flex flex-wrap items-center gap-next-2 text-next-xs text-next-muted-foreground"
        >
          <Icon name="alert-circle" class="shrink-0" aria-hidden="true" />
          <span>{{ t('editor.aiText.authorCheckFailed', 'We couldn’t check this author.') }}</span>
          <Button variant="ghost" size="sm" @click="directory.retry(authorId)">
            {{ t('editor.aiText.authorRetry', 'Try again') }}
          </Button>
        </div>
      </FormField>

      <!-- Legacy tone: read-only, clearable, never re-selectable. -->
      <div
        v-if="hasLegacyTone"
        class="flex flex-col gap-next-2 rounded-next-md border border-next-border bg-next-muted/40 p-next-3"
      >
        <div class="flex flex-wrap items-center gap-next-2">
          <Badge variant="neutral" size="sm" icon="clock">
            {{ t('editor.aiText.legacyToneBadge', 'Legacy tone') }}
          </Badge>
          <span class="min-w-0 flex-1 truncate text-next-sm text-next-fg">{{ legacyToneLabel }}</span>
          <Button
            variant="ghost"
            size="sm"
            :aria-label="t('editor.aiText.legacyToneClearAria', 'Clear legacy tone: {tone}', { tone: legacyToneLabel })"
            @click="clearLegacyTone"
          >
            {{ t('editor.aiText.legacyToneClear', 'Clear tone') }}
          </Button>
        </div>
        <p class="text-next-xs text-next-muted-foreground">
          {{ t('editor.aiText.legacyToneHint', 'This block was saved with an old tone. It still works, but it can no longer be picked by hand.') }}
        </p>
        <p v-if="authorId" class="text-next-xs text-next-muted-foreground">
          {{ t('editor.aiText.legacyToneOverridden', 'The author takes precedence over the legacy tone.') }}
        </p>
      </div>
      <!-- Cleared in THIS session — the effect lands on Save, like every other field here. -->
      <p v-else-if="legacyToneCleared" class="text-next-xs text-next-muted-foreground">
        {{ t('editor.aiText.legacyToneCleared', 'Tone cleared — after saving, the block will use the neutral tone.') }}
      </p>

      <!-- ZONE 2 · CONTENT -->
      <div class="flex flex-col gap-next-1_5">
        <label class="text-next-sm font-next-medium text-next-fg">{{ t('editor.aiText.prompt', 'Prompt') }}</label>
        <MarkdownEditor
          v-model="prompt"
          :placeholder="t('editor.aiText.promptPlaceholder', 'Describe what the AI should generate…')"
          :variables="variables"
          :if-blocks="ifBlocks"
          min-height="6rem"
        />
        <p class="text-next-xs text-next-muted-foreground">
          {{ t('editor.aiText.promptHint', 'You can use full Markdown, variables and IF blocks.') }}
        </p>
      </div>
    </div>

    <template #footer>
      <Button variant="ghost" type="button" @click="emit('remove')">{{ t('editor.aiText.remove', 'Delete') }}</Button>
      <span class="flex-1" />
      <Button variant="outline" type="button" @click="open = false">{{ t('editor.aiText.cancel', 'Cancel') }}</Button>
      <Button variant="primary" type="button" @click="save">{{ t('editor.aiText.save', 'Save') }}</Button>
    </template>
  </Modal>
</template>
