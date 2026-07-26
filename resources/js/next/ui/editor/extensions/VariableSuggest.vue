<script setup lang="ts">
// VariableSuggest — the caret-anchored `{`-insert popup, rendered by the SHARED
// `VariableBrowser` (B4). It replaces the flat `MentionSuggest variant:'variable'` list, whose
// rows carried no type markers, could not expand a container, and (because every row was
// insertable) made an object global / form section a trap: picking one dropped a directive that
// resolves to a MAP inside the user's text.
//
// The browser fixes all three by construction: glyphs with `?` / `[]` markers, inline expansion of
// containers, and "a non-array object is NEVER selectable" — so a container here can only ever be
// OPENED, never inserted.
//
// ── VIRTUAL FOCUS (the ProseMirror contract this must not break) ────────────────
// The editor keeps DOM focus for the popup's whole life: the caret must stay in the text so the
// user can go on typing the query. So:
//   • the plugin FORWARDS keys into `store.onKey` (registered below) instead of the browser
//     taking focus — the browser's body is never focused here,
//   • the panel swallows `mousedown` (`@mousedown.prevent`), the same trick MentionSuggest uses,
//     so clicking a row cannot blur the editor (the click still fires),
//   • positioning anchors against a SYNTHETIC element whose rect is the store's caret rect, and
//     follows scroll/resize through `store.reposition` (SF3.1), exactly as before.
import { computed, onBeforeUnmount, ref, watch, nextTick } from 'vue';
import { useAnchoredPosition } from '../../../app/composables/useAnchoredPosition';
import { useTheme } from '../../../app/lib/theme';
import { useI18n } from '../../../app/i18n';
import VariableBrowser from '../../variables/VariableBrowser.vue';
import type { VariableNode } from '../../variables/types';
import type { SuggestionStore } from './suggestionStore';

const props = defineProps<{ store: SuggestionStore }>();

const { isDark } = useTheme();
const { t } = useI18n();

const anchorRef = ref<HTMLElement | null>(null);
const panelRef = ref<HTMLElement | null>(null);
const browserRef = ref<InstanceType<typeof VariableBrowser> | null>(null);

const { style: anchorStyle, update: updatePosition } = useAnchoredPosition(anchorRef, panelRef, {
  placement: () => 'bottom-start',
  gap: 6,
  flip: true,
});

// A STABLE synthetic anchor whose rect always reflects the store's CURRENT caret rect (see
// MentionSuggest: keeping it stable is what lets `updatePosition` re-read fresh coordinates).
const syntheticAnchor = {
  getBoundingClientRect: () => props.store.rect ?? new DOMRect(),
} as unknown as HTMLElement;

watch(
  () => props.store.rect,
  (rect) => {
    anchorRef.value = rect ? syntheticAnchor : null;
    if (!rect) return;
    void nextTick(() => {
      updatePosition();
      requestAnimationFrame(updatePosition);
    });
  },
  { immediate: true },
);

/** SF3.1 — ask the PLUGIN to re-measure the caret (it repositions or closes when it scrolled away). */
function onViewportChange(): void {
  if (props.store.reposition) props.store.reposition();
  else updatePosition();
}
function bindViewportListeners(): void {
  window.addEventListener('scroll', onViewportChange, true);
  window.addEventListener('resize', onViewportChange);
}
function unbindViewportListeners(): void {
  window.removeEventListener('scroll', onViewportChange, true);
  window.removeEventListener('resize', onViewportChange);
}
watch(
  () => props.store.active,
  (active) => {
    if (active) bindViewportListeners();
    else unbindViewportListeners();
  },
);
onBeforeUnmount(() => {
  unbindViewportListeners();
  if (props.store.onKey) props.store.onKey = null;
});

// --- The keyboard bridge ----------------------------------------------------
// The plugin owns WHICH keys are forwarded (it alone knows the query + the caret); this only
// hands them to the browser's one keyboard model. `origin: 'query'` is the truthful origin: the
// text being typed after `{` IS the query input, and it holds the caret — so while a query is
// active ←/→ are NOT consumed and stay with the caret. (The plugin does not forward them then
// either; agreeing on both sides means neither can strand the caret.)
watch(
  browserRef,
  (browser) => {
    props.store.onKey = browser ? (event: KeyboardEvent) => browser.handleKey(event, 'query') : null;
  },
  { immediate: true },
);

const nodes = computed<VariableNode[]>(() => props.store.nodes ?? []);

function onSelect(node: VariableNode): void {
  props.store.onSelectNode?.(node);
}
function onClose(): void {
  props.store.onClose?.();
}
</script>

<template>
  <Teleport to="body">
    <div v-if="store.active" class="next-root next-overlay-root" :class="isDark ? 'dark' : ''">
      <!-- `mousedown.prevent` keeps DOM focus (and therefore the caret) in the editor. -->
      <div
        ref="panelRef"
        class="next-suggest-pop fixed z-[var(--z-next-popover)] flex flex-col overflow-hidden rounded-next-lg border border-next-border bg-next-popover text-next-popover-foreground shadow-next-lg"
        :style="{
          top: `${anchorStyle.top}px`,
          left: `${anchorStyle.left}px`,
          width: 'min(26rem, calc(100vw - 2rem))',
        }"
        data-variable-suggest
        @mousedown.prevent
      >
        <VariableBrowser
          ref="browserRef"
          :nodes="nodes"
          :query="store.query"
          :root-label="t('variableBrowser.label')"
          @select="onSelect"
          @close="onClose"
        />
      </div>
    </div>
  </Teleport>
</template>
