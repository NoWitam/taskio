// mention.ts — the `@`-mention PART 2 node + its caret-anchored suggestion popup.
//
// SHAPE: an inline, atomic, SELECTABLE node (one deletable unit). Renders via the
// `MentionChip` Vue NodeView. Serializes to the legacy directive
// `@[mention]("{…}")` (see FORMAT.md) so content is portable between editors.
//
// SUGGESTION (no tippy): a bespoke ProseMirror plugin watches the text before the
// caret for an `@query` trigger and publishes the caret rect + query + (async)
// items into a reactive `suggestionStore`. A single always-mounted
// `MentionSuggest.vue` (created with Vue's `createApp` against the store, à la the
// legacy `VueRenderer`, but positioned with OUR `useAnchoredPosition`) renders the
// listbox. Keyboard (↑/↓/Enter/Esc) is forwarded from the plugin's `handleKeyDown`.
import { Node, mergeAttributes } from '@tiptap/core';
import { VueNodeViewRenderer } from '@tiptap/vue-3';
import { Plugin, PluginKey } from '@tiptap/pm/state';
import type { EditorView } from '@tiptap/pm/view';
import { createApp, type App } from 'vue';
import MentionChip from './MentionChip.vue';
import MentionSuggest from './MentionSuggest.vue';
import {
  createSuggestionStore,
  type SuggestionStore,
  type SuggestionRow,
} from './suggestionStore';
import {
  registerMarkdownNode,
  registerInlineDirective,
  type JSONNode,
} from '../markdown';
import {
  DATA_VERSION,
  type MentionItem,
  type MentionNodeAttrs,
} from './types';

export interface MentionOptions {
  /** Async source: returns the items to suggest for a query (debounced upstream). */
  fetch: (query: string) => Promise<MentionItem[]>;
  /** Trigger character. Default `@`. */
  trigger?: string;
}

const pluginKey = new PluginKey('next-mention-suggestion');

// --- markdown (de)serialization (legacy FORMAT.md parity) -------------------
// Register once at module load so markdownToDoc/docToMarkdown round-trip even
// before any editor instance exists (tests import the extension to register).
function encodeMentionDirective(attrs: MentionNodeAttrs): string {
  const data = { id: attrs.id, name: attrs.name, avatar: attrs.avatar ?? '' };
  const json = JSON.stringify({ v: DATA_VERSION, data });
  return `@[mention]("${json.replace(/"/g, '\\"')}")`;
}

let registered = false;
function ensureMarkdownRegistered(): void {
  if (registered) return;
  registered = true;
  registerMarkdownNode('mention', {
    toMarkdown(node) {
      return encodeMentionDirective(node.attrs as unknown as MentionNodeAttrs);
    },
  });
  registerInlineDirective({
    keyword: 'mention',
    toNode(payload): JSONNode | null {
      const p = payload as Partial<MentionNodeAttrs> | null;
      if (!p || !p.id) return null;
      return {
        type: 'mention',
        attrs: { id: p.id, name: p.name ?? p.id, avatar: p.avatar ?? null },
      };
    },
  });
}

ensureMarkdownRegistered();

export function createMention(options: MentionOptions) {
  const trigger = options.trigger ?? '@';

  return Node.create({
    name: 'mention',
    group: 'inline',
    inline: true,
    atom: true,
    selectable: true,

    addAttributes() {
      return {
        id: { default: null },
        name: { default: null },
        avatar: { default: null },
      };
    },

    parseHTML() {
      return [{ tag: 'span[data-mention]' }];
    },

    renderHTML({ node }) {
      return [
        'span',
        mergeAttributes({
          'data-mention': 'true',
          'data-id': node.attrs.id,
          'data-name': node.attrs.name,
        }),
        `@${node.attrs.name}`,
      ];
    },

    addNodeView() {
      return VueNodeViewRenderer(MentionChip as never);
    },

    addProseMirrorPlugins() {
      const editor = this.editor;
      const store = createSuggestionStore();
      store.variant = 'mention';
      const popup = mountSuggestPopup(store);
      let requestToken = 0;

      const close = (): void => {
        store.active = false;
        store.items = [];
        store.loading = false;
        store.query = '';
        store.rect = null;
      };
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

      const insert = (view: EditorView, item: SuggestionRow): void => {
        const state = pluginKey.getState(view.state) as TriggerState | undefined;
        if (!state?.range) return;
        editor
          .chain()
          .focus()
          .insertContentAt(state.range, [
            {
              type: 'mention',
              attrs: { id: item.id, name: item.label, avatar: item.avatar ?? null },
            },
            { type: 'text', text: ' ' },
          ])
          .run();
        close();
      };
      store.onSelect = (item) => insert(editor.view, item);

      const runFetch = async (query: string): Promise<void> => {
        const token = ++requestToken;
        store.loading = true;
        store.items = [];
        store.activeIndex = 0;
        try {
          const items = await options.fetch(query);
          if (token !== requestToken) return; // stale response dropped
          store.items = items.map(
            (m: MentionItem): SuggestionRow => ({
              id: m.id,
              label: m.label,
              avatar: m.avatar ?? null,
            }),
          );
        } catch {
          if (token === requestToken) store.items = [];
        } finally {
          if (token === requestToken) store.loading = false;
        }
      };

      return [
        new Plugin<TriggerState>({
          key: pluginKey,
          state: {
            init: () => ({ active: false, query: '', range: null }),
            apply(tr, _value, _old, newState): TriggerState {
              if (!editor.isEditable) return { active: false, query: '', range: null };
              const { $from } = newState.selection;
              const pos = $from.pos;
              const before = newState.doc.textBetween(
                Math.max(0, pos - 50),
                pos,
                '\n',
                '￼',
              );
              const esc = trigger.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
              const match = before.match(new RegExp(`(^|\\s)${esc}([\\w-]*)$`));
              if (match) {
                const query = match[2] ?? '';
                return {
                  active: true,
                  query,
                  range: { from: pos - query.length - trigger.length, to: pos },
                };
              }
              return { active: false, query: '', range: null };
            },
          },
          props: {
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

interface TriggerState {
  active: boolean;
  query: string;
  range: { from: number; to: number } | null;
}

// Mount the single suggestion popup app, bound to this editor's store. Teleports
// itself to <body>, so we only need a detached host element to mount into.
function mountSuggestPopup(store: SuggestionStore): { destroy: () => void } {
  const host = document.createElement('div');
  let app: App | null = createApp(MentionSuggest, { store });
  app.mount(host);
  return {
    destroy() {
      app?.unmount();
      app = null;
      host.remove();
    },
  };
}
