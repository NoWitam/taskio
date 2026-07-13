<script setup lang="ts" generic="T extends string = string">
// Tabs — a tab list + panels for the "next" frontend.
//
// Item-driven API: pass `items` ({ value, label, icon?, badge?, disabled? });
// the active panel is `v-model` (controlled) or self-managed (uncontrolled, seeded
// from the first non-disabled tab). Render panel bodies with the scoped
// `#panel="{ value }"` slot (or per-value `#panel-<value>` slots). Optional lazy
// mounting only mounts a panel once it has been activated.
//
// NAV-ONLY use (no panel slot at all — e.g. ModuleTabs, where the "panel" is the
// routed page below): the tabpanel elements and the tabs' `aria-controls` are
// omitted entirely, so no empty-but-focusable tabpanel lands in the a11y tree
// and the root's gap adds no dead space under the tab row.
//
// Variants: `underline` (default — a moving underline under the active tab) and
// `pills` (segmented filled chips in a tinted track). Sizes `sm` / `md`.
//
// Overflow: the tab list NEVER wraps — it scrolls horizontally with edge fades and
// keeps the focused/selected tab scrolled into view.
//
// A11y: `role="tablist"` / `tab` / `tabpanel`; roving tabindex (only the active
// tab is a tab stop); ←/→ move, Home/End jump (skipping disabled); `aria-selected`,
// `aria-controls` / `aria-labelledby`. `activation="automatic"` (default) selects
// on arrow-move; `activation="manual"` only moves focus (Enter/Space selects).
import { computed, nextTick, onMounted, onBeforeUnmount, ref, shallowRef, useSlots, watch } from 'vue';
import Icon, { type IconName } from '../primitives/Icon.vue';
import Badge from '../primitives/Badge.vue';

export interface TabItem<V extends string = string> {
  /** Stable value used by v-model + panel slots. */
  value: V;
  label: string;
  icon?: IconName;
  /** Numeric count rendered as a small Badge. */
  badge?: number | string;
  disabled?: boolean;
}

type TabsVariant = 'underline' | 'pills';
type TabsSize = 'sm' | 'md';
type TabsActivation = 'automatic' | 'manual';

const props = withDefaults(
  defineProps<{
    items: TabItem<T>[];
    variant?: TabsVariant;
    size?: TabsSize;
    /** automatic: arrow-move selects; manual: arrow-move only focuses. */
    activation?: TabsActivation;
    /** Only mount a panel once it has been activated (and keep it mounted). */
    lazy?: boolean;
    /**
     * Fill the available height: the Tabs root becomes a height-constrained flex
     * column (`flex-1 min-h-0`) with a fixed tablist and the ACTIVE panel growing
     * to fill the rest (`flex-1 min-h-0 flex flex-col`) so its content can scroll
     * internally. Use inside a full-height page (e.g. a board). Off by default so
     * document-flow pages keep content-sized panels.
     */
    fill?: boolean;
    /** Accessible label for the tablist (recommended). */
    ariaLabel?: string;
  }>(),
  {
    variant: 'underline',
    size: 'md',
    activation: 'automatic',
    lazy: false,
    fill: false,
  },
);

// Controlled when a parent binds v-model; otherwise self-managed.
const model = defineModel<T | null>({ default: null });

// NAV-ONLY detection: with no panel slot of any kind, skip the tabpanel
// elements (and the tabs' aria-controls) entirely.
const slots = useSlots();
const hasPanels = computed(
  () => !!slots.panel || props.items.some((item) => !!slots[`panel-${item.value}`]),
);

const baseId = `next-tabs-${Math.random().toString(36).slice(2, 8)}`;
const tabId = (value: string) => `${baseId}-tab-${value}`;
const panelId = (value: string) => `${baseId}-panel-${value}`;

const enabled = computed(() => props.items.filter((t) => !t.disabled));
const firstEnabled = computed<T | null>(() => enabled.value[0]?.value ?? null);

// Resolve the active value, falling back to the first enabled tab when the model
// is unset or points at a missing/disabled tab.
const active = computed<T | null>(() => {
  const v = model.value;
  if (v != null && props.items.some((t) => t.value === v && !t.disabled)) return v;
  return firstEnabled.value;
});

// Track which panels have ever been active, for lazy mounting. A shallowRef +
// reassignment keeps reactivity without Vue deep-unwrapping the Set's generic.
const mounted = shallowRef<Set<T>>(new Set<T>());
watch(
  active,
  (v) => {
    if (v != null && !mounted.value.has(v)) {
      mounted.value = new Set(mounted.value).add(v);
    }
  },
  { immediate: true },
);

function select(value: T): void {
  const item = props.items.find((t) => t.value === value);
  if (!item || item.disabled) return;
  model.value = value;
}

// --- Refs + roving focus ----------------------------------------------------
const tabRefs = ref<Record<string, HTMLButtonElement | null>>({});
const listRef = ref<HTMLElement | null>(null);

function setTabRef(el: HTMLButtonElement | null, value: string): void {
  tabRefs.value[value] = el;
}

function focusTab(value: T): void {
  const el = tabRefs.value[value];
  el?.focus();
  el?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
}

function moveFocus(value: T): void {
  if (props.activation === 'automatic') select(value);
  nextTick(() => focusTab(value));
}

function neighbour(from: T, dir: 1 | -1): T {
  const list = enabled.value;
  if (list.length === 0) return from;
  const idx = list.findIndex((t) => t.value === from);
  const start = idx === -1 ? 0 : idx;
  const next = (start + dir + list.length) % list.length;
  return list[next].value;
}

function onKeydown(event: KeyboardEvent): void {
  const current = active.value;
  if (current == null) return;
  switch (event.key) {
    case 'ArrowRight':
    case 'ArrowDown':
      event.preventDefault();
      moveFocus(neighbour(current, 1));
      break;
    case 'ArrowLeft':
    case 'ArrowUp':
      event.preventDefault();
      moveFocus(neighbour(current, -1));
      break;
    case 'Home':
      event.preventDefault();
      if (firstEnabled.value != null) moveFocus(firstEnabled.value);
      break;
    case 'End': {
      event.preventDefault();
      const last = enabled.value[enabled.value.length - 1];
      if (last) moveFocus(last.value);
      break;
    }
    case 'Enter':
    case ' ':
      // In manual mode, Enter/Space commits the focused tab.
      if (props.activation === 'manual') {
        event.preventDefault();
        select(current);
      }
      break;
  }
}

// --- Overflow edge fades ----------------------------------------------------
const atStart = ref(true);
const atEnd = ref(true);

function updateEdges(): void {
  const el = listRef.value;
  if (!el) return;
  const max = el.scrollWidth - el.clientWidth;
  atStart.value = el.scrollLeft <= 1;
  atEnd.value = el.scrollLeft >= max - 1;
}

let ro: ResizeObserver | undefined;
onMounted(() => {
  updateEdges();
  if (typeof ResizeObserver !== 'undefined' && listRef.value) {
    ro = new ResizeObserver(updateEdges);
    ro.observe(listRef.value);
  }
});
onBeforeUnmount(() => ro?.disconnect());
watch(() => props.items, () => nextTick(updateEdges), { deep: true });

// --- Styling ----------------------------------------------------------------
const SIZE_TAB: Record<TabsSize, string> = {
  sm: 'h-8 px-next-2_5 text-next-xs gap-next-1_5',
  md: 'h-10 px-next-3 text-next-sm gap-next-2',
};

function tabClass(item: TabItem<T>): string[] {
  const isActive = item.value === active.value;
  const base = [
    'next-tab relative inline-flex shrink-0 items-center whitespace-nowrap font-next-medium',
    'transition-colors duration-[var(--duration-next-fast)] ease-[var(--ease-next-standard)]',
    'outline-none focus-visible:ring-2 focus-visible:ring-next-ring focus-visible:ring-offset-1 focus-visible:ring-offset-next-bg',
    SIZE_TAB[props.size],
  ];
  if (item.disabled) {
    base.push('cursor-not-allowed text-next-muted-foreground/50');
    return base;
  }
  base.push('cursor-pointer');
  if (props.variant === 'pills') {
    base.push('rounded-next-md');
    base.push(
      isActive
        ? 'bg-next-primary text-next-primary-foreground shadow-next-xs'
        : 'text-next-muted-foreground hover:text-next-primary',
    );
  } else {
    // underline
    base.push('rounded-next-sm');
    base.push(
      isActive
        ? 'text-next-primary'
        : 'text-next-muted-foreground hover:text-next-primary',
    );
  }
  return base;
}

const listClass = computed(() => {
  if (props.variant === 'pills') {
    return 'inline-flex items-center gap-next-1 rounded-next-lg border border-next-border bg-next-muted p-next-1';
  }
  // underline: a row sitting on a baseline border
  return 'inline-flex items-center gap-next-1 border-b border-next-border';
});

function badgeVariant(item: TabItem<T>): 'primary' | 'neutral' {
  return item.value === active.value ? 'primary' : 'neutral';
}
</script>

<template>
  <div
    class="next-tabs flex w-full min-w-0 flex-col gap-next-4"
    :class="fill ? 'min-h-0 flex-1' : ''"
  >
    <!-- Scroll viewport with edge fades. The fades are decorative gradients that
         only show when there is hidden content on that side. -->
    <div class="relative min-w-0">
      <div
        ref="listRef"
        role="tablist"
        :aria-label="ariaLabel"
        aria-orientation="horizontal"
        :class="['next-tabs__list min-w-0 max-w-full overflow-x-auto scrollbar-none', listClass]"
        @keydown="onKeydown"
        @scroll="updateEdges"
      >
        <button
          v-for="item in items"
          :key="item.value"
          :ref="(el) => setTabRef(el as HTMLButtonElement | null, item.value)"
          type="button"
          role="tab"
          :id="tabId(item.value)"
          :aria-controls="hasPanels ? panelId(item.value) : undefined"
          :aria-selected="item.value === active"
          :aria-disabled="item.disabled || undefined"
          :tabindex="item.value === active ? 0 : -1"
          :disabled="item.disabled"
          :class="tabClass(item)"
          @click="select(item.value)"
        >
          <Icon v-if="item.icon" :name="item.icon" class="shrink-0" />
          <span>{{ item.label }}</span>
          <Badge
            v-if="item.badge !== undefined"
            :variant="badgeVariant(item)"
            tone="subtle"
            size="sm"
          >
            {{ item.badge }}
          </Badge>

          <!-- Underline indicator: a thick rule on the bottom edge of the active
               tab, overlapping the list's baseline border. -->
          <span
            v-if="variant === 'underline' && item.value === active"
            class="next-tabs__underline pointer-events-none absolute inset-x-0 -bottom-px h-0.5 rounded-next-full bg-next-primary"
            aria-hidden="true"
          />
        </button>
      </div>

      <!-- Edge fades (underline variant only; pills sit in a contained track). -->
      <template v-if="variant === 'underline'">
        <span
          v-show="!atStart"
          class="next-tabs__fade pointer-events-none absolute inset-y-0 left-0 w-8 bg-gradient-to-r from-next-bg to-transparent"
          aria-hidden="true"
        />
        <span
          v-show="!atEnd"
          class="next-tabs__fade pointer-events-none absolute inset-y-0 right-0 w-8 bg-gradient-to-l from-next-bg to-transparent"
          aria-hidden="true"
        />
      </template>
    </div>

    <!-- Panels. Only the active panel is shown; lazy mode keeps unmounted panels
         out of the DOM until first activated. Skipped entirely in nav-only use
         (no panel slots) — see the docblock. -->
    <template v-for="item in items" :key="`panel-${item.value}`">
      <div
        v-if="hasPanels && (!lazy || mounted.has(item.value))"
        v-show="item.value === active"
        role="tabpanel"
        :id="panelId(item.value)"
        :aria-labelledby="tabId(item.value)"
        :tabindex="item.value === active ? 0 : -1"
        :hidden="item.value !== active"
        class="next-tabs__panel min-w-0 outline-none focus-visible:ring-2 focus-visible:ring-next-ring focus-visible:rounded-next-md"
        :class="fill && item.value === active ? 'flex min-h-0 flex-1 flex-col' : ''"
      >
        <slot :name="`panel-${item.value}`" :value="item.value">
          <slot name="panel" :value="item.value" />
        </slot>
      </div>
    </template>
  </div>
</template>

<style scoped>
/* Hide the horizontal scrollbar on the tab list while keeping it scrollable
   (edge fades signal overflow instead). */
.scrollbar-none {
  scrollbar-width: none;
  -ms-overflow-style: none;
}
.scrollbar-none::-webkit-scrollbar {
  display: none;
}
</style>
