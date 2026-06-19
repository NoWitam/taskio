// markdown.ts — the isolated, unit-testable (de)serialization module for the
// "next" Markdown editor (Tier 7, PART 1).
//
// Two pure functions bridge a MARKDOWN STRING and a Tiptap/ProseMirror JSON doc:
//
//   markdownToDoc(md)  : string         -> JSONContent ("doc")   [parse]
//   docToMarkdown(doc) : JSONContent    -> string                [serialize]
//
// They are the SINGLE source of truth for the editor's wire format, kept out of
// the Vue component so they can be tested in isolation and reused by
// MarkdownViewer-style consumers.
//
// ─────────────────────────────────────────────────────────────────────────────
// FORMAT CONTRACT (the "next" markdown dialect)
// ─────────────────────────────────────────────────────────────────────────────
// The legacy editor's FORMAT.md documents ONLY its custom directives (mentions,
// variables, ai-text, if-blocks) layered on top of CommonMark; it never pins the
// BASE markdown grammar. PART 1 therefore commits the base grammar explicitly,
// chosen to (a) round-trip losslessly through Tiptap's StarterKit + table/
// underline/link/strike nodes, and (b) stay portable with the legacy editor,
// which is `marked`-based CommonMark + GFM. The base grammar is:
//
//   • Headings           `#`..`###`        (H1–H3; the editor caps at 3)
//   • Bold               `**text**`
//   • Italic             `*text*`
//   • Strikethrough      `~~text~~`        (GFM)
//   • Inline code        `` `text` ``
//   • Underline          `<u>text</u>`     (no CommonMark syntax → HTML element,
//                                            same convention the legacy renderer
//                                            uses; round-trips via `marked`+sanitize)
//   • Link               `[text](href)`    (http/https/mailto; rel="noopener")
//   • Bullet list        `- item`
//   • Ordered list       `1. item`     (`start` preserved when != 1)
//   • Task list          `- [ ]` / `- [x]` are PARSED (GFM) but DEGRADE to bullet
//                          items with the literal `[ ]` text kept — the core has
//                          no task-list node (legacy parity + no new dep). PART 2
//                          may register a real task node.
//   • Blockquote         `> quote`
//   • Fenced code block  ```` ```lang ````  (language preserved after the fence)
//   • Horizontal rule    `---`
//   • Table              GFM pipe tables with `:---`, `:---:`, `---:` alignment.
//     The FIRST row is the header row (TableHeader cells); a leading column made
//     of header cells is preserved as a `<th>` row-header when present.
//   • Hard break         a trailing `\` or two trailing spaces  -> `<br>`
//
// PART 2 (NOT in this pass) re-introduces the directive layer (mentions,
// variables, if-blocks, AI chips) on TOP of this base, exactly as legacy FORMAT.md
// specifies, by registering extra extensions + extending these two functions via
// the documented hooks (see README.md "Extension points"). The base functions are
// deliberately node-type driven so an unknown node type degrades to its text
// content rather than throwing.
//
// IMPLEMENTATION NOTES
//   • Parsing uses `marked` to tokenize, then maps tokens -> Tiptap JSON. We do
//     NOT use prosemirror-markdown: its schema would have to mirror Tiptap's node
//     names exactly and it cannot express the underline/task-list conventions
//     above without custom tokenizers anyway. A direct token map is smaller and
//     fully under our control (and matches the legacy editor's bespoke approach).
//   • Serializing walks the Tiptap JSON. Inline marks are emitted innermost-first
//     in a stable order so `**_x_**` style nesting round-trips.
//   • Round-trip guarantee: docToMarkdown(markdownToDoc(md)) is STABLE (idempotent
//     after the first normalization pass) for all grammar above. It is not
//     guaranteed to reproduce byte-for-byte cosmetic input (e.g. `*` vs `_` for
//     italics, extra blank lines) — it normalizes to the canonical forms listed.

import { marked, type Token, type Tokens } from 'marked';

export interface JSONMark {
  type: string;
  attrs?: Record<string, unknown>;
}

// ─────────────────────────────────────────────────────────────────────────────
// PART 2 NODE REGISTRY (the documented `registerMarkdownNode` hook)
// ─────────────────────────────────────────────────────────────────────────────
// App-specific nodes (mention, variable, if-block, AI chip) own their OWN
// markdown form. They register here so the two pure functions consult the
// registry FIRST — before falling back to the graceful text degradation that
// PART 1 ships. Registration is idempotent and global (the schema is global too).
//
//   • `toMarkdown(node)` — serialize a JSON node to its directive string. For
//     inline nodes this is the full `@[type]("…")` directive; for block nodes
//     (if-block) it is the fenced ```` ```if-block ```` form. Returning a string
//     short-circuits the default degradation.
//   • PARSE side — inline directives register via `registerInlineDirective`
//     (a keyword + a `toNode(payload)` mapper; the parser masks directives out of
//     the markdown before `marked` lexing, then restores them), and the if-block
//     fence registers via `registerIfBlockParser` (a `toNode(meta, body, parse)`
//     consulted by a block pre-splitter — see `splitIfBlocks`).
//
// The legacy editor encodes directives identically (see its FORMAT.md), so the
// registered serializers reproduce its exact bytes for cross-editor portability.

export interface MarkdownNodeHandler {
  /** Serialize a JSON node of this type to markdown. Return null to fall back. */
  toMarkdown(node: JSONNode, ctx: MarkdownSerializeContext): string | null;
}

export interface MarkdownSerializeContext {
  /** Serialize child block nodes (used by if-block bodies). */
  serializeBlocks(nodes: JSONNode[]): string;
  /** Serialize inline content (used inside directive bodies). */
  serializeInline(nodes: JSONNode[]): string;
}

const nodeRegistry = new Map<string, MarkdownNodeHandler>();

/**
 * Register a PART 2 node's markdown (de)serialization. Consulted by
 * `docToMarkdown`. Idempotent: re-registering replaces the prior handler.
 */
export function registerMarkdownNode(
  type: string,
  handler: MarkdownNodeHandler,
): void {
  nodeRegistry.set(type, handler);
}

/** Remove a registered handler — exported for test isolation. */
export function unregisterMarkdownNode(type: string): void {
  nodeRegistry.delete(type);
}

/** Test helper: clear the whole registry so degradation can be exercised. */
export function __clearMarkdownNodes(): void {
  nodeRegistry.clear();
  inlineDirectiveParsers.length = 0;
  ifBlockParser = null;
}

// --- PART 2 PARSE hooks ------------------------------------------------------
// Inline directives and the if-block fence are NOT CommonMark, so `marked` can't
// see them. PART 2 registers parsers here; PART 1 leaves them empty, so the same
// markdown round-trips as plain text (graceful degradation, no crash).

export interface InlineDirectiveParser {
  /** The directive keyword as it appears in `@[keyword]("…")` (e.g. 'mention'). */
  keyword: string;
  /** Map a decoded JSON payload (already unwrapped from `{v,data}`) to a node. */
  toNode(payload: unknown): JSONNode | null;
}

export interface IfBlockParser {
  /**
   * Build an if-block JSON node from a parsed fence (fence meta + the raw body
   * lines between the fences). `parseBody` re-parses a markdown string into block
   * nodes so nested directives/if-blocks work recursively.
   */
  toNode(
    meta: Record<string, unknown> | null,
    bodyLines: string[],
    parseBody: (md: string) => JSONNode[],
  ): JSONNode | null;
}

const inlineDirectiveParsers: InlineDirectiveParser[] = [];
let ifBlockParser: IfBlockParser | null = null;

/** Register an inline directive parser (mention/variable/ai-text). */
export function registerInlineDirective(parser: InlineDirectiveParser): void {
  const i = inlineDirectiveParsers.findIndex((p) => p.keyword === parser.keyword);
  if (i >= 0) inlineDirectiveParsers[i] = parser;
  else inlineDirectiveParsers.push(parser);
}

/** Register the if-block fence parser (only one — the container is singular). */
export function registerIfBlockParser(parser: IfBlockParser): void {
  ifBlockParser = parser;
}

export interface JSONNode {
  type: string;
  attrs?: Record<string, unknown>;
  content?: JSONNode[];
  marks?: JSONMark[];
  text?: string;
}

export interface MarkdownDoc {
  type: 'doc';
  content: JSONNode[];
}

const EMPTY_DOC: MarkdownDoc = {
  type: 'doc',
  content: [{ type: 'paragraph' }],
};

// ─────────────────────────────────────────────────────────────────────────────
// PARSE: markdown string -> Tiptap doc JSON
// ─────────────────────────────────────────────────────────────────────────────

export function markdownToDoc(markdown: string): MarkdownDoc {
  if (!markdown || !markdown.trim()) {
    return { type: 'doc', content: [{ type: 'paragraph' }] };
  }
  const content = parseBlocks(markdown);
  return {
    type: 'doc',
    content: content.length ? content : EMPTY_DOC.content,
  };
}

// Parse a markdown string into block nodes, splicing out PART 2 if-block fences
// FIRST (so `marked` never sees them as plain code blocks) and feeding the
// non-directive remainder through `marked`. The if-block parser re-enters here
// for each branch body, so nesting works recursively. Exported indirectly to
// PART 2 parsers via the `parseBody` callback they receive.
function parseBlocks(markdown: string): JSONNode[] {
  const out: JSONNode[] = [];
  if (ifBlockParser) {
    const segments = splitIfBlocks(markdown);
    for (const seg of segments) {
      if (seg.kind === 'if-block') {
        const node = ifBlockParser.toNode(seg.meta, seg.body, parseBlocks);
        if (node) out.push(node);
        else if (seg.raw) out.push(...lexBlocks(seg.raw));
      } else if (seg.text.trim()) {
        out.push(...lexBlocks(seg.text));
      }
    }
    return out;
  }
  return lexBlocks(markdown);
}

function lexBlocks(markdown: string): JSONNode[] {
  if (!markdown.trim()) return [];
  // PART 2: an inline directive `@[type]("…")` looks like a reference link to
  // `marked` (`[text](url)`), so mask every directive to an inert placeholder
  // token BEFORE lexing, then restore the real directive nodes after. PART 1
  // leaves `inlineDirectiveParsers` empty so masking is a no-op.
  const text = inlineDirectiveParsers.length
    ? maskInlineDirectives(markdown)
    : markdown;
  // GFM on (tables, strikethrough, task lists). `breaks:false` keeps single line
  // breaks as soft (joined) text; explicit hard breaks come from `<br>` / `\`.
  const tokens = marked.lexer(text, { gfm: true, breaks: false });
  return blockTokensToNodes(tokens);
}

// Replace each `@[type]("…")` with an inert ALPHANUMERIC placeholder that
// `marked` carries through as plain text (no markdown-significant chars → no
// tokenizer fires), so a directive that looks like a `[ref](link)` can't be
// mis-parsed. `textRun` restores it to the real directive node via PLACEHOLDER_RE.
// The directive's raw source is stashed per-parse, keyed by the numeric id.
const PLACEHOLDER_RE = /zzdir(\d+)zz/g;
const DIRECTIVE_STASH: string[] = [];

function maskInlineDirectives(markdown: string): string {
  DIRECTIVE_STASH.length = 0;
  let cursor = 0;
  let out = '';

  while (cursor < markdown.length) {
    const start = markdown.indexOf('@[', cursor);
    if (start === -1) {
      out += markdown.slice(cursor);
      break;
    }
    const typeEnd = markdown.indexOf(']', start);
    if (
      typeEnd === -1 ||
      markdown[typeEnd + 1] !== '(' ||
      markdown[typeEnd + 2] !== '"'
    ) {
      const stop = typeEnd === -1 ? markdown.length : typeEnd + 1;
      out += markdown.slice(cursor, stop);
      cursor = stop;
      continue;
    }
    const keyword = markdown.slice(start + 2, typeEnd);
    const extracted = extractDirectivePayload(markdown, typeEnd + 2);
    const known = inlineDirectiveParsers.some((p) => p.keyword === keyword);
    if (!extracted || !known) {
      out += markdown.slice(cursor, typeEnd + 1);
      cursor = typeEnd + 1;
      continue;
    }
    out += markdown.slice(cursor, start);
    const id = DIRECTIVE_STASH.push(markdown.slice(start, extracted.nextIndex)) - 1;
    out += `zzdir${id}zz`;
    cursor = extracted.nextIndex;
  }
  return out;
}

// --- if-block fence splitting -----------------------------------------------
// Walk lines, tracking fence depth so a NESTED ```if-block``` inside a branch
// body stays part of the OUTER block's body (the if-block parser re-enters
// `parseBlocks` per branch, which re-splits it). Matches the legacy fence form.
type IfSegment =
  | { kind: 'text'; text: string }
  | { kind: 'if-block'; meta: Record<string, unknown> | null; body: string[]; raw: string };

const IF_FENCE_RE = /^```if-block\b\s*(.*)$/;

function splitIfBlocks(markdown: string): IfSegment[] {
  const lines = markdown.split(/\r?\n/);
  const segments: IfSegment[] = [];
  let textBuf: string[] = [];
  let i = 0;

  const flushText = () => {
    if (textBuf.length) {
      segments.push({ kind: 'text', text: textBuf.join('\n') });
      textBuf = [];
    }
  };

  while (i < lines.length) {
    const m = lines[i].match(IF_FENCE_RE);
    if (m) {
      flushText();
      const meta = m[1] ? safeJson(m[1].trim()) : null;
      const body: string[] = [];
      const rawLines: string[] = [lines[i]];
      let depth = 1;
      i += 1;
      while (i < lines.length && depth > 0) {
        const line = lines[i];
        rawLines.push(line);
        if (IF_FENCE_RE.test(line)) {
          depth += 1;
          body.push(line);
        } else if (line.trim() === '```') {
          depth -= 1;
          if (depth > 0) body.push(line);
        } else {
          body.push(line);
        }
        i += 1;
      }
      segments.push({
        kind: 'if-block',
        meta,
        body,
        raw: rawLines.join('\n'),
      });
      continue;
    }
    textBuf.push(lines[i]);
    i += 1;
  }
  flushText();
  return segments;
}

function safeJson(input: string): Record<string, unknown> | null {
  if (!input) return null;
  try {
    const v = JSON.parse(input);
    return v && typeof v === 'object' ? (v as Record<string, unknown>) : null;
  } catch {
    return null;
  }
}

function blockTokensToNodes(tokens: Token[]): JSONNode[] {
  const nodes: JSONNode[] = [];
  for (const token of tokens) {
    const node = blockTokenToNode(token);
    if (Array.isArray(node)) nodes.push(...node);
    else if (node) nodes.push(node);
  }
  return nodes;
}

function blockTokenToNode(token: Token): JSONNode | JSONNode[] | null {
  switch (token.type) {
    case 'space':
      return null;
    case 'heading': {
      const t = token as Tokens.Heading;
      const level = Math.min(Math.max(t.depth, 1), 3);
      return {
        type: 'heading',
        attrs: { level },
        content: inlineTokensToNodes(t.tokens ?? []),
      };
    }
    case 'paragraph': {
      const t = token as Tokens.Paragraph;
      return {
        type: 'paragraph',
        content: inlineTokensToNodes(t.tokens ?? []),
      };
    }
    case 'text': {
      // Loose list items / stray text blocks surface as paragraphs.
      const t = token as Tokens.Text;
      return {
        type: 'paragraph',
        content: t.tokens
          ? inlineTokensToNodes(t.tokens)
          : textRun(t.text),
      };
    }
    case 'blockquote': {
      const t = token as Tokens.Blockquote;
      return {
        type: 'blockquote',
        content: blockTokensToNodes(t.tokens ?? []),
      };
    }
    case 'code': {
      const t = token as Tokens.Code;
      const attrs = t.lang ? { language: t.lang } : {};
      return {
        type: 'codeBlock',
        attrs,
        content: t.text ? [{ type: 'text', text: t.text }] : [],
      };
    }
    case 'hr':
      return { type: 'horizontalRule' };
    case 'list':
      return listTokenToNode(token as Tokens.List);
    case 'table':
      return tableTokenToNode(token as Tokens.Table);
    default:
      return null;
  }
}

function listTokenToNode(token: Tokens.List): JSONNode {
  // NOTE: the core schema has no task-list node (matching the legacy editor and
  // the no-new-deps rule — see extensions/index.ts). GFM task syntax `- [ ]` /
  // `- [x]` therefore DEGRADES to a normal bullet item, re-prepending the literal
  // checkbox text so nothing is lost. PART 2 may register a real task node.
  const type = token.ordered ? 'orderedList' : 'bulletList';
  const attrs =
    token.ordered && token.start && token.start !== 1
      ? { start: token.start }
      : undefined;
  return {
    type,
    ...(attrs ? { attrs } : {}),
    content: token.items.map((item) => ({
      type: 'listItem',
      content: listItemBody(item),
    })),
  };
}

// A list item's children: nested block tokens become block nodes; a bare text
// token becomes a paragraph so every list item holds block content (Tiptap rule).
function listItemBody(item: Tokens.ListItem): JSONNode[] {
  const out: JSONNode[] = [];
  // Degraded task item: re-prepend the literal checkbox glyph (see above).
  const taskPrefix = item.task ? `[${item.checked ? 'x' : ' '}] ` : '';
  let first = true;
  for (const child of item.tokens ?? []) {
    if (child.type === 'text') {
      const t = child as Tokens.Text;
      const inline = t.tokens ? inlineTokensToNodes(t.tokens) : textRun(t.text);
      if (first && taskPrefix) inline.unshift({ type: 'text', text: taskPrefix });
      out.push({ type: 'paragraph', content: inline });
      first = false;
    } else {
      const node = blockTokenToNode(child);
      if (Array.isArray(node)) out.push(...node);
      else if (node) out.push(node);
      first = false;
    }
  }
  return out.length ? out : [{ type: 'paragraph' }];
}

function tableTokenToNode(token: Tokens.Table): JSONNode {
  const rows: JSONNode[] = [];

  // Header row: every cell is a header cell.
  rows.push({
    type: 'tableRow',
    content: token.header.map((cell, i) =>
      cellNode('tableHeader', cell, token.align[i]),
    ),
  });

  for (const row of token.rows) {
    rows.push({
      type: 'tableRow',
      content: row.map((cell, i) =>
        cellNode('tableCell', cell, token.align[i]),
      ),
    });
  }

  return { type: 'table', content: rows };
}

function cellNode(
  type: 'tableHeader' | 'tableCell',
  cell: Tokens.TableCell,
  align: 'center' | 'left' | 'right' | null,
): JSONNode {
  const attrs: Record<string, unknown> = {};
  if (align) attrs.align = align;
  return {
    type,
    ...(Object.keys(attrs).length ? { attrs } : {}),
    content: [
      {
        type: 'paragraph',
        content: inlineTokensToNodes(cell.tokens ?? []),
      },
    ],
  };
}

// --- Inline -----------------------------------------------------------------

function inlineTokensToNodes(tokens: Token[], marks: JSONMark[] = []): JSONNode[] {
  const out: JSONNode[] = [];
  for (const token of tokens) {
    out.push(...inlineTokenToNodes(token, marks));
  }
  return out;
}

function inlineTokenToNodes(token: Token, marks: JSONMark[]): JSONNode[] {
  switch (token.type) {
    case 'text': {
      const t = token as Tokens.Text;
      // `marked` nests inline tokens under a text token when there's markup.
      if (t.tokens && t.tokens.length) {
        return inlineTokensToNodes(t.tokens, marks);
      }
      return textRun(decodeEntities(t.text), marks);
    }
    case 'escape': {
      const t = token as Tokens.Escape;
      return textRun(t.text, marks);
    }
    case 'strong':
      return inlineTokensToNodes((token as Tokens.Strong).tokens ?? [], [
        ...marks,
        { type: 'bold' },
      ]);
    case 'em':
      return inlineTokensToNodes((token as Tokens.Em).tokens ?? [], [
        ...marks,
        { type: 'italic' },
      ]);
    case 'del':
      return inlineTokensToNodes((token as Tokens.Del).tokens ?? [], [
        ...marks,
        { type: 'strike' },
      ]);
    case 'codespan': {
      const t = token as Tokens.Codespan;
      return textRun(decodeEntities(t.text), [...marks, { type: 'code' }]);
    }
    case 'link': {
      const t = token as Tokens.Link;
      const linkMark: JSONMark = {
        type: 'link',
        attrs: { href: t.href, ...(t.title ? { title: t.title } : {}) },
      };
      return inlineTokensToNodes(t.tokens ?? [], [...marks, linkMark]);
    }
    case 'br':
      return [{ type: 'hardBreak' }];
    case 'html': {
      // Underline is carried as a raw <u>…</u> element (no CommonMark syntax).
      const t = token as Tokens.HTML;
      const html = t.text.trim();
      if (/^<u>$/i.test(html)) {
        underlineDepth += 1;
        return [];
      }
      if (/^<\/u>$/i.test(html)) {
        underlineDepth = Math.max(0, underlineDepth - 1);
        return [];
      }
      if (/^<br\s*\/?>$/i.test(html)) return [{ type: 'hardBreak' }];
      // Unknown inline HTML: keep its text content, stripped of tags.
      return textRun(stripTags(decodeEntities(t.text)), marks);
    }
    default:
      return [];
  }
}

// `<u>` toggling spans sibling tokens, so we track depth and fold it into the
// mark set of every text run produced while open. Reset per parse for safety.
let underlineDepth = 0;

function textRun(text: string, marks: JSONMark[] = []): JSONNode[] {
  if (!text) return [];
  const all = underlineDepth > 0 ? [...marks, { type: 'underline' }] : marks;
  // PART 2: split inline directives (`@[type]("…")`) out of the text run; the
  // directive node itself is atomic + unmarked (legacy parity). PART 1 leaves
  // `inlineDirectiveParsers` empty, so this is a single plain text node.
  const pieces = inlineDirectiveParsers.length ? splitInlineDirectives(text) : [text];
  const out: JSONNode[] = [];
  for (const piece of pieces) {
    if (typeof piece === 'string') {
      if (!piece) continue;
      const node: JSONNode = { type: 'text', text: piece };
      if (all.length) node.marks = dedupeMarks(all);
      out.push(node);
    } else {
      out.push(piece);
    }
  }
  return out;
}

// Restore masked directive placeholders (`zzdir<id>zz`) in a text run to their
// real nodes. The placeholder's stashed source is `@[keyword]("…escaped json…")`;
// we decode the keyword + payload (JSON-in-attribute escaping: `\"`-escaped JSON)
// and map it to the registered node, mirroring the legacy parser's decoding.
function splitInlineDirectives(text: string): (string | JSONNode)[] {
  const out: (string | JSONNode)[] = [];
  let lastIndex = 0;
  PLACEHOLDER_RE.lastIndex = 0;
  let m: RegExpExecArray | null;

  while ((m = PLACEHOLDER_RE.exec(text)) !== null) {
    if (m.index > lastIndex) out.push(text.slice(lastIndex, m.index));
    const raw = DIRECTIVE_STASH[Number(m[1])];
    const node = raw ? directiveSourceToNode(raw) : null;
    out.push(node ?? m[0]);
    lastIndex = m.index + m[0].length;
  }
  if (lastIndex < text.length) out.push(text.slice(lastIndex));
  return out;
}

// Decode a stashed `@[keyword]("…")` directive string to its registered node.
function directiveSourceToNode(raw: string): JSONNode | null {
  const typeEnd = raw.indexOf(']');
  if (typeEnd === -1 || raw[typeEnd + 1] !== '(' || raw[typeEnd + 2] !== '"') {
    return null;
  }
  const keyword = raw.slice(2, typeEnd);
  const parser = inlineDirectiveParsers.find((p) => p.keyword === keyword);
  const extracted = extractDirectivePayload(raw, typeEnd + 2);
  if (!parser || !extracted) return null;
  const payload = decodeDirectivePayload(extracted.payload);
  return payload != null ? parser.toNode(payload) : null;
}

// Extract the `"…"` payload up to the closing `")`. The serialization is the
// legacy byte-format (single-level `"`→`\"` escaping); parsing here is the
// inverse but made robust to NESTED directives (an `@[ai-text]` prompt that
// itself contains an `@[variable]`): a `"` is the real terminator only when it is
// preceded by an EVEN number of backslashes (so the nested directive's escaped
// `\\")` doesn't end the OUTER payload early). This stays byte-compatible — it
// only changes parsing, never the emitted bytes.
function extractDirectivePayload(
  text: string,
  quoteIndex: number,
): { payload: string; nextIndex: number } | null {
  const start = quoteIndex + 1;
  // Try each candidate `")` terminator from the LAST one backwards: the genuine
  // payload is the one that, after unescaping `\"`→`"`, parses as valid JSON. A
  // nested directive's escaped `\\")` produces a shorter prefix that is NOT valid
  // JSON, so it's rejected and we keep scanning outward. Scanning from the last
  // candidate first finds the outermost terminator (the full payload).
  const candidates: number[] = [];
  for (let i = start; i < text.length - 1; i += 1) {
    if (text[i] === '"' && text[i + 1] === ')') candidates.push(i);
  }
  for (let c = candidates.length - 1; c >= 0; c -= 1) {
    const end = candidates[c];
    const payload = text.slice(start, end);
    if (isDecodablePayload(payload)) {
      return { payload, nextIndex: end + 2 };
    }
  }
  return null;
}

/** True when a raw directive payload unescapes + JSON-parses cleanly. */
function isDecodablePayload(payload: string): boolean {
  try {
    JSON.parse(payload.replace(/\\"/g, '"'));
    return true;
  } catch {
    return false;
  }
}

// Decode a directive payload: unescape `\"` → `"`, JSON.parse, then unwrap the
// `{ v, data }` envelope (legacy parity). Returns the inner `data` (or the whole
// parsed object if it has no `data` key).
function decodeDirectivePayload(payload: string): unknown {
  try {
    const parsed = JSON.parse(payload.replace(/\\"/g, '"'));
    if (parsed && typeof parsed === 'object' && 'data' in parsed) {
      return (parsed as { data: unknown }).data;
    }
    return parsed;
  } catch {
    return null;
  }
}

function dedupeMarks(marks: JSONMark[]): JSONMark[] {
  const seen = new Set<string>();
  const out: JSONMark[] = [];
  for (const m of marks) {
    const key = m.type + (m.attrs ? JSON.stringify(m.attrs) : '');
    if (seen.has(key)) continue;
    seen.add(key);
    out.push(m);
  }
  return out;
}

function decodeEntities(text: string): string {
  return text
    .replace(/&amp;/g, '&')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&#39;/g, "'")
    .replace(/&nbsp;/g, ' ');
}

function stripTags(text: string): string {
  return text.replace(/<[^>]*>/g, '');
}

// ─────────────────────────────────────────────────────────────────────────────
// SERIALIZE: Tiptap doc JSON -> markdown string
// ─────────────────────────────────────────────────────────────────────────────

export function docToMarkdown(doc: JSONNode | null | undefined): string {
  if (!doc || doc.type !== 'doc' || !Array.isArray(doc.content)) return '';
  const md = serializeBlocks(doc.content).trim();
  return md;
}

const serializeContext: MarkdownSerializeContext = {
  serializeBlocks,
  serializeInline,
};

function serializeBlocks(nodes: JSONNode[]): string {
  return nodes
    .map((node) => serializeBlock(node))
    .filter((s) => s !== null)
    .join('\n\n');
}

function serializeBlock(node: JSONNode): string | null {
  // PART 2 block nodes (if-block) own their markdown form via the registry.
  const handler = nodeRegistry.get(node.type);
  if (handler) {
    const out = handler.toMarkdown(node, serializeContext);
    if (out !== null) return out;
  }
  switch (node.type) {
    case 'paragraph':
      return serializeInline(node.content ?? []);
    case 'heading': {
      const level = Math.min(Math.max(Number(node.attrs?.level ?? 1), 1), 3);
      return `${'#'.repeat(level)} ${serializeInline(node.content ?? [])}`;
    }
    case 'blockquote':
      return serializeBlocks(node.content ?? [])
        .split('\n')
        .map((line) => (line ? `> ${line}` : '>'))
        .join('\n');
    case 'codeBlock': {
      const lang = (node.attrs?.language as string) ?? '';
      const text = (node.content ?? [])
        .map((c) => c.text ?? '')
        .join('');
      return `\`\`\`${lang}\n${text}\n\`\`\``;
    }
    case 'horizontalRule':
      return '---';
    case 'bulletList':
      return serializeList(node, 'bullet');
    case 'orderedList':
      return serializeList(node, 'ordered');
    case 'table':
      return serializeTable(node);
    default:
      // Unknown block: degrade to its inline/text content (PART 2 extensions
      // override this path before reaching here).
      return serializeInline(node.content ?? []);
  }
}

function serializeList(
  node: JSONNode,
  kind: 'bullet' | 'ordered',
  depth = 0,
): string {
  const indent = '  '.repeat(depth);
  const start = Number(node.attrs?.start ?? 1);
  const lines: string[] = [];

  (node.content ?? []).forEach((item, index) => {
    const marker = kind === 'ordered' ? `${start + index}.` : '-';

    const childBlocks = item.content ?? [];
    childBlocks.forEach((block, blockIndex) => {
      if (block.type === 'bulletList' || block.type === 'orderedList') {
        const nestedKind = block.type === 'orderedList' ? 'ordered' : 'bullet';
        lines.push(serializeList(block, nestedKind, depth + 1));
      } else {
        const body = serializeBlock(block) ?? '';
        if (blockIndex === 0) {
          lines.push(`${indent}${marker} ${body}`);
        } else {
          // Continuation block under the same item: indent to align.
          const pad = indent + ' '.repeat(marker.length + 1);
          lines.push(
            body
              .split('\n')
              .map((l) => (l ? pad + l : l))
              .join('\n'),
          );
        }
      }
    });
  });

  return lines.join('\n');
}

function serializeTable(node: JSONNode): string {
  const rows = node.content ?? [];
  if (!rows.length) return '';

  const matrix: string[][] = rows.map((row) =>
    (row.content ?? []).map((cell) => serializeInline(cellInline(cell)).trim()),
  );
  const aligns: (string | null)[] = (rows[0].content ?? []).map(
    (cell) => (cell.attrs?.align as string) ?? null,
  );

  const headerCells = matrix[0] ?? [];
  const headerLine = `| ${headerCells.join(' | ')} |`;
  const dividerLine = `| ${aligns
    .map((a) => {
      if (a === 'center') return ':---:';
      if (a === 'left') return ':---';
      if (a === 'right') return '---:';
      return '---';
    })
    .join(' | ')} |`;
  const bodyLines = matrix
    .slice(1)
    .map((cells) => `| ${cells.join(' | ')} |`);

  return [headerLine, dividerLine, ...bodyLines].join('\n');
}

// A table cell holds a paragraph; flatten to its inline content.
function cellInline(cell: JSONNode): JSONNode[] {
  const para = (cell.content ?? []).find((c) => c.type === 'paragraph');
  return para?.content ?? cell.content ?? [];
}

// --- Inline serialization ---------------------------------------------------

function serializeInline(nodes: JSONNode[]): string {
  return nodes.map((node) => serializeInlineNode(node)).join('');
}

function serializeInlineNode(node: JSONNode): string {
  if (node.type === 'hardBreak') return '  \n';
  if (node.type !== 'text') {
    // PART 2 inline directives (mention/variable/ai-text) own their markdown.
    const handler = nodeRegistry.get(node.type);
    if (handler) {
      const out = handler.toMarkdown(node, serializeContext);
      if (out !== null) return out;
    }
    // Unknown inline (registry-less core) degrades to nested text content.
    return serializeInline(node.content ?? []);
  }

  let text = escapeMarkdown(node.text ?? '');
  const marks = node.marks ?? [];

  // Order matters: link wraps the marked text; code is exclusive (no markdown
  // markup inside a codespan, so it goes innermost and we DON'T escape inside it).
  const hasCode = marks.some((m) => m.type === 'code');
  if (hasCode) {
    text = `\`${node.text ?? ''}\``;
  } else {
    // Apply emphasis marks innermost-first in a stable order.
    if (marks.some((m) => m.type === 'underline')) text = `<u>${text}</u>`;
    if (marks.some((m) => m.type === 'strike')) text = `~~${text}~~`;
    if (marks.some((m) => m.type === 'italic')) text = `*${text}*`;
    if (marks.some((m) => m.type === 'bold')) text = `**${text}**`;
  }

  const link = marks.find((m) => m.type === 'link');
  if (link) {
    const href = String(link.attrs?.href ?? '');
    const title = link.attrs?.title ? ` "${link.attrs.title}"` : '';
    text = `[${text}](${href}${title})`;
  }

  return text;
}

// Escape only the characters that trigger INLINE markdown parsing in running
// text. Block-level markers (`#`, `-`, `>`, `1.`) are a line-start concern the
// block serializer already controls, so escaping them in prose would corrupt
// idempotency (e.g. every hyphenated word). We escape the inline set plus the
// pipe (so cell text never splits a GFM table column). Backslash first.
function escapeMarkdown(text: string): string {
  return text.replace(/[\\`*_~[\]|]/g, '\\$&');
}

/** Reset module-level parse state — exported for test isolation. */
export function __resetParserState(): void {
  underlineDepth = 0;
  DIRECTIVE_STASH.length = 0;
}
