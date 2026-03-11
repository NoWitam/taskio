import type { JSONContent } from '@tiptap/core';
import type {
  AiTextNodeAttrs,
  IfBlockState,
  IfBranchState,
  IfBranchKind,
  IfConditionState,
  MentionNodeAttrs,
  VariableNodeAttrs,
  VariablePipelineStep,
} from '../types/editor';

const HEADING_REGEX = /^(#{1,3})\s+(.*)$/;

export function parseMarkdown(markdown: string): JSONContent {
  const lines = markdown.split(/\r?\n/);
  const content: JSONContent[] = [];
  let index = 0;

  while (index < lines.length) {
    const rawLine = lines[index];
    const line = rawLine.trim();

    if (!line) {
      index += 1;
      continue;
    }

    if (line.startsWith('```if-block')) {
      const { node, nextIndex } = parseIfBlock(lines, index);
      if (node) content.push(node);
      index = nextIndex;
      continue;
    }

    const headingMatch = line.match(HEADING_REGEX);
    if (headingMatch) {
      const level = headingMatch[1].length as 1 | 2 | 3;
      const text = headingMatch[2] ?? '';
      content.push({
        type: 'heading',
        attrs: { level },
        content: parseInline(text),
      });
      index += 1;
      continue;
    }

    // Paragraph (aggregate until blank line or IF block start)
    const paragraphLines: string[] = [];
    while (index < lines.length) {
      const current = lines[index];
      if (!current.trim()) break;
      if (current.trim().startsWith('```if-block')) break;
      paragraphLines.push(current);
      index += 1;
    }

    const paragraphText = paragraphLines.join('\n');
    content.push({
      type: 'paragraph',
      content: parseInline(paragraphText),
    });
  }

  return {
    type: 'doc',
    content: content.length ? content : [{ type: 'paragraph', content: [] }],
  };
}

function parseInline(text: string): JSONContent[] {
  if (!text) return [];
  const nodes: JSONContent[] = [];
  let cursor = 0;

  while (cursor < text.length) {
    const start = text.indexOf('@[', cursor);
    if (start === -1) {
      nodes.push({ type: 'text', text: text.slice(cursor) });
      break;
    }

    if (start > cursor) {
      nodes.push({ type: 'text', text: text.slice(cursor, start) });
    }

    const typeEnd = text.indexOf(']', start);
    if (typeEnd === -1) {
      nodes.push({ type: 'text', text: text.slice(start) });
      break;
    }

    const type = text.slice(start + 2, typeEnd);
    if (text[typeEnd + 1] !== '(' || text[typeEnd + 2] !== '"') {
      nodes.push({ type: 'text', text: text.slice(start, typeEnd + 1) });
      cursor = typeEnd + 1;
      continue;
    }

    const extraction = extractDirectivePayload(text, typeEnd + 2);
    if (!extraction) {
      nodes.push({ type: 'text', text: text.slice(start, typeEnd + 1) });
      cursor = typeEnd + 1;
      continue;
    }

    const payload = decodePayload(extraction.payload);
    if (payload) {
      if (type === 'mention') {
        nodes.push({ type: 'mention', attrs: payload as MentionNodeAttrs });
      } else if (type === 'variable') {
        nodes.push({ type: 'variable', attrs: payload as VariableNodeAttrs });
      } else if (type === 'ai-text') {
        nodes.push({ type: 'aiText', attrs: payload as AiTextNodeAttrs });
      }
      cursor = extraction.nextIndex;
    } else {
      nodes.push({ type: 'text', text: text.slice(start, extraction.nextIndex) });
      cursor = extraction.nextIndex;
    }
  }

  return nodes;
}

function extractDirectivePayload(text: string, quoteIndex: number) {
  let cursor = quoteIndex + 1;
  let payload = '';
  let escaped = false;

  while (cursor < text.length) {
    const char = text[cursor];
    if (char === '\\' && !escaped) {
      escaped = true;
      payload += char;
      cursor += 1;
      continue;
    }

    if (char === '"' && !escaped) {
      if (text[cursor + 1] === ')') {
        return { payload, nextIndex: cursor + 2 };
      }
    }

    payload += char;
    escaped = false;
    cursor += 1;
  }

  return null;
}

function parseIfBlock(lines: string[], startIndex: number) {
  const fenceLine = lines[startIndex];
  const fenceMeta = extractJson(fenceLine.replace('```if-block', '').trim());
  const blockState: IfBlockState = {
    id: fenceMeta?.id || generateId('if'),
    branches: [],
  };

  let index = startIndex + 1;
  let currentBranch: IfBranchState | null = null;
  let buffer: string[] = [];

  const flushBranch = () => {
    if (!currentBranch) return;
    const contentString = buffer.join('\n').trim();
    const parsed = parseMarkdown(contentString);
    currentBranch.content = parsed.content?.length ? parsed : { type: 'doc', content: [{ type: 'paragraph', content: [] }] };
    blockState.branches.push(currentBranch);
    buffer = [];
    currentBranch = null;
  };

  while (index < lines.length) {
    const rawLine = lines[index];
    const line = rawLine.trim();

    if (line === '```') {
      flushBranch();
      index += 1;
      break;
    }

    const branchMatch = line.match(/^\[\[(IF|ELSE_IF|ELSE)(.*)?\]\]$/);
    if (branchMatch) {
      flushBranch();
      const kind = branchKeywordToKind(branchMatch[1]);
      const conditionRaw = branchMatch[2]?.trim();
      const meta = conditionRaw ? extractJson(conditionRaw) : null;
      const branchId = typeof meta === 'object' && meta && 'id' in meta ? (meta as any).id : generateId(kind);

      currentBranch = {
        id: branchId,
        kind,
        content: { type: 'doc', content: [] },
      };

      if (kind !== 'else') {
        const conditionSource = typeof meta === 'object' && meta ? (meta as any).condition || meta : undefined;
        currentBranch.condition = normalizeCondition(conditionSource);
      }

      index += 1;
      continue;
    }

    buffer.push(rawLine);
    index += 1;
  }

  return { node: { type: 'ifBlock', attrs: blockState }, nextIndex: index };
}

function decodePayload(payload: string) {
  try {
    const restored = payload.replace(/\\"/g, '"');
    const parsed = JSON.parse(restored);
    if (parsed && typeof parsed === 'object' && 'data' in parsed) {
      return (parsed as { data: unknown }).data;
    }
    return parsed;
  } catch (error) {
    console.warn('[MarkdownEditor] Failed to parse directive payload', error);
    return null;
  }
}

function extractJson(input: string) {
  if (!input) return null;
  const trimmed = input.trim();
  try {
    return JSON.parse(trimmed);
  } catch (error) {
    console.warn('[MarkdownEditor] Failed to parse JSON block', error);
    return null;
  }
}

function branchKeywordToKind(keyword: string): IfBranchKind {
  switch (keyword) {
    case 'IF':
      return 'if';
    case 'ELSE_IF':
      return 'else-if';
    default:
      return 'else';
  }
}

function normalizeCondition(raw: any): IfConditionState | undefined {
  if (!raw || typeof raw !== 'object') return undefined;
  return {
    variableId: raw.variableId,
    pipeline: Array.isArray(raw.pipeline) ? (raw.pipeline as VariablePipelineStep[]) : [],
    resultType: 'boolean',
  };
}

function generateId(prefix: string) {
  return `${prefix}_${Math.random().toString(36).slice(2, 8)}`;
}
