import { Node, mergeAttributes } from '@tiptap/core';
import { VueNodeViewRenderer } from '@tiptap/vue-3';
import IfBlockView from '../nodeviews/IfBlockView.vue';
import type { IfBlockNodeAttrs } from '../../types/editor';

export const IfBlockNode = Node.create({
  name: 'ifBlock',
  group: 'block',
  draggable: false,
  atom: true,

  addAttributes() {
    return {
      id: {
        default: '',
        parseHTML: (element: HTMLElement) => element.getAttribute('data-id') || '',
        renderHTML: (attrs: IfBlockNodeAttrs) => ({ 'data-id': attrs.id }),
      },
      branches: {
        default: [],
        parseHTML: (element: HTMLElement) => {
          const payload = element.getAttribute('data-branches');
          return payload ? JSON.parse(payload) : [];
        },
        renderHTML: (attrs: IfBlockNodeAttrs) => ({ 'data-branches': JSON.stringify(attrs.branches || []) }),
      },
    };
  },

  parseHTML() {
    return [
      {
        tag: 'div[data-if-block]',
      },
    ];
  },

  renderHTML({ node }) {
    return [
      'div',
      mergeAttributes({
        'data-if-block': 'true',
        'data-id': node.attrs.id,
        'data-branches': JSON.stringify(node.attrs.branches || []),
      }),
    ];
  },

  addNodeView() {
    return VueNodeViewRenderer(IfBlockView);
  },
});
