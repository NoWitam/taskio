// @vitest-environment happy-dom
// ConstantsPicker.spec — proves a workspace CONST (formerly a "global") flows through the
// SAME catalog the editor already consumes: it is surfaced by the variable adapters UNDER the
// "Globals" group node (the RUNTIME wire root stays `globals` even though the feature is now
// "consts" — B4: every feed is a tree now, so the old "Globals ›" text prefix is gone), an
// OBJECT const is surfaced WHOLE (it carries its own descriptor fields, having no flat
// leaves), and picking one in ValueOrVariableField yields a `globals.<key>` ref carrying its
// type. The wire assertions (`source:'globals'`, `path:'globals.<key>'`) are intentionally
// UNCHANGED — only the feature/URL/nav renamed, not the run-context reference.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick, h } from 'vue';
import { setLocale } from '../../../app/i18n';
import { allValueVariables, toEditorVariablesTyped, variablesOfType } from '../workflowVariables';
import ValueOrVariableField from '../ValueOrVariableField.vue';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import type { CatalogVariable, WorkflowCatalog } from '../types';

beforeEach(() => setLocale('en'));

describe('consts in the catalog adapters (wire root stays `globals`)', () => {
  // B4 — UPDATED: the "Globals ›" qualifier was a workaround for a picker with no group headers.
  // Every feed is now rendered as a TREE, so the const rides UNQUALIFIED under the group node.
  it('surfaces a const under the "Globals" group node, with its own unqualified name', () => {
    const catalog: WorkflowCatalog = {
      variables: [
        {
          source: 'globals',
          path: 'globals.brand',
          name: 'Brand',
          type: 'text',
          descriptor: { base: 'text', nullable: false, array: false },
        },
      ],
      fields: [],
    };

    const vars = variablesOfType(catalog, [], 0, ['text']);
    expect(vars.map((v) => v.path)).toEqual(['globals', 'globals.brand']);
    const brand = vars[1];
    expect(brand.source).toBe('globals');
    expect(brand.type).toBe('text');
    expect(brand.name).toBe('Brand');
  });

  // UPDATED (B2.1): the value-field feed now ALSO carries a trigger SECTION — as an
  // expand-only container that groups its own flat leaves — where it used to be dropped
  // entirely. The two object shapes stay DIFFERENT: a const is self-contained and keeps its
  // `descriptor.fields` (it has no flat leaves), a section keeps none (its leaves are flat).
  it('surfaces an OBJECT const whole, and a trigger SECTION as a fields-less container', () => {
    const objectDescriptor = {
      base: 'object' as const,
      nullable: false,
      array: false,
      fields: [{ key: 'city', label: 'City', descriptor: { base: 'text' as const, nullable: false, array: false } }],
    };
    const catalog: WorkflowCatalog = {
      variables: [
        { source: 'globals', path: 'globals.address', name: 'Address', type: 'text', descriptor: objectDescriptor },
        { source: 'trigger', path: 'trigger.section', name: 'Section', type: 'text', descriptor: objectDescriptor },
      ],
      fields: [],
    };

    const vars = allValueVariables(catalog, [], 0);
    const paths = vars.map((v) => v.path);
    expect(paths).toContain('globals.address'); // globals object → surfaced whole …
    expect(vars.find((v) => v.path === 'globals.address')?.descriptor).toBe(objectDescriptor);

    // … and the trigger SECTION is surfaced too, WITHOUT its descriptor fields: only its real
    // flat leaves may nest under it, so the tree can never synthesize an unoffered path.
    expect(paths).toContain('trigger.section');
    expect(vars.find((v) => v.path === 'trigger.section')?.descriptor).toEqual({
      base: 'object',
      nullable: false,
      array: false,
    });

    // B4 — the MARKDOWN `{`-insert feed now carries the same containers (it browses the same
    // tree), each marked `base:'object'` so it stays EXPAND-ONLY there as well.
    const editorFeed = toEditorVariablesTyped(catalog, [], 0);
    expect(editorFeed.map((v) => v.id)).toContain('trigger.section');
    expect(editorFeed.find((v) => v.id === 'trigger.section')?.base).toBe('object');
    expect(editorFeed.find((v) => v.id === 'globals.address')?.base).toBe('object');
  });
});

describe('picking a const in ValueOrVariableField', () => {
  beforeEach(() => installBrowserMocks());
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('emits a variable union with a globals.<key> ref carrying the right type', async () => {
    const globalVar: CatalogVariable = {
      source: 'globals',
      path: 'globals.brand',
      name: 'Globals › Brand',
      type: 'text',
      descriptor: { base: 'text', nullable: false, array: false },
    };
    const wrapper = mount(ValueOrVariableField, {
      attachTo: document.body,
      props: { variables: [globalVar] },
      slots: {
        default: (slotProps: { value: unknown; setValue: (v: unknown) => void }) =>
          h('input', {
            class: 'literal-input',
            value: (slotProps.value as string) ?? '',
            onInput: (e: Event) => slotProps.setValue((e.target as HTMLInputElement).value),
          }),
      },
    });

    // Switch to variable mode, open the picker, choose the const (a flat leaf tree row).
    await wrapper.get('button[aria-label="Variable"]').trigger('click');
    await nextTick();
    await wrapper.get('[role="combobox"]').trigger('click');
    await nextTick();
    await Promise.resolve();
    await nextTick();

    document.body.querySelectorAll<HTMLElement>('[role="treeitem"]')[0].click();
    await nextTick();

    const emitted = wrapper.emitted('update:modelValue');
    expect(emitted?.[emitted.length - 1]).toEqual([
      { kind: 'variable', ref: { source: 'globals', path: 'globals.brand', type: 'text' } },
    ]);

    wrapper.unmount();
  });

  it('an OBJECT const expands INLINE; picking a field emits globals.<key>.<field> (B3)', async () => {
    const addressGlobal: CatalogVariable = {
      source: 'globals',
      path: 'globals.address',
      name: 'Globals › Address',
      type: 'text',
      descriptor: {
        base: 'object',
        nullable: false,
        array: false,
        fields: [{ key: 'city', label: 'City', descriptor: { base: 'text', nullable: false, array: false } }],
      },
    };
    const wrapper = mount(ValueOrVariableField, {
      attachTo: document.body,
      props: { variables: [addressGlobal] },
      slots: {
        default: (slotProps: { value: unknown; setValue: (v: unknown) => void }) =>
          h('input', {
            class: 'literal-input',
            value: (slotProps.value as string) ?? '',
            onInput: (e: Event) => slotProps.setValue((e.target as HTMLInputElement).value),
          }),
      },
    });

    await wrapper.get('button[aria-label="Variable"]').trigger('click');
    await nextTick();
    await wrapper.get('[role="combobox"]').trigger('click');
    await nextTick();
    await Promise.resolve();
    await nextTick();

    const rows = () => Array.from(document.body.querySelectorAll<HTMLElement>('[role="treeitem"]'));
    // The object const is a single expandable node (its descriptor.fields are not flat entries).
    expect(rows().length).toBe(1);
    expect(rows()[0].getAttribute('aria-expanded')).toBe('false');
    rows()[0].querySelector('button')!.click();
    await nextTick();

    // Its field appeared INLINE, one level deeper — the container itself stays on screen.
    expect(rows().map((r) => r.getAttribute('aria-level'))).toEqual(['1', '2']);

    // Picking the composed `city` child emits a ref at globals.address.city.
    rows().find((r) => r.textContent?.includes('City'))!.click();
    await nextTick();

    const emitted = wrapper.emitted('update:modelValue');
    expect(emitted?.[emitted.length - 1]).toEqual([
      { kind: 'variable', ref: { source: 'globals', path: 'globals.address.city', type: 'text' } },
    ]);

    wrapper.unmount();
  });

  // B3 CHANGE 3 — through the REAL feed: every const hangs under ONE "Globals" node, and an
  // OBJECT const is an expandable container there (it used to be one flat, dead `Globals › X`
  // row, because the globals branch short-circuited before the container rules).
  it('groups every const under ONE expandable "Globals" node; an object const expands one level further', async () => {
    const catalog: WorkflowCatalog = {
      variables: [
        { source: 'trigger', path: 'trigger.title', name: 'Title', type: 'text' },
        {
          source: 'globals',
          path: 'globals.brand',
          name: 'Brand',
          type: 'text',
          descriptor: { base: 'text', nullable: false, array: false },
        },
        {
          source: 'globals',
          path: 'globals.address',
          name: 'Address',
          type: 'text',
          descriptor: {
            base: 'object',
            nullable: false,
            array: false,
            fields: [{ key: 'city', label: 'City', descriptor: { base: 'text', nullable: false, array: false } }],
          },
        },
      ],
      fields: [],
    };

    const wrapper = mount(ValueOrVariableField, {
      attachTo: document.body,
      props: { variables: allValueVariables(catalog, [], 0) },
      slots: {
        default: () => h('input', { class: 'literal-input' }),
      },
    });

    await wrapper.get('button[aria-label="Variable"]').trigger('click');
    await nextTick();
    await wrapper.get('[role="combobox"]').trigger('click');
    await nextTick();
    await Promise.resolve();
    await nextTick();

    const rows = () => Array.from(document.body.querySelectorAll<HTMLElement>('[role="treeitem"]'));
    const label = (el: HTMLElement) => el.querySelector('span.truncate')?.textContent?.trim() ?? '';

    // ONE "Globals" root beside the trigger variable — no "Globals › x" rows at the top level.
    expect(rows().map(label)).toEqual(['Title', 'Globals']);
    const globalsRow = rows()[1];
    expect(globalsRow.getAttribute('aria-expanded')).toBe('false');

    // Clicking it expands (a group node is never selectable) — both consts live inside it.
    globalsRow.click();
    await nextTick();
    expect(wrapper.emitted('update:modelValue')).toBeUndefined();
    expect(rows().map(label)).toEqual(['Title', 'Globals', 'Brand', 'Address']);
    expect(rows().map((r) => r.getAttribute('aria-level'))).toEqual(['1', '1', '2', '2']);

    // The OBJECT const is itself a container: expand-only, one level deeper.
    const address = rows()[3];
    expect(address.getAttribute('aria-expanded')).toBe('false');
    address.click();
    await nextTick();
    expect(wrapper.emitted('update:modelValue')).toBeUndefined();
    expect(rows().map(label)).toEqual(['Title', 'Globals', 'Brand', 'Address', 'City']);
    expect(rows()[4].getAttribute('aria-level')).toBe('3');

    // Its leaf emits the composed ref, inheriting the globals source.
    rows()[4].click();
    await nextTick();
    const emitted = wrapper.emitted('update:modelValue');
    expect(emitted?.[emitted.length - 1]).toEqual([
      { kind: 'variable', ref: { source: 'globals', path: 'globals.address.city', type: 'text' } },
    ]);

    wrapper.unmount();
  });
});
