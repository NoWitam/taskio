// aiText.ts — the AI-text PART 2 node: an inline chip marking AI-generated /
// AI-insertable text. It carries a `personaId`, a `prompt` (markdown, may itself
// contain directives + if-blocks per FORMAT.md) and optional `labels`.
//
// SHAPE: inline, atomic, selectable; rendered via the `AiTextChip` NodeView
// (which owns its Modal edit panel: persona Select + a NESTED MarkdownEditor for
// the prompt + labels multi-select). Serializes to the legacy directive
// `@[ai-text]("{id,personaId,prompt,labels,v}")` (FORMAT.md). Adds an
// `insertAiText` command for the toolbar button.
import { Node, mergeAttributes } from '@tiptap/core';
import { VueNodeViewRenderer } from '@tiptap/vue-3';
import AiTextChip from './AiTextChip.vue';
import {
  registerMarkdownNode,
  registerInlineDirective,
  type JSONNode,
} from '../markdown';
import {
  DATA_VERSION,
  generateId,
  type AiPersona,
  type AiLabelOption,
  type AiTextNodeAttrs,
} from './types';

export interface AiTextOptions {
  personas?: AiPersona[];
  labelsEnabled?: boolean;
  labelsCatalog?: AiLabelOption[];
}

declare module '@tiptap/core' {
  interface Commands<ReturnType> {
    aiText: {
      insertAiText: (attrs?: Partial<AiTextNodeAttrs>) => ReturnType;
    };
  }
}

function encodeAiTextDirective(attrs: AiTextNodeAttrs): string {
  const data = {
    id: attrs.id,
    personaId: attrs.personaId ?? null,
    prompt: attrs.prompt ?? '',
    labels: attrs.labels ?? [],
  };
  const json = JSON.stringify({ v: DATA_VERSION, data });
  return `@[ai-text]("${json.replace(/"/g, '\\"')}")`;
}

let registered = false;
function ensureMarkdownRegistered(): void {
  if (registered) return;
  registered = true;
  registerMarkdownNode('aiText', {
    toMarkdown(node) {
      return encodeAiTextDirective(node.attrs as unknown as AiTextNodeAttrs);
    },
  });
  registerInlineDirective({
    keyword: 'ai-text',
    toNode(payload): JSONNode | null {
      const p = payload as (Partial<AiTextNodeAttrs> & { persona?: string | null }) | null;
      if (!p) return null;
      return {
        type: 'aiText',
        attrs: {
          id: p.id ?? generateId('ai'),
          // FORMAT sample uses `persona`; legacy node uses `personaId` — accept both.
          personaId: p.personaId ?? p.persona ?? null,
          prompt: p.prompt ?? '',
          labels: Array.isArray(p.labels) ? p.labels : [],
        },
      };
    },
  });
}

ensureMarkdownRegistered();

export function createAiText(options: AiTextOptions = {}) {
  return Node.create({
    name: 'aiText',
    group: 'inline',
    inline: true,
    atom: true,
    selectable: true,

    addStorage() {
      return {
        personas: options.personas ?? [],
        labelsEnabled: options.labelsEnabled ?? false,
        labelsCatalog: options.labelsCatalog ?? [],
      };
    },

    addAttributes() {
      return {
        id: { default: '' },
        personaId: { default: null },
        prompt: { default: '' },
        labels: { default: [] },
      };
    },

    parseHTML() {
      return [{ tag: 'span[data-ai-text]' }];
    },

    renderHTML({ node }) {
      return [
        'span',
        mergeAttributes({
          'data-ai-text': 'true',
          'data-id': node.attrs.id,
        }),
        'AI',
      ];
    },

    addNodeView() {
      return VueNodeViewRenderer(AiTextChip as never);
    },

    addCommands() {
      return {
        insertAiText:
          (attrs = {}) =>
          ({ commands }) =>
            commands.insertContent({
              type: 'aiText',
              attrs: {
                id: attrs.id || generateId('ai'),
                personaId: attrs.personaId ?? null,
                prompt: attrs.prompt ?? '',
                labels: attrs.labels ?? [],
              },
            }),
      };
    },
  });
}
