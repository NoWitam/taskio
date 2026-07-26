// @vitest-environment happy-dom
// VariableReferenceEditor.dom.spec — the SHARED body of "edit this variable reference" (B3),
// extracted from ValueOrVariableField's operations modal so the markdown chip, the if-block
// condition and the condition builder can all be moved onto it.
//
// What is pinned here is the CONTRACT the extraction created, i.e. everything a future host
// gets for free and must not have to re-derive:
//   • the wire-free `VariableRefDraft` v-model (in AND out),
//   • the source header resolved from the TREE (label / glyph / markers / type badge),
//   • the nullable-gated typed default (VariableDefaultField) — including "empty ⇒ null",
//   • the terminal-status strip (`data-type-satisfied`) + the choice-target hint,
//   • the CHANGE-SOURCE affordance and THE PROMOTED RULE: switching source while a non-empty
//     pipeline exists ALWAYS confirms first (previously only the condition modal did),
//   • the arg-variable slot, re-exposed with each argument's derived variable POLICY.
// The behaviour of the pieces it composes (the browser, the pipeline editor, the typed literal
// controls) is covered by their own specs.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { h, nextTick } from 'vue';
import VariableReferenceEditor from '../VariableReferenceEditor.vue';
import VariablePipelineEditor from '../../editor/extensions/VariablePipelineEditor.vue';
import { buildVariableTree } from '../variableTree';
import { standardOperationsCatalog } from '../../editor/extensions/standardOperations';
import { setLocale } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import type { VariableRefDraft, VariableSourceVar } from '../types';

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
    path: 'trigger.fields.status',
    name: 'Status',
    type: 'enum',
    enumOptions: ['open', 'done'],
    descriptor: {
      base: 'enum',
      nullable: false,
      array: false,
      options: [
        { key: 'open', label: 'Open ticket' },
        { key: 'done', label: 'Resolved' },
      ],
    },
  },
];
const TREE = buildVariableTree(VARS, {});

function draftFor(path: string, over: Partial<VariableRefDraft> = {}): VariableRefDraft {
  const type = VARS.find((v) => v.path === path)!.type;
  return { source: 'trigger', path, type, pipeline: [], default: null, ...over };
}

/** Scoped-slot renderers keyed by slot name (VTU accepts any render function). */
type SlotMap = Record<string, (slotProps: Record<string, unknown>) => unknown>;

function mountEditor(props: Record<string, unknown> = {}, slots: SlotMap = {}) {
  return mount(VariableReferenceEditor, {
    attachTo: document.body,
    props: {
      nodes: TREE,
      operationsCatalog: standardOperationsCatalog(),
      modelValue: draftFor('trigger.fields.name'),
      ...props,
    },
    slots,
  });
}

type Wrapper = ReturnType<typeof mountEditor>;

function lastDraft(wrapper: Wrapper): VariableRefDraft | null {
  const emitted = wrapper.emitted('update:modelValue');
  return (emitted?.[emitted.length - 1]?.[0] ?? null) as VariableRefDraft | null;
}

/**
 * A button of the CONFIRM dialog by its text. Scoped to the dialog on purpose: the
 * change-source trigger's own placeholder reads "Change variable" too, and it sits earlier in
 * the DOM — a document-wide query would re-open the picker instead of confirming.
 */
function confirmButton(text: string): HTMLButtonElement {
  const dialog = document.body.querySelector('[role="dialog"]')!;
  return Array.from(dialog.querySelectorAll('button')).find(
    (b) => b.textContent?.trim() === text,
  ) as HTMLButtonElement;
}

/** Open the change-source browser and click the row whose label matches. */
async function pickSource(wrapper: Wrapper, label: string): Promise<void> {
  await wrapper.get('[role="combobox"]').trigger('click');
  await nextTick();
  await Promise.resolve();
  await nextTick();
  const row = Array.from(document.body.querySelectorAll<HTMLElement>('[role="treeitem"]')).find(
    (el) => el.querySelector('span.truncate')?.textContent?.trim() === label,
  );
  row!.click();
  await nextTick();
}

describe('VariableReferenceEditor', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('renders the source header from the TREE — label + type badge + markers', () => {
    const wrapper = mountEditor({ modelValue: draftFor('trigger.fields.nickname') });

    const header = wrapper.get('[data-variable-source]');
    expect(header.text()).toContain('Nickname');
    // The TRUE type reads as a badge, and the nullable descriptor marks the glyph.
    expect(header.text()).toContain('Text');
    expect(header.find('[data-marker="optional"]').exists()).toBe(true);

    wrapper.unmount();
  });

  it('offers the default block ONLY for a NULLABLE variable, and normalises "cleared" to null', async () => {
    const nonNull = mountEditor({ modelValue: draftFor('trigger.fields.name') });
    expect(nonNull.find('[data-vov-default]').exists()).toBe(false);
    nonNull.unmount();

    const wrapper = mountEditor({
      modelValue: draftFor('trigger.fields.nickname', { default: 'Anonymous' }),
    });
    const input = wrapper.get('[data-vov-default] input');
    expect((input.element as HTMLInputElement).value).toBe('Anonymous'); // hydrated

    await input.setValue('');
    // Cleared ⇒ NULL (never ''), which is the host's single "omit the key" signal.
    expect(lastDraft(wrapper)!.default).toBeNull();

    wrapper.unmount();
  });

  it('reports the terminal status: satisfied, and the CHOICE-target hint when only the last op is wrong', async () => {
    const wrapper = mountEditor({
      modelValue: draftFor('trigger.fields.status'),
      resultTypes: ['enum'],
    });
    // An identity enum ref returns `enum` — the type matches …
    expect(wrapper.get('[data-type-satisfied]').attributes('data-type-satisfied')).toBe('true');

    // … but with a destination choice set it must ALSO end on a choice-producing op.
    await wrapper.setProps({ targetOptions: [{ value: 'urgent', label: 'Urgent' }] });
    const strip = wrapper.get('[data-type-satisfied]');
    expect(strip.attributes('data-type-satisfied')).toBe('false');
    expect(strip.text()).toContain('map it to one of this field’s choices');

    wrapper.unmount();
  });

  it('changing the source with an EMPTY pipeline applies straight away (source/path/type replaced)', async () => {
    const wrapper = mountEditor({ modelValue: draftFor('trigger.fields.name') });

    await pickSource(wrapper, 'Status');

    expect(lastDraft(wrapper)).toEqual({
      source: 'trigger',
      path: 'trigger.fields.status',
      type: 'enum',
      pipeline: [],
      default: null,
    });

    wrapper.unmount();
  });

  it('THE PROMOTED RULE: changing the source with a NON-EMPTY pipeline always confirms first', async () => {
    const wrapper = mountEditor({
      modelValue: draftFor('trigger.fields.name', {
        pipeline: [{ stepId: 's1', operationId: 'text_length', args: {}, outputType: 'number' }],
        default: 'x',
      }),
    });

    await pickSource(wrapper, 'Status');
    // Nothing committed yet — a confirm went up instead.
    expect(wrapper.emitted('update:modelValue')).toBeUndefined();
    expect(document.body.textContent).toContain('Change the variable?');

    // Cancel KEEPS the current source + its pipeline.
    confirmButton('Keep variable').click();
    await nextTick();
    expect(wrapper.emitted('update:modelValue')).toBeUndefined();

    // Confirming applies the new source and DROPS the (now wrongly typed) pipeline + default.
    await pickSource(wrapper, 'Status');
    confirmButton('Change variable').click();
    await nextTick();

    expect(lastDraft(wrapper)).toEqual({
      source: 'trigger',
      path: 'trigger.fields.status',
      type: 'enum',
      pipeline: [],
      default: null,
    });

    wrapper.unmount();
  });

  it('hides the change-source affordance when the host owns the picker (`:change-source="false"`)', () => {
    const wrapper = mountEditor({ changeSource: false });
    expect(wrapper.find('[role="combobox"]').exists()).toBe(false);
    // The read-only header still renders.
    expect(wrapper.find('[data-variable-source]').exists()).toBe(true);
    wrapper.unmount();
  });

  it('feeds the pipeline editor the base type + the referenced variable’s HUMAN enum options', () => {
    const wrapper = mountEditor({ modelValue: draftFor('trigger.fields.status') });

    const pipeline = wrapper.findComponent(VariablePipelineEditor);
    expect(pipeline.props('baseType')).toBe('enum');
    expect(pipeline.props('sourceOptions')).toEqual([
      { label: 'Open ticket', value: 'open' },
      { label: 'Resolved', value: 'done' },
    ]);

    wrapper.unmount();
  });

  it('re-exposes the argVariable slot enriched with each NON-structural argument’s derived variable POLICY', async () => {
    const seen: Array<Record<string, unknown>> = [];
    const pool: VariableSourceVar[] = [
      { source: 'trigger', path: 'trigger.title', name: 'Title', type: 'text' },
    ];
    const wrapper = mountEditor(
      {
        // text_append has ONE value-typed (text) arg — a whole-arg value variable.
        modelValue: draftFor('trigger.fields.name', {
          pipeline: [
            { stepId: 's1', operationId: 'text_append', args: { value: '' }, outputType: 'text' },
          ],
        }),
        argVariables: pool,
      },
      {
        argVariable: (slotProps: Record<string, unknown>) => {
          seen.push(slotProps);
          return h('div', { class: 'arg-slot' });
        },
      },
    );

    // Enter the step's edit mode so its args render.
    await wrapper.get('ol button').trigger('click');
    await nextTick();

    expect(wrapper.findAll('.arg-slot').length).toBeGreaterThan(0);
    const value = seen.find((p) => (p.arg as { type: string }).type === 'text')!;
    expect(value).toBeTruthy();
    // The derived policy: the show-all pool, the FULL coercion catalog, and the value arg's ['text']
    // terminal gate — the backend's argVariablePolicy, mirrored.
    expect(value.variables).toEqual(pool);
    expect(value.operationsCatalog).toEqual(standardOperationsCatalog());
    expect(value.resultTypes).toEqual(['text']);
    // The pipeline editor's own slot props still ride through (depth, setValue, options…).
    expect(value.depth).toBe(1);
    expect(typeof value.setValue).toBe('function');

    wrapper.unmount();
  });

  it('Defect-3: a STRUCTURAL sourceMap re-exposes the slot PER ENTRY, typed to the entry target (not one whole-arg slot)', async () => {
    const seen: Array<Record<string, unknown>> = [];
    const pool: VariableSourceVar[] = [
      { source: 'trigger', path: 'trigger.title', name: 'Title', type: 'text' },
    ];
    const wrapper = mountEditor(
      {
        modelValue: draftFor('trigger.fields.status', {
          pipeline: [
            { stepId: 's1', operationId: 'enum_to_text', args: { mapping: {} }, outputType: 'text' },
          ],
        }),
        argVariables: pool,
      },
      {
        argVariable: (slotProps: Record<string, unknown>) => {
          seen.push(slotProps);
          return h('div', { class: 'arg-slot' });
        },
      },
    );

    await wrapper.get('ol button').trigger('click');
    await nextTick();

    // The map is NEVER a whole-arg variable now — each source option (open, done) is its own entry.
    expect(seen.some((p) => (p.arg as { type: string }).type === 'sourceMap')).toBe(false);
    const entry = seen.find((p) => String((p.arg as { id: string }).id).startsWith('mapping.'))!;
    expect(entry).toBeTruthy();
    // A TEXT map entry (enum_to_text): the show-all pool, the FULL coercion catalog, a ['text'] terminal,
    // no destination choice, and the pipeline editor's own slot props ride through.
    expect(entry.variables).toEqual(pool);
    expect(entry.operationsCatalog).toEqual(standardOperationsCatalog());
    expect(entry.resultTypes).toEqual(['text']);
    expect(entry.targetOptions).toEqual([]);
    expect(entry.depth).toBe(1);
    expect(typeof entry.setValue).toBe('function');

    wrapper.unmount();
  });
});
