<script setup lang="ts">
// TextStage — a TEXT file's preview IS its editor: the content loads into a monospace textarea,
// `dirty` tracks divergence from the loaded snapshot, and the toolbar carries the SAVE split
// button — "Zapisz" overwrites the file's content (disk-native + editable only), the toolbox
// menu offers "Zapisz jako…" (a new disk file). The shell owns the actual save calls; this
// stage emits `save`/`save-as` with the encoded payload and reports `update:dirty`.
//
// Above the editor sits an AI actions panel (mirrors ImageAiPanel): preset transforms + a free
// prompt post the whole buffer to the store's aiEditText and REPLACE it with the result (so
// `dirty` flips and Save enables). Single-flight (`aiBusy` disables every AI control + the
// textarea while running) with a one-step Undo of the last AI change. AI is offered whenever the
// content is loaded, independent of overwrite — a non-overwritable result is kept via "Zapisz jako…".
import { computed, ref, watch } from 'vue';
import Button from '../../../../ui/primitives/Button.vue';
import Icon from '../../../../ui/primitives/Icon.vue';
import Spinner from '../../../../ui/primitives/Spinner.vue';
import TextInput from '../../../../ui/forms/TextInput.vue';
import SegmentedControl, { type SegmentOption } from '../../../../ui/forms/SegmentedControl.vue';
import Skeleton from '../../../../ui/data/Skeleton.vue';
import DraftRestoreBanner from '../DraftRestoreBanner.vue';
// The diff engine is a DESIGN-SYSTEM component since B14 (the knowledge version drawer and the
// AI wizard read the same one); this stage is one of its hosts, not its owner.
import TextDiffView from '../../../../ui/data/TextDiffView.vue';
import type { IconName } from '../../../../ui/primitives/icons';
import { api } from '../../../../app/lib/api';
import { useDiskStore } from '../../../../app/stores/disk';
import { useToast } from '../../../../app/composables/useToast';
import { useI18n } from '../../../../app/i18n';
import { useDraftAutosave } from '../useDraftAutosave';
import type { DiskFile } from '../../types';

/** An AI transform shortcut: a fixed ENGLISH instruction (models follow English most reliably) with
 *  a localized chip label — the free prompt below sends whatever the user types instead. */
interface TextAiPreset {
  key: string;
  labelKey: string;
  icon: IconName;
  prompt: string;
}

const TEXT_AI_PRESETS: TextAiPreset[] = [
  {
    key: 'grammar',
    labelKey: 'disk.preview.ai.text.grammar',
    icon: 'check',
    prompt:
      'Fix all spelling, grammar and punctuation mistakes in the following text. Preserve the ' +
      'original meaning, language, tone and formatting. Return only the corrected text, with no ' +
      'explanations or commentary.',
  },
  {
    key: 'improve',
    labelKey: 'disk.preview.ai.text.improve',
    icon: 'sparkles',
    prompt:
      'Improve the writing of the following text: make it clearer, more natural and better ' +
      'structured while preserving its original meaning, language and intent. Return only the ' +
      'rewritten text, with no explanations or commentary.',
  },
  {
    key: 'shorten',
    labelKey: 'disk.preview.ai.text.shorten',
    icon: 'minus',
    prompt:
      'Make the following text shorter and more concise while keeping its key information, meaning ' +
      'and language. Return only the shortened text, with no explanations or commentary.',
  },
  {
    key: 'summarize',
    labelKey: 'disk.preview.ai.text.summarize',
    icon: 'list',
    prompt:
      'Summarize the following text into a short summary that captures its main points, in the ' +
      'same language as the text. Return only the summary, with no explanations or commentary.',
  },
];

const props = defineProps<{
  file: DiskFile;
  /** Whether "Zapisz" (overwrite) is offered — the shell derives it (disk-native + can_be_updated). */
  canOverwrite: boolean;
  saving: boolean;
}>();

const emit = defineEmits<{
  (e: 'update:dirty', dirty: boolean): void;
  (e: 'save', payload: { blob: Blob; filename: string }): void;
  (e: 'save-as', payload: { blob: Blob; defaultName: string }): void;
}>();

const { t } = useI18n();
const toast = useToast();
const store = useDiskStore();

const content = ref('');
const loaded = ref('');
const loading = ref(false);
const failed = ref(false);

const dirty = computed(() => content.value !== loaded.value);
watch(dirty, (d) => emit('update:dirty', d), { immediate: true });

// Editor AREA mode: the editable textarea ("Edycja") vs a read-only diff of the SAVED file
// (`loaded`) against the CURRENT buffer (`content`) ("Podgląd zmian"). The toggle ONLY swaps the
// area — the AI panel + Save controls act on `content` and stay visible/working in both modes.
const view = ref<'edit' | 'diff'>('edit');
/** The content has loaded (drives the AI panel AND the edit/diff toggle). Hidden while loading/failed. */
const contentReady = computed(() => !loading.value && !failed.value);
const viewOptions = computed<SegmentOption<'edit' | 'diff'>[]>(() => [
  { value: 'edit', label: t('disk.preview.text.edit', 'Edit') },
  { value: 'diff', label: t('disk.preview.text.review', 'Review changes') },
]);
function setView(v: 'edit' | 'diff' | null | ('edit' | 'diff')[]): void {
  view.value = v === 'diff' ? 'diff' : 'edit';
}

async function load(): Promise<void> {
  loading.value = true;
  failed.value = false;
  view.value = 'edit'; // each newly opened file starts in the editable view
  try {
    const inline = props.file.path + (props.file.path.includes('?') ? '&' : '?') + 'inline=1';
    const blob = await api.get<Blob>(inline, { responseType: 'blob' });
    loaded.value = await blob.text();
    content.value = loaded.value;
  } catch {
    failed.value = true;
  } finally {
    loading.value = false;
  }
}

watch(() => props.file.id, load, { immediate: true });

// Server-side autosave draft: the buffer is debounced-serialized so a refresh/crash never loses work;
// on reopen a pending draft drives the restore banner. `content` doubles as the change signal.
const draftAutosave = useDraftAutosave({
  fileId: () => props.file.id,
  fileVersion: () => props.file.updated_at_iso,
  kind: 'text',
  isDirty: dirty,
  changeSignal: content,
  // The list/info `has_draft` gates the restore probe: GET /{file}/draft fires ONLY when a draft
  // is known to exist.
  hasDraft: () => props.file.has_draft,
  serialize: async () => ({ manifest: { kind: 'text' as const, content: content.value }, bases: [] }),
  onRestoreText: (text) => {
    content.value = text; // diverges from `loaded` → dirty flips true → Save enables
  },
});
const { pendingDraft, stale: draftStale } = draftAutosave;

async function onRestoreDraft(): Promise<void> {
  await draftAutosave.restore();
  toast.success(t('disk.preview.draft.restored', 'Draft restored.'));
}
async function onDiscardDraft(): Promise<void> {
  await draftAutosave.discard();
  toast.info(t('disk.preview.draft.discarded', 'Draft discarded.'));
}

function encode(): Blob {
  return new Blob([content.value], { type: props.file.mime_type ?? 'text/plain' });
}

function onSave(): void {
  if (!dirty.value) return;
  emit('save', { blob: encode(), filename: props.file.name });
}

function copyName(name: string): string {
  const dot = name.lastIndexOf('.');
  return dot > 0 ? `${name.slice(0, dot)}-edited${name.slice(dot)}` : `${name}-edited`;
}

function onSaveAs(): void {
  emit('save-as', { blob: encode(), defaultName: copyName(props.file.name) });
}

/** The shell calls this after a successful overwrite so `dirty` resets to the saved state. */
function markSaved(): void {
  loaded.value = content.value;
  void draftAutosave.clear(); // the file now equals the edit → the draft is obsolete
}

defineExpose({ markSaved });

// --- AI actions --------------------------------------------------------------
const aiPrompt = ref('');
/** Single-flight: one AI request at a time; disables every AI control + the textarea while set. */
const aiBusy = ref(false);
/** Key of the action in flight (drives its button spinner); null when idle. */
const aiRunning = ref<string | null>(null);
/** The pre-edit content snapshot for a one-step undo of the last AI change; null = nothing to undo. */
const aiUndo = ref<string | null>(null);

/** Run one AI transform over the WHOLE buffer; replaces the content on success. Returns success. */
async function runAi(instruction: string, key: string): Promise<boolean> {
  const prompt = instruction.trim();
  if (aiBusy.value || !prompt) return false;
  const previous = content.value;
  aiBusy.value = true;
  aiRunning.value = key;
  try {
    const text = await store.aiEditText(content.value, prompt);
    content.value = text; // diverges from `loaded` → `dirty` flips true → Save enables
    view.value = 'diff'; // surface WHAT the AI changed straight away (read-only diff)
    aiUndo.value = previous; // snapshot so the change can be undone in one step
    return true;
  } catch (err: unknown) {
    const message = (err as { response?: { data?: { message?: string } } })?.response?.data?.message;
    toast.danger(message ?? t('disk.preview.ai.text.error', 'The AI edit failed. Please try again.'));
    return false;
  } finally {
    aiBusy.value = false;
    aiRunning.value = null;
  }
}

function runPreset(preset: TextAiPreset): void {
  void runAi(preset.prompt, preset.key);
}

async function runPrompt(): Promise<void> {
  if (await runAi(aiPrompt.value, 'prompt')) aiPrompt.value = '';
}

/** Restore the content to its pre-AI snapshot (one step) and clear the Undo affordance. */
function undoAi(): void {
  if (aiUndo.value === null) return;
  content.value = aiUndo.value;
  aiUndo.value = null;
}
</script>

<template>
  <div class="flex flex-col gap-next-3">
    <!-- Restore banner: an unsaved autosave draft was found on reopen. -->
    <DraftRestoreBanner
      v-if="pendingDraft"
      :updated-at="pendingDraft.updated_at"
      :stale="draftStale"
      @restore="onRestoreDraft"
      @discard="onDiscardDraft"
    />

    <!-- Type-specific toolbar row: the Edycja / Podgląd zmian view toggle (left) opposite the save
         split button (right). The toggle only swaps the editor AREA below; the save controls act on
         `content` and stay put in both modes. The toggle appears only once the content has loaded. -->
    <div class="flex flex-wrap items-center gap-next-2">
      <SegmentedControl
        v-if="contentReady"
        :model-value="view"
        :options="viewOptions"
        size="sm"
        :aria-label="t('disk.preview.text.viewMode', 'Text view')"
        @update:model-value="setView"
      />
      <div class="ml-auto flex items-center gap-next-2">
        <Button
          v-if="canOverwrite"
          :disabled="!dirty || aiBusy"
          :loading="saving"
          leading-icon="check"
          :menu-items="[{ value: 'save-as', label: t('disk.preview.saveAs', 'Save as…'), icon: 'copy' }]"
          :menu-aria-label="t('disk.preview.moreSaveOptions', 'More save options')"
          @click="onSave"
          @menu-select="onSaveAs"
        >
          {{ t('disk.preview.save', 'Save') }}
        </Button>
        <Button v-else :disabled="aiBusy" :loading="saving" leading-icon="copy" variant="outline" @click="onSaveAs">
          {{ t('disk.preview.saveAs', 'Save as…') }}
        </Button>
      </div>
    </div>

    <!-- AI actions — available whenever the content is loaded (independent of overwrite: a
         non-overwritable result is kept via "Zapisz jako…"). Hidden while loading/failed. -->
    <div v-if="contentReady" class="flex flex-col gap-next-2 rounded-next-lg border border-next-border bg-next-muted/20 p-next-3">
      <div class="flex flex-wrap items-center gap-next-2">
        <span class="flex items-center gap-next-1_5 text-next-xs font-next-medium uppercase tracking-wide text-next-muted-foreground">
          <Icon name="sparkles" aria-hidden="true" />
          {{ t('disk.editor.modes.ai', 'AI') }}
        </span>
        <Button
          v-for="preset in TEXT_AI_PRESETS"
          :key="preset.key"
          size="sm"
          variant="ghost"
          :leading-icon="preset.icon"
          :disabled="aiBusy"
          :loading="aiRunning === preset.key"
          @click="runPreset(preset)"
        >
          {{ t(preset.labelKey) }}
        </Button>
        <Button
          v-if="aiUndo !== null"
          size="sm"
          variant="outline"
          leading-icon="undo"
          :disabled="aiBusy"
          @click="undoAi"
        >
          {{ t('disk.preview.ai.text.undo', 'Undo AI change') }}
        </Button>
      </div>

      <!-- Free-text instruction (whole-content edit). -->
      <div class="flex min-w-56 items-center gap-next-2">
        <TextInput
          v-model="aiPrompt"
          size="sm"
          class="min-w-0 flex-1"
          :placeholder="t('disk.preview.ai.text.promptPlaceholder', 'Tell the AI what to change…')"
          :aria-label="t('disk.preview.ai.promptLabel', 'AI instruction')"
          :disabled="aiBusy"
          @keydown.enter="runPrompt"
        />
        <Button
          size="sm"
          variant="secondary"
          leading-icon="sparkles"
          :disabled="aiBusy || !aiPrompt.trim()"
          :loading="aiRunning === 'prompt'"
          @click="runPrompt"
        >
          {{ t('disk.preview.ai.text.apply', 'Apply') }}
        </Button>
      </div>

      <!-- In-flight status. -->
      <div v-if="aiBusy" class="flex items-center gap-next-2 text-next-sm text-next-muted-foreground" role="status" aria-live="polite">
        <Spinner size="sm" decorative />
        <span>{{ t('disk.preview.ai.text.processing', 'Editing…') }}</span>
      </div>
    </div>

    <!-- Editor area. `v-show` keeps the textarea MOUNTED while reviewing the diff so its content/
         scroll survive the round-trip and `content` stays bound for the diff's `:current`. -->
    <div v-show="view === 'edit'" class="rounded-next-lg border border-next-border bg-next-card">
      <Skeleton v-if="loading" class="m-next-4 h-64 rounded-next-md" />
      <p v-else-if="failed" class="m-next-4 flex items-center gap-next-2 text-next-sm text-next-danger" role="alert">
        <Icon name="alert-circle" aria-hidden="true" />
        {{ t('disk.preview.loadError', 'Could not load the file.') }}
      </p>
      <textarea
        v-else
        v-model="content"
        spellcheck="false"
        :disabled="aiBusy"
        class="block h-[60vh] w-full resize-y rounded-next-lg bg-transparent p-next-4 font-mono text-next-sm leading-relaxed text-next-fg outline-none disabled:cursor-not-allowed disabled:opacity-60"
        :aria-label="t('disk.preview.textEditor', 'File content')"
      />
    </div>

    <!-- Review changes: a read-only GitHub-style diff of the saved file vs the current buffer. -->
    <TextDiffView v-if="view === 'diff'" :old="loaded" :current="content" />
  </div>
</template>
