// ifBlockDepth.spec.ts — depth-cap enforcement for nested if-blocks.
//
// The editor-instance path (Tiptap `Editor`) needs a DOM, which the node test
// env doesn't provide. But the depth rule is a PURE function of the document
// tree: `ifBlockDepthAt($pos)` counts ancestor ifBlock nodes, and `insertIfBlock`
// refuses when that count >= maxDepth. We build the schema (DOM-free) from the
// extensions, construct a depth-3 nested ifBlock doc, and assert the depth count
// at the deepest branch is 3 — i.e. a 4th-level insert there is prevented.
import { describe, it, expect } from 'vitest';
import { getSchema } from '@tiptap/core';
import { createCoreExtensions } from '../extensions';
import { createIfBlock, ifBlockDepthAt, DEFAULT_MAX_DEPTH } from '../extensions/ifBlock';
import type { Node as PMNode } from '@tiptap/pm/model';

const schema = getSchema([...createCoreExtensions(), ...createIfBlock()]);

// Build a branch with the given inner body content.
function branch(body: PMNode[]) {
  return schema.nodes.ifBranch.create(
    { id: 'b', kind: 'if', condition: null },
    body,
  );
}
function para(text: string) {
  return schema.nodes.paragraph.create(null, text ? schema.text(text) : null);
}
function ifBlock(branches: PMNode[]) {
  return schema.nodes.ifBlock.create({ id: 'x' }, branches);
}

describe('if-block depth cap', () => {
  it('exposes a default max depth of 3', () => {
    expect(DEFAULT_MAX_DEPTH).toBe(3);
  });

  it('counts nesting depth at a position inside deeply nested branches', () => {
    // depth 3: outer ifBlock > branch > inner ifBlock > branch > innermost ifBlock
    const innermost = ifBlock([branch([para('deep')])]);
    const middle = ifBlock([branch([innermost])]);
    const outer = ifBlock([branch([middle])]);
    const doc = schema.nodes.doc.create(null, outer);

    // Find a position inside the innermost paragraph text.
    let target = -1;
    doc.descendants((node, pos) => {
      if (target === -1 && node.isText && node.text === 'deep') target = pos + 1;
      return true;
    });
    expect(target).toBeGreaterThan(0);

    const depth = ifBlockDepthAt(doc.resolve(target));
    expect(depth).toBe(3);
    // At depth === maxDepth, a further insert is refused.
    expect(depth >= DEFAULT_MAX_DEPTH).toBe(true);
  });

  it('reports depth 0 at top level (insert allowed)', () => {
    const doc = schema.nodes.doc.create(null, para('hello'));
    const depth = ifBlockDepthAt(doc.resolve(1));
    expect(depth).toBe(0);
    expect(depth >= DEFAULT_MAX_DEPTH).toBe(false);
  });
});
