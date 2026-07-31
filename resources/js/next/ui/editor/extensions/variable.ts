// variable.ts — the template-`{ variable }` PART 2 node.
//
// SHAPE: inline, atomic, selectable; rendered via the `VariableChip` NodeView
// (which owns its Modal-hosted pipeline editor). Serializes to the legacy
// directive `@[variable]("{id,name,type,locked,pipeline,resultType,v}")`
// (FORMAT.md), so content is portable with the legacy editor.
//
// INSERTION (legacy parity): NOT a toolbar button — a TRIGGER like the mention,
// default `{`. A bespoke ProseMirror plugin watches the text before the caret for
// the trigger + query, publishes the caret rect + the offered variable TREE into the
// shared `suggestionStore`, and the teleported `VariableSuggest` popup renders it
// through the SHARED `VariableBrowser` (B4 — it replaced the flat MentionSuggest
// list, which had no type markers, could not expand a container and made an object
// entry insertable). Selecting inserts a chip built from the chosen tree NODE.
//
// VIRTUAL FOCUS: the editor keeps DOM focus for the popup's whole life — the plugin
// FORWARDS keys into the popup (`store.onKey`) rather than letting it take focus, so
// the caret never leaves the text. Forwarded: ↑/↓/Enter/Esc always, and ←/→ ONLY
// while the query is empty (once the user is typing, those keys must move the caret
// inside the query text). Nothing else is forwarded — a printable key must always
// reach ProseMirror, or the query could never grow.
import { Node, mergeAttributes } from '@tiptap/core';
import { VueNodeViewRenderer } from '@tiptap/vue-3';
import { Plugin, PluginKey } from '@tiptap/pm/state';
import type { EditorView } from '@tiptap/pm/view';
import { createApp, type App } from 'vue';
import VariableChip from './VariableChip.vue';
import VariableSuggest from './VariableSuggest.vue';
import {
  createSuggestionStore,
  createSuggestionOverlay,
  type SuggestionStore,
} from './suggestionStore';
import { variableFeedTree } from './variableFeed';
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
  type VariableStorage,
} from './types';
import type { Component } from 'vue';
import type { VariableLiteral, VariableNode, VariableSourceVar } from '../../variables/types';

export interface VariableOptions {
  /** Predefined source variables the user can insert (the FROZEN fallback feed). */
  variables?: VariableDefinition[];
  /** Operations catalog used by the pipeline editor (the FROZEN fallback feed). */
  operationsCatalog?: VariableOperationDefinition[];
  /**
   * LIVE getter for the offered variables in the SHARED model's vocabulary. Read at CALL
   * time (never captured), so an async / changing host feed reaches an already-open editor.
   * Wins over `variables` whenever it returns a non-empty list.
   */
  source?: () => VariableSourceVar[];
  /** LIVE getter for the operations catalog. Read at CALL time; wins over `operationsCatalog`. */
  catalog?: () => VariableOperationDefinition[];
  /** Trigger character. Default `{` (must not collide with `@` / input rules). */
  trigger?: string;
  /**
   * OPTIONAL host-injected value-or-variable control for ONE pipeline ARGUMENT inside the chip
   * panel (B4). See `VariableFeatureConfig.argVariableField` — `ui/**` cannot import the page's
   * value-or-variable field, so the host hands it in. Absent ⇒ literal-only arguments.
   */
  argVariableField?: Component;
}

/**
 * One SHARED-model variable projected onto the editor's identity-only `VariableDefinition`
 * (`id` === the variable's path — the same contract `toEditorVariablesTyped` produces). Lets a
 * host feed the editor the very same `VariableSourceVar[]` its pickers use, with no mapping at
 * the call site. Choices prefer the structured descriptor options (real human labels, wire
 * `key` as the value) and fall back to the flat `enumOptions`.
 */
export function sourceVarToDefinition(variable: VariableSourceVar): VariableDefinition {
  const descriptor = variable.descriptor;
  const definition: VariableDefinition = {
    id: variable.path,
    name: variable.name || variable.path,
    type: variable.type ?? 'text',
  };
  if (descriptor?.options?.length) {
    definition.options = descriptor.options.map((option) => ({
      label: option.label,
      value: option.key,
    }));
  } else if (variable.enumOptions?.length) {
    definition.options = variable.enumOptions.map((value) => ({ label: value, value }));
  }
  if (descriptor?.nullable || variable.nullable) definition.nullable = true;
  if (descriptor?.array) definition.array = true;
  // The STRUCTURAL base, but only when the flat type cannot express it (an `object` container
  // degrades to `text`) — so the flat definition can still be promoted back into a container.
  const implied = definition.type === 'multi' ? 'enum' : definition.type;
  if (descriptor?.base && descriptor.base !== implied) definition.base = descriptor.base;
  return definition;
}

const pluginKey = new PluginKey('next-variable-suggestion');

/**
 * Whether a per-reference default counts as UNSET, i.e. the directive must OMIT `data.default`
 * entirely (a ref without a default stays BYTE-IDENTICAL to the pre-1b format).
 *
 * Only `undefined` / `null` / `''` are unset. `false` and `0` are MEANINGFUL values and are
 * emitted — which is exactly why the panel's boolean control is a tri-state ("no default" vs
 * "no") rather than a switch.
 */
function defaultIsUnset(value: VariableLiteral | undefined): boolean {
  return value === undefined || value === null || value === '';
}

function encodeVariableDirective(attrs: VariableNodeAttrs): string {
  const data: Record<string, unknown> = {
    id: attrs.id,
    name: attrs.name,
    type: attrs.type,
    locked: attrs.locked,
    pipeline: attrs.pipeline ?? [],
    resultType: attrs.resultType,
  };
  // Emit-or-OMIT the per-reference default (phase-1b), now TYPED (B4): the value is written
  // through JSON.stringify AS IT IS, so a number/boolean default rides as a JSON scalar
  // (`"default":12` / `"default":false`) and parses back as the same JS type. A date is an ISO
  // `YYYY-MM-DD` string, an enum default its option KEY.
  if (!defaultIsUnset(attrs.default)) data.default = attrs.default;
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
      const attrs: Record<string, unknown> = {
        id: p.id,
        name: p.name ?? p.id,
        type: p.type ?? 'text',
        locked: p.locked ?? false,
        pipeline: Array.isArray(p.pipeline) ? p.pipeline : [],
        resultType: p.resultType ?? 'text',
      };
      // Rehydrate the optional per-reference default (phase-1b) only when present, so a
      // directive without one keeps `default` at its (null) attr default. The parsed JSON scalar
      // is kept AS IT IS (number stays number, boolean stays boolean) — the panel's typed control
      // reads it back with no coercion.
      if (!defaultIsUnset(p.default)) attrs.default = p.default;
      return { type: 'variable', attrs };
    },
  });
}

ensureMarkdownRegistered();

export function createVariable(options: VariableOptions = {}) {
  const trigger = options.trigger ?? '{';
  // The FROZEN fallback arrays (a host that passes no getters keeps today's behavior).
  const definitions = options.variables ?? [];
  const catalog = options.operationsCatalog ?? [];

  // --- The LIVE readers -----------------------------------------------------
  // NOTHING below captures a list: every read goes through these, so the feed a consumer
  // sees is always the host's CURRENT one. Called inside a Vue computed they also register
  // the reactive dependency, which is what makes an async catalog reach an open editor.
  const getSource = (): VariableSourceVar[] => options.source?.() ?? [];
  const getDefinitions = (): VariableDefinition[] => {
    // The LIVE feed wins whenever it carries anything; the frozen array is the fallback
    // (a host with no getter, or the transient moment before an async catalog resolves).
    const shared = getSource();
    return shared.length ? shared.map(sourceVarToDefinition) : definitions;
  };
  const getCatalog = (): VariableOperationDefinition[] => {
    const live = options.catalog?.();
    return live?.length ? live : catalog;
  };

  return Node.create({
    name: 'variable',
    group: 'inline',
    inline: true,
    atom: true,
    selectable: true,

    addStorage(): VariableStorage {
      return {
        definitions,
        catalog,
        getDefinitions,
        getCatalog,
        getSource,
        argVariableField: options.argVariableField,
      };
    },

    addAttributes() {
      return {
        id: { default: '' },
        name: { default: '' },
        type: { default: 'text' },
        locked: { default: false },
        pipeline: { default: [] },
        resultType: { default: 'text' },
        // Optional per-reference default (phase-1b); null ⇒ omitted from the directive.
        default: { default: null },
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
        store.nodes = [];
        store.loading = false;
        store.query = '';
        store.rect = null;
        overlay.release();
      };
      // The active popup is a real overlay: it joins the shared stack so Escape closes IT and not
      // the Modal/Drawer around the editor (see `createSuggestionOverlay`). Declared after `close`
      // because the stack needs it as the dismiss callback; it only runs after setup.
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

      /**
       * Insert the chip for a picked TREE node. The node carries everything the identity-only
       * directive needs — `path` IS the id, its label the display name, its flat type the base +
       * initial result type — so nothing is re-resolved against a second list.
       *
       * A container can never arrive here (the browser only emits SELECTABLE nodes, and a
       * non-array object never is), but the guard is kept: inserting one would drop a directive
       * that resolves to a MAP into the user's text.
       */
      const insert = (view: EditorView, node: VariableNode): void => {
        const state = pluginKey.getState(view.state) as TriggerState | undefined;
        if (!state?.range || !node?.selectable) return;
        editor
          .chain()
          .focus()
          .insertContentAt(state.range, [
            {
              type: 'variable',
              attrs: {
                id: node.path,
                name: node.label,
                type: node.type,
                locked: false,
                pipeline: [],
                resultType: node.type,
              },
            },
            { type: 'text', text: ' ' },
          ])
          .run();
        close();
      };
      store.onSelectNode = (node) => insert(editor.view, node);

      /**
       * Publish the offered TREE. Read the feed HERE, on every (re)open + query change — never a
       * snapshot from build time — and let the browser do the searching: it matches label AND
       * path, and only ever returns SELECTABLE hits.
       */
      const publishNodes = (): void => {
        store.nodes = variableFeedTree(getSource(), getDefinitions());
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
            /**
             * Key FORWARDING — the whole virtual-focus mechanism. ProseMirror keeps DOM focus, so
             * the popup can only ever react to what we hand it, and everything we do NOT hand it
             * reaches the document (which is what keeps typing, the caret and undo intact).
             *
             *   Esc         closes (never forwarded — the popup has nothing else to do with it).
             *   ↑ ↓ Enter   always forwarded: move the browser's virtual cursor / activate the
             *               row (a leaf inserts, a container toggles).
             *   ← →         forwarded ONLY while the query is EMPTY, where they are the WAI-ARIA
             *               tree's expand/collapse keys. The moment the user has typed a query
             *               they belong to the caret, so it can move inside that query text.
             *   everything else (printables, Space, Home/End, Tab…) is left alone.
             */
            handleKeyDown(view, event) {
              const state = pluginKey.getState(view.state) as TriggerState | undefined;
              if (!state?.active || !store.active) return false;
              if (event.key === 'Escape') {
                close();
                return true;
              }
              // Nothing to navigate ⇒ every key behaves as if the popup were not there.
              if (!store.nodes.length) return false;

              const navigates =
                event.key === 'ArrowDown' || event.key === 'ArrowUp' || event.key === 'Enter';
              const walksTree =
                (event.key === 'ArrowLeft' || event.key === 'ArrowRight') && state.query === '';
              if (!navigates && !walksTree) return false;

              return store.onKey?.(event) ?? false;
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
                    store.query = state.query;
                    publishNodes();
                  } else if (prev.query !== state.query) {
                    store.query = state.query;
                    publishNodes();
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
  let app: App | null = createApp(VariableSuggest, { store });
  app.mount(host);
  return {
    destroy() {
      app?.unmount();
      app = null;
      host.remove();
    },
  };
}
