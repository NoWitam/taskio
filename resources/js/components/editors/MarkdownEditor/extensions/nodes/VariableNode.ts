import { Node, mergeAttributes } from '@tiptap/core';
import { VueNodeViewRenderer } from '@tiptap/vue-3';
import VariableChip from '../nodeviews/VariableChip.vue';
import type { VariableNodeAttrs } from '../../types/editor';
import { DATA_VERSION } from '../../types/editor';

export const VariableNode = Node.create({
  name: 'variable',
  group: 'inline',
  inline: true,
  selectable: false,
  atom: true,

  addAttributes() {
    return {
      id: {
        default: '',
        parseHTML: (element: HTMLElement) => element.getAttribute('data-id') || '',
        renderHTML: (attributes: VariableNodeAttrs) => ({ 'data-id': attributes.id }),
      },
      name: {
        default: '',
        parseHTML: (element: HTMLElement) => element.getAttribute('data-name') || '',
        renderHTML: (attributes: VariableNodeAttrs) => ({ 'data-name': attributes.name }),
      },
      type: {
        default: 'text',
        parseHTML: (element: HTMLElement) => element.getAttribute('data-type') || 'text',
        renderHTML: (attributes: VariableNodeAttrs) => ({ 'data-type': attributes.type }),
      },
      locked: {
        default: false,
        parseHTML: (element: HTMLElement) => element.getAttribute('data-locked') === 'true',
        renderHTML: (attributes: VariableNodeAttrs) => ({ 'data-locked': String(attributes.locked) }),
      },
      pipeline: {
        default: [],
        parseHTML: (element: HTMLElement) => {
          const payload = element.getAttribute('data-pipeline');
          return payload ? JSON.parse(payload) : [];
        },
        renderHTML: (attributes: VariableNodeAttrs) => ({
          'data-pipeline': JSON.stringify(attributes.pipeline || []),
        }),
      },
      resultType: {
        default: 'text',
        parseHTML: (element: HTMLElement) => element.getAttribute('data-result-type') || 'text',
        renderHTML: (attributes: VariableNodeAttrs) => ({ 'data-result-type': attributes.resultType }),
      },
      version: { default: DATA_VERSION },
    } satisfies Record<keyof VariableNodeAttrs | 'version', any>;
  },

  parseHTML() {
    return [
      {
        tag: 'span[data-variable]',
      },
    ];
  },

  renderHTML({ node }) {
    return [
      'span',
      mergeAttributes(
        {
          'data-variable': 'true',
          'data-id': node.attrs.id,
          'data-name': node.attrs.name,
          'data-type': node.attrs.type,
          'data-locked': String(node.attrs.locked),
          'data-pipeline': JSON.stringify(node.attrs.pipeline || []),
          'data-result-type': node.attrs.resultType,
        }
      ),
      `{{${node.attrs.name || node.attrs.id}}}`,
    ];
  },

  addNodeView() {
    return VueNodeViewRenderer(VariableChip);
  },
});
