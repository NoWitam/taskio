<script setup lang="ts">
// BotKnowledgeBindingPanel — WHICH knowledge base this bot reads, and HOW.
//
// It sits at the top of the bot editor's knowledge module, above the built-in entry list, because
// it decides whether that list is read at all: while a binding exists the built-in entries are
// KEPT but NOT injected (verified: `BotResource.knowledge_binding` "takes precedence"). The panel
// therefore owns the copy that tells the user their entries went quiet — and the way back.
//
// IT SAVES IMMEDIATELY, and does not ride the editor's Save button. Three reasons, in order:
//   • it is a different endpoint (`PUT /bots/{id}/knowledge-binding`) with a different body, so
//     folding it into the bot PUT would mean inventing a field the FormRequest does not accept;
//   • it answers with the whole bot, which is how the built-in section learns it went inactive;
//   • an unsaved binding is a lie about what the bot currently reads.
// Same split the visual module already makes: the module's TEXT is form state, its SERVER FACTS
// are a mirror replaced only from a response.
//
// A bot that was never saved has no id and therefore cannot be bound — the section says so instead
// of offering a control that would 404.
import { computed, ref, watch } from 'vue';
import FormField from '../../ui/forms/FormField.vue';
import KnowledgeBaseSelect from '../../ui/forms/KnowledgeBaseSelect.vue';
import SegmentedControl from '../../ui/forms/SegmentedControl.vue';
import Button from '../../ui/primitives/Button.vue';
import Text from '../../ui/primitives/Text.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Badge from '../../ui/primitives/Badge.vue';
import Alert from '../../ui/feedback/Alert.vue';
import Surface from '../../ui/layout/Surface.vue';
import { useBotsStore } from '../../app/stores/bots';
import { useToast } from '../../app/composables/useToast';
import { useConfirm } from '../../app/composables/useConfirm';
import { useI18n } from '../../app/i18n';
import {
  KNOWLEDGE_BINDING_MODES,
  type BotDetail,
  type BotKnowledgeBinding,
  type KnowledgeBindingMode,
} from './types';

const props = withDefaults(
  defineProps<{
    /** The bot being edited, or null for one that has never been saved. */
    botId: string | null;
    /** The SERVER's binding — never written here, only mirrored from a response. */
    binding: BotKnowledgeBinding | null;
    /** How many built-in entries the bot carries (drives the "migrate instead" offer). */
    builtInCount?: number;
  }>(),
  { builtInCount: 0 },
);

const emit = defineEmits<{
  /** A fresh bot arrived (bind / unbind) — the editor re-mirrors `binding`. */
  (e: 'sync', bot: BotDetail): void;
  /** Open the migration modal (the editor hosts it — one modal, two entry points). */
  (e: 'migrate'): void;
}>();

const { t } = useI18n();
const store = useBotsStore();
const toast = useToast();
const confirm = useConfirm();

// --- Draft ------------------------------------------------------------------
// The DRAFT is what the controls edit; `props.binding` is what the server holds. They are separate
// so an in-progress choice is never mistaken for a live binding.
const draftBaseId = ref<string | null>(props.binding?.knowledge_base_id ?? null);
const draftMode = ref<KnowledgeBindingMode>(props.binding?.mode ?? 'auto');
const busy = ref(false);
/** The base's name, learned from the picker — so the "active source" line can name it. */
const pickedName = ref<string | null>(null);

// The server's binding can change under the panel WITHOUT the user touching these controls — a
// migration binds the bot from the modal next door. Re-seed the draft from it, so the picker shows
// what the bot actually reads instead of the empty choice the user never made.
watch(
  () => props.binding,
  (binding) => {
    draftBaseId.value = binding?.knowledge_base_id ?? null;
    draftMode.value = binding?.mode ?? 'auto';
  },
);

const isBound = computed(() => props.binding !== null);
const canEdit = computed(() => props.botId !== null);

/** Whether the draft says something different from what the server holds. */
const dirty = computed(
  () =>
    draftBaseId.value !== (props.binding?.knowledge_base_id ?? null) ||
    draftMode.value !== (props.binding?.mode ?? 'auto'),
);

const modeOptions = computed(() =>
  KNOWLEDGE_BINDING_MODES.map((mode) => ({
    value: mode,
    label: t(`bots.editor.knowledge.binding.mode.${mode}`),
    description: t(`bots.editor.knowledge.binding.mode.${mode}Hint`),
  })),
);

/** Seed the picker so an already-bound base renders by NAME before any page of options lands. */
const seed = computed(() =>
  props.binding ? [{ id: props.binding.knowledge_base_id, name: pickedName.value ?? props.binding.knowledge_base_id }] : [],
);

async function onSave(): Promise<void> {
  if (!props.botId || !draftBaseId.value) return;
  busy.value = true;
  try {
    const bot = await store.bindKnowledgeBase(props.botId, {
      knowledge_base_id: draftBaseId.value,
      mode: draftMode.value,
    });
    emit('sync', bot);
    toast.success(t('bots.editor.knowledge.binding.saved'));
  } catch {
    toast.danger(t('bots.editor.knowledge.binding.saveError'));
  } finally {
    busy.value = false;
  }
}

async function onUnbind(): Promise<void> {
  if (!props.botId) return;
  // Confirmed, because it silently changes what the bot KNOWS on its next run — and the built-in
  // entries it falls back to may be years out of date.
  const ok = await confirm({
    title: t('bots.editor.knowledge.binding.unbindConfirm.title'),
    message: t('bots.editor.knowledge.binding.unbindConfirm.message'),
    confirmLabel: t('bots.editor.knowledge.binding.unbind'),
    cancelLabel: t('common.cancel'),
    variant: 'danger',
  });
  if (!ok) return;

  busy.value = true;
  try {
    const bot = await store.unbindKnowledgeBase(props.botId);
    draftBaseId.value = null;
    draftMode.value = 'auto';
    emit('sync', bot);
    toast.success(t('bots.editor.knowledge.binding.unbound'));
  } catch {
    toast.danger(t('bots.editor.knowledge.binding.saveError'));
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <Surface bg="card" border radius="lg" class="flex flex-col gap-next-3 p-next-3">
    <div class="flex flex-wrap items-center gap-next-2">
      <Icon name="book-open" class="shrink-0 text-next-muted-foreground" aria-hidden="true" />
      <Text variant="ui" class="font-next-medium">{{ t('bots.editor.knowledge.binding.title') }}</Text>
      <!-- The state, as a word: this badge is the answer to "where does this bot get its facts?". -->
      <Badge v-if="isBound" variant="success" tone="subtle" icon="check-circle" size="sm">
        {{ t('bots.editor.knowledge.binding.active') }}
      </Badge>
      <Badge v-else variant="neutral" tone="subtle" size="sm">
        {{ t('bots.editor.knowledge.binding.inactive') }}
      </Badge>
    </div>

    <Text variant="caption" tone="muted">{{ t('bots.editor.knowledge.binding.hint') }}</Text>

    <!-- An unsaved bot has no id to bind to. Said plainly, rather than shown as a dead control. -->
    <Alert v-if="!canEdit" variant="info" size="sm">
      {{ t('bots.editor.knowledge.binding.saveFirst') }}
    </Alert>

    <template v-else>
      <FormField :label="t('bots.editor.knowledge.binding.baseLabel')">
        <KnowledgeBaseSelect
          v-model="draftBaseId"
          :seed="seed"
          :disabled="busy"
          :placeholder="t('bots.editor.knowledge.binding.basePlaceholder')"
          :aria-label="t('bots.editor.knowledge.binding.baseLabel')"
          @update:selected="(option) => (pickedName = option?.label ?? null)"
        />
      </FormField>

      <FormField
        :label="t('bots.editor.knowledge.binding.modeLabel')"
        :description="t('bots.editor.knowledge.binding.modeHint')"
      >
        <SegmentedControl
          v-model="draftMode"
          :options="modeOptions"
          size="sm"
          :disabled="busy"
          :aria-label="t('bots.editor.knowledge.binding.modeLabel')"
        />
      </FormField>

      <div class="flex flex-wrap items-center gap-next-2">
        <Button
          size="sm"
          leading-icon="check"
          :disabled="!draftBaseId || !dirty || busy"
          :loading="busy && !!draftBaseId"
          @click="onSave"
        >
          {{ isBound ? t('bots.editor.knowledge.binding.rebind') : t('bots.editor.knowledge.binding.bind') }}
        </Button>

        <Button v-if="isBound" variant="outline" size="sm" leading-icon="unlink" :disabled="busy" @click="onUnbind">
          {{ t('bots.editor.knowledge.binding.unbind') }}
        </Button>

        <!-- The other way in: no base yet, but the bot already carries built-in entries. -->
        <Button
          v-if="!isBound && builtInCount > 0"
          variant="ghost"
          size="sm"
          leading-icon="sparkles"
          :disabled="busy"
          @click="emit('migrate')"
        >
          {{ t('bots.editor.knowledge.binding.migrate') }}
        </Button>
      </div>
    </template>
  </Surface>
</template>
