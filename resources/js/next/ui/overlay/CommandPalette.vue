<script lang="ts">
export default { inheritAttrs: false };
</script>

<script setup lang="ts">
// CommandPalette — a ⌘K / Ctrl+K command launcher for the "next" frontend.
//
// A teleported, centered overlay that REUSES the Modal mechanics: a scrim
// (`--color-next-overlay`, `--z-next-overlay`), the shared overlay stack (only
// the topmost layer closes on Escape), a focus trap, body-scroll lock, and
// stacking z-index. The panel contains an autofocused search TextInput and a
// scrollable, GROUPED command list.
//
// Two data modes:
//   • static `commands` — filtered locally (substring over label + keywords).
//   • async `fetchCommands(query)` — debounced; option-row skeletons show while
//     loading (per the Skeleton usage rule), never a spinner.
//
// Keyboard: ↑/↓ move the active command (skipping disabled, wrapping), Enter runs
// it and closes, Esc closes, typing filters. A11y: `role="dialog"` (+ aria-label);
// the input is `role="combobox"` with `aria-controls`/`aria-activedescendant`; the
// list is `role="listbox"` of `role="option"`; each command's shortcut renders
// via <Kbd>. Empty state ("No results") + an optional empty-query "recent" view.
import { computed, nextTick, onBeforeUnmount, ref, shallowRef, watch } from 'vue';
import { useFocusTrap } from '../../app/composables/useFocusTrap';
import { useOverlayStack, type OverlayHandle } from '../../app/composables/useOverlayStack';
import { useDebounce } from '../../app/composables/useDebounce';
import { useTheme } from '../../app/lib/theme';
import { useI18n } from '../../app/i18n';
import Icon, { type IconName } from '../primitives/Icon.vue';
import Kbd from '../primitives/Kbd.vue';
import Skeleton from '../data/Skeleton.vue';
import EmptyState from '../data/EmptyState.vue';
import type { Command } from './commandPalette';

const props = withDefaults(
  defineProps<{
    /** Static command list (filtered locally). Ignored when `fetchCommands` is set. */
    commands?: Command[];
    /** Async provider; called (debounced) with the query. Enables async mode. */
    fetchCommands?: (query: string) => Promise<Command[]>;
    /** Search input placeholder. */
    placeholder?: string;
    /** Accessible label for the dialog. */
    ariaLabel?: string;
    /** Debounce (ms) for async fetching. */
    debounce?: number;
  }>(),
  { debounce: 200 },
);

const emit = defineEmits<{
  (e: 'select', command: Command): void;
  (e: 'open'): void;
  (e: 'close'): void;
}>();

const { t } = useI18n();
const { isDark } = useTheme();

const open = defineModel<boolean>('open', { default: false });

let seq = 0;
const uid = `next-cmdk-${(seq += 1)}-${Math.random().toString(36).slice(2, 6)}`;
const listId = `${uid}-list`;
const inputId = `${uid}-input`;
const optionId = (id: string) => `${uid}-opt-${id}`;

const panelRef = ref<HTMLElement | null>(null);
const inputRef = ref<HTMLInputElement | null>(null);
const query = ref('');

// --- Overlay mechanics (mirrors Modal) --------------------------------------
const overlay = shallowRef<OverlayHandle | null>(null);
const isTop = computed(() => overlay.value?.isTop.value ?? true);
const depth = computed(() => overlay.value?.depth.value ?? 0);

useFocusTrap(panelRef, computed(() => open.value));

function lockBody(): void {
  const count = Number(document.body.dataset.nextModalLocks ?? '0') + 1;
  document.body.dataset.nextModalLocks = String(count);
  if (count === 1) {
    document.body.dataset.nextPrevOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
  }
}
function unlockBody(): void {
  const count = Math.max(0, Number(document.body.dataset.nextModalLocks ?? '1') - 1);
  document.body.dataset.nextModalLocks = String(count);
  if (count === 0) {
    document.body.style.overflow = document.body.dataset.nextPrevOverflow ?? '';
    delete document.body.dataset.nextPrevOverflow;
    delete document.body.dataset.nextModalLocks;
  }
}

// --- Async fetching ---------------------------------------------------------
const isAsync = computed(() => typeof props.fetchCommands === 'function');
const asyncResults = shallowRef<Command[]>([]);
const loading = ref(false);
let fetchToken = 0;

async function runFetch(q: string): Promise<void> {
  if (!props.fetchCommands) return;
  const token = ++fetchToken;
  loading.value = true;
  try {
    const res = await props.fetchCommands(q);
    if (token === fetchToken) asyncResults.value = res;
  } finally {
    if (token === fetchToken) loading.value = false;
  }
}
const debouncedFetch = useDebounce((q: string) => void runFetch(q), props.debounce);

watch(query, (q) => {
  if (isAsync.value) debouncedFetch(q);
});

// --- Resolved + filtered + grouped list -------------------------------------
const source = computed<Command[]>(() =>
  isAsync.value ? asyncResults.value : props.commands ?? [],
);

const filtered = computed<Command[]>(() => {
  // Async mode: the provider already filtered; show as-is.
  if (isAsync.value) return source.value;
  const q = query.value.trim().toLowerCase();
  if (!q) return source.value;
  return source.value.filter((c) => {
    if (c.label.toLowerCase().includes(q)) return true;
    return (c.keywords ?? []).some((k) => k.toLowerCase().includes(q));
  });
});

interface CommandGroup {
  name: string | null;
  commands: Command[];
}

const groups = computed<CommandGroup[]>(() => {
  const map = new Map<string | null, Command[]>();
  for (const c of filtered.value) {
    const key = c.group ?? null;
    if (!map.has(key)) map.set(key, []);
    map.get(key)!.push(c);
  }
  return [...map.entries()].map(([name, commands]) => ({ name, commands }));
});

// Flat order (for active-index keyboard movement), enabled-only navigation.
const flat = computed<Command[]>(() => groups.value.flatMap((g) => g.commands));
const enabledFlat = computed<Command[]>(() => flat.value.filter((c) => !c.disabled));

const activeId = ref<string | null>(null);

// Keep a valid active command: default to the first enabled result; reset when
// results change so the highlight never points at a vanished row.
watch(
  [enabledFlat, open],
  () => {
    if (!open.value) return;
    if (activeId.value && enabledFlat.value.some((c) => c.id === activeId.value)) return;
    activeId.value = enabledFlat.value[0]?.id ?? null;
  },
  { immediate: true },
);

function activeIndex(): number {
  return enabledFlat.value.findIndex((c) => c.id === activeId.value);
}

function moveActive(delta: 1 | -1): void {
  const list = enabledFlat.value;
  if (list.length === 0) return;
  let i = activeIndex();
  if (i === -1) i = delta === 1 ? -1 : 0;
  i = (i + delta + list.length) % list.length;
  activeId.value = list[i].id;
  scrollActiveIntoView();
}

function scrollActiveIntoView(): void {
  nextTick(() => {
    if (!activeId.value || !panelRef.value) return;
    const el = panelRef.value.querySelector(`#${CSS.escape(optionId(activeId.value))}`);
    (el as HTMLElement | null)?.scrollIntoView({ block: 'nearest' });
  });
}

function run(command: Command): void {
  if (command.disabled) return;
  emit('select', command);
  command.perform();
  open.value = false;
}

function onInputKeydown(event: KeyboardEvent): void {
  switch (event.key) {
    case 'ArrowDown':
      event.preventDefault();
      moveActive(1);
      break;
    case 'ArrowUp':
      event.preventDefault();
      moveActive(-1);
      break;
    case 'Enter': {
      event.preventDefault();
      const cmd = enabledFlat.value.find((c) => c.id === activeId.value);
      if (cmd) run(cmd);
      break;
    }
    // Escape is handled by the overlay stack (topmost-only).
  }
}

function requestClose(): void {
  open.value = false;
}

function onScrimClick(): void {
  if (isTop.value) requestClose();
}

// --- Open / close lifecycle -------------------------------------------------
watch(open, (isOpen) => {
  if (isOpen) {
    overlay.value = useOverlayStack({
      kind: 'modal',
      close: () => requestClose(),
      dismissable: () => true,
    });
    lockBody();
    query.value = '';
    if (isAsync.value) void runFetch('');
    nextTick(() => inputRef.value?.focus({ preventScroll: true }));
    emit('open');
  } else {
    overlay.value?.release();
    overlay.value = null;
    unlockBody();
    emit('close');
  }
});

onBeforeUnmount(() => {
  if (open.value) {
    overlay.value?.release();
    unlockBody();
  }
});

const showEmpty = computed(
  () => !loading.value && filtered.value.length === 0,
);
const dialogLabel = computed(
  () => props.ariaLabel ?? t('commandPalette.label', 'Command palette'),
);
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="next-root next-overlay-root fixed inset-0"
      :class="isDark ? 'dark' : ''"
      :style="{ zIndex: `calc(var(--z-next-overlay) + ${depth * 10})` }"
    >
      <!-- Scrim -->
      <Transition
        appear
        enter-active-class="transition-opacity duration-[var(--duration-next-normal)] ease-[var(--ease-next-standard)]"
        enter-from-class="opacity-0"
        leave-active-class="transition-opacity duration-[var(--duration-next-fast)] ease-[var(--ease-next-exit)]"
        leave-to-class="opacity-0"
      >
        <div
          class="absolute inset-0 bg-[var(--color-next-overlay)]"
          aria-hidden="true"
          @click="onScrimClick"
        />
      </Transition>

      <!-- Panel container: top-aligned, like a launcher. -->
      <div class="absolute inset-0 flex items-start justify-center overflow-y-auto p-next-4 pt-[12vh]">
        <Transition
          appear
          enter-active-class="transition duration-[var(--duration-next-slow)] ease-[var(--ease-next-emphasized)]"
          enter-from-class="opacity-0 -translate-y-2 scale-[0.98]"
          leave-active-class="transition duration-[var(--duration-next-fast)] ease-[var(--ease-next-exit)]"
          leave-to-class="opacity-0 -translate-y-2 scale-[0.98]"
        >
          <div
            v-if="open"
            ref="panelRef"
            role="dialog"
            aria-modal="true"
            :aria-label="dialogLabel"
            class="next-cmdk relative z-[var(--z-next-modal)] flex w-full max-w-xl flex-col overflow-hidden rounded-next-xl border border-next-border bg-next-popover text-next-popover-foreground shadow-next-xl outline-none"
            :class="$attrs.class"
          >
            <!-- Search row -->
            <div class="flex items-center gap-next-2 border-b border-next-border px-next-4">
              <Icon name="search" class="shrink-0 text-next-lg text-next-muted-foreground" />
              <input
                :id="inputId"
                ref="inputRef"
                v-model="query"
                type="text"
                role="combobox"
                aria-autocomplete="list"
                :aria-controls="listId"
                :aria-expanded="true"
                :aria-activedescendant="activeId ? optionId(activeId) : undefined"
                :aria-label="t('commandPalette.searchLabel', 'Search commands')"
                :placeholder="placeholder ?? t('commandPalette.placeholder', 'Type a command or search…')"
                class="h-12 w-full min-w-0 flex-1 border-0 bg-transparent text-next-base text-current outline-none placeholder:text-next-muted-foreground"
                @keydown="onInputKeydown"
              />
              <Kbd :keys="['esc']" class="shrink-0" />
            </div>

            <!-- Results -->
            <div class="max-h-[min(24rem,60vh)] overflow-y-auto p-next-2">
              <!-- Loading (async): option-row skeletons, not a spinner. -->
              <div
                v-if="loading"
                class="flex flex-col gap-next-1"
                role="status"
                :aria-label="t('commandPalette.loading', 'Loading commands…')"
              >
                <div
                  v-for="n in 5"
                  :key="n"
                  class="flex items-center gap-next-3 rounded-next-md px-next-2 py-next-2"
                >
                  <Skeleton variant="circle" diameter="1.25rem" />
                  <Skeleton variant="text" :width="`${40 + ((n * 13) % 40)}%`" />
                </div>
              </div>

              <!-- Empty -->
              <EmptyState
                v-else-if="showEmpty"
                size="sm"
                variant="search"
                :title="t('commandPalette.empty', 'No results')"
              />

              <!-- Grouped listbox -->
              <ul
                v-else
                :id="listId"
                role="listbox"
                :aria-label="dialogLabel"
                class="flex flex-col gap-next-1"
              >
                <template v-for="group in groups" :key="group.name ?? '__none'">
                  <li
                    v-if="group.name"
                    role="presentation"
                    class="px-next-2 pb-next-1 pt-next-2 text-next-2xs font-next-semibold uppercase tracking-next-wide text-next-muted-foreground"
                  >
                    {{ group.name }}
                  </li>
                  <li
                    v-for="command in group.commands"
                    :id="optionId(command.id)"
                    :key="command.id"
                    role="option"
                    :aria-selected="command.id === activeId"
                    :aria-disabled="command.disabled ? 'true' : undefined"
                    class="flex cursor-pointer items-center gap-next-3 rounded-next-md px-next-2 py-next-2 text-next-sm outline-none"
                    :class="[
                      command.disabled
                        ? 'cursor-not-allowed text-next-muted-foreground/60'
                        : command.id === activeId
                          ? 'bg-next-accent text-next-accent-foreground'
                          : 'text-next-fg',
                    ]"
                    @click="run(command)"
                    @mousemove="!command.disabled && (activeId = command.id)"
                  >
                    <Icon
                      v-if="command.icon"
                      :name="(command.icon as IconName)"
                      class="shrink-0 text-next-muted-foreground"
                      :class="command.id === activeId ? 'text-current' : ''"
                    />
                    <span class="min-w-0 flex-1 truncate">{{ command.label }}</span>
                    <Kbd
                      v-if="command.shortcut && command.shortcut.length"
                      :keys="command.shortcut"
                      class="shrink-0"
                    />
                  </li>
                </template>
              </ul>
            </div>
          </div>
        </Transition>
      </div>
    </div>
  </Teleport>
</template>
