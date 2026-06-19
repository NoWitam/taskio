// Task description boundary conversion for the "next" Tasks module.
//
// CONTRACT MISMATCH this module bridges:
//   The backend stores a task `description` as a ProseMirror/Tiptap doc OBJECT
//   (`{ type: 'doc', content: [...] }`, via Laravel's `MarkdownTreeCast` →
//   `MarkdownTree`) and returns it that way in BOTH the list resource and
//   `GET /tasks/{id}`. The "next" editor + MarkdownViewer speak a MARKDOWN STRING.
//
//   So everything that crosses the Tasks ↔ editor boundary funnels through here,
//   reusing the editor's single source of truth in `ui/editor/markdown.ts`
//   (`docToMarkdown` doc→string, `markdownToDoc` string→doc). The write payload
//   must be a STRING because `StoreTasksRequest` validates
//   `description` as `nullable|string|max:2500` and the cast parses it via
//   `fromJson` — so we send `JSON.stringify(doc)` (or null when empty).
//
// These helpers tolerate ANY shape the backend/legacy data might hold: a real doc
// object, a JSON STRING of a doc, a plain markdown/text string, or null/undefined.
import {
  docToMarkdown,
  markdownToDoc,
  type JSONNode,
  type MarkdownDoc,
} from '../../ui/editor/markdown';

/** A task `description` as it can arrive from the API (or be absent). */
export type TaskDescription = JSONNode | string | null | undefined;

/** True when a value looks like a ProseMirror doc object. */
function isDocObject(value: unknown): value is JSONNode {
  return (
    !!value &&
    typeof value === 'object' &&
    (value as JSONNode).type === 'doc'
  );
}

/**
 * Coerce whatever the API gave us into a ProseMirror doc object (or null).
 * Accepts a doc object, a JSON string of a doc, or null/undefined.
 */
function coerceToDoc(value: TaskDescription): JSONNode | null {
  if (value == null) return null;
  if (isDocObject(value)) return value;
  if (typeof value === 'string') {
    const trimmed = value.trim();
    if (!trimmed) return null;
    // The backend cast serializes the tree as JSON; try to parse a doc out of it.
    try {
      const parsed = JSON.parse(trimmed);
      if (isDocObject(parsed)) return parsed as JSONNode;
    } catch {
      // Not JSON — treat as raw markdown/text (handled by callers separately).
    }
  }
  return null;
}

/**
 * DISPLAY: convert a task description (doc object / JSON string / null) to a
 * markdown STRING for `MarkdownViewer`. A plain (non-doc) string is returned
 * as-is so legacy/markdown data still renders. Empty → ''.
 */
export function taskDescriptionToMarkdown(value: TaskDescription): string {
  if (value == null) return '';
  const doc = coerceToDoc(value);
  if (doc) return docToMarkdown(doc);
  // Already a plain markdown/text string (not a JSON doc).
  if (typeof value === 'string') return value;
  return '';
}

/**
 * WRITE: convert the editor's markdown string to the description payload the
 * backend expects — a JSON STRING of the ProseMirror doc, or `null` when empty
 * (so an emptied editor clears the field rather than storing an empty doc).
 */
export function markdownToTaskDescriptionPayload(markdown: string): string | null {
  if (!markdown || !markdown.trim()) return null;
  const doc: MarkdownDoc = markdownToDoc(markdown);
  return JSON.stringify(doc);
}

/**
 * PREVIEW: derive a PLAIN-TEXT snippet from a task description by walking the
 * doc's text nodes (block boundaries → spaces). Used by the card preview so the
 * doc object is NEVER handed to a markdown renderer. A plain string degrades to
 * itself; empty → ''.
 */
export function taskDescriptionToPlainText(value: TaskDescription): string {
  if (value == null) return '';
  const doc = coerceToDoc(value);
  if (!doc) {
    return typeof value === 'string' ? value.trim() : '';
  }
  const parts: string[] = [];
  walkText(doc, parts);
  return parts.join(' ').replace(/\s+/g, ' ').trim();
}

function walkText(node: JSONNode, out: string[]): void {
  if (node.text) out.push(node.text);
  if (Array.isArray(node.content)) {
    for (const child of node.content) walkText(child, out);
  }
}
