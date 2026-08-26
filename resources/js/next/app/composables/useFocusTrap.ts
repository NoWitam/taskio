// useFocusTrap — minimal focus trap for the isolated "next" frontend.
//
// Self-contained (no dependency on the legacy composable). When `active` is
// truthy it: remembers the previously focused element, moves focus into the
// container, keeps Tab/Shift+Tab cycling within the container's focusable
// elements, and restores focus to the trigger when deactivated. Used by the
// AppShell mobile drawer, Modal, Drawer and CommandPalette.
//
// LAYERING — why the traps form a stack (see `trapStack` below): several traps
// can be active at once (a Modal opened over a Drawer), and only the TOPMOST one
// may answer Tab. Lower traps stay ACTIVE and keep their remembered trigger; they
// simply do not react. Deactivating them instead would restore focus to their own
// trigger and yank it out of the top layer — the opposite of the fix.
import { getCurrentScope, onScopeDispose, watch, type Ref } from 'vue';

const FOCUSABLE_SELECTOR = [
  'a[href]',
  'button:not([disabled])',
  'input:not([disabled])',
  'select:not([disabled])',
  'textarea:not([disabled])',
  '[tabindex]:not([tabindex="-1"])',
].join(',');

function getFocusable(container: HTMLElement): HTMLElement[] {
  return Array.from(
    container.querySelectorAll<HTMLElement>(FOCUSABLE_SELECTOR),
  ).filter((el) => el.offsetParent !== null || el === document.activeElement);
}

// --- Trap stack --------------------------------------------------------------
// One shared document listener dispatching to the topmost trap, instead of one
// listener per trap. Each trap used to register its own capture-phase handler and
// never checked whether it was on top: with a dialog open over a drawer, the
// DRAWER's handler ran first (registered first), saw focus sitting inside the
// dialog, judged it "outside my container" and pulled focus back down behind the
// scrim. The first Tab in the top layer escaped it. Making the dispatch itself
// topmost-only makes that structurally impossible rather than a check each handler
// has to remember.
//
// Why NOT `useOverlayStack`, which already knows what is topmost: it answers a
// different question — which layer Escape DISMISSES — over a different set. It
// contains overlays that never trap focus (every open `Select` list registers as
// `kind: 'popover'`, and its panel is teleported to <body> OUTSIDE the modal
// panel), so a modal's trap would stand down whenever a select inside it was open
// and Tab would walk straight out of the modal into the page behind — a worse
// regression than the bug being fixed. It also does not contain traps that are not
// dismissible overlays. This is not a second overlay stack and duplicates none of
// its data: the two registries answer "who dismisses on Escape" and "who owns
// focus containment" and are written/read in exactly one place each.
interface TrapEntry {
  handleTab: (event: KeyboardEvent) => void;
}

const trapStack: TrapEntry[] = [];
let listening = false;

function onDocumentKeydown(event: KeyboardEvent): void {
  if (event.key !== 'Tab') return;
  const top = trapStack[trapStack.length - 1];
  if (!top) return;
  top.handleTab(event);
}

function pushTrap(entry: TrapEntry): void {
  const existing = trapStack.indexOf(entry);
  if (existing >= 0) trapStack.splice(existing, 1);
  trapStack.push(entry);
  if (listening) return;
  // Capture phase, as before: run ahead of component-local key handlers.
  document.addEventListener('keydown', onDocumentKeydown, true);
  listening = true;
}

function removeTrap(entry: TrapEntry): void {
  const i = trapStack.indexOf(entry);
  if (i < 0) return;
  trapStack.splice(i, 1);
  // Removing the top entry hands Tab straight back to the layer beneath it.
  if (trapStack.length > 0 || !listening) return;
  document.removeEventListener('keydown', onDocumentKeydown, true);
  listening = false;
}

/** Number of currently active traps (test/debug helper, mirrors `overlayStackSize`). */
export function focusTrapStackSize(): number {
  return trapStack.length;
}

export function useFocusTrap(
  containerRef: Ref<HTMLElement | null>,
  active: Ref<boolean>,
): void {
  let previouslyFocused: HTMLElement | null = null;
  let registered = false;

  function handleTab(event: KeyboardEvent): void {
    const container = containerRef.value;
    if (!container) return;

    const focusable = getFocusable(container);
    if (focusable.length === 0) {
      event.preventDefault();
      return;
    }

    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    const activeEl = document.activeElement as HTMLElement | null;

    // All focus moves use { preventScroll: true } so trapping focus inside a
    // body-teleported overlay (e.g. a centered Modal) never scroll-jumps the page
    // (Issue 3).
    if (event.shiftKey) {
      if (activeEl === first || !container.contains(activeEl)) {
        event.preventDefault();
        last.focus({ preventScroll: true });
      }
    } else if (activeEl === last || !container.contains(activeEl)) {
      event.preventDefault();
      first.focus({ preventScroll: true });
    }
  }

  const entry: TrapEntry = { handleTab };

  function activate(): void {
    previouslyFocused = document.activeElement as HTMLElement | null;
    pushTrap(entry);
    registered = true;
    // Defer so the container has rendered its focusable children.
    requestAnimationFrame(() => {
      const container = containerRef.value;
      if (!container) return;
      const focusable = getFocusable(container);
      (focusable[0] ?? container).focus({ preventScroll: true });
    });
  }

  function deactivate(): void {
    removeTrap(entry);
    registered = false;
    previouslyFocused?.focus?.({ preventScroll: true });
    previouslyFocused = null;
  }

  watch(
    active,
    (isActive) => {
      if (isActive) activate();
      else deactivate();
    },
    { flush: 'post' },
  );

  // Unmounting while still active must not leave a dead entry on top of the stack:
  // it would swallow Tab for every layer beneath it for the rest of the session.
  // Focus is deliberately NOT restored here (unchanged from before this composable
  // had any teardown) — the owner is gone, and stealing focus on unmount would be a
  // new behavior of its own.
  if (getCurrentScope()) {
    onScopeDispose(() => {
      if (!registered) return;
      removeTrap(entry);
      registered = false;
      previouslyFocused = null;
    });
  }
}
