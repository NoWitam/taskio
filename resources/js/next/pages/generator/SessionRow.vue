<script setup lang="ts">
// SessionRow — one row of the generation-sessions list. A card row carrying the session's name, a
// content-TYPE badge + glyph, its StatusBadge (never color-only), the created-at timestamp and the
// creator, plus the Open (primary) and Delete actions and an Archive/Unarchive overflow action (R2
// sub-stage 2d; gated on `can_archive`, with an "archived" marker when frozen). Delete is GATED on the
// backend capability flag (`can_be_deleted`); a disabled action keeps a tooltip explaining why. Mirrors
// `TemplateRow.vue` and emits `open` / `delete` / `archive` (a toggle — the view owns navigation, the
// confirm, and which of archive/unarchive to call from `is_archived`).
import { computed } from 'vue';
import Button from '../../ui/primitives/Button.vue';
import Badge from '../../ui/primitives/Badge.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Tooltip from '../../ui/overlay/Tooltip.vue';
import StatusBadge from '../../ui/data/StatusBadge.vue';
import CreatorBadge from '../../ui/patterns/CreatorBadge.vue';
import BotAuthorChip from './session/BotAuthorChip.vue';
import DropdownMenu from '../../ui/overlay/DropdownMenu.vue';
import DropdownMenuItem from '../../ui/overlay/DropdownMenuItem.vue';
import { sessionStatusMap } from './session/sessionStatus';
import { contentTypeIcon, contentTypeLabel } from './templateMeta';
import { useI18n } from '../../app/i18n';
import type { Session } from './sessionTypes';

const props = defineProps<{ session: Session }>();

const emit = defineEmits<{ open: [Session]; delete: [Session]; archive: [Session] }>();

const { t } = useI18n();

const typeLabel = computed(() => contentTypeLabel(props.session.content_type, t));
const typeIcon = computed(() => contentTypeIcon(props.session.content_type));
const statusMap = computed(() => sessionStatusMap(t));

/** Localized created-at (absolute; `''` when absent so the template can `v-if` on it). */
const when = computed(() => {
  const raw = props.session.created_at;
  if (!raw) return '';
  const d = new Date(raw);
  return Number.isNaN(d.getTime()) ? raw : d.toLocaleString();
});
</script>

<template>
  <div class="flex items-start gap-next-4 rounded-next-lg border border-next-border bg-next-card p-next-4">
    <span
      class="flex h-9 w-9 shrink-0 items-center justify-center rounded-next-md bg-next-muted text-next-muted-foreground"
      aria-hidden="true"
    >
      <Icon :name="typeIcon" />
    </span>

    <div class="flex min-w-0 flex-1 flex-col gap-next-2">
      <!-- Name + type + status -->
      <div class="flex flex-wrap items-center gap-x-next-2 gap-y-next-1">
        <button
          type="button"
          class="truncate rounded-next-sm text-left font-next-semibold text-next-fg hover:text-next-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-next-ring"
          @click="emit('open', session)"
        >
          {{ session.name }}
        </button>
        <Badge variant="neutral" tone="subtle" size="sm" :icon="typeIcon">{{ typeLabel }}</Badge>
        <StatusBadge :status="session.status" :status-map="statusMap" size="sm" />
        <!-- Archived marker (never color-only: an icon + text Badge). -->
        <Badge v-if="session.is_archived" variant="neutral" tone="subtle" size="sm" icon="archive">
          {{ t('generator.sessions.archived') }}
        </Badge>
      </div>

      <!-- Metadata: when + creator -->
      <div class="flex flex-wrap items-center gap-x-next-3 gap-y-next-1 text-next-sm">
        <span v-if="when" class="inline-flex items-center gap-next-1 text-next-muted-foreground">
          <Icon name="clock" class="text-next-xs" aria-hidden="true" />
          {{ when }}
        </span>
        <CreatorBadge :creator="session.creator" size="xs" class="text-next-xs" />
        <!-- Bot author chip (ADDITIVE — the human creator stays shown; only when delegated). -->
        <BotAuthorChip v-if="session.bot_author" :author="session.bot_author" size="xs" class="text-next-xs" />
      </div>
    </div>

    <!-- Actions -->
    <div class="flex shrink-0 items-center gap-next-1">
      <Button size="sm" leading-icon="arrow-right" @click="emit('open', session)">
        {{ t('generator.sessions.list.open') }}
      </Button>
      <Tooltip :label="session.can_be_deleted ? t('generator.sessions.list.delete') : t('generator.sessions.list.deleteDisabled')">
        <Button
          variant="ghost"
          size="icon-sm"
          leading-icon="trash"
          :disabled="!session.can_be_deleted"
          :aria-label="t('generator.sessions.list.delete')"
          @click="emit('delete', session)"
        />
      </Tooltip>
      <!-- Overflow: archive / un-archive (creator-only, gated on can_archive). -->
      <DropdownMenu placement="bottom-end" :aria-label="t('generator.sessions.menu')">
        <template #trigger="{ props: triggerProps }">
          <button
            type="button"
            class="inline-flex h-8 w-8 items-center justify-center rounded-next-md text-next-fg transition-colors hover:bg-next-accent hover:text-next-accent-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-next-ring"
            :aria-haspopup="triggerProps['aria-haspopup']"
            :aria-expanded="triggerProps['aria-expanded'] === 'true'"
            :aria-controls="triggerProps['aria-controls']"
            :aria-label="t('generator.sessions.menu')"
          >
            <Icon name="more-vertical" />
          </button>
        </template>
        <DropdownMenuItem
          :icon="session.is_archived ? 'rotate-ccw' : 'archive'"
          :disabled="!session.can_archive"
          :label="session.is_archived ? t('generator.sessions.unarchive') : t('generator.sessions.archive')"
          @select="emit('archive', session)"
        >
          {{ session.is_archived ? t('generator.sessions.unarchive') : t('generator.sessions.archive') }}
        </DropdownMenuItem>
      </DropdownMenu>
    </div>
  </div>
</template>
