<script setup lang="ts">
// Breadcrumbs — a hierarchical path nav for the "next" frontend.
//
// Items are passed as an array of `{ label, to?, href?, icon? }`. Each item links
// (router-link when `to` is set, plain `<a>` when `href` is set) EXCEPT the last,
// which renders as plain text marked `aria-current="page"`. Labels truncate.
//
// Long-path collapsing: when there are more than `maxVisible` items, the middle
// items collapse into a single "…" trigger that opens a DropdownMenu listing the
// hidden crumbs; the first crumb and the last `maxVisible - 1` tail crumbs always
// stay visible.
//
// A11y: `<nav aria-label>` wrapping an `<ol>`; the separator icons are decorative
// (`aria-hidden`); the current page is the only non-link and carries
// `aria-current="page"`. The collapse trigger is a real menu button. Hidden
// crumbs in the menu emit `navigate` (the host routes — menu items are not links).
import { computed } from 'vue';
import Icon, { type IconName } from '../primitives/Icon.vue';
import DropdownMenu from '../overlay/DropdownMenu.vue';
import DropdownMenuItem from '../overlay/DropdownMenuItem.vue';

export interface BreadcrumbItem {
  label: string;
  /** router-link target (real app navigation). */
  to?: string | Record<string, unknown>;
  /** Plain anchor href (external / demos). Ignored when `to` is set. */
  href?: string;
  /** Optional leading icon for the crumb. */
  icon?: IconName;
}

const props = withDefaults(
  defineProps<{
    items: BreadcrumbItem[];
    /** Separator icon between crumbs. */
    separator?: IconName;
    /**
     * Max crumbs rendered inline before the middle collapses into a "…" menu.
     * The first crumb + the last `maxVisible - 1` crumbs always stay visible.
     * Set 0 / a number ≥ items.length to disable collapsing.
     */
    maxVisible?: number;
    ariaLabel?: string;
  }>(),
  {
    separator: 'chevron-right',
    maxVisible: 0,
    ariaLabel: 'Breadcrumbs',
  },
);

const emit = defineEmits<{ (e: 'navigate', item: BreadcrumbItem): void }>();

interface RenderedCrumb {
  kind: 'item' | 'collapse';
  item?: BreadcrumbItem;
  /** Hidden crumbs (for the collapse menu). */
  hidden?: BreadcrumbItem[];
  /** Index in the original list (item kind only). */
  index?: number;
}

const collapsed = computed<RenderedCrumb[]>(() => {
  const items = props.items;
  const max = props.maxVisible;
  if (!max || items.length <= max) {
    return items.map((item, index) => ({ kind: 'item', item, index }) as RenderedCrumb);
  }
  // Keep the first crumb + the last (max - 1) crumbs; collapse the middle.
  const tailCount = Math.max(1, max - 1);
  const head = items[0];
  const tail = items.slice(items.length - tailCount);
  const hidden = items.slice(1, items.length - tailCount);
  return [
    { kind: 'item', item: head, index: 0 },
    { kind: 'collapse', hidden },
    ...tail.map((item, i) => ({
      kind: 'item',
      item,
      index: items.length - tailCount + i,
    })) as RenderedCrumb[],
  ];
});

function isLast(index: number | undefined): boolean {
  return index === props.items.length - 1;
}

function linkTag(item: BreadcrumbItem): 'router-link' | 'a' {
  return item.to !== undefined ? 'router-link' : 'a';
}

function onNavigate(item: BreadcrumbItem): void {
  emit('navigate', item);
}
</script>

<template>
  <nav :aria-label="ariaLabel" class="next-breadcrumbs min-w-0 text-next-sm">
    <ol class="flex min-w-0 flex-wrap items-center gap-next-1">
      <template v-for="(crumb, i) in collapsed" :key="i">
        <li class="flex min-w-0 items-center gap-next-1">
          <!-- Collapsed middle: a "…" menu of hidden crumbs. -->
          <DropdownMenu
            v-if="crumb.kind === 'collapse'"
            aria-label="More breadcrumbs"
            placement="bottom-start"
          >
            <template #trigger="{ props: triggerProps, open }">
              <button
                v-bind="triggerProps"
                type="button"
                class="inline-flex h-6 w-6 items-center justify-center rounded-next-sm text-next-muted-foreground transition-colors duration-[var(--duration-next-fast)] hover:bg-next-accent hover:text-next-accent-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-next-ring"
                aria-label="Show collapsed breadcrumbs"
                :aria-expanded="open"
              >
                <Icon name="more-horizontal" />
              </button>
            </template>

<DropdownMenuItem
              v-for="(hiddenItem, hi) in crumb.hidden"
              :key="hi"
              :icon="hiddenItem.icon"
              @select="onNavigate(hiddenItem)"
            >
              {{ hiddenItem.label }}
            </DropdownMenuItem>
          </DropdownMenu>

          <!-- Current page (last item): plain text, marked current. -->
          <span
            v-else-if="isLast(crumb.index)"
            class="inline-flex min-w-0 items-center gap-next-1 font-next-medium text-next-fg"
            aria-current="page"
          >
            <Icon v-if="crumb.item!.icon" :name="crumb.item!.icon" class="shrink-0" />
            <span class="truncate">{{ crumb.item!.label }}</span>
          </span>

          <!-- Linked crumb. -->
          <component
            :is="linkTag(crumb.item!)"
            v-else
            :to="crumb.item!.to"
            :href="crumb.item!.to === undefined ? crumb.item!.href : undefined"
            class="inline-flex min-w-0 items-center gap-next-1 rounded-next-sm text-next-muted-foreground transition-colors duration-[var(--duration-next-fast)] hover:text-next-fg hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-next-ring"
            @click="onNavigate(crumb.item!)"
          >
            <Icon v-if="crumb.item!.icon" :name="crumb.item!.icon" class="shrink-0" />
            <span class="truncate">{{ crumb.item!.label }}</span>
          </component>

          <!-- Separator after every crumb except the last rendered one. -->
          <Icon
            v-if="i < collapsed.length - 1"
            :name="separator"
            class="shrink-0 text-next-muted-foreground/60"
            aria-hidden="true"
          />
        </li>
      </template>
    </ol>
  </nav>
</template>
