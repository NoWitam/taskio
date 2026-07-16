// @vitest-environment happy-dom
// Unit tests for EntryListInput — the shared COMPACT two-field entry editor used by
// the bot's knowledge / dictionary / phrases modules. Saved entries are collapsed
// rows; adding/editing happens in an inline form. Asserts the add-form save flow
// (emitting the configured-key shape), remove, and per-row error surfacing.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import EntryListInput from '../EntryListInput.vue';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

type Row = Record<string, string>;

// Knowledge-like config (title/content), both required.
const baseProps = {
  primaryKey: 'title',
  secondaryKey: 'content',
  primaryLabel: 'Title',
  primaryPlaceholder: 'Title',
  secondaryLabel: 'Content',
  secondaryPlaceholder: 'Content',
  addLabel: 'Add',
  emptyLabel: 'None',
};

describe('EntryListInput', () => {
  beforeEach(() => installBrowserMocks());
  afterEach(() => restoreBrowserMocks());

  function lastModel(wrapper: ReturnType<typeof mount>): Row[] {
    const emitted = wrapper.emitted('update:modelValue');
    return (emitted?.[emitted.length - 1]?.[0] ?? []) as Row[];
  }

  it('shows the empty note + an add button when there are no rows', () => {
    const wrapper = mount(EntryListInput, { props: { ...baseProps, modelValue: [] } });
    expect(wrapper.findAll('button').length).toBeGreaterThanOrEqual(1);
  });

  it('add opens a form; filling both fields + Save emits the configured-key row', async () => {
    const wrapper = mount(EntryListInput, { props: { ...baseProps, modelValue: [] } });

    // Open the add form (the only button while the list is empty).
    await wrapper.find('button').trigger('click');
    await nextTick();

    // Fill the two fields.
    await wrapper.find('input').setValue('Brand voice');
    await wrapper.find('textarea').setValue('Always upbeat.');
    await nextTick();

    // The form's Save button is the first button in the open form.
    const formButtons = wrapper.findAll('button');
    await formButtons[0].trigger('click');
    await nextTick();

    expect(lastModel(wrapper)).toEqual([{ title: 'Brand voice', content: 'Always upbeat.' }]);
  });

  it('does not save when a required field is blank', async () => {
    const wrapper = mount(EntryListInput, { props: { ...baseProps, modelValue: [] } });
    await wrapper.find('button').trigger('click');
    await nextTick();
    await wrapper.find('input').setValue('Only a title');
    // Save with an empty secondary → no emit, an inline error shows.
    await wrapper.findAll('button')[0].trigger('click');
    await nextTick();

    expect(wrapper.emitted('update:modelValue')).toBeFalsy();
    expect(wrapper.find('[role="alert"]').exists()).toBe(true);
  });

  it('remove drops the row at its index', async () => {
    const wrapper = mount(EntryListInput, {
      props: {
        ...baseProps,
        modelValue: [
          { title: 'A', content: 'a' },
          { title: 'B', content: 'b' },
        ],
      },
    });
    // Each collapsed row exposes edit + remove icon buttons; remove is the 2nd.
    const rowActionButtons = wrapper.findAll('li button');
    // row0: [expand, edit, remove] → the remove for row 0 is index 2.
    await rowActionButtons[2].trigger('click');
    await nextTick();

    expect(lastModel(wrapper)).toEqual([{ title: 'B', content: 'b' }]);
  });

  it('secondaryOptional saves with only the primary filled', async () => {
    const wrapper = mount(EntryListInput, {
      props: { ...baseProps, primaryKey: 'phrase', secondaryKey: 'context', secondaryOptional: true, modelValue: [] },
    });
    await wrapper.find('button').trigger('click');
    await nextTick();
    await wrapper.find('input').setValue('Who decides that?');
    await wrapper.findAll('button')[0].trigger('click');
    await nextTick();

    expect(lastModel(wrapper)).toEqual([{ phrase: 'Who decides that?', context: '' }]);
  });
});
