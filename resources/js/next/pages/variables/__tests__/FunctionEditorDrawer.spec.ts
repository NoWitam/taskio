// @vitest-environment happy-dom
// FunctionEditorDrawer.spec — the create/edit form. Asserts the ARGS builder (add/remove), the
// client REJECTION of a reserved scope name (input/element/index) with a clear message + a blocked
// Save, the body TERMINAL gate (a wrong return terminal disables Save — an empty body is the identity
// function, valid only when input == return), and the WIRING it feeds the shared body editor: the
// {input, args} SCOPE vars as its op-argument pool (all `source:'scope'`, NEVER globals), the return
// type as the required terminal, a read-only source, and a suppressed default.
//
// The Drawer TELEPORTS its content to <body>, so buttons/inputs are queried there. The heavy shared
// VariableReferenceEditor is STUBBED to a prop-capturing placeholder — this spec pins the drawer's own
// logic + wiring; the scope feed's downstream behaviour is proven separately (functionBodyScope.dom.spec).
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { setLocale } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

const cap = vi.hoisted(() => ({ props: null as Record<string, unknown> | null }));

vi.mock('../../../ui/variables/VariableReferenceEditor.vue', () => ({
  default: {
    name: 'VariableReferenceEditorStub',
    props: {
      modelValue: { type: Object, default: null },
      nodes: { type: Array, default: () => [] },
      changeSource: { type: Boolean, default: true },
      operationsCatalog: { type: Array, default: () => [] },
      resultTypes: { type: Array, default: () => [] },
      maxSteps: { type: Number, default: undefined },
      argVariables: { type: Array, default: () => [] },
      hidePresenceOps: { type: Boolean, default: true },
      hideDefault: { type: Boolean, default: false },
      disabled: { type: Boolean, default: false },
    },
    setup(props: Record<string, unknown>) {
      cap.props = props;
      return () => null;
    },
  },
}));

import FunctionEditorDrawer from '../FunctionEditorDrawer.vue';
import Select from '../../../ui/forms/Select.vue';

function buttons(): HTMLButtonElement[] {
  return Array.from(document.body.querySelectorAll('button'));
}
function button(re: RegExp): HTMLButtonElement | undefined {
  return buttons().find((b) => re.test(b.textContent ?? ''));
}
function buttonByAria(label: string): HTMLButtonElement | undefined {
  return buttons().find((b) => b.getAttribute('aria-label') === label);
}
const saveButton = () => button(/Create function|^Save$/);
function nameInputs(): HTMLInputElement[] {
  return Array.from(document.body.querySelectorAll('input[aria-label="Name"]'));
}
function setInput(el: HTMLInputElement, value: string): void {
  el.value = value;
  el.dispatchEvent(new Event('input', { bubbles: true }));
}

async function flush() {
  await nextTick();
  await Promise.resolve();
  await nextTick();
}

describe('FunctionEditorDrawer', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
    cap.props = null;
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('adds and removes argument rows', async () => {
    const wrapper = mount(FunctionEditorDrawer, {
      props: { open: true, operationsCatalog: [] },
      attachTo: document.body,
    });
    await flush();

    expect(nameInputs().length).toBe(0);

    button(/Add argument/)!.click();
    await flush();
    expect(nameInputs().length).toBe(1);

    buttonByAria('Remove argument')!.click();
    await flush();
    expect(nameInputs().length).toBe(0);

    wrapper.unmount();
  });

  it('rejects a reserved scope name (input/element/index) with a message + blocks Save', async () => {
    const wrapper = mount(FunctionEditorDrawer, {
      props: { open: true, operationsCatalog: [] },
      attachTo: document.body,
    });
    await flush();

    // A fresh function is text→text (identity) → Save is enabled to start.
    expect(saveButton()!.hasAttribute('disabled')).toBe(false);

    button(/Add argument/)!.click();
    await flush();

    setInput(nameInputs()[0], 'input');
    await flush();

    // A clear reserved-name message shows, and Save is blocked.
    expect(document.body.textContent).toContain('reserved');
    expect(saveButton()!.hasAttribute('disabled')).toBe(true);

    // A valid name clears it.
    setInput(nameInputs()[0], 'factor');
    await flush();
    expect(document.body.textContent).not.toContain('reserved');
    expect(saveButton()!.hasAttribute('disabled')).toBe(false);

    wrapper.unmount();
  });

  it('the body TERMINAL gate disables Save when the return type ≠ the (empty-body) input type', async () => {
    const wrapper = mount(FunctionEditorDrawer, {
      props: { open: true, operationsCatalog: [] },
      attachTo: document.body,
    });
    await flush();

    // text → text (identity) is a valid empty body → Save enabled.
    expect(saveButton()!.hasAttribute('disabled')).toBe(false);

    // Switch the RETURN type to number: an empty text body can no longer reach number → Save blocked.
    const returnSelect = wrapper.findAllComponents(Select).find((c) => c.props('ariaLabel') === 'Return type')!;
    returnSelect.vm.$emit('update:modelValue', 'number');
    await flush();
    expect(saveButton()!.hasAttribute('disabled')).toBe(true);

    wrapper.unmount();
  });

  it('feeds the shared body editor the {input, args} scope vars (all scope, no globals) + return terminal', async () => {
    const wrapper = mount(FunctionEditorDrawer, {
      props: { open: true, operationsCatalog: [] },
      attachTo: document.body,
    });
    await flush();

    // Add one arg named "factor".
    button(/Add argument/)!.click();
    await flush();
    setInput(nameInputs()[0], 'factor');
    await flush();

    const props = cap.props!;
    // The op-argument pool is the function scope: input + factor, ALL source:scope, NO globals.
    const argVars = props.argVariables as Array<{ source: string; path: string }>;
    expect(argVars.map((v) => v.path)).toEqual(['input', 'factor']);
    expect(argVars.every((v) => v.source === 'scope')).toBe(true);
    // The body must END on the return type; the source is read-only; the default is suppressed.
    expect(props.resultTypes).toEqual(['text']);
    expect(props.changeSource).toBe(false);
    expect(props.hideDefault).toBe(true);
    expect(props.hidePresenceOps).toBe(false);
    // The draft roots at the `input` scope var of the input type.
    expect(props.modelValue).toMatchObject({ source: 'scope', path: 'input', type: 'text' });

    wrapper.unmount();
  });

  it('emits a well-formed write payload on Save (name trimmed, args mapped, description omitted when empty)', async () => {
    const wrapper = mount(FunctionEditorDrawer, {
      props: { open: true, operationsCatalog: [] },
      attachTo: document.body,
    });
    await flush();

    // Function name (the first, FormField-labelled input — not the arg name input).
    const fnName = document.body.querySelector('input:not([aria-label])') as HTMLInputElement;
    setInput(fnName, '  Format  ');
    // One typed arg.
    button(/Add argument/)!.click();
    await flush();
    setInput(nameInputs()[0], 'factor');
    await flush();

    saveButton()!.click();
    await flush();

    const emitted = wrapper.emitted('submit');
    expect(emitted).toBeTruthy();
    expect(emitted![0][0]).toEqual({
      name: 'Format',
      input_type: 'text',
      return_type: 'text',
      args: [{ name: 'factor', type: 'text' }],
      body: [],
    });

    wrapper.unmount();
  });

  it('excludes the function’s OWN fn: op from its body add-menu (no self-reference)', async () => {
    const wrapper = mount(FunctionEditorDrawer, {
      props: {
        open: true,
        func: {
          id: 'me',
          name: 'Me',
          description: null,
          input_type: 'text',
          return_type: 'text',
          args: [],
          body: [],
          is_owner: true,
          can_be_edited: true,
          can_be_deleted: true,
          creator: null,
          created_at: null,
          updated_at: null,
        },
        operationsCatalog: [
          { id: 'fn:me', label: 'Me', inputTypes: ['text'], outputType: 'text', args: [] },
          { id: 'fn:other', label: 'Other', inputTypes: ['text'], outputType: 'text', args: [] },
        ],
      },
      attachTo: document.body,
    });
    await flush();

    const catalog = cap.props!.operationsCatalog as Array<{ id: string }>;
    expect(catalog.map((o) => o.id)).toEqual(['fn:other']); // fn:me removed
    expect(catalog.some((o) => o.id === 'fn:me')).toBe(false);

    wrapper.unmount();
  });
});
