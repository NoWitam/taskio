// @vitest-environment happy-dom
// functionBodyScope.dom.spec — the NOVEL part of the function editor: the body pipeline's SCOPE feed.
// The body is the shared VariableReferenceEditor rooted at a `source:'scope'` `input` draft, fed the
// function's {input, <argName>…} scope vars as its op-argument POOL (`arg-variables`). These tests pin,
// with the SAME wiring the drawer uses:
//   • TOP LEVEL — a body op's argument offers input + args (all `source:'scope'`), and NO globals;
//   • FRAME STACK — inside a map/filter over the body, a nested element op offers BOTH the array's
//     element/index AND the function's input/args (the shared editor merges them);
//   • ADD MENU — a custom `fn:<uuid>` op surfaces in the pipeline add menu on a matching input type,
//     with its own label, and renders its declared ARGS when added.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { h, nextTick } from 'vue';
import { setLocale } from '../../../app/i18n';
import VariableReferenceEditor from '../../../ui/variables/VariableReferenceEditor.vue';
import VariablePipelineEditor from '../../../ui/editor/extensions/VariablePipelineEditor.vue';
import { standardOperationsCatalog } from '../../../ui/editor/extensions/standardOperations';
import { buildVariableTree } from '../../../ui/variables/variableTree';
import type { VariableOperationDefinition } from '../../../ui/editor/extensions/types';
import type { VariableSourceVar } from '../../../ui/variables/types';

function flush() {
  return new Promise((r) => setTimeout(r, 0));
}

const CATALOG = standardOperationsCatalog();

type SlotProps = { variables?: Array<{ source: string; path: string }>; scopeVariables?: Array<{ path: string }> };
function captureSlot(bucket: SlotProps[]) {
  return {
    argVariable: (p: SlotProps) => {
      bucket.push(p);
      return h('div', { class: 'arg-var-slot' });
    },
  };
}

const INPUT_TEXT: VariableSourceVar = { source: 'scope', path: 'input', name: 'Input', type: 'text' };
const INPUT_MULTI: VariableSourceVar = {
  source: 'scope',
  path: 'input',
  name: 'Input',
  type: 'multi',
  descriptor: { base: 'enum', nullable: false, array: true, options: [{ key: 'open', label: 'Open' }, { key: 'done', label: 'Done' }] },
};
const FACTOR: VariableSourceVar = { source: 'scope', path: 'factor', name: 'factor', type: 'number' };

describe('function body — scope feed (top level)', () => {
  beforeEach(() => setLocale('en'));
  afterEach(() => {
    document.body.innerHTML = '';
  });

  it('offers the input + args (all source:scope) to a body op argument, and NO globals', async () => {
    const captured: SlotProps[] = [];
    const wrapper = mount(VariableReferenceEditor, {
      props: {
        modelValue: {
          source: 'scope',
          path: 'input',
          type: 'text',
          pipeline: [{ stepId: 's1', operationId: 'text_append', args: { value: '' }, outputType: 'text' }],
          default: null,
        },
        nodes: buildVariableTree([INPUT_TEXT, FACTOR], {}),
        changeSource: false,
        operationsCatalog: CATALOG,
        resultTypes: ['text'],
        argVariables: [INPUT_TEXT, FACTOR],
        hidePresenceOps: false,
        hideDefault: true,
      },
      slots: captureSlot(captured),
      attachTo: document.body,
    });

    // Expand the text_append step → its value arg offers the arg-variable slot.
    await wrapper.get('ol button').trigger('click');
    await flush();

    const pooled = captured.find((p) => Array.isArray(p.variables) && p.variables.some((v) => v.path === 'input'));
    expect(pooled, 'an arg slot carrying the scope pool').toBeTruthy();
    const paths = pooled!.variables!.map((v) => v.path);
    expect(paths).toContain('input');
    expect(paths).toContain('factor');
    // At the TOP level there is no array frame, so no element/index leaks in.
    expect(paths).not.toContain('element');
    expect(paths).not.toContain('index');
    // The pool is scope-only — NOTHING global.
    expect(pooled!.variables!.every((v) => v.source === 'scope')).toBe(true);

    wrapper.unmount();
  });
});

describe('function body — FRAME STACK (map/filter over the body)', () => {
  beforeEach(() => setLocale('en'));
  afterEach(() => {
    document.body.innerHTML = '';
  });

  it('a nested element op offers element/index AND the function input/args', async () => {
    const captured: SlotProps[] = [];
    const wrapper = mount(VariableReferenceEditor, {
      props: {
        modelValue: {
          source: 'scope',
          path: 'input',
          type: 'multi',
          pipeline: [
            {
              stepId: 's1',
              operationId: 'array_filter',
              args: { pipeline: [{ op: 'enum_is', args: { value: 'open' } }] },
              outputType: 'multi',
            },
          ],
          default: null,
        },
        nodes: buildVariableTree([INPUT_MULTI, FACTOR], {}),
        changeSource: false,
        operationsCatalog: CATALOG,
        resultTypes: ['multi'],
        argVariables: [INPUT_MULTI, FACTOR],
        hidePresenceOps: false,
        hideDefault: true,
      },
      slots: captureSlot(captured),
      attachTo: document.body,
    });

    // Open the filter step, then the nested enum_is so its arg offers the slot with the element frame.
    await wrapper.get('ol button').trigger('click');
    await flush();
    const nestedChip = wrapper.findAll('ol button').find((b) => b.text().includes('Is'));
    expect(nestedChip, 'nested enum_is chip').toBeTruthy();
    await nestedChip!.trigger('click');
    await flush();

    // The pool the nested arg sees is the FRAME STACK: element + index (from the array op) AND the
    // function's input + factor (propagated down).
    const stack = captured.find((p) => Array.isArray(p.variables) && p.variables.some((v) => v.path === 'element'));
    expect(stack, 'an arg slot carrying the element frame').toBeTruthy();
    const paths = stack!.variables!.map((v) => v.path);
    expect(paths).toContain('element');
    expect(paths).toContain('index');
    expect(paths).toContain('input');
    expect(paths).toContain('factor');

    wrapper.unmount();
  });
});

describe('function body — custom fn: op in the add menu', () => {
  beforeEach(() => {
    setLocale('en');
    document.body.innerHTML = '';
  });
  afterEach(() => {
    document.body.innerHTML = '';
  });

  const FN_OP: VariableOperationDefinition = {
    id: 'fn:double',
    label: 'Double it',
    description: 'x2',
    inputTypes: ['text'],
    outputType: 'text',
    args: [{ id: 'factor', label: 'factor', type: 'number' }],
  };

  async function openAddMenu(): Promise<void> {
    Array.from(document.body.querySelectorAll('button'))
      .find((b) => b.textContent?.trim() === 'Add operation')!
      .click();
    await flush();
    await nextTick();
  }

  it('surfaces the fn op (its own label) on a matching input type and renders its declared args', async () => {
    const wrapper = mount(VariablePipelineEditor, {
      props: { baseType: 'text', catalog: [...CATALOG, FN_OP], modelValue: [] },
      attachTo: document.body,
    });
    await openAddMenu();

    // The function's own name labels the add-menu entry (never the fn:<uuid> id).
    expect(document.body.textContent).toContain('Double it');
    expect(document.body.textContent).not.toContain('fn:double');

    // Add it → the step opens in edit mode and renders its declared `factor` argument.
    Array.from(document.body.querySelectorAll('button'))
      .find((b) => b.textContent?.includes('Double it'))!
      .click();
    await flush();
    expect(wrapper.text()).toContain('factor');

    wrapper.unmount();
  });
});
