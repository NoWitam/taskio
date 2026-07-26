// @vitest-environment happy-dom
// ifConditionPanel.dom.spec — the markdown IF / ELSE-IF branch condition Modal, after B5 put it on
// the SHARED `VariableReferenceEditor` (`ui/variables`) — the same body the step field, the markdown
// chip and the flow-condition modal use.
//
// What this pins (the B5 migration contract):
//   • the SOURCE picker is the inline ARIA TREE (`VariableBrowser`), NOT a flat Select — rows carry
//     the type glyph + `?`/`[]` markers, and an OBJECT container EXPANDS (never selects).
//   • the pipeline still GATES on a boolean terminal (the shared status strip + the disabled Save).
//   • the PRESENCE family stays available (a boolean gate legitimately ends on is_present/is_null).
//   • an operation ARGUMENT offers the variable toggle once the host injects its field.
//   • the branch condition wire round-trips: Save emits `{variableId, pipeline, resultType:'boolean'}`
//     with NO `default`, so `ifBlock.ts` serialization is byte-identical for the unchanged parts.
//   • the typed "default when empty" is ABSENT here — the if-branch runtime never applies it (see the
//     `hideDefault` note; a backend follow-up in `evaluateBranchCondition` is required before offering
//     one).
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { defineComponent, h, markRaw, nextTick } from 'vue';
import IfConditionPanel from '../extensions/IfConditionPanel.vue';
import { variableFeedTree } from '../extensions/variableFeed';
import { standardOperationsCatalog } from '../extensions/standardOperations';
import { setLocale } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import type { VariableSourceVar } from '../../variables/types';
import type { IfConditionState } from '../extensions/types';

/** A feed exercising markers (nullable/list), a boolean gate source, and an OBJECT container. */
const VARS: VariableSourceVar[] = [
  {
    source: 'trigger',
    path: 'trigger.fields.note',
    name: 'Note',
    type: 'text',
    descriptor: { base: 'text', nullable: true, array: false },
  },
  {
    source: 'trigger',
    path: 'trigger.fields.tags',
    name: 'Tags',
    type: 'multi',
    descriptor: { base: 'enum', nullable: false, array: true },
  },
  {
    source: 'trigger',
    path: 'trigger.fields.name',
    name: 'Name',
    type: 'text',
    descriptor: { base: 'text', nullable: false, array: false },
  },
  {
    source: 'trigger',
    path: 'trigger.fields.active',
    name: 'Active',
    type: 'boolean',
    descriptor: { base: 'boolean', nullable: false, array: false },
  },
  {
    source: 'globals',
    path: 'globals.contact',
    name: 'Contact',
    type: 'text',
    descriptor: {
      base: 'object',
      nullable: false,
      array: false,
      fields: [{ key: 'email', label: 'Email', descriptor: { base: 'text', nullable: false, array: false } }],
    },
  },
];
const NODES = variableFeedTree(VARS, []);

function mountPanel(props: Record<string, unknown> = {}) {
  return mount(IfConditionPanel, {
    attachTo: document.body,
    props: {
      open: true,
      condition: null,
      nodes: NODES,
      catalog: standardOperationsCatalog(),
      argVariables: VARS,
      ...props,
    },
  });
}

type Wrapper = ReturnType<typeof mountPanel>;

function saved(wrapper: Wrapper): IfConditionState | undefined {
  const emitted = wrapper.emitted('save');
  return emitted?.[emitted.length - 1]?.[0] as IfConditionState | undefined;
}

function treeitems(): HTMLElement[] {
  return Array.from(document.body.querySelectorAll<HTMLElement>('[role="treeitem"]'));
}
function treeitemByText(text: string): HTMLElement {
  return treeitems().find((r) => r.textContent?.includes(text))!;
}

/** Open the source picker's inline tree (the change-source popover trigger). */
async function openTree(): Promise<void> {
  (document.body.querySelector('[role="combobox"]') as HTMLElement).click();
  await nextTick();
  await nextTick();
}

/** Pick a variable by label from the tree (selects a leaf, closing the popover). */
async function pick(label: string): Promise<void> {
  await openTree();
  treeitemByText(label).click();
  await nextTick();
}

function clickByText(text: string): void {
  Array.from(document.body.querySelectorAll('button'))
    .find((b) => b.textContent?.trim() === text)!
    .click();
}

describe('IfConditionPanel — source picker is the shared inline TREE', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('renders an ARIA tree (not a flat listbox) with type-marker rows', async () => {
    const wrapper = mountPanel();
    await openTree();

    // The browser body is a real ARIA tree — the flat Select of option rows is gone.
    expect(document.body.querySelector('[data-variable-browser][role="tree"]')).not.toBeNull();
    expect(document.body.querySelector('[role="option"]')).toBeNull();

    // Rows carry the shared type markers: a nullable `?` and a list `[]`.
    expect(treeitemByText('Note').querySelector('[data-marker="optional"]')).toBeTruthy();
    expect(treeitemByText('Tags').querySelector('[data-marker="list"]')).toBeTruthy();
    expect(treeitemByText('Name').querySelector('[data-marker="optional"]')).toBeNull();

    wrapper.unmount();
  });

  it('EXPANDS an object container instead of selecting it, and picks its leaf', async () => {
    const wrapper = mountPanel();
    await openTree();

    const contact = treeitemByText('Contact');
    expect(contact.getAttribute('aria-expanded')).toBe('false'); // expandable, not selectable
    contact.click();
    await nextTick();

    // No source was chosen (no header) — the container only opened.
    expect(document.body.querySelector('[data-variable-source]')).toBeNull();
    expect(treeitemByText('Contact').getAttribute('aria-expanded')).toBe('true');

    // Its leaf is now visible and selectable.
    treeitemByText('Email').click();
    await nextTick();
    expect(document.body.querySelector('[data-variable-source]')!.textContent).toContain('Email');

    wrapper.unmount();
  });

  it('echoes the chosen source in the header with its glyph + marker', async () => {
    const wrapper = mountPanel();
    await pick('Note');

    const header = document.body.querySelector('[data-variable-source]')!;
    expect(header.textContent).toContain('Note');
    expect(header.querySelector('[data-marker="optional"]')).toBeTruthy();

    wrapper.unmount();
  });
});

describe('IfConditionPanel — boolean terminal gate', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('is UNSATISFIED (Save disabled) for a non-boolean source with no operations', async () => {
    const wrapper = mountPanel();
    await pick('Note'); // text → not a boolean terminal

    expect(document.body.querySelector('[data-type-satisfied="false"]')).not.toBeNull();
    const save = Array.from(document.body.querySelectorAll('button')).find(
      (b) => b.textContent?.trim() === 'Save condition',
    ) as HTMLButtonElement;
    expect(save.disabled).toBe(true);

    wrapper.unmount();
  });

  it('is SATISFIED (Save enabled) for a boolean source, and saves the branch condition', async () => {
    const wrapper = mountPanel();
    await pick('Active'); // boolean → the terminal is already boolean

    expect(document.body.querySelector('[data-type-satisfied="true"]')).not.toBeNull();
    clickByText('Save condition');
    await nextTick();

    const emitted = saved(wrapper)!;
    expect(emitted).toEqual({ variableId: 'trigger.fields.active', pipeline: [], resultType: 'boolean' });
    // No `default` key — the if-branch runtime never applies one, so the wire stays byte-identical.
    expect('default' in emitted).toBe(false);

    wrapper.unmount();
  });

  it('round-trips an existing piped condition unchanged on Save', async () => {
    const condition: IfConditionState = {
      variableId: 'trigger.fields.name',
      pipeline: [{ stepId: 's1', operationId: 'text_is_empty', args: {}, outputType: 'boolean' }],
      resultType: 'boolean',
    };
    const wrapper = mountPanel({ condition });

    // The pipeline resolves to boolean → satisfied, Save enabled.
    expect(document.body.querySelector('[data-type-satisfied="true"]')).not.toBeNull();
    clickByText('Save condition');
    await nextTick();

    expect(saved(wrapper)).toEqual(condition);
    wrapper.unmount();
  });
});

describe('IfConditionPanel — presence ops kept + default suppressed', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('KEEPS the presence family (is_present / is_null) in the add-operation menu', async () => {
    const wrapper = mountPanel();
    await pick('Note');

    // Open the pipeline's add-operation menu.
    Array.from(document.body.querySelectorAll('button'))
      .find((b) => b.textContent?.trim() === 'Add operation')!
      .click();
    await new Promise((r) => setTimeout(r, 0));
    await nextTick();

    const text = document.body.textContent ?? '';
    expect(text).toContain('Has a value'); // is_present — a valid boolean terminal for a gate
    expect(text).toContain('Has no value'); // is_null

    wrapper.unmount();
  });

  it('SUPPRESSES the typed "default when empty" even for a nullable source', async () => {
    // The runtime path (evaluateBranchCondition) reads only variableId + pipeline, so a default would
    // be inert. It is hidden until the backend applies it (reported as a follow-up).
    const wrapper = mountPanel();
    await pick('Note'); // nullable text — WOULD show a default on a reference surface

    expect(document.body.querySelector('[data-vov-default]')).toBeNull();
    wrapper.unmount();
  });
});

describe('IfConditionPanel — operation ARGUMENTS as variables', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  /** A stand-in for the page's value-or-variable field: the toggle + the pool size it receives. */
  const ArgField = defineComponent({
    name: 'ArgFieldStub',
    props: {
      arg: { type: Object, required: true },
      value: { type: null, default: null },
      depth: { type: Number, default: 0 },
      variables: { type: Array, default: () => [] },
      operationsCatalog: { type: Array, default: () => [] },
      resultTypes: { type: Array, default: () => [] },
      variableModeLabel: { type: String, default: undefined },
      sourceOptions: { type: Array, default: () => [] },
      targetOptions: { type: Array, default: () => [] },
      disabled: { type: Boolean, default: false },
    },
    emits: ['update:value'],
    setup(props, { emit }) {
      return () =>
        h('div', { class: 'arg-field', 'data-arg': props.arg.id, 'data-depth': String(props.depth) }, [
          h(
            'button',
            {
              class: 'arg-variable-toggle',
              onClick: () =>
                emit('update:value', {
                  kind: 'variable',
                  ref: { source: 'trigger', path: 'trigger.fields.name', type: 'text' },
                }),
            },
            'Variable',
          ),
          h('span', { class: 'arg-pool' }, String((props.variables as unknown[]).length)),
        ]);
    },
  });

  const PIPED: IfConditionState = {
    variableId: 'trigger.fields.name',
    pipeline: [{ stepId: 's1', operationId: 'text_append', args: { value: 'x' }, outputType: 'text' }],
    resultType: 'boolean',
  };

  it('offers the variable toggle on a pipeline argument once the host injects its field', async () => {
    const wrapper = mountPanel({ condition: PIPED, argVariableField: markRaw(ArgField) });

    // Enter the step's edit mode so its arguments render.
    document.body.querySelector<HTMLElement>('ol button')!.click();
    await nextTick();

    const field = document.body.querySelector<HTMLElement>('.arg-field')!;
    expect(field).not.toBeNull();
    expect(field.querySelector('.arg-variable-toggle')).not.toBeNull();
    // It arrives parameterized with the arg pool and the depth the pipeline editor assigned.
    expect(field.getAttribute('data-depth')).toBe('1');
    expect(field.querySelector('.arg-pool')!.textContent).toBe(String(VARS.length));

    wrapper.unmount();
  });

  it('offers NO toggle when the host injected nothing (literal-only argument)', async () => {
    const wrapper = mountPanel({ condition: PIPED });
    document.body.querySelector<HTMLElement>('ol button')!.click();
    await nextTick();

    expect(document.body.querySelector('.arg-field')).toBeNull();
    expect(document.body.querySelectorAll('input').length).toBeGreaterThan(0); // the literal control
    wrapper.unmount();
  });
});
