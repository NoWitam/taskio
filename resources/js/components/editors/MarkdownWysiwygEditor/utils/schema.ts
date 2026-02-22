/**
 * Tiptap schema z custom extensions
 */

import { Node as TiptapNode, mergeAttributes } from '@tiptap/core';
import { VueNodeViewRenderer } from '@tiptap/vue-3';
import type { EditorConfig } from '../types';
import MentionNodeView from '../panels/MentionNodeView.vue';
import VariableNodeView from '../panels/VariableNodeView.vue';

/**
 * Custom Mention node
 */
export const MentionNode = TiptapNode.create({
  name: 'mention',
  group: 'inline',
  inline: true,
  atom: true,
  draggable: false,

  addAttributes() {
    return {
      id: {
        default: null,
        parseHTML: (element) => element.getAttribute('data-id'),
        renderHTML: (attributes) => ({
          'data-id': attributes.id,
        }),
      },
      label: {
        default: null,
        parseHTML: (element) => element.getAttribute('data-label'),
        renderHTML: (attributes) => ({
          'data-label': attributes.label,
        }),
      },
    };
  },

  parseHTML() {
    return [
      {
        tag: 'span[data-mention]',
        getAttrs: (element: any) => {
          return {
            id: element.getAttribute('data-id'),
            label: element.getAttribute('data-label'),
          };
        },
      },
    ];
  },

  renderHTML({ node }) {
    return [
      'span',
      mergeAttributes(
        {
          'data-mention': true,
          class: 'mention-chip',
          'data-id': node.attrs.id,
          'data-label': node.attrs.label,
        }
      ),
      node.attrs.label,
    ];
  },

  addNodeView() {
    return VueNodeViewRenderer(MentionNodeView);
  },
});

/**
 * Custom Variable node
 */
export const VariableNode = TiptapNode.create({
  name: 'variable',
  group: 'inline',
  inline: true,
  atom: true,
  draggable: false,

  addAttributes() {
    return {
      varId: {
        default: null,
        parseHTML: (element) => element.getAttribute('data-var-id'),
        renderHTML: (attributes) => ({
          'data-var-id': attributes.varId,
        }),
      },
      varName: {
        default: null,
        parseHTML: (element) => element.getAttribute('data-var-name'),
        renderHTML: (attributes) => ({
          'data-var-name': attributes.varName,
        }),
      },
      varType: {
        default: null,
        parseHTML: (element) => element.getAttribute('data-var-type'),
        renderHTML: (attributes) => ({
          'data-var-type': attributes.varType,
        }),
      },
      ops: {
        default: [],
        parseHTML: (element) => {
          const opsJson = element.getAttribute('data-ops');
          return opsJson ? JSON.parse(opsJson) : [];
        },
        renderHTML: (attributes) => ({
          'data-ops': JSON.stringify(attributes.ops || []),
        }),
      },
    };
  },

  parseHTML() {
    return [
      {
        tag: 'span[data-variable]',
        getAttrs: (element: any) => {
          return {
            varId: element.getAttribute('data-var-id'),
            varName: element.getAttribute('data-var-name'),
            varType: element.getAttribute('data-var-type'),
            ops: element.getAttribute('data-ops')
              ? JSON.parse(element.getAttribute('data-ops'))
              : [],
          };
        },
      },
    ];
  },

  renderHTML({ node }) {
    let label = `{{${node.attrs.varId}`;
    if (node.attrs.ops && node.attrs.ops.length > 0) {
      const opsStr = node.attrs.ops
        .map(
          (op: any) =>
            `op:${op.op}${op.args ? `(${op.args.join(',')})` : ''}`
        )
        .join('|');
      label += `|${opsStr}`;
    }
    label += '}}';

    return [
      'span',
      mergeAttributes(
        {
          'data-variable': true,
          class: 'variable-chip',
          'data-var-id': node.attrs.varId,
          'data-var-name': node.attrs.varName,
          'data-var-type': node.attrs.varType,
          'data-ops': JSON.stringify(node.attrs.ops || []),
        }
      ),
      label,
    ];
  },

  addNodeView() {
    return VueNodeViewRenderer(VariableNodeView);
  },
});

/**
 * Custom AI Block node
 */
export const AiBlockNode = TiptapNode.create({
  name: 'aiBlock',
  group: 'block',
  atom: true,
  draggable: false,

  addAttributes() {
    return {
      aiId: {
        default: null,
        parseHTML: (element) => element.getAttribute('data-ai-id'),
        renderHTML: (attributes) => ({
          'data-ai-id': attributes.aiId,
        }),
      },
      botId: {
        default: null,
        parseHTML: (element) => element.getAttribute('data-bot-id'),
        renderHTML: (attributes) => ({
          'data-bot-id': attributes.botId,
        }),
      },
      tags: {
        default: [],
        parseHTML: (element) => {
          const tagsJson = element.getAttribute('data-tags');
          return tagsJson ? JSON.parse(tagsJson) : [];
        },
        renderHTML: (attributes) => ({
          'data-tags': JSON.stringify(attributes.tags || []),
        }),
      },
      prompt: {
        default: '',
        parseHTML: (element) => element.getAttribute('data-prompt'),
        renderHTML: (attributes) => ({
          'data-prompt': attributes.prompt,
        }),
      },
      nestingLevel: {
        default: 0,
        parseHTML: (element) => {
          const level = element.getAttribute('data-nesting-level');
          return level ? Number(level) : 0;
        },
        renderHTML: (attributes) => ({
          'data-nesting-level': String(attributes.nestingLevel || 0),
        }),
      },
    };
  },

  parseHTML() {
    return [
      {
        tag: 'div[data-ai-block]',
        getAttrs: (element: any) => {
          return {
            aiId: element.getAttribute('data-ai-id'),
            botId: element.getAttribute('data-bot-id'),
            tags: element.getAttribute('data-tags')
              ? JSON.parse(element.getAttribute('data-tags'))
              : [],
            prompt: element.getAttribute('data-prompt'),
            nestingLevel: Number(element.getAttribute('data-nesting-level') || 0),
          };
        },
      },
    ];
  },

  renderHTML({ node }) {
    const tagsStr = node.attrs.tags?.join(', ') || 'no tags';

    return [
      'div',
      mergeAttributes(
        {
          'data-ai-block': true,
          class: 'ai-block',
          'data-ai-id': node.attrs.aiId,
          'data-bot-id': node.attrs.botId,
          'data-tags': JSON.stringify(node.attrs.tags || []),
          'data-prompt': node.attrs.prompt,
          'data-nesting-level': String(node.attrs.nestingLevel || 0),
        }
      ),
      ['div', { class: 'ai-block-header' }, `🤖 AI: ${node.attrs.botId} (${tagsStr})`],
    ];
  },
});

/**
 * Custom Conditional Block node
 */
export const ConditionalBlockNode = TiptapNode.create({
  name: 'conditionalBlock',
  group: 'block',
  atom: true,
  draggable: false,

  addAttributes() {
    return {
      type: {
        default: 'if',
        parseHTML: (element) => element.getAttribute('data-type'),
        renderHTML: (attributes) => ({
          'data-type': attributes.type,
        }),
      },
      condition: {
        default: null,
        parseHTML: (element) => {
          const condJson = element.getAttribute('data-condition');
          return condJson ? JSON.parse(condJson) : null;
        },
        renderHTML: (attributes) => ({
          'data-condition': JSON.stringify(attributes.condition || {}),
        }),
      },
    };
  },

  parseHTML() {
    return [
      {
        tag: 'div[data-conditional-block]',
        getAttrs: (element: any) => {
          return {
            type: element.getAttribute('data-type'),
            condition: element.getAttribute('data-condition')
              ? JSON.parse(element.getAttribute('data-condition'))
              : null,
          };
        },
      },
    ];
  },

  renderHTML({ node }) {
    const labels: Record<string, string> = {
      if: 'IF Condition',
      for: 'FOR Loop',
      switch: 'SWITCH',
    };

    return [
      'div',
      mergeAttributes(
        {
          'data-conditional-block': true,
          class: `conditional-block conditional-${node.attrs.type}`,
          'data-type': node.attrs.type,
          'data-condition': JSON.stringify(node.attrs.condition || {}),
        }
      ),
      [
        'div',
        { class: 'conditional-block-header' },
        labels[node.attrs.type] || node.attrs.type,
      ],
    ];
  },
});
