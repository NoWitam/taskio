// @vitest-environment happy-dom
// Unit tests for the generator catalog → shared-editor adapter: a `slots.<name>` variable flows
// into the MarkdownEditor's identity definitions + the live source-var feed, workspace globals ride
// through, and a wire-defined `fn:<uuid>` operation keeps its own label.
import { beforeEach, describe, expect, it } from 'vitest';
import { setLocale } from '../../../app/i18n';
import {
  templateEditorVariables,
  templateOperationsCatalog,
  templateSourceVariables,
} from '../templateCatalog';
import { buildVariableTree, findNodeByPath } from '../../../ui/variables/variableTree';
import type { GeneratorCatalog } from '../types';

const FILE_FIELDS = [
  { key: 'id', label: 'id', descriptor: { base: 'text' as const, nullable: false, array: false } },
  { key: 'name', label: 'name', descriptor: { base: 'text' as const, nullable: false, array: false } },
  { key: 'type', label: 'type', descriptor: { base: 'text' as const, nullable: false, array: false } },
  { key: 'size', label: 'size', descriptor: { base: 'number' as const, nullable: false, array: false } },
  { key: 'url', label: 'url', descriptor: { base: 'text' as const, nullable: false, array: false } },
];

const catalog: GeneratorCatalog = {
  variables: [
    { source: 'slots', path: 'slots.topic', name: 'topic', type: 'text', descriptor: { base: 'text', nullable: false, array: false } },
    { source: 'globals', path: 'globals.brand', name: 'Brand', type: 'text', descriptor: { base: 'text', nullable: false, array: false } },
  ],
  operations: [
    { id: 'fn:abc', input: 'text', output: 'text', args: [], label: 'My Function' },
  ],
  types: [],
};

// A BE-FAITHFUL structural-slot catalog — the exact shape WorkflowVariableCatalogService emits for
// a template's declared slots (verified against the object-global emission it mirrors):
//   • an OBJECT slot is ONE variable carrying its subfields in `descriptor.fields` (scalar / nested
//     leaves) and NO flat `slots.<name>.<field>` entry, and
//   • a FILE slot is ONE variable carrying the file composite `descriptor.fields` ({id,name,type,
//     size,url}) — again with no flat subfield entries.
// Nothing here fabricates a flat leaf the backend never emits; the tree/definition machinery is what
// turns these descriptors into pickable references.
const SLOT_CATALOG: GeneratorCatalog = {
  variables: [
    {
      source: 'slots',
      path: 'slots.product',
      name: 'Product',
      type: 'text',
      descriptor: {
        base: 'object',
        nullable: false,
        array: false,
        fields: [{ key: 'name', label: 'Name', descriptor: { base: 'text', nullable: false, array: false } }],
      },
    },
    {
      source: 'slots',
      path: 'slots.attachment',
      name: 'Attachment',
      type: 'file',
      descriptor: { base: 'file', nullable: false, array: false, fields: FILE_FIELDS },
    },
  ],
  operations: [],
  types: [],
};

beforeEach(() => setLocale('en'));

describe('templateEditorVariables', () => {
  it('offers the slot as an identity definition (id === path)', () => {
    const definitions = templateEditorVariables(catalog);
    expect(definitions.some((d) => d.id === 'slots.topic')).toBe(true);
    expect(definitions.some((d) => d.id === 'globals.brand')).toBe(true);
  });

  it('degrades to an empty list for a null catalog', () => {
    expect(templateEditorVariables(null)).toEqual([]);
  });
});

describe('templateSourceVariables', () => {
  it('carries the slot as a source var at source "slots"', () => {
    const vars = templateSourceVariables(catalog);
    const slot = vars.find((v) => v.path === 'slots.topic');
    expect(slot).toBeTruthy();
    expect(slot?.source).toBe('slots');
  });
});

describe('templateOperationsCatalog', () => {
  it('keeps a wire-defined fn operation with its own label', () => {
    const ops = templateOperationsCatalog(catalog);
    const fn = ops.find((op) => op.id === 'fn:abc');
    expect(fn?.label).toBe('My Function');
  });
});

describe('slots picker expansion (through the live templateSourceVariables feed)', () => {
  // The editor browses `buildVariableTree(templateSourceVariables(catalog))` — the SAME path
  // TemplateEditorDrawer wires via `source: () => sourceVariables`. This test goes through that REAL
  // feed (not `buildVariableTree` directly, which would bypass `expandVariables` and hide the bug).
  it('expands a slots OBJECT to its subfields and a slots FILE to its {id,name,type,size,url}', () => {
    const tree = buildVariableTree(templateSourceVariables(SLOT_CATALOG));

    // An object slot is EXPAND-ONLY (its children are pickable) — exactly like an object global.
    const product = findNodeByPath(tree, 'slots.product');
    expect(product?.selectable).toBe(false);
    expect(product?.children?.map((child) => child.path)).toContain('slots.product.name');
    expect(findNodeByPath(tree, 'slots.product.name')?.selectable).toBe(true);

    // A file slot is BOTH selectable (whole-file ref) AND expandable (its 5 fixed subfields).
    const attachment = findNodeByPath(tree, 'slots.attachment');
    expect(attachment?.selectable).toBe(true);
    expect(attachment?.children?.map((child) => child.path)).toEqual([
      'slots.attachment.id',
      'slots.attachment.name',
      'slots.attachment.type',
      'slots.attachment.size',
      'slots.attachment.url',
    ]);
  });
});

describe('slots identity definitions (BE-faithful, no fabricated flat subfield)', () => {
  // The old test HAND-ADDED a flat `slots.product.name` catalog variable — a shape the backend never
  // emits — which masked the bug. With the fabrication gone, an object slot rides as ONE `object`
  // identity (its subfields reached through the tree above, exactly like an object global); only a
  // FILE slot flattens its subfields into identity definitions (`fileSubfieldVariable`).
  it('rides an object slot as ONE object identity; a file slot flattens its subfields', () => {
    const definitions = templateEditorVariables(SLOT_CATALOG);

    const product = definitions.find((d) => d.id === 'slots.product');
    expect(product?.base).toBe('object');
    expect(definitions.some((d) => d.id === 'slots.product.name')).toBe(false);

    expect(definitions.some((d) => d.id === 'slots.attachment.url')).toBe(true);
  });
});

describe('regression: an object slot is REFERENCEABLE through the real editor feed', () => {
  // FAILS BEFORE the `expandVariables` fix, PASSES AFTER. Before the fix a `source:'slots'` object
  // was routed through `sectionContainerVariable` (correct for a FORM SECTION), which DROPS
  // `descriptor.fields`; the container then had no children and `pruneDeadNodes` removed it, so
  // `slots.product.name` was neither in the tree nor selectable — a user could declare an object
  // slot but never reference it or its fields. This asserts the whole real path
  // (`templateSourceVariables` → `buildVariableTree`) now yields SELECTABLE subfields.
  it('makes slots.product.name and slots.attachment.url selectable nodes', () => {
    const tree = buildVariableTree(templateSourceVariables(SLOT_CATALOG));

    expect(findNodeByPath(tree, 'slots.product.name')?.selectable).toBe(true);
    expect(findNodeByPath(tree, 'slots.attachment.url')?.selectable).toBe(true);
  });
});
