// Behavioural tests for the SHARED variable model (B1). These pin THE SINGLE RULE —
// expandable ⟺ has children, selectable ⟺ not a non-array object container — plus the
// slot policy, the SF3.2 identifier strip (with its file-subfield exception) and the
// search/traversal helpers. No snapshots, no mounting: the module is pure by contract.
import { describe, it, expect } from 'vitest';
import {
  buildVariableTree,
  findNodeByPath,
  flattenNodes,
  nodeIcon,
  searchNodes,
} from '../variableTree';
import type { VariableNode, VariableSourceVar } from '../types';

// --- Fixtures ---------------------------------------------------------------
// These mirror the shapes the backend catalog actually emits (WorkflowVariableCatalog):
// a FILE composite advertises its {id,name,type,size,url} subfields; a form SECTION is an
// `object` container whose leaves are ALSO emitted flat; a REPEATER is an `array<object>`
// with NO flat leaves; an object GLOBAL is self-contained (fields, no flat leaves).

/** The `trigger.fields.attachment` FILE composite (5 fixed subfields, incl. `id`). */
const FILE_VAR: VariableSourceVar = {
  source: 'trigger',
  path: 'trigger.fields.attachment',
  name: 'Attachment',
  type: 'file',
  descriptor: {
    base: 'file',
    nullable: false,
    array: false,
    fields: [
      { key: 'id', label: 'id', descriptor: { base: 'text', nullable: false, array: false } },
      { key: 'name', label: 'name', descriptor: { base: 'text', nullable: false, array: false } },
      { key: 'size', label: 'size', descriptor: { base: 'number', nullable: false, array: false } },
    ],
  },
};

/** The `trigger.fields.details` SECTION container … */
const SECTION_VAR: VariableSourceVar = {
  source: 'trigger',
  path: 'trigger.fields.details',
  name: 'Details',
  type: 'text',
  descriptor: {
    base: 'object',
    nullable: false,
    array: false,
    fields: [{ key: 'note', label: 'Note', descriptor: { base: 'text', nullable: false, array: false } }],
  },
};

/** … and its flat leaves, ALSO emitted top-level by the backend's leaf pass. */
const SECTION_LEAVES: VariableSourceVar[] = [
  { source: 'trigger', path: 'trigger.fields.details.note', name: 'Note', type: 'text' },
  {
    source: 'trigger',
    path: 'trigger.fields.details.section_tags',
    name: 'Section tags',
    type: 'multi',
    enumOptions: ['x', 'y'],
  },
];

/** The `trigger.fields.items` REPEATER (array<object>) — no flat leaves. */
const REPEATER_VAR: VariableSourceVar = {
  source: 'trigger',
  path: 'trigger.fields.items',
  name: 'Items',
  type: 'text',
  descriptor: {
    base: 'object',
    nullable: false,
    array: true,
    fields: [{ key: 'item_name', label: 'Item name', descriptor: { base: 'text', nullable: false, array: false } }],
  },
};

/** A user-authored object GLOBAL with a NESTED object inside it. */
const GLOBAL_VAR: VariableSourceVar = {
  source: 'globals',
  path: 'globals.company',
  name: 'Company',
  type: 'text',
  descriptor: {
    base: 'object',
    nullable: false,
    array: false,
    fields: [
      { key: 'name', label: 'Name', descriptor: { base: 'text', nullable: false, array: false } },
      {
        key: 'address',
        label: 'Address',
        descriptor: {
          base: 'object',
          nullable: false,
          array: false,
          fields: [{ key: 'city', label: 'City', descriptor: { base: 'text', nullable: true, array: false } }],
        },
      },
    ],
  },
};

/** Depth-first path list — the whole tree shape in one assertion-friendly array. */
function paths(nodes: VariableNode[]): string[] {
  return flattenNodes(nodes).map((node) => node.path);
}
function at(nodes: VariableNode[], path: string): VariableNode {
  const node = findNodeByPath(nodes, path);
  expect(node, `expected a node at ${path}`).not.toBeNull();
  return node as VariableNode;
}

// --- The single rule --------------------------------------------------------

describe('buildVariableTree — object containers expand, never select', () => {
  it('nests a SECTION\'s leaves under it EXACTLY once (never also flat)', () => {
    const tree = buildVariableTree([SECTION_VAR, ...SECTION_LEAVES]);

    // ONE root: the section. Both leaves live under it, and nowhere else.
    expect(tree.map((node) => node.path)).toEqual(['trigger.fields.details']);
    expect(paths(tree).filter((p) => p === 'trigger.fields.details.note')).toHaveLength(1);
    expect(tree[0].children?.map((child) => child.path)).toEqual([
      'trigger.fields.details.note',
      'trigger.fields.details.section_tags',
    ]);
    // The flat catalog entry wins over the descriptor field of the same key (real name/type).
    expect(at(tree, 'trigger.fields.details.note').label).toBe('Note');
    expect(at(tree, 'trigger.fields.details.section_tags').type).toBe('multi');
  });

  it('makes a SECTION expandable but NOT selectable', () => {
    const tree = buildVariableTree([SECTION_VAR, ...SECTION_LEAVES]);
    const section = tree[0];

    expect(section.selectable).toBe(false);
    expect(section.children).toHaveLength(2);
    expect(section.base).toBe('object'); // reads as a container despite the degraded flat type
    expect(section.children?.every((child) => child.selectable)).toBe(true);
  });

  it('makes an object GLOBAL expandable but NOT selectable, recursing into nested objects', () => {
    const tree = buildVariableTree([GLOBAL_VAR]);

    expect(tree[0].path).toBe('globals.company');
    expect(tree[0].selectable).toBe(false); // a whole object resolves to a map — never a ref
    expect(paths(tree)).toEqual([
      'globals.company',
      'globals.company.name',
      'globals.company.address',
      'globals.company.address.city',
    ]);
    // The nested object obeys the SAME rule one level down …
    expect(at(tree, 'globals.company.address').selectable).toBe(false);
    expect(at(tree, 'globals.company.address').children).toHaveLength(1);
    // … and its scalar leaf is selectable, carrying its own nullable marker.
    expect(at(tree, 'globals.company.address.city').selectable).toBe(true);
    expect(at(tree, 'globals.company.address.city').nullable).toBe(true);
  });

  it('makes a FILE composite BOTH expandable and selectable', () => {
    const tree = buildVariableTree([FILE_VAR]);
    const file = tree[0];

    expect(file.selectable).toBe(true); // the whole-file ref is a valid reference
    expect(file.children?.map((child) => child.path)).toEqual([
      'trigger.fields.attachment.id',
      'trigger.fields.attachment.name',
      'trigger.fields.attachment.size',
    ]);
    expect(at(tree, 'trigger.fields.attachment.size').type).toBe('number');
    expect(file.children?.every((child) => child.selectable)).toBe(true);
  });

  it('makes a REPEATER selectable but NOT expandable (per-element access deferred)', () => {
    const tree = buildVariableTree([REPEATER_VAR]);

    expect(tree).toHaveLength(1);
    expect(tree[0].selectable).toBe(true); // the whole list IS a value
    expect(tree[0].children).toBeUndefined(); // no per-element children, from either source
    expect(tree[0].array).toBe(true); // the flag the caller turns into the "(list)" affordance
  });

  it('never attaches a flat leaf inside a repeater (it re-roots instead of vanishing)', () => {
    const tree = buildVariableTree([
      REPEATER_VAR,
      { source: 'trigger', path: 'trigger.fields.items.stray', name: 'Stray', type: 'text' },
    ]);

    expect(tree.map((node) => node.path)).toEqual(['trigger.fields.items', 'trigger.fields.items.stray']);
    expect(tree[0].children).toBeUndefined();
  });

  it('nests plain flat dotted paths and leaves a non-structural list untouched', () => {
    const tree = buildVariableTree([
      { source: 'trigger', path: 'trigger.form', name: 'Form', type: 'text' },
      { source: 'trigger', path: 'trigger.form.name', name: 'Form name', type: 'text' },
      { source: 'steps', path: 'steps.make.title', name: 'make.title', type: 'text' },
    ]);

    // `a.b` nests under `a` — and a scalar parent stays selectable (it is not an object).
    expect(tree.map((node) => node.path)).toEqual(['trigger.form', 'steps.make.title']);
    expect(tree[0].selectable).toBe(true);
    expect(tree[0].children?.map((child) => child.path)).toEqual(['trigger.form.name']);
    expect(tree[1].children).toBeUndefined();
    expect(tree[1].selectable).toBe(true);
  });

  it('drops an object container that would have no children at all', () => {
    const tree = buildVariableTree([
      { ...SECTION_VAR, descriptor: { base: 'object', nullable: false, array: false, fields: [] } },
    ]);
    // Neither selectable nor expandable ⇒ it could only render as an inert row.
    expect(tree).toEqual([]);
  });
});

// --- The emitted SOURCE -----------------------------------------------------

describe('buildVariableTree — every node carries its root source', () => {
  it('carries the variable\'s OWN source, and a descriptor child inherits its parent\'s', () => {
    const tree = buildVariableTree([FILE_VAR, GLOBAL_VAR]);

    expect(at(tree, 'trigger.fields.attachment').source).toBe('trigger');
    expect(at(tree, 'trigger.fields.attachment.name').source).toBe('trigger');
    expect(at(tree, 'globals.company').source).toBe('globals');
    expect(at(tree, 'globals.company.address.city').source).toBe('globals');
  });

  it('keeps the source even when pathTransform strips the root the path carried', () => {
    // The conditions surface emits `fields.<id>`, which no longer names its root — so the
    // source can NEVER be re-derived from the path. It rides from the variable itself.
    const tree = buildVariableTree([SECTION_VAR, ...SECTION_LEAVES], {
      pathTransform: (path) => path.replace(/^trigger\./, ''),
    });

    expect(tree[0].path).toBe('fields.details');
    expect(flattenNodes(tree).every((node) => node.source === 'trigger')).toBe(true);
  });

  it('a step output keeps `steps`, so a pick emits the right ref arm', () => {
    const tree = buildVariableTree([
      { source: 'steps', path: 'steps.make.title', name: 'make.title', type: 'text' },
    ]);
    expect(tree[0].source).toBe('steps');
  });
});

// --- The slot policy --------------------------------------------------------

describe('buildVariableTree — VariableSlotPolicy', () => {
  it('acceptedPaths DROPS a non-listed leaf (it could only be a dead row) but keeps its walkable section', () => {
    const tree = buildVariableTree([SECTION_VAR, ...SECTION_LEAVES], {
      acceptedPaths: new Set(['trigger.fields.details.note']),
    });

    // The section survives: not selectable, but still expandable to its accepted leaf.
    expect(tree.map((node) => node.path)).toEqual(['trigger.fields.details']);
    expect(tree[0].selectable).toBe(false);
    expect(at(tree, 'trigger.fields.details.note').selectable).toBe(true);
    // The excluded leaf is neither selectable NOR expandable ⇒ pruned, not shown inert.
    expect(findNodeByPath(tree, 'trigger.fields.details.section_tags')).toBeNull();
  });

  it('prunes an excluded REPEATER, and prunes a container whose children were ALL excluded', () => {
    const tree = buildVariableTree([SECTION_VAR, ...SECTION_LEAVES, REPEATER_VAR], {
      // The conditions surface: only real condition FIELDS are valid source paths, so the
      // repeater (no flat leaf) and every other unlisted path can only be a dead row.
      acceptedPaths: new Set(['trigger.fields.nothing.here']),
    });

    expect(tree).toEqual([]);
  });

  it('keeps an excluded node that is still EXPANDABLE (a file composite stays walkable)', () => {
    const tree = buildVariableTree([FILE_VAR], {
      acceptedPaths: new Set(['trigger.fields.attachment.name']),
    });

    // The whole-file ref is not accepted here, but its subfield is — so the file row stays
    // as a pure container and only its accepted child can be picked.
    expect(tree[0].path).toBe('trigger.fields.attachment');
    expect(tree[0].selectable).toBe(false);
    expect(tree[0].children?.map((child) => child.path)).toEqual(['trigger.fields.attachment.name']);
  });

  it('acceptedPaths can only take selection away — never grants it to an object container', () => {
    const tree = buildVariableTree([SECTION_VAR, ...SECTION_LEAVES], {
      acceptedPaths: new Set(['trigger.fields.details', 'trigger.fields.details.note']),
    });

    expect(tree[0].selectable).toBe(false); // listed, and STILL not selectable
    expect(at(tree, 'trigger.fields.details.note').selectable).toBe(true);
  });

  it('pathTransform emits `fields.<id>` paths, including for descriptor children', () => {
    const policy = { pathTransform: (path: string) => path.replace(/^trigger\./, '') };
    const tree = buildVariableTree([SECTION_VAR, ...SECTION_LEAVES, FILE_VAR], policy);

    expect(paths(tree)).toEqual([
      'fields.details',
      'fields.details.note',
      'fields.details.section_tags',
      'fields.attachment',
      // Composed from the ALREADY-transformed parent path — the strip never double-applies.
      'fields.attachment.id',
      'fields.attachment.name',
      'fields.attachment.size',
    ]);
  });

  it('composes pathTransform with acceptedPaths on the EMITTED path (the conditions surface)', () => {
    // Exactly how the condition builder will drive it: only the catalog's condition FIELDS
    // are valid `source` paths; their section must still be walkable.
    const tree = buildVariableTree([SECTION_VAR, ...SECTION_LEAVES], {
      pathTransform: (path) => path.replace(/^trigger\./, ''),
      acceptedPaths: new Set(['fields.details.note', 'fields.details.section_tags']),
    });

    expect(tree[0].path).toBe('fields.details');
    expect(tree[0].selectable).toBe(false);
    expect(tree[0].children?.every((child) => child.selectable)).toBe(true);
  });
});

// --- The identifier strip ---------------------------------------------------

describe('buildVariableTree — the SF3.2 SYSTEM-identifier strip', () => {
  it('hides SYSTEM `*.id` / `*_id` variables from the offered tree', () => {
    const tree = buildVariableTree([
      { source: 'trigger', path: 'trigger.submission.id', name: 'Submission ID', type: 'text' },
      { source: 'trigger', path: 'trigger.form.name', name: 'Form name', type: 'text' },
      { source: 'steps', path: 'steps.make.task_id', name: 'make.task_id', type: 'text' },
      // The dot / underscore boundary must not swallow a legitimate field.
      { source: 'trigger', path: 'trigger.fields.paid', name: 'Paid', type: 'boolean' },
      { source: 'trigger', path: 'trigger.fields.valid', name: 'Valid', type: 'boolean' },
    ]);

    expect(paths(tree)).toEqual([
      'trigger.form.name',
      'trigger.fields.paid',
      'trigger.fields.valid',
    ]);
  });

  it('keeps a FILE subfield `id` — from the descriptor AND from a pre-expanded flat list', () => {
    // (a) synthesized from the composite's descriptor …
    expect(paths(buildVariableTree([FILE_VAR]))).toContain('trigger.fields.attachment.id');

    // (b) … and when the caller feeds a list that was already expanded upstream.
    const preExpanded = buildVariableTree([
      { source: 'trigger', path: 'trigger.fields.attachment', name: 'Attachment', type: 'file', descriptor: { base: 'file', nullable: false, array: false } },
      { source: 'trigger', path: 'trigger.fields.attachment.id', name: 'Attachment › ID', type: 'text' },
      { source: 'trigger', path: 'trigger.fields.attachment.name', name: 'Attachment › Name', type: 'text' },
    ]);
    expect(paths(preExpanded)).toEqual([
      'trigger.fields.attachment',
      'trigger.fields.attachment.id',
      'trigger.fields.attachment.name',
    ]);
    expect(at(preExpanded, 'trigger.fields.attachment.id').selectable).toBe(true);
  });

  // B3 — the strip is scoped to SYSTEM identity paths BY CONSTRUCTION, so a USER-AUTHORED
  // form field named `order_id` / `numer_id` is offered on EVERY surface, with NO per-slot
  // opt-out (the old `stripIdentifiers` policy flag is gone). Before this, such a field
  // silently disappeared from the step-field picker and the markdown feed.
  it('NEVER strips a user-named `*_id` FORM FIELD — on any surface, with no policy needed', () => {
    // Both spellings of the form-field namespace: the catalog's `trigger.fields.<id>` and
    // the conditions surface's prefix-stripped `fields.<id>`.
    const feed: VariableSourceVar[] = [
      { source: 'trigger', path: 'fields.order_id', name: 'Order number', type: 'text' },
      { source: 'trigger', path: 'trigger.fields.numer_id', name: 'Numer ID', type: 'text' },
      { source: 'trigger', path: 'fields.name', name: 'Name', type: 'text' },
    ];

    for (const tree of [buildVariableTree(feed), buildVariableTree(feed, {})]) {
      expect(paths(tree)).toEqual(['fields.order_id', 'trigger.fields.numer_id', 'fields.name']);
      expect(at(tree, 'fields.order_id').selectable).toBe(true);
      expect(at(tree, 'trigger.fields.numer_id').selectable).toBe(true);
    }
  });

  it('keeps a user-named `*_id` descriptor CHILD of a form-field container too', () => {
    const container: VariableSourceVar = {
      source: 'trigger',
      path: 'fields.contact',
      name: 'Contact',
      type: 'text',
      descriptor: {
        base: 'object',
        nullable: false,
        array: false,
        fields: [
          { key: 'customer_id', label: 'Customer id', descriptor: { base: 'text', nullable: false, array: false } },
          { key: 'email', label: 'Email', descriptor: { base: 'text', nullable: false, array: false } },
        ],
      },
    };

    expect(paths(buildVariableTree([container]))).toEqual([
      'fields.contact',
      'fields.contact.customer_id',
      'fields.contact.email',
    ]);
  });

  // B4 — FLIPPED. This used to assert that an object GLOBAL's `id` field was stripped, on the
  // grounds that only form fields are user-authored. A workspace global (its key AND its object
  // fields) is authored by a user too, so `globals.*` joined the exempt namespace: someone who
  // names a global `order_id` — or gives an object global an `id` field — was silently losing it
  // from every picker, which is the exact defect the form-field exemption already fixed.
  it('NEVER strips a user-named id under `globals.*` (the key OR an object field)', () => {
    const tree = buildVariableTree([
      { source: 'globals', path: 'globals.order_id', name: 'Order number', type: 'text' },
      {
        source: 'globals',
        path: 'globals.thing',
        name: 'Thing',
        type: 'text',
        descriptor: {
          base: 'object',
          nullable: false,
          array: false,
          fields: [
            { key: 'id', label: 'Id', descriptor: { base: 'text', nullable: false, array: false } },
            { key: 'label', label: 'Label', descriptor: { base: 'text', nullable: false, array: false } },
          ],
        },
      },
    ]);

    expect(paths(tree)).toEqual([
      'globals.order_id',
      'globals.thing',
      'globals.thing.id',
      'globals.thing.label',
    ]);
    expect(at(tree, 'globals.order_id').selectable).toBe(true);
    expect(at(tree, 'globals.thing.id').selectable).toBe(true);
  });

  it('still strips an `id` child of a SYSTEM (non-user-authored) container', () => {
    // A step output's object: the engine names everything inside it, so its identity key stays a
    // machine key and is never offered.
    const tree = buildVariableTree([
      {
        source: 'steps',
        path: 'steps.make.thing',
        name: 'make.thing',
        type: 'text',
        descriptor: {
          base: 'object',
          nullable: false,
          array: false,
          fields: [
            { key: 'id', label: 'Id', descriptor: { base: 'text', nullable: false, array: false } },
            { key: 'label', label: 'Label', descriptor: { base: 'text', nullable: false, array: false } },
          ],
        },
      },
    ]);

    expect(paths(tree)).toEqual(['steps.make.thing', 'steps.make.thing.label']);
  });
});

// --- Search + traversal -----------------------------------------------------

describe('searchNodes', () => {
  const TREE = buildVariableTree([SECTION_VAR, ...SECTION_LEAVES, FILE_VAR, GLOBAL_VAR]);

  it('finds deep selectable matches and carries the ancestor breadcrumb', () => {
    const hits = searchNodes(TREE, 'city');

    expect(hits).toHaveLength(1);
    expect(hits[0].node.path).toBe('globals.company.address.city');
    expect(hits[0].breadcrumb).toEqual(['Company', 'Address']);
  });

  it('never returns a non-selectable object container, even on an exact label match', () => {
    // The tree DOES hold non-selectable containers ("Details", "Company", "Address") …
    expect(flattenNodes(TREE).filter((node) => !node.selectable).map((node) => node.path)).toEqual([
      'trigger.fields.details',
      'globals.company',
      'globals.company.address',
    ]);
    // … and an exact label hit on one still never yields it as a pickable result.
    for (const query of ['Details', 'Company', 'Address', '']) {
      const hits = searchNodes(TREE, query);
      expect(hits.every((hit) => hit.node.selectable)).toBe(true);
      expect(hits.some((hit) => hit.node.path === 'trigger.fields.details')).toBe(false);
      expect(hits.some((hit) => hit.node.path === 'globals.company')).toBe(false);
    }
  });

  it('matches the PATH too, so a container key surfaces its leaves', () => {
    // "details" matches no leaf LABEL, but every leaf path carries the section key.
    expect(searchNodes(TREE, 'details').map((hit) => hit.node.path)).toEqual([
      'trigger.fields.details.note',
      'trigger.fields.details.section_tags',
    ]);
  });

  it('is case-insensitive and trims the query', () => {
    expect(searchNodes(TREE, '  ATTACH  ').map((hit) => hit.node.path)).toContain(
      'trigger.fields.attachment',
    );
  });
});

describe('flattenNodes / findNodeByPath', () => {
  const TREE = buildVariableTree([FILE_VAR, GLOBAL_VAR]);

  it('walks the whole tree pre-order and resolves a node at any depth', () => {
    expect(flattenNodes(TREE)).toHaveLength(8); // 1 file + 3 subfields, 1 global + 2 + 1
    expect(findNodeByPath(TREE, 'globals.company.address.city')?.label).toBe('City');
    expect(findNodeByPath(TREE, 'nope.missing')).toBeNull();
    expect(findNodeByPath(TREE, null)).toBeNull();
  });
});

// --- Icons + fail-soft ------------------------------------------------------

describe('nodeIcon', () => {
  it('prefers the container glyph over the degraded flat type, else maps the flat type', () => {
    const tree = buildVariableTree([SECTION_VAR, ...SECTION_LEAVES, FILE_VAR, REPEATER_VAR]);

    expect(nodeIcon(at(tree, 'trigger.fields.details'))).toBe('braces'); // flat type is `text`
    expect(nodeIcon(at(tree, 'trigger.fields.items'))).toBe('braces'); // a repeater is an object too
    expect(nodeIcon(at(tree, 'trigger.fields.attachment'))).toBe('file-text');
    expect(nodeIcon(at(tree, 'trigger.fields.attachment.size'))).toBe('hash');
    expect(nodeIcon(at(tree, 'trigger.fields.details.section_tags'))).toBe('list-checks');
  });

  it('falls back to the text glyph for an unknown type', () => {
    expect(nodeIcon({ type: 'wat' as never, base: 'wat' as never })).toBe('type');
  });
});

describe('fail-soft', () => {
  it('tolerates an unknown descriptor base, a missing descriptor and malformed entries', () => {
    const build = () =>
      buildVariableTree([
        // An unknown base (a NEW backend base reaching an older frontend).
        {
          source: 'trigger',
          path: 'trigger.fields.geo',
          name: 'Geo',
          type: 'text',
          descriptor: { base: 'geo' as never, nullable: false, array: false },
        },
        // A container whose fields list carries a malformed entry.
        {
          source: 'trigger',
          path: 'trigger.fields.mixed',
          name: 'Mixed',
          type: 'text',
          descriptor: {
            base: 'object',
            nullable: false,
            array: false,
            fields: [
              null as never,
              { key: 'ok', label: 'Ok', descriptor: { base: 'text', nullable: false, array: false } },
            ],
          },
        },
        // No descriptor at all (an older catalog entry / a synthesized step output).
        { source: 'steps', path: 'steps.make.title', name: 'make.title', type: 'text' },
        null as never,
        { source: 'trigger', path: '', name: 'Pathless', type: 'text' },
      ]);

    expect(build).not.toThrow();
    const tree = build();
    expect(paths(tree)).toEqual([
      'trigger.fields.geo',
      'trigger.fields.mixed',
      'trigger.fields.mixed.ok',
      'steps.make.title',
    ]);
    // An unknown base is treated as a plain, selectable scalar (never a container).
    expect(at(tree, 'trigger.fields.geo').selectable).toBe(true);
    expect(searchNodes(tree, 'ok')).toHaveLength(1);
  });

  it('returns an empty tree for null/undefined/empty input and dedupes repeated paths', () => {
    expect(buildVariableTree(null)).toEqual([]);
    expect(buildVariableTree(undefined)).toEqual([]);
    expect(buildVariableTree([])).toEqual([]);
    expect(flattenNodes(null)).toEqual([]);

    const dupes = buildVariableTree([
      { source: 'trigger', path: 'trigger.a', name: 'First', type: 'text' },
      { source: 'trigger', path: 'trigger.a', name: 'Second', type: 'number' },
    ]);
    expect(dupes).toHaveLength(1);
    expect(dupes[0].label).toBe('First'); // first wins
  });

  it('carries a nullable flag from EITHER the descriptor or the variable', () => {
    const tree = buildVariableTree([
      { source: 'trigger', path: 'trigger.fields.a', name: 'A', type: 'text', nullable: true },
      {
        source: 'trigger',
        path: 'trigger.fields.b',
        name: 'B',
        type: 'text',
        descriptor: { base: 'text', nullable: true, array: false },
      },
      { source: 'trigger', path: 'trigger.fields.c', name: 'C', type: 'text' },
    ]);

    expect(at(tree, 'trigger.fields.a').nullable).toBe(true);
    expect(at(tree, 'trigger.fields.b').nullable).toBe(true);
    expect(at(tree, 'trigger.fields.c').nullable).toBe(false);
  });

  it('exposes enum choices from the descriptor, falling back to flat enumOptions', () => {
    const tree = buildVariableTree([
      {
        source: 'trigger',
        path: 'trigger.fields.status',
        name: 'Status',
        type: 'enum',
        descriptor: {
          base: 'enum',
          nullable: false,
          array: false,
          options: [{ key: 'open', label: 'Open' }],
        },
        enumOptions: ['open'],
      },
      {
        source: 'trigger',
        path: 'trigger.fields.legacy',
        name: 'Legacy',
        type: 'enum',
        enumOptions: ['a', 'b'],
      },
      { source: 'trigger', path: 'trigger.fields.plain', name: 'Plain', type: 'text' },
    ]);

    expect(at(tree, 'trigger.fields.status').options).toEqual([{ key: 'open', label: 'Open' }]);
    expect(at(tree, 'trigger.fields.legacy').options).toEqual([
      { key: 'a', label: 'a' },
      { key: 'b', label: 'b' },
    ]);
    expect(at(tree, 'trigger.fields.plain').options).toBeUndefined();
  });
});
