// wikilink.ts — the `[[` autocomplete trigger for knowledge wikilinks.
//
// THIS IS NOT A NODE. Deliberately: a wikilink stays PLAIN MARKDOWN TEXT (`[[slug]]` /
// `[[slug|label]]`) in the document. No `Node.create`, no `registerMarkdownNode`, no
// `registerInlineDirective`. The reasons are contract-level, not stylistic:
//
//   • The BACKEND already parses `[[…]]` out of the raw content to build graph edges
//     (Support/WikilinkParser). A Tiptap node would create a SECOND source of truth about what a
//     link is, and the two would drift the first time serialization changed.
//   • The content stays portable and greppable — copy-paste between entries just works, and an
//     export or a prompt assembled by a bot reads the same characters the author typed.
//   • Zero risk to the FORMAT contract (the `@[…]` inline directives) — we add no grammar.
//
// So this extension registers exactly ONE ProseMirror plugin: it watches the text before the caret,
// publishes a query into the shared `suggestionStore`, renders `WikilinkSuggest.vue` against it, and
// on pick INSERTS TEXT. Structurally the same machinery as `mention.ts`, minus the node.
//
// ─────────────────────────────────────────────────────────────────────────────
// CRITICAL — Escape must not destroy the user's work.
// ─────────────────────────────────────────────────────────────────────────────
// The plugin answers Escape from ProseMirror's `handleKeyDown`, a BUBBLE-phase listener. The shared
// overlay stack listens on `document` in the CAPTURE phase and stops Escape on behalf of the
// topmost overlay. Without registering, the first Escape closes the surrounding Drawer/Modal (or,
// here, could dismiss the editor route) and discards everything typed, while the suggestion list
// stays open. That exact regression already hit `@`-mentions and `Select`.
//
// Hence `createSuggestionOverlay(close)` + `overlay.acquire()` on open and `overlay.release()` in
// BOTH `close()` and `destroy()`. It is pinned by a test.
import { Extension } from '@tiptap/core';
import { Plugin, PluginKey } from '@tiptap/pm/state';
import type { EditorView } from '@tiptap/pm/view';
import { createApp, type App } from 'vue';
import WikilinkSuggest from './WikilinkSuggest.vue';
import type { StatusMap } from '../../data/StatusBadge.vue';
import {
  createSuggestionStore,
  createSuggestionOverlay,
  type SuggestionStore,
  type SuggestionRow,
} from './suggestionStore';

/** ONE entry offered by the `[[` popup. */
export interface WikilinkItem {
  slug: string;
  title: string;
  /** Editorial status (`draft` / `proposed` / `approved` / `archived`), rendered as a badge. */
  status?: string | null;
}

export interface WikilinkOptions {
  /** Async source for a query. Debouncing/caching is the HOST's business, not the plugin's. */
  fetch: (query: string) => Promise<WikilinkItem[]>;
  /**
   * Offer a trailing "Create entry «query»" row. Default true.
   *
   * Picking it only INSERTS `[[<slug>]]` — the editor never creates an entity in the background.
   * The entry itself is born from the reader's red-link CTA, where the user can see what they are
   * making.
   */
  allowCreate?: boolean;
  /** Turn a typed phrase into a slug. Host-supplied so it matches the backend's normalizer. */
  slugify?: (value: string) => string;
  /**
   * Entry-status descriptors for the row badge.
   *
   * Passed in rather than imported: the map lives in the Knowledge module and this is design-system
   * code. Omit it and the badge is simply not drawn — which is right for a host whose items carry
   * no status, and better than printing a raw enum value.
   */
  statusMap?: StatusMap;
  /** Localized strings for the popup. The design system carries no module copy. */
  labels: {
    list: string;
    create: (query: string) => string;
    empty: string;
    /** Shown when the fetch FAILED — never folded into the empty row. */
    error: string;
  };
}

const pluginKey = new PluginKey('next-wikilink-suggestion');

/**
 * The trigger.
 *
 * `[[` then anything that is not a bracket, a pipe or a newline. Two consequences, both wanted:
 *   • the query MAY contain spaces (entry titles are prose, not identifiers);
 *   • once the user types `|` to write their own label, the popup stops offering to overwrite it.
 */
const TRIGGER_RE = /\[\[([^[\]|\n]*)$/;

/** How far back from the caret we look. Comfortably longer than any sane entry title. */
const LOOKBEHIND = 120;

/**
 * Letters Unicode normalization does NOT decompose, transliterated the way Laravel's `Str::ascii()`
 * does. Without this, `ł` survives NFKD and then becomes a HYPHEN — "Zażółć" would slug to
 * `zazo-c` instead of `zazolc`.
 */
const TRANSLITERATE: Record<string, string> = {
  ł: 'l', Ł: 'l',
  đ: 'd', Đ: 'd',
  ø: 'o', Ø: 'o',
  ß: 'ss',
  æ: 'ae', Æ: 'ae',
  œ: 'oe', Œ: 'oe',
  þ: 'th', Þ: 'th',
  ð: 'd', Ð: 'd',
  ı: 'i', İ: 'i',
};

/**
 * The FALLBACK slugifier, used only when a host does not supply its own.
 *
 * A host whose backend mints slugs should always pass `slugify` so the two agree exactly — the
 * Knowledge editor passes `normalizeSlug`, which mirrors `WikilinkParser::normalize`
 * (`Str::slug`). This default aims at the same result so an omission degrades gracefully instead
 * of silently authoring links that resolve to nothing.
 */
function defaultSlugify(value: string): string {
  return value
    .replace(/[łŁđĐøØßæÆœŒþÞðÐıİ]/g, (ch) => TRANSLITERATE[ch] ?? ch)
    .normalize('NFKD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');
}

export function createWikilink(options: WikilinkOptions): Extension {
  const allowCreate = options.allowCreate ?? true;
  const slugify = options.slugify ?? defaultSlugify;

  return Extension.create({
    name: 'wikilinkSuggestion',

    addProseMirrorPlugins() {
      const editor = this.editor;
      const store = createSuggestionStore();
      store.variant = 'wikilink';
      const popup = mountSuggestPopup(store, options);
      let requestToken = 0;

      const close = (): void => {
        store.active = false;
        store.items = [];
        store.loading = false;
        // Cleared with the rest of the state: an error belongs to the search that failed, not to
        // the next time the popup opens.
        store.errored = false;
        store.query = '';
        store.rect = null;
        overlay.release();
      };
      // Declared after `close` because the stack needs it as the dismiss callback. See the header:
      // this line is the difference between Escape closing a list and Escape destroying an entry.
      const overlay = createSuggestionOverlay(close);
      store.onClose = close;

      const caretRect = (view: EditorView, pos: number): DOMRect => {
        const coords = view.coordsAtPos(pos);
        return {
          top: coords.top,
          bottom: coords.bottom,
          left: coords.left,
          right: coords.left,
          width: 0,
          height: coords.bottom - coords.top,
          x: coords.left,
          y: coords.top,
          toJSON() {},
        } as DOMRect;
      };

      // Keep the popup glued to the caret on scroll; close it when the caret leaves the viewport
      // (a popup floating away from its anchor is worse than no popup).
      const reposition = (): void => {
        if (!store.active) return;
        const state = pluginKey.getState(editor.view.state) as TriggerState | undefined;
        if (!state?.active || !state.range) {
          close();
          return;
        }
        let rect: DOMRect;
        try {
          rect = caretRect(editor.view, state.range.from);
        } catch {
          close();
          return;
        }
        if (rect.bottom < 0 || rect.top > window.innerHeight) {
          close();
          return;
        }
        store.rect = rect;
      };
      store.reposition = reposition;

      /**
       * Insert `[[slug]]` as TEXT over the trigger range.
       *
       * No trailing space: a wikilink usually sits mid-sentence, and the author is mid-thought —
       * adding whitespace they did not ask for is the kind of "helpful" edit that gets undone.
       */
      const insert = (view: EditorView, item: SuggestionRow): void => {
        const state = pluginKey.getState(view.state) as TriggerState | undefined;
        if (!state?.range) return;

        const slug =
          item.kind === 'create' ? slugify(store.query) : (item.slug ?? slugify(item.label));
        if (slug === '') {
          close();
          return;
        }

        editor
          .chain()
          .focus()
          .insertContentAt(state.range, [{ type: 'text', text: `[[${slug}]]` }])
          .run();
        close();
      };
      store.onSelect = (item) => insert(editor.view, item);

      const runFetch = async (query: string): Promise<void> => {
        const token = ++requestToken;
        store.loading = true;
        store.errored = false;
        store.items = [];
        store.activeIndex = 0;
        try {
          const items = await options.fetch(query);
          if (token !== requestToken) return; // stale response dropped
          store.items = toRows(items, query, allowCreate, slugify);
        } catch {
          // A FAILED SEARCH IS NOT AN EMPTY ONE. Rendering the failure as "no matches" told the
          // writer this base has no such entry — so they write a red link, and the entry that did
          // exist stays unlinked. The flag says which happened; the popup words it.
          if (token === requestToken) {
            store.errored = true;
            store.items = toRows([], query, allowCreate, slugify);
          }
        } finally {
          if (token === requestToken) store.loading = false;
        }
      };

      return [
        new Plugin<TriggerState>({
          key: pluginKey,
          state: {
            init: () => ({ active: false, query: '', range: null }),
            apply(_tr, _value, _old, newState): TriggerState {
              if (!editor.isEditable) return { active: false, query: '', range: null };
              const { $from } = newState.selection;
              const pos = $from.pos;
              const before = newState.doc.textBetween(Math.max(0, pos - LOOKBEHIND), pos, '\n', '￼');
              const match = before.match(TRIGGER_RE);
              if (match) {
                const query = match[1] ?? '';
                return {
                  // `- 2` for the two `[` characters the trigger consumed.
                  active: true,
                  query,
                  range: { from: pos - query.length - 2, to: pos },
                };
              }
              return { active: false, query: '', range: null };
            },
          },
          props: {
            // Virtual focus: DOM focus never leaves ProseMirror, so the caret stays put and the
            // listbox is a companion the plugin drives.
            handleKeyDown(view, event) {
              const state = pluginKey.getState(view.state) as TriggerState | undefined;
              if (!state?.active || !store.active) return false;
              if (event.key === 'Escape') {
                close();
                return true;
              }
              if (event.key === 'ArrowDown') {
                if (store.items.length) {
                  store.activeIndex = (store.activeIndex + 1) % store.items.length;
                }
                return true;
              }
              if (event.key === 'ArrowUp') {
                if (store.items.length) {
                  store.activeIndex =
                    (store.activeIndex + store.items.length - 1) % store.items.length;
                }
                return true;
              }
              if (event.key === 'Enter') {
                const item = store.items[store.activeIndex];
                if (item) {
                  insert(view, item);
                  return true;
                }
                return false;
              }
              return false;
            },
          },
          view() {
            return {
              update(view, prevStateView) {
                const state = pluginKey.getState(view.state) as TriggerState;
                const prev = pluginKey.getState(prevStateView) as TriggerState;
                if (state.active) {
                  store.rect = caretRect(view, state.range!.from);
                  if (!prev.active) {
                    store.active = true;
                    overlay.acquire();
                    store.activeIndex = 0;
                    store.query = state.query;
                    void runFetch(state.query);
                  } else if (prev.query !== state.query) {
                    store.query = state.query;
                    void runFetch(state.query);
                  }
                } else if (prev.active) {
                  close();
                }
              },
              destroy() {
                close();
                popup.destroy();
              },
            };
          },
        }),
      ];
    },
  });
}

/** Map fetched entries to rows, appending the "create" affordance when it would do something. */
function toRows(
  items: WikilinkItem[],
  query: string,
  allowCreate: boolean,
  slugify: (value: string) => string,
): SuggestionRow[] {
  const rows: SuggestionRow[] = items.map((item) => ({
    id: item.slug,
    label: item.title,
    slug: item.slug,
    status: item.status ?? undefined,
    kind: 'entry',
  }));

  // Offered only when the typed phrase yields a slug that is not already on the list — otherwise
  // "create" would sit next to the very entry it would duplicate.
  const candidate = slugify(query);
  if (allowCreate && candidate !== '' && !rows.some((row) => row.slug === candidate)) {
    rows.push({ id: `create:${candidate}`, label: query, slug: candidate, kind: 'create' });
  }
  return rows;
}

interface TriggerState {
  active: boolean;
  query: string;
  range: { from: number; to: number } | null;
}

/** Mount the single popup app bound to this editor's store. It teleports itself to <body>. */
function mountSuggestPopup(
  store: SuggestionStore,
  options: WikilinkOptions,
): { destroy: () => void } {
  const host = document.createElement('div');
  let app: App | null = createApp(WikilinkSuggest, { store, statusMap: options.statusMap, labels: options.labels });
  app.mount(host);
  return {
    destroy() {
      app?.unmount();
      app = null;
      host.remove();
    },
  };
}
