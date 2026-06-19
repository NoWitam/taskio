// Shared types + the optional global ⌘K / Ctrl+K listener helper for the
// CommandPalette. Kept in a plain .ts module so the runtime `useCommandPalette`
// export is allowed and consumers can register the shortcut without importing
// the SFC.
import { onBeforeUnmount, onMounted, ref, type Ref } from 'vue';

export interface Command {
  /** Stable, unique id. */
  id: string;
  /** Visible label (the primary search target). */
  label: string;
  /** Optional group heading the command is bucketed under. */
  group?: string;
  /** Optional leading icon name (string kept loose to avoid an Icon import here). */
  icon?: string;
  /** Extra search terms (matched alongside the label). */
  keywords?: string[];
  /** Shortcut hint shown via <Kbd> (e.g. ['mod', 'k']). */
  shortcut?: string[];
  disabled?: boolean;
  /** Run on activation. The palette closes after a successful run. */
  perform: () => void;
}

export interface UseCommandPaletteReturn {
  /** Bind to the palette's `v-model:open`. */
  open: Ref<boolean>;
  openPalette: () => void;
  closePalette: () => void;
  toggle: () => void;
}

/**
 * Register a global ⌘K (macOS) / Ctrl+K (others) listener that opens a single
 * host-mounted CommandPalette. Returns a reactive `open` to bind to the
 * palette's `v-model:open`, plus imperative open/close/toggle helpers. The
 * listener is installed on mount and removed on unmount.
 */
export function useCommandPalette(): UseCommandPaletteReturn {
  const open = ref(false);

  function openPalette(): void {
    open.value = true;
  }
  function closePalette(): void {
    open.value = false;
  }
  function toggle(): void {
    open.value = !open.value;
  }

  function onKeydown(event: KeyboardEvent): void {
    const mod = event.metaKey || event.ctrlKey;
    if (mod && event.key.toLowerCase() === 'k') {
      event.preventDefault();
      toggle();
    }
  }

  onMounted(() => document.addEventListener('keydown', onKeydown));
  onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown));

  return { open, openPalette, closePalette, toggle };
}
