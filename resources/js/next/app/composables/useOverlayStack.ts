// useOverlayStack — a single shared registry of open overlays for the isolated
// "next" frontend.
//
// Every dismissible overlay (Popover, DropdownMenu, Modal, ConfirmDialog) that
// can be closed by Escape or by clicking the scrim registers itself here while
// open. The stack guarantees that:
//
//   - Only the TOPMOST overlay reacts to a global Escape press (so closing a
//     menu opened inside a modal closes the menu, not the modal).
//   - Scrim / outside-click dismissal is likewise scoped to the topmost layer.
//   - Layering is consistent: each overlay can ask for its position so it can
//     bump its z-index above an already-open modal when needed (stacked modals).
//
// This is built fresh — it does NOT import or wrap the legacy `useOverlayStack`.
//
// Design: a module-level reactive stack (shared across the whole app) plus a
// single global `keydown` listener installed lazily on the first registration
// and removed when the stack empties. Each entry exposes a `close` callback the
// stack invokes on Escape, and a `dismissable` flag (Escape ignored when false,
// e.g. a modal mid-submit). Components call `register()` on open and the
// returned `release()` on close/unmount.
import { computed, reactive, type ComputedRef } from 'vue';

export type OverlayKind =
  | 'popover'
  | 'dropdown'
  | 'modal'
  | 'confirm'
  | 'tooltip';

export interface OverlayEntry {
  /** Stable id for this open overlay. */
  id: number;
  kind: OverlayKind;
  /** Called when Escape dismisses this (topmost) overlay. */
  close: () => void;
  /** When false, Escape is ignored for this entry (e.g. modal mid-submit). */
  dismissable: () => boolean;
}

export interface OverlayHandle {
  /** This overlay's id. */
  id: number;
  /** Reactive: true while this entry is the topmost in the stack. */
  isTop: ComputedRef<boolean>;
  /** Reactive: 0-based depth of this entry (used to offset z-index). */
  depth: ComputedRef<number>;
  /** Remove this overlay from the stack. Safe to call multiple times. */
  release: () => void;
}

// Module-level singleton stack — shared by every overlay in the next app.
const stack = reactive<OverlayEntry[]>([]);
let nextId = 1;
let listening = false;

function topEntry(): OverlayEntry | undefined {
  return stack[stack.length - 1];
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key !== 'Escape') return;
  const top = topEntry();
  if (!top) return;
  if (!top.dismissable()) return;
  // Stop the event from also reaching native handlers / parents so only the
  // topmost overlay closes per Escape press.
  event.stopPropagation();
  event.preventDefault();
  top.close();
}

function startListening(): void {
  if (listening) return;
  // Capture phase so we run before component-local handlers and can scope the
  // dismissal to exactly the topmost overlay.
  document.addEventListener('keydown', onKeydown, true);
  listening = true;
}

function stopListening(): void {
  if (!listening) return;
  document.removeEventListener('keydown', onKeydown, true);
  listening = false;
}

/**
 * Register an open overlay. Returns a handle with reactive `isTop` / `depth`
 * and a `release()` to remove it (call on close or unmount).
 */
export function useOverlayStack(options: {
  kind: OverlayKind;
  /** Invoked when Escape closes this overlay while it is topmost. */
  close: () => void;
  /** Reactive predicate; Escape is ignored when it returns false. */
  dismissable?: () => boolean;
}): OverlayHandle {
  const id = nextId++;
  const entry: OverlayEntry = {
    id,
    kind: options.kind,
    close: options.close,
    dismissable: options.dismissable ?? (() => true),
  };

  stack.push(entry);
  startListening();

  const indexOf = (): number => stack.findIndex((e) => e.id === id);

  const isTop = computed(() => topEntry()?.id === id);
  const depth = computed(() => {
    const i = indexOf();
    return i < 0 ? 0 : i;
  });

  function release(): void {
    const i = indexOf();
    if (i >= 0) stack.splice(i, 1);
    if (stack.length === 0) stopListening();
  }

  return { id, isTop, depth, release };
}

/** Read-only snapshot of the current stack depth (handy in tests/debug). */
export function overlayStackSize(): number {
  return stack.length;
}
