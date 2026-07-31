<script setup lang="ts">
// BotCard — one bot row in the Bots list (next, Batch 1).
//
// Built on EntityCard: a leading sparkles icon bubble, the name + description, a
// status badge (icon+text, never color-only), a metadata footer of MODULE CHIPS
// (Text always; Task-execution when enabled; Visual·soon / Voice·soon as disabled
// placeholders), and a kebab DropdownMenu of actions (Edit / Delete) gated by the
// server's ownership + capability flags. Clicking the card opens the DETAIL view —
// the page PREFETCHES the full bot first (so the detail renders populated). All
// strings are localized.
//
// Gating rules (server-authoritative):
//   • Edit enabled only when `is_owner && can_be_edited`.
//   • Delete enabled only when `is_owner && can_be_deleted`.
// Disabled actions stay VISIBLE (with an explanatory label) so the reason is clear.
import { computed } from 'vue';
import EntityCard from '../../ui/patterns/EntityCard.vue';
import Badge from '../../ui/primitives/Badge.vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Spinner from '../../ui/primitives/Spinner.vue';
import DropdownMenu from '../../ui/overlay/DropdownMenu.vue';
import DropdownMenuItem from '../../ui/overlay/DropdownMenuItem.vue';
import { botStatusMap } from '../../ui/data/botStatus';
import { useI18n } from '../../app/i18n';
import type { IconName } from '../../ui/primitives/icons';
import type { BotListItem } from './types';

const props = defineProps<{
  bot: BotListItem;
  /** This card is fetching its bot detail before opening the detail view. */
  opening?: boolean;
}>();

const emit = defineEmits<{
  (e: 'open', bot: BotListItem): void;
  (e: 'edit', bot: BotListItem): void;
  (e: 'delete', bot: BotListItem): void;
  /** Toggle the bot's live status (Activate ⇄ Deactivate); parent calls the store. */
  (e: 'toggle-status', bot: BotListItem): void;
}>();

const { t } = useI18n();

const statusMap = computed(() => botStatusMap(t));

const canEdit = computed(() => props.bot.is_owner);
const canDelete = computed(() => props.bot.is_owner);
const isActive = computed(() => props.bot.status === 'active');
const statusDisabledReason = computed<string | undefined>(() =>
  props.bot.is_owner ? undefined : t('bots.actions.statusDisabledOwner'),
);

/**
 * The VISUAL module chip — OPERATIONAL READINESS, not "is this configured": the module being on and a
 * likeness being approved are independent, and only BOTH means "this bot will appear on its images".
 * The two half-states are the ones a user needs told (an enabled module with no image silently produces
 * character-less pictures; an approved likeness with the module off does the same), so each gets its own
 * variant + icon + title. Neither → no chip at all: the module was simply never touched.
 */
const visualChip = computed<{ label: string; title: string; variant: 'primary' | 'warning' | 'neutral'; icon: IconName } | null>(() => {
  const { visual_enabled: enabled, visual_has_image: hasImage } = props.bot;
  if (enabled && hasImage) {
    return {
      label: t('bots.card.visualReady'),
      title: t('bots.card.visualReadyTitle'),
      variant: 'primary',
      icon: 'palette',
    };
  }
  if (enabled) {
    return {
      label: t('bots.card.visualNoImage'),
      title: t('bots.card.visualNoImageTitle'),
      variant: 'warning',
      icon: 'alert-triangle',
    };
  }
  if (hasImage) {
    return {
      label: t('bots.card.visualOff'),
      title: t('bots.card.visualOffTitle'),
      variant: 'neutral',
      icon: 'palette',
    };
  }
  return null;
});

const editDisabledReason = computed<string | undefined>(() =>
  props.bot.is_owner ? undefined : t('bots.actions.editDisabledOwner'),
);
const deleteDisabledReason = computed<string | undefined>(() =>
  props.bot.is_owner ? undefined : t('bots.actions.deleteDisabledOwner'),
);

function onOpen(): void {
  emit('open', props.bot);
}
</script>

<template>
  <EntityCard
    :title="bot.name"
    :subtitle="bot.description ?? t('bots.card.noDescription')"
    :disabled="opening"
    :status="bot.status"
    :status-map="statusMap"
    :action-label="t('bots.card.open', '', { name: bot.name })"
    @click="onOpen"
  >
    <template #leading>
      <span
        class="flex h-10 w-10 items-center justify-center rounded-next-lg bg-next-muted text-next-muted-foreground"
        aria-hidden="true"
      >
        <Spinner v-if="opening" size="sm" tone="muted" decorative />
        <Icon v-else :name="(bot.icon as IconName) || 'sparkles'" class="text-next-lg" />
      </span>
    </template>

    <!-- Edit / Delete actions, gated by ownership. Disabled items stay visible
         with an explanatory label so the reason is clear. -->
    <template #actions>
      <DropdownMenu placement="bottom-end" :aria-label="t('bots.actions.menu')">
        <template #trigger="{ props: triggerProps }">
          <Button
            v-bind="triggerProps"
            variant="ghost"
            size="icon-sm"
            leading-icon="more-vertical"
            :aria-label="t('bots.actions.menu')"
          />
        </template>

        <!-- Activate / Deactivate — toggles live status (creator-only). -->
        <DropdownMenuItem
          :icon="isActive ? 'circle' : 'check-circle'"
          :disabled="!canEdit"
          :label="statusDisabledReason ?? (isActive ? t('bots.statusAction.deactivate') : t('bots.statusAction.activate'))"
          @select="canEdit && emit('toggle-status', bot)"
        >
          {{ isActive ? t('bots.statusAction.deactivate') : t('bots.statusAction.activate') }}
        </DropdownMenuItem>
        <DropdownMenuItem
          icon="pencil"
          :disabled="!canEdit"
          :label="editDisabledReason ?? t('bots.actions.edit')"
          @select="canEdit && emit('edit', bot)"
        >
          {{ t('bots.actions.edit') }}
        </DropdownMenuItem>
        <DropdownMenuItem
          icon="trash"
          destructive
          :disabled="!canDelete"
          :label="deleteDisabledReason ?? t('bots.actions.delete')"
          @select="canDelete && emit('delete', bot)"
        >
          {{ t('bots.actions.delete') }}
        </DropdownMenuItem>
      </DropdownMenu>
    </template>

    <!-- Module chips: a CONSISTENT footer showing which modules the bot carries.
         Text is always present (persona is mandatory); Task-execution shows when
         enabled; the Appearance chip reports operational readiness (see `visualChip`);
         Audio stays a disabled "soon" chip so the IA is visible without faking capability. -->
    <template #meta>
      <Badge variant="primary" tone="subtle" size="sm" icon="file-text">
        {{ t('bots.modules.text') }}
      </Badge>
      <Badge
        v-if="bot.task_execution_enabled"
        variant="success"
        tone="subtle"
        size="sm"
        icon="list-checks"
      >
        {{ t('bots.modules.taskExecution') }}
      </Badge>
      <Badge
        v-if="visualChip"
        :variant="visualChip.variant"
        tone="subtle"
        size="sm"
        :icon="visualChip.icon"
        :title="visualChip.title"
      >
        {{ visualChip.label }}
      </Badge>
      <Badge variant="neutral" tone="subtle" size="sm" icon="bell">
        {{ t('bots.modules.audioSoon') }}
      </Badge>
    </template>
  </EntityCard>
</template>
