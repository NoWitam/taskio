<script setup lang="ts">
// BotKnowledgeMigrationModal — lift a bot's BUILT-IN knowledge entries into a real knowledge base.
//
// One irreversible-looking act, so it is a modal with a PREVIEW rather than a button that just
// does it: the user sees which bot, how many entries, and what the base will be called before
// anything is created. (It is in fact additive — `bots.knowledge` is left untouched — and the
// preview says so, because "migrate" reads like "move" and a user who thinks their bot is about to
// be emptied will not press the button.)
//
// TWO HOSTS, ONE COMPONENT:
//   • the Knowledge bases screen's empty state ("import a bot's knowledge"), where the bot is PICKED;
//   • the bot editor's knowledge module, where the bot is FIXED (`:bot-id`) and only confirmed.
//
// MODULE DIRECTION. This lives under `pages/knowledge/` and imports NOTHING from `pages/bots/`
// except its wire types — mirroring the backend boundary, where the Bot module depends on Knowledge
// and never the other way round. The bot list comes from the shared `ui/forms/BotSelect`, and the
// mutation from the app-level bots store.
import { computed, ref, watch } from 'vue';
import Modal from '../../ui/overlay/Modal.vue';
import Button from '../../ui/primitives/Button.vue';
import Text from '../../ui/primitives/Text.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Badge from '../../ui/primitives/Badge.vue';
import FormField from '../../ui/forms/FormField.vue';
import BotSelect from '../../ui/forms/BotSelect.vue';
import Alert from '../../ui/feedback/Alert.vue';
import Skeleton from '../../ui/data/Skeleton.vue';
import Surface from '../../ui/layout/Surface.vue';
import { api } from '../../app/lib/api';
import { useBotsStore } from '../../app/stores/bots';
import { useToast } from '../../app/composables/useToast';
import { useI18n } from '../../app/i18n';
import type { BotDetail, BotKnowledgeMigrationResult } from '../bots/types';

const props = withDefaults(
  defineProps<{
    /** Fix the bot (the editor host). Omit to let the user pick one. */
    botId?: string | null;
    /** A name for the fixed bot, so the preview reads right before any fetch lands. */
    botName?: string | null;
  }>(),
  { botId: null, botName: null },
);

const open = defineModel<boolean>('open', { default: false });

const emit = defineEmits<{
  /** The base was created and the bot bound to it. The host refreshes and/or navigates. */
  (e: 'migrated', result: BotKnowledgeMigrationResult): void;
}>();

const { t } = useI18n();
const store = useBotsStore();
const toast = useToast();

const pickedBotId = ref<string | null>(props.botId);
const preview = ref<BotDetail | null>(null);
const previewLoading = ref(false);
const previewError = ref(false);
const submitting = ref(false);
/** The server's own 422 message. Shown verbatim — it NAMES the entries that blocked the migration. */
const serverError = ref<string | null>(null);

const effectiveBotId = computed(() => props.botId ?? pickedBotId.value);

/**
 * Read the picked bot for the preview.
 *
 * Deliberately NOT `store.fetchBot`: that writes the store's single global `detail`, which is what
 * the bot detail page renders — previewing a bot from the Knowledge screen must not replace what
 * some other screen is showing. Same reasoning as `botDirectory` vs. `bots`.
 */
async function loadPreview(id: string): Promise<void> {
  previewLoading.value = true;
  previewError.value = false;
  serverError.value = null;
  try {
    const res = await api.get<{ data: BotDetail }>(`/bots/${id}`);
    preview.value = res.data;
  } catch {
    preview.value = null;
    previewError.value = true;
  } finally {
    previewLoading.value = false;
  }
}

watch(
  [open, effectiveBotId],
  ([isOpen, id]) => {
    if (!isOpen) return;
    if (!id) {
      preview.value = null;
      return;
    }
    if (preview.value?.id === id) return;
    void loadPreview(id);
  },
  { immediate: true },
);

// A fresh open starts clean: a stale 422 from a previous attempt would read as a new refusal.
watch(open, (isOpen) => {
  if (isOpen) {
    serverError.value = null;
    pickedBotId.value = props.botId;
  }
});

/**
 * How many entries will actually be carried over.
 *
 * Counted the way the SERVER counts them (`Bot::knowledgeEntries()` drops any row with a blank
 * title), and NOT filtered by `knowledge.enabled` — the migration reads the entries whether or not
 * the module is switched on. A preview that promised a different number than the result delivers
 * is worse than no preview.
 */
const entryCount = computed(
  () => (preview.value?.knowledge?.entries ?? []).filter((entry) => (entry.title ?? '').trim() !== '').length,
);

const botLabel = computed(() => preview.value?.name ?? props.botName ?? '');

/** The base's name, mirroring the backend's `bot.knowledge.base_name` line so the preview is true. */
const futureBaseName = computed(() =>
  botLabel.value ? t('knowledge.migrate.baseName', '', { bot: botLabel.value }) : '',
);

/** Already bound: migrating again would make a SECOND base and silently re-point the bot at it. */
const alreadyBound = computed(() => !!preview.value?.knowledge_binding);

const canSubmit = computed(
  () => !!effectiveBotId.value && !previewLoading.value && !previewError.value && entryCount.value > 0 && !submitting.value,
);

/** The 422 body's `errors.knowledge[0]` — the message that names the offending entries. */
function migrationErrorOf(err: unknown): string | null {
  const data = (err as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } })?.response?.data;
  const named = data?.errors?.knowledge;
  if (Array.isArray(named) && named.length > 0) return named[0];
  return typeof data?.message === 'string' ? data.message : null;
}

async function onSubmit(): Promise<void> {
  const id = effectiveBotId.value;
  if (!id) return;

  submitting.value = true;
  serverError.value = null;
  try {
    const result = await store.migrateKnowledge(id);
    toast.success(t('knowledge.migrate.done', '', { count: result.entries_count }));
    open.value = false;
    emit('migrated', result);
  } catch (err: unknown) {
    // Shown INSIDE the modal, not as a toast: the message lists entries the user must go and fix,
    // and a toast that disappears after four seconds is not a to-do list.
    serverError.value = migrationErrorOf(err) ?? t('knowledge.common.saveError');
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <Modal v-model:open="open" size="md" :aria-label="t('knowledge.migrate.title')">
    <template #title>{{ t('knowledge.migrate.title') }}</template>

    <div class="flex flex-col gap-next-4">
      <Text variant="body" tone="muted">{{ t('knowledge.migrate.intro') }}</Text>

      <!-- Pick a bot (skipped when the host fixed one). -->
      <FormField v-if="!botId" :label="t('knowledge.migrate.botLabel')" :description="t('knowledge.migrate.botHint')">
        <BotSelect v-model="pickedBotId" status-badge :placeholder="t('knowledge.migrate.botPlaceholder')" />
      </FormField>

      <!-- Preview: what this will actually do. -->
      <Surface v-if="effectiveBotId" bg="muted" radius="md" class="flex flex-col gap-next-2 p-next-3">
        <div v-if="previewLoading" class="flex flex-col gap-next-2" role="status" :aria-label="t('knowledge.common.loadingLabel')">
          <Skeleton variant="text" width="55%" />
          <Skeleton variant="text" width="35%" />
        </div>

        <Text v-else-if="previewError" variant="caption" tone="muted">
          {{ t('knowledge.migrate.previewError') }}
        </Text>

        <template v-else>
          <div class="flex min-w-0 items-center gap-next-2">
            <Icon name="book-open" class="shrink-0 text-next-muted-foreground" aria-hidden="true" />
            <Text variant="ui" class="min-w-0 flex-1 truncate font-next-medium">{{ futureBaseName }}</Text>
            <Badge variant="neutral" tone="subtle" size="sm">
              {{ t('knowledge.migrate.entries', '', { count: entryCount }) }}
            </Badge>
          </div>
          <Text variant="caption" tone="muted">{{ t('knowledge.migrate.additive') }}</Text>
        </template>
      </Surface>

      <!-- Nothing to carry over: say it here rather than letting the server refuse the click. -->
      <Alert v-if="effectiveBotId && !previewLoading && !previewError && entryCount === 0" variant="info" size="sm">
        {{ t('knowledge.migrate.emptyBot') }}
      </Alert>

      <!-- The bot already reads a base: a second migration would create a second base and
           silently re-point it. Not blocked (it is a legitimate re-import), but never silent. -->
      <Alert v-if="alreadyBound" variant="warning" size="sm">
        {{ t('knowledge.migrate.alreadyBound') }}
      </Alert>

      <!-- The server's refusal, verbatim: it names the entries that carry template syntax. -->
      <Alert v-if="serverError" variant="danger" size="sm">{{ serverError }}</Alert>
    </div>

    <template #footer>
      <Button variant="ghost" :disabled="submitting" @click="open = false">{{ t('common.cancel') }}</Button>
      <Button leading-icon="sparkles" :disabled="!canSubmit" :loading="submitting" @click="onSubmit">
        {{ t('knowledge.migrate.confirm') }}
      </Button>
    </template>
  </Modal>
</template>
