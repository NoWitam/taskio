// @vitest-environment happy-dom
// variableFeedReactivity.dom.spec — REGRESSION for the frozen-variable-feed bug.
//
// The editor builds its Tiptap extensions ONCE, at setup. Before the fix, the variable
// feature's `variables` array was captured in that build and stored on the extension, so
// every consumer (the chip, the if-branch header, the `{` insert suggestion) was reading a
// SNAPSHOT taken at mount. In the real app the workflow step card derives its variables from
// an ASYNC-fetched catalog, so whether an editor ever saw a populated list was a race — and
// renaming a step key, inserting an earlier step or switching the trigger form never reached
// an already-open editor at all.
//
// The fix is the OPTIONAL `source()` / `catalog()` getters on `VariableFeatureConfig`: they
// are read at CALL time by the extension and inside the consumers' computeds, so a reactive
// host feed stays live. These tests would fail against the pre-fix code — the getters did
// not exist and the storage held frozen arrays.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick, ref } from 'vue';
import MarkdownEditor from '../MarkdownEditor.vue';
import { createVariable, sourceVarToDefinition } from '../extensions/variable';
import { setLocale } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import type { VariableSourceVar } from '../../variables/types';
import type { VariableOperationDefinition, VariableStorage } from '../extensions/types';

/** The variable the document already references — the catalog it belongs to arrives LATER. */
const NULLABLE_VAR: VariableSourceVar = {
  source: 'trigger',
  path: 'trigger.fields.nickname',
  name: 'Nickname',
  type: 'text',
  descriptor: { base: 'text', nullable: true, array: false },
};

const APPEND_OP: VariableOperationDefinition = {
  id: 'text_append',
  label: 'Append',
  inputTypes: ['text'],
  outputType: 'text',
  args: [{ id: 'value', label: 'Value', type: 'text' }],
};

/** A saved document holding ONE variable chip pointing at `trigger.fields.nickname`. */
const DOC =
  '@[variable]("' +
  JSON.stringify({
    v: 1,
    data: {
      id: 'trigger.fields.nickname',
      name: 'Nickname',
      type: 'text',
      locked: false,
      pipeline: [],
      resultType: 'text',
    },
  }).replace(/"/g, '\\"') +
  '")';

describe('the editor variable feed is LIVE, not a mount-time snapshot', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('a chip picks up variables that arrive AFTER the editor mounted', async () => {
    // The host's feed starts EMPTY — exactly the async-catalog race in the step card.
    const source = ref<VariableSourceVar[]>([]);

    const wrapper = mount(MarkdownEditor, {
      attachTo: document.body,
      props: {
        modelValue: DOC,
        variables: {
          variables: [],
          operationsCatalog: [],
          source: () => source.value,
          catalog: () => [APPEND_OP],
        },
      },
    });
    await nextTick();
    await nextTick();

    // The chip renders, but with no definition behind it: no type MARKERS.
    const chip = wrapper.get('.next-var-chip');
    expect(chip.find('[data-marker="optional"]').exists()).toBe(false);

    // …the catalog resolves.
    source.value = [NULLABLE_VAR];
    await nextTick();

    // The SAME, still-open editor now resolves the reference: the nullable "?" marker
    // appears. Against the pre-fix (frozen) storage this stays false forever.
    expect(wrapper.get('.next-var-chip').find('[data-marker="optional"]').exists()).toBe(true);

    wrapper.unmount();
  });

  it('a chip follows a RENAME of an already-referenced variable', async () => {
    const source = ref<VariableSourceVar[]>([NULLABLE_VAR]);
    const wrapper = mount(MarkdownEditor, {
      attachTo: document.body,
      props: {
        modelValue: DOC,
        variables: { variables: [], operationsCatalog: [], source: () => source.value },
      },
    });
    await nextTick();
    await nextTick();
    expect(wrapper.get('.next-var-chip').find('[data-marker="optional"]').exists()).toBe(true);

    // The same path, now REQUIRED (e.g. the form field became mandatory).
    source.value = [{ ...NULLABLE_VAR, descriptor: { base: 'text', nullable: false, array: false } }];
    await nextTick();

    expect(wrapper.get('.next-var-chip').find('[data-marker="optional"]').exists()).toBe(false);

    wrapper.unmount();
  });

  it('the extension storage reads the getters at CALL time and falls back to the frozen arrays', () => {
    const source = ref<VariableSourceVar[]>([]);
    const catalog = ref<VariableOperationDefinition[]>([]);

    const node = createVariable({
      variables: [{ id: 'seed', name: 'Seed', type: 'text' }],
      operationsCatalog: [],
      source: () => source.value,
      catalog: () => catalog.value,
    });
    const storage = node.config.addStorage!.call({} as never) as VariableStorage;

    // Nothing live yet ⇒ the frozen seed array is the fallback (older hosts keep working).
    expect(storage.getDefinitions().map((d) => d.id)).toEqual(['seed']);
    expect(storage.getCatalog()).toEqual([]);

    // Once the host has a feed, EVERY read reflects it — the same storage object.
    source.value = [NULLABLE_VAR];
    catalog.value = [APPEND_OP];
    expect(storage.getDefinitions().map((d) => d.id)).toEqual(['trigger.fields.nickname']);
    expect(storage.getSource()).toEqual([NULLABLE_VAR]);
    expect(storage.getCatalog()).toEqual([APPEND_OP]);
  });

  it('a host with NO getters keeps the previous (array) behaviour', () => {
    const node = createVariable({
      variables: [{ id: 'a', name: 'A', type: 'text' }],
      operationsCatalog: [APPEND_OP],
    });
    const storage = node.config.addStorage!.call({} as never) as VariableStorage;

    expect(storage.getDefinitions().map((d) => d.id)).toEqual(['a']);
    expect(storage.getCatalog()).toEqual([APPEND_OP]);
    expect(storage.getSource()).toEqual([]);
    // The legacy fields stay on the storage for anything reading them directly.
    expect(storage.definitions.map((d) => d.id)).toEqual(['a']);
  });

  it('projects a shared-model variable onto the editor definition (identity-only: id === path)', () => {
    expect(
      sourceVarToDefinition({
        source: 'trigger',
        path: 'trigger.fields.status',
        name: 'Status',
        type: 'enum',
        descriptor: {
          base: 'enum',
          nullable: true,
          array: false,
          options: [{ key: 'open', label: 'Open' }],
        },
      }),
    ).toEqual({
      id: 'trigger.fields.status',
      name: 'Status',
      type: 'enum',
      options: [{ label: 'Open', value: 'open' }],
      nullable: true,
    });

    // No descriptor: the flat enumOptions become the choices, value === label.
    expect(
      sourceVarToDefinition({
        source: 'steps',
        path: 'steps.make.kind',
        name: 'Kind',
        type: 'enum',
        enumOptions: ['a'],
      }),
    ).toEqual({ id: 'steps.make.kind', name: 'Kind', type: 'enum', options: [{ label: 'a', value: 'a' }] });
  });

  it('an editor with NO variables feature is untouched (pages/tasks)', async () => {
    // The variable node is never built, so nothing reads a feed — and the editor still works.
    const wrapper = mount(MarkdownEditor, {
      attachTo: document.body,
      props: { modelValue: 'Plain **text**' },
    });
    await nextTick();

    expect(wrapper.find('.next-var-chip').exists()).toBe(false);
    expect(wrapper.find('.next-md-shell').exists()).toBe(true);
    // No `variable` extension at all ⇒ no storage for a consumer to read.
    expect(
      (wrapper.vm as unknown as { editor?: { storage?: Record<string, unknown> } }).editor?.storage
        ?.variable,
    ).toBeUndefined();

    wrapper.unmount();
  });
});
