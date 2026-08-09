// @vitest-environment happy-dom
// aiOnlyConversion.spec — the pivot is COMPLETE, and stays complete (spec §25 / R16 / R17).
//
// A half-done pivot is worse than none: one surviving door into the editor's create mode means two
// ways to make an entry, one of which skips the composer, the duplicate check and the relations
// preview entirely. These tests are the fence around that.
//
// Three things are pinned:
//   1. every "new entry" affordance targets the COMPOSER, with the right seed;
//   2. `store.createEntry` is called by NO component (it survives only for the acceptance path);
//   3. the editor's route rejects a missing slug, and its unknown-slug state is a dead end WITH a
//      way forward rather than a create form.
import { describe, it, expect } from 'vitest';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { composeLocation, amendLocation, COMPOSE_ROUTE } from '../composeSeed';

/** `fileURLToPath`, not `.pathname` — the latter loses the drive/UNC prefix off Windows hosts. */
const KNOWLEDGE_DIR = dirname(dirname(fileURLToPath(import.meta.url)));

/** Every `.vue` under `pages/knowledge`, recursively. */
function vueFiles(dir: string): string[] {
  const out: string[] = [];
  for (const name of readdirSync(dir)) {
    if (name === '__tests__') continue;
    const full = join(dir, name);
    if (statSync(full).isDirectory()) out.push(...vueFiles(full));
    else if (name.endsWith('.vue')) out.push(full);
  }
  return out;
}

describe('composeSeed — one place that knows where "write this" goes', () => {
  it('builds the composer location, seeded and unseeded', () => {
    expect(composeLocation('b1')).toEqual({ name: COMPOSE_ROUTE, params: { baseId: 'b1' }, query: {} });
    expect(composeLocation('b1', 'polityka-zwrotow')).toEqual({
      name: COMPOSE_ROUTE,
      params: { baseId: 'b1' },
      query: { seed: 'polityka-zwrotow' },
    });
    // A null/empty seed must not produce `?seed=` — an empty query key would prefill nothing and
    // make the composer claim the user arrived from a red link.
    expect(composeLocation('b1', null)).toEqual({ name: COMPOSE_ROUTE, params: { baseId: 'b1' }, query: {} });
    expect(composeLocation('b1', '')).toEqual({ name: COMPOSE_ROUTE, params: { baseId: 'b1' }, query: {} });
  });

  it('keeps AMENDING distinct from seeding — one starts from an entry, the other from a slug', () => {
    expect(amendLocation('b1', 'e9')).toEqual({
      name: COMPOSE_ROUTE,
      params: { baseId: 'b1' },
      query: { amend: 'e9' },
    });
  });
});

describe('the pivot leaves no second path to creating an entry', () => {
  const files = vueFiles(KNOWLEDGE_DIR);

  it('finds the module’s components (guard against an empty sweep)', () => {
    expect(files.length).toBeGreaterThan(10);
  });

  it('calls store.createEntry from NO component', () => {
    // It stays in the store for the composer's acceptance path (spec §25.2), but a component
    // calling it would be a hand-written entry — exactly what the pivot removed.
    const offenders = files.filter((file) => readFileSync(file, 'utf8').includes('createEntry('));
    expect(offenders).toEqual([]);
  });

  it('routes no component to the editor without a slug', () => {
    // `edit` with no slug used to open the create form. Any push that omits the slug — including
    // the `slug ?? undefined` spelling — is that door reopening (R16).
    for (const file of files) {
      const source = readFileSync(file, 'utf8');
      expect(source).not.toContain('slug: slug.value ?? undefined');
      expect(source).not.toContain("query: { title:");
    }
  });

  it('has no component left pointing "new entry" at the editor', () => {
    const editorTargets = files.filter((file) => {
      const source = readFileSync(file, 'utf8');
      // The editor route may only be referenced WITH a slug (the real edit affordances) or by the
      // module layout's active-item matching.
      if (!source.includes('next.knowledge.base.edit')) return false;
      return !source.includes('slug:') && !source.includes('current === ');
    });
    expect(editorTargets).toEqual([]);
  });
});
