// @vitest-environment happy-dom
// variablePanel.dom.spec — the markdown variable chip's EDIT PANEL, after B4 put it on the shared
// `VariableReferenceEditor`.
//
// TWO of the three owner-reported defects live here (the third is the `{` list — see
// variableSuggest.dom.spec):
//
//   1. "Default when empty" was ALWAYS shown and ALWAYS a plain TEXT input. It is now the shared
//      `VariableDefaultField`: rendered ONLY for a NULLABLE variable, and TYPED to its base
//      (number → number input, date → picker, enum → select, boolean → TRI-STATE select). This
//      spec pins the gate, each control, and the "empty ⇒ null ⇒ the directive omits the key" rule.
//   3. An operation ARGUMENT could not be supplied by a variable here. The panel now fills the
//      pipeline editor's `argVariable` slot with the HOST-INJECTED field (`ui/**` cannot import the
//      page's value-or-variable field, so the host hands it in) — and offers nothing when no host
//      injected one, which is the old literal-only behaviour.
//
// What must NOT have moved: the markdown-only DISPLAY NAME + its LOCK. That concept exists on no
// other variable surface and is deliberately still owned by this panel.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { defineComponent, h, markRaw, nextTick } from 'vue';
import VariablePanel from '../extensions/VariablePanel.vue';
import VariableDefaultField from '../../variables/VariableDefaultField.vue';
import { variableFeedTree } from '../extensions/variableFeed';
import { standardOperationsCatalog } from '../extensions/standardOperations';
import { setLocale } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import type { VariableSourceVar } from '../../variables/types';
import type { VariableNodeAttrs } from '../extensions/types';

/** One variable per BASE the typed default cares about, each in a nullable + a required flavour. */
const VARS: VariableSourceVar[] = [
  {
    source: 'trigger',
    path: 'trigger.fields.nickname',
    name: 'Nickname',
    type: 'text',
    descriptor: { base: 'text', nullable: true, array: false },
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
    path: 'trigger.fields.amount',
    name: 'Amount',
    type: 'number',
    descriptor: { base: 'number', nullable: true, array: false },
  },
  {
    source: 'trigger',
    path: 'trigger.fields.due',
    name: 'Due date',
    type: 'date',
    descriptor: { base: 'date', nullable: true, array: false },
  },
  {
    source: 'trigger',
    path: 'trigger.fields.paid',
    name: 'Paid',
    type: 'boolean',
    descriptor: { base: 'boolean', nullable: true, array: false },
  },
  {
    source: 'trigger',
    path: 'trigger.fields.status',
    name: 'Status',
    type: 'enum',
    descriptor: {
      base: 'enum',
      nullable: true,
      array: false,
      options: [
        { key: 'open', label: 'Open ticket' },
        { key: 'done', label: 'Resolved' },
      ],
    },
  },
];
const NODES = variableFeedTree(VARS, []);

function attrsFor(path: string, over: Partial<VariableNodeAttrs> = {}): VariableNodeAttrs {
  const variable = VARS.find((v) => v.path === path)!;
  return {
    id: variable.path,
    name: variable.name,
    type: variable.type,
    locked: false,
    pipeline: [],
    resultType: variable.type,
    ...over,
  };
}

function mountPanel(props: Record<string, unknown> = {}) {
  return mount(VariablePanel, {
    attachTo: document.body,
    props: {
      open: true,
      state: attrsFor('trigger.fields.nickname'),
      nodes: NODES,
      catalog: standardOperationsCatalog(),
      ...props,
    },
  });
}

type Wrapper = ReturnType<typeof mountPanel>;

/** The panel teleports into a Modal, so query the document, not the wrapper. */
function defaultBlock(): HTMLElement | null {
  return document.body.querySelector<HTMLElement>('[data-vov-default]');
}

function saved(wrapper: Wrapper): VariableNodeAttrs | undefined {
  const emitted = wrapper.emitted('save');
  return emitted?.[emitted.length - 1]?.[0] as VariableNodeAttrs | undefined;
}

function clickByText(text: string): void {
  const button = Array.from(document.body.querySelectorAll('button')).find(
    (b) => b.textContent?.trim() === text,
  );
  button!.click();
}

describe('VariablePanel — the DEFAULT block (defect 1)', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('is HIDDEN for a variable that can never resolve empty', () => {
    // The regression: it used to render unconditionally, inviting a default that the engine
    // could never apply.
    const wrapper = mountPanel({ state: attrsFor('trigger.fields.name') });
    expect(defaultBlock()).toBeNull();
    expect(wrapper.findComponent(VariableDefaultField).exists()).toBe(true); // mounted, renders nothing
    wrapper.unmount();
  });

  it('is SHOWN for a nullable variable, as a TEXT input, and hydrates the stored value', () => {
    const wrapper = mountPanel({
      state: attrsFor('trigger.fields.nickname', { default: 'Anonymous' }),
    });
    const input = defaultBlock()!.querySelector<HTMLInputElement>('input')!;
    expect(input.type).toBe('text');
    expect(input.value).toBe('Anonymous');
    wrapper.unmount();
  });

  it('is TYPED to the variable base: number → number input', () => {
    const wrapper = mountPanel({ state: attrsFor('trigger.fields.amount', { default: 12 }) });
    const input = defaultBlock()!.querySelector<HTMLInputElement>('input')!;
    expect(input.type).toBe('number');
    expect(input.value).toBe('12'); // the STORED number, not a stringified guess
    wrapper.unmount();
  });

  it('is TYPED to the variable base: date → a date picker, not free text', () => {
    const wrapper = mountPanel({ state: attrsFor('trigger.fields.due', { default: '2026-07-24' }) });
    const block = defaultBlock()!;
    expect(block.querySelector('input')!.getAttribute('type')).not.toBe('number');
    // The date control is the shared DatePicker (a combobox trigger), never a plain text box.
    expect(block.querySelector('[role="combobox"], input[type="date"]')).not.toBeNull();
    wrapper.unmount();
  });

  it('is TYPED to the variable base: enum → a Select over the REAL option labels', () => {
    const wrapper = mountPanel({ state: attrsFor('trigger.fields.status') });
    const block = defaultBlock()!;
    const trigger = block.querySelector<HTMLElement>('[role="combobox"]')!;
    trigger.click();
    return nextTick().then(() => {
      const labels = Array.from(document.body.querySelectorAll('[role="option"]')).map((o) =>
        o.textContent?.trim(),
      );
      expect(labels).toContain('Open ticket'); // the human label …
      expect(labels).toContain('Resolved');
      expect(labels).not.toContain('open'); // … never the raw wire key
      wrapper.unmount();
    });
  });

  it('is TYPED to the variable base: boolean → a TRI-STATE (no default / yes / no), not a switch', async () => {
    const wrapper = mountPanel({ state: attrsFor('trigger.fields.paid') });
    const block = defaultBlock()!;
    expect(block.querySelector('[role="switch"]')).toBeNull(); // a switch could not say "unset"

    block.querySelector<HTMLElement>('[role="combobox"]')!.click();
    await nextTick();
    const labels = Array.from(document.body.querySelectorAll('[role="option"]')).map((o) =>
      o.textContent?.trim(),
    );
    expect(labels).toEqual(['No default', 'Yes', 'No']);
    wrapper.unmount();
  });

  it('SAVES a typed default as its own type — and OMITS it when left empty', async () => {
    // number: the value stays a number all the way to the node attrs (the directive then writes
    // it as a JSON scalar — see directives.spec).
    const numeric = mountPanel({ state: attrsFor('trigger.fields.amount') });
    const input = defaultBlock()!.querySelector<HTMLInputElement>('input')!;
    input.value = '42';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
    await nextTick();
    clickByText('Save');
    await nextTick();
    expect(saved(numeric)!.default).toBe(42);
    numeric.unmount();
    document.body.innerHTML = '';

    // …and a CLEARED control saves `null`, the host's single "omit the key" signal.
    const cleared = mountPanel({ state: attrsFor('trigger.fields.nickname', { default: 'x' }) });
    const text = defaultBlock()!.querySelector<HTMLInputElement>('input')!;
    text.value = '';
    text.dispatchEvent(new Event('input', { bubbles: true }));
    await nextTick();
    clickByText('Save');
    await nextTick();
    expect(saved(cleared)!.default).toBeNull();
    cleared.unmount();
  });

  it('keeps the markdown-only display NAME + lock, and saves the edited name', async () => {
    const wrapper = mountPanel({ state: attrsFor('trigger.fields.nickname') });
    const name = document.body.querySelector<HTMLInputElement>('#next-var-name')!;
    expect(name.value).toBe('Nickname');

    // The lock makes the name read-only (a concept that exists on NO other variable surface).
    const lock = document.body.querySelector<HTMLElement>('[aria-label="Lock name"]')!;
    lock.click();
    await nextTick();
    expect(document.body.querySelector<HTMLInputElement>('#next-var-name')!.readOnly).toBe(true);

    clickByText('Save');
    await nextTick();
    expect(saved(wrapper)!.name).toBe('Nickname');
    expect(saved(wrapper)!.locked).toBe(true);
    wrapper.unmount();
  });

  it('resolves the referenced variable from the TREE (header label + type badge + markers)', () => {
    const wrapper = mountPanel({ state: attrsFor('trigger.fields.nickname') });
    const header = document.body.querySelector<HTMLElement>('[data-variable-source]')!;
    expect(header.textContent).toContain('Nickname');
    expect(header.textContent).toContain('Text');
    expect(header.querySelector('[data-marker="optional"]')).not.toBeNull();
    wrapper.unmount();
  });
});

describe('VariablePanel — operation ARGUMENTS as variables (defect 3)', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  /** A stand-in for the page's value-or-variable field: the toggle is what must show up. */
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
              'aria-label': props.variableModeLabel ?? 'Variable',
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

  /** Open the panel on a variable whose pipeline already has ONE argument-carrying step. */
  function mountWithPipeline(extra: Record<string, unknown> = {}) {
    return mountPanel({
      state: attrsFor('trigger.fields.name', {
        pipeline: [
          { stepId: 's1', operationId: 'text_append', args: { value: 'x' }, outputType: 'text' },
        ],
      }),
      argVariables: VARS,
      ...extra,
    });
  }

  it('offers the variable toggle on a pipeline argument once the host injects its field', async () => {
    const wrapper = mountWithPipeline({ argVariableField: markRaw(ArgField) });

    // Enter the step's edit mode so its arguments render.
    document.body.querySelector<HTMLElement>('ol button')!.click();
    await nextTick();

    const field = document.body.querySelector<HTMLElement>('.arg-field')!;
    expect(field).not.toBeNull();
    expect(field.querySelector('.arg-variable-toggle')).not.toBeNull();
    // It arrives fully parameterized: the arg pool, and the depth the pipeline editor assigned.
    expect(field.getAttribute('data-depth')).toBe('1');
    expect(field.querySelector('.arg-pool')!.textContent).toBe(String(VARS.length));

    wrapper.unmount();
  });

  it('writes a picked arg-variable straight into the saved pipeline args', async () => {
    const wrapper = mountWithPipeline({ argVariableField: markRaw(ArgField) });
    document.body.querySelector<HTMLElement>('ol button')!.click();
    await nextTick();

    document.body.querySelector<HTMLElement>('.arg-variable-toggle')!.click();
    await nextTick();
    clickByText('Save');
    await nextTick();

    expect(saved(wrapper)!.pipeline[0].args.value).toEqual({
      kind: 'variable',
      ref: { source: 'trigger', path: 'trigger.fields.name', type: 'text' },
    });
    wrapper.unmount();
  });

  it('offers NO toggle when the host injected nothing (literal-only, as before)', async () => {
    const wrapper = mountWithPipeline();
    document.body.querySelector<HTMLElement>('ol button')!.click();
    await nextTick();

    expect(document.body.querySelector('.arg-field')).toBeNull();
    // The literal control is still there — the argument is simply not variable-capable.
    expect(document.body.querySelectorAll('input').length).toBeGreaterThan(0);
    wrapper.unmount();
  });
});

// --- F1: array ops on a repeater in the MARKDOWN chip panel ------------------
describe('VariablePanel — array ops on a repeater (F1)', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  /** A repeater whose FLAT type degrades to `text` — the array-ness rides ONLY in the descriptor. */
  const REPEATER_VARS: VariableSourceVar[] = [
    {
      source: 'trigger',
      path: 'trigger.fields.items',
      name: 'Items',
      type: 'text',
      descriptor: {
        base: 'object',
        nullable: false,
        array: true,
        fields: [{ key: 'price', label: 'Price', descriptor: { base: 'number', nullable: false, array: false } }],
      },
    },
  ];
  const REPEATER_NODES = variableFeedTree(REPEATER_VARS, []);

  it('offers the ARRAY ops (Get element) once the repeater descriptor is threaded', async () => {
    // The referenced repeater's node type is the degraded `text` — WITHOUT F1's descriptor threading the
    // pipeline would root at a scalar and offer NO array ops. With it, the repeater roots as a LIST.
    const wrapper = mount(VariablePanel, {
      attachTo: document.body,
      props: {
        open: true,
        state: {
          id: 'trigger.fields.items',
          name: 'Items',
          type: 'text',
          locked: false,
          pipeline: [],
          resultType: 'text',
        },
        nodes: REPEATER_NODES,
        catalog: standardOperationsCatalog(),
        argVariables: REPEATER_VARS,
      },
    });

    Array.from(document.body.querySelectorAll('button'))
      .find((b) => b.textContent?.trim() === 'Add operation')!
      .click();
    await new Promise((r) => setTimeout(r, 0));
    await nextTick();

    // "Get element" (array_at) is offered — it appears ONLY because the object-array descriptor roots
    // the pipeline as a list; the flat `text` type alone offers no array ops.
    expect(document.body.textContent).toContain('Get element');
    wrapper.unmount();
  });
});
