<script setup lang="ts">
// PublicationCard — one row of the publications list, built on `EntityCard`.
//
// A CARD AND NOT A TABLE ROW. The two things a person scans this list for are PROSE (the
// title and the first line of the body) and a MOMENT. A table with the caption clipped at
// forty characters answers neither, and `Table responsive="stack"` below `next-md` would
// explode every row into label/value pairs — the worst possible layout for a content object.
// `EntityCard` already has the regions this object needs: leading glyph, title, clamped
// subtitle, status, metadata footer, kebab.
//
// EVERY AFFORDANCE IS GATED ON A `can_be_*` FLAG, never on `is_owner` and never on this
// screen's own reading of `status` — the flags route through the policy and compose who is
// asking with where the row is in its life. The three named exceptions live in
// `publicationActions.ts`, where they are argued one by one.
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import EntityCard from '../../ui/patterns/EntityCard.vue';
import Badge from '../../ui/primitives/Badge.vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import CreatorBadge from '../../ui/patterns/CreatorBadge.vue';
import DropdownMenu from '../../ui/overlay/DropdownMenu.vue';
import DropdownMenuItem from '../../ui/overlay/DropdownMenuItem.vue';
import { useI18n } from '../../app/i18n';
import { failureSentenceKey, platformIcon, statusIcon, toneToVariant } from './publishingMeta';
import { armAffordance, hasRowActions } from './publicationActions';
import { formatInstant } from './publishingTime';
import type { PlatformConnection, Publication } from './types';

const props = defineProps<{
  publication: Publication;
  /** The workspace's clock. Null = "inherit the application clock" (see publishingTime). */
  timezone: string | null;
  /** Known connections, for the one affordance a flag cannot answer alone (D5/L5). */
  connections?: PlatformConnection[] | null;
}>();

const emit = defineEmits<{
  (e: 'edit', publication: Publication): void;
  (e: 'schedule', publication: Publication): void;
  (e: 'delete', publication: Publication): void;
}>();

const { t, currentLocale } = useI18n();
const router = useRouter();

const to = computed(() => ({
  name: 'next.publishing.publication.overview',
  params: { id: props.publication.id },
}));

const arm = computed(() => armAffordance(props.publication, props.connections));
const showKebab = computed(() => hasRowActions(props.publication));

/**
 * ONE moment, chosen by status — never two.
 *
 * A card reading "Goes out 09:00 · Published 11:24" makes the reader compare two numbers in
 * the one place built for scanning. Both moments stand together on the DETAIL, where the
 * difference between them is information (reconciliation can pull them hours apart) rather
 * than noise.
 */
const moment = computed(() => {
  const p = props.publication;
  const at = (iso: string | null): string => formatInstant(iso, props.timezone, currentLocale.value);

  switch (p.status) {
    case 'published':
      return p.published_at
        ? t('publishing.card.moment.published', '', { value: at(p.published_at) })
        : '';
    case 'scheduled':
    case 'blocked':
      return p.scheduled_at
        ? t('publishing.card.moment.scheduled', '', { value: at(p.scheduled_at) })
        : '';
    case 'publishing':
      return p.last_attempt_at
        ? t('publishing.card.moment.since', '', { value: at(p.last_attempt_at) })
        : '';
    default:
      // A DRAFT WITH A MOMENT IS NOT GOING ANYWHERE, and the card has to say so. Setting a
      // time is not arming; a card that printed a bare "Goes out 10 Sep 09:00" here would
      // promise a publication nobody asked for.
      return p.scheduled_at
        ? t('publishing.card.moment.unarmed', '', { value: at(p.scheduled_at) })
        : t('publishing.card.moment.none');
  }
});

/** The failure sentence, one line, only on a row that needs a decision. */
const failureSentence = computed(() => {
  const p = props.publication;
  if (!p.needs_attention || !p.failure_code) return '';
  const key = failureSentenceKey(p.failure_code);
  return t(key, '', { code: p.failure_code });
});

/**
 * The attention accent is a LEFT EDGE, not a filled card. Twenty red cards in a list stop
 * meaning anything; an edge plus a sentence in the footer keeps the signal countable.
 */
const accentClass = computed(() => {
  if (!props.publication.needs_attention) return '';
  return toneToVariant(props.publication.status_tone) === 'warning'
    ? 'border-l-2 border-l-next-warning'
    : 'border-l-2 border-l-next-danger';
});

function onOpen(): void {
  void router.push(to.value);
}

/** `noopener,noreferrer`: the destination is a page this application does not control. */
function onOpenRemote(): void {
  const url = props.publication.remote_url;
  if (url) window.open(url, '_blank', 'noopener,noreferrer');
}
</script>

<template>
  <EntityCard
    :title="publication.title"
    :subtitle="publication.body ?? ''"
    :subtitle-lines="2"
    :to="to"
    :class="accentClass"
  >
    <!-- Destination glyph. A hairline keeps the bubble a separate surface in dark mode,
         where `muted` and `card` sit close together. -->
    <template #leading>
      <span
        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-next-lg border border-next-border bg-next-muted text-next-muted-foreground"
        aria-hidden="true"
      >
        <Icon :name="platformIcon(publication.platform)" class="text-next-lg" />
      </span>
    </template>

    <!-- The status badge: the server's tone picks the variant, the server's prose is the
         text, and the icon means colour is never the only signal. -->
    <template #status>
      <Badge
        :variant="toneToVariant(publication.status_tone)"
        tone="subtle"
        size="sm"
        :icon="statusIcon(publication.status)"
      >
        {{ publication.status_label }}
      </Badge>
    </template>

    <template #meta>
      <span class="inline-flex items-center gap-next-1">
        <Icon name="send" class="text-next-xs" aria-hidden="true" />
        {{ publication.platform_label }}
        <Badge v-if="!publication.publishes_publicly" variant="neutral" tone="subtle" size="sm">
          {{ t('publishing.rehearsal') }}
        </Badge>
      </span>

      <span v-if="moment" class="inline-flex items-center gap-next-1">
        <Icon name="clock" class="text-next-xs" aria-hidden="true" />
        {{ moment }}
      </span>

      <span v-if="publication.media.length > 0" class="inline-flex items-center gap-next-1">
        <Icon name="image" class="text-next-xs" aria-hidden="true" />
        {{ t('publishing.card.media', '', { count: publication.media.length }) }}
      </span>

      <!-- The creator is polymorphic: a publication is often a workflow run's or a bot's. -->
      <CreatorBadge :creator="publication.creator ?? null" size="xs" />

      <span
        v-if="failureSentence"
        class="inline-flex min-w-0 items-center gap-next-1 text-next-danger"
      >
        <Icon name="alert-circle" class="shrink-0 text-next-xs" aria-hidden="true" />
        <span class="truncate">{{ failureSentence }}</span>
      </span>
    </template>

    <!-- The kebab does not render at all when nothing but "Open" would be in it: a menu
         whose only entry repeats clicking the card teaches that the kebab is sometimes
         empty, and then nobody opens it when it is not. -->
    <template v-if="showKebab" #actions>
      <DropdownMenu
        placement="bottom-end"
        :aria-label="t('publishing.card.actionsLabel', '', { title: publication.title })"
      >
        <template #trigger="{ props: triggerProps }">
          <Button
            variant="ghost"
            size="icon-sm"
            leading-icon="more-vertical"
            :aria-label="t('publishing.card.actionsLabel', '', { title: publication.title })"
            :aria-haspopup="triggerProps['aria-haspopup']"
            :aria-expanded="triggerProps['aria-expanded'] === 'true'"
            :aria-controls="triggerProps['aria-controls']"
          />
        </template>

        <DropdownMenuItem
          icon="arrow-right"
          :label="t('publishing.actions.open')"
          @select="onOpen"
        >
          {{ t('publishing.actions.open') }}
        </DropdownMenuItem>

        <!-- The one road to the artifact itself. `noopener,noreferrer` because the target
             is a page this application does not control. -->
        <DropdownMenuItem
          v-if="publication.remote_url"
          icon="external-link"
          :label="`${t('publishing.actions.openOnPlatform')} — ${t('publishing.actions.openOnPlatformHint')}`"
          @select="onOpenRemote"
        >
          {{ t('publishing.actions.openOnPlatform') }}
        </DropdownMenuItem>

        <DropdownMenuItem
          v-if="publication.can_be_edited"
          icon="pencil"
          :label="t('publishing.actions.edit')"
          @select="emit('edit', publication)"
        >
          {{ t('publishing.actions.edit') }}
        </DropdownMenuItem>

        <!-- Arming, named for what it actually does. `changeTime` is a PUT, not a
             transition; `submitForReview` opens a review rather than arming anything. -->
        <DropdownMenuItem
          v-if="arm.kind !== 'none'"
          :icon="arm.kind === 'changeTime' ? 'clock' : 'send'"
          :label="t(`publishing.actions.${arm.kind}`)"
          :disabled="!arm.enabled"
          @select="emit('schedule', publication)"
        >
          {{ t(`publishing.actions.${arm.kind}`) }}
        </DropdownMenuItem>

        <!-- Checking from the list only NAVIGATES. A check spends a platform limit shared
             by the whole installation and has three outcomes, one of which has to be read;
             a button that fires it from a row showing neither the cause nor the result
             would be an invitation to click in a loop. -->
        <DropdownMenuItem
          v-if="publication.can_be_reconciled"
          icon="help-circle"
          :label="t('publishing.actions.reconcile')"
          @select="onOpen"
        >
          {{ t('publishing.actions.reconcile') }}
        </DropdownMenuItem>

        <DropdownMenuItem
          v-if="publication.can_be_deleted"
          icon="trash"
          destructive
          :label="
            publication.status === 'published'
              ? t('publishing.actions.deleteRecord')
              : t('publishing.actions.delete')
          "
          @select="emit('delete', publication)"
        >
          {{
            publication.status === 'published'
              ? t('publishing.actions.deleteRecord')
              : t('publishing.actions.delete')
          }}
        </DropdownMenuItem>
      </DropdownMenu>
    </template>
  </EntityCard>
</template>
