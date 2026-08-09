<script setup lang="ts">
// KnowledgeBaseCard — one knowledge base in the Bases list (spec §3.2).
//
// Built on EntityCard: a book-open bubble, the name, the charter's opening sentence as the
// subtitle, a metadata footer (entries · language · schema fields · updated · creator) and a
// kebab of actions gated by the server's capability flags. Localized throughout.
//
// B5 UPDATE — two changes, both undoing a B4 stopgap:
//
//  1. THE CARD NOW OPENS THE READER. B4 pointed it at Settings because the reader did not exist
//     yet; navigating to a base's configuration when a user clicks its name was always the wrong
//     destination. Settings keeps a dedicated route in the kebab (and in the module aside).
//  2. THE INDEX BADGE + RED-LINK CHIP ARE BACK. B4 documented them as absent from the contract;
//     B2b then added `index_summary` and `ghost_links_count` to KnowledgeBaseResource — ALWAYS
//     present, on every response path — so the card can now render both from the list response
//     with no follow-up request per base.
//
// The red-link chip renders ONLY when the count is above zero: a permanent "0 red links" is noise,
// while a number that appears is a signal to act.
import { computed } from 'vue';
import EntityCard, { type EntityMetaItem } from '../../ui/patterns/EntityCard.vue';
import CreatorBadge from '../../ui/patterns/CreatorBadge.vue';
import StatusBadge from '../../ui/data/StatusBadge.vue';
import Badge from '../../ui/primitives/Badge.vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import DropdownMenu from '../../ui/overlay/DropdownMenu.vue';
import DropdownMenuItem from '../../ui/overlay/DropdownMenuItem.vue';
import { baseSubtitle, baseIndexState, formatDate, languageLabel } from './baseMeta';
import { indexStatusMap } from './statusMaps';
import { useI18n } from '../../app/i18n';
import type { KnowledgeBase } from './types';

const props = defineProps<{ base: KnowledgeBase }>();

const emit = defineEmits<{
  (e: 'open', base: KnowledgeBase): void;
  (e: 'settings', base: KnowledgeBase): void;
  (e: 'trash', base: KnowledgeBase): void;
  /**
   * TRASHED BASES ONLY. Bases were never covered by the authoring ban — the owner withdrew manual
   * authorship of ENTRIES and RELATIONS. A base keeps its whole life cycle, and its charter is
   * required by the GDPR procedure, so losing the way back from the trash was a real hole rather
   * than a consequence of the removal.
   */
  (e: 'restore', base: KnowledgeBase): void;
  (e: 'purge', base: KnowledgeBase): void;
}>();

const { t } = useI18n();

const subtitle = computed(() => baseSubtitle(props.base, t));

const actionLabel = computed(() => t('knowledge.bases.card.open', '', { name: props.base.name }));

const indexMap = computed(() => indexStatusMap(t));

/** Aggregate index state, counted in ENTRIES. Null on an empty base — "0/0" claims nothing. */
const indexState = computed(() => baseIndexState(props.base, t));

/**
 * The metadata footer. `entries_count` is `whenCounted` on the resource — present on a list row,
 * absent on a create/update response — so the item is omitted rather than showing a made-up 0.
 */
const meta = computed<EntityMetaItem[]>(() => {
  const out: EntityMetaItem[] = [];
  if (props.base.entries_count !== undefined) {
    out.push({
      icon: 'file-text',
      label: t('knowledge.bases.meta.entries'),
      value: props.base.entries_count,
    });
  }
  out.push({
    icon: 'type',
    label: t('knowledge.bases.meta.language'),
    value: languageLabel(props.base.language, t),
  });
  out.push({
    icon: 'braces',
    label: t('knowledge.bases.meta.schema'),
    value: props.base.metadata_schema?.length ?? 0,
  });
  out.push({
    icon: 'clock',
    label: t('knowledge.bases.meta.updated'),
    value: formatDate(props.base.updated_at),
  });
  return out;
});
</script>

<template>
  <EntityCard
    :title="base.name"
    :subtitle="subtitle"
    :action-label="actionLabel"
    @click="emit('open', base)"
  >
    <template #leading>
      <span
        class="flex h-10 w-10 items-center justify-center rounded-next-lg bg-next-primary-subtle text-next-primary-subtle-foreground"
        aria-hidden="true"
      >
        <Icon name="book-open" class="text-next-lg" />
      </span>
    </template>

    <!-- Conditional signals come BEFORE the permanent kebab, so the kebab never shifts between
         cards (trailing-affordance ordering rule). -->
    <template #status>
      <div class="flex flex-wrap items-center gap-next-1_5">
        <StatusBadge
          v-if="indexState"
          :status="indexState.status"
          :status-map="indexMap"
          :label="indexState.label"
          size="sm"
        />
        <Badge v-if="base.ghost_links_count > 0" variant="danger" tone="subtle" icon="unlink" size="sm">
          {{ t('knowledge.ghosts.count', '', { count: base.ghost_links_count }) }}
        </Badge>
      </div>
    </template>

    <!-- Trash stays VISIBLE but disabled for a non-manager, with the reason as its label —
         a vanished action teaches nothing. -->
    <template #actions>
      <DropdownMenu placement="bottom-end" :aria-label="t('knowledge.bases.menu.label')">
        <template #trigger="{ props: triggerProps }">
          <Button
            v-bind="triggerProps"
            variant="ghost"
            size="icon-sm"
            leading-icon="more-vertical"
            :aria-label="t('knowledge.bases.menu.label')"
          />
        </template>

        <DropdownMenuItem
          v-if="!base.deleted_at"
          icon="book-open"
          :label="t('knowledge.bases.menu.open')"
          @select="emit('open', base)"
        >
          {{ t('knowledge.bases.menu.open') }}
        </DropdownMenuItem>
        <DropdownMenuItem
          v-if="!base.deleted_at"
          icon="settings"
          :label="t('knowledge.bases.menu.settings')"
          @select="emit('settings', base)"
        >
          {{ t('knowledge.bases.menu.settings') }}
        </DropdownMenuItem>
        <!--
          A TRASHED base offers the two acts that apply to it and nothing else. Leaving "Settings"
          and "Move to trash" on a row that is already in the trash would be offering to do again
          what has been done.
        -->
        <template v-if="base.deleted_at">
          <DropdownMenuItem
            icon="rotate-ccw"
            :label="t('knowledge.bases.menu.restore')"
            @select="emit('restore', base)"
          >
            {{ t('knowledge.bases.menu.restore') }}
          </DropdownMenuItem>
          <DropdownMenuItem
            icon="trash"
            destructive
            :disabled="!base.can_be_deleted"
            :label="t('knowledge.bases.menu.purge')"
            @select="base.can_be_deleted && emit('purge', base)"
          >
            {{ t('knowledge.bases.menu.purge') }}
          </DropdownMenuItem>
        </template>

        <DropdownMenuItem
          v-else
          icon="trash"
          destructive
          :disabled="!base.can_be_deleted"
          :label="base.can_be_deleted ? t('knowledge.bases.menu.trash') : t('knowledge.bases.menu.trashDisabled')"
          @select="base.can_be_deleted && emit('trash', base)"
        >
          {{ t('knowledge.bases.menu.trash') }}
        </DropdownMenuItem>
      </DropdownMenu>
    </template>

    <template #meta>
      <span v-for="(item, i) in meta" :key="i" class="inline-flex min-w-0 items-center gap-next-1">
        <Icon v-if="item.icon" :name="item.icon" class="shrink-0 text-next-sm" />
        <span class="truncate">{{ item.label }}</span>
        <span v-if="item.value !== undefined" class="font-next-medium text-next-fg">
          {{ item.value }}
        </span>
      </span>
      <CreatorBadge :creator="base.creator ?? null" size="xs" />
    </template>
  </EntityCard>
</template>
