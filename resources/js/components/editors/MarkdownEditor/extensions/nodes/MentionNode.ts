import { Node, mergeAttributes } from '@tiptap/core';
import { VueNodeViewRenderer, VueRenderer } from '@tiptap/vue-3';
import { PluginKey } from '@tiptap/pm/state';
import { Plugin } from '@tiptap/pm/state';
import tippy, { type Instance as TippyInstance } from 'tippy.js';
import MentionChip from '../nodeviews/MentionChip.vue';
import MentionList from '../../components/MentionList.vue';
import type { MentionNodeAttrs, MentionUser } from '../../types/editor';
import { DATA_VERSION } from '../../types/editor';

export const MentionNode = Node.create({
  name: 'mention',
  group: 'inline',
  inline: true,
  selectable: false,
  atom: true,

  addAttributes() {
    return {
      id: { default: null },
      name: { default: null },
      avatar: { default: null },
      version: { default: DATA_VERSION },
    } satisfies Record<keyof MentionNodeAttrs | 'version', any>;
  },

  parseHTML() {
    return [
      {
        tag: 'span[data-mention]',
        getAttrs: (element: HTMLElement) => ({
          id: element.getAttribute('data-id'),
          name: element.getAttribute('data-name'),
          avatar: element.getAttribute('data-avatar') || undefined,
        }),
      },
    ];
  },

  renderHTML({ node }) {
    return [
      'span',
      mergeAttributes(
        {
          'data-mention': 'true',
          'data-id': node.attrs.id,
          'data-name': node.attrs.name,
          'data-avatar': node.attrs.avatar,
        }
      ),
      `@${node.attrs.name}`,
    ];
  },

  addNodeView() {
    return VueNodeViewRenderer(MentionChip);
  },

  addProseMirrorPlugins() {
    const editor = this.editor;
    let popup: TippyInstance | null = null;
    let component: VueRenderer | null = null;

    const closeMentionPopup = () => {
      if (popup) {
        popup.destroy();
        popup = null;
      }
      if (component) {
        component.destroy();
        component = null;
      }
    };

    const showMentionPopup = (view: any, state: any) => {
      const config = editor.storage.markdownEditorConfig?.config;
      const users: MentionUser[] = config?.features?.mentions?.users || [];
      
      if (!users.length) return;

      const filteredItems = !state.query
        ? users
        : users.filter((user) =>
            user.name.toLowerCase().includes(state.query.toLowerCase()) ||
            user.email?.toLowerCase().includes(state.query.toLowerCase())
          );

      component = new VueRenderer(MentionList, {
        props: {
          items: filteredItems,
          command: (user: MentionUser) => {
            if (!state.range) return;
            
            editor
              .chain()
              .focus()
              .deleteRange(state.range)
              .insertContent([
                {
                  type: 'mention',
                  attrs: {
                    id: user.id,
                    name: user.name,
                    avatar: user.avatar,
                  },
                },
                { type: 'text', text: ' ' },
              ])
              .run();

            closeMentionPopup();
          },
        },
        editor,
      });

      const getClientRect = () => {
        const pos = state.range?.from ?? view.state.selection.$from.pos;
        const coords = view.coordsAtPos(pos);
        return {
          top: coords.top,
          left: coords.left,
          bottom: coords.bottom,
          right: coords.right,
          width: 0,
          height: coords.bottom - coords.top,
          x: coords.left,
          y: coords.top,
        } as DOMRect;
      };

      popup = tippy(document.body, {
        getReferenceClientRect: getClientRect,
        appendTo: () => document.body,
        content: component.element,
        showOnCreate: true,
        interactive: true,
        trigger: 'manual',
        placement: 'bottom-start',
        arrow: false,
        maxWidth: 'none',
        zIndex: 9999,
        theme: '',
      })[0];
    };

    const updateMentionPopup = (view: any, state: any) => {
      if (!component || !popup) return;

      const config = editor.storage.markdownEditorConfig?.config;
      const users: MentionUser[] = config?.features?.mentions?.users || [];
      
      const filteredItems = !state.query
        ? users
        : users.filter((user) =>
            user.name.toLowerCase().includes(state.query.toLowerCase()) ||
            user.email?.toLowerCase().includes(state.query.toLowerCase())
          );

      component.updateProps({
        items: filteredItems,
      });

      if (state.range) {
        const coords = view.coordsAtPos(state.range.from);
        popup.setProps({
          getReferenceClientRect: () => ({
            top: coords.top,
            left: coords.left,
            bottom: coords.bottom,
            right: coords.right,
            width: 0,
            height: coords.bottom - coords.top,
            x: coords.left,
            y: coords.top,
          } as DOMRect),
        });
      }
    };

    const pluginKey = new PluginKey('mention-suggestion');

    return [
      new Plugin({
        key: pluginKey,
        state: {
          init() {
            return {
              active: false,
              query: '',
              range: null as { from: number; to: number } | null,
            };
          },
          apply(tr, state, oldState, newState) {
            if (!editor.isEditable) {
              return { active: false, query: '', range: null };
            }

            const { selection, doc } = newState;
            const { $from } = selection;
            const pos = $from.pos;

            // Look back up to 50 chars to find @ trigger
            const textBefore = doc.textBetween(Math.max(0, pos - 50), pos, '\n', '\ufffc');
            const match = textBefore.match(/(^|\s)@([\w]*)$/);

            if (match) {
              const query = match[2] || '';
              const from = pos - query.length - 1; // -1 for @
              return {
                active: true,
                query,
                range: { from, to: pos },
              };
            }

            return { active: false, query: '', range: null };
          },
        },
        props: {
          handleKeyDown(view, event) {
            const state = pluginKey.getState(view.state);
            if (!state || !state.active) return false;

            if (event.key === 'Escape') {
              closeMentionPopup();
              return true;
            }

            if (component?.ref?.onKeyDown) {
              const handled = component.ref.onKeyDown(event);
              if (handled) {
                event.preventDefault();
                return true;
              }
            }

            return false;
          },
        },
        view() {
          return {
            update: (view, prevState) => {
              const state = pluginKey.getState(view.state);
              const prevPluginState = pluginKey.getState(prevState);

              if (!state || !prevPluginState) return;

              // If just became active, show popup
              if (state.active && !prevPluginState.active) {
                showMentionPopup(view, state);
              }
              // If still active, update
              else if (state.active && prevPluginState.active) {
                updateMentionPopup(view, state);
              }
              // If just became inactive, hide
              else if (!state.active && prevPluginState.active) {
                closeMentionPopup();
              }
            },
            destroy: () => {
              closeMentionPopup();
            },
          };
        },
      }),
    ];
  },
});
