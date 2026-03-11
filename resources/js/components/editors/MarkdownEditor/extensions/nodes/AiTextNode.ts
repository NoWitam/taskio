import { Node, mergeAttributes } from '@tiptap/core';
import { VueNodeViewRenderer } from '@tiptap/vue-3';
import AiTextChip from '../nodeviews/AiTextChip.vue';
import type { AiTextNodeAttrs } from '../../types/editor';
import { DATA_VERSION } from '../../types/editor';

export const AiTextNode = Node.create({
  name: 'aiText',
  group: 'inline',
  inline: true,
  selectable: false,
  atom: true,

  addAttributes() {
    return {
      id: {
        default: '',
        parseHTML: (element: HTMLElement) => element.getAttribute('data-id') || '',
        renderHTML: (attributes: AiTextNodeAttrs) => ({ 'data-id': attributes.id }),
      },
      personaId: {
        default: null,
        parseHTML: (element: HTMLElement) => element.getAttribute('data-persona-id'),
        renderHTML: (attributes: AiTextNodeAttrs) => ({ 'data-persona-id': attributes.personaId ?? '' }),
      },
      prompt: {
        default: '',
        parseHTML: (element: HTMLElement) => element.getAttribute('data-prompt') || '',
        renderHTML: (attributes: AiTextNodeAttrs) => ({ 'data-prompt': attributes.prompt }),
      },
      labels: {
        default: [],
        parseHTML: (element: HTMLElement) => {
          const payload = element.getAttribute('data-labels');
          return payload ? JSON.parse(payload) : [];
        },
        renderHTML: (attributes: AiTextNodeAttrs) => ({
          'data-labels': JSON.stringify(attributes.labels || []),
        }),
      },
      version: { default: DATA_VERSION },
    } satisfies Record<keyof AiTextNodeAttrs | 'version', any>;
  },

  parseHTML() {
    return [
      {
        tag: 'span[data-ai-text]',
      },
    ];
  },

  renderHTML({ node }) {
    return [
      'span',
      mergeAttributes(
        {
          'data-ai-text': 'true',
          'data-id': node.attrs.id,
          'data-persona-id': node.attrs.personaId,
          'data-prompt': node.attrs.prompt,
          'data-labels': JSON.stringify(node.attrs.labels || []),
        }
      ),
      'AI',
    ];
  },

  addNodeView() {
    return VueNodeViewRenderer(AiTextChip);
  },
});
