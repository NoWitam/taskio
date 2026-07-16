<script setup lang="ts">
// WorkflowCard — one workflow row in the Workflows list (next, §2.4).
//
// Built on EntityCard: a leading icon bubble (the workflow's `icon`, fallback
// `workflow`; a Spinner while `opening`), the name + description, a status badge
// (icon+text, never color-only), a metadata footer of chips (trigger type · step
// count · next-due for schedule triggers), and a kebab DropdownMenu of actions
// (Run now / Activate·Deactivate / Edit / Delete) gated by the server's capability
// flags. Clicking the card opens the DETAIL view — the page PREFETCHES the full
// workflow first. All strings are localized.
//
// Gating (server-authoritative): Run enabled iff `can_run`; status toggle iff
// `can_change_status`; Edit iff `can_be_edited`; Delete iff `can_be_deleted`. The
// list resource does not carry these per-row capability flags, so ownership
// (`is_owner`) gates the card's menu; the detail view re-gates on the precise
// flags. Disabled items stay VISIBLE with an explanatory label.
import { computed } from 'vue';
import EntityCard from '../../ui/patterns/EntityCard.vue';
import Badge from '../../ui/primitives/Badge.vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Spinner from '../../ui/primitives/Spinner.vue';
import DropdownMenu from '../../ui/overlay/DropdownMenu.vue';
import DropdownMenuItem from '../../ui/overlay/DropdownMenuItem.vue';
import { workflowStatusMap } from './workflowStatus';
import { triggerIcon, triggerShort } from './workflowMeta';
import { useI18n } from '../../app/i18n';
import type { StatusMap } from '../../ui/data/StatusBadge.vue';
import type { IconName } from '../../ui/primitives/icons';
import type { WorkflowListItem } from './types';

const props = defineProps<{
  workflow: WorkflowListItem;
  /** Rendered in the Deleted tab → swaps the kebab actions for restore only. */
  trashed?: boolean;
  /** This card is fetching its workflow detail before opening the detail view. */
  opening?: boolean;
}>();

const emit = defineEmits<{
  (e: 'open', workflow: WorkflowListItem): void;
  (e: 'run', workflow: WorkflowListItem): void;
  (e: 'edit', workflow: WorkflowListItem): void;
  (e: 'delete', workflow: WorkflowListItem): void;
  /** Toggle the workflow's status (Activate ⇄ Deactivate); parent calls the store. */
  (e: 'toggle-status', workflow: WorkflowListItem): void;
  /** Restore a soft-deleted workflow (Deleted tab); parent calls the store. */
  (e: 'restore', workflow: WorkflowListItem): void;
}>();

const { t } = useI18n();

const statusMap = computed(() => workflowStatusMap(t));

// In the Deleted tab the card shows a single "Deleted" badge instead of the live
// active/inactive status (a trashed workflow's stored status would read misleadingly).
const cardStatus = computed<string>(() => (props.trashed ? 'deleted' : props.workflow.status));
const cardStatusMap = computed<StatusMap>(() =>
  props.trashed
    ? { deleted: { label: t('workflows.status.deleted'), variant: 'neutral', tone: 'subtle', icon: 'trash' } }
    : statusMap.value,
);

// Whole-card accessible name: an active card names the open action; a Deleted card
// has none (only the kebab acts, the card itself is inert).
const actionLabel = computed<string | undefined>(() =>
  props.trashed ? undefined : t('workflows.card.open', '', { name: props.workflow.name }),
);

// The list row carries only `is_owner`; the precise capability flags live on the
// detail resource. Gate the row's actions on ownership (the backend still enforces
// the real policy).
const canManage = computed(() => props.workflow.is_owner);
const isActive = computed(() => props.workflow.status === 'active');

const runDisabledReason = computed<string | undefined>(() =>
  canManage.value ? undefined : t('workflows.actions.runDisabled'),
);
const statusDisabledReason = computed<string | undefined>(() =>
  canManage.value ? undefined : t('workflows.actions.statusDisabledOwner'),
);
const editDisabledReason = computed<string | undefined>(() =>
  canManage.value ? undefined : t('workflows.actions.editDisabledOwner'),
);
const deleteDisabledReason = computed<string | undefined>(() =>
  canManage.value ? undefined : t('workflows.actions.deleteDisabledOwner'),
);
const restoreDisabledReason = computed<string | undefined>(() =>
  canManage.value ? undefined : t('workflows.actions.restoreDisabledOwner'),
);

// Human-readable "next run" for schedule triggers (only shown when both hold).
const showNextDue = computed(
  () => props.workflow.trigger_type === 'schedule' && props.workflow.next_due_at != null,
);
const nextDueText = computed(() => {
  const raw = props.workflow.next_due_at;
  if (!raw) return '';
  const d = new Date(raw);
  return Number.isNaN(d.getTime()) ? raw : d.toLocaleString();
});

// The whole (non-trashed) card opens the detail view. A Deleted card has no detail
// target, so it stays inert — only the kebab's Restore acts.
function onOpen(): void {
  if (props.trashed) return;
  emit('open', props.workflow);
}
</script>

<template>
  <EntityCard
    :title="workflow.name"
    :subtitle="workflow.description ?? t('workflows.card.noDescription')"
    :disabled="trashed || opening"
    :status="cardStatus"
    :status-map="cardStatusMap"
    :action-label="actionLabel"
    @click="onOpen"
  >
    <template #leading>
      <span
        class="flex h-10 w-10 items-center justify-center rounded-next-lg bg-next-muted text-next-muted-foreground"
        aria-hidden="true"
      >
        <Spinner v-if="opening" size="sm" tone="muted" decorative />
        <Icon v-else :name="(workflow.icon as IconName) || 'workflow'" class="text-next-lg" />
      </span>
    </template>

    <!-- Deleted tab: Restore only (workflows have no permanent-delete endpoint).
         Active tab: Run / Activate·Deactivate / Edit / Delete, gated by ownership.
         Disabled items stay visible with an explanatory label so the reason is clear. -->
    <template #actions>
      <DropdownMenu placement="bottom-end" :aria-label="t('workflows.actions.menu')">
        <template #trigger="{ props: triggerProps }">
          <Button
            v-bind="triggerProps"
            variant="ghost"
            size="icon-sm"
            leading-icon="more-vertical"
            :aria-label="t('workflows.actions.menu')"
          />
        </template>

        <!-- Deleted tab: restore only. -->
        <DropdownMenuItem
          v-if="trashed"
          icon="rotate-ccw"
          :disabled="!canManage"
          :label="restoreDisabledReason ?? t('workflows.actions.restore')"
          @select="canManage && emit('restore', workflow)"
        >
          {{ t('workflows.actions.restore') }}
        </DropdownMenuItem>

        <!-- Active tab: run / status / edit / delete, each gated by ownership. -->
        <template v-else>
          <DropdownMenuItem
            icon="arrow-right"
            :disabled="!canManage"
            :label="runDisabledReason ?? t('workflows.actions.run')"
            @select="canManage && emit('run', workflow)"
          >
            {{ t('workflows.actions.run') }}
          </DropdownMenuItem>
          <DropdownMenuItem
            :icon="isActive ? 'circle' : 'check-circle'"
            :disabled="!canManage"
            :label="statusDisabledReason ?? (isActive ? t('workflows.actions.deactivate') : t('workflows.actions.activate'))"
            @select="canManage && emit('toggle-status', workflow)"
          >
            {{ isActive ? t('workflows.actions.deactivate') : t('workflows.actions.activate') }}
          </DropdownMenuItem>
          <DropdownMenuItem
            icon="pencil"
            :disabled="!canManage"
            :label="editDisabledReason ?? t('workflows.actions.edit')"
            @select="canManage && emit('edit', workflow)"
          >
            {{ t('workflows.actions.edit') }}
          </DropdownMenuItem>
          <DropdownMenuItem
            icon="trash"
            destructive
            :disabled="!canManage"
            :label="deleteDisabledReason ?? t('workflows.actions.delete')"
            @select="canManage && emit('delete', workflow)"
          >
            {{ t('workflows.actions.delete') }}
          </DropdownMenuItem>
        </template>
      </DropdownMenu>
    </template>

    <!-- Metadata footer: trigger-type badge · step-count · (schedule) next-due. -->
    <template #meta>
      <Badge variant="info" tone="subtle" size="sm" :icon="triggerIcon(workflow.trigger_type)">
        {{ triggerShort(workflow.trigger_type, t) }}
      </Badge>
      <Badge variant="neutral" tone="subtle" size="sm" icon="list-checks">
        {{ t('workflows.card.stepCount', '', { count: workflow.step_count }) }}
      </Badge>
      <Badge
        v-if="showNextDue"
        variant="neutral"
        tone="subtle"
        size="sm"
        icon="calendar"
      >
        {{ t('workflows.card.nextDue', '', { when: nextDueText }) }}
      </Badge>
    </template>
  </EntityCard>
</template>
