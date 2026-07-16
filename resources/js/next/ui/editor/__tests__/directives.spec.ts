// directives.spec.ts — PART 2 round-trip + degradation tests for the app-specific
// editor nodes (mention, variable, if-block, AI chip). Importing each extension
// module registers its markdown (de)serialization with the core registry, so the
// pure `markdownToDoc`/`docToMarkdown` functions handle the directive layer.
//
// We assert BOTH the exact serialized directive bytes (legacy FORMAT.md parity —
// the portability contract) AND structural round-trips (parse → serialize → same).
import { describe, it, expect, beforeEach, afterAll } from 'vitest';
import {
  markdownToDoc,
  docToMarkdown,
  __resetParserState,
  __clearMarkdownNodes,
  type JSONNode,
  type MarkdownDoc,
} from '../markdown';

// Side-effect imports: each registers its node with the core registry.
import '../extensions/mention';
import '../extensions/variable';
import '../extensions/aiText';
import '../extensions/ifBlock';

beforeEach(() => __resetParserState());

function ser(doc: MarkdownDoc | JSONNode): string {
  return docToMarkdown(doc as JSONNode);
}
function roundTrip(md: string): string {
  return docToMarkdown(markdownToDoc(md));
}

// Helper: find the first inline node of a type inside the first paragraph.
function firstInline(md: string, type: string): JSONNode | undefined {
  const doc = markdownToDoc(md);
  const para = doc.content[0];
  return (para.content ?? []).find((n) => n.type === type);
}

// ─────────────────────────────────────────────────────────────────────────────
// Mention
// ─────────────────────────────────────────────────────────────────────────────
describe('mention directive', () => {
  const MD =
    'Hi @[mention]("{\\"v\\":1,\\"data\\":{\\"id\\":\\"u_1\\",\\"name\\":\\"Alice\\",\\"avatar\\":\\"/img/alice.png\\"}}") there';

  it('parses an inline mention directive to a mention node', () => {
    const node = firstInline(MD, 'mention');
    expect(node).toMatchObject({
      type: 'mention',
      attrs: { id: 'u_1', name: 'Alice', avatar: '/img/alice.png' },
    });
  });

  it('serializes a mention node to the exact legacy directive', () => {
    const doc: MarkdownDoc = {
      type: 'doc',
      content: [
        {
          type: 'paragraph',
          content: [
            { type: 'text', text: 'Hi ' },
            { type: 'mention', attrs: { id: 'u_1', name: 'Alice', avatar: '/img/alice.png' } },
            { type: 'text', text: ' there' },
          ],
        },
      ],
    };
    expect(ser(doc)).toBe(MD);
  });

  it('round-trips (parse → serialize) unchanged', () => {
    expect(roundTrip(MD)).toBe(MD);
  });

  it('a directive that LOOKS like a [ref](link) is not mis-parsed as a link', () => {
    const doc = markdownToDoc(MD);
    const para = doc.content[0];
    const link = (para.content ?? []).find((n) =>
      (n.marks ?? []).some((m) => m.type === 'link'),
    );
    expect(link).toBeUndefined();
  });

  it('works inside a list item (mention in list)', () => {
    const md = `- todo @[mention]("{\\"v\\":1,\\"data\\":{\\"id\\":\\"u_2\\",\\"name\\":\\"Bob\\",\\"avatar\\":\\"\\"}}")`;
    const doc = markdownToDoc(md);
    const item = doc.content[0].content?.[0];
    const para = item?.content?.[0];
    const mention = (para?.content ?? []).find((n) => n.type === 'mention');
    expect(mention).toMatchObject({ type: 'mention', attrs: { id: 'u_2', name: 'Bob' } });
    expect(roundTrip(md)).toBe(md);
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// Variable
// ─────────────────────────────────────────────────────────────────────────────
describe('variable directive', () => {
  const ATTRS = {
    id: 'total',
    name: 'Order total',
    type: 'number' as const,
    locked: false,
    pipeline: [],
    resultType: 'number' as const,
  };
  const MD =
    '@[variable]("' +
    JSON.stringify({ v: 1, data: ATTRS }).replace(/"/g, '\\"') +
    '")';

  it('serializes a variable node to the exact legacy directive', () => {
    const doc: MarkdownDoc = {
      type: 'doc',
      content: [{ type: 'paragraph', content: [{ type: 'variable', attrs: ATTRS }] }],
    };
    expect(ser(doc)).toBe(MD);
  });

  it('parses + round-trips, preserving the pipeline array', () => {
    const node = firstInline(MD, 'variable');
    expect(node).toMatchObject({ type: 'variable', attrs: { id: 'total', resultType: 'number' } });
    expect(roundTrip(MD)).toBe(MD);
  });

  it('round-trips when the payload contains brackets and sits next to a real link', () => {
    const data = {
      id: 'a',
      name: 'A [x]',
      type: 'text' as const,
      locked: false,
      pipeline: [{ stepId: 's1', operationId: 'op', args: { k: 'v' }, outputType: 'text' as const }],
      resultType: 'text' as const,
    };
    const directive =
      '@[variable]("' + JSON.stringify({ v: 1, data }).replace(/"/g, '\\"') + '")';
    const md = `Start ${directive} end **bold** and [real link](https://x.test)`;
    const out = roundTrip(md);
    expect(out).toBe(md);
    // The adjacent REAL link is still a link mark (not masked).
    const doc = markdownToDoc(md);
    const json = JSON.stringify(doc);
    expect(json).toContain('"type":"variable"');
    expect(json).toContain('"href":"https://x.test"');
  });

  it('survives inside a table cell', () => {
    const cellDirective =
      '@[variable]("' +
      JSON.stringify({ v: 1, data: ATTRS }).replace(/"/g, '\\"') +
      '")';
    const md = `| Field | Value |\n| --- | --- |\n| Total | ${cellDirective} |`;
    const doc = markdownToDoc(md);
    const table = doc.content.find((n) => n.type === 'table');
    expect(table).toBeTruthy();
    // The variable node should appear somewhere in the table's inline content.
    const json = JSON.stringify(doc);
    expect(json).toContain('"type":"variable"');
    expect(roundTrip(md)).toBe(md);
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// AI text
// ─────────────────────────────────────────────────────────────────────────────
describe('ai-text directive', () => {
  const ATTRS = {
    id: 'ai_1',
    personaId: null,
    prompt: 'Write a friendly summary.',
    labels: ['summary'],
  };
  const MD =
    'Intro @[ai-text]("' +
    JSON.stringify({ v: 1, data: ATTRS }).replace(/"/g, '\\"') +
    '") outro';

  it('serializes an aiText node to the exact legacy directive', () => {
    const doc: MarkdownDoc = {
      type: 'doc',
      content: [
        {
          type: 'paragraph',
          content: [
            { type: 'text', text: 'Intro ' },
            { type: 'aiText', attrs: ATTRS },
            { type: 'text', text: ' outro' },
          ],
        },
      ],
    };
    expect(ser(doc)).toBe(MD);
  });

  it('parses mid-paragraph + round-trips', () => {
    const node = firstInline(MD, 'aiText');
    expect(node).toMatchObject({
      type: 'aiText',
      attrs: { id: 'ai_1', prompt: 'Write a friendly summary.', labels: ['summary'] },
    });
    expect(roundTrip(MD)).toBe(MD);
  });

  it('round-trips when the prompt itself contains a variable directive', () => {
    // The prompt is markdown stored inside the directive JSON; an inner variable
    // directive survives the nested JSON escaping (the prompt is opaque markdown).
    const innerVar =
      '@[variable]("' +
      JSON.stringify({
        v: 1,
        data: { id: 'name', name: 'Name', type: 'text', locked: false, pipeline: [], resultType: 'text' },
      }).replace(/"/g, '\\"') +
      '")';
    const attrs = {
      id: 'ai_2',
      personaId: 'friendly',
      prompt: `Greet ${innerVar} warmly.`,
      labels: ['intro'],
    };
    const md =
      '@[ai-text]("' + JSON.stringify({ v: 1, data: attrs }).replace(/"/g, '\\"') + '")';
    const node = firstInline(md, 'aiText');
    expect(node?.attrs?.prompt).toContain('@[variable]');
    expect(node?.attrs?.personaId).toBe('friendly');
    expect(roundTrip(md)).toBe(md);
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// If-block
// ─────────────────────────────────────────────────────────────────────────────
describe('if-block container', () => {
  // Canonical form: every branch (incl. ELSE) carries its `{id}` meta, exactly as
  // the legacy serializer emits — so this is the stable round-trip target.
  const MD = [
    '```if-block {"id":"if_1","v":1}',
    '[[IF {"id":"b1","condition":{"variableId":"var_bool","pipeline":[],"resultType":"boolean"}}]]',
    'Shown when true.',
    '[[ELSE {"id":"b2"}]]',
    'Shown otherwise.',
    '```',
  ].join('\n');

  it('parses the fence into an ifBlock node with two ifBranch children', () => {
    const doc = markdownToDoc(MD);
    const block = doc.content.find((n) => n.type === 'ifBlock');
    expect(block).toBeTruthy();
    const branches = (block?.content ?? []).filter((n) => n.type === 'ifBranch');
    expect(branches).toHaveLength(2);
    expect(branches[0].attrs?.kind).toBe('if');
    expect(branches[1].attrs?.kind).toBe('else');
    // The IF branch carries a boolean condition; ELSE has none.
    expect((branches[0].attrs?.condition as { resultType?: string })?.resultType).toBe('boolean');
    expect(branches[1].attrs?.condition ?? null).toBeNull();
  });

  it('round-trips the fenced form exactly', () => {
    expect(roundTrip(MD)).toBe(MD);
  });

  it('contains a list + a variable inside a branch body and round-trips', () => {
    const varDirective =
      '@[variable]("' +
      JSON.stringify({
        v: 1,
        data: {
          id: 'x',
          name: 'X',
          type: 'text',
          locked: false,
          pipeline: [],
          resultType: 'text',
        },
      }).replace(/"/g, '\\"') +
      '")';
    const md = [
      '```if-block {"id":"if_2","v":1}',
      '[[IF {"id":"b1","condition":{"variableId":"v","pipeline":[],"resultType":"boolean"}}]]',
      `- one ${varDirective}`,
      '- two',
      '```',
    ].join('\n');
    const doc = markdownToDoc(md);
    const block = doc.content.find((n) => n.type === 'ifBlock');
    const branch0 = (block?.content ?? []).find((n) => n.type === 'ifBranch');
    const bodyJson = JSON.stringify(branch0?.content);
    expect(bodyJson).toContain('"type":"bulletList"');
    expect(bodyJson).toContain('"type":"variable"');
    expect(roundTrip(md)).toBe(md);
  });

  it('nests an if-block inside a branch body and round-trips (depth 2)', () => {
    const md = [
      '```if-block {"id":"outer","v":1}',
      '[[IF {"id":"o1","condition":{"variableId":"a","pipeline":[],"resultType":"boolean"}}]]',
      '```if-block {"id":"inner","v":1}',
      '[[IF {"id":"i1","condition":{"variableId":"b","pipeline":[],"resultType":"boolean"}}]]',
      'Deep.',
      '```',
      '```',
    ].join('\n');
    expect(roundTrip(md)).toBe(md);
  });

  it('round-trips a condition with a NON-EMPTY operations pipeline', () => {
    const md = [
      '```if-block {"id":"if_3","v":1}',
      '[[IF {"id":"b1","condition":{"variableId":"order_total","pipeline":[{"stepId":"s1","operationId":"greaterThan","args":{"value":100},"outputType":"boolean"}],"resultType":"boolean"}}]]',
      'Big order.',
      '[[ELSE {"id":"b2"}]]',
      'Small order.',
      '```',
    ].join('\n');
    const doc = markdownToDoc(md);
    const block = doc.content.find((n) => n.type === 'ifBlock');
    const ifBranch = (block?.content ?? []).find((n) => n.type === 'ifBranch');
    const cond = ifBranch?.attrs?.condition as {
      pipeline: Array<{ operationId: string; args: Record<string, unknown> }>;
    };
    expect(cond.pipeline).toHaveLength(1);
    expect(cond.pipeline[0].operationId).toBe('greaterThan');
    expect(cond.pipeline[0].args).toEqual({ value: 100 });
    expect(roundTrip(md)).toBe(md);
  });

  it('round-trips nested branches (depth 3) containing variables + marks', () => {
    const varDirective =
      '@[variable]("' +
      JSON.stringify({
        v: 1,
        data: { id: 'name', name: 'Name', type: 'text', locked: false, pipeline: [], resultType: 'text' },
      }).replace(/"/g, '\\"') +
      '")';
    const md = [
      '```if-block {"id":"d1","v":1}',
      '[[IF {"id":"a","condition":{"variableId":"x","pipeline":[],"resultType":"boolean"}}]]',
      '```if-block {"id":"d2","v":1}',
      '[[IF {"id":"b","condition":{"variableId":"y","pipeline":[],"resultType":"boolean"}}]]',
      '```if-block {"id":"d3","v":1}',
      '[[IF {"id":"c","condition":{"variableId":"z","pipeline":[],"resultType":"boolean"}}]]',
      `Hi **bold** ${varDirective}`,
      '```',
      '```',
      '```',
    ].join('\n');
    const out = roundTrip(md);
    expect(out).toBe(md);
    expect(out).toContain('@[variable]');
    expect(out).toContain('**bold**');
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// Mixed document
// ─────────────────────────────────────────────────────────────────────────────
describe('mixed directive document', () => {
  it('is idempotent across mentions, variables, ai chips, and if-blocks', () => {
    const mention = '@[mention]("{\\"v\\":1,\\"data\\":{\\"id\\":\\"u\\",\\"name\\":\\"U\\",\\"avatar\\":\\"\\"}}")';
    const md = [
      '# Title',
      '',
      `Para with ${mention} and text.`,
      '',
      '```if-block {"id":"if","v":1}',
      '[[IF {"id":"b","condition":{"variableId":"c","pipeline":[],"resultType":"boolean"}}]]',
      'Body.',
      '```',
    ].join('\n');
    const once = roundTrip(md);
    expect(roundTrip(once)).toBe(once);
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// Graceful degradation (registry-less core)
// ─────────────────────────────────────────────────────────────────────────────
describe('graceful degradation without the registry', () => {
  // NOTE: runs last — it clears the global registry. afterAll restores nothing
  // (the process ends), but other describe blocks already ran above.
  afterAll(() => __resetParserState());

  it('a doc with PART 2 nodes serialized by a registry-less core degrades to text', () => {
    __clearMarkdownNodes();
    const doc: MarkdownDoc = {
      type: 'doc',
      content: [
        {
          type: 'paragraph',
          content: [
            { type: 'text', text: 'Hi ' },
            { type: 'mention', attrs: { id: 'u', name: 'U' } },
          ],
        },
        {
          type: 'ifBlock',
          attrs: { id: 'if' },
          content: [
            {
              type: 'ifBranch',
              attrs: { id: 'b', kind: 'if', condition: null },
              content: [{ type: 'paragraph', content: [{ type: 'text', text: 'inner' }] }],
            },
          ],
        },
      ],
    };
    // No throw; the surrounding text survives, and NO directive bytes are emitted
    // because no handler is registered (mention → nothing; if-block → empty, since
    // its body lives in attrs.branches which the core can't see).
    expect(() => docToMarkdown(doc as JSONNode)).not.toThrow();
    const out = docToMarkdown(doc as JSONNode);
    expect(out).toContain('Hi');
    expect(out).not.toContain('@[mention]');
    expect(out).not.toContain('```if-block');
  });

  it('directive source in markdown stays literal text when no parser is registered', () => {
    __clearMarkdownNodes();
    const md = 'plain @[mention]("{\\"v\\":1,\\"data\\":{\\"id\\":\\"u\\"}}") text';
    // Without an inline parser the directive is left as-is (marked may treat it
    // as a link, but it must NOT crash and must round-trip stably).
    expect(() => markdownToDoc(md)).not.toThrow();
    const out = roundTrip(md);
    expect(roundTrip(out)).toBe(out);
  });
});
