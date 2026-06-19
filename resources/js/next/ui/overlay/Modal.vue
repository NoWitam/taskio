<script lang="ts">
export default { inheritAttrs: false };
</script>

<script setup lang="ts">
// Modal (Dialog) — a Teleported, focus-trapped dialog for the "next" frontend.
//
// Renders a scrim (`--color-next-overlay`, `--z-next-overlay`) and a centered
// panel (`--z-next-modal`). Focus is trapped with `useFocusTrap` (reused from
// the next composables) and returns to the previously focused element on close.
// Escape and scrim-click close it (both configurable) but only when it is the
// TOPMOST overlay — via the shared overlay stack — so a modal opened from a
// modal stacks and dismisses in order. Body scroll is locked while ANY modal is
// open (reference-counted).
//
// A11y: `role="dialog"`, `aria-modal="true"`, labelled by the title id and
// described by the description id when those slots are present. Enter/leave
// transitions use the motion tokens; reduced motion is handled globally.
import {
  computed,
  nextTick,
  onBeforeUnmount,
  ref,
  shallowRef,
  watch,
} from 'vue';
import { useFocusTrap } from '../../app/composables/useFocusTrap';
import { useOverlayStack, type OverlayHandle } from '../../app/composables/useOverlayStack';
import { useTheme } from '../../app/lib/theme';
import { useI18n } from '../../app/i18n';
import Icon from '../primitives/Icon.vue';

type ModalSize = 'sm' | 'md' | 'lg' | 'xl' | 'full';

const props = withDefaults(
  defineProps<{
    size?: ModalSize;
    /** Close when Escape is pressed (topmost only). */
    closeOnEsc?: boolean;
    /** Close when the scrim is clicked (topmost only). */
    closeOnScrim?: boolean;
    /** Show the built-in close (✕) button in the header. */
    showClose?: boolean;
    /** Accessible label when no visible #title slot is provided. */
    ariaLabel?: string;
  }>(),
  {
    size: 'md',
    closeOnEsc: true,
    closeOnScrim: true,
    showClose: true,
  },
);

const emit = defineEmits<{
  (e: 'open'): void;
  (e: 'close'): void;
}>();

const { t } = useI18n();

const open = defineModel<boolean>('open', { default: false });

const { isDark } = useTheme();

let modalSeq = 0;
const uid = `next-modal-${(modalSeq += 1)}-${Math.random().toString(36).slice(2, 6)}`;
const titleId = `${uid}-title`;
const descId = `${uid}-desc`;

const panelRef = ref<HTMLElement | null>(null);
const hasTitle = ref(false);
const hasDesc = ref(false);

const overlay = shallowRef<OverlayHandle | null>(null);
const isTop = computed(() => overlay.value?.isTop.value ?? true);
const depth = computed(() => overlay.value?.depth.value ?? 0);

// Focus trap reuse: active while open.
const trapActive = computed(() => open.value);
useFocusTrap(panelRef, trapActive);

const SIZE_CLASS: Record<ModalSize, string> = {
  sm: 'max-w-sm',
  md: 'max-w-md',
  lg: 'max-w-lg',
  xl: 'max-w-2xl',
  full: 'max-w-[calc(100vw-2rem)] h-[calc(100vh-2rem)]',
};

// --- Body scroll lock (reference-counted across stacked modals) -----------
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

watch(open, (isOpen) => {
  if (isOpen) {
    overlay.value = useOverlayStack({
      kind: 'modal',
      close: () => {
        if (props.closeOnEsc) open.value = false;
      },
      dismissable: () => props.closeOnEsc,
    });
    lockBody();
    nextTick(() => {
      hasTitle.value = !!panelRef.value?.querySelector('[data-modal-title]');
      hasDesc.value = !!panelRef.value?.querySelector('[data-modal-desc]');
    });
    emit('open');
  } else {
    overlay.value?.release();
    overlay.value = null;
    unlockBody();
    emit('close');
  }
});

function requestClose(): void {
  open.value = false;
}

function onScrimClick(): void {
  if (props.closeOnScrim && isTop.value) requestClose();
}

const labelledBy = computed(() => (hasTitle.value ? titleId : undefined));
const describedBy = computed(() => (hasDesc.value ? descId : undefined));

onBeforeUnmount(() => {
  if (open.value) {
    overlay.value?.release();
    unlockBody();
  }
});
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

      <!-- Panel container (centers the dialog, scrolls if tall) -->
      <div class="absolute inset-0 flex items-center justify-center overflow-y-auto p-next-4">
        <Transition
          appear
          enter-active-class="transition duration-[var(--duration-next-slow)] ease-[var(--ease-next-emphasized)]"
          enter-from-class="opacity-0 translate-y-2 scale-[0.98]"
          leave-active-class="transition duration-[var(--duration-next-fast)] ease-[var(--ease-next-exit)]"
          leave-to-class="opacity-0 translate-y-2 scale-[0.98]"
        >
          <div
            v-if="open"
            ref="panelRef"
            role="dialog"
            aria-modal="true"
            :aria-labelledby="labelledBy"
            :aria-describedby="describedBy"
            :aria-label="!hasTitle ? ariaLabel : undefined"
            tabindex="-1"
            class="next-modal relative z-[var(--z-next-modal)] flex w-full flex-col rounded-next-xl border border-next-border bg-next-card text-next-card-foreground shadow-next-xl outline-none"
            :class="[SIZE_CLASS[size], $attrs.class]"
          >
            <!-- Header -->
            <header
              v-if="$slots.title || showClose"
              class="flex items-start justify-between gap-next-3 border-b border-next-border p-next-4"
            >
              <div v-if="$slots.title" :id="titleId" data-modal-title class="min-w-0 flex-1 text-next-lg font-next-semibold">
                <slot name="title" />
              </div>
              <div v-else class="flex-1" />
              <button
                v-if="showClose"
                type="button"
                class="-mr-next-1 -mt-next-1 shrink-0 rounded-next-md p-next-1 text-next-muted-foreground transition-colors duration-[var(--duration-next-fast)] hover:bg-next-accent hover:text-next-accent-foreground"
                :aria-label="t('modal.close', 'Close dialog')"
                @click="requestClose"
              >
                <Icon name="x" class="text-next-lg" />
              </button>
            </header>

            <!-- Body -->
            <div class="min-w-0 flex-1 overflow-y-auto p-next-4">
              <div v-if="$slots.description" :id="descId" data-modal-desc class="mb-next-3 text-next-sm text-next-muted-foreground">
                <slot name="description" />
              </div>
              <slot />
            </div>

            <!-- Footer -->
            <footer
              v-if="$slots.footer"
              class="flex items-center justify-end gap-next-2 border-t border-next-border p-next-4"
            >
              <slot name="footer" :close="requestClose" />
            </footer>
          </div>
        </Transition>
      </div>
    </div>
  </Teleport>
</template>
