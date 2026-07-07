<script setup lang="ts">
// BotEditorDrawer — create / edit a bot (AI Character). Redesign (user feedback).
//
// Hosted inside BotsModuleLayout's query-driven `size="cover"` Drawer
// (`?bot=new` · `?bot=<id>`). SLIM header (title only); Cancel/Save live in a
// STICKY BOTTOM FOOTER that stays visible while the body scrolls.
//
// Layout:
//   • GENERAL INFO band (always visible): icon + name + description. Status is NOT
//     here — a bot is created inactive and toggled via the detail's status action
//     (`PATCH /bots/{id}/status`), never through this form.
//   • MODULES as a left vertical nav + a right content panel:
//       1. text (required, always on) · 2. task-execution · 3. knowledge ·
//       4. visual (soon) · 5. audio (soon).
//   • The enable Switch for the toggleable modules (task-execution + knowledge)
//     lives IN THE NAV ROW; ENABLED modules stand out (accent left-border + filled
//     dot + bolder label). Each content panel ALWAYS shows the module's description
//     at the top; when a module is OFF its fields stay visible but disabled/readable.
//
// Edits a LOCAL reactive state seeded once from the store's prefetched detail (the
// component is keyed by id in the layout, so it remounts per bot). `structuredClone`
// throws on Vue reactive proxies, so detail is cloned via `clonePlain` (JSON).
//
// Submitting builds the EXACT FormRequest payload (persona required; dictionary/
// phrases as object arrays; task_execution as `{enabled, tools}` or null; knowledge
// as `{enabled, entries}`; NO status; visual/audio omitted) and maps a 422 onto
// field errors, jumping to the first module that carries an error.
import { computed, onMounted, reactive, ref } from 'vue';
import FormField from '../../ui/forms/FormField.vue';
import TextInput from '../../ui/forms/TextInput.vue';
import Textarea from '../../ui/forms/Textarea.vue';
import Select, { type SelectOption } from '../../ui/forms/Select.vue';
import Switch from '../../ui/forms/Switch.vue';
import IconInput from '../../ui/forms/IconInput.vue';
import StringListInput from './StringListInput.vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Badge from '../../ui/primitives/Badge.vue';
import Skeleton from '../../ui/data/Skeleton.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import Alert from '../../ui/feedback/Alert.vue';
import EntryListInput from './EntryListInput.vue';
import { toolIcon, toolLabel, toolDescription, combineToolSelection } from './botToolMeta';
import { useBotsStore } from '../../app/stores/bots';
import { useBotToolRegistryStore } from '../../app/stores/botToolRegistry';
import { useToast } from '../../app/composables/useToast';
import { useI18n } from '../../app/i18n';
import type { IconName } from '../../ui/primitives/icons';
import type { BotDetail, BotDictionaryEntry, BotPhraseEntry, BotWritePayload } from './types';

const props = defineProps<{
  /** Bot id to edit, or null to create a new one. */
  botId: string | null;
}>();

const emit = defineEmits<{
  (e: 'close'): void;
  (e: 'saved', bot: BotDetail): void;
}>();

const { t } = useI18n();
const store = useBotsStore();
const toolRegistry = useBotToolRegistryStore();
const toast = useToast();

function clonePlain<T>(value: T): T {
  return JSON.parse(JSON.stringify(value)) as T;
}

const isEdit = computed(() => props.botId !== null);

// --- Modules (the left nav) -----------------------------------------------
type ModuleKey = 'text' | 'task_execution' | 'knowledge' | 'visual' | 'audio';
interface ModuleMeta {
  key: ModuleKey;
  icon: IconName;
  /** Required modules are always on (no toggle). */
  required?: boolean;
  /** Placeholder modules can't be enabled yet. */
  soon?: boolean;
  /** Toggleable modules carry an enable Switch IN their nav row. */
  toggleable?: boolean;
}
const MODULES: ModuleMeta[] = [
  { key: 'text', icon: 'file-text', required: true },
  { key: 'task_execution', icon: 'list-checks', toggleable: true },
  { key: 'knowledge', icon: 'bookmark', toggleable: true },
  { key: 'visual', icon: 'palette', soon: true },
  { key: 'audio', icon: 'bell', soon: true },
];
const activeModule = ref<ModuleKey>('text');

// --- Local editor state ---------------------------------------------------
// Status is NOT part of the form (toggled via the detail's status action).
const form = reactive<{
  name: string;
  description: string;
  icon: IconName | null;
  persona: string;
  style: string;
  dictionary: Record<string, string>[];
  phrases: Record<string, string>[];
  prohibitions: string[];
  taskExecutionEnabled: boolean;
  tools: string[];
  knowledgeEnabled: boolean;
  knowledge: Record<string, string>[];
}>({
  name: '',
  description: '',
  icon: null,
  persona: '',
  style: '',
  dictionary: [],
  phrases: [],
  prohibitions: [],
  taskExecutionEnabled: false,
  tools: [],
  knowledgeEnabled: false,
  knowledge: [],
});

// Whether a module is currently ENABLED (drives the nav emphasis + inert forms).
function moduleEnabled(key: ModuleKey): boolean {
  if (key === 'text') return true;
  if (key === 'task_execution') return form.taskExecutionEnabled;
  if (key === 'knowledge') return form.knowledgeEnabled;
  return false; // visual / audio placeholders
}

/** Toggle a module's enable Switch from its nav row (v-model bridge). */
function moduleToggle(key: ModuleKey): boolean {
  return key === 'task_execution' ? form.taskExecutionEnabled : form.knowledgeEnabled;
}
function setModuleToggle(key: ModuleKey, value: boolean): void {
  if (key === 'task_execution') form.taskExecutionEnabled = value;
  else if (key === 'knowledge') form.knowledgeEnabled = value;
}

// Seed ONCE from the prefetched detail when editing.
const detailError = ref(false);
if (isEdit.value) {
  const detail = store.detail && store.detail.id === props.botId ? store.detail : null;
  if (detail) {
    const cloned = clonePlain(detail);
    form.name = cloned.name;
    form.description = cloned.description ?? '';
    form.icon = (cloned.icon as IconName | null) ?? null;
    form.persona = cloned.persona ?? '';
    form.style = cloned.style ?? '';
    // dictionary/phrases arrive as object arrays (accessor-normalized server-side).
    form.dictionary = (cloned.dictionary ?? []).map((d) => ({ term: d.term, meaning: d.meaning }));
    form.phrases = (cloned.phrases ?? []).map((p) => ({ phrase: p.phrase, context: p.context ?? '' }));
    form.prohibitions = cloned.prohibitions ?? [];
    if (cloned.task_execution) {
      form.taskExecutionEnabled = cloned.task_execution.enabled;
      form.tools = cloned.task_execution.tools ?? [];
    }
    form.knowledgeEnabled = cloned.knowledge?.enabled ?? false;
    form.knowledge = (cloned.knowledge?.entries ?? []).map((k) => ({ title: k.title, content: k.content }));
  } else {
    detailError.value = true;
  }
}

// --- Tool registry --------------------------------------------------------
onMounted(() => void toolRegistry.fetchRegistry());

const toolOptions = computed<SelectOption[]>(() =>
  toolRegistry.availableIds().map((id) => ({
    value: id,
    label: toolLabel(id, t),
    icon: toolIcon(id),
  })),
);

const unavailableSelectedTools = computed<string[]>(() =>
  form.tools.filter((id) => !toolRegistry.isAvailable(id)),
);

const selectedAvailableTools = computed<string[]>({
  get: () => form.tools.filter((id) => toolRegistry.isAvailable(id)),
  set: (ids) => {
    form.tools = combineToolSelection(ids, form.tools, (id) => toolRegistry.isAvailable(id));
  },
});

function removeUnavailableTool(id: string): void {
  form.tools = form.tools.filter((t0) => t0 !== id);
}

// --- Validation -----------------------------------------------------------
const errors = reactive<{
  name: string | null;
  persona: string | null;
  tools: string | null;
  knowledge: string | null;
  knowledgeEntries: Record<number, { title?: string; content?: string }>;
  /** Per-row dictionary errors: `{ <i>: { term?, meaning? } }`. */
  dictionaryEntries: Record<number, Record<string, string>>;
  /** Per-row phrases errors: `{ <i>: { phrase?, context? } }`. */
  phraseEntries: Record<number, Record<string, string>>;
}>({
  name: null,
  persona: null,
  tools: null,
  knowledge: null,
  knowledgeEntries: {},
  dictionaryEntries: {},
  phraseEntries: {},
});

/** Which module a given error belongs to (drives the nav error dot + jump-to). */
function moduleHasError(key: ModuleKey): boolean {
  if (key === 'text') {
    return (
      !!errors.persona ||
      Object.keys(errors.dictionaryEntries).length > 0 ||
      Object.keys(errors.phraseEntries).length > 0
    );
  }
  if (key === 'task_execution') return !!errors.tools;
  if (key === 'knowledge') return !!errors.knowledge || Object.keys(errors.knowledgeEntries).length > 0;
  return false;
}

/** Focus the first module that carries an error (name lives in the always-visible band). */
function jumpToFirstError(): void {
  const first = MODULES.find((m) => moduleHasError(m.key));
  if (first) activeModule.value = first.key;
}

function validate(): boolean {
  errors.name = null;
  errors.persona = null;
  errors.tools = null;
  errors.knowledge = null;
  errors.knowledgeEntries = {};
  errors.dictionaryEntries = {};
  errors.phraseEntries = {};
  let ok = true;
  if (!form.name.trim()) {
    errors.name = t('bots.editor.validation.nameRequired');
    ok = false;
  }
  if (!form.persona.trim()) {
    errors.persona = t('bots.editor.validation.personaRequired');
    ok = false;
  }
  if (!ok) jumpToFirstError();
  return ok;
}

// --- Submit ---------------------------------------------------------------
const saving = ref(false);

function buildPayload(): BotWritePayload {
  // task_execution is sent as the nested object whenever the user enabled it OR
  // selected any tools; otherwise null (the module is off).
  const hasTaskConfig = form.taskExecutionEnabled || form.tools.length > 0;
  // dictionary/phrases: trim + drop rows the user left entirely blank (a partial
  // row is kept so the server 422 can point the user at the missing field).
  const dictionary: BotDictionaryEntry[] = form.dictionary
    .map((d) => ({ term: (d.term ?? '').trim(), meaning: (d.meaning ?? '').trim() }))
    .filter((d) => d.term !== '' || d.meaning !== '');
  const phrases: BotPhraseEntry[] = form.phrases
    .map((p) => ({ phrase: (p.phrase ?? '').trim(), context: (p.context ?? '').trim() || null }))
    .filter((p) => p.phrase !== '' || (p.context ?? '') !== '');
  return {
    // NO status — a bot is created inactive; status toggles via PATCH /status.
    name: form.name.trim(),
    description: form.description.trim() || null,
    icon: form.icon,
    persona: form.persona.trim(),
    style: form.style.trim() || null,
    dictionary,
    phrases,
    prohibitions: form.prohibitions,
    task_execution: hasTaskConfig ? { enabled: form.taskExecutionEnabled, tools: form.tools } : null,
    // Knowledge module: an explicitly-enabled module of {title, content} entries.
    knowledge: {
      enabled: form.knowledgeEnabled,
      entries: form.knowledge.map((k) => ({ title: (k.title ?? '').trim(), content: (k.content ?? '').trim() })),
    },
    // visual / audio are NOT writable (placeholders) → never sent.
  };
}

/** Map a server 422 validation bag onto the local field errors, then jump to it. */
function applyServerErrors(err: unknown): void {
  const bag = (err as { response?: { data?: { errors?: Record<string, string[]> } } })?.response?.data?.errors;
  if (!bag) return;
  if (bag.name?.length) errors.name = bag.name[0];
  if (bag.persona?.length) errors.persona = bag.persona[0];
  const toolsKey = Object.keys(bag).find(
    (k) => k === 'task_execution.tools' || /^task_execution\.tools\.\d+$/.test(k),
  );
  if (toolsKey && bag[toolsKey]?.length) errors.tools = bag[toolsKey][0];

  // Knowledge: bag-level `knowledge.entries` → section message; per-row keys →
  // `knowledge.entries.<i>.(title|content)`.
  const knowledgeBag = bag['knowledge.entries'] ?? bag.knowledge;
  if (knowledgeBag?.length) errors.knowledge = knowledgeBag[0];
  const entryErrors: Record<number, { title?: string; content?: string }> = {};
  const dictErrors: Record<number, Record<string, string>> = {};
  const phraseErrors: Record<number, Record<string, string>> = {};
  Object.entries(bag).forEach(([key, msgs]) => {
    if (!msgs.length) return;
    // knowledge.entries.<i>.(title|content)
    const km = key.match(/^knowledge\.entries\.(\d+)\.(title|content)$/);
    if (km) {
      const idx = Number(km[1]);
      entryErrors[idx] = { ...(entryErrors[idx] ?? {}), [km[2]]: msgs[0] };
      return;
    }
    // dictionary.<i>.(term|meaning)
    const dm = key.match(/^dictionary\.(\d+)\.(term|meaning)$/);
    if (dm) {
      const idx = Number(dm[1]);
      dictErrors[idx] = { ...(dictErrors[idx] ?? {}), [dm[2]]: msgs[0] };
      return;
    }
    // phrases.<i>.(phrase|context)
    const pm = key.match(/^phrases\.(\d+)\.(phrase|context)$/);
    if (pm) {
      const idx = Number(pm[1]);
      phraseErrors[idx] = { ...(phraseErrors[idx] ?? {}), [pm[2]]: msgs[0] };
    }
  });
  errors.knowledgeEntries = entryErrors;
  errors.dictionaryEntries = dictErrors;
  errors.phraseEntries = phraseErrors;
  jumpToFirstError();
}

async function onSubmit(): Promise<void> {
  if (saving.value) return;
  if (!validate()) return;
  saving.value = true;
  const payload = buildPayload();
  try {
    const result =
      isEdit.value && props.botId
        ? await store.updateBot(props.botId, payload)
        : await store.createBot(payload);
    toast.success(t(isEdit.value ? 'bots.editor.toasts.updated' : 'bots.editor.toasts.created'));
    emit('saved', result);
  } catch (err: unknown) {
    applyServerErrors(err);
    toast.danger(t('bots.editor.toasts.error'));
  } finally {
    saving.value = false;
  }
}

function onCancel(): void {
  emit('close');
}
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col">
    <!-- SLIM header: just the title (Cancel/Save moved to the sticky footer). -->
    <header class="flex items-center gap-next-3 border-b border-next-border p-next-4">
      <h2 class="min-w-0 truncate text-next-lg font-next-semibold text-next-fg">
        {{ isEdit ? t('bots.editor.editTitle') : t('bots.editor.createTitle') }}
      </h2>
    </header>

    <!-- Deep-link without a prefetched detail → a clear error (no blank form). -->
    <EmptyState
      v-if="detailError"
      variant="error"
      class="m-next-4"
      :title="t('bots.editor.editTitle')"
      :description="t('bots.editor.detailError')"
    >
      <template #action>
        <Button variant="outline" size="sm" @click="onCancel">
          {{ t('bots.editor.cancel') }}
        </Button>
      </template>
    </EmptyState>

    <div v-else class="flex min-h-0 flex-1 flex-col">
      <!-- GENERAL INFO — always visible above the modules. -->
      <div class="flex flex-col gap-next-3 border-b border-next-border p-next-4">
        <!-- Name on the LEFT, icon on the RIGHT (matches every other form); the icon
             control is a FIXED width so it doesn't grow/shrink with the chosen name. -->
        <div class="flex flex-col gap-next-3 next-sm:flex-row next-sm:items-start">
          <FormField
            :label="t('bots.editor.nameLabel')"
            required
            :error="errors.name ?? undefined"
            class="min-w-0 flex-1"
          >
            <TextInput v-model="form.name" :maxlength="255" :placeholder="t('bots.editor.namePlaceholder')" />
          </FormField>
          <FormField :label="t('bots.editor.iconLabel')" class="w-full shrink-0 next-sm:w-64">
            <IconInput v-model="form.icon" :placeholder="t('bots.editor.iconPlaceholder')" />
          </FormField>
        </div>
        <FormField :label="t('bots.editor.descriptionLabel')">
          <Textarea
            v-model="form.description"
            :rows="2"
            :maxlength="2500"
            :placeholder="t('bots.editor.descriptionPlaceholder')"
          />
        </FormField>
      </div>

      <!-- MODULES: left nav (tabs) + right content panel. -->
      <div class="flex min-h-0 flex-1 flex-col next-md:flex-row">
        <!-- Left module nav. -->
        <nav
          class="flex shrink-0 gap-next-1 overflow-x-auto border-b border-next-border p-next-2 next-md:w-64 next-md:flex-col next-md:overflow-x-visible next-md:overflow-y-auto next-md:border-b-0 next-md:border-r"
          :aria-label="t('bots.editor.modulesNavLabel')"
        >
          <!-- Each module is a nav ROW: a select-the-panel button + (for toggleable
               modules) an inline enable Switch. ENABLED modules get an accent
               left-border + filled dot + bolder label so they stand out; the
               active-panel highlight is a distinct background. -->
          <div
            v-for="m in MODULES"
            :key="m.key"
            class="flex items-center gap-next-1 rounded-next-md border-l-2 pr-next-1 transition-colors"
            :class="[
              activeModule === m.key ? 'bg-next-primary-subtle' : 'hover:bg-next-muted',
              moduleEnabled(m.key) && !m.required ? 'border-next-primary' : 'border-transparent',
            ]"
          >
            <button
              type="button"
              class="flex min-w-0 flex-1 items-center gap-next-2 px-next-3 py-next-2 text-left text-next-sm transition-colors"
              :class="activeModule === m.key ? 'text-next-primary-subtle-foreground' : 'text-next-fg'"
              :aria-current="activeModule === m.key ? 'true' : undefined"
              @click="activeModule = m.key"
            >
              <Icon
                :name="m.icon"
                class="shrink-0"
                :class="!moduleEnabled(m.key) && !m.required ? 'opacity-50' : ''"
              />
              <span
                class="min-w-0 flex-1 truncate"
                :class="moduleEnabled(m.key) && !m.required ? 'font-next-semibold' : ''"
              >
                {{ t(`bots.modules.${m.key === 'task_execution' ? 'taskExecution' : m.key}`) }}
              </span>
              <!-- State indicator (never color-only): error / required / soon / on / off. -->
              <span
                v-if="moduleHasError(m.key)"
                class="flex items-center gap-next-1 text-next-2xs font-next-medium text-next-danger"
              >
                <Icon name="alert-circle" class="shrink-0" aria-hidden="true" />
                <span class="sr-only">{{ t('bots.editor.moduleHasError') }}</span>
              </span>
              <span v-else-if="m.required" class="shrink-0 text-next-2xs text-next-muted-foreground">
                {{ t('bots.modules.state.required') }}
              </span>
              <span v-else-if="m.soon" class="shrink-0 text-next-2xs text-next-muted-foreground">
                {{ t('bots.modules.state.soon') }}
              </span>
              <span
                v-else
                class="flex shrink-0 items-center gap-next-1 text-next-2xs font-next-medium"
                :class="moduleEnabled(m.key) ? 'text-next-primary' : 'text-next-muted-foreground'"
              >
                <span
                  class="h-2 w-2 rounded-full"
                  :class="moduleEnabled(m.key) ? 'bg-next-primary' : 'bg-next-muted-foreground/40'"
                  aria-hidden="true"
                />
                {{ moduleEnabled(m.key) ? t('bots.modules.state.on') : t('bots.modules.state.off') }}
              </span>
            </button>
            <!-- Inline enable Switch (toggleable modules only). -->
            <Switch
              v-if="m.toggleable"
              :model-value="moduleToggle(m.key)"
              size="sm"
              class="shrink-0"
              :aria-label="t('bots.editor.enableModuleNamed', '', { module: t(`bots.modules.${m.key === 'task_execution' ? 'taskExecution' : m.key}`) })"
              @update:model-value="(v: boolean) => setModuleToggle(m.key, v)"
            />
          </div>
        </nav>

        <!-- Right content panel (scrolls). -->
        <div class="min-h-0 flex-1 overflow-y-auto p-next-4">
          <!-- 1. TEXT MODULE (required, always on). -->
          <section v-show="activeModule === 'text'" class="flex flex-col gap-next-5">
            <div class="flex items-center justify-between gap-next-3">
              <h3 class="text-next-base font-next-semibold text-next-fg">{{ t('bots.modules.text') }}</h3>
              <span class="shrink-0 text-next-xs text-next-muted-foreground">{{ t('bots.modules.state.required') }}</span>
            </div>
            <!-- Persistent module description (never hidden). -->
            <Alert variant="info" size="sm">{{ t('bots.editor.textHint') }}</Alert>

            <FormField
              :label="t('bots.editor.personaLabel')"
              required
              :description="t('bots.editor.personaHint')"
              :error="errors.persona ?? undefined"
            >
              <Textarea
                v-model="form.persona"
                :rows="6"
                :maxlength="10000"
                counter
                :placeholder="t('bots.editor.personaPlaceholder')"
              />
            </FormField>

            <FormField :label="t('bots.editor.styleLabel')" :description="t('bots.editor.styleHint')">
              <Textarea v-model="form.style" :rows="3" :maxlength="5000" :placeholder="t('bots.editor.stylePlaceholder')" />
            </FormField>

            <!-- Three stacked, full-width sub-sections (two-field rows won't fit a
                 3-column grid): Dictionary (gwara) + Phrases + Prohibitions. -->
            <FormField :label="t('bots.editor.dictionary.label')" :description="t('bots.editor.dictionary.hint')">
              <EntryListInput
                v-model="form.dictionary"
                primary-key="term"
                secondary-key="meaning"
                :secondary-rows="2"
                :primary-label="t('bots.editor.dictionary.termLabel')"
                :primary-placeholder="t('bots.editor.dictionary.termPlaceholder')"
                :secondary-label="t('bots.editor.dictionary.meaningLabel')"
                :secondary-placeholder="t('bots.editor.dictionary.meaningPlaceholder')"
                :add-label="t('bots.editor.dictionary.add')"
                :empty-label="t('bots.editor.dictionary.empty')"
                :entry-errors="errors.dictionaryEntries"
                :max="100"
              />
            </FormField>

            <FormField :label="t('bots.editor.phrases.label')" :description="t('bots.editor.phrases.hint')">
              <EntryListInput
                v-model="form.phrases"
                primary-key="phrase"
                secondary-key="context"
                secondary-optional
                :secondary-rows="2"
                :primary-label="t('bots.editor.phrases.phraseLabel')"
                :primary-placeholder="t('bots.editor.phrases.phrasePlaceholder')"
                :secondary-label="t('bots.editor.phrases.contextLabel')"
                :secondary-placeholder="t('bots.editor.phrases.contextPlaceholder')"
                :add-label="t('bots.editor.phrases.add')"
                :empty-label="t('bots.editor.phrases.empty')"
                :entry-errors="errors.phraseEntries"
                :max="100"
              />
            </FormField>

            <FormField :label="t('bots.editor.prohibitions.label')" :description="t('bots.editor.prohibitions.hint')">
              <StringListInput
                v-model="form.prohibitions"
                :placeholder="t('bots.editor.prohibitions.placeholder')"
                :add-label="t('bots.editor.prohibitions.add')"
                :empty-label="t('bots.editor.prohibitions.empty')"
                :remove-label="t('bots.editor.prohibitions.remove')"
                :max="100"
              />
            </FormField>
          </section>

          <!-- 2. TASK-EXECUTION MODULE (enabled from the nav; inert-when-off preview). -->
          <section v-show="activeModule === 'task_execution'" class="flex flex-col gap-next-4">
            <div class="flex items-center justify-between gap-next-3">
              <h3 class="text-next-base font-next-semibold text-next-fg">{{ t('bots.modules.taskExecution') }}</h3>
              <span
                class="flex shrink-0 items-center gap-next-1 text-next-xs font-next-medium"
                :class="form.taskExecutionEnabled ? 'text-next-primary' : 'text-next-muted-foreground'"
              >
                <span
                  class="h-2 w-2 rounded-full"
                  :class="form.taskExecutionEnabled ? 'bg-next-primary' : 'bg-next-muted-foreground/40'"
                  aria-hidden="true"
                />
                {{ form.taskExecutionEnabled ? t('bots.modules.state.on') : t('bots.modules.state.off') }}
              </span>
            </div>
            <!-- Persistent module description (never hidden — on OR off). -->
            <Alert variant="info" size="sm">
              {{ t('bots.editor.taskExecutionHint') }}
              <template v-if="!form.taskExecutionEnabled"> {{ t('bots.editor.moduleOffHint') }}</template>
            </Alert>

            <!-- Disabled-but-READABLE when off: fields are `disabled` (kept in the AT
                 tree, unlike `inert`) so a screen-reader user can still preview it. -->
            <div
              :aria-disabled="!form.taskExecutionEnabled ? 'true' : undefined"
              :class="!form.taskExecutionEnabled && 'opacity-70'"
            >
              <FormField
                :label="t('bots.editor.toolsLabel')"
                :description="t('bots.editor.toolsHint')"
                :error="errors.tools ?? undefined"
              >
                <div v-if="toolRegistry.loading && !toolRegistry.loaded" class="flex flex-col gap-next-2" role="status" :aria-label="t('common.loading')">
                  <Skeleton variant="rect" height="2.5rem" radius="md" />
                </div>

                <Alert v-else-if="toolRegistry.error" variant="danger" size="sm">
                  <div class="flex items-center justify-between gap-next-2">
                    <span>{{ t('bots.tools.loadError') }}</span>
                    <Button size="sm" variant="outline" leading-icon="rotate-ccw" :loading="toolRegistry.loading" @click="toolRegistry.retry()">
                      {{ t('bots.errors.retry') }}
                    </Button>
                  </div>
                </Alert>

                <p v-else-if="!toolOptions.length" class="rounded-next-md bg-next-muted px-next-3 py-next-2 text-next-xs text-next-muted-foreground">
                  {{ t('bots.tools.empty') }}
                </p>

                <Select
                  v-else
                  v-model:values="selectedAvailableTools"
                  :options="toolOptions"
                  multiple
                  searchable
                  :disabled="!form.taskExecutionEnabled"
                  leading-icon="settings"
                  :placeholder="t('bots.editor.toolsPlaceholder')"
                  :search-placeholder="t('bots.tools.search')"
                  :aria-label="t('bots.editor.toolsLabel')"
                >
                  <template #option="{ option }">
                    <Icon :name="(option as any).icon ?? 'settings'" class="mt-px shrink-0 text-next-muted-foreground" />
                    <span class="flex min-w-0 flex-1 flex-col">
                      <span class="truncate text-next-sm">{{ option.label }}</span>
                      <span v-if="toolDescription(String(option.value), t)" class="truncate text-next-xs text-next-muted-foreground">
                        {{ toolDescription(String(option.value), t) }}
                      </span>
                    </span>
                  </template>
                </Select>

                <div v-if="unavailableSelectedTools.length" class="mt-next-2 flex flex-col gap-next-1_5">
                  <span class="text-next-xs text-next-muted-foreground">{{ t('bots.tools.unavailableHint') }}</span>
                  <div class="flex flex-wrap gap-next-1">
                    <Badge
                      v-for="id in unavailableSelectedTools"
                      :key="id"
                      variant="neutral"
                      tone="subtle"
                      size="sm"
                      icon="alert-circle"
                      :trailing-action="{ icon: 'x', label: t('bots.tools.removeUnavailable', '', { tool: toolLabel(id, t) }) }"
                      :title="t('bots.tools.unavailableTitle')"
                      @action="removeUnavailableTool(id)"
                    >
                      {{ toolLabel(id, t) }}
                    </Badge>
                  </div>
                </div>
              </FormField>
            </div>
          </section>

          <!-- 3. KNOWLEDGE MODULE (enabled from the nav; inert-when-off preview). -->
          <section v-show="activeModule === 'knowledge'" class="flex flex-col gap-next-4">
            <div class="flex items-center justify-between gap-next-3">
              <h3 class="text-next-base font-next-semibold text-next-fg">{{ t('bots.modules.knowledge') }}</h3>
              <span
                class="flex shrink-0 items-center gap-next-1 text-next-xs font-next-medium"
                :class="form.knowledgeEnabled ? 'text-next-primary' : 'text-next-muted-foreground'"
              >
                <span
                  class="h-2 w-2 rounded-full"
                  :class="form.knowledgeEnabled ? 'bg-next-primary' : 'bg-next-muted-foreground/40'"
                  aria-hidden="true"
                />
                {{ form.knowledgeEnabled ? t('bots.modules.state.on') : t('bots.modules.state.off') }}
              </span>
            </div>
            <!-- Persistent module description (never hidden — on OR off). -->
            <Alert variant="info" size="sm">
              {{ t('bots.editor.knowledge.hint') }}
              <template v-if="!form.knowledgeEnabled"> {{ t('bots.editor.moduleOffHint') }}</template>
            </Alert>

            <Alert v-if="errors.knowledge" variant="danger" size="sm">{{ errors.knowledge }}</Alert>

            <!-- Disabled-but-READABLE when off (fields disabled, not `inert`). -->
            <div
              :aria-disabled="!form.knowledgeEnabled ? 'true' : undefined"
              :class="!form.knowledgeEnabled && 'opacity-70'"
            >
              <EntryListInput
                v-model="form.knowledge"
                primary-key="title"
                secondary-key="content"
                :secondary-rows="3"
                :primary-label="t('bots.editor.knowledge.titleLabel')"
                :primary-placeholder="t('bots.editor.knowledge.titlePlaceholder')"
                :secondary-label="t('bots.editor.knowledge.contentLabel')"
                :secondary-placeholder="t('bots.editor.knowledge.contentPlaceholder')"
                :add-label="t('bots.editor.knowledge.addEntry')"
                :empty-label="t('bots.editor.knowledge.empty')"
                :entry-errors="errors.knowledgeEntries"
                :disabled="!form.knowledgeEnabled"
                :max="50"
              />
            </div>
          </section>

          <!-- 4. VISUAL — placeholder (coming soon). -->
          <section v-show="activeModule === 'visual'" class="flex flex-col gap-next-4">
            <div class="flex items-start justify-between gap-next-3">
              <div>
                <h3 class="text-next-base font-next-semibold text-next-fg">{{ t('bots.modules.visual') }}</h3>
                <p class="text-next-xs text-next-muted-foreground">{{ t('bots.editor.visualHint') }}</p>
              </div>
              <Badge variant="neutral" tone="subtle" size="sm">{{ t('bots.detail.comingSoon') }}</Badge>
            </div>
            <div class="rounded-next-lg border border-dashed border-next-border bg-next-muted/40 p-next-6 text-center" aria-disabled="true">
              <Icon name="palette" class="mx-auto mb-next-2 text-next-muted-foreground" aria-hidden="true" />
              <p class="text-next-sm text-next-muted-foreground">{{ t('bots.editor.visualPlaceholder') }}</p>
            </div>
          </section>

          <!-- 5. AUDIO — placeholder (coming soon). -->
          <section v-show="activeModule === 'audio'" class="flex flex-col gap-next-4">
            <div class="flex items-start justify-between gap-next-3">
              <div>
                <h3 class="text-next-base font-next-semibold text-next-fg">{{ t('bots.modules.audio') }}</h3>
                <p class="text-next-xs text-next-muted-foreground">{{ t('bots.editor.audioHint') }}</p>
              </div>
              <Badge variant="neutral" tone="subtle" size="sm">{{ t('bots.detail.comingSoon') }}</Badge>
            </div>
            <div class="rounded-next-lg border border-dashed border-next-border bg-next-muted/40 p-next-6 text-center" aria-disabled="true">
              <Icon name="bell" class="mx-auto mb-next-2 text-next-muted-foreground" aria-hidden="true" />
              <p class="text-next-sm text-next-muted-foreground">{{ t('bots.editor.audioPlaceholder') }}</p>
            </div>
          </section>
        </div>
      </div>

      <!-- STICKY FOOTER: Cancel / Save stay visible while the body scrolls. -->
      <footer class="flex shrink-0 items-center justify-end gap-next-2 border-t border-next-border bg-next-card p-next-4">
        <Button variant="ghost" :disabled="saving" @click="onCancel">
          {{ t('bots.editor.cancel') }}
        </Button>
        <Button leading-icon="check" :loading="saving" @click="onSubmit">
          {{ saving ? t('bots.editor.saving') : t('bots.editor.save') }}
        </Button>
      </footer>
    </div>
  </div>
</template>
