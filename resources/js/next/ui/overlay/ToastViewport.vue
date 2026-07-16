<script lang="ts">
export default { inheritAttrs: false };
</script>

<script setup lang="ts">
// ToastViewport — the single, teleported live region that renders every toast
// pushed via `useToast()` (next frontend).
//
// Mount it ONCE near the app root (App.vue / the gallery shell). It owns the
// auto-dismiss timing (pause-on-hover / pause-on-focus) that individual Toast
// cards surface via `pause`/`resume`, caps how many toasts are visible at once
// (`maxVisible`, extras stay queued in the store until a slot frees up), and
// positions the stack in one of the four corners (or top/bottom center).
//
// A11y: each Toast card is its OWN live region (polite for info/success/warning,
// assertive for danger) so it is announced correctly on insertion without the
// container re-announcing the whole stack; cards carry meaning via icon + text
// (never color alone). The container is `pointer-events-none` so it never blocks
// the page; each card re-enables pointer events for its controls.
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import Toast from './Toast.vue';
import { useTheme } from '../../app/lib/theme';
import {
  useToastStore,
  dismissToast,
  type ToastRecord,
} from '../../app/composables/useToast';

type ToastPosition =
  | 'top-left'
  | 'top-center'
  | 'top-right'
  | 'bottom-left'
  | 'bottom-center'
  | 'bottom-right';

const props = withDefaults(
  defineProps<{
    /** Corner / edge the stack is anchored to. */
    position?: ToastPosition;
    /** How many toasts render at once; the rest stay queued in the store. */
    maxVisible?: number;
  }>(),
  {
    position: 'top-right',
    maxVisible: 4,
  },
);

const { isDark } = useTheme();
const toasts = useToastStore();

// Newest-first when anchored to the top so toasts visually "drop in" at the edge.
const isTop = computed(() => props.position.startsWith('top'));
const visible = computed<ToastRecord[]>(() => {
  const list = isTop.value ? [...toasts.value].reverse() : toasts.value;
  return list.slice(0, props.maxVisible);
});

// --- Auto-dismiss timers (owned here, paused on hover/focus) ----------------
interface Timer {
  handle: ReturnType<typeof setTimeout>;
  startedAt: number;
  remaining: number;
}
const timers = new Map<number, Timer>();

function startTimer(toast: ToastRecord): void {
  if (toast.duration == null || toast.duration <= 0) return; // sticky
  if (timers.has(toast.id)) return;
  scheduleTimer(toast.id, toast.remaining > 0 ? toast.remaining : toast.duration);
}

function scheduleTimer(id: number, remaining: number): void {
  const handle = setTimeout(() => {
    timers.delete(id);
    dismissToast(id);
  }, remaining);
  timers.set(id, { handle, startedAt: Date.now(), remaining });
}

function pause(id: number): void {
  const timer = timers.get(id);
  if (!timer) return;
  clearTimeout(timer.handle);
  const elapsed = Date.now() - timer.startedAt;
  const record = toasts.value.find((t) => t.id === id);
  if (record) record.remaining = Math.max(0, timer.remaining - elapsed);
  timers.delete(id);
}

function resume(id: number): void {
  const record = toasts.value.find((t) => t.id === id);
  if (record) startTimer(record);
}

function clearTimer(id: number): void {
  const timer = timers.get(id);
  if (timer) clearTimeout(timer.handle);
  timers.delete(id);
}

// Keep timers in sync with the visible set: start timers for newly-visible
// toasts, drop timers for ones that left the viewport (dismissed or queued out).
watch(
  visible,
  (next) => {
    const ids = new Set(next.map((t) => t.id));
    for (const id of [...timers.keys()]) {
      if (!ids.has(id)) clearTimer(id);
    }
    for (const toast of next) startTimer(toast);
  },
  { immediate: true, deep: true },
);

function onAction(toast: ToastRecord): void {
  toast.action?.onClick();
  dismissToast(toast.id);
}

onBeforeUnmount(() => {
  for (const timer of timers.values()) clearTimeout(timer.handle);
  timers.clear();
});

const POSITION_CLASS: Record<ToastPosition, string> = {
  'top-left': 'top-next-0 left-next-0 items-start',
  'top-center': 'top-next-0 left-1/2 -translate-x-1/2 items-center',
  'top-right': 'top-next-0 right-next-0 items-end',
  'bottom-left': 'bottom-next-0 left-next-0 items-start',
  'bottom-center': 'bottom-next-0 left-1/2 -translate-x-1/2 items-center',
  'bottom-right': 'bottom-next-0 right-next-0 items-end',
};
</script>

<template>
  <Teleport to="body">
    <div
      class="next-root next-overlay-root pointer-events-none fixed flex w-full max-w-sm flex-col gap-next-3 p-next-4 z-[var(--z-next-toast)]"
      :class="[POSITION_CLASS[position], isTop ? 'flex-col' : 'flex-col-reverse', isDark ? 'dark' : '', $attrs.class]"
    >
      <TransitionGroup
        :enter-from-class="isTop ? 'opacity-0 -translate-y-2 scale-[0.98]' : 'opacity-0 translate-y-2 scale-[0.98]'"
        enter-active-class="transition duration-[var(--duration-next-normal)] ease-[var(--ease-next-emphasized)]"
        leave-active-class="transition duration-[var(--duration-next-fast)] ease-[var(--ease-next-exit)] absolute"
        leave-to-class="opacity-0 scale-[0.98]"
        move-class="transition-transform duration-[var(--duration-next-normal)] ease-[var(--ease-next-standard)]"
      >
        <div v-for="toast in visible" :key="toast.id" class="w-full">
          <Toast
            :variant="toast.variant"
            :title="toast.title"
            :description="toast.description"
            :action="toast.action"
            @dismiss="dismissToast(toast.id)"
            @action="onAction(toast)"
            @pause="pause(toast.id)"
            @resume="resume(toast.id)"
          />
        </div>
      </TransitionGroup>
    </div>
  </Teleport>
</template>
