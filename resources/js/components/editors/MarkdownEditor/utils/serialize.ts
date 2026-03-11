import type { JSONContent } from '@tiptap/core';
import {
  DATA_VERSION,
  type AiTextNodeAttrs,
  type IfBlockState,
  type IfBranchState,
  type MentionNodeAttrs,
  type VariableNodeAttrs,
} from '../types/editor';

export function serializeDocument(doc: JSONContent): string {
  if (!doc || doc.type !== 'doc' || !Array.isArray(doc.content)) return '';

  return doc.content
    .map((node) => serializeNode(node))
    .filter(Boolean)
    .join('\n\n');
}

function serializeNode(node: JSONContent): string {
  switch (node.type) {
    case 'paragraph':
      return serializeInline(node.content ?? []);
    case 'heading': {
      const level = Number(node.attrs?.level ?? 1);
      return `${'#'.repeat(Math.min(Math.max(level, 1), 3))} ${serializeInline(node.content ?? [])}`;
    }
    case 'ifBlock':
      return serializeIfBlock(node.attrs as IfBlockState);
    default:
      return serializeInline(node.content ?? []);
  }
}

function serializeInline(content: JSONContent[]): string {
  return (content || [])
    .map((node) => {
      if (node.type === 'text') {
        return node.text ?? '';
      }
      if (node.type === 'mention') {
        return encodeDirective('mention', node.attrs as MentionNodeAttrs);
      }
      if (node.type === 'variable') {
        return encodeDirective('variable', node.attrs as VariableNodeAttrs);
      }
      if (node.type === 'aiText') {
        return encodeDirective('ai-text', node.attrs as AiTextNodeAttrs);
      }
      return serializeNode(node);
    })
    .join('');
}

function encodeDirective(type: 'mention' | 'variable' | 'ai-text', payload: Record<string, unknown>) {
  const json = JSON.stringify({ v: DATA_VERSION, data: payload });
  const escaped = json.replace(/"/g, '\\"');
  return `@[${type}]("${escaped}")`;
}

function serializeIfBlock(state: IfBlockState): string {
  const header = `\`\`\`if-block ${JSON.stringify({ id: state.id, v: DATA_VERSION })}`;
  const sections = state.branches
    .map((branch) => serializeBranch(branch))
    .join('\n');
  return `${header}\n${sections}\n\`\`\``;
}

function serializeBranch(branch: IfBranchState): string {
  const keyword = branch.kind === 'if' ? 'IF' : branch.kind === 'else-if' ? 'ELSE_IF' : 'ELSE';
  const meta = branch.condition
    ? { id: branch.id, condition: branch.condition }
    : { id: branch.id };
  const condition = branch.kind === 'else' ? ` ${JSON.stringify(meta)}` : ` ${JSON.stringify(meta)}`;
  const header = `[[${keyword}${condition}]]`;
  const bodyDoc = branch.content as JSONContent;
  const body = serializeDocument(bodyDoc);
  return `${header}\n${body}`;
}
