<script setup lang="ts">
// PageHeader — the standard page-top header every "next" page uses.
//
// Composes the existing system (Breadcrumbs, Heading, Icon, Avatar) into a
// consistent, responsive header: an optional breadcrumb row, a leading visual
// (icon bubble or avatar), a title + optional description, a trailing actions
// cluster, and an optional tabs row underneath. On narrow screens the actions
// wrap below the title instead of crowding it.
//
// Pass breadcrumbs via the `breadcrumbs` prop (→ our Breadcrumbs) OR the
// `#breadcrumbs` slot; the title via the `title` prop OR `#title`; actions via
// `#actions`; a Tabs row via `#tabs`; a status/meta chip next to the title via
// `#meta`.
//
// `size` scales the title row: `md` (default) is the full page header; `sm` is a
// tighter variant (smaller icon bubble, no responsive title jump) for panels or
// nested sections.
//
// A11y: renders a real `<header>` and a single `<h1>` (level configurable via
// `level` so a sub-page can use h2). The `#meta` chip sits beside — never inside
// — the heading so the heading stays plain text (truncation/screen readers). The
// leading icon bubble is decorative; an Avatar carries its own label.
import { computed } from 'vue';
import Breadcrumbs, { type BreadcrumbItem } from '../navigation/Breadcrumbs.vue';
import Icon, { type IconName } from '../primitives/Icon.vue';

const props = withDefaults(
  defineProps<{
    /** Page title (or use the #title slot). */
    title?: string;
    /** Sub-heading under the title (or use the #description slot). */
    description?: string;
    /** Semantic heading level for the title. */
    level?: 1 | 2 | 3;
    /** Crumbs for the built-in Breadcrumbs (or use the #breadcrumbs slot). */
    breadcrumbs?: BreadcrumbItem[];
    /** A leading icon rendered in a tinted bubble (decorative). */
    icon?: IconName;
    /** Header scale: `md` (default) or a tighter `sm`. */
    size?: 'md' | 'sm';
  }>(),
  { level: 1, size: 'md' },
);

const emit = defineEmits<{ (e: 'breadcrumb-navigate', item: BreadcrumbItem): void }>();

const headingTag = computed(() => `h${props.level}`);
const isSm = computed(() => props.size === 'sm');
</script>

<template>
  <header class="next-page-header flex flex-col gap-next-4">
    <!-- Breadcrumb row (slot wins over the prop). -->
    <div v-if="$slots.breadcrumbs || (breadcrumbs && breadcrumbs.length > 0)">
      <slot name="breadcrumbs">
        <Breadcrumbs
          :items="breadcrumbs ?? []"
          @navigate="emit('breadcrumb-navigate', $event)"
        />
      </slot>
    </div>

    <!-- Title row: leading visual + title/description on the left, actions on the
         right. Wraps to a stacked layout on small screens. -->
    <div class="flex flex-col gap-next-3 next-md:flex-row next-md:items-start next-md:justify-between">
      <div class="flex min-w-0 items-start" :class="isSm ? 'gap-next-2' : 'gap-next-3'">
        <!-- Leading visual: an Avatar via slot, or a tinted icon bubble. -->
        <div v-if="$slots.leading" class="shrink-0">
          <slot name="leading" />
        </div>
        <span
          v-else-if="icon"
          class="flex shrink-0 items-center justify-center rounded-next-lg bg-next-primary text-next-primary-foreground"
          :class="isSm ? 'h-9 w-9' : 'h-11 w-11'"
          aria-hidden="true"
        >
          <Icon :name="icon" :class="isSm ? 'text-next-lg' : 'text-next-xl'" />
        </span>

        <div class="flex min-w-0 flex-col gap-next-1">
          <!-- Heading + optional #meta on one line; meta sits beside (not inside)
               the heading so the heading stays plain text. -->
          <div class="flex min-w-0 items-center gap-next-2">
            <component
              :is="headingTag"
              class="min-w-0 font-next-semibold text-next-fg"
              :class="isSm ? 'text-next-xl' : 'text-next-2xl next-md:text-next-3xl'"
            >
              <slot name="title">{{ title }}</slot>
            </component>
            <span v-if="$slots.meta" class="shrink-0">
              <slot name="meta" />
            </span>
          </div>
          <p
            v-if="$slots.description || description"
            class="max-w-2xl text-next-sm text-next-muted-foreground"
          >
            <slot name="description">{{ description }}</slot>
          </p>
        </div>
      </div>

      <!-- Actions cluster (buttons). Wraps below the title on small screens. -->
      <div
        v-if="$slots.actions"
        class="flex shrink-0 flex-wrap items-center gap-next-2"
      >
        <slot name="actions" />
      </div>
    </div>

    <!-- Optional tabs row under the header. -->
    <div v-if="$slots.tabs">
      <slot name="tabs" />
    </div>
  </header>
</template>
