// variable.ts — the template-`{ variable }` PART 2 node.
//
// SHAPE: inline, atomic, selectable; rendered via the `VariableChip` NodeView
// (which owns its Modal-hosted pipeline editor). Serializes to the legacy
// directive `@[variable]("{id,name,type,locked,pipeline,resultType,v}")`
// (FORMAT.md), so content is portable with the legacy editor.
//
// INSERTION (legacy parity): NOT a toolbar button — a TRIGGER like the mention,
// default `{`. A bespoke ProseMirror plugin watches the text before the caret for
// the trigger + query, publishes the caret rect + locally-filtered predefined
// variables into the shared `suggestionStore`, and the teleported `MentionSuggest`
// popup (variant: 'variable') renders the type-icon rows. Selecting inserts a
// chip whose attrs come from the chosen VariableDefinition.
import { Node, mergeAttributes } from '@tiptap/core';
import { VueNodeViewRenderer } from '@tiptap/vue-3';
import { Plugin, PluginKey } from '@tiptap/pm/state';
import type { EditorView } from '@tiptap/pm/view';
import { createApp, type App } from 'vue';
import VariableChip from './VariableChip.vue';
import MentionSuggest from './MentionSuggest.vue';
import {
  createSuggestionStore,
  type SuggestionStore,
  type SuggestionRow,
} from './suggestionStore';
import { getVariableIconName } from './operationHelpers';
import {
  registerMarkdownNode,
  registerInlineDirective,
  type JSONNode,
} from '../markdown';
import {
  DATA_VERSION,
  type VariableDefinition,
  type VariableNodeAttrs,
  type VariableOperationDefinition,
} from './types';

export interface VariableOptions {
  /** Predefined source variables the user can insert. */
  variables?: VariableDefinition[];
  /** Operations catalog used by the pipeline editor. */
  operationsCatalog?: VariableOperationDefinition[];
  /** Trigger character. Default `{` (must not collide with `@` / input rules). */
  trigger?: string;
}

const pluginKey = new PluginKey('next-variable-suggestion');

function encodeVariableDirective(attrs: VariableNodeAttrs): string {
  const data = {
    id: attrs.id,
    name: attrs.name,
    type: attrs.type,
    locked: attrs.locked,
    pipeline: attrs.pipeline ?? [],
    resultType: attrs.resultType,
  };
  const json = JSON.stringify({ v: DATA_VERSION, data });
  return `@[variable]("${json.replace(/"/g, '\\"')}")`;
}

let registered = false;
function ensureMarkdownRegistered(): void {
  if (registered) return;
  registered = true;
  registerMarkdownNode('variable', {
    toMarkdown(node) {
      return encodeVariableDirective(node.attrs as unknown as VariableNodeAttrs);
    },
  });
  registerInlineDirective({
    keyword: 'variable',
    toNode(payload): JSONNode | null {
      const p = payload as Partial<VariableNodeAttrs> | null;
      if (!p || !p.id) return null;
      return {
        type: 'variable',
        attrs: {
          id: p.id,
          name: p.name ?? p.id,
          type: p.type ?? 'text',
          locked: p.locked ?? false,
          pipeline: Array.isArray(p.pipeline) ? p.pipeline : [],
          resultType: p.resultType ?? 'text',
        },
      };
    },
  });
}

ensureMarkdownRegistered();

export function createVariable(options: VariableOptions = {}) {
  const trigger = options.trigger ?? '{';
  const definitions = options.variables ?? [];
  const catalog = options.operationsCatalog ?? [];

  return Node.create({
    name: 'variable',
    group: 'inline',
    inline: true,
    atom: true,
    selectable: true,

    addStorage() {
      return { definitions, catalog };
    },

    addAttributes() {
      return {
        id: { default: '' },
        name: { default: '' },
        type: { default: 'text' },
        locked: { default: false },
        pipeline: { default: [] },
        resultType: { default: 'text' },
      };
    },

    parseHTML() {
      return [{ tag: 'span[data-variable]' }];
    },

    renderHTML({ node }) {
      return [
        'span',
        mergeAttributes({
          'data-variable': 'true',
          'data-id': node.attrs.id,
          'data-name': node.attrs.name,
        }),
        `{{${node.attrs.name || node.attrs.id}}}`,
      ];
    },

    addNodeView() {
      return VueNodeViewRenderer(VariableChip as never);
    },

    addProseMirrorPlugins() {
      const editor = this.editor;
      const store = createSuggestionStore();
      store.variant = 'variable';
      const popup = mountSuggestPopup(store);

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

      // SF3.1 — keep the popup glued to the caret on scroll. Re-measure the caret
      // rect (coordsAtPos reflects the CURRENT scroll) and either follow it, or
      // CLOSE when the caret scrolled out of the viewport (a detached popup is worse
      // than none). Same behavior the shared MentionSuggest gives the `@` trigger.
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

      const insert = (view: EditorView, row: SuggestionRow): void => {
        const state = pluginKey.getState(view.state) as TriggerState | undefined;
        if (!state?.range) return;
        const def = definitions.find((d) => d.id === row.id);
        if (!def) return;
        editor
          .chain()
          .focus()
          .insertContentAt(state.range, [
            {
              type: 'variable',
              attrs: {
                id: def.id,
                name: def.name,
                type: def.type,
                locked: false,
                pipeline: [],
                resultType: def.type,
              },
            },
            { type: 'text', text: ' ' },
          ])
          .run();
        close();
      };
      store.onSelect = (row) => insert(editor.view, row);

      const runFilter = (query: string): void => {
        const q = query.toLowerCase();
        const matches = (q
          ? definitions.filter((d) => d.name.toLowerCase().includes(q))
          : definitions
        ).map(
          (d): SuggestionRow => ({
            id: d.id,
            label: d.name,
            icon: getVariableIconName(d.type),
          }),
        );
        store.items = matches;
        store.activeIndex = 0;
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
              const before = newState.doc.textBetween(Math.max(0, pos - 50), pos, '\n', '￼');
              const esc = trigger.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
              // Allow letters, digits, spaces, hyphen, underscore in the query so
              // multi-word variable names ("Order total") are searchable.
              const match = before.match(new RegExp(`(^|\\s)${esc}([\\w\\s-]*)$`));
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
                    store.query = state.query;
                    runFilter(state.query);
                  } else if (prev.query !== state.query) {
                    store.query = state.query;
                    runFilter(state.query);
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
