import { Node, mergeAttributes } from '@tiptap/core';
import { VueNodeViewRenderer } from '@tiptap/vue-3';
import MentionChip from '../nodeviews/MentionChip.vue';
import type { MentionNodeAttrs } from '../../types/editor';
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
});
