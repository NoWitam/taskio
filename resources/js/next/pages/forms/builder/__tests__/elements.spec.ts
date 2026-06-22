// Unit tests for the form-builder element core (pure tree ops + validity mirror).
import { describe, expect, it } from 'vitest';
import {
  addChild,
  canHaveChildren,
  categoryOf,
  childrenOf,
  collectIssues,
  createElement,
  duplicateElement,
  findElement,
  hasInputFields,
  isInputType,
  moveElement,
  regenerateIds,
  removeElement,
  updateElementConfig,
} from '../elements';
import type { FormElement } from '../../types';

describe('form-builder elements core', () => {
  it('createElement seeds the per-type default config (mirrors legacy templates)', () => {
    const section = createElement('section', 'My section');
    expect(section).toMatchObject({ type: 'section', config: { name: 'My section', children: [] } });

    const repeater = createElement('repeater', 'Rep');
    expect(repeater.config).toMatchObject({ name: 'Rep', min: 1, max: 5, children: [] });

    const select = createElement('select', 'Pick');
    expect(select.config).toMatchObject({ label: 'Pick', multiple: false, options: [] });

    const image = createElement('image', 'Img');
    expect(image.config.maxSize).toBe(5);
    expect(image.config.acceptedTypes).toContain('image/png');

    expect(createElement('divider', '').config).toEqual({});
  });

  it('classifies categories + input types', () => {
    expect(categoryOf('section')).toBe('layout');
    expect(categoryOf('heading')).toBe('content');
    expect(categoryOf('short_text')).toBe('input');
    expect(isInputType('checkbox')).toBe(true);
    expect(isInputType('grid')).toBe(false);
    expect(canHaveChildren('section')).toBe(true);
    expect(canHaveChildren('grid')).toBe(false);
  });

  it('findElement + childrenOf descend into sections and grid columns', () => {
    const inner = createElement('short_text', 'Inner');
    const grid = createElement('grid', '');
    grid.config.columns = [{ width: 50, element: inner }, { width: 50, element: null }];
    const section = createElement('section', 'S');
    section.config.children = [grid];
    const tree: FormElement[] = [section];

    expect(findElement(tree, inner.id)?.id).toBe(inner.id);
    expect(childrenOf(grid).map((e) => e.id)).toEqual([inner.id]);
  });

  it('addChild appends into a section, updateElementConfig edits nested', () => {
    const section = createElement('section', 'S');
    const tree: FormElement[] = [section];
    const child = createElement('short_text', 'Field');

    expect(addChild(tree, section.id, child)).toBe(true);
    expect(section.config.children).toHaveLength(1);

    updateElementConfig(tree, child.id, { ...child.config, label: 'Renamed' });
    expect(findElement(tree, child.id)?.config.label).toBe('Renamed');
  });

  it('removeElement clears a grid column element', () => {
    const field = createElement('number', 'N');
    const grid = createElement('grid', '');
    grid.config.columns = [{ width: 100, element: field }];
    const tree: FormElement[] = [grid];

    expect(removeElement(tree, field.id)).toBe(true);
    expect(grid.config.columns?.[0].element).toBeNull();
  });

  it('moveElement reorders within the sibling list', () => {
    const a = createElement('short_text', 'A');
    const b = createElement('short_text', 'B');
    const tree: FormElement[] = [a, b];

    expect(moveElement(tree, b.id, -1)).toBe(true);
    expect(tree.map((e) => e.id)).toEqual([b.id, a.id]);
    // Can't move past the edge.
    expect(moveElement(tree, b.id, -1)).toBe(false);
  });

  it('duplicateElement inserts a deep copy with fresh ids', () => {
    const section = createElement('section', 'S');
    section.config.children = [createElement('short_text', 'Child')];
    const tree: FormElement[] = [section];

    const copy = duplicateElement(tree, section.id);
    expect(tree).toHaveLength(2);
    expect(copy).not.toBeNull();
    expect(copy!.id).not.toBe(section.id);
    expect(copy!.config.children![0].id).not.toBe(section.config.children![0].id);
  });

  it('regenerateIds rewrites every id in the subtree', () => {
    const section = createElement('section', 'S');
    const child = createElement('short_text', 'C');
    section.config.children = [child];
    const before = { section: section.id, child: child.id };

    regenerateIds(section);
    expect(section.id).not.toBe(before.section);
    expect(section.config.children![0].id).not.toBe(before.child);
  });

  it('hasInputFields detects inputs nested in sections / grids', () => {
    expect(hasInputFields([createElement('heading', 'H')])).toBe(false);

    const grid = createElement('grid', '');
    grid.config.columns = [{ width: 100, element: createElement('short_text', 'X') }];
    expect(hasInputFields([grid])).toBe(true);
  });

  it('collectIssues mirrors the key ValidFormContent rules', () => {
    const select = createElement('select', '');
    select.config.label = '';
    select.config.options = [];
    const grid = createElement('grid', '');
    grid.config.columns = [{ width: 75, element: null }, { width: 75, element: null }];

    const issues = collectIssues([select, grid]).map((i) => i.key);
    expect(issues).toContain('forms.builder.issues.label');
    expect(issues).toContain('forms.builder.issues.options');
    expect(issues).toContain('forms.builder.issues.gridTotal');

    // A complete select with options produces no option/label issue.
    const ok = createElement('select', 'Choose');
    ok.config.options = [{ value: 'a', label: 'A' }];
    expect(collectIssues([ok])).toHaveLength(0);
  });
});
