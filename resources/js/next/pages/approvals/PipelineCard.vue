<script setup lang="ts">
// PipelineCard — one pipeline row in the Approvals → Pipelines list (next).
//
// Built on EntityCard: a leading git-branch icon bubble, the name + description,
// a stage-count metadata footer, and a kebab DropdownMenu of actions (Edit /
// Delete) gated by the server's ownership + capability flags. Clicking the card
// opens the builder drawer — the page PREFETCHES the full pipeline first (so the
// drawer renders populated with no loading flash). All strings are localized.
//
// Gating rules (server-authoritative):
//   • Edit enabled only when `is_owner && can_be_edited`.
//   • Delete enabled only when `is_owner && can_be_deleted`.
// Disabled actions stay VISIBLE (with an explanatory aria/title) so the user can
// see WHY an action is unavailable — UI never invents authorization.
import { computed } from 'vue';
import EntityCard, { type EntityMetaItem } from '../../ui/patterns/EntityCard.vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Spinner from '../../ui/primitives/Spinner.vue';
import DropdownMenu from '../../ui/overlay/DropdownMenu.vue';
import DropdownMenuItem from '../../ui/overlay/DropdownMenuItem.vue';
import { resolvePipelineIcon } from '../../ui/forms/pipelineIcon';
import { useI18n } from '../../app/i18n';
import type { ApprovalPipelineListItem } from './types';

const props = defineProps<{
  pipeline: ApprovalPipelineListItem;
  /** This card is fetching its pipeline before opening the builder drawer. */
  opening?: boolean;
}>();

const emit = defineEmits<{
  (e: 'open', pipeline: ApprovalPipelineListItem): void;
  (e: 'edit', pipeline: ApprovalPipelineListItem): void;
  (e: 'delete', pipeline: ApprovalPipelineListItem): void;
}>();

const { t } = useI18n();

const stageCount = computed(() => props.pipeline.stages_count ?? props.pipeline.stages.length);

const canEdit = computed(() => props.pipeline.is_owner && props.pipeline.can_be_edited);
const canDelete = computed(() => props.pipeline.is_owner && props.pipeline.can_be_deleted);

/** Why an action is disabled (ownership first, then active processes). */
const editDisabledReason = computed<string | undefined>(() => {
  if (!props.pipeline.is_owner) return t('approvals.actions.editDisabledOwner');
  if (!props.pipeline.can_be_edited) return t('approvals.actions.editDisabledActive');
  return undefined;
});
const deleteDisabledReason = computed<string | undefined>(() => {
  if (!props.pipeline.is_owner) return t('approvals.actions.deleteDisabledOwner');
  if (!props.pipeline.can_be_deleted) return t('approvals.actions.deleteDisabledActive');
  return undefined;
});

const meta = computed<EntityMetaItem[]>(() => {
  const out: EntityMetaItem[] = [
    { icon: 'git-branch', label: t('approvals.pipelines.card.stages'), value: stageCount.value },
  ];
  if (props.pipeline.created_at) {
    out.push({ icon: 'calendar', label: formatDate(props.pipeline.created_at) });
  }
  return out;
});

// ISO timestamp → `dd.mm.yyyy` (locale-agnostic; the surrounding labels are i18n).
function formatDate(iso: string): string {
  const m = iso.match(/^(\d{4})-(\d{2})-(\d{2})/);
  return m ? `${m[3]}.${m[2]}.${m[1]}` : iso;
}

// Clicking the card opens the builder. We emit `open` (rather than a router link)
// so the page can PREFETCH the pipeline before opening the drawer, then render it
// already populated.
function onOpen(): void {
  emit('open', props.pipeline);
}
</script>

<template>
  <EntityCard
    :title="pipeline.name"
    :subtitle="pipeline.description ?? t('approvals.pipelines.card.noDescription')"
    :disabled="opening"
    :action-label="t('approvals.pipelines.card.open', '', { name: pipeline.name })"
    @click="onOpen"
  >
    <template #leading>
      <span
        class="flex h-10 w-10 items-center justify-center rounded-next-lg bg-next-muted text-next-muted-foreground"
        aria-hidden="true"
      >
        <Spinner v-if="opening" size="sm" tone="muted" decorative />
        <!-- The pipeline's OWN icon (legacy IconEnum → next glyph; git-branch fallback). -->
        <Icon v-else :name="resolvePipelineIcon(pipeline.icon)" class="text-next-lg" />
      </span>
    </template>

    <!-- Edit / Delete actions, gated by ownership + capability flags. Disabled
         items stay visible with an explanatory label so the reason is clear. -->
    <template #actions>
      <DropdownMenu placement="bottom-end" :aria-label="t('approvals.actions.menu')">
        <template #trigger="{ props: triggerProps }">
          <Button
            v-bind="triggerProps"
            variant="ghost"
            size="icon-sm"
            leading-icon="more-vertical"
            :aria-label="t('approvals.actions.menu')"
          />
        </template>

        <DropdownMenuItem
          icon="pencil"
          :disabled="!canEdit"
          :label="editDisabledReason ?? t('approvals.actions.edit')"
          @select="canEdit && emit('edit', pipeline)"
        >
          {{ t('approvals.actions.edit') }}
        </DropdownMenuItem>
        <DropdownMenuItem
          icon="trash"
          destructive
          :disabled="!canDelete"
          :label="deleteDisabledReason ?? t('approvals.actions.delete')"
          @select="canDelete && emit('delete', pipeline)"
        >
          {{ t('approvals.actions.delete') }}
        </DropdownMenuItem>
      </DropdownMenu>
    </template>

    <template #meta>
      <span
        v-for="(item, i) in meta"
        :key="i"
        class="inline-flex min-w-0 items-center gap-next-1"
      >
        <Icon v-if="item.icon" :name="item.icon" class="shrink-0 text-next-sm" />
        <span class="truncate">{{ item.label }}</span>
        <span v-if="item.value !== undefined" class="font-next-medium text-next-fg">
          {{ item.value }}
        </span>
      </span>
    </template>
  </EntityCard>
</template>
