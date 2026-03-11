import { describe, it, expect } from 'vitest';
import type { JSONContent } from '@tiptap/core';
import { parseMarkdown } from '../utils/parse';
import { serializeDocument } from '../utils/serialize';

const sampleDoc: JSONContent = {
  type: 'doc',
  content: [
    {
      type: 'heading',
      attrs: { level: 2 },
      content: [{ type: 'text', text: 'Podsumowanie' }],
    },
    {
      type: 'paragraph',
      content: [
        { type: 'text', text: 'Hej ' },
        { type: 'mention', attrs: { id: 'u_marta', name: 'Marta Kowalska', avatar: '/avatars/marta.png' } },
        { type: 'text', text: ', status zamówienia to ' },
        {
          type: 'variable',
          attrs: {
            id: 'var_total',
            name: 'Suma zamówienia',
            type: 'number',
            locked: false,
            pipeline: [],
            resultType: 'number',
          },
        },
        { type: 'text', text: '. ' },
        { type: 'aiText', attrs: { id: 'ai_1', personaId: null, prompt: 'Napisz CTA', labels: [] } },
      ],
    },
    {
      type: 'ifBlock',
      attrs: {
        id: 'if_block_1',
        branches: [
          {
            id: 'if_branch_1',
            kind: 'if',
            condition: { variableId: 'var_has_discount', pipeline: [], resultType: 'boolean' },
            content: {
              type: 'doc',
              content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Dodaj sekcję o rabacie.' }] }],
            },
          },
          {
            id: 'else_branch_1',
            kind: 'else',
            content: {
              type: 'doc',
              content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Brak rabatu.' }] }],
            },
          },
        ],
      },
    },
  ],
};

describe('MarkdownEditor serialization', () => {
  it('round trips doc -> markdown -> doc', () => {
    const markdown = serializeDocument(sampleDoc);
    const parsed = parseMarkdown(markdown);
    expect(parsed).toEqual(sampleDoc);
  });

  it('round trips markdown -> doc -> markdown', () => {
    const markdown = [
      '## Podsumowanie',
      '',
      'Hej @[mention]("{\\"v\\":1,\\"data\\":{\\"id\\":\\"u_marta\\",\\"name\\":\\"Marta Kowalska\\"}}"), status zamówienia to @[variable]("{\\"v\\":1,\\"data\\":{\\"id\\":\\"var_total\\",\\"name\\":\\"Suma zamówienia\\",\\"type\\":\\"number\\",\\"locked\\":false,\\"pipeline\\":[],\\"resultType\\":\\"number\\"}}") . @[ai-text]("{\\"v\\":1,\\"data\\":{\\"id\\":\\"ai_1\\",\\"personaId\\":null,\\"prompt\\":\\"Napisz CTA\\",\\"labels\\":[]}}")',
      '',
      '```if-block {"id":"if_block_1","v":1}',
      '[[IF {"id":"if_branch_1","condition":{"variableId":"var_has_discount","pipeline":[],"resultType":"boolean"}}]]',
      'Dodaj sekcję o rabacie.',
      '[[ELSE {"id":"else_branch_1"}]]',
      'Brak rabatu.',
      '```',
      '',
    ].join('\n');

    const doc = parseMarkdown(markdown);
    const again = serializeDocument(doc);
    expect(again.trim()).toEqual(markdown.trim());
  });
});
