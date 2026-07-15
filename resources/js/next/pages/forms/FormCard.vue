<script setup lang="ts">
// FormCard — one form row in the Forms BROWSE list (next frontend).
//
// Built on EntityCard: a leading forms icon bubble, the name + description, a
// status cluster (enabled/draft · indexed/indexing · deleted — each icon+text,
// never color-only), a metadata footer (submissions count, creator, created
// date), and a kebab DropdownMenu of lifecycle actions gated by the server's
// `can_be_*` capability flags. All strings are localized; the card itself is not
// a navigation target in this batch (detail/builder land in later batches), so
// only the action menu is interactive.
import { computed } from 'vue';
import EntityCard, { type EntityMetaItem } from '../../ui/patterns/EntityCard.vue';
import StatusBadge, { type StatusDescriptor } from '../../ui/data/StatusBadge.vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Spinner from '../../ui/primitives/Spinner.vue';
import DropdownMenu from '../../ui/overlay/DropdownMenu.vue';
import DropdownMenuItem from '../../ui/overlay/DropdownMenuItem.vue';
import { resolveFormIcon } from '../../ui/forms/formIcon';
import { creatorIcon, creatorLabel } from '../../ui/patterns/creator';
import { useI18n } from '../../app/i18n';
import type { FormSummary } from './types';

const props = defineProps<{
  form: FormSummary;
  /** Rendered in the trashed view → swaps lifecycle actions for restore/purge. */
  trashed?: boolean;
  /** This card is fetching its form before navigating into its sub-module. */
  opening?: boolean;
}>();

const emit = defineEmits<{
  (e: 'open', form: FormSummary): void;
  (e: 'edit', form: FormSummary): void;
  (e: 'enable', form: FormSummary): void;
  (e: 'disable', form: FormSummary): void;
  (e: 'index', form: FormSummary): void;
  (e: 'unindex', form: FormSummary): void;
  (e: 'restore-index', form: FormSummary): void;
  (e: 'delete', form: FormSummary): void;
  (e: 'restore', form: FormSummary): void;
  (e: 'force-delete', form: FormSummary): void;
}>();

const { t } = useI18n();

// Whole-card accessible name: the active list card names the open action; a trashed
// card has none (only the kebab acts).
const actionLabel = computed<string | undefined>(() =>
  !props.trashed ? t('forms.card.open', '', { name: props.form.name }) : undefined,
);

// Status badges. Each is icon + text (color is never the sole signal). The
// primary state (deleted / enabled / draft) always shows; an index state shows
// alongside it when relevant.
interface Badge {
  key: string;
  descriptor: StatusDescriptor;
}
const badges = computed<Badge[]>(() => {
  const list: Badge[] = [];
  if (props.trashed) {
    list.push({
      key: 'deleted',
      descriptor: { label: t('forms.status.deleted'), variant: 'neutral', tone: 'subtle', icon: 'trash' },
    });
    return list;
  }

  list.push(
    props.form.is_enabled
      ? { key: 'enabled', descriptor: { label: t('forms.status.enabled'), variant: 'success', tone: 'subtle', icon: 'check-circle' } }
      : { key: 'draft', descriptor: { label: t('forms.status.draft'), variant: 'neutral', tone: 'subtle', icon: 'file-text' } },
  );

  if (props.form.is_indexing) {
    list.push({
      key: 'indexing',
      descriptor: { label: t('forms.status.indexing'), variant: 'warning', tone: 'subtle', icon: 'loader' },
    });
  } else if (props.form.is_indexed) {
    list.push({
      key: 'indexed',
      descriptor: { label: t('forms.status.indexed'), variant: 'info', tone: 'subtle', icon: 'sparkles' },
    });
  }

  return list;
});

const meta = computed<EntityMetaItem[]>(() => {
  const out: EntityMetaItem[] = [
    { icon: 'inbox', label: t('forms.card.submissions'), value: props.form.submissions_count ?? 0 },
  ];
  if (props.form.creator) {
    out.push({ icon: creatorIcon(props.form.creator), label: creatorLabel(props.form.creator, t) });
  }
  if (props.form.created_at) {
    out.push({ icon: 'calendar', label: formatDate(props.form.created_at) });
  }
  return out;
});

// ISO timestamp → `dd.mm.yyyy` (locale-agnostic; the surrounding labels are i18n).
function formatDate(iso: string): string {
  const m = iso.match(/^(\d{4})-(\d{2})-(\d{2})/);
  return m ? `${m[3]}.${m[2]}.${m[1]}` : iso;
}

// Whether the lifecycle menu would have ANY enabled action (so we can still show
// the kebab — disabled actions stay visible to explain why, per the UX rules).
const hasMenu = computed(() => true);

// The whole (non-trashed) card opens the form's sub-module. We emit `open` (rather
// than a router link) so the page can PREFETCH the form before navigating, then
// render the sub-module already populated. Trashed forms have no sub-module.
function onOpen(): void {
  if (props.trashed) return;
  emit('open', props.form);
}
</script>

<template>
  <EntityCard
    :title="form.name"
    :subtitle="form.description ?? t('forms.card.noDescription')"
    :disabled="trashed || opening"
    :action-label="actionLabel"
    @click="onOpen"
  >
    <template #leading>
      <span
        class="flex h-10 w-10 items-center justify-center rounded-next-lg bg-next-muted text-next-muted-foreground"
        aria-hidden="true"
      >
        <Spinner v-if="opening" size="sm" tone="muted" decorative />
        <!-- The form's OWN icon (legacy IconEnum → next glyph; file-text fallback). -->
        <Icon v-else :name="resolveFormIcon(form.icon)" class="text-next-lg" />
      </span>
    </template>

    <!-- Status cluster: one or two icon+text badges. -->
    <template #status>
      <div class="flex flex-wrap items-center justify-end gap-next-1">
        <StatusBadge
          v-for="b in badges"
          :key="b.key"
          :status="b.key"
          :label="b.descriptor.label"
          :status-map="{ [b.key]: b.descriptor }"
          size="sm"
        />
      </div>
    </template>

    <!-- Lifecycle actions, gated by the server capability flags. -->
    <template #actions>
      <DropdownMenu v-if="hasMenu" placement="bottom-end" :aria-label="t('forms.actions.menu')">
        <template #trigger="{ props: triggerProps }">
          <Button
            v-bind="triggerProps"
            variant="ghost"
            size="icon-sm"
            leading-icon="more-vertical"
            :aria-label="t('forms.actions.menu')"
          />
        </template>

        <!-- Trashed view: restore + permanent delete only. -->
        <template v-if="trashed">
          <DropdownMenuItem icon="rotate-ccw" :label="t('forms.actions.restore')" @select="emit('restore', form)">
            {{ t('forms.actions.restore') }}
          </DropdownMenuItem>
          <DropdownMenuItem
            icon="trash"
            destructive
            :label="t('forms.actions.forceDelete')"
            @select="emit('force-delete', form)"
          >
            {{ t('forms.actions.forceDelete') }}
          </DropdownMenuItem>
        </template>

        <!-- Active view: edit + activation + indexing + delete, each gated by a flag. -->
        <template v-else>
          <DropdownMenuItem
            v-if="form.can_be_edited"
            icon="pencil"
            :label="t('forms.actions.edit')"
            @select="emit('edit', form)"
          >
            {{ t('forms.actions.edit') }}
          </DropdownMenuItem>
          <DropdownMenuItem
            v-if="form.can_be_enabled"
            icon="check-circle"
            :label="t('forms.actions.enable')"
            @select="emit('enable', form)"
          >
            {{ t('forms.actions.enable') }}
          </DropdownMenuItem>
          <DropdownMenuItem
            v-if="form.can_be_disabled"
            icon="x-circle"
            :label="t('forms.actions.disable')"
            @select="emit('disable', form)"
          >
            {{ t('forms.actions.disable') }}
          </DropdownMenuItem>
          <DropdownMenuItem
            v-if="form.can_be_indexed"
            icon="sparkles"
            :label="t('forms.actions.index')"
            @select="emit('index', form)"
          >
            {{ t('forms.actions.index') }}
          </DropdownMenuItem>
          <DropdownMenuItem
            v-if="form.can_be_unindexed"
            icon="x"
            :label="t('forms.actions.unindex')"
            @select="emit('unindex', form)"
          >
            {{ t('forms.actions.unindex') }}
          </DropdownMenuItem>
          <DropdownMenuItem
            v-if="form.can_restore_index"
            icon="rotate-ccw"
            :label="t('forms.actions.restoreIndex')"
            @select="emit('restore-index', form)"
          >
            {{ t('forms.actions.restoreIndex') }}
          </DropdownMenuItem>
          <DropdownMenuItem
            icon="trash"
            destructive
            :label="t('forms.actions.delete')"
            @select="emit('delete', form)"
          >
            {{ t('forms.actions.delete') }}
          </DropdownMenuItem>
        </template>
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
