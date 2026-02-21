/**
 * Render - konwersja custom markdown na HTML dla preview rendered
 */

import type { VariableDef, AiBot } from '../types';
import { renderVariable, parseOperationsFromString } from './operations';
import { evaluateCondition, parseIfBlock, parseForBlock, parseSwitchBlock } from './conditions';

/**
 * Renderuj markdown string w try Rendered mode
 */
export function renderMarkdown(
  markdown: string,
  variables: VariableDef[],
  aiBots: AiBot[] = []
): string {
  let result = markdown;

  // 1. Renderuj variables z operacjami
  result = renderVariables(result, variables);

  // 2. Renderuj mention chips
  result = renderMentions(result);

  // 3. Renderuj conditional blocks
  result = renderConditionals(result, variables);

  // 4. Renderuj AI blocks
  result = renderAiBlocks(result, aiBots);

  // 5. Konwertuj markdown na HTML (basic)
  result = markdownToHtml(result);

  return result;
}

/**
 * Renderuj variables: {{var:ID|op:...}} → HTML chip z wartością
 */
function renderVariables(markdown: string, variables: VariableDef[]): string {
  const regex = /\{\{var:([^|}\]]+)(?:\|(.+?))?\}\}/g;

  return markdown.replace(regex, (match, varId, opsStr) => {
    const ops = opsStr ? parseOperationsFromString(opsStr) : [];
    const value = renderVariable(variables, varId, ops);
    const varDef = variables.find((v) => v.id === varId);
    const varName = varDef?.name || varId;
    // Uniknij wyświetlania pełnego varId w chipie, pokaż ładną nazwę
    return `<span class="variable-chip-styled" title="${varName}: ${value}">${varName}</span>`;
  });
}

/**
 * Renderuj mentions: @[user:ID|STRING] → HTML chip z ikonką
 */
function renderMentions(markdown: string): string {
  return markdown.replace(/@\[user:([^|]+)\|([^\]]+)\]/g, (match, userId, userName) => {
    const atIcon = `<svg class="mention-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="14" height="14" style="display: inline; vertical-align: middle; margin-right: 2px;"><circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M9 11a3 3 0 1 0 6 0 3 3 0 0 0-6 0z"/><path d="M12.5 7a1.5 1.5 0 0 0-1.5 1.5M12 16.5c2 0 3.5 1 3.5 2.5"/></svg>`;
    return `<span class="mention-chip-styled">${atIcon}${userName}</span>`;
  });
}

/**
 * Renderuj conditional blocks
 */
function renderConditionals(markdown: string, variables: VariableDef[]): string {
  // IF/ELSE
  let result = markdown;
  const ifRegex = /\{\{#if\s+condition:"([^"]+)"\}\}([\s\S]*?)(?:\{\{#else\}\}([\s\S]*?))?\{\{\/if\}\}/g;
  result = result.replace(ifRegex, (match, condition, ifContent, elseContent = '') => {
    const passes = evaluateCondition(condition, variables);
    return passes ? ifContent : elseContent;
  });

  // FOR loops
  const forRegex = /\{\{#for\s+item:"([^"]+)"\}\}([\s\S]*?)\{\{\/for\}\}/g;
  result = result.replace(forRegex, (match, varId, content) => {
    const variable = variables.find((v) => v.id === varId);
    if (!variable || !Array.isArray(variable.value)) {
      return '';
    }
    return variable.value
      .map((item, index) => {
        let itemContent = content;
        // Pozwól na {{item}} lub {{item.property}}
        itemContent = itemContent.replace(/\{\{item\}\}/g, String(item));
        itemContent = itemContent.replace(/\{\{item\.(\w+)\}\}/g, (m, prop) => {
          return typeof item === 'object' ? String(item[prop] ?? '') : '';
        });
        return itemContent;
      })
      .join('');
  });

  // SWITCH
  const switchRegex = /\{\{#switch\s+expr:"([^"]+)"\}\}([\s\S]*?)\{\{\/switch\}\}/g;
  result = result.replace(switchRegex, (match, varId, body) => {
    const variable = variables.find((v) => v.id === varId);
    if (!variable) return '';

    const value = String(variable.value);
    const caseRegex = /\{\{#case\s+value:"([^"]+)"\}\}([\s\S]*?)\{\{\/case\}\}/g;
    const defaultRegex = /\{\{#default\}\}([\s\S]*?)\{\{\/default\}\}/;

    let matched = '';
    let caseMatch;
    while ((caseMatch = caseRegex.exec(body)) !== null) {
      if (caseMatch[1] === value) {
        matched = caseMatch[2];
        break;
      }
    }

    if (!matched) {
      const defaultMatch = body.match(defaultRegex);
      matched = defaultMatch ? defaultMatch[1] : '';
    }

    return matched;
  });

  return result;
}

/**
 * Renderuj AI blocks
 */
function renderAiBlocks(markdown: string, aiBots: AiBot[]): string {
  const regex = /\{\{ai:([^|]+)\|bot:([^|]+)\|tags:([^|]*)\|prompt:"([^"]*)"(?:\|nestingLevel:(\d+))?\}\}/g;

  return markdown.replace(regex, (match, aiId, botId, tagsStr, prompt, nestingLevel) => {
    const bot = aiBots.find((b) => b.id === botId);
    const botName = bot?.name ?? botId;
    const tags = tagsStr ? tagsStr.split(',') : [];
    const tagStr = tags.length > 0 ? ` · ${tags.join(', ')}` : '';
    const nestLevel = nestingLevel ? ` · nesting: ${nestingLevel}` : '';

    return `<div class="ai-block-styled"><div class="ai-block-header-styled">✨ ${botName}${tagStr}${nestLevel}</div><p style="font-size: 0.875rem; color: var(--color-muted-foreground); margin: 0;">${prompt || '(AI Generated Content)'}</p></div>`;
  });
}

/**
 * Konwertuj markdown na HTML (very basic)
 */
function markdownToHtml(markdown: string): string {
  let html = markdown;

  // Headings
  html = html.replace(/^### (.*?)$/gm, '<h3>$1</h3>');
  html = html.replace(/^## (.*?)$/gm, '<h2>$1</h2>');
  html = html.replace(/^# (.*?)$/gm, '<h1>$1</h1>');

  // Blockquotes
  html = html.replace(/^&gt; (.*?)$/gm, '<blockquote><p>$1</p></blockquote>');

  // Code blocks
  html = html.replace(/```([\s\S]*?)```/g, '<pre><code>$1</code></pre>');

  // Bold, Italic, Underline
  html = html.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
  html = html.replace(/\*(.*?)\*/g, '<em>$1</em>');
  html = html.replace(/__(.*?)__/g, '<u>$1</u>');

  // Inline code
  html = html.replace(/`(.*?)`/g, '<code>$1</code>');

  // Links
  html = html.replace(/\[(.*?)\]\((.*?)\)/g, '<a href="$2">$1</a>');

  // Images
  html = html.replace(/!\[(.*?)\]\((.*?)\)/g, '<img src="$2" alt="$1" />');

  // Paragraphs
  const paragraphs = html
    .split('\n\n')
    .map((p) => {
      if (p.match(/<[h|blockquote|pre]/)) return p;
      return `<p>${p}</p>`;
    })
    .join('');

  return paragraphs;
}

/**
 * Export HTML jako string
 */
export function getRenderedHtml(
  markdown: string,
  variables: VariableDef[],
  aiBots: AiBot[] = []
): string {
  return renderMarkdown(markdown, variables, aiBots);
}
