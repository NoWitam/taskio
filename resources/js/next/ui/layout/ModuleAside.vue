<script setup lang="ts">
// ModuleAside — the shared LEFT sub-navigation of a module shell. One component
// replaces the four copy-pasted asides in the forms / approvals / bots /
// workflows module layouts.
//
// TWO-LEVEL structure (user-accepted redesign):
//   • MODULE block — icon bubble + title + hint saying what the whole module is
//     for, followed by the module-level nav (pages that do NOT need a resource).
//   • RESOURCE section — only for modules with resource-scoped pages:
//     - no resource selected → a muted, dashed PLACEHOLDER (shaped like the
//       future selected block: icon, label, hint) that LINKS to the module list
//       where a resource is picked, followed by a purely decorative
//       (aria-hidden) preview of the resource nav in a disabled look;
//     - resource selected → the section moves to the TOP of the aside as a
//       tinted "selected" block (icon + name + back-to-list icon button +
//       #resource-meta + short description) above the live resource nav; the
//       module block drops below.
//
// The section swap ANIMATES: the three levels are keyed TransitionGroup
// children, so the module block glides (FLIP move) while the placeholder /
// selected sections fade-slide in and out — never a hard binary jump. Motion
// uses the shared duration/easing tokens and collapses to none under
// `prefers-reduced-motion`.
//
// The page's PageHeader describes the PAGE; this aside carries the module and
// resource identity — the two never duplicate each other.
//
// Nav item styling is the module convention: active = subtle primary pill
// (NAVIGATION per D3 — solid pills stay reserved for data-scope filters),
// inactive = quiet text with the neutral accent hover. Active state is decided
// by the host via `activeMatch` (route matching differs per module). `soon`
// items render as disabled rows with a "coming soon" tag.
//
// Hidden below the `next-lg` breakpoint — ModuleTabs is the small-screen
// fallback (D2=B). A11y: real <nav> elements labelled `common.moduleNav` /
// `resourceNavLabel` (distinct fallbacks — two landmarks never share one
// accessible name); the active link carries `aria-current="page"`; the disabled
// nav preview is decorative and hidden from AT.
import Surface from './Surface.vue';
import Icon, { type IconName } from '../primitives/Icon.vue';
import { useI18n } from '../../app/i18n';
import type { RouteLocationRaw } from 'vue-router';

export interface ModuleNavItem {
  /** Stable key (also the Tabs value in ModuleTabs). */
  key: string;
  label: string;
  icon: IconName;
  to?: RouteLocationRaw;
  /** Render as a disabled "coming soon" row instead of a link. */
  soon?: boolean;
}

/** The selected resource shown in the top block (icon + name + short description). */
export interface ModuleResource {
  icon: IconName;
  name: string;
  description?: string | null;
}

/** The empty-state resource slot: looks muted, links to the list to pick one. */
export interface ModuleResourcePlaceholder {
  icon: IconName;
  label: string;
  hint: string;
  to: RouteLocationRaw;
}

/** The selected block's back-to-list icon button (label = its accessible name). */
export interface ModuleResourceBack {
  label: string;
  to: RouteLocationRaw;
}

withDefaults(
  defineProps<{
    /** Module block (always rendered): what the module is for. */
    moduleIcon: IconName;
    moduleTitle: string;
    moduleHint?: string;
    /** Module-level pages (no resource required). */
    moduleItems: ModuleNavItem[];
    /** Resource-scoped pages; omit for modules without them (e.g. Approvals). */
    resourceItems?: ModuleNavItem[];
    /** The open resource — presence switches the resource section to the top. */
    resource?: ModuleResource | null;
    /** The pick-a-resource slot rendered when no resource is open. */
    resourcePlaceholder?: ModuleResourcePlaceholder;
    /** Back-to-list icon button in the selected block. */
    resourceBack?: ModuleResourceBack;
    /** Decides the active nav item (route matching differs per module). */
    activeMatch: (item: ModuleNavItem) => boolean;
    /** Accessible label of the resource nav (e.g. "Form sections"). */
    resourceNavLabel?: string;
    /** Accessible label of the module nav; defaults to `common.moduleNav`. */
    moduleNavLabel?: string;
  }>(),
  {
    moduleHint: undefined,
    resourceItems: undefined,
    resource: null,
    resourcePlaceholder: undefined,
    resourceBack: undefined,
    resourceNavLabel: undefined,
    moduleNavLabel: undefined,
  },
);

const { t } = useI18n();
</script>

<template>
  <Surface
    as="aside"
    bg="card"
    border
    elevation="sm"
    radius="lg"
    class="relative hidden w-64 shrink-0 min-h-0 flex-col overflow-y-auto next-lg:flex"
  >
    <TransitionGroup name="aside-section">
      <!-- SELECTED resource: identity block + its nav, promoted to the top. -->
      <div v-if="resource && resourceItems && resourceItems.length > 0" key="resource-selected">
        <div class="flex items-start gap-next-3 border-b border-next-border bg-next-primary-subtle p-next-4">
          <span
            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-next-lg bg-next-primary text-next-primary-foreground"
            aria-hidden="true"
          >
            <Icon :name="resource.icon" class="text-next-lg" />
          </span>
          <div class="min-w-0 flex-1">
            <h2 class="truncate text-next-sm font-next-semibold text-next-primary-subtle-foreground">
              {{ resource.name }}
            </h2>
            <div v-if="$slots['resource-meta']" class="mt-next-1">
              <slot name="resource-meta" />
            </div>
            <p v-if="resource.description" class="mt-next-1 line-clamp-2 text-next-xs text-next-muted-foreground">
              {{ resource.description }}
            </p>
          </div>
          <!-- Back to the list (icon-only; the label is its accessible name). -->
          <RouterLink
            v-if="resourceBack"
            :to="resourceBack.to"
            class="shrink-0 rounded-next-md p-next-1_5 text-next-muted-foreground transition-colors hover:bg-next-accent hover:text-next-accent-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-next-ring"
            :aria-label="resourceBack.label"
            :title="resourceBack.label"
          >
            <Icon name="arrow-left" aria-hidden="true" />
          </RouterLink>
        </div>

        <!-- Distinct fallback from the module nav below — two landmarks must
             never share one accessible name. -->
        <nav class="flex flex-col gap-next-0_5 p-next-2" :aria-label="resourceNavLabel ?? t('common.resourceNav')">
          <template v-for="item in resourceItems" :key="item.key">
            <RouterLink
              v-if="item.to && !item.soon"
              :to="item.to"
              class="flex items-center gap-next-2 rounded-next-md px-next-3 py-next-2 text-next-sm transition-colors"
              :class="activeMatch(item)
                ? 'bg-next-primary-subtle text-next-primary-subtle-foreground font-next-medium'
                : 'text-next-fg hover:bg-next-accent hover:text-next-accent-foreground'"
              :aria-current="activeMatch(item) ? 'page' : undefined"
            >
              <Icon :name="item.icon" class="shrink-0" />
              {{ item.label }}
            </RouterLink>
            <span
              v-else
              class="flex items-center gap-next-2 rounded-next-md px-next-3 py-next-2 text-next-sm text-next-muted-foreground/60"
            >
              <Icon :name="item.icon" class="shrink-0" />
              <span class="flex-1">{{ item.label }}</span>
              <span v-if="item.soon" class="text-next-2xs uppercase tracking-next-wide">{{ t('nav.comingSoon') }}</span>
            </span>
          </template>
        </nav>
      </div>

      <!-- MODULE block: what the module is for + its resource-free pages. -->
      <div key="module">
        <div
          class="flex items-start gap-next-3 border-b border-next-border p-next-4"
          :class="resource ? 'border-t' : ''"
        >
          <span
            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-next-lg bg-next-primary text-next-primary-foreground"
            aria-hidden="true"
          >
            <Icon :name="moduleIcon" class="text-next-lg" />
          </span>
          <div class="min-w-0">
            <h2 class="truncate text-next-sm font-next-semibold text-next-fg">{{ moduleTitle }}</h2>
            <p v-if="moduleHint" class="mt-next-0_5 text-next-xs text-next-muted-foreground">{{ moduleHint }}</p>
          </div>
        </div>

        <nav
          v-if="moduleItems.length > 0"
          class="flex flex-col gap-next-0_5 p-next-2"
          :aria-label="moduleNavLabel ?? t('common.moduleNav')"
        >
          <template v-for="item in moduleItems" :key="item.key">
            <RouterLink
              v-if="item.to && !item.soon"
              :to="item.to"
              class="flex items-center gap-next-2 rounded-next-md px-next-3 py-next-2 text-next-sm transition-colors"
              :class="activeMatch(item)
                ? 'bg-next-primary-subtle text-next-primary-subtle-foreground font-next-medium'
                : 'text-next-fg hover:bg-next-accent hover:text-next-accent-foreground'"
              :aria-current="activeMatch(item) ? 'page' : undefined"
            >
              <Icon :name="item.icon" class="shrink-0" />
              {{ item.label }}
            </RouterLink>
            <span
              v-else
              class="flex items-center gap-next-2 rounded-next-md px-next-3 py-next-2 text-next-sm text-next-muted-foreground/60"
            >
              <Icon :name="item.icon" class="shrink-0" />
              <span class="flex-1">{{ item.label }}</span>
              <span v-if="item.soon" class="text-next-2xs uppercase tracking-next-wide">{{ t('nav.comingSoon') }}</span>
            </span>
          </template>
        </nav>
      </div>

      <!-- EMPTY resource slot: a muted stand-in shaped like the selected block,
           linking to the list where a resource is picked, plus a decorative
           disabled preview of the resource nav. -->
      <div
        v-if="!resource && resourceItems && resourceItems.length > 0 && resourcePlaceholder"
        key="resource-empty"
      >
        <RouterLink
          :to="resourcePlaceholder.to"
          class="group m-next-3 flex items-start gap-next-3 rounded-next-lg border border-dashed border-next-border p-next-3 transition-colors hover:border-next-primary/50 hover:bg-next-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-next-ring"
        >
          <span
            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-next-lg bg-next-muted text-next-muted-foreground"
            aria-hidden="true"
          >
            <Icon :name="resourcePlaceholder.icon" class="text-next-lg" />
          </span>
          <div class="min-w-0">
            <p class="truncate text-next-sm font-next-medium text-next-muted-foreground transition-colors group-hover:text-next-fg">
              {{ resourcePlaceholder.label }}
            </p>
            <p class="mt-next-0_5 text-next-xs text-next-muted-foreground/70">{{ resourcePlaceholder.hint }}</p>
          </div>
        </RouterLink>

        <!-- Decorative preview of what unlocks after picking (hidden from AT). -->
        <div class="flex flex-col gap-next-0_5 px-next-2 pb-next-2" aria-hidden="true">
          <span
            v-for="item in resourceItems"
            :key="item.key"
            class="flex items-center gap-next-2 rounded-next-md px-next-3 py-next-2 text-next-sm text-next-muted-foreground/50"
          >
            <Icon :name="item.icon" class="shrink-0" />
            {{ item.label }}
          </span>
        </div>
      </div>
    </TransitionGroup>
  </Surface>
</template>

<style scoped>
/* Section swap animation: the module block FLIP-glides while the placeholder /
   selected sections fade-slide. The leaving section is lifted out of the flow
   so the survivors can start moving immediately. */
.aside-section-move,
.aside-section-enter-active {
  transition:
    transform var(--duration-next-normal) var(--ease-next-standard),
    opacity var(--duration-next-normal) var(--ease-next-standard);
}
.aside-section-leave-active {
  position: absolute;
  left: 0;
  right: 0;
  transition:
    transform var(--duration-next-fast) var(--ease-next-exit),
    opacity var(--duration-next-fast) var(--ease-next-exit);
}
.aside-section-enter-from,
.aside-section-leave-to {
  opacity: 0;
  transform: translateY(-0.5rem);
}

@media (prefers-reduced-motion: reduce) {
  .aside-section-move,
  .aside-section-enter-active,
  .aside-section-leave-active {
    transition: none;
  }
}
</style>
