<script setup lang="ts">
// BotEditorDrawer — create / edit a bot (AI Character). Redesign.
//
// Hosted inside BotsModuleLayout's query-driven `size="cover"` Drawer
// (`?bot=new` · `?bot=<id>`). Owns its OWN header (title + Cancel/Save).
//
// Layout:
//   • GENERAL INFO band (always visible): icon + name + status + description.
//   • MODULES as a left vertical nav (tabs) + a right content panel:
//       1. text (required, always on) · 2. task-execution · 3. knowledge ·
//       4. visual (placeholder) · 5. audio (placeholder).
//   • Every module EXCEPT the required text module has an enable Switch. When a
//     module is OFF its form is shown but INERT (disabled + greyed), with an info
//     panel explaining what the module does — the user can preview it before
//     turning it on. Visual/Audio are not-yet-available placeholders.
//
// Edits a LOCAL reactive state seeded once from the store's prefetched detail (the
// component is keyed by id in the layout, so it remounts per bot). `structuredClone`
// throws on Vue reactive proxies, so detail is cloned via `clonePlain` (JSON).
//
// Submitting builds the EXACT FormRequest payload (persona required; task_execution
// as the nested `{enabled, tools}` object or null; knowledge as `{enabled, entries}`;
// visual/audio omitted) and maps a 422 onto field errors, jumping to the first
// module that carries an error.
import { computed, onMounted, reactive, ref } from 'vue';
import FormField from '../../ui/forms/FormField.vue';
import TextInput from '../../ui/forms/TextInput.vue';
import Textarea from '../../ui/forms/Textarea.vue';
import Select, { type SelectOption } from '../../ui/forms/Select.vue';
import Switch from '../../ui/forms/Switch.vue';
import IconInput from '../../ui/forms/IconInput.vue';
import PillGroupInput from '../../ui/forms/PillGroupInput.vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Badge from '../../ui/primitives/Badge.vue';
import Skeleton from '../../ui/data/Skeleton.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import Alert from '../../ui/feedback/Alert.vue';
import KnowledgeEntriesInput from './KnowledgeEntriesInput.vue';
import { BOT_STATUSES } from './botStatus';
import { toolIcon, toolLabel, toolDescription, combineToolSelection } from './botToolMeta';
import { useBotsStore } from '../../app/stores/bots';
import { useBotToolRegistryStore } from '../../app/stores/botToolRegistry';
import { useToast } from '../../app/composables/useToast';
import { useI18n } from '../../app/i18n';
import type { IconName } from '../../ui/primitives/icons';
import type { BotDetail, BotKnowledgeEntry, BotStatus, BotWritePayload } from './types';

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
}
const MODULES: ModuleMeta[] = [
  { key: 'text', icon: 'file-text', required: true },
  { key: 'task_execution', icon: 'list-checks' },
  { key: 'knowledge', icon: 'bookmark' },
  { key: 'visual', icon: 'palette', soon: true },
  { key: 'audio', icon: 'bell', soon: true },
];
const activeModule = ref<ModuleKey>('text');

// --- Status select options ------------------------------------------------
const statusOptions = computed<SelectOption[]>(() =>
  BOT_STATUSES.map((value) => ({ value, label: t(`bots.statuses.${value}`) })),
);

// --- Local editor state ---------------------------------------------------
const form = reactive<{
  name: string;
  status: BotStatus;
  description: string;
  icon: IconName | null;
  persona: string;
  style: string;
  dictionary: string[];
  phrases: string[];
  prohibitions: string[];
  taskExecutionEnabled: boolean;
  tools: string[];
  knowledgeEnabled: boolean;
  knowledge: BotKnowledgeEntry[];
}>({
  name: '',
  status: 'draft',
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

const statusModel = computed<string | null>({
  get: () => form.status,
  set: (value) => {
    form.status = (value as BotStatus | null) ?? 'draft';
  },
});

// Whether a module is currently ENABLED (drives the nav indicator + inert forms).
function moduleEnabled(key: ModuleKey): boolean {
  if (key === 'text') return true;
  if (key === 'task_execution') return form.taskExecutionEnabled;
  if (key === 'knowledge') return form.knowledgeEnabled;
  return false; // visual / audio placeholders
}

// Seed ONCE from the prefetched detail when editing.
const detailError = ref(false);
if (isEdit.value) {
  const detail = store.detail && store.detail.id === props.botId ? store.detail : null;
  if (detail) {
    const cloned = clonePlain(detail);
    form.name = cloned.name;
    form.status = cloned.status;
    form.description = cloned.description ?? '';
    form.icon = (cloned.icon as IconName | null) ?? null;
    form.persona = cloned.persona ?? '';
    form.style = cloned.style ?? '';
    form.dictionary = cloned.dictionary ?? [];
    form.phrases = cloned.phrases ?? [];
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
}>({
  name: null,
  persona: null,
  tools: null,
  knowledge: null,
  knowledgeEntries: {},
});

/** Which module a given error belongs to (drives the nav error dot + jump-to). */
function moduleHasError(key: ModuleKey): boolean {
  if (key === 'text') return !!errors.persona;
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
  return {
    name: form.name.trim(),
    status: form.status,
    description: form.description.trim() || null,
    icon: form.icon,
    persona: form.persona.trim(),
    style: form.style.trim() || null,
    dictionary: form.dictionary,
    phrases: form.phrases,
    prohibitions: form.prohibitions,
    task_execution: hasTaskConfig ? { enabled: form.taskExecutionEnabled, tools: form.tools } : null,
    // Knowledge module: an explicitly-enabled module of {title, content} entries.
    knowledge: {
      enabled: form.knowledgeEnabled,
      entries: form.knowledge.map((k) => ({ title: k.title.trim(), content: k.content.trim() })),
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
  Object.entries(bag).forEach(([key, msgs]) => {
    const m = key.match(/^knowledge\.entries\.(\d+)\.(title|content)$/);
    if (!m || !msgs.length) return;
    const idx = Number(m[1]);
    entryErrors[idx] = { ...(entryErrors[idx] ?? {}), [m[2]]: msgs[0] };
  });
  errors.knowledgeEntries = entryErrors;
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
    <!-- Header: title + actions (the drawer adds no chrome). -->
    <header class="flex items-center justify-between gap-next-3 border-b border-next-border p-next-4">
      <h2 class="min-w-0 truncate text-next-lg font-next-semibold text-next-fg">
        {{ isEdit ? t('bots.editor.editTitle') : t('bots.editor.createTitle') }}
      </h2>
      <div class="flex shrink-0 items-center gap-next-2">
        <Button variant="ghost" :disabled="saving" @click="onCancel">
          {{ t('bots.editor.cancel') }}
        </Button>
        <Button leading-icon="check" :loading="saving" :disabled="detailError" @click="onSubmit">
          {{ saving ? t('bots.editor.saving') : t('bots.editor.save') }}
        </Button>
      </div>
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
        <div class="flex flex-col gap-next-3 next-sm:flex-row next-sm:items-start">
          <FormField :label="t('bots.editor.iconLabel')" class="shrink-0">
            <IconInput v-model="form.icon" :placeholder="t('bots.editor.iconPlaceholder')" />
          </FormField>
          <FormField
            :label="t('bots.editor.nameLabel')"
            required
            :error="errors.name ?? undefined"
            class="min-w-0 flex-1"
          >
            <TextInput v-model="form.name" :maxlength="255" :placeholder="t('bots.editor.namePlaceholder')" />
          </FormField>
          <FormField :label="t('bots.editor.statusLabel')" class="shrink-0 next-sm:w-48">
            <Select v-model="statusModel" :options="statusOptions" :aria-label="t('bots.editor.statusLabel')" />
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
          <button
            v-for="m in MODULES"
            :key="m.key"
            type="button"
            class="flex items-center gap-next-2 rounded-next-md px-next-3 py-next-2 text-left text-next-sm transition-colors"
            :class="
              activeModule === m.key
                ? 'bg-next-primary-subtle text-next-primary-subtle-foreground'
                : 'text-next-fg hover:bg-next-muted'
            "
            :aria-current="activeModule === m.key ? 'true' : undefined"
            @click="activeModule = m.key"
          >
            <Icon :name="m.icon" class="shrink-0" :class="!moduleEnabled(m.key) && !m.required ? 'opacity-60' : ''" />
            <span class="min-w-0 flex-1 truncate">{{ t(`bots.modules.${m.key === 'task_execution' ? 'taskExecution' : m.key}`) }}</span>
            <!-- State indicator: required / enabled / off / soon (+ error dot). -->
            <span
              v-if="moduleHasError(m.key)"
              class="h-2 w-2 shrink-0 rounded-full bg-next-danger"
              :aria-label="t('bots.editor.moduleHasError')"
            />
            <Badge v-else-if="m.required" variant="neutral" tone="subtle" size="sm">
              {{ t('bots.modules.state.required') }}
            </Badge>
            <Badge v-else-if="m.soon" variant="neutral" tone="subtle" size="sm">
              {{ t('bots.detail.comingSoon') }}
            </Badge>
            <Icon
              v-else-if="moduleEnabled(m.key)"
              name="check"
              class="shrink-0 text-next-success"
              :aria-label="t('bots.modules.state.enabled')"
            />
            <span v-else class="h-2 w-2 shrink-0 rounded-full bg-next-muted-foreground/40" :aria-label="t('bots.modules.state.disabled')" />
          </button>
        </nav>

        <!-- Right content panel (scrolls). -->
        <div class="min-h-0 flex-1 overflow-y-auto p-next-4">
          <!-- 1. TEXT MODULE (required, always on). -->
          <section v-show="activeModule === 'text'" class="flex flex-col gap-next-4">
            <div class="flex items-center justify-between gap-next-3">
              <div>
                <h3 class="text-next-base font-next-semibold text-next-fg">{{ t('bots.modules.text') }}</h3>
                <p class="text-next-xs text-next-muted-foreground">{{ t('bots.editor.textHint') }}</p>
              </div>
              <Badge variant="neutral" tone="subtle" size="sm">{{ t('bots.modules.state.required') }}</Badge>
            </div>

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

            <FormField :label="t('bots.editor.styleLabel')">
              <Textarea v-model="form.style" :rows="3" :maxlength="5000" :placeholder="t('bots.editor.stylePlaceholder')" />
            </FormField>

            <div class="grid grid-cols-1 gap-next-4 next-md:grid-cols-3">
              <FormField :label="t('bots.editor.dictionaryLabel')">
                <PillGroupInput v-model="form.dictionary" :placeholder="t('bots.editor.listPlaceholder')" :aria-label="t('bots.editor.dictionaryLabel')" />
              </FormField>
              <FormField :label="t('bots.editor.phrasesLabel')">
                <PillGroupInput v-model="form.phrases" :placeholder="t('bots.editor.listPlaceholder')" :aria-label="t('bots.editor.phrasesLabel')" />
              </FormField>
              <FormField :label="t('bots.editor.prohibitionsLabel')">
                <PillGroupInput v-model="form.prohibitions" :placeholder="t('bots.editor.listPlaceholder')" :aria-label="t('bots.editor.prohibitionsLabel')" />
              </FormField>
            </div>
          </section>

          <!-- 2. TASK-EXECUTION MODULE (enable toggle + inert-when-off preview). -->
          <section v-show="activeModule === 'task_execution'" class="flex flex-col gap-next-4">
            <div class="flex items-start justify-between gap-next-3">
              <div>
                <h3 class="text-next-base font-next-semibold text-next-fg">{{ t('bots.modules.taskExecution') }}</h3>
                <p class="text-next-xs text-next-muted-foreground">{{ t('bots.editor.taskExecutionHint') }}</p>
              </div>
              <label class="flex shrink-0 items-center gap-next-2 text-next-sm text-next-fg">
                <span>{{ t('bots.editor.enableModule') }}</span>
                <Switch v-model="form.taskExecutionEnabled" :aria-label="t('bots.editor.taskExecutionEnabledLabel')" />
              </label>
            </div>

            <Alert v-if="!form.taskExecutionEnabled" variant="info" size="sm">
              {{ t('bots.editor.taskExecutionDisabledInfo') }}
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

          <!-- 3. KNOWLEDGE MODULE (enable toggle + inert-when-off preview). -->
          <section v-show="activeModule === 'knowledge'" class="flex flex-col gap-next-4">
            <div class="flex items-start justify-between gap-next-3">
              <div>
                <h3 class="text-next-base font-next-semibold text-next-fg">{{ t('bots.modules.knowledge') }}</h3>
                <p class="text-next-xs text-next-muted-foreground">{{ t('bots.editor.knowledge.hint') }}</p>
              </div>
              <label class="flex shrink-0 items-center gap-next-2 text-next-sm text-next-fg">
                <span>{{ t('bots.editor.enableModule') }}</span>
                <Switch v-model="form.knowledgeEnabled" :aria-label="t('bots.editor.knowledgeEnabledLabel')" />
              </label>
            </div>

            <Alert v-if="!form.knowledgeEnabled" variant="info" size="sm">
              {{ t('bots.editor.knowledgeDisabledInfo') }}
            </Alert>

            <Alert v-if="errors.knowledge" variant="danger" size="sm">{{ errors.knowledge }}</Alert>

            <!-- Disabled-but-READABLE when off (fields disabled, not `inert`). -->
            <div
              :aria-disabled="!form.knowledgeEnabled ? 'true' : undefined"
              :class="!form.knowledgeEnabled && 'opacity-70'"
            >
              <KnowledgeEntriesInput
                v-model="form.knowledge"
                :entry-errors="errors.knowledgeEntries"
                :disabled="!form.knowledgeEnabled"
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
    </div>
  </div>
</template>
