<script setup lang="ts">
// VariableBrowserPopover — the FIELD-shaped way to open a VariableBrowser.
//
// It is the (previously per-page) picker shell, now shared: a FieldShell trigger so it
// sits inside a value-or-variable box exactly like a Select, plus a TELEPORTED, anchored
// panel holding a search input and the browser itself.
//
//   trigger (combobox)  →  panel: [ search ] [ VariableBrowser (tree | results) ]
//
// The panel is anchored with `useAnchoredPosition` and dismissed with `useOutsideClick`,
// the same pair every other next popover uses, and follows scroll/resize while open.
//
// FOCUS: the search input takes focus on open (typing is the fastest way to a variable in a
// deep catalog) and FORWARDS its key events to the browser, which owns the one keyboard
// model. ←/→ from the browser body hand editing back to the input. So there is exactly one
// tab stop inside the panel plus the browser body, and Esc/Tab always close.
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';
import Icon from '../primitives/Icon.vue';
import FieldShell from '../forms/FieldShell.vue';
import VariableBrowser from './VariableBrowser.vue';
import { useAnchoredPosition } from '../../app/composables/useAnchoredPosition';
import { useOutsideClick } from '../../app/composables/useOutsideClick';
import { useTheme } from '../../app/lib/theme';
import { useI18n } from '../../app/i18n';
import type { VariableNode } from './types';

const props = withDefaults(
  defineProps<{
    /** The tree to browse (built + policy-applied by the surface). */
    nodes: VariableNode[];
    /** The path already chosen by the surface (marks its row `aria-selected`). */
    selectedPath?: string | null;
    disabled?: boolean;
    /** aria-label of the tree + the trigger. */
    label?: string;
    /** Trigger placeholder. */
    placeholder?: string;
  }>(),
  { selectedPath: null, disabled: false },
);

const emit = defineEmits<{ select: [VariableNode] }>();

const { t } = useI18n();
const { isDark } = useTheme();

const open = ref(false);
const query = ref('');

const rootRef = ref<HTMLElement | null>(null);
const panelRef = ref<HTMLElement | null>(null);
const triggerRef = ref<HTMLButtonElement | null>(null);
const inputRef = ref<HTMLInputElement | null>(null);
const browserRef = ref<InstanceType<typeof VariableBrowser> | null>(null);

const baseId = `next-vbrowse-pop-${Math.random().toString(36).slice(2, 8)}`;
const panelId = `${baseId}-panel`;

const triggerLabel = computed(() => props.label ?? t('variableBrowser.trigger'));

// --- Open / close -----------------------------------------------------------
const { style: panelPos, update: updatePos } = useAnchoredPosition(rootRef, panelRef, {
  placement: () => 'bottom-start',
  gap: 4,
  flip: true,
});
function reposition(): void {
  updatePos();
}

function openPanel(): void {
  if (props.disabled || open.value) return;
  open.value = true;
  query.value = '';
  nextTick(() => {
    reposition();
    requestAnimationFrame(reposition);
    inputRef.value?.focus({ preventScroll: true });
  });
  window.addEventListener('scroll', reposition, true);
  window.addEventListener('resize', reposition);
}

function closePanel(returnFocus = true): void {
  if (!open.value) return;
  open.value = false;
  window.removeEventListener('scroll', reposition, true);
  window.removeEventListener('resize', reposition);
  if (returnFocus) nextTick(() => triggerRef.value?.focus({ preventScroll: true }));
}

function toggle(): void {
  open.value ? closePanel() : openPanel();
}

useOutsideClick([panelRef, rootRef], () => closePanel(false), open);
onBeforeUnmount(() => {
  window.removeEventListener('scroll', reposition, true);
  window.removeEventListener('resize', reposition);
});

// --- Wiring the browser -----------------------------------------------------
function onSelect(node: VariableNode): void {
  emit('select', node);
  closePanel();
}

/** The input owns the caret; the browser owns the keyboard model. */
function onInputKeydown(event: KeyboardEvent): void {
  browserRef.value?.handleKey(event, 'query');
}

/** ←/→ while arrowing results from the browser body: put the caret back in the query. */
function focusQuery(): void {
  inputRef.value?.focus({ preventScroll: true });
}
</script>

<template>
  <div ref="rootRef" class="relative w-full">
    <FieldShell :disabled="disabled" :focused="open || undefined">
      <button
        :id="baseId"
        ref="triggerRef"
        type="button"
        role="combobox"
        class="flex h-full w-full min-w-0 flex-1 items-center gap-next-2 pl-next-3 pr-next-3 text-left outline-none disabled:cursor-not-allowed"
        :disabled="disabled"
        aria-haspopup="tree"
        :aria-expanded="open"
        :aria-controls="open ? panelId : undefined"
        :aria-label="triggerLabel"
        @click="toggle"
        @keydown.down.prevent="openPanel"
        @keydown.enter.prevent="openPanel"
      >
        <span class="truncate text-next-muted-foreground">
          {{ placeholder ?? triggerLabel }}
        </span>
      </button>
      <template #trailing>
        <Icon
          name="chevron-down"
          class="shrink-0 text-next-muted-foreground transition-transform duration-[var(--duration-next-fast)]"
          :class="open ? 'rotate-180' : ''"
          aria-hidden="true"
        />
      </template>
    </FieldShell>

    <Teleport to="body">
      <Transition
        enter-active-class="transition-opacity duration-[var(--duration-next-fast)] ease-[var(--ease-next-emphasized)]"
        enter-from-class="opacity-0"
        leave-active-class="transition-opacity duration-[var(--duration-next-fast)] ease-[var(--ease-next-exit)]"
        leave-to-class="opacity-0"
      >
        <div v-if="open" class="next-root next-overlay-root" :class="isDark ? 'dark' : ''">
          <div
            :id="panelId"
            ref="panelRef"
            class="fixed z-[var(--z-next-popover)] flex flex-col overflow-hidden rounded-next-md border border-next-border bg-next-popover text-next-popover-foreground shadow-next-lg"
            :style="{
              top: `${panelPos.top}px`,
              left: `${panelPos.left}px`,
              /* One inline tree: wide enough for a few nesting levels, never wider than
                 the viewport, never narrower than the field it drops out of. */
              width: 'min(26rem, calc(100vw - 2rem))',
              minWidth: `${rootRef?.offsetWidth ?? 0}px`,
            }"
          >
            <!-- Search: typing switches the browser to flat RESULTS; clearing restores
                 the tree exactly where it was. -->
            <div class="flex items-center gap-next-2 border-b border-next-border px-next-3">
              <Icon name="search" class="shrink-0 text-next-muted-foreground" aria-hidden="true" />
              <input
                ref="inputRef"
                v-model="query"
                type="text"
                autocomplete="off"
                class="h-9 w-full min-w-0 flex-1 border-0 bg-transparent text-next-sm text-current outline-none placeholder:text-next-muted-foreground"
                :aria-label="t('variableBrowser.searchLabel')"
                :aria-controls="panelId"
                :aria-activedescendant="browserRef?.activeDescendantId"
                :placeholder="t('variableBrowser.searchPlaceholder')"
                @keydown="onInputKeydown"
              />
            </div>

            <VariableBrowser
              ref="browserRef"
              :nodes="nodes"
              :selected-path="selectedPath"
              :root-label="label ?? t('variableBrowser.label')"
              :query="query"
              @select="onSelect"
              @close="closePanel()"
              @focus-query="focusQuery"
            />
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>
