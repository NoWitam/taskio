/**
 * Deserialize - konwersja custom markdown string na dokument Tiptap
 */

import { Fragment, Schema } from '@tiptap/pm/model';
import type { Node } from '@tiptap/pm/model';

/**
 * Konwertuj markdown bezpośrednio na editor JSON (bez parametru schema)
 */
export function markdownToEditorJSON(markdown: string): { type: string; content: any[] } {
  return deserializeMarkdown(markdown);
}

/**
 * Sparsuj markdown string na dokument JSON
 */
export function deserializeMarkdown(markdown: string, schema?: Schema): { type: string; content: any[] } {
  const lines = markdown.split('\n');
  const content: any[] = [];
  let i = 0;

  while (i < lines.length) {
    const line = lines[i];

    // Skip empty lines
    if (!line.trim()) {
      i++;
      continue;
    }

    // Headings
    if (line.match(/^#{1,6}\s/)) {
      const level = line.match(/^#+/)![0].length;
      const text = line.replace(/^#+\s/, '');
      content.push({
        type: 'heading',
        attrs: { level },
        content: [{ type: 'text', text }],
      });
      i++;
      continue;
    }

    // Code block
    if (line.trim().startsWith('```')) {
      const codeLines: string[] = [];
      i++;
      while (i < lines.length && !lines[i].trim().startsWith('```')) {
        codeLines.push(lines[i]);
        i++;
      }
      i++; // Skip closing ```
      content.push({
        type: 'code_block',
        content: [{ type: 'text', text: codeLines.join('\n') }],
      });
      continue;
    }

    // Blockquote
    if (line.startsWith('> ')) {
      const quoteText = line.replace(/^>\s/, '');
      content.push({
        type: 'blockquote',
        content: [
          {
            type: 'paragraph',
            content: [{ type: 'text', text: quoteText }],
          },
        ],
      });
      i++;
      continue;
    }

    // Horizontal rule
    if (line.trim() === '---') {
      content.push({ type: 'horizontal_rule' });
      i++;
      continue;
    }

    // Conditional blocks (IF/FOR/SWITCH)
    if (line.includes('{{#if')) {
      const { block, nextIndex } = parseConditionalBlock(lines, i);
      if (block) content.push(block);
      i = nextIndex;
      continue;
    }

    if (line.includes('{{#for')) {
      const { block, nextIndex } = parseForBlock(lines, i);
      if (block) content.push(block);
      i = nextIndex;
      continue;
    }

    if (line.includes('{{#switch')) {
      const { block, nextIndex } = parseSwitchBlock(lines, i);
      if (block) content.push(block);
      i = nextIndex;
      continue;
    }

    // Normal paragraph
    const paragraph = parseInline(line);
    if (paragraph.content.length > 0) {
      content.push({
        type: 'paragraph',
        content: paragraph.content,
      });
    }

    i++;
  }

  // Ensure document is not empty
  if (content.length === 0) {
    content.push({
      type: 'paragraph',
      content: [],
    });
  }

  return {
    type: 'doc',
    content,
  };
}

/**
 * Sparsuj inline content (mentions, variables, AI blocks, text s marks)
 */
function parseInline(text: string): { content: any[] } {
  const content: any[] = [];
  let remaining = text;

  const tokenRegex = /@\[user:[^\]]+\]|\{\{var:[^}]+\}\}|\{\{ai:[^}]+\}\}|\*\*[^*]+\*\*|\*[^*]+\*|__[^_]+__|`[^`]+`|\[[^\]]+\]\([^)]+\)|!\[[^\]]*\]\([^)]+\)/g;

  let lastIndex = 0;

  let match;
  while ((match = tokenRegex.exec(remaining)) !== null) {
    const token = match[0];
    const index = match.index;

    // Tekst przed tokenem
    if (index > lastIndex) {
      const textBefore = remaining.substring(lastIndex, index);
      content.push({ type: 'text', text: textBefore });
    }

    // Sparsuj token
    if (token.startsWith('@[user:')) {
      const mention = parseMentionToken(token);
      if (mention) {
        content.push({
          type: 'mention',
          attrs: { id: mention.id, label: mention.label },
        });
      }
    } else if (token.startsWith('{{var:')) {
      const variable = parseVariableToken(token);
      if (variable) {
        content.push({
          type: 'variable',
          attrs: { varId: variable.varId, ops: variable.ops },
        });
      }
    } else if (token.startsWith('{{ai:')) {
      const aiBlock = parseAiToken(token);
      if (aiBlock) {
        content.push({
          type: 'aiBlock',
          attrs: aiBlock,
        });
      }
    } else if (token.startsWith('**') && token.endsWith('**')) {
      content.push({
        type: 'text',
        text: token.slice(2, -2),
        marks: [{ type: 'bold' }],
      });
    } else if (token.startsWith('*') && token.endsWith('*') && !token.startsWith('**')) {
      content.push({
        type: 'text',
        text: token.slice(1, -1),
        marks: [{ type: 'italic' }],
      });
    } else if (token.startsWith('__') && token.endsWith('__')) {
      content.push({
        type: 'text',
        text: token.slice(2, -2),
        marks: [{ type: 'underline' }],
      });
    } else if (token.startsWith('`') && token.endsWith('`')) {
      content.push({
        type: 'text',
        text: token.slice(1, -1),
        marks: [{ type: 'code' }],
      });
    } else if (token.startsWith('[') && token.includes('](')) {
      const linkMatch = token.match(/\[([^\]]+)\]\(([^)]+)\)/);
      if (linkMatch) {
        content.push({
          type: 'text',
          text: linkMatch[1],
          marks: [{ type: 'link', attrs: { href: linkMatch[2] } }],
        });
      }
    } else if (token.startsWith('![')) {
      const imgMatch = token.match(/!\[([^\]]*)\]\(([^)]+)\)/);
      if (imgMatch) {
        const src = imgMatch[2];
        const alt = imgMatch[1];
        if (src.startsWith('disk://')) {
          content.push({
            type: 'image',
            attrs: { diskId: src.replace('disk://', ''), alt },
          });
        } else {
          content.push({
            type: 'image',
            attrs: { src, alt },
          });
        }
      }
    }

    lastIndex = match.index + token.length;
  }

  // Tekst po ostatnim tokenie
  if (lastIndex < remaining.length) {
    const textAfter = remaining.substring(lastIndex);
    if (textAfter) {
      content.push({ type: 'text', text: textAfter });
    }
  }

  return { content };
}

/**
 * Sparsuj mention token
 */
function parseMentionToken(token: string): { id: string; label: string } | null {
  const match = token.match(/@\[user:([^|]+)\|([^\]]+)\]/);
  if (!match) return null;
  return { id: match[1], label: match[2] };
}

/**
 * Sparsuj variable token
 */
function parseVariableToken(token: string): { varId: string; ops: any[] } | null {
  const match = token.match(/\{\{var:([^|}\]]+)(?:\|(.+?))?\}\}/);
  if (!match) return null;

  const varId = match[1];
  const opsStr = match[2];

  const ops = opsStr ? parseOpsFromString(opsStr) : [];

  return { varId, ops };
}

/**
 * Sparsuj operacje z stringa
 */
function parseOpsFromString(opsStr: string): any[] {
  const operations: any[] = [];
  const parts = opsStr.split('|');

  for (const part of parts) {
    const match = part.match(/op:(\w+)(?:\(([^)]*)\))?/);
    if (!match) continue;

    const opName = match[1];
    const argsStr = match[2];

    const args = argsStr
      ? argsStr
          .split(',')
          .map((a) => {
            const trimmed = a.trim();
            if (trimmed.startsWith('"') && trimmed.endsWith('"')) {
              return trimmed.slice(1, -1);
            }
            return trimmed;
          })
      : [];

    operations.push({
      op: opName,
      args: args.length > 0 ? args : undefined,
    });
  }

  return operations;
}

/**
 * Sparsuj AI token
 */
function parseAiToken(token: string): any | null {
  const match = token.match(
    /\{\{ai:([^|]+)\|bot:([^|]+)\|tags:([^|]*)\|prompt:"([^"]*)"(?:\|nestingLevel:(\d+))?\}\}/
  );
  if (!match) return null;

  return {
    aiId: match[1],
    botId: match[2],
    tags: match[3] ? match[3].split(',') : [],
    prompt: match[4],
    nestingLevel: match[5] ? Number(match[5]) : 0,
  };
}

/**
 * Sparsuj warunkowy blok
 */
function parseConditionalBlock(lines: string[], startIndex: number): { block: any | null; nextIndex: number } {
  const line = lines[startIndex];
  const match = line.match(/\{\{#if\s+condition:"([^"]+)"\}\}/);
  if (!match) return { block: null, nextIndex: startIndex };

  const condition = match[1];
  let i = startIndex + 1;
  const ifContent: string[] = [];
  let elseContent: string[] = [];
  let inElse = false;

  while (i < lines.length) {
    const currentLine = lines[i];
    if (currentLine.includes('{{#else}}')) {
      inElse = true;
      i++;
      continue;
    }
    if (currentLine.includes('{{/if}}')) {
      break;
    }
    if (inElse) {
      elseContent.push(currentLine);
    } else {
      ifContent.push(currentLine);
    }
    i++;
  }

  return {
    block: {
      type: 'conditionalBlock',
      attrs: { type: 'if', condition: { expr: condition } },
    },
    nextIndex: i + 1,
  };
}

/**
 * Sparsuj FOR blok
 */
function parseForBlock(lines: string[], startIndex: number): { block: any | null; nextIndex: number } {
  const line = lines[startIndex];
  const match = line.match(/\{\{#for\s+item:"([^"]+)"\}\}/);
  if (!match) return { block: null, nextIndex: startIndex };

  const varId = match[1];
  let i = startIndex + 1;

  while (i < lines.length) {
    if (lines[i].includes('{{/for}}')) {
      break;
    }
    i++;
  }

  return {
    block: {
      type: 'conditionalBlock',
      attrs: { type: 'for', condition: { varId } },
    },
    nextIndex: i + 1,
  };
}

/**
 * Sparsuj SWITCH blok
 */
function parseSwitchBlock(lines: string[], startIndex: number): { block: any | null; nextIndex: number } {
  const line = lines[startIndex];
  const match = line.match(/\{\{#switch\s+expr:"([^"]+)"\}\}/);
  if (!match) return { block: null, nextIndex: startIndex };

  const varId = match[1];
  let i = startIndex + 1;

  while (i < lines.length) {
    if (lines[i].includes('{{/switch}}')) {
      break;
    }
    i++;
  }

  return {
    block: {
      type: 'conditionalBlock',
      attrs: { type: 'switch', condition: { varId } },
    },
    nextIndex: i + 1,
  };
}
