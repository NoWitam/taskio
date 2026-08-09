// @vitest-environment happy-dom
// KnowledgeBaseForm.spec — the create/edit body behind the base settings drawer.
//
// The subject here is the PATCH DIFF, which is the one piece of this batch where being merely
// "close enough" produces a bug nobody can explain: on the server an ABSENT key means UNCHANGED,
// and a PRESENT governed key (`charter` / `metadata_schema`) escalates the request from the
// `update` ability to `manage`. So a plain rename that echoed the stored charter back would 403
// an ordinary member — and a form that resolved an untouched charter to null would WIPE it.
//
// Also covers the capability split itself (a member sees the charter, read-only) and the
// client-side required-name guard.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { nextTick } from 'vue';
import KnowledgeBaseForm from '../KnowledgeBaseForm.vue';
import { setLocale, translate } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import type { KnowledgeBase, KnowledgeBaseWritePayload } from '../types';

const t = translate;

beforeEach(() => {
  installBrowserMocks();
  setLocale('en');
});
afterEach(() => {
  document.body.innerHTML = '';
  restoreBrowserMocks();
  vi.restoreAllMocks();
});

function base(overrides: Partial<KnowledgeBase> = {}): KnowledgeBase {
  return {
    id: 'b1',
    name: 'Brand',
    description: 'Everything about the brand',
    charter: 'How we speak.',
    language: 'pl',
    metadata_schema: [
      { key: 'channel', label: 'Channel', descriptor: { base: 'text', nullable: false, array: false } },
    ],
    entries_count: 4,
    creator: null,
    is_owner: true,
    can_be_edited: true,
    can_be_managed: true,
    can_be_deleted: true,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
    deleted_at: null,
    ...overrides,
  };
}

type Wrapper = VueWrapper<InstanceType<typeof KnowledgeBaseForm>>;

function mountForm(props: Record<string, unknown> = {}): Wrapper {
  return mount(KnowledgeBaseForm, { attachTo: document.body, props }) as Wrapper;
}

/** The form's inputs, in DOM order: [name, description, charter] are the text controls. */
function nameInput(wrapper: Wrapper) {
  return wrapper.findAll('input')[0];
}
function textareas(wrapper: Wrapper) {
  return wrapper.findAll('textarea');
}

async function submit(wrapper: Wrapper): Promise<void> {
  (wrapper.vm as unknown as { submit: () => void }).submit();
  await nextTick();
}

function lastPayload(wrapper: Wrapper): KnowledgeBaseWritePayload {
  const emitted = wrapper.emitted('submit');
  expect(emitted, 'expected the form to have emitted a payload').toBeTruthy();
  return emitted![emitted!.length - 1][0] as KnowledgeBaseWritePayload;
}

describe('create', () => {
  it('emits the full record, omitting the prose it has nothing for', async () => {
    const wrapper = mountForm();

    await nameInput(wrapper).setValue('Support');
    await submit(wrapper);

    expect(lastPayload(wrapper)).toEqual({ name: 'Support', language: 'en' });

    wrapper.unmount();
  });

  it('carries description, charter and the schema when they are filled in', async () => {
    const wrapper = mountForm();

    await nameInput(wrapper).setValue('Support');
    await textareas(wrapper)[0].setValue('Helpdesk answers');
    await textareas(wrapper)[1].setValue('What we tell customers.');
    await submit(wrapper);

    expect(lastPayload(wrapper)).toEqual({
      name: 'Support',
      language: 'en',
      description: 'Helpdesk answers',
      charter: 'What we tell customers.',
    });

    wrapper.unmount();
  });

  it('refuses to submit without a name, and says why', async () => {
    const wrapper = mountForm();

    await submit(wrapper);

    expect(wrapper.emitted('submit')).toBeFalsy();
    expect(wrapper.text()).toContain(t('knowledge.settings.nameRequired'));

    wrapper.unmount();
  });
});

describe('edit — the PATCH diff', () => {
  it('sends ONLY the changed name (never the untouched charter or schema)', async () => {
    const wrapper = mountForm({ base: base() });

    await nameInput(wrapper).setValue('Marka');
    await submit(wrapper);

    const payload = lastPayload(wrapper);
    expect(payload).toEqual({ name: 'Marka' });
    expect('charter' in payload).toBe(false);
    expect('metadata_schema' in payload).toBe(false);

    wrapper.unmount();
  });

  it('sends nothing at all when nothing changed', async () => {
    const wrapper = mountForm({ base: base() });

    await submit(wrapper);

    expect(lastPayload(wrapper)).toEqual({});

    wrapper.unmount();
  });

  it('sends the charter when a manager actually edits it', async () => {
    const wrapper = mountForm({ base: base() });

    await textareas(wrapper)[1].setValue('How we speak. And what we never say.');
    await submit(wrapper);

    expect(lastPayload(wrapper)).toEqual({ charter: 'How we speak. And what we never say.' });

    wrapper.unmount();
  });

  it('normalizes an emptied charter to null rather than an empty string', async () => {
    const wrapper = mountForm({ base: base() });

    await textareas(wrapper)[1].setValue('   ');
    await submit(wrapper);

    expect(lastPayload(wrapper)).toEqual({ charter: null });

    wrapper.unmount();
  });

  it('does not report a change when a null description is left empty', async () => {
    const wrapper = mountForm({ base: base({ description: null }) });

    await nameInput(wrapper).setValue('Marka');
    await submit(wrapper);

    expect(lastPayload(wrapper)).toEqual({ name: 'Marka' });

    wrapper.unmount();
  });

  it('sends the schema when it really changed', async () => {
    const wrapper = mountForm({ base: base() });

    const removeField = wrapper
      .findAll('button')
      .find((b) => (b.attributes('aria-label') ?? '').startsWith(t('knowledge.schema.removeField')));
    await removeField!.trigger('click');
    await nextTick();
    await submit(wrapper);

    expect(lastPayload(wrapper)).toEqual({ metadata_schema: [] });

    wrapper.unmount();
  });

  it('blocks the submit while the schema is invalid', async () => {
    const wrapper = mountForm({ base: base() });

    // Break the key of the only field.
    const keyInput = wrapper.findAll('input')[1];
    await keyInput.setValue('1bad');
    await nextTick();
    await submit(wrapper);

    expect(wrapper.emitted('submit')).toBeFalsy();
    expect(wrapper.text()).toContain(t('knowledge.schema.keyInvalid'));

    wrapper.unmount();
  });
});

describe('the two abilities are not the same ability', () => {
  it('leaves a NON-manager the everyday fields and locks the governed ones, with the reason', () => {
    const wrapper = mountForm({ base: base({ can_be_managed: false }) });

    expect(wrapper.text()).toContain(t('knowledge.settings.governedLocked'));
    // Name + description stay editable…
    expect(nameInput(wrapper).attributes('disabled')).toBeUndefined();
    expect(textareas(wrapper)[0].attributes('disabled')).toBeUndefined();
    // …the charter is readable but inert, and the schema rows lose their controls.
    expect(textareas(wrapper)[1].attributes('disabled')).toBeDefined();
    expect(
      wrapper.findAll('button').some((b) => b.text() === t('knowledge.schema.addField')),
    ).toBe(false);

    wrapper.unmount();
  });

  it('still shows the charter to a non-manager (it governs what they write)', () => {
    const wrapper = mountForm({ base: base({ can_be_managed: false }) });

    expect((textareas(wrapper)[1].element as HTMLTextAreaElement).value).toBe('How we speak.');

    wrapper.unmount();
  });

  it('explains a fully read-only base instead of showing dead controls', () => {
    const wrapper = mountForm({ base: base({ can_be_edited: false, can_be_managed: false }) });

    expect(wrapper.text()).toContain(t('knowledge.settings.editLocked'));
    expect(nameInput(wrapper).attributes('disabled')).toBeDefined();

    wrapper.unmount();
  });
});

describe('server errors', () => {
  it('renders a 422 field message next to its field', () => {
    const wrapper = mountForm({
      base: base(),
      serverErrors: { name: 'That name is taken.' },
    });

    expect(wrapper.text()).toContain('That name is taken.');

    wrapper.unmount();
  });
});
