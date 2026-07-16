<script setup lang="ts">
// EntityCard — the standard "domain object" card for the "next" frontend.
//
// A composed pattern built ON Card: a consistent layout for a domain entity
// (a task, a user, a form, …) with well-defined regions:
//   • leading visual  — an Avatar / Icon (the `#leading` slot),
//   • title           — text, optionally a Link / router `to` (the whole-card
//                       action when interactive),
//   • subtitle        — a clamped description line,
//   • status          — a StatusBadge (the `status` prop) or the `#status` slot,
//   • metadata footer — a CONSISTENT area of icon+label[+value] pairs (the `meta`
//                       prop and/or the `#meta` slot),
//   • actions         — a kebab DropdownMenu / buttons (the `#actions` slot) that
//                       stay clickable ABOVE the stretched whole-card link.
//
// Interactive: when given `to` (router) / `href` (anchor) / a `@click` listener,
// the WHOLE card becomes a single accessible action via the "stretched link"
// pattern — exactly one tab stop + accessible name covers the card, while the
// `#actions` region sits above it (z-index) so its controls stay usable. This is
// Card's single-accessible-action rule, applied to the entity layout. (`to` needs
// a router; the link element is resolved from the global `router-link` when
// present, else a plain <a>.)
//
// `loading` renders a skeleton variant that MIRRORS the card's geometry (an
// avatar circle + a title line + subtitle + meta lines) per the skeleton rule —
// never a spinner.
import { computed, getCurrentInstance, resolveComponent, useSlots, type Component } from 'vue';
import type { RouteLocationRaw } from 'vue-router';
import Card from '../layout/Card.vue';
import Icon, { type IconName } from '../primitives/Icon.vue';
import Skeleton from '../data/Skeleton.vue';
import StatusBadge, { type StatusKey, type StatusMap } from '../data/StatusBadge.vue';

export interface EntityMetaItem {
  /** Leading icon for the pair. */
  icon?: IconName;
  /** The label (or, with `value`, the field name). */
  label: string;
  /** Optional value rendered after the label. */
  value?: string | number;
}

const props = withDefaults(
  defineProps<{
    /** Primary title text. Override with the `#title` slot for richer content. */
    title?: string;
    /** Secondary description (clamped to `subtitleLines`). */
    subtitle?: string;
    /** Clamp the subtitle to N lines. */
    subtitleLines?: number;
    /** A known status → renders a StatusBadge (or use the `#status` slot). */
    status?: StatusKey | string;
    /** Override the status label. */
    statusLabel?: string;
    /** Extend / override the status mapping per domain. */
    statusMap?: StatusMap;
    /** Metadata footer pairs (icon + label [+ value]); also see the `#meta` slot. */
    meta?: EntityMetaItem[];

    // --- Interactive (single whole-card action) ---
    /** Router target — makes the whole card a router-link action. */
    to?: RouteLocationRaw;
    /** Href — makes the whole card an anchor action. */
    href?: string;
    /** Anchor target; `_blank` adds rel="noopener noreferrer". */
    target?: string;
    /** Accessible name for the whole-card action (when the title isn't enough). */
    actionLabel?: string;

    // --- States ---
    selected?: boolean;
    disabled?: boolean;
    /** Skeleton variant mirroring the card geometry. */
    loading?: boolean;
  }>(),
  {
    subtitleLines: 2,
    selected: false,
    disabled: false,
    loading: false,
  },
);

const emit = defineEmits<{ (e: 'click', event: MouseEvent): void }>();

const slots = useSlots();
const instance = getCurrentInstance();

// Interactive when any navigation/click intent is supplied. A `@click` listener
// alone (no to/href) makes the card a <button> action. A declared emit listener
// lands on the instance vnode props (not $attrs / props), so detect it there.
const hasClick = computed(() => !!instance?.vnode.props?.onClick);
const isInteractive = computed(
  () => props.to !== undefined || props.href !== undefined || hasClick.value,
);
const isInert = computed(() => props.disabled);

// Use a router-link only when `to` is set AND a router is registered globally
// (resolveComponent returns the string name when it isn't).
const useRouterLink = computed(
  () => props.to !== undefined && !isInert.value && typeof resolveComponent('router-link') !== 'string',
);

// Resolve the action element for the stretched title link.
const actionTag = computed<Component | 'a' | 'button'>(() => {
  if (useRouterLink.value) return resolveComponent('router-link') as Component;
  if (props.href !== undefined) return 'a';
  if (props.to !== undefined && !isInert.value) return 'a'; // `to` without router → fall back to <a>
  return 'button';
});

const renderAsAnchor = computed(
  () => actionTag.value === 'a',
);

const subtitleClampStyle = computed(() => ({
  display: '-webkit-box',
  WebkitLineClamp: String(props.subtitleLines),
  WebkitBoxOrient: 'vertical' as const,
  overflow: 'hidden',
}));

const hasMeta = computed(() => (props.meta && props.meta.length > 0) || !!slots.meta);
const hasStatus = computed(() => props.status !== undefined || !!slots.status);

function onActivate(event: MouseEvent): void {
  if (isInert.value) {
    event.preventDefault();
    event.stopPropagation();
    return;
  }
  emit('click', event);
}
</script>

<template>
  <!-- Card hosts the surface, selected/disabled visuals, and (for interactive
       cards) the :focus-within ring. We pass variant `interactive` so the hover
       lift + focus ring kick in, but render OUR OWN stretched action (so router
       `to` works) inside the body. -->
  <Card
    :variant="isInteractive ? 'interactive' : 'default'"
    :selected="selected"
    :disabled="disabled"
    class="next-entity-card"
  >
    <!-- Loading skeleton mirrors the real geometry: avatar circle + title line +
         subtitle + two meta lines. Several shapes, not a spinner. -->
    <div v-if="loading" class="flex flex-col gap-next-3" aria-hidden="true">
      <div class="flex items-start gap-next-3">
        <Skeleton variant="circle" diameter="2.5rem" />
        <div class="flex min-w-0 flex-1 flex-col gap-next-2">
          <Skeleton variant="text" width="55%" />
          <Skeleton variant="text" width="85%" />
        </div>
      </div>
      <div class="flex flex-wrap gap-next-4 pt-next-1">
        <Skeleton variant="text" width="6rem" />
        <Skeleton variant="text" width="5rem" />
      </div>
    </div>

    <div v-else class="flex items-start gap-next-3">
      <!-- Leading visual (Avatar / Icon). Decorative wrapper. -->
      <div v-if="$slots.leading" class="shrink-0">
        <slot name="leading" />
      </div>

      <div class="flex min-w-0 flex-1 flex-col gap-next-1">
        <div class="flex min-w-0 items-start justify-between gap-next-3">
          <div class="min-w-0">
            <!-- Title — the whole-card stretched action when interactive. -->
            <component
              :is="actionTag"
              v-if="isInteractive"
              class="next-entity-card__action min-w-0 rounded-next-sm text-left text-next-sm font-next-semibold text-next-fg outline-none"
              :to="useRouterLink ? to : undefined"
              :href="!useRouterLink && renderAsAnchor && !isInert ? href : undefined"
              :target="renderAsAnchor ? target : undefined"
              :rel="renderAsAnchor && target === '_blank' ? 'noopener noreferrer' : undefined"
              :type="actionTag === 'button' ? 'button' : undefined"
              :disabled="actionTag === 'button' && isInert ? true : undefined"
              :aria-disabled="isInert ? 'true' : undefined"
              :aria-label="actionLabel"
              @click="onActivate"
            >
              <slot name="title">
                <span class="block truncate">{{ title }}</span>
              </slot>
            </component>
            <div v-else class="min-w-0 text-next-sm font-next-semibold text-next-fg">
              <slot name="title">
                <span class="block truncate">{{ title }}</span>
              </slot>
            </div>

            <p
              v-if="subtitle || $slots.subtitle"
              class="mt-next-0_5 text-next-sm text-next-muted-foreground"
              :style="subtitleClampStyle"
            >
              <slot name="subtitle">{{ subtitle }}</slot>
            </p>
          </div>

          <!-- Trailing cluster: status badge + actions. Both sit ABOVE the
               stretched link (relative + z) so actions stay independently
               clickable (conditional status before the permanent actions kebab). -->
          <div class="next-entity-card__trailing relative flex shrink-0 items-center gap-next-2">
            <div v-if="hasStatus" class="shrink-0">
              <slot name="status">
                <StatusBadge
                  v-if="status !== undefined"
                  :status="status"
                  :label="statusLabel"
                  :status-map="statusMap"
                  size="sm"
                />
              </slot>
            </div>
            <div v-if="$slots.actions" class="shrink-0">
              <slot name="actions" />
            </div>
          </div>
        </div>

        <!-- Metadata footer: a CONSISTENT row of icon+label[+value] pairs. -->
        <div
          v-if="hasMeta"
          class="mt-next-2 flex flex-wrap items-center gap-x-next-4 gap-y-next-1 text-next-xs text-next-muted-foreground"
        >
          <slot name="meta">
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
          </slot>
        </div>
      </div>
    </div>
  </Card>
</template>

<style scoped>
/* Stretched link: the title action's hit target covers the whole card, so the
   entire card is clickable with exactly one tab stop / accessible name. The
   trailing cluster (.next-entity-card__trailing) is positioned + raised so its
   controls (status / kebab) stay independently usable above the stretched link. */
.next-entity-card__action::after {
  content: '';
  position: absolute;
  inset: 0;
  z-index: 1;
}

.next-entity-card__trailing {
  z-index: 2;
}

/* The card already rings via :focus-within, so suppress the inner action's own
   ring to avoid a double ring. */
.next-entity-card__action:focus-visible {
  outline: none;
}
</style>
