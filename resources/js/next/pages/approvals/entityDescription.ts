// Plain-text rendering for an approvable entity's `description`.
//
// CONTRACT MISMATCH this bridges: `Approvable::toApprovalQueueItem()` passes the
// entity's own `description` straight through. For a Task that is a ProseMirror
// doc OBJECT (`{ type: 'doc', content: [...] }`, via `MarkdownTreeCast`); for
// other entities it may be a plain string. Rendering the raw value in a template
// prints the doc JSON verbatim — so the queue card + review drawer funnel it
// through here to a readable plain-text snippet (block boundaries → spaces).
//
// Tolerates: a doc object, a JSON STRING of a doc, a plain string, or null.
import type { JSONNode } from '../../ui/editor/markdown';

/** A description as it can arrive on an approvable entity (or be absent). */
export type EntityDescription = JSONNode | string | null | undefined;

function isDocObject(value: unknown): value is JSONNode {
  return !!value && typeof value === 'object' && (value as JSONNode).type === 'doc';
}

function walkText(node: JSONNode, out: string[]): void {
  if (node.text) out.push(node.text);
  if (Array.isArray(node.content)) {
    for (const child of node.content) walkText(child, out);
  }
}

function docToText(doc: JSONNode): string {
  const parts: string[] = [];
  walkText(doc, parts);
  return parts.join(' ').replace(/\s+/g, ' ').trim();
}

/**
 * Convert an entity description (doc object / JSON-string-of-doc / plain string /
 * null) to a plain-text snippet. Empty / whitespace-only → '' so callers can hide
 * the slot or fall back to the type label.
 */
export function entityDescriptionToText(value: EntityDescription): string {
  if (value == null) return '';
  if (isDocObject(value)) return docToText(value);
  if (typeof value === 'string') {
    const trimmed = value.trim();
    if (!trimmed) return '';
    try {
      const parsed = JSON.parse(trimmed);
      if (isDocObject(parsed)) return docToText(parsed as JSONNode);
    } catch {
      // Not JSON — a plain string; return as-is.
    }
    return trimmed;
  }
  return '';
}
