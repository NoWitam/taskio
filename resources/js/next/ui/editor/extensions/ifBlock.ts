// ifBlock.ts — the conditional `if-block` PART 2 nodes.
//
// SHAPE (reworked for inline editing): TWO nodes.
//   • `ifBlock`   — a block CONTAINER whose content is `ifBranch+`. Carries `id`.
//   • `ifBranch`  — a block node with attrs `{ id, kind, condition }` and an
//                   EDITABLE body (`content: 'block+'`). The user edits branch
//                   text INLINE with the full editor (paragraphs, marks, mentions,
//                   variables, ai, even nested if-blocks). Rendered via a Vue
//                   NodeView with a `contentDOM` so the body is a real editor
//                   region — NOT a nested markdown textarea.
//
// DEPTH CAP: an `ifBlock` may be nested inside an `ifBranch` only while depth <
// maxDepth (default 3). The insert command + paste/drop are blocked past that.
//
// SERIALIZES to the legacy fenced form EXACTLY (FORMAT.md), reading branch content
// from the node structure (ifBlock → ifBranch children → block bodies):
//
//   ```if-block {"id":"if_1","v":1}
//   [[IF {"id":"b1","condition":{…}}]]
//   …branch body markdown…
//   [[ELSE]]
//   …branch body markdown…
//   ```
import { Node, mergeAttributes } from '@tiptap/core';
import { Plugin, PluginKey } from '@tiptap/pm/state';
import type { Node as PMNode } from '@tiptap/pm/model';
import { VueNodeViewRenderer } from '@tiptap/vue-3';
import IfBlockView from './IfBlockView.vue';
import IfBranchView from './IfBranchView.vue';
import {
  registerMarkdownNode,
  registerIfBlockParser,
  type JSONNode,
  type MarkdownSerializeContext,
} from '../markdown';
import {
  DATA_VERSION,
  generateId,
  type IfBranchKind,
  type IfConditionState,
} from './types';

declare module '@tiptap/core' {
  interface Commands<ReturnType> {
    ifBlock: {
      insertIfBlock: () => ReturnType;
    };
  }
}

export const DEFAULT_MAX_DEPTH = 3;

const KEYWORD: Record<IfBranchKind, string> = {
  if: 'IF',
  'else-if': 'ELSE_IF',
  else: 'ELSE',
};

function keywordToKind(keyword: string): IfBranchKind {
  if (keyword === 'IF') return 'if';
  if (keyword === 'ELSE_IF') return 'else-if';
  return 'else';
}

// --- serialize (legacy serializeIfBlock / serializeBranch parity) -----------
// `node` is the ifBlock JSON: { type:'ifBlock', attrs:{id}, content: ifBranch[] }.
function serializeIfBlock(node: JSONNode, ctx: MarkdownSerializeContext): string {
  const id = (node.attrs?.id as string) || generateId('if');
  const header = '```if-block ' + JSON.stringify({ id, v: DATA_VERSION });
  const sections = (node.content ?? [])
    .filter((b) => b.type === 'ifBranch')
    .map((branch) => serializeBranch(branch, ctx))
    .join('\n');
  return `${header}\n${sections}\n\`\`\``;
}

function serializeBranch(branch: JSONNode, ctx: MarkdownSerializeContext): string {
  const attrs = branch.attrs ?? {};
  const kind = (attrs.kind as IfBranchKind) ?? 'if';
  const condition = (attrs.condition as IfConditionState | null) ?? null;
  const keyword = KEYWORD[kind];
  const meta = condition
    ? { id: attrs.id, condition }
    : { id: attrs.id };
  const head = `[[${keyword} ${JSON.stringify(meta)}]]`;
  const body = ctx.serializeBlocks(branch.content ?? []);
  return `${head}\n${body}`;
}

// --- parse (legacy parseIfBlock parity) -------------------------------------
function parseIfBlockBody(
  meta: Record<string, unknown> | null,
  bodyLines: string[],
  parseBody: (md: string) => JSONNode[],
): JSONNode {
  const branches: JSONNode[] = [];
  let current: { id: string; kind: IfBranchKind; condition: IfConditionState | null } | null = null;
  let buffer: string[] = [];

  const flush = (): void => {
    if (!current) return;
    const content = parseBody(buffer.join('\n').trim());
    branches.push({
      type: 'ifBranch',
      attrs: { id: current.id, kind: current.kind, condition: current.condition },
      content: content.length ? content : [{ type: 'paragraph' }],
    });
    buffer = [];
    current = null;
  };

  const BRANCH_RE = /^\[\[(IF|ELSE_IF|ELSE)(.*)?\]\]$/;
  const IF_FENCE = /^```if-block\b/;
  let fenceDepth = 0;
  for (const raw of bodyLines) {
    const line = raw.trim();
    if (IF_FENCE.test(line)) {
      fenceDepth += 1;
      buffer.push(raw);
      continue;
    }
    if (line === '```' && fenceDepth > 0) {
      fenceDepth -= 1;
      buffer.push(raw);
      continue;
    }
    const m = fenceDepth === 0 ? line.match(BRANCH_RE) : null;
    if (m) {
      flush();
      const kind = keywordToKind(m[1]);
      const metaJson = m[2]?.trim() ? safeParse(m[2].trim()) : null;
      const id =
        metaJson && typeof metaJson === 'object' && 'id' in metaJson
          ? String((metaJson as { id: unknown }).id)
          : generateId(kind);
      let condition: IfConditionState | null = null;
      if (kind !== 'else') {
        const condSource =
          metaJson && typeof metaJson === 'object'
            ? ((metaJson as Record<string, unknown>).condition ?? metaJson)
            : undefined;
        condition = normalizeCondition(condSource);
      }
      current = { id, kind, condition };
      continue;
    }
    buffer.push(raw);
  }
  flush();

  return {
    type: 'ifBlock',
    attrs: {
      id:
        meta && typeof meta.id === 'string' && meta.id ? meta.id : generateId('if'),
    },
    content: branches.length
      ? branches
      : [
          {
            type: 'ifBranch',
            attrs: { id: generateId('if'), kind: 'if', condition: null },
            content: [{ type: 'paragraph' }],
          },
        ],
  };
}

function normalizeCondition(raw: unknown): IfConditionState | null {
  if (!raw || typeof raw !== 'object') return null;
  const r = raw as Record<string, unknown>;
  if (typeof r.variableId !== 'string') return null;
  return {
    variableId: r.variableId,
    pipeline: Array.isArray(r.pipeline) ? (r.pipeline as IfConditionState['pipeline']) : [],
    resultType: 'boolean',
  };
}

function safeParse(input: string): unknown {
  try {
    return JSON.parse(input);
  } catch {
    return null;
  }
}

let mdRegistered = false;
function ensureMarkdownRegistered(): void {
  if (mdRegistered) return;
  mdRegistered = true;
  registerMarkdownNode('ifBlock', {
    toMarkdown(node, ctx) {
      return serializeIfBlock(node, ctx);
    },
  });
  // The branch body itself never serializes standalone (only via the ifBlock
  // handler), but register a passthrough so a stray ifBranch degrades to its body.
  registerMarkdownNode('ifBranch', {
    toMarkdown(node, ctx) {
      return ctx.serializeBlocks(node.content ?? []);
    },
  });
  registerIfBlockParser({ toNode: parseIfBlockBody });
}

ensureMarkdownRegistered();

// --- depth helpers ----------------------------------------------------------
/** Count ancestor ifBlock nodes at a position (the depth of an INSERT there). */
export function ifBlockDepthAt($pos: import('@tiptap/pm/model').ResolvedPos): number {
  let count = 0;
  for (let d = $pos.depth; d > 0; d -= 1) {
    if ($pos.node(d).type.name === 'ifBlock') count += 1;
  }
  return count;
}

function countIfBlockAncestorsOfNode(doc: PMNode, pos: number): number {
  const $pos = doc.resolve(pos);
  return ifBlockDepthAt($pos);
}

export interface IfBlockOptions {
  maxElseIf: number;
  maxDepth: number;
}

function defaultBranchNodes(): JSONNode[] {
  return [
    {
      type: 'ifBranch',
      attrs: {
        id: generateId('if'),
        kind: 'if',
        condition: { variableId: '', pipeline: [], resultType: 'boolean' },
      },
      content: [{ type: 'paragraph' }],
    },
  ];
}

export function createIfBlock(options: Partial<IfBlockOptions> = {}) {
  const maxElseIf = options.maxElseIf ?? Number.POSITIVE_INFINITY;
  const maxDepth = options.maxDepth ?? DEFAULT_MAX_DEPTH;

  const branch = Node.create({
    name: 'ifBranch',
    group: 'block',
    content: 'block+',
    defining: true,
    isolating: true,
    selectable: false,

    addAttributes() {
      return {
        id: { default: '' },
        kind: { default: 'if' },
        condition: { default: null },
      };
    },

    parseHTML() {
      return [{ tag: 'div[data-if-branch]' }];
    },

    renderHTML({ node }) {
      return [
        'div',
        mergeAttributes({ 'data-if-branch': 'true', 'data-kind': node.attrs.kind }),
        0,
      ];
    },

    addNodeView() {
      return VueNodeViewRenderer(IfBranchView as never);
    },
  });

  const block = Node.create({
    name: 'ifBlock',
    group: 'block',
    content: 'ifBranch+',
    selectable: true,
    draggable: false,

    addStorage() {
      return { maxElseIf, maxDepth };
    },

    addAttributes() {
      return {
        id: { default: '' },
      };
    },

    parseHTML() {
      return [{ tag: 'div[data-if-block]' }];
    },

    renderHTML({ node }) {
      return [
        'div',
        mergeAttributes({ 'data-if-block': 'true', 'data-id': node.attrs.id }),
        0,
      ];
    },

    addNodeView() {
      return VueNodeViewRenderer(IfBlockView as never);
    },

    addCommands() {
      return {
        insertIfBlock:
          () =>
          ({ state, chain }) => {
            // Depth cap: refuse if the selection is already at maxDepth.
            const depth = ifBlockDepthAt(state.selection.$from);
            if (depth >= maxDepth) return false;
            return chain()
              .insertContent({
                type: 'ifBlock',
                attrs: { id: generateId('if') },
                content: defaultBranchNodes(),
              })
              .run();
          },
      };
    },

    addProseMirrorPlugins() {
      // Block paste/drop that would push an ifBlock past maxDepth.
      return [
        new Plugin({
          key: new PluginKey('next-ifblock-depth-guard'),
          filterTransaction(tr, state) {
            if (!tr.docChanged) return true;
            let violation = false;
            tr.doc.descendants((node, pos) => {
              if (violation) return false;
              if (node.type.name === 'ifBlock') {
                // depth = number of ifBlock ancestors ABOVE this node + this one.
                const ancestors = countIfBlockAncestorsOfNode(tr.doc, pos);
                if (ancestors + 1 > maxDepth) violation = true;
              }
              return true;
            });
            void state;
            return !violation;
          },
        }),
      ];
    },
  });

  return [branch, block];
}
