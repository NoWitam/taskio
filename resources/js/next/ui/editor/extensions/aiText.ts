// aiText.ts — the AI-text PART 2 node: an inline chip marking AI-generated /
// AI-insertable text. It carries the per-block AUTHOR (`authorId` + a display-only
// `authorName` snapshot), a LEGACY `personaId` tone, a `prompt` (markdown, may
// itself contain directives + if-blocks per FORMAT.md) and `labels` (carried, no
// longer edited).
//
// SHAPE: inline, atomic, selectable; rendered via the `AiTextChip` NodeView
// (which owns its Modal edit panel: an Author BotSelect + a NESTED MarkdownEditor
// for the prompt). Serializes to the legacy directive
// `@[ai-text]("{id,personaId,authorId?,authorName?,prompt,labels,v}")` (FORMAT.md)
// — the two author keys are EMIT-OR-OMIT so an author-less block stays byte-identical
// to a pre-author document. Adds an `insertAiText` command for the toolbar button.
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

/**
 * Serialize an aiText node to the legacy directive.
 *
 * `authorId` / `authorName` are EMIT-OR-OMIT: when the block has no author they are not written at
 * all, so an author-less block keeps producing the EXACT pre-author bytes (the FORMAT.md parity
 * assertions in `directives.spec.ts` cover this). The backend has NO alias for the author key — it
 * reads `authorId` and nothing else, so anything written under a different name would silently
 * degrade the block to the neutral default tone.
 */
function encodeAiTextDirective(attrs: AiTextNodeAttrs): string {
  const data: Record<string, unknown> = {
    id: attrs.id,
    personaId: attrs.personaId ?? null,
  };
  if (attrs.authorId != null && attrs.authorId !== '') {
    data.authorId = attrs.authorId;
    // The display snapshot only travels alongside a real author id.
    if (attrs.authorName != null && attrs.authorName !== '') {
      data.authorName = attrs.authorName;
    }
  }
  data.prompt = attrs.prompt ?? '';
  data.labels = attrs.labels ?? [];
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
          // The per-block AUTHOR. NO alias here on purpose: the backend reads `authorId` only.
          authorId: typeof p.authorId === 'string' && p.authorId !== '' ? p.authorId : null,
          authorName:
            typeof p.authorName === 'string' && p.authorName !== '' ? p.authorName : null,
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
        authorId: { default: null },
        authorName: { default: null },
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
                authorId: attrs.authorId ?? null,
                authorName: attrs.authorName ?? null,
                prompt: attrs.prompt ?? '',
                labels: attrs.labels ?? [],
              },
            }),
      };
    },
  });
}
