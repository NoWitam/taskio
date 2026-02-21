/**
 * Serialize - konwersja dokumentu Tiptap na custom markdown string
 */

import type { Node } from '@tiptap/pm/model';

/**
 * Serializuj cały dokument
 */
export function serializeDocument(doc: any): string {
  if (!doc || !doc.content) return '';
  return serializeNodes(doc.content);
}

/**
 * Serializuj listę nodes (może być Fragment lub array)
 */
function serializeNodes(nodes: any): string {
  // Convert Fragment to array if needed
  const nodeArray = Array.isArray(nodes) ? nodes : nodes.content || [];
  
  let result = '';
  if (nodes.forEach) {
    // Fragment has forEach method
    nodes.forEach((node: Node) => {
      result += serializeNode(node);
    });
  } else if (Array.isArray(nodeArray)) {
    // Regular array
    result = nodeArray.map((node) => serializeNode(node)).join('');
  }
  
  return result;
}

/**
 * Serializuj pojedynczy node
 */
function serializeNode(node: Node): string {
  switch (node.type.name) {
    case 'doc':
      return serializeNodes(node.content as any);

    case 'paragraph':
      return serializeInline(node) + '\n\n';

    case 'heading':
      const level = node.attrs.level;
      const heading = '#'.repeat(level) + ' ' + serializeInline(node);
      return heading + '\n\n';

    case 'bullet_list':
      return serializeList(node, false) + '\n';

    case 'ordered_list':
      return serializeList(node, true) + '\n';

    case 'blockquote':
      return '> ' + serializeInline(node) + '\n\n';

    case 'code_block':
      return '```\n' + (node.textContent || '') + '\n```\n\n';

    case 'mention':
      return `@[user:${node.attrs.id}|${node.attrs.label}]`;

    case 'variable':
      let varToken = `{{var:${node.attrs.varId}`;
      if (node.attrs.ops && node.attrs.ops.length > 0) {
        const opsStr = node.attrs.ops
          .map(
            (op: any) =>
              `op:${op.op}${op.args ? `(${op.args.map((a: string) => `"${a}"`).join(',')})` : ''}`
          )
          .join('|');
        varToken += `|${opsStr}`;
      }
      varToken += '}}';
      return varToken;

    case 'aiBlock':
      const tagsStr = node.attrs.tags?.join(',') ?? '';
      return `{{ai:${node.attrs.aiId}|bot:${node.attrs.botId}|tags:${tagsStr}|prompt:"${node.attrs.prompt}"}}`;

    case 'conditionalBlock':
      switch (node.attrs.type) {
        case 'if':
          return serializeIfBlock(node);
        case 'for':
          return serializeForBlock(node);
        case 'switch':
          return serializeSwitchBlock(node);
        default:
          return '';
      }

    case 'image':
      if (node.attrs.diskId) {
        return `![${node.attrs.alt || ''}](disk://${node.attrs.diskId})`;
      } else {
        return `![${node.attrs.alt || ''}](${node.attrs.src || ''})`;
      }

    case 'hard_break':
      return '\n';

    case 'horizontal_rule':
      return '---\n\n';

    default:
      if (node.content) {
        return serializeNodes(node.content as any);
      }
      return node.textContent || '';
  }
}

/**
 * Serializuj inline nodes (text, marks, etc.)
 */
function serializeInline(node: Node): string {
  let result = '';

  if (!node.content) {
    return node.textContent || '';
  }

  node.content.forEach((child: Node) => {
    if (child.type.name === 'text') {
      let text = child.text || '';

      // Zastosuj marks
      if (child.marks.length > 0) {
        for (const mark of child.marks) {
          switch (mark.type.name) {
            case 'bold':
              text = `**${text}**`;
              break;
            case 'italic':
              text = `*${text}*`;
              break;
            case 'underline':
              text = `__${text}__`;
              break;
            case 'code':
              text = `\`${text}\``;
              break;
            case 'link':
              text = `[${text}](${mark.attrs.href})`;
              break;
          }
        }
      }

      result += text;
    } else {
      result += serializeNode(child);
    }
  });

  return result;
}

/**
 * Serializuj list (bullet/ordered)
 */
function serializeList(node: Node, ordered: boolean): string {
  let result = '';
  let index = 1;

  if (!node.content) return '';

  node.content.forEach((child: Node) => {
    if (child.type.name === 'list_item') {
      const prefix = ordered ? `${index}. ` : '- ';
      const content = serializeInline(child);
      result += prefix + content + '\n';
      if (ordered) index++;

      // Zagnieżdżone listy
      if (child.content) {
        let hasNestedList = false;
        child.content.forEach((n: Node) => {
          if (n.type.name === 'bullet_list' || n.type.name === 'ordered_list') {
            hasNestedList = true;
          }
        });
        
        if (hasNestedList) {
          child.content.forEach((subNode: Node) => {
            if (subNode.type.name === 'bullet_list' || subNode.type.name === 'ordered_list') {
              const indented = serializeList(subNode, subNode.type.name === 'ordered_list')
                .split('\n')
                .filter((line) => line)
                .map((line) => '  ' + line)
                .join('\n');
              result += indented + '\n';
            }
          });
        }
      }
    }
  });

  return result.trim();
}

/**
 * Serializuj IF blok
 */
function serializeIfBlock(node: Node): string {
  const condition = node.attrs.condition?.expr || '';
  // W tym miejscu możesz wstawić logikę renderowania zawartości
  return `{{#if condition:"${condition}"}}{{/if}}\n\n`;
}

/**
 * Serializuj FOR blok
 */
function serializeForBlock(node: Node): string {
  const varId = node.attrs.condition?.varId || '';
  return `{{#for item:"${varId}"}}{{/for}}\n\n`;
}

/**
 * Serializuj SWITCH blok
 */
function serializeSwitchBlock(node: Node): string {
  const varId = node.attrs.condition?.varId || '';
  return `{{#switch expr:"${varId}"}}{{/switch}}\n\n`;
}

/**
 * Sparsuj mention token: @[user:ID|LABEL]
 */
export function parseMentionToken(token: string): { id: string; label: string } | null {
  const match = token.match(/@\[user:([^|]+)\|([^\]]+)\]/);
  if (!match) return null;
  return { id: match[1], label: match[2] };
}

/**
 * Sparsuj variable token: {{var:ID|op:...}}
 */
export function parseVariableToken(token: string): { varId: string; ops: any[] } | null {
  const match = token.match(/\{\{var:([^|}\]]+)(?:\|(.+?))?\}\}/);
  if (!match) return null;

  const varId = match[1];
  const opsStr = match[2];

  // Sparsuj operacje
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
