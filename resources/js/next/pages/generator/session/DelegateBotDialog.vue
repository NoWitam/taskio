<script setup lang="ts">
// DelegateBotDialog — "Delegate to bot": hand this editable session to a bot so it fills the inputs in its
// own voice (R2 sub-stage 3).
//
// A Modal listing the active workspace bots (reusing the bots store) with a sparkles glyph + name + persona
// summary + status; the human picks ONE, chooses the FILL MODE (gaps vs fresh — see below), optionally ticks
// "Auto-generate after filling" (DEFAULT OFF — the owner-confirmed "gate-przed-wydatkiem": review the
// bot-filled inputs before spending on generation), then confirms. The caller runs `store.delegate(...)` and
// renders the fill report. States: loading skeletons / error+retry / empty ("create a bot first" → Bots) /
// the selectable list. NEXT tokens only.
//
// FILL MODE (asked at click time, fixes "delegating did nothing / returned the same values"): `gaps` fills
// ONLY the empty inputs and never touches what the human typed; `fresh` proposes everything anew, replacing
// the current inputs (undo restores them — said out loud in the helper so the destructive option is safe).
// DEFAULT `gaps` (the non-destructive one) — enforced here AND re-asserted on every open, exactly like the
// auto-generate toggle's default OFF, so neither a prior tick nor a prior mode can ever leak forward.
import { computed, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import Modal from '../../../ui/overlay/Modal.vue';
import Button from '../../../ui/primitives/Button.vue';
import Icon from '../../../ui/primitives/Icon.vue';
import Switch from '../../../ui/forms/Switch.vue';
import FormField from '../../../ui/forms/FormField.vue';
import Radio from '../../../ui/forms/Radio.vue';
import RadioGroup from '../../../ui/forms/RadioGroup.vue';
import Skeleton from '../../../ui/data/Skeleton.vue';
import EmptyState from '../../../ui/data/EmptyState.vue';
import { useBotsStore } from '../../../app/stores/bots';
import { useI18n } from '../../../app/i18n';
import type { BotListItem } from '../../bots/types';
import type { SlotFillMode } from '../sessionTypes';

const props = defineProps<{ submitting?: boolean }>();

const emit = defineEmits<{
  confirm: [{ botId: string; autoGenerate: boolean; fillMode: SlotFillMode }];
}>();

const { t } = useI18n();
const router = useRouter();
const store = useBotsStore();

const open = defineModel<boolean>('open', { default: false });

const selectedBotId = ref<string | null>(null);
// DEFAULT OFF — the gate-before-spend. Re-asserted on every open so a prior tick can never leak forward.
const autoGenerate = ref(false);
// DEFAULT 'gaps' — the non-destructive mode. Re-asserted on every open, like the toggle above.
const fillMode = ref<SlotFillMode>('gaps');

function refetch(): void {
  void store.fetchBots({}, { reset: true });
}

// (Re)load + reset the picker whenever the modal opens.
watch(open, (isOpen) => {
  if (isOpen) {
    selectedBotId.value = null;
    autoGenerate.value = false;
    fillMode.value = 'gaps';
    refetch();
  }
});

const items = computed<BotListItem[]>(() => store.items);
const initialLoading = computed(() => store.loading && items.value.length === 0);
const isEmpty = computed(() => !store.loading && !store.errored && items.value.length === 0);
const canConfirm = computed(() => !!selectedBotId.value && !props.submitting);
const skeletonKeys = Array.from({ length: 4 }, (_, i) => i);

function statusLabel(status: BotListItem['status']): string {
  return t(`bots.statuses.${status}`, status);
}

function select(bot: BotListItem): void {
  selectedBotId.value = bot.id;
}

/** RadioGroup speaks `string | null`; narrow it back to the two legal modes (an unknown value is ignored). */
const fillModeModel = computed<string | null>({
  get: () => fillMode.value,
  set: (value) => {
    if (value === 'gaps' || value === 'fresh') fillMode.value = value;
  },
});

function confirm(): void {
  if (!selectedBotId.value || props.submitting) return;
  emit('confirm', {
    botId: selectedBotId.value,
    autoGenerate: autoGenerate.value,
    fillMode: fillMode.value,
  });
}

/** Empty state → close the dialog and route to Bots to create one. */
function goToBots(): void {
  open.value = false;
  void router.push({ name: 'next.bots' });
}
</script>

<template>
  <Modal v-model:open="open" size="lg" :aria-label="t('generator.sessions.delegate.dialogTitle')">
    <template #title>{{ t('generator.sessions.delegate.dialogTitle') }}</template>
    <template #description>{{ t('generator.sessions.delegate.dialogSubtitle') }}</template>

    <div class="flex flex-col gap-next-4">
      <!-- Error + retry. -->
      <EmptyState
        v-if="store.errored && items.length === 0"
        variant="error"
        :title="t('generator.sessions.delegate.loadErrorTitle')"
        :description="t('generator.sessions.delegate.loadError')"
      >
        <template #action>
          <Button variant="outline" size="sm" leading-icon="rotate-ccw" @click="refetch">
            {{ t('generator.sessions.errors.retry') }}
          </Button>
        </template>
      </EmptyState>

      <!-- Loading skeletons. -->
      <ul v-else-if="initialLoading" class="flex flex-col gap-next-2" aria-hidden="true">
        <li
          v-for="n in skeletonKeys"
          :key="`sk-${n}`"
          class="flex items-center gap-next-3 rounded-next-lg border border-next-border p-next-3"
        >
          <Skeleton variant="rect" width="2.25rem" height="2.25rem" radius="full" />
          <div class="flex flex-1 flex-col gap-next-1">
            <Skeleton variant="text" width="40%" />
            <Skeleton variant="text" width="60%" />
          </div>
        </li>
      </ul>

      <!-- Empty: no bots → route to Bots to create one. -->
      <EmptyState
        v-else-if="isEmpty"
        icon="sparkles"
        :title="t('generator.sessions.delegate.emptyTitle')"
        :description="t('generator.sessions.delegate.emptyDescription')"
      >
        <template #action>
          <Button variant="outline" size="sm" leading-icon="arrow-right" @click="goToBots">
            {{ t('generator.sessions.delegate.emptyAction') }}
          </Button>
        </template>
      </EmptyState>

      <!-- The bots (single-select). -->
      <template v-else>
        <ul
          class="flex max-h-[40vh] flex-col gap-next-2 overflow-y-auto"
          role="radiogroup"
          :aria-label="t('generator.sessions.delegate.pickBot')"
        >
          <li v-for="bot in items" :key="bot.id">
            <button
              type="button"
              role="radio"
              :aria-checked="selectedBotId === bot.id"
              class="flex w-full items-center gap-next-3 rounded-next-lg border p-next-3 text-left transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-next-ring"
              :class="
                selectedBotId === bot.id
                  ? 'border-next-primary bg-next-primary-subtle'
                  : 'border-next-border bg-next-card hover:border-next-primary hover:bg-next-accent'
              "
              :disabled="submitting"
              @click="select(bot)"
            >
              <span
                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-next-full bg-next-primary-subtle text-next-primary-subtle-foreground"
                aria-hidden="true"
              >
                <Icon name="sparkles" />
              </span>
              <span class="flex min-w-0 flex-1 flex-col">
                <span class="flex items-center gap-next-2">
                  <span class="truncate font-next-medium text-next-fg">{{ bot.name }}</span>
                  <span class="shrink-0 text-next-xs text-next-muted-foreground">{{ statusLabel(bot.status) }}</span>
                </span>
                <span v-if="bot.description" class="truncate text-next-xs text-next-muted-foreground">
                  {{ bot.description }}
                </span>
              </span>
              <Icon
                v-if="selectedBotId === bot.id"
                name="check"
                class="shrink-0 text-next-primary"
                aria-hidden="true"
              />
            </button>
          </li>
        </ul>

        <!-- Fill mode (DEFAULT 'gaps' = non-destructive): what should the bot actually change? -->
        <div class="rounded-next-lg border border-next-border bg-next-muted/20 p-next-3">
          <FormField :label="t('generator.sessions.delegate.fillMode.legend')">
            <RadioGroup
              v-model="fillModeModel"
              :disabled="submitting"
              :aria-label="t('generator.sessions.delegate.fillMode.legend')"
            >
              <Radio
                value="gaps"
                size="sm"
                :label="t('generator.sessions.delegate.fillMode.gaps')"
                :description="t('generator.sessions.delegate.fillMode.gapsHelp')"
              />
              <Radio
                value="fresh"
                size="sm"
                :label="t('generator.sessions.delegate.fillMode.fresh')"
                :description="t('generator.sessions.delegate.fillMode.freshHelp')"
              />
            </RadioGroup>
          </FormField>
        </div>

        <!-- Auto-generate opt-in (DEFAULT OFF): review the bot-filled inputs before spending on generation. -->
        <div class="flex flex-col gap-next-1 rounded-next-lg border border-next-border bg-next-muted/20 p-next-3">
          <Switch v-model="autoGenerate" :label="t('generator.sessions.delegate.autoGenerate')" />
          <p class="text-next-xs text-next-muted-foreground">
            {{ t('generator.sessions.delegate.autoGenerateHelp') }}
          </p>
        </div>
      </template>
    </div>

    <template #footer="{ close }">
      <Button variant="ghost" :disabled="submitting" @click="close">
        {{ t('common.cancel') }}
      </Button>
      <Button
        variant="primary"
        leading-icon="sparkles"
        :disabled="!canConfirm"
        :loading="submitting"
        @click="confirm"
      >
        {{ t('generator.sessions.delegate.confirm') }}
      </Button>
    </template>
  </Modal>
</template>
