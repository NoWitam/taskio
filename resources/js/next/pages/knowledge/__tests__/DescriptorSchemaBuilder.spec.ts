// @vitest-environment happy-dom
// DescriptorSchemaBuilder.spec — the metadata-schema editor.
//
// Asserts what a schema builder can silently get wrong: that adding / editing / removing / moving
// a field emits the RIGHT stored shape (`{key, label, descriptor}` with the shared descriptor
// vocabulary), that key validation mirrors the backend and only SHOWS once the parent asks, that
// an enum's choices ride along in the descriptor, and that a field the builder cannot express is
// left strictly alone rather than rewritten.
//
// The real design-system controls are mounted (no stubs): the point is the emitted contract, and
// stubbing SegmentedControl / Switch would test the stubs' idea of a base picker.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { nextTick } from 'vue';
import DescriptorSchemaBuilder from '../DescriptorSchemaBuilder.vue';
import { setLocale, translate } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import type { KnowledgeSchemaField } from '../types';

beforeEach(() => {
  installBrowserMocks();
  setLocale('en');
});
afterEach(() => {
  restoreBrowserMocks();
  vi.restoreAllMocks();
});

type Wrapper = VueWrapper<InstanceType<typeof DescriptorSchemaBuilder>>;

function mountBuilder(modelValue: KnowledgeSchemaField[] = [], props: Record<string, unknown> = {}): Wrapper {
  return mount(DescriptorSchemaBuilder, {
    props: { modelValue, ...props },
  }) as Wrapper;
}

/** The last `update:modelValue` payload (the schema the parent would persist). */
function lastSchema(wrapper: Wrapper): KnowledgeSchemaField[] {
  const emitted = wrapper.emitted('update:modelValue');
  expect(emitted, 'expected the builder to have emitted a schema').toBeTruthy();
  return emitted![emitted!.length - 1][0] as KnowledgeSchemaField[];
}

/** The last `update:valid` payload. */
function lastValid(wrapper: Wrapper): boolean {
  const emitted = wrapper.emitted('update:valid');
  return emitted![emitted!.length - 1][0] as boolean;
}

function fieldRows(wrapper: Wrapper) {
  return wrapper.findAll('ul > li');
}

/** The key / label inputs of one row, in DOM order. */
function rowInputs(wrapper: Wrapper, index: number) {
  return fieldRows(wrapper)[index].findAll('input');
}

async function addField(wrapper: Wrapper): Promise<void> {
  const button = wrapper
    .findAll('button')
    .find((b) => b.text() === translate('knowledge.schema.addField'));
  expect(button, 'expected an "Add field" button').toBeTruthy();
  await button!.trigger('click');
  await nextTick();
}

describe('empty state', () => {
  it('offers the first field instead of an empty box', () => {
    const wrapper = mountBuilder([]);

    expect(wrapper.text()).toContain(translate('knowledge.schema.empty'));
    expect(fieldRows(wrapper)).toHaveLength(0);
  });

  it('hides the add affordance when the viewer may not manage the base', () => {
    const wrapper = mountBuilder([], { disabled: true });

    expect(
      wrapper.findAll('button').some((b) => b.text() === translate('knowledge.schema.addField')),
    ).toBe(false);
  });
});

describe('seeding from a stored schema', () => {
  it('renders one row per stored field with its key + label', () => {
    const wrapper = mountBuilder([
      { key: 'channel', label: 'Channel', descriptor: { base: 'enum', nullable: false, array: false, options: [{ key: 'ig', label: 'IG' }] } },
      { key: 'weight', label: 'Weight', descriptor: { base: 'number', nullable: true, array: false } },
    ]);

    expect(fieldRows(wrapper)).toHaveLength(2);
    expect((rowInputs(wrapper, 0)[0].element as HTMLInputElement).value).toBe('channel');
    expect((rowInputs(wrapper, 0)[1].element as HTMLInputElement).value).toBe('Channel');
    expect((rowInputs(wrapper, 1)[0].element as HTMLInputElement).value).toBe('weight');
  });

  it('always announces its validity to the parent, even before any edit', () => {
    const wrapper = mountBuilder([]);
    expect(wrapper.emitted('update:valid')).toBeTruthy();
    expect(lastValid(wrapper)).toBe(true);
  });
});

describe('adding and editing fields', () => {
  it('adds a row and emits a text descriptor with the key derived from the label', async () => {
    const wrapper = mountBuilder([]);

    await addField(wrapper);
    expect(fieldRows(wrapper)).toHaveLength(1);

    await rowInputs(wrapper, 0)[1].setValue('Product line');
    await nextTick();

    expect(lastSchema(wrapper)).toEqual([
      {
        key: 'product_line',
        label: 'Product line',
        descriptor: { base: 'text', nullable: false, array: false },
      },
    ]);
  });

  it('pins the key once it is typed into (the label no longer drives it)', async () => {
    const wrapper = mountBuilder([]);
    await addField(wrapper);

    await rowInputs(wrapper, 0)[1].setValue('Product line');
    await rowInputs(wrapper, 0)[0].setValue('sku');
    await nextTick();
    await rowInputs(wrapper, 0)[1].setValue('Something else');
    await nextTick();

    expect(lastSchema(wrapper)[0].key).toBe('sku');
    expect(lastSchema(wrapper)[0].label).toBe('Something else');
  });

  it('emits the orthogonal nullable / array flags', async () => {
    const wrapper = mountBuilder([
      { key: 'tag', label: 'Tag', descriptor: { base: 'text', nullable: false, array: false } },
    ]);

    const switches = fieldRows(wrapper)[0].findAll('button[role="switch"]');
    expect(switches).toHaveLength(2);

    await switches[0].trigger('click'); // Optional
    await switches[1].trigger('click'); // List of values
    await nextTick();

    expect(lastSchema(wrapper)[0].descriptor).toEqual({ base: 'text', nullable: true, array: true });
  });

  it('switches the base and emits enum CHOICES inside the descriptor', async () => {
    const wrapper = mountBuilder([
      { key: 'channel', label: 'Channel', descriptor: { base: 'text', nullable: false, array: false } },
    ]);

    const radios = fieldRows(wrapper)[0].findAll('[role="radio"]');
    expect(radios).toHaveLength(5); // text | number | boolean | date | enum — no object/file/time
    await radios[4].trigger('click');
    await nextTick();

    // The choice editor appears; fill in the first choice.
    const inputs = rowInputs(wrapper, 0);
    // [0] key, [1] label, [2] choice key, [3] choice label
    await inputs[2].setValue('ig');
    await inputs[3].setValue('Instagram');
    await nextTick();

    expect(lastSchema(wrapper)[0].descriptor).toEqual({
      base: 'enum',
      nullable: false,
      array: false,
      options: [{ key: 'ig', label: 'Instagram' }],
    });
  });

  it('adds and removes enum choices', async () => {
    const wrapper = mountBuilder([
      {
        key: 'channel',
        label: 'Channel',
        descriptor: { base: 'enum', nullable: false, array: false, options: [{ key: 'ig', label: 'IG' }] },
      },
    ]);

    const addChoice = fieldRows(wrapper)[0]
      .findAll('button')
      .find((b) => b.text() === translate('knowledge.schema.optionAdd'));
    await addChoice!.trigger('click');
    await nextTick();

    await rowInputs(wrapper, 0)[4].setValue('tt');
    await nextTick();

    expect(lastSchema(wrapper)[0].descriptor.options).toEqual([
      { key: 'ig', label: 'IG' },
      { key: 'tt', label: 'tt' },
    ]);

    const removeChoice = fieldRows(wrapper)[0]
      .findAll('button')
      .filter((b) => b.attributes('aria-label') === translate('knowledge.schema.optionRemove'));
    await removeChoice[1].trigger('click');
    await nextTick();

    expect(lastSchema(wrapper)[0].descriptor.options).toEqual([{ key: 'ig', label: 'IG' }]);
  });
});

describe('removing and reordering', () => {
  const schema: KnowledgeSchemaField[] = [
    { key: 'a', label: 'A', descriptor: { base: 'text', nullable: false, array: false } },
    { key: 'b', label: 'B', descriptor: { base: 'number', nullable: false, array: false } },
  ];

  /** A row's icon button whose aria-label starts with the given i18n phrase. */
  function rowButton(wrapper: Wrapper, index: number, key: string) {
    return fieldRows(wrapper)
      [index].findAll('button')
      .find((b) => (b.attributes('aria-label') ?? '').startsWith(translate(key)));
  }

  it('removes a field', async () => {
    const wrapper = mountBuilder(schema);

    await rowButton(wrapper, 0, 'knowledge.schema.removeField')!.trigger('click');
    await nextTick();

    expect(lastSchema(wrapper).map((f) => f.key)).toEqual(['b']);
  });

  it('moves a field down (keyboard-reachable buttons, no drag & drop)', async () => {
    const wrapper = mountBuilder(schema);

    await rowButton(wrapper, 0, 'knowledge.schema.moveDown')!.trigger('click');
    await nextTick();

    expect(lastSchema(wrapper).map((f) => f.key)).toEqual(['b', 'a']);
  });

  it('moves a field up', async () => {
    const wrapper = mountBuilder(schema);

    await rowButton(wrapper, 1, 'knowledge.schema.moveUp')!.trigger('click');
    await nextTick();

    expect(lastSchema(wrapper).map((f) => f.key)).toEqual(['b', 'a']);
  });

  it('disables the move affordance at the ends', () => {
    const wrapper = mountBuilder(schema);

    expect(rowButton(wrapper, 0, 'knowledge.schema.moveUp')!.attributes('disabled')).toBeDefined();
    expect(rowButton(wrapper, 1, 'knowledge.schema.moveDown')!.attributes('disabled')).toBeDefined();
  });
});

describe('validation', () => {
  it('reports an unsafe key as invalid but stays QUIET until the parent asks', async () => {
    const wrapper = mountBuilder([]);
    await addField(wrapper);
    await rowInputs(wrapper, 0)[0].setValue('1bad');
    await rowInputs(wrapper, 0)[1].setValue('Bad');
    await nextTick();

    expect(lastValid(wrapper)).toBe(false);
    expect(wrapper.text()).not.toContain(translate('knowledge.schema.keyInvalid'));

    await wrapper.setProps({ showErrors: true });
    await nextTick();

    expect(wrapper.text()).toContain(translate('knowledge.schema.keyInvalid'));
  });

  it('shows a duplicate-key message on BOTH offending rows', async () => {
    const wrapper = mountBuilder(
      [
        { key: 'dup', label: 'One', descriptor: { base: 'text', nullable: false, array: false } },
        { key: 'dup', label: 'Two', descriptor: { base: 'text', nullable: false, array: false } },
      ],
      { showErrors: true },
    );
    await nextTick();

    const message = translate('knowledge.schema.keyDuplicate');
    expect(fieldRows(wrapper)[0].text()).toContain(message);
    expect(fieldRows(wrapper)[1].text()).toContain(message);
    expect(lastValid(wrapper)).toBe(false);
  });

  it('requires a label', async () => {
    const wrapper = mountBuilder([], { showErrors: true });
    await addField(wrapper);
    await rowInputs(wrapper, 0)[0].setValue('ok_key');
    await nextTick();

    expect(wrapper.text()).toContain(translate('knowledge.schema.labelRequired'));
    expect(lastValid(wrapper)).toBe(false);
  });

  it('lets a SERVER message win over the client verdict', async () => {
    const wrapper = mountBuilder(
      [{ key: 'dup', label: 'One', descriptor: { base: 'text', nullable: false, array: false } }],
      { showErrors: true, serverErrors: { 'metadata_schema.0.key': 'Server says no' } },
    );
    await nextTick();

    expect(fieldRows(wrapper)[0].text()).toContain('Server says no');
  });
});

describe('a descriptor the builder cannot express', () => {
  const legacy: KnowledgeSchemaField[] = [
    {
      key: 'blob',
      label: 'Blob',
      descriptor: { base: 'object', nullable: false, array: false, fields: [] },
    },
  ];

  it('is shown as read-only, without a type picker', () => {
    const wrapper = mountBuilder(legacy);

    expect(wrapper.text()).toContain(translate('knowledge.schema.unsupported'));
    expect(fieldRows(wrapper)[0].findAll('[role="radio"]')).toHaveLength(0);
    expect(rowInputs(wrapper, 0)[0].attributes('readonly')).toBeDefined();
  });

  it('survives an unrelated edit untouched', async () => {
    const wrapper = mountBuilder([
      ...legacy,
      { key: 'tag', label: 'Tag', descriptor: { base: 'text', nullable: false, array: false } },
    ]);

    await rowInputs(wrapper, 1)[1].setValue('Tags');
    await nextTick();

    expect(lastSchema(wrapper)[0]).toEqual(legacy[0]);
    expect(lastValid(wrapper)).toBe(true);
  });
});

describe('re-seeding', () => {
  it('re-seeds when a DIFFERENT schema arrives', async () => {
    const wrapper = mountBuilder([
      { key: 'a', label: 'A', descriptor: { base: 'text', nullable: false, array: false } },
    ]);

    await wrapper.setProps({
      modelValue: [
        { key: 'x', label: 'X', descriptor: { base: 'number', nullable: false, array: false } },
        { key: 'y', label: 'Y', descriptor: { base: 'date', nullable: false, array: false } },
      ],
    });
    await nextTick();

    expect(fieldRows(wrapper)).toHaveLength(2);
    expect((rowInputs(wrapper, 0)[0].element as HTMLInputElement).value).toBe('x');
  });

  it('does NOT re-seed when the parent echoes back what we just emitted', async () => {
    const wrapper = mountBuilder([]);
    await addField(wrapper);
    await rowInputs(wrapper, 0)[1].setValue('Product line');
    await nextTick();

    const echoed = lastSchema(wrapper);
    const before = fieldRows(wrapper)[0].attributes('data-row-id');
    expect(before, 'the row must carry a stable id for this assertion to mean anything').toBeTruthy();
    await wrapper.setProps({ modelValue: echoed });
    await nextTick();

    // Same single row, same typed values — a re-seed would have rebuilt it (and dropped focus).
    expect(fieldRows(wrapper)).toHaveLength(1);
    expect((rowInputs(wrapper, 0)[1].element as HTMLInputElement).value).toBe('Product line');
    expect(fieldRows(wrapper)[0].attributes('data-row-id')).toBe(before);
  });
});
